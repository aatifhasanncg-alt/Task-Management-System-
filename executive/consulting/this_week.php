<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

// ── Get or create today's/target work_plans row for this user ──────────────
function getOrCreatePlanId(PDO $db, int $uid, string $planDate): int
{
    $ts  = strtotime($planDate);
    $dow = (int) date('N', $ts); // 1=Mon, 7=Sun
    $weekStart = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
    $weekEnd   = date('Y-m-d', strtotime('+' . (7 - $dow) . ' days', $ts));
    $planMonth = date('Y-m-01', $ts);
    $weekNumber = (int) date('W', $ts);

    // First: try to find existing plan for this user+week (by date range, not just week_start_date)
    $stmt = $db->prepare("
        SELECT id FROM work_plans 
        WHERE user_id = ? 
          AND week_start_date = ?
        LIMIT 1
    ");
    $stmt->execute([$uid, $weekStart]);
    $planId = $stmt->fetchColumn();
    if ($planId) return (int) $planId;

    // Also check by overlapping date range in case week_start_date was stored differently
    $stmt2 = $db->prepare("
        SELECT id FROM work_plans
        WHERE user_id = ?
          AND week_end_date >= ?
          AND week_start_date <= ?
        LIMIT 1
    ");
    $stmt2->execute([$uid, $weekStart, $weekEnd]);
    $planId = $stmt2->fetchColumn();
    if ($planId) return (int) $planId;

    // Fetch user fields
    $uStmt = $db->prepare("SELECT department_id, branch_id, managed_by FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$uid]);
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
    $deptId       = $uRow['department_id'] ?? null;
    $branchId     = $uRow['branch_id']     ?? null;
    $supervisorId = $uRow['managed_by']    ?? null;

    // Insert — if unique constraint fires, retrieve existing row instead
    try {
        $ins = $db->prepare("
            INSERT INTO work_plans
                (user_id, supervisor_id, department_id, branch_id,
                 plan_month, week_number, week_start_date, week_end_date,
                 status, created_at)
            VALUES (?,?,?,?,?,?,?,?,'draft',NOW())
        ");
        $ins->execute([
            $uid, $supervisorId, $deptId, $branchId,
            $planMonth, $weekNumber, $weekStart, $weekEnd
        ]);
        return (int) $db->lastInsertId();

    } catch (PDOException $e) {
        // Duplicate key — fetch the existing row
        if ($e->getCode() === '23000') {
            $retry = $db->prepare("
                SELECT id FROM work_plans 
                WHERE user_id = ? AND week_start_date = ? 
                LIMIT 1
            ");
            $retry->execute([$uid, $weekStart]);
            $planId = $retry->fetchColumn();
            if ($planId) return (int) $planId;
        }
        throw $e;
    }
}

// ── POST: save_entry (add or update a work_plan_entries row) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_entry'])) {
    verifyCsrf();

    $entryId = (int) ($_POST['entry_id'] ?? 0);
    $planDate = $_POST['plan_date'] ?? date('Y-m-d');
    $clientId = ($_POST['client_id'] ?? '') !== '' ? (int) $_POST['client_id'] : null;
    $timeIn = $_POST['planned_time_in'] ?? null;
    $timeOut = $_POST['planned_time_out'] ?? null;
    $notes = trim($_POST['notes'] ?? '');

    // auto-calc hours from time in/out
    $hours = 0;
    if ($timeIn && $timeOut) {
        $diff = (strtotime($planDate . ' ' . $timeOut) - strtotime($planDate . ' ' . $timeIn)) / 3600;
        $hours = $diff > 0 ? round($diff, 2) : 0;
    }
    $isNewEntry = !$entryId;

    // Validate client is required
    if (!$clientId) {
        setFlash('error', 'Client is required.');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    // Fetch supervisor for use in entry insert
    $supStmt = $db->prepare("SELECT managed_by FROM users WHERE id = ? LIMIT 1");
    $supStmt->execute([$uid]);
    $supervisorId = $supStmt->fetchColumn() ?: null;

    // Fetch supervisor's role + department (for building the correct notification link)
    $supervisorRole = null;
    $supervisorDeptCode = null;
    if ($supervisorId) {
        $supRoleStmt = $db->prepare("
            SELECT r.role_name, d.dept_code
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.id = ?
        ");
        $supRoleStmt->execute([$supervisorId]);
        $supRow = $supRoleStmt->fetch(PDO::FETCH_ASSOC);
        $supervisorRole = strtolower($supRow['role_name'] ?? 'admin');
        $supervisorDeptCode = $supRow['dept_code'] ?? null;
    }

    if ($entryId) {
        // Update existing entry — verify ownership via work_plans.user_id
        $own = $db->prepare("
            SELECT wpe.id FROM work_plan_entries wpe
            JOIN work_plans wp ON wp.id = wpe.plan_id
            WHERE wpe.id = ? AND wp.user_id = ?
        ");
        $own->execute([$entryId, $uid]);
        if ($own->fetch()) {
            $db->prepare("
                UPDATE work_plan_entries
                SET client_id=?, client_code=(SELECT company_code FROM companies WHERE id=? LIMIT 1),
                    plan_date=?, day_of_week=?, planned_time_in=?, planned_time_out=?, planned_hours=?, notes=?
                WHERE id=?
            ")->execute([
                        $clientId,
                        $clientId,
                        $planDate,
                        date('l', strtotime($planDate)),
                        $timeIn ?: null,
                        $timeOut ?: null,
                        $hours,
                        $notes ?: null,
                        $entryId
                    ]);
            // Also update supervisor on the plan header if overridden
            if (!empty($_POST['supervisor_id'])) {
                $db->prepare("UPDATE work_plans SET supervisor_id = ? WHERE id = (SELECT plan_id FROM work_plan_entries WHERE id = ?)")
                    ->execute([(int) $_POST['supervisor_id'], $entryId]);
            }
            setFlash('success', 'Plan entry updated.');
        }
    } else {
        // New entry — find or create the work_plans row for that date's week
        $planId = getOrCreatePlanId($db, $uid, $planDate);
        // Fetch client_code for the selected client
        $clientCode = null;
        if ($clientId) {
            $ccStmt = $db->prepare("SELECT company_code FROM companies WHERE id = ? LIMIT 1");
            $ccStmt->execute([$clientId]);
            $clientCode = $ccStmt->fetchColumn() ?: null;
        }

        $dayOfWeek = date('l', strtotime($planDate)); // e.g. "Monday"

        // Allow user to override supervisor from form, fallback to managed_by
        $formSupervisorId = ($_POST['supervisor_id'] ?? '') !== ''
            ? (int) $_POST['supervisor_id']
            : $supervisorId;

        $db->prepare("
            INSERT INTO work_plan_entries
                (plan_id, client_id, client_code, assigned_to, supervisor_id,
                 plan_date, day_of_week, planned_time_in, planned_time_out,
                 planned_hours, notes, created_at, is_notified)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),0)
        ")->execute([
                    $planId,
                    $clientId,
                    $clientCode,
                    $uid,
                    $formSupervisorId,
                    $planDate,
                    $dayOfWeek,
                    $timeIn ?: null,
                    $timeOut ?: null,
                    $hours,
                    $notes ?: null
                ]);
        setFlash('success', 'Plan entry added.');
    }
    $linkPlanId = null;
    if ($entryId) {
        $lpStmt = $db->prepare("SELECT plan_id FROM work_plan_entries WHERE id = ?");
        $lpStmt->execute([$entryId]);
        $linkPlanId = $lpStmt->fetchColumn() ?: null;
    } elseif (!empty($planId)) {
        $linkPlanId = $planId;
    }
    // Notify supervisor if managed_by set
    if (!empty($supervisorId)) {
        try {
            $action = $isNewEntry ? 'added' : 'updated';

            if ($supervisorRole === 'executive') {
                $basePath = '/executive/consulting/';
            } elseif ($supervisorRole === 'manager') {
                $basePath = ($supervisorDeptCode === 'CORE')
                    ? '/manager/consulting/branch/'
                    : '/manager/consulting/';
            } else {
                $basePath = '/admin/consulting/';
            }

            $notifyLink = APP_URL . $basePath . 'plan_view.php?id=' . $linkPlanId;

            notify(
                (int) $supervisorId,
                'Work Plan Edited',
                '' . htmlspecialchars($user['full_name'] ?? 'A user') . ' has ' . $action . ' a work plan entry for ' . date('l, d M Y', strtotime($planDate)) . '.',
                'system',
                $notifyLink,
                true,
                ['template' => 'generic']
            );
        } catch (Exception $ne) {
            error_log('Plan entry notify error: ' . $ne->getMessage());
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── POST: delete_entry ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_entry'])) {
    verifyCsrf();
    $entryId = (int) ($_POST['entry_id'] ?? 0);
    $del = $db->prepare("
        DELETE wpe FROM work_plan_entries wpe
        JOIN work_plans wp ON wp.id = wpe.plan_id
        WHERE wpe.id = ? AND wp.user_id = ?
    ");
    $del->execute([$entryId, $uid]);
    setFlash('success', 'Plan entry deleted.');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function renderTable($entries)
{
    echo '<div style="padding:0;" class="cn-table-wrap">';
    echo '<table class="cn-table" style="margin:0;width:100%;min-width:480px;">';
    echo '<thead><tr>
        <th>Client</th>
        <th style="width:90px;">Time In</th>
        <th style="width:90px;">Time Out</th>
        <th class="text-center" style="width:65px;">Hours</th>
        <th>Notes</th>
        <th style="width:70px;"></th>
    </tr></thead>';
    echo '<tbody>';
    foreach ($entries as $e) {
        $id = (int) $e['id'];
        $name = htmlspecialchars($e['company_name'] ?? '—');
        $code = htmlspecialchars($e['company_code'] ?? '');
        $tin = $e['planned_time_in'] ? date('h:i A', strtotime($e['planned_time_in'])) : '—';
        $tout = $e['planned_time_out'] ? date('h:i A', strtotime($e['planned_time_out'])) : '—';
        $hours = number_format((float) $e['planned_hours'], 1);
        $notes = htmlspecialchars($e['notes'] ?? '—');

        // raw values for the edit modal (data attrs)
        $rawClientId = (int) ($e['client_id'] ?? 0);
        $rawDate = htmlspecialchars($e['plan_date'] ?? '');
        $rawTin = htmlspecialchars($e['planned_time_in'] ?? '');
        $rawTout = htmlspecialchars($e['planned_time_out'] ?? '');
        $rawNotes = htmlspecialchars($e['notes'] ?? '', ENT_QUOTES);
        $rawSupervisorId = (int) ($e['supervisor_id'] ?? 0);

        echo "
        <tr>
            <td>
                <div style='font-weight:600;font-size:.82rem;'>{$name}</div>
                <div style='font-size:.68rem;color:#9ca3af;'>{$code}</div>
            </td>
            <td style='font-size:.81rem;'>{$tin}</td>
            <td style='font-size:.81rem;'>{$tout}</td>
            <td class='text-center'><strong style='color:#c9a84c;'>{$hours}h</strong></td>
            <td style='font-size:.77rem;color:#6b7280;'>{$notes}</td>
            <td class='text-center'>
                <button type='button' class='btn btn-sm btn-outline-secondary py-0 px-1' style='font-size:.7rem;'
                    onclick='openPlanModal({$id}, {$rawClientId}, \"{$rawDate}\", \"{$rawTin}\", \"{$rawTout}\", \"{$rawNotes}\", {$rawSupervisorId})'>
                    <i class='fas fa-pen'></i>
                </button>
            </td>
        </tr>";
    }
    echo '</tbody></table></div>';
}
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// Fetch entries (today + tomorrow)
$stmt = $db->prepare("
    SELECT wpe.*, c.company_name, c.company_code
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    LEFT JOIN companies c ON c.id = wpe.client_id
    WHERE wp.user_id = ?
      AND wpe.plan_date IN (?, ?)
    ORDER BY wpe.plan_date ASC, wpe.planned_time_in ASC
");
$stmt->execute([$uid, $today, $tomorrow]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Fetch this week's full schedule
$todayTs   = strtotime($today);
$todayDow  = (int) date('N', $todayTs);
$weekStart = date('Y-m-d', strtotime('-' . ($todayDow - 1) . ' days', $todayTs));
$weekEnd   = date('Y-m-d', strtotime('+' . (7 - $todayDow) . ' days', $todayTs));

$weekStmt = $db->prepare("
    SELECT wpe.*, c.company_name, c.company_code
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    LEFT JOIN companies c ON c.id = wpe.client_id
    WHERE wp.user_id = ?
      AND wpe.plan_date BETWEEN ? AND ?
    ORDER BY wpe.plan_date ASC, wpe.planned_time_in ASC
");
$weekStmt->execute([$uid, $weekStart, $weekEnd]);
$weekData = $weekStmt->fetchAll(PDO::FETCH_ASSOC);
$allClients = $db->query("SELECT id, company_name, company_code FROM companies WHERE is_active=1 ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);
// Fetch possible supervisors (admins/managers/executives)
$allSupervisors = $db->query("
    SELECT u.id, u.full_name, r.role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.role_name IN ('admin','manager','executive')
      AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

// Current user's default supervisor
$defaultSupervisorId = null;
$supDefStmt = $db->prepare("SELECT managed_by FROM users WHERE id = ?");
$supDefStmt->execute([$uid]);
$defaultSupervisorId = $supDefStmt->fetchColumn() ?: null;
// Group by date
$byDay = [];
foreach ($weekData as $wd) {
    $byDay[$wd['plan_date']][] = $wd;
}

// Total week hours
$weekTotalHours = array_sum(array_column($weekData, 'planned_hours'));
$weekTotalVisits = count($weekData);
// Group
$grouped = [
    'today' => [],
    'tomorrow' => []
];

foreach ($data as $d) {
    if ($d['plan_date'] == $today) {
        $grouped['today'][] = $d;
    } elseif ($d['plan_date'] == $tomorrow) {
        $grouped['tomorrow'][] = $d;
    }
}

$pageTitle = "Today's & Tomorrow's Plan";
include '../../includes/header.php';
?>

<link rel="stylesheet" href="consulting.css">
<style>
    /* ── Responsive: Today & Tomorrow grid ── */
    @media (max-width: 768px) {

        /* Stack today/tomorrow cards vertically */
        .tt-grid {
            grid-template-columns: 1fr !important;
        }

        /* KPI row: 2 columns on mobile */
        .kpi-row {
            grid-template-columns: repeat(2, 1fr) !important;
        }

        /* Page hero: stack vertically */
        .page-hero .d-flex.justify-content-between {
            flex-direction: column !important;
            align-items: flex-start !important;
            gap: 10px !important;
        }

        /* Week schedule: reduce indent for day entries */
        #day-entries-indent {
            padding-left: 16px !important;
        }

        /* Tables: make scrollable on small screens */
        .cn-table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Modal: full width on mobile */
        #planModalOverlay>div {
            max-width: 100% !important;
            margin: 0 !important;
            border-radius: 12px 12px 0 0 !important;
            position: fixed !important;
            bottom: 0 !important;
            left: 0 !important;
            right: 0 !important;
        }

        #planModalOverlay {
            align-items: flex-end !important;
        }

        /* Shrink day-number badge indent */
        .day-entry-block {
            padding-left: 16px !important;
        }

        /* Topbar / sidebar handled by your existing includes */
    }

    @media (max-width: 480px) {
        .kpi-row {
            grid-template-columns: repeat(2, 1fr) !important;
        }

        .kpi-val {
            font-size: 1.3rem !important;
        }

        .cn-panel-hd {
            flex-wrap: wrap;
            gap: 4px;
        }
    }
</style>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div class="cn-wrap">

            <?= flashHtml() ?>

            <!-- PAGE HERO -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge">
                            <i class="fas fa-calendar-day"></i> Daily Planning
                        </div>
                        <h4>Today & Tomorrow Plans</h4>
                        <p><?= date('d M Y') ?> · Quick View</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <span style="font-size:.78rem;color:#9ca3af;">
                            Week: <?= date('d M', strtotime($weekStart)) ?> – <?= date('d M', strtotime($weekEnd)) ?>
                        </span>
                        
                    </div>
                </div>
            </div>

            <!-- WEEK KPI ROW -->
            <div class="kpi-row mb-4">
                <div class="kpi-tile" style="--kpi-color:#3b82f6;">
                    <div class="kpi-icon"><i class="fas fa-calendar-week" style="color:#3b82f6;"></i></div>
                    <div class="kpi-val"><?= $weekTotalVisits ?></div>
                    <div class="kpi-label">Week Visits</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#c9a84c;">
                    <div class="kpi-icon"><i class="fas fa-clock" style="color:#c9a84c;"></i></div>
                    <div class="kpi-val"><?= number_format($weekTotalHours, 1) ?>h</div>
                    <div class="kpi-label">Week Hours</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#10b981;">
                    <div class="kpi-icon"><i class="fas fa-sun" style="color:#10b981;"></i></div>
                    <div class="kpi-val"><?= count($grouped['today']) ?></div>
                    <div class="kpi-label">Today</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#8b5cf6;">
                    <div class="kpi-icon"><i class="fas fa-moon" style="color:#8b5cf6;"></i></div>
                    <div class="kpi-val"><?= count($grouped['tomorrow']) ?></div>
                    <div class="kpi-label">Tomorrow</div>
                </div>
            </div>

            <!-- TODAY + TOMORROW GRID -->
            <div class="tt-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

                <!-- TODAY -->
                <div class="cn-panel">
                    <div class="cn-panel-hd">
                        <span class="cn-panel-title">
                            <i class="fas fa-sun me-2" style="color:var(--gold)"></i>
                            Today — <?= date('l, d M') ?>
                        </span>
                        <span style="font-size:.72rem;color:#9ca3af;"><?= count($grouped['today']) ?> visit(s)</span>
                    </div>
                    <div style="padding:0;">
                        <?php if (empty($grouped['today'])): ?>
                            <div style="padding:30px;text-align:center;color:#9ca3af;font-size:.8rem;">
                                <i class="fas fa-calendar-times"
                                    style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                                No plans for today
                            </div>
                        <?php else:
                            renderTable($grouped['today']);
                        endif; ?>
                    </div>
                </div>

                <!-- TOMORROW -->
                <div class="cn-panel">
                    <div class="cn-panel-hd">
                        <span class="cn-panel-title">
                            <i class="fas fa-moon me-2" style="color:#8b5cf6"></i>
                            Tomorrow — <?= date('l, d M', strtotime('+1 day')) ?>
                        </span>
                        <span style="font-size:.72rem;color:#9ca3af;"><?= count($grouped['tomorrow']) ?> visit(s)</span>
                    </div>
                    <div style="padding:0;">
                        <?php if (empty($grouped['tomorrow'])): ?>
                            <div style="padding:30px;text-align:center;color:#9ca3af;font-size:.8rem;">
                                <i class="fas fa-calendar-times"
                                    style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                                No plans for tomorrow
                            </div>
                        <?php else:
                            renderTable($grouped['tomorrow']);
                        endif; ?>
                    </div>
                </div>

            </div>

            <!-- THIS WEEK SCHEDULE -->
            <div class="cn-panel mb-4">
                <div class="cn-panel-hd" style="justify-content:space-between;">
                    <span class="cn-panel-title">
                        <i class="fas fa-calendar-week me-2" style="color:var(--gold)"></i>
                        This Week's Schedule
                    </span>
                    <span style="font-size:.72rem;color:#9ca3af;">
                        <?= date('d M', strtotime($weekStart)) ?> – <?= date('d M', strtotime($weekEnd)) ?>
                        · <?= number_format($weekTotalHours, 1) ?>h planned
                    </span>
                </div>

                <?php if (empty($byDay)): ?>
                    <div style="padding:40px;text-align:center;color:#9ca3af;font-size:.8rem;">
                        <i class="fas fa-calendar-times" style="font-size:2rem;display:block;margin-bottom:10px;"></i>
                        No plans this week
                    </div>
                <?php else: ?>

                    <?php
                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    $dayDates = [];
                    foreach ($days as $i => $dayName) {
                        $dayDates[$dayName] = date('Y-m-d', strtotime($weekStart . ' +' . $i . ' days'));
                    }
                    ?>

                    <div style="padding:0;">
                        <?php foreach ($dayDates as $dayName => $dayDate):
                            $isToday = ($dayDate === $today);
                            $isTomorrow = ($dayDate === $tomorrow);
                            $isPast = ($dayDate < $today);
                            $dayEntries = $byDay[$dayDate] ?? [];
                            $dayHours = array_sum(array_column($dayEntries, 'planned_hours'));

                            $rowBg = $isToday ? '#fffbeb' : ($isPast && empty($dayEntries) ? '#fafafa' : '#fff');
                            $borderL = $isToday ? '3px solid #c9a84c' : ($isTomorrow ? '3px solid #8b5cf6' : '3px solid transparent');
                            ?>
                            <div style="border-left:<?= $borderL ?>;background:<?= $rowBg ?>;border-bottom:1px solid #f1f5f9;">
                                <!-- Day header -->
                                <div style="padding:10px 16px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;"
                                    onclick="toggleDay('day_<?= $dayDate ?>')">
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div
                                            style="width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;
                        background:<?= $isToday ? '#c9a84c' : ($isTomorrow ? '#8b5cf6' : ($isPast ? '#f1f5f9' : '#f1f5f9')) ?>;
                        color:<?= $isToday ? '#fff' : ($isTomorrow ? '#fff' : '#9ca3af') ?>;font-weight:700;font-size:.78rem;">
                                            <?= date('d', strtotime($dayDate)) ?>
                                        </div>
                                        <div>
                                            <div
                                                style="font-size:.85rem;font-weight:700;color:<?= $isPast && empty($dayEntries) ? '#d1d5db' : '#1f2937' ?>;">
                                                <?= $dayName ?>
                                                <?php if ($isToday): ?>
                                                    <span
                                                        style="background:#c9a84c;color:#fff;font-size:.62rem;padding:1px 6px;border-radius:10px;margin-left:5px;vertical-align:middle;">TODAY</span>
                                                <?php elseif ($isTomorrow): ?>
                                                    <span
                                                        style="background:#8b5cf6;color:#fff;font-size:.62rem;padding:1px 6px;border-radius:10px;margin-left:5px;vertical-align:middle;">TOMORROW</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size:.7rem;color:#9ca3af;">
                                                <?= date('d M Y', strtotime($dayDate)) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:12px;">
                                        <?php if (!empty($dayEntries)): ?>
                                            <span
                                                style="background:#f0fdf4;color:#15803d;padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:600;">
                                                <?= count($dayEntries) ?> visit(s)
                                            </span>
                                            <span style="font-size:.78rem;font-weight:700;color:#c9a84c;">
                                                <?= number_format($dayHours, 1) ?>h
                                            </span>
                                        <?php else: ?>
                                            <span style="font-size:.75rem;color:#d1d5db;">No visits</span>
                                        <?php endif; ?>
                                        <button type="button"
    onclick="event.stopPropagation(); openPlanModal(0, 0, '<?= $dayDate ?>', '', '', '', 0)"
    style="background:#c9a84c;border:none;color:#fff;border-radius:6px;
    padding:3px 9px;font-size:.72rem;cursor:pointer;white-space:nowrap;line-height:1.6;">
    <i class="fas fa-plus"></i>
</button>
                                        <i class="fas fa-chevron-down" style="font-size:.7rem;color:#9ca3af;transition:.2s;"
                                            id="icon_<?= $dayDate ?>"></i>
                                    </div>
                                </div>

                                <!-- Day entries (collapsible) -->
                                <?php if (!empty($dayEntries)): ?>
                                    <div id="day_<?= $dayDate ?>" class="day-entry-block cn-table-wrap"
                                        style="<?= $isToday || $isTomorrow ? '' : 'display:none;' ?>padding:0 16px 12px 62px;">
                                        <table class="cn-table" style="margin:0;border-radius:8px;overflow:hidden;min-width:480px;">
                                            <thead>
                                                <tr>
                                                    <th>Client</th>
                                                    <th style="width:90px;">Time In</th>
                                                    <th style="width:90px;">Time Out</th>
                                                    <th class="text-center" style="width:65px;">Hours</th>
                                                    <th>Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($dayEntries as $e): ?>
                                                    <tr>
                                                        <td>
                                                            <div style="font-weight:600;font-size:.82rem;">
                                                                <?= htmlspecialchars($e['company_name'] ?? '—') ?>
                                                            </div>
                                                            <div style="font-size:.68rem;color:#9ca3af;">
                                                                <?= htmlspecialchars($e['company_code'] ?? '') ?>
                                                            </div>
                                                        </td>
                                                        <td style="font-size:.81rem;">
                                                            <?= $e['planned_time_in'] ? date('h:i A', strtotime($e['planned_time_in'])) : '—' ?>
                                                        </td>
                                                        <td style="font-size:.81rem;">
                                                            <?= $e['planned_time_out'] ? date('h:i A', strtotime($e['planned_time_out'])) : '—' ?>
                                                        </td>
                                                        <td class="text-center"><strong
                                                                style="color:#c9a84c;"><?= number_format((float) $e['planned_hours'], 1) ?>h</strong>
                                                        </td>
                                                        <td style="font-size:.77rem;color:#6b7280;">
                                                            <?= htmlspecialchars($e['notes'] ?? '—') ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /cn-wrap -->
    </div>
</div>
<!-- Tom Select CSS for searchable dropdown -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

<!-- Add/Edit Plan Modal -->
<div id="planModalOverlay"
    style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;align-items:center;justify-content:center;">
    <div
        style="background:#fff;border-radius:12px;width:100%;max-width:480px;margin:1rem;box-shadow:0 10px 40px rgba(0,0,0,.25);position:relative;z-index:100000;">
        <form method="POST" id="planForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="save_entry" value="1">
            <input type="hidden" name="entry_id" id="pm_entry_id" value="">

            <div
                style="padding:1rem 1.25rem;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;">
                <h5 id="pm_title" style="margin:0;font-size:.95rem;font-weight:700;">Add Plan Entry</h5>
                <button type="button" onclick="closePlanEntryModal()"
                    style="border:none;background:none;font-size:1.1rem;color:#9ca3af;cursor:pointer;">&times;</button>
            </div>

            <div style="padding:1.25rem;">
                <div class="mb-3">
                    <label class="form-label-mis" style="font-size:.75rem;font-weight:600;color:#374151;">Date <span
                            style="color:#ef4444;">*</span></label>
                    <input type="date" name="plan_date" id="pm_plan_date" class="form-control form-control-sm" required>
                </div>
                <div class="mb-3">
                    <label class="form-label-mis" style="font-size:.75rem;font-weight:600;color:#374151;">Client <span
                            style="color:#ef4444;">*</span></label>
                    <select name="client_id" id="pm_client_id" class="form-select form-select-sm" required>
                        <option value="">-- Select Client --</option>
                        <?php foreach ($allClients as $c): ?>
                            <option value="<?= $c['id'] ?>">
                                <?= htmlspecialchars($c['company_name']) ?>
                                <?= $c['company_code'] ? ' (' . htmlspecialchars($c['company_code']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label-mis" style="font-size:.75rem;font-weight:600;color:#374151;">Time
                            In</label>
                        <input type="time" name="planned_time_in" id="pm_time_in" class="form-control form-control-sm">
                    </div>
                    <div class="col-6">
                        <label class="form-label-mis" style="font-size:.75rem;font-weight:600;color:#374151;">Time
                            Out</label>
                        <input type="time" name="planned_time_out" id="pm_time_out"
                            class="form-control form-control-sm">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label-mis" style="font-size:.75rem;font-weight:600;color:#374151;">
                        Supervisor <span style="color:#9ca3af;font-weight:400;">(optional override)</span>
                    </label>
                    <select name="supervisor_id" id="pm_supervisor_id" class="form-select form-select-sm">
                        <option value="">— Default (from profile) —</option>
                        <?php foreach ($allSupervisors as $sv): ?>
                            <option value="<?= $sv['id'] ?>" <?= $sv['id'] == $defaultSupervisorId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sv['full_name']) ?>
                                (<?= ucfirst($sv['role_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label-mis" style="font-size:.75rem;font-weight:600;color:#374151;">Notes</label>
                    <textarea name="notes" id="pm_notes" class="form-control form-control-sm" rows="2"></textarea>
                </div>
            </div>

            <div
                style="padding:.85rem 1.25rem;border-top:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;">
                <button type="button" id="pm_delete_btn" class="btn btn-sm btn-outline-danger" style="display:none;"
                    onclick="deletePlanEntry()">
                    <i class="fas fa-trash me-1"></i>Delete
                </button>
                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                        onclick="closePlanEntryModal()">Cancel</button>
                    <button type="submit" class="btn btn-gold btn-sm"><i class="fas fa-save me-1"></i>Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Hidden delete form -->
<form method="POST" id="deleteEntryForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="delete_entry" value="1">
    <input type="hidden" name="entry_id" id="del_entry_id" value="">
</form>

<script>
    // Tom Select — searchable client dropdown
    let clientSelect = null;
    let supervisorSelect = null;

    function initClientSelect() {
        if (clientSelect) return; // already init

        clientSelect = new TomSelect('#pm_client_id', {
            placeholder: 'Search client...',
            allowEmptyOption: true,
            maxOptions: 200,
        });

        supervisorSelect = new TomSelect('#pm_supervisor_id', {
            placeholder: 'Search supervisor...',
            allowEmptyOption: false,
            maxOptions: 100,
            render: {
                option: function (data, escape) {
                    const match = data.text.match(/^(.+?)\s*\((.+?)\)/);
                    const name = escape(match ? match[1].trim() : data.text);
                    const role = match
                        ? `<span style="font-size:.68rem;color:#9ca3af;margin-left:4px;">${escape(match[2])}</span>`
                        : '';
                    return `<div style="padding:3px 2px;">${name}${role}</div>`;
                },
                item: function (data, escape) {
                    const match = data.text.match(/^(.+?)\s*\((.+?)\)/);
                    const name = escape(match ? match[1].trim() : data.text);
                    const role = match
                        ? `<span style="font-size:.7rem;color:#9ca3af;margin-left:4px;">(${escape(match[2])})</span>`
                        : '';
                    return `<div>${name}${role}</div>`;
                }
            }
        });
    }

    function toggleDay(id) {
        const el = document.getElementById(id);
        const date = id.replace('day_', '');
        const icon = document.getElementById('icon_' + date);
        if (!el) return;
        const open = el.style.display !== 'none';
        el.style.display = open ? 'none' : 'block';
        if (icon) icon.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
    }

    // Validate client required before form submit
    document.getElementById('planForm').addEventListener('submit', function (e) {
        const clientVal = clientSelect ? clientSelect.getValue() : document.getElementById('pm_client_id').value;
        if (!clientVal) {
            e.preventDefault();
            alert('Please select a client.');
            return false;
        }
    });

    function openPlanModal(entryId = 0, clientId = 0, planDate = '', timeIn = '', timeOut = '', notes = '', supervisorId = 0) {
        initClientSelect();

        document.getElementById('pm_entry_id').value = entryId;
        document.getElementById('pm_plan_date').value = planDate || '<?= date('Y-m-d') ?>';
        document.getElementById('pm_time_in').value = timeIn || '';
        document.getElementById('pm_time_out').value = timeOut || '';
        document.getElementById('pm_notes').value = notes || '';
        // Set supervisor dropdown
        // Set supervisor via TomSelect
        const supVal = supervisorId ? String(supervisorId) : '<?= $defaultSupervisorId ?? '' ?>';
        if (supervisorSelect) supervisorSelect.setValue(supVal, true);
        document.getElementById('pm_title').textContent = entryId ? 'Edit Plan Entry' : 'Add Plan Entry';
        document.getElementById('pm_delete_btn').style.display = entryId ? 'inline-block' : 'none';

        // Set Tom Select value
        clientSelect.setValue(clientId ? String(clientId) : '', true);
        supervisorSelect.setValue(supervisorId ? String(supervisorId) : '<?= $defaultSupervisorId ?? '' ?>', true);

        document.getElementById('planModalOverlay').style.display = 'flex';
    }

    function closePlanEntryModal() {
        document.getElementById('planModalOverlay').style.display = 'none';
    }

    function deletePlanEntry() {
        const entryId = document.getElementById('pm_entry_id').value;
        if (!entryId) return;
        if (!confirm('Delete this plan entry?')) return;
        closePlanEntryModal();
        document.getElementById('del_entry_id').value = entryId;
        document.getElementById('deleteEntryForm').submit();
    }

    // Close on overlay backdrop click
    document.getElementById('planModalOverlay').addEventListener('click', function (e) {
        if (e.target === this) closePlanEntryModal();
    });
</script>

<?php include '../../includes/footer.php'; ?>