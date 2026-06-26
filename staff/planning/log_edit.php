<?php
/**
 * consulting/admin/log_edit.php — Edit Own Visit Log
 * Any role can edit their OWN logs only.
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];
$currentRole = $_SESSION['role'] ?? ($user['role'] ?? '');

// ── Load log — must belong to current user ─────────────────────
$logId = (int) ($_GET['id'] ?? 0);
if (!$logId) {
    setFlash('error', 'Invalid log.');
    header('Location: log_list.php');
    exit;
}

$logStmt = $db->prepare("
    SELECT wl.*, c.company_name, c.company_code, c.pan_number
    FROM work_logs wl
    LEFT JOIN companies c ON c.id = wl.client_id
    WHERE wl.id = ? AND wl.user_id = ?
");
$logStmt->execute([$logId, $uid]);
$log = $logStmt->fetch(PDO::FETCH_ASSOC);

if (!$log) {
    setFlash('error', 'Log not found or you do not have permission to edit it.');
    header('Location: log_list.php');
    exit;
}

// ── UDA consulting dept detection ─────────────────────────────
$deptId = (int) $user['department_id'];
$branchId = (int) $user['branch_id'];

$__deptMetaQ = $db->prepare("SELECT dept_code, dept_name FROM departments WHERE id = ?");
$__deptMetaQ->execute([$user['department_id']]);
$__deptMeta = $__deptMetaQ->fetch(PDO::FETCH_ASSOC);
$__primaryCode = $__deptMeta['dept_code'] ?? '';
$__isConsPrimary = ($__primaryCode === 'CON' || stripos($__deptMeta['dept_name'] ?? '', 'consult') !== false);
$__isCoreAdmin = ($__primaryCode === 'CORE');

$__udaQ = $db->prepare("
    SELECT d.id, d.dept_code FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ? AND (d.dept_code = 'CON' OR d.dept_name LIKE '%consult%')
    LIMIT 1
");
$__udaQ->execute([$uid]);
$__udaCons = $__udaQ->fetch(PDO::FETCH_ASSOC);

if ($__isConsPrimary) {
    $deptId = (int) $user['department_id'];
} elseif ($__isCoreAdmin && $__udaCons) {
    $deptId = (int) $__udaCons['id'];
} elseif ($__udaCons) {
    $deptId = (int) $__udaCons['id'];
}

// ── Month context ──────────────────────────────────────────────
$month = substr($log['month_year'], 0, 7); // stored as 'Y-m'
$monthDate = DateTime::createFromFormat('Y-m', $month) ?: new DateTime();
$monthLabel = $monthDate->format('F Y');

// ── Companies ──────────────────────────────────────────────────
$companies = $db->query("
    SELECT id, company_name, company_code, pan_number
    FROM companies WHERE is_active = 1 ORDER BY company_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Linked plan entry (for reference display) ──────────────────
$linkedPlan = null;
if ($log['plan_entry_id']) {
    $lpStmt = $db->prepare("
        SELECT wpe.*, wp.week_number, wp.week_start_date, wp.week_end_date
        FROM work_plan_entries wpe
        JOIN work_plans wp ON wp.id = wpe.plan_id
        WHERE wpe.id = ?
    ");
    $lpStmt->execute([$log['plan_entry_id']]);
    $linkedPlan = $lpStmt->fetch(PDO::FETCH_ASSOC);
}
// ── Linked "rescheduled to" entry (for pre-fill / edit) ─────────
$rescheduledEntry = null;
if (!empty($log['rescheduled_to_entry_id'])) {
    $reStmt = $db->prepare("SELECT * FROM work_plan_entries WHERE id = ?");
    $reStmt->execute([$log['rescheduled_to_entry_id']]);
    $rescheduledEntry = $reStmt->fetch(PDO::FETCH_ASSOC);
}
// ── POST ───────────────────────────────────────────────────────
$errors = [];
$postData = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $clientId = (int) ($_POST['client_id'] ?? 0);
    $logDate = trim($_POST['log_date'] ?? '');
    $timeIn = trim($_POST['time_in'] ?? '') ?: null;
    $timeOut = trim($_POST['time_out'] ?? '') ?: null;
    $visitStatus = trim($_POST['visit_status'] ?? 'visited');
    $rescheduleDate = trim($_POST['reschedule_date'] ?? '') ?: null;
    $rescheduleTimeIn = trim($_POST['reschedule_time_in'] ?? '') ?: null;
    $rescheduleTimeOut = trim($_POST['reschedule_time_out'] ?? '') ?: null;
    $rescheduleNotes = trim($_POST['reschedule_notes'] ?? '');

    if ($visitStatus === 'rescheduled' && !$rescheduleDate) {
        $errors[] = 'Please select a new date for the rescheduled visit.';
    }
    $workDesc = trim($_POST['work_description'] ?? '');

    if (!$clientId)
        $errors[] = 'Please select a client.';
    if (!$logDate)
        $errors[] = 'Log date is required.';
    if (!in_array($visitStatus, ['visited', 'missed', 'rescheduled'])) {
        $errors[] = 'Invalid visit status.';
    }

    // Calculate duration
    $durationHours = 0.0;
    if ($timeIn && $timeOut) {
        $diff = strtotime($timeOut) - strtotime($timeIn);
        if ($diff > 0)
            $durationHours = round($diff / 3600, 2);
    }

    if (!$errors) {
        try {
            $ccMap = array_column($companies, 'company_code', 'id');

            $db->prepare("
                UPDATE work_logs SET
                    client_id        = ?,
                    log_date         = ?,
                    day_of_week      = ?,
                    time_in          = ?,
                    time_out         = ?,
                    duration_hours   = ?,
                    visit_status     = ?,
                    work_description = ?,
                    updated_at       = NOW()
                WHERE id = ? AND user_id = ?
            ")->execute([
                        $clientId,
                        $logDate,
                        date('l', strtotime($logDate)),
                        $timeIn,
                        $timeOut,
                        $durationHours,
                        $visitStatus,
                        $workDesc,
                        $logId,
                        $uid,
                    ]);
            // ── Sync reschedule plan entry ──────────────────────────────
            $existingEntryId = (int) $log['rescheduled_to_entry_id'] ?? 0;

            if ($visitStatus === 'rescheduled' && $rescheduleDate) {
                $plannedHours = 0;
                if ($rescheduleTimeIn && $rescheduleTimeOut) {
                    $diffR = strtotime($rescheduleTimeOut) - strtotime($rescheduleTimeIn);
                    $plannedHours = $diffR > 0 ? round($diffR / 3600, 2) : 0;
                }
                $rDow = date('l', strtotime($rescheduleDate));

                if ($existingEntryId) {
                    // Update the existing plan entry in place
                    $db->prepare("
            UPDATE work_plan_entries SET
                plan_date = ?, day_of_week = ?, planned_time_in = ?,
                planned_time_out = ?, planned_hours = ?, notes = ?
            WHERE id = ?
        ")->execute([
                                $rescheduleDate,
                                $rDow,
                                $rescheduleTimeIn,
                                $rescheduleTimeOut,
                                $plannedHours,
                                $rescheduleNotes !== '' ? $rescheduleNotes : $workDesc,
                                $existingEntryId,
                            ]);
                } else {
                    // Create new — find/create the right work_plans header for this week
                    $rdObj = new DateTime($rescheduleDate);
                    $rPlanMonth = $rdObj->format('Y-m-01');
                    $rWeekNum = (int) ceil((int) $rdObj->format('j') / 7);
                    $monthStart = new DateTime($rPlanMonth);
                    $monthEnd = (clone $monthStart)->modify('last day of this month');
                    $wStart = (clone $monthStart)->modify('+' . (($rWeekNum - 1) * 7) . ' days');
                    $wEnd = (clone $wStart)->modify('+6 days');
                    if ($wEnd > $monthEnd)
                        $wEnd = clone $monthEnd;

                    $planStmt = $db->prepare("
            SELECT id FROM work_plans
            WHERE user_id = ? AND department_id = ? AND branch_id = ?
              AND plan_month = ? AND week_number = ? LIMIT 1
        ");
                    $planStmt->execute([$uid, $deptId, $branchId, $rPlanMonth, $rWeekNum]);
                    $planId = $planStmt->fetchColumn();

                    if (!$planId) {
                        $db->prepare("
                INSERT INTO work_plans
                (user_id, supervisor_id, department_id, branch_id, plan_month,
                 week_number, week_start_date, week_end_date, status)
                VALUES (?,?,?,?,?,?,?,?, 'draft')
            ")->execute([
                                    $uid,
                                    $log['supervisor_id'] ?? null,
                                    $deptId,
                                    $branchId,
                                    $rPlanMonth,
                                    $rWeekNum,
                                    $wStart->format('Y-m-d'),
                                    $wEnd->format('Y-m-d'),
                                ]);
                        $planId = (int) $db->lastInsertId();
                    }

                    $ccStmt = $db->prepare("SELECT company_code FROM companies WHERE id = ?");
                    $ccStmt->execute([$clientId]);
                    $clientCode = $ccStmt->fetchColumn() ?: null;

                    $db->prepare("
            INSERT INTO work_plan_entries
            (plan_id, client_id, client_code, assigned_to, plan_date, day_of_week,
             planned_time_in, planned_time_out, planned_hours, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
                                $planId,
                                $clientId,
                                $clientCode,
                                $uid,
                                $rescheduleDate,
                                $rDow,
                                $rescheduleTimeIn,
                                $rescheduleTimeOut,
                                $plannedHours,
                                $rescheduleNotes !== '' ? $rescheduleNotes : $workDesc,
                            ]);
                    $newEntryId = (int) $db->lastInsertId();

                    $db->prepare("UPDATE work_logs SET rescheduled_to_entry_id = ? WHERE id = ?")
                        ->execute([$newEntryId, $logId]);
                }
            } elseif ($existingEntryId) {
                // Status changed away from rescheduled — clean up the orphaned plan entry
                $db->prepare("DELETE FROM work_plan_entries WHERE id = ?")->execute([$existingEntryId]);
                $db->prepare("UPDATE work_logs SET rescheduled_to_entry_id = NULL WHERE id = ?")->execute([$logId]);
            }

            // Notify supervisor if managed_by set
            if (!empty($user['managed_by'])) {
                try {
                    $db->prepare("
                        INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
                        VALUES (?, 'task', 'Visit Log Updated', ?, ?, 0, NOW())
                    ")->execute([
                                (int) $user['managed_by'],
                                $user['full_name'] . ' updated a visit log for ' . date('d M Y', strtotime($logDate)),
                                APP_URL . '/staff/planning/log_list.php?month=' . $month,
                            ]);
                } catch (Exception $ne) {
                }
            }

            logActivity('Edited log #' . $logId, 'consulting');
            setFlash('success', 'Visit log updated successfully!');
            header('Location: log_list.php?month=' . $month);
            exit;

        } catch (Exception $e) {
            $errors[] = 'Failed to save: ' . $e->getMessage();
        }
    }
}

// ── Visit status styles ────────────────────────────────────────
$vstStyles = [
    'visited' => ['bg' => '#ecfdf5', 'color' => '#10b981', 'icon' => 'fa-check-circle'],
    'missed' => ['bg' => '#fef2f2', 'color' => '#ef4444', 'icon' => 'fa-times-circle'],
    'rescheduled' => ['bg' => '#fffbeb', 'color' => '#f59e0b', 'icon' => 'fa-redo'],
];
$currentVst = $postData['visit_status'] ?? $log['visit_status'] ?? 'visited';
$vs = $vstStyles[$currentVst] ?? $vstStyles['visited'];

$pageTitle = 'Edit Visit Log';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
    .required-star {
        color: #ef4444;
    }

    .edit-warning {
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 10px;
        padding: 12px 16px;
        font-size: .8rem;
        color: #92400e;
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 16px;
    }

    .status-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: .75rem;
        font-weight: 700;
    }

    .hrs-display {
        background: var(--cn3);
        border-radius: 8px;
        padding: 10px 14px;
        text-align: center;
    }

    .vst-option {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        border-radius: 8px;
        border: 2px solid transparent;
        cursor: pointer;
        transition: all .15s;
        font-size: .82rem;
        font-weight: 600;
    }

    .vst-option:hover {
        border-color: currentColor;
        opacity: .85;
    }

    .vst-option.active {
        border-color: currentColor;
    }

    .info-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 6px 0;
        border-bottom: 1px solid var(--cn4);
        font-size: .78rem;
    }

    .info-row:last-child {
        border-bottom: none;
    }

    .info-label {
        color: var(--muted);
        min-width: 120px;
    }

    .info-value {
        font-weight: 600;
        color: #1f2937;
        text-align: right;
    }
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <?= flashHtml() ?>

            <!-- ── Page Hero ──────────────────────────────────────────── -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-pencil-alt"></i> Consulting · Edit Log</div>
                        <h4>Edit Visit Log
                            <span class="status-chip ms-2"
                                style="background:<?= $vs['bg'] ?>;color:<?= $vs['color'] ?>;">
                                <i class="fas <?= $vs['icon'] ?>"></i>
                                <?= ucfirst($currentVst) ?>
                            </span>
                        </h4>
                        <p>
                            <?= htmlspecialchars($user['full_name']) ?>
                            · <?= date('d M Y', strtotime($log['log_date'])) ?>
                            · <?= $log['day_of_week'] ?>
                            · <?= $monthLabel ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="log_list.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-list me-1"></i> All Logs
                        </a>
                        <a href="index.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- ── Own-log notice ─────────────────────────────────────── -->
            <div class="edit-warning">
                <i class="fas fa-info-circle fa-lg" style="color:#f59e0b;flex-shrink:0;"></i>
                <div>You are editing <strong>your own</strong> visit log. Only your logs can be modified.</div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="cn-alert cn-alert-danger" style="margin-bottom:16px;">
                    <div style="font-weight:700;font-size:.84rem;margin-bottom:5px;">
                        <i class="fas fa-exclamation-circle me-1"></i>Please fix the following:
                    </div>
                    <ul style="margin:0;padding-left:1.2rem;font-size:.8rem;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="logForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

                    <!-- ── LEFT ───────────────────────────────────────── -->
                    <div>

                        <!-- Client & Date -->
                        <div class="cn-panel" style="margin-bottom:16px;">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-building me-2" style="color:var(--gold)"></i>Visit Details
                                </span>
                            </div>
                            <div style="padding:16px 18px;">

                                <!-- Client -->
                                <div class="cn-form-group" style="margin-bottom:14px;">
                                    <label class="cn-label">Client <span class="required-star">*</span></label>
                                    <select name="client_id" id="clientSelect" class="cn-input" required>
                                        <option value="">— Select Client —</option>
                                        <?php foreach ($companies as $c): ?>
                                            <option value="<?= $c['id'] ?>"
                                                data-code="<?= htmlspecialchars($c['company_code'] ?? '') ?>"
                                                data-pan="<?= htmlspecialchars($c['pan_number'] ?? '') ?>"
                                                <?= ($postData['client_id'] ?? $log['client_id']) == $c['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($c['company_name']) ?>
                                                <?= $c['company_code'] ? ' — ' . htmlspecialchars($c['company_code']) : '' ?>
                                                <?= $c['pan_number'] ? ' — ' . htmlspecialchars($c['pan_number']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Date + Times -->
                                <div
                                    style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px;">
                                    <div>
                                        <label class="cn-label">Log Date <span class="required-star">*</span></label>
                                        <input type="date" name="log_date" class="cn-input" required id="logDate"
                                            value="<?= htmlspecialchars($postData['log_date'] ?? $log['log_date']) ?>"
                                            onchange="updateDayLabel(this.value)">
                                        <div id="dayLabel"
                                            style="font-size:.72rem;color:var(--muted);margin-top:4px;font-weight:600;">
                                            <?= $log['day_of_week'] ?>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="cn-label">Time In</label>
                                        <input type="time" name="time_in" class="cn-input time-in" id="timeIn"
                                            value="<?= htmlspecialchars($postData['time_in'] ?? $log['time_in'] ?? '') ?>"
                                            onchange="calcHours()">
                                    </div>
                                    <div>
                                        <label class="cn-label">Time Out</label>
                                        <input type="time" name="time_out" class="cn-input time-out" id="timeOut"
                                            value="<?= htmlspecialchars($postData['time_out'] ?? $log['time_out'] ?? '') ?>"
                                            onchange="calcHours()">
                                    </div>
                                </div>

                                <!-- Work description -->
                                <div class="cn-form-group">
                                    <label class="cn-label">Work Description</label>
                                    <textarea name="work_description" class="cn-input" rows="3"
                                        placeholder="Describe the work done during this visit…"><?= htmlspecialchars($postData['work_description'] ?? $log['work_description'] ?? '') ?></textarea>
                                </div>

                            </div>
                        </div>

                        <!-- Visit Status -->
                        <div class="cn-panel">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-flag me-2" style="color:var(--gold)"></i>Visit Status
                                </span>
                            </div>
                            <div style="padding:14px 18px;">
                                <input type="hidden" name="visit_status" id="visitStatusInput"
                                    value="<?= htmlspecialchars($postData['visit_status'] ?? $log['visit_status'] ?? 'visited') ?>">
                                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">

                                    <?php
                                    $vstOpts = [
                                        'visited' => ['bg' => '#ecfdf5', 'color' => '#10b981', 'icon' => 'fa-check-circle', 'label' => 'Visited'],
                                        'missed' => ['bg' => '#fef2f2', 'color' => '#ef4444', 'icon' => 'fa-times-circle', 'label' => 'Missed'],
                                        'rescheduled' => ['bg' => '#fffbeb', 'color' => '#f59e0b', 'icon' => 'fa-redo', 'label' => 'Rescheduled'],
                                    ];
                                    $selectedVst = $postData['visit_status'] ?? $log['visit_status'] ?? 'visited';
                                    foreach ($vstOpts as $val => $opt):
                                        ?>
                                        <div class="vst-option <?= $selectedVst === $val ? 'active' : '' ?>"
                                            style="background:<?= $opt['bg'] ?>;color:<?= $opt['color'] ?>;"
                                            onclick="selectVst('<?= $val ?>', this)">
                                            <i class="fas <?= $opt['icon'] ?>" style="font-size:1.1rem;"></i>
                                            <span><?= $opt['label'] ?></span>
                                        </div>
                                    <?php endforeach; ?>

                                </div>
                            </div>
                        </div>
                        <!-- Reschedule Details -->
                        <div class="cn-panel" style="margin-top:16px;" id="rescheduleSection"
                            style="display:<?= $selectedVst === 'rescheduled' ? 'block' : 'none' ?>;">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-redo me-2" style="color:var(--gold)"></i>Reschedule To — New Plan
                                    Entry
                                </span>
                            </div>
                            <div style="padding:16px 18px;">
                                <div
                                    style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px;">
                                    <div>
                                        <label class="cn-label">New Visit Date <span
                                                class="required-star">*</span></label>
                                        <input type="date" name="reschedule_date" id="rescheduleDate" class="cn-input"
                                            value="<?= htmlspecialchars($postData['reschedule_date'] ?? $rescheduledEntry['plan_date'] ?? '') ?>">
                                    </div>
                                    <div>
                                        <label class="cn-label">Planned Time In</label>
                                        <input type="time" name="reschedule_time_in" id="rescheduleTimeIn"
                                            class="cn-input"
                                            value="<?= htmlspecialchars($postData['reschedule_time_in'] ?? $rescheduledEntry['planned_time_in'] ?? '') ?>"
                                            onchange="calcRescheduleDuration()">
                                    </div>
                                    <div>
                                        <label class="cn-label">Planned Time Out</label>
                                        <input type="time" name="reschedule_time_out" id="rescheduleTimeOut"
                                            class="cn-input"
                                            value="<?= htmlspecialchars($postData['reschedule_time_out'] ?? $rescheduledEntry['planned_time_out'] ?? '') ?>"
                                            onchange="calcRescheduleDuration()">
                                    </div>
                                </div>
                                <div style="display:grid;grid-template-columns:160px 1fr;gap:12px;align-items:start;">
                                    <div
                                        style="background:var(--cn3);border-radius:8px;padding:10px;text-align:center;">
                                        <div style="font-size:.7rem;color:var(--muted);">Planned Duration</div>
                                        <div style="font-size:1.1rem;font-weight:800;color:var(--gold);"
                                            id="rescheduleDurationDisp">—</div>
                                    </div>
                                    <div>
                                        <label class="cn-label">Reschedule Notes</label>
                                        <input type="text" name="reschedule_notes" class="cn-input"
                                            placeholder="Reason / new arrangement notes..."
                                            value="<?= htmlspecialchars($postData['reschedule_notes'] ?? $rescheduledEntry['notes'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── RIGHT ──────────────────────────────────────── -->
                    <div>

                        <!-- Duration summary -->
                        <div class="cn-panel" style="margin-bottom:14px;">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-clock me-2" style="color:var(--gold)"></i>Duration
                                </span>
                            </div>
                            <div style="padding:16px;">
                                <div class="hrs-display">
                                    <div style="font-size:2.2rem;font-weight:800;color:var(--gold);line-height:1;"
                                        id="durationDisplay">
                                        <?php
                                        $h = (float) $log['duration_hours'];
                                        echo $h > 0 ? number_format($h, 2) . 'h' : '—';
                                        ?>
                                    </div>
                                    <div style="font-size:.72rem;color:var(--muted);margin-top:4px;">
                                        Total Hours
                                    </div>
                                </div>
                                <?php if ($log['time_in'] && $log['time_out']): ?>
                                    <div style="font-size:.73rem;color:var(--muted);text-align:center;margin-top:8px;">
                                        <?= date('g:i A', strtotime($log['time_in'])) ?>
                                        &rarr;
                                        <?= date('g:i A', strtotime($log['time_out'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Save Actions -->
                        <div class="cn-panel" style="margin-bottom:14px;">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-save me-2" style="color:var(--gold)"></i>Save Changes
                                </span>
                            </div>
                            <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px;">
                                <button type="submit" class="cn-btn cn-btn-gold" style="justify-content:center;">
                                    <i class="fas fa-save"></i> Update Log
                                </button>
                                <a href="log_list.php?month=<?= $month ?>" class="cn-btn cn-btn-out"
                                    style="justify-content:center;">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </div>

                        <!-- Log info -->
                        <div class="cn-panel" style="margin-bottom:14px;">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-info-circle me-2" style="color:var(--gold)"></i>Log Info
                                </span>
                            </div>
                            <div style="padding:12px 16px;">
                                <div class="info-row">
                                    <span class="info-label">Log #</span>
                                    <span class="info-value"><?= $logId ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Week</span>
                                    <span class="info-value">Week <?= $log['week_number'] ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Month</span>
                                    <span class="info-value"><?= $monthLabel ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Logged</span>
                                    <span class="info-value"><?= date('d M Y', strtotime($log['created_at'])) ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Last Updated</span>
                                    <span class="info-value"><?= date('d M Y', strtotime($log['updated_at'])) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Linked plan (if any) -->
                        <?php if ($linkedPlan): ?>
                            <div class="cn-panel">
                                <div class="cn-panel-hd">
                                    <span class="cn-panel-title">
                                        <i class="fas fa-link me-2" style="color:var(--gold)"></i>Linked Plan
                                    </span>
                                </div>
                                <div style="padding:12px 16px;">
                                    <div class="info-row">
                                        <span class="info-label">Week</span>
                                        <span class="info-value">Week <?= $linkedPlan['week_number'] ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Period</span>
                                        <span class="info-value">
                                            <?= date('d M', strtotime($linkedPlan['week_start_date'])) ?>
                                            –
                                            <?= date('d M', strtotime($linkedPlan['week_end_date'])) ?>
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Planned In</span>
                                        <span class="info-value">
                                            <?= $linkedPlan['planned_time_in']
                                                ? date('g:i A', strtotime($linkedPlan['planned_time_in']))
                                                : '—' ?>
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Planned Out</span>
                                        <span class="info-value">
                                            <?= $linkedPlan['planned_time_out']
                                                ? date('g:i A', strtotime($linkedPlan['planned_time_out']))
                                                : '—' ?>
                                        </span>
                                    </div>
                                    <div style="margin-top:8px;">
                                        <a href="plan_view.php?id=<?= $linkedPlan['plan_id'] ?? '' ?>"
                                            style="font-size:.75rem;color:var(--blue);">
                                            <i class="fas fa-eye me-1"></i>View Plan
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                </div><!-- /grid -->

            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
    // ── TomSelect on client ────────────────────────────────────────
    new TomSelect(document.getElementById('clientSelect'), {
        placeholder: 'Search by name, code or PAN…',
        maxOptions: 500,
        searchField: ['text'],
        render: {
            option: function (data, escape) {
                const code = data.$option?.dataset?.code || '';
                const pan = data.$option?.dataset?.pan || '';
                return `<div style="padding:4px 2px;">
                <div style="font-weight:600;font-size:.83rem;">${escape(data.text.split(' — ')[0])}</div>
                <div style="font-size:.7rem;color:#9ca3af;display:flex;gap:10px;margin-top:1px;">
                    ${code ? `<span><i class="fas fa-tag" style="font-size:.6rem;"></i> ${escape(code)}</span>` : ''}
                    ${pan ? `<span><i class="fas fa-id-card" style="font-size:.6rem;"></i> PAN: ${escape(pan)}</span>` : ''}
                </div>
            </div>`;
            },
            item: function (data, escape) {
                const pan = data.$option?.dataset?.pan || '';
                const name = escape(data.text.split(' — ')[0]);
                return pan
                    ? `<div>${name} <span style="font-size:.72rem;color:#9ca3af;">(PAN: ${escape(pan)})</span></div>`
                    : `<div>${name}</div>`;
            }
        }
    });

    // ── Duration calculator ────────────────────────────────────────
    function calcHours() {
        const tin = document.getElementById('timeIn').value;
        const tout = document.getElementById('timeOut').value;
        const disp = document.getElementById('durationDisplay');
        if (tin && tout) {
            const diff = (new Date('1970-01-01T' + tout) - new Date('1970-01-01T' + tin)) / 3600000;
            disp.textContent = diff > 0 ? diff.toFixed(2) + 'h' : '—';
            disp.style.color = diff >= 4 ? '#10b981' : (diff >= 2 ? '#f59e0b' : '#ef4444');
        } else {
            disp.textContent = '—';
            disp.style.color = 'var(--gold)';
        }
    }

    // ── Day label updater ──────────────────────────────────────────
    function updateDayLabel(dateVal) {
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        if (dateVal) {
            const d = new Date(dateVal + 'T00:00:00');
            document.getElementById('dayLabel').textContent = days[d.getDay()];
        } else {
            document.getElementById('dayLabel').textContent = '';
        }
    }

    // ── Visit status selector ──────────────────────────────────────
    function selectVst(val, el) {
        document.getElementById('visitStatusInput').value = val;
        document.querySelectorAll('.vst-option').forEach(o => o.classList.remove('active'));
        el.classList.add('active');
        toggleReschedule();
    }

    function toggleReschedule() {
        const status = document.getElementById('visitStatusInput').value;
        const section = document.getElementById('rescheduleSection');
        const dateField = document.getElementById('rescheduleDate');
        if (status === 'rescheduled') {
            section.style.display = 'block';
            dateField.setAttribute('required', 'required');
        } else {
            section.style.display = 'none';
            dateField.removeAttribute('required');
        }
    }

    function calcRescheduleDuration() {
        const tin = document.getElementById('rescheduleTimeIn').value;
        const tout = document.getElementById('rescheduleTimeOut').value;
        const disp = document.getElementById('rescheduleDurationDisp');
        if (tin && tout) {
            const diff = (new Date('1970-01-01T' + tout) - new Date('1970-01-01T' + tin)) / 3600000;
            disp.textContent = diff > 0 ? diff.toFixed(2) + 'h' : '—';
            disp.style.color = diff > 0 ? '#c9a84c' : '#ef4444';
        } else {
            disp.textContent = '—';
            disp.style.color = '#9ca3af';
        }
    }

    // Init duration on load
    calcHours();
    toggleReschedule();
    calcRescheduleDuration();
</script>
<?php include '../../includes/footer.php'; ?>