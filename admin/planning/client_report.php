<?php
/**
 * admin/planning/client_report.php
 * Client-wise Performance Report (Admin / Executive / Superadmin)
 *
 * Features:
 *  • Shows every client visited/planned by any in-scope staff
 *  • Includes staff with no department (multi-dept / unassigned)
 *  • Per-client: hours, visits, staff involved, planned vs actual, efficiency
 *  • Client detail drilldown: day-wise visit log per client
 *  • Top-clients horizontal bar chart
 *  • Notification badge via plan_notifications table
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];
$currentRole = $_SESSION['role'] ?? ($user['role_name'] ?? '');
$branchId = (int) ($user['branch_id'] ?? 0);

// Detect primary dept
$__deptMetaStmt = $db->prepare("
    SELECT d.id, d.dept_code, d.dept_name 
    FROM departments d 
    WHERE d.id = ?
");
$__deptMetaStmt->execute([$user['department_id']]);
$__deptMeta = $__deptMetaStmt->fetch(PDO::FETCH_ASSOC);
$__primaryDeptCode = $__deptMeta['dept_code'] ?? '';
$__isBranchManager = ($__primaryDeptCode === 'CORE');
$__isConsultingPrimary = ($__primaryDeptCode === 'CON' 
    || stripos($__deptMeta['dept_name'] ?? '', 'consult') !== false);

// Check UDA for consulting dept
$__udaConsStmt = $db->prepare("
    SELECT d.id, d.dept_code FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ? AND (d.dept_code = 'CON' 
        OR d.dept_name LIKE '%consult%')
    LIMIT 1
");
$__udaConsStmt->execute([$uid]);
$__udaConsDept = $__udaConsStmt->fetch(PDO::FETCH_ASSOC);
$__hasUdaConsulting = !empty($__udaConsDept);

// Resolve effective consulting dept ID
if ($__isConsultingPrimary) {
    $deptId = (int) $user['department_id'];
} elseif ($__hasUdaConsulting) {
    $deptId = (int) $__udaConsDept['id'];
} else {
    $deptId = (int) ($user['department_id'] ?? 0);
}

// ── Month / filter ────────────────────────────────────────────
$now = new DateTime();
$month = $_GET['month'] ?? $now->format('Y-m');
$monthDate = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthEnd = $monthDate->format('Y-m-t');
$monthLabel = $monthDate->format('F Y');

$filterClientId = (int) ($_GET['client_id'] ?? 0) ?: null;
$filterStaffId = (int) ($_GET['staff_id'] ?? 0) ?: null;
$filterFrom = $_GET['from'] ?? $monthStart;
$filterTo = $_GET['to'] ?? $monthEnd;
$filterFrom = max($filterFrom, $monthStart);
$filterTo = min($filterTo, $monthEnd);
$filterStatus = $_GET['visit_status'] ?? '';

// ── Department name ───────────────────────────────────────────
$deptRow = $db->prepare("SELECT dept_name FROM departments WHERE id=?");
$deptRow->execute([$deptId]);
$deptName = $deptRow->fetchColumn() ?: 'Consulting';

// ── Unread notifications ──────────────────────────────────────
$notifCount = (int) $db->query("
    SELECT COUNT(*) FROM plan_notifications
    WHERE user_id={$uid} AND is_read=0
")->fetchColumn();

// ── Scope staff (same dept + no-dept in branch) ───────────────
if ($__isBranchManager) {
    // BM: all staff in their branch who are in consulting dept or have UDA consulting
    $scopeStmt = $db->prepare("
        SELECT DISTINCT u.id, u.full_name, u.employee_id, u.department_id
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
        LEFT JOIN departments ud ON ud.id = uda.department_id
        WHERE u.is_active = 1
          AND u.branch_id = ?
          AND (
              d.dept_code = 'CON'
              OR d.dept_name LIKE '%consult%'
              OR ud.dept_code = 'CON'
              OR ud.dept_name LIKE '%consult%'
          )
        ORDER BY u.full_name
    ");
    $scopeStmt->execute([$branchId]);
} else {
    // Consulting primary or UDA consulting: same dept + no-dept in branch
    $scopeStmt = $db->prepare("
        SELECT DISTINCT u.id, u.full_name, u.employee_id, u.department_id
        FROM users u
        LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
        WHERE u.is_active = 1
          AND u.branch_id = ?
          AND (
              u.id = ?
              OR u.department_id = ?
              OR u.department_id IS NULL
              OR u.department_id = 0
              OR uda.department_id = ?
          )
        ORDER BY u.full_name
    ");
    $scopeStmt->execute([$branchId, $uid, $deptId, $deptId]);
}
$scopeStaff = $scopeStmt->fetchAll(PDO::FETCH_ASSOC);
$scopeIds   = array_unique(array_map('intval', array_column($scopeStaff, 'id')));
if (!in_array($uid, $scopeIds)) $scopeIds[] = $uid;
$inList     = implode(',', $scopeIds) ?: '0';

// Build active in-list (filter by staff if selected)
$activeInList = $inList;
if ($filterStaffId && in_array($filterStaffId, $scopeIds)) {
    $activeInList = (string) $filterStaffId;
}

// ── Client list for filter dropdown ──────────────────────────
$clientsForFilter = $db->query("
    SELECT DISTINCT c.id, c.company_name, c.company_code, c.pan_number
    FROM companies c
    WHERE c.is_active = 1
    ORDER BY c.company_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Staff list for filter dropdown ───────────────────────────
$staffForFilter = $scopeStaff;

// ════════════════════════════════════════════════════════════════
// A. AGGREGATE KPIs
// ════════════════════════════════════════════════════════════════
$kpiWhere = "wl.month_year='{$month}' AND wl.user_id IN ({$activeInList})";
if ($filterClientId)
    $kpiWhere .= " AND wl.client_id={$filterClientId}";

$kpi = $db->query("
    SELECT
        COUNT(*)                                AS total_logs,
        COALESCE(SUM(duration_hours),0)         AS total_hours,
        SUM(visit_status='visited')             AS visited,
        SUM(visit_status='missed')              AS missed,
        SUM(visit_status='rescheduled')         AS rescheduled,
        COUNT(DISTINCT client_id)               AS unique_clients,
        COUNT(DISTINCT user_id)                 AS active_staff
    FROM work_logs wl
    WHERE {$kpiWhere}
")->fetch(PDO::FETCH_ASSOC);

// ── Total planned hours for scope ─────────────────────────────
$totalPlanned = (float) $db->query("
    SELECT COALESCE(SUM(wpe.planned_hours),0)
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    WHERE wp.plan_month = '{$monthStart}'
      AND wpe.assigned_to IN ({$activeInList})
      " . ($filterClientId ? "AND wpe.client_id={$filterClientId}" : "") . "
")->fetchColumn();

// ════════════════════════════════════════════════════════════════
// B. CLIENT-WISE PERFORMANCE
// ════════════════════════════════════════════════════════════════
$params = [];
$clientWhere = "wl.month_year=? AND wl.user_id IN ({$activeInList})";
$params[] = $month;

if ($filterClientId) {
    $clientWhere .= " AND wl.client_id=?";
    $params[] = $filterClientId;
}

if ($filterStatus) {
    $clientWhere .= " AND wl.visit_status=?";
    $params[] = $filterStatus;
}

$stmt = $db->prepare("
    SELECT
        c.id AS client_id,
        c.company_name,
        c.company_code,
        COUNT(wl.id) AS total_visits,
        SUM(wl.visit_status='visited') AS visited,
        SUM(wl.visit_status='missed') AS missed,
        SUM(wl.visit_status='rescheduled') AS rescheduled,
        COALESCE(SUM(wl.duration_hours),0) AS actual_hours,
        COUNT(DISTINCT wl.user_id) AS staff_count,
        GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS staff_names,
        (SELECT COALESCE(SUM(wpe.planned_hours),0)
         FROM work_plan_entries wpe
         JOIN work_plans wp ON wp.id=wpe.plan_id
         WHERE wpe.client_id=c.id
           AND wp.plan_month='{$monthStart}'
           AND wpe.assigned_to IN ({$activeInList})) AS planned_hours,
        (SELECT COUNT(DISTINCT wpe.id)
         FROM work_plan_entries wpe
         JOIN work_plans wp ON wp.id=wpe.plan_id
         WHERE wpe.client_id=c.id
           AND wp.plan_month='{$monthStart}'
           AND wpe.assigned_to IN ({$activeInList})) AS planned_entries,
        (SELECT COUNT(DISTINCT CASE
            WHEN wl2.client_id=wpe2.client_id AND wl2.log_date=wpe2.plan_date
            THEN wpe2.id END)
         FROM work_plan_entries wpe2
         JOIN work_plans wp2 ON wp2.id=wpe2.plan_id
         LEFT JOIN work_logs wl2
             ON wl2.client_id=wpe2.client_id
             AND wl2.log_date=wpe2.plan_date
             AND wl2.user_id=wpe2.assigned_to
         WHERE wpe2.client_id=c.id
           AND wp2.plan_month='{$monthStart}'
           AND wpe2.assigned_to IN ({$activeInList})) AS matched_visits,
        MIN(wl.log_date) AS first_visit,
        MAX(wl.log_date) AS last_visit,
        COUNT(DISTINCT wl.log_date) AS visit_days
    FROM work_logs wl
    LEFT JOIN companies c ON c.id=wl.client_id
    LEFT JOIN users u ON u.id=wl.user_id
    WHERE {$clientWhere}
    GROUP BY c.id, c.company_name, c.company_code
    ORDER BY actual_hours DESC
");

$stmt->execute($params);
$clientPerf = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Chart data: top 10 clients ────────────────────────────────
$topClientNames = [];
$topClientHours = [];
$topClientVisits = [];
$topClientEff = [];
foreach (array_slice($clientPerf, 0, 10) as $cp) {
    $topClientNames[] = mb_strimwidth($cp['company_name'] ?? '—', 0, 18, '…');
    $topClientHours[] = (float) $cp['actual_hours'];
    $topClientVisits[] = (int) $cp['total_visits'];
    $pe = (int) $cp['planned_entries'];
    $mv = (int) $cp['matched_visits'];
    $topClientEff[] = $pe > 0 ? round(min(($mv / $pe) * 100, 100), 1) : 0;
}

// ════════════════════════════════════════════════════════════════
// C. DRILLDOWN — Day-wise logs for a selected client
// ════════════════════════════════════════════════════════════════
$drilldownLogs = [];
if ($filterClientId) {
    $drilldownSQL = "
        SELECT wl.log_date, wl.day_of_week, wl.time_in, wl.time_out,
               wl.duration_hours, wl.visit_status, wl.work_description,
               u.full_name AS staff_name, u.employee_id
        FROM work_logs wl
        JOIN users u ON u.id = wl.user_id
        WHERE wl.month_year = '{$month}'
          AND wl.client_id = {$filterClientId}
          AND wl.user_id IN ({$activeInList})
          AND wl.log_date BETWEEN '{$filterFrom}' AND '{$filterTo}'
        ORDER BY wl.log_date DESC, wl.time_in ASC
    ";
    $drilldownLogs = $db->query($drilldownSQL)->fetchAll(PDO::FETCH_ASSOC);
}

// ════════════════════════════════════════════════════════════════
// D. PLANNED ENTRIES (not yet logged) for selected client
// ════════════════════════════════════════════════════════════════
$unloggedPlans = [];
if ($filterClientId) {
    $unloggedPlans = $db->query("
        SELECT wpe.plan_date, wpe.day_of_week, wpe.planned_time_in, wpe.planned_time_out,
               wpe.planned_hours, wpe.notes, u.full_name AS staff_name
        FROM work_plan_entries wpe
        JOIN work_plans wp ON wp.id = wpe.plan_id
        JOIN users u ON u.id = wpe.assigned_to
        LEFT JOIN work_logs wl
            ON wl.client_id = wpe.client_id
            AND wl.log_date = wpe.plan_date
            AND wl.user_id  = wpe.assigned_to
        WHERE wpe.client_id = {$filterClientId}
          AND wp.plan_month = '{$monthStart}'
          AND wpe.assigned_to IN ({$activeInList})
          AND wl.id IS NULL
          AND wpe.plan_date BETWEEN '{$filterFrom}' AND '{$filterTo}'
        ORDER BY wpe.plan_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Helpers ───────────────────────────────────────────────────
function vstBadgeCR(string $s): string
{
    $map = [
        'visited' => ['#ecfdf5', '#10b981', 'fa-check-circle', 'Visited'],
        'missed' => ['#fef2f2', '#ef4444', 'fa-times-circle', 'Missed'],
        'rescheduled' => ['#fffbeb', '#f59e0b', 'fa-redo', 'Rescheduled'],
    ];
    [$bg, $col, $ico, $lbl] = $map[$s] ?? ['#f9fafb', '#9ca3af', 'fa-circle', '—'];
    return "<span style='background:{$bg};color:{$col};padding:.15rem .55rem;border-radius:99px;
            font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;white-space:nowrap;'>
            <i class=\"fas {$ico}\" style=\"font-size:.6rem;\"></i>{$lbl}</span>";
}

function safeEffCR(float $actual, float $planned): array
{
    if ($planned <= 0)
        return [0, 0, '#9ca3af'];
    $raw = round(($actual / $planned) * 100, 1);
    $capped = min($raw, 100);
    $color = $capped >= 80 ? '#10b981' : ($capped >= 50 ? '#f59e0b' : '#ef4444');
    return [$capped, $raw, $color];
}

function visitEffCR(int $matched, int $planned): array
{
    if ($planned <= 0)
        return [0, 0, '#9ca3af'];
    $raw = round(($matched / $planned) * 100, 1);
    $capped = min($raw, 100);
    $color = $capped >= 80 ? '#10b981' : ($capped >= 50 ? '#f59e0b' : '#ef4444');
    return [$capped, $raw, $color];
}

$pageTitle = 'Client-wise Report';
include '../../includes/header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">
            <?= flashHtml() ?>

            <!-- ══ HERO ════════════════════════════════════════════════════ -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge">
                            <i class="fas fa-building"></i> Client Performance
                            <?php if ($notifCount > 0): ?>
                                <span style="background:#ef4444;color:#fff;border-radius:99px;padding:.05rem .42rem;
                             font-size:.65rem;font-weight:700;margin-left:.35rem;"><?= $notifCount ?></span>
                            <?php endif; ?>
                        </div>
                        <h4>Client-wise Performance Report</h4>
                        <p>
                            <?= htmlspecialchars($user['full_name']) ?> ·
                            <?= htmlspecialchars($deptName) ?> · <?= $monthLabel ?>
                            <?php if ($filterClientId): ?>
                                <span style="font-size:.72rem;background:#eff6ff;color:#3b82f6;border-radius:99px;
                             padding:.1rem .55rem;margin-left:.35rem;">
                                    Filtered by 1 client
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="month" class="form-control form-control-sm" style="width:150px;"
                            value="<?= $month ?>" onchange="location='?month='+this.value">
                        <a href="plan_approvals.php" class="btn btn-outline-secondary btn-sm position-relative">
                            <i class="fas fa-check-circle me-1"></i>Approvals
                            <?php if ($notifCount > 0): ?>
                                <span style="position:absolute;top:-5px;right:-5px;background:#ef4444;color:#fff;
                             border-radius:50%;width:16px;height:16px;font-size:.6rem;font-weight:700;
                             display:flex;align-items:center;justify-content:center;"><?= $notifCount ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="staff_performance.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-users me-1"></i>Staff Report
                        </a>
                        <a href="../../index.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-th-large me-1"></i>Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- ══ FILTERS ════════════════════════════════════════════════ -->
            <div class="card-mis mb-4">
                <div class="card-mis-body" style="padding:.75rem 1rem;">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label-mis">Month</label>
                            <input type="month" id="filterMonth" class="form-control form-control-sm"
                                value="<?= $month ?>" onchange="applyFilters()">
                        </div>
                        <div class="col-md-3" <?= $__isBranchManager ? 'style="display:none;"' : '' ?>>
                            <label class="form-label-mis">Client</label>
                            <select id="filterClient" class="form-select form-select-sm">
                                <option value="">— All Clients —</option>
                                <?php foreach ($clientsForFilter as $cf): ?>
                                    <option value="<?= $cf['id'] ?>"
                                        data-code="<?= htmlspecialchars($cf['company_code'] ?? '') ?>"
                                        data-pan="<?= htmlspecialchars($cf['pan_number'] ?? '') ?>"
                                        <?= $filterClientId == $cf['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cf['company_name']) ?>
                                        <?= $cf['company_code'] ? ' — ' . $cf['company_code'] : '' ?>
                                        <?= $cf['pan_number'] ? ' — ' . $cf['pan_number'] : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label-mis">Staff</label>
                            <select id="filterStaff" class="form-select form-select-sm" onchange="applyFilters()">
                                <option value="">— All Staff —</option>
                                <?php foreach ($staffForFilter as $sf): ?>
                                    <option value="<?= $sf['id'] ?>" <?= $filterStaffId == $sf['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sf['full_name']) ?>
                                        <?= empty($sf['department_id']) ? ' (Multi-dept)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label-mis">Visit Status</label>
                            <select id="filterVisitStatus" class="form-select form-select-sm" onchange="applyFilters()">
                                <option value="">— All Status —</option>
                                <option value="visited" <?= $filterStatus == 'visited' ? 'selected' : '' ?>>Visited
                                </option>
                                <option value="missed" <?= $filterStatus == 'missed' ? 'selected' : '' ?>>Missed</option>
                                <option value="rescheduled" <?= $filterStatus == 'rescheduled' ? 'selected' : '' ?>>
                                    Rescheduled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label-mis">Date Range</label>
                            <div class="input-group input-group-sm">
                                <input type="date" id="filterFrom" class="form-control" value="<?= $filterFrom ?>"
                                    onchange="applyFilters()">
                                <input type="date" id="filterTo" class="form-control" value="<?= $filterTo ?>"
                                    onchange="applyFilters()">
                            </div>
                        </div>
                        <div class="col-md-1">
                            <a href="client_report.php?month=<?= $month ?>"
                                class="btn btn-outline-secondary btn-sm w-100">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ KPI CARDS ══════════════════════════════════════════════ -->
            <div class="row g-3 mb-4">
                <?php
                $totalHours = (float) ($kpi['total_hours'] ?? 0);
                $visitPct = (int) ($kpi['total_logs'] ?? 0) > 0
                    ? min(100, round(($kpi['visited'] / $kpi['total_logs']) * 100))
                    : 0;
                $visitPctCol = $visitPct >= 80 ? '#10b981' : ($visitPct >= 50 ? '#f59e0b' : '#ef4444');

                $kpiCards = [
                    ['fa-building', '#8b5cf6', '#f5f3ff', 'Clients Served', (int) ($kpi['unique_clients'] ?? 0)],
                    ['fa-users', '#0ea5e9', '#e0f2fe', 'Active Staff', (int) ($kpi['active_staff'] ?? 0)],
                    ['fa-clock', '#3b82f6', '#eff6ff', 'Total Hours', number_format($totalHours, 1) . 'h'],
                    ['fa-calendar-alt', '#c9a84c', '#fefce8', 'Planned Hours', number_format($totalPlanned, 1) . 'h'],
                    ['fa-check-circle', '#10b981', '#ecfdf5', 'Visited', (int) ($kpi['visited'] ?? 0)],
                    ['fa-times-circle', '#ef4444', '#fef2f2', 'Missed', (int) ($kpi['missed'] ?? 0)],
                    ['fa-redo', '#f59e0b', '#fffbeb', 'Rescheduled', (int) ($kpi['rescheduled'] ?? 0)],
                    ['fa-tachometer-alt', $visitPctCol, '#f9fafb', 'Visit Rate', $visitPct . '%'],
                ];
                foreach ($kpiCards as [$icon, $col, $bg, $lbl, $val]):
                    ?>
                    <div class="col-6 col-md-3">
                        <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;
                padding:1rem 1.1rem;display:flex;align-items:center;gap:.8rem;
                box-shadow:0 1px 3px rgba(0,0,0,.04);">
                            <div style="width:40px;height:40px;border-radius:10px;background:<?= $bg ?>;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas <?= $icon ?>" style="color:<?= $col ?>;font-size:.95rem;"></i>
                            </div>
                            <div>
                                <div style="font-size:1.35rem;font-weight:800;color:#1f2937;line-height:1.1;"><?= $val ?>
                                </div>
                                <div style="font-size:.7rem;color:#9ca3af;margin-top:.1rem;"><?= $lbl ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ══ DRILLDOWN — Selected Client Detail ═════════════════════ -->
            <?php if ($filterClientId && !empty($drilldownLogs)): ?>
                <?php
                $selClient = array_values(array_filter($clientPerf, fn($c) => $c['client_id'] == $filterClientId))[0] ?? null;
                ?>
                <?php if ($selClient): ?>
                    <div class="card-mis mb-4" style="border-left:4px solid #3b82f6;">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-search text-warning me-2"></i>
                                Client Detail — <?= htmlspecialchars($selClient['company_name']) ?>
                            </h5>
                            <span
                                style="font-size:.75rem;color:#9ca3af;"><?= htmlspecialchars($selClient['company_code'] ?? '') ?></span>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3 mb-3">
                                <?php foreach ([
                                    ['fa-clock', '#3b82f6', number_format((float) $selClient['actual_hours'], 1) . 'h', 'Actual Hours'],
                                    ['fa-calendar', '#c9a84c', number_format((float) $selClient['planned_hours'], 1) . 'h', 'Planned Hours'],
                                    ['fa-users', '#8b5cf6', (int) $selClient['staff_count'], 'Staff Involved'],
                                    ['fa-check-circle', '#10b981', (int) $selClient['visited'], 'Visited'],
                                    ['fa-times-circle', '#ef4444', (int) $selClient['missed'], 'Missed'],
                                    ['fa-calendar-day', '#0ea5e9', (int) $selClient['visit_days'], 'Visit Days'],
                                ] as [$ico, $col, $val, $lbl]):
                                    ?>
                                    <div class="col-4 col-md-2">
                                        <div style="text-align:center;background:#f9fafb;border-radius:10px;padding:.8rem .5rem;">
                                            <i class="fas <?= $ico ?>"
                                                style="color:<?= $col ?>;font-size:1rem;margin-bottom:.3rem;display:block;"></i>
                                            <div style="font-size:1.15rem;font-weight:800;color:#1f2937;"><?= $val ?></div>
                                            <div style="font-size:.65rem;color:#9ca3af;"><?= $lbl ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($selClient['staff_names']): ?>
                                <div style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">
                                    <i class="fas fa-user-friends me-1 text-warning"></i>
                                    <strong>Staff involved:</strong> <?= htmlspecialchars($selClient['staff_names']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Day-wise visit log -->
                        <div style="border-top:1px solid #f3f4f6;">
                            <div style="padding:.6rem 1rem;font-size:.8rem;font-weight:700;color:#374151;background:#f9fafb;">
                                <i class="fas fa-calendar-day me-1 text-warning"></i>
                                Day-wise Visit Log (<?= count($drilldownLogs) ?> entries)
                            </div>
                            <div class="table-responsive">
                                <table class="table-mis w-100">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th>Staff</th>
                                            <th class="text-center">Time In</th>
                                            <th class="text-center">Time Out</th>
                                            <th class="text-center">Hours</th>
                                            <th>Status</th>
                                            <th>Description</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($drilldownLogs as $dl): ?>
                                            <tr>
                                                <td style="font-size:.83rem;font-weight:500;white-space:nowrap;">
                                                    <?= date('d M Y', strtotime($dl['log_date'])) ?>
                                                </td>
                                                <td style="font-size:.75rem;color:#9ca3af;">
                                                    <?= htmlspecialchars($dl['day_of_week'] ?? '') ?>
                                                </td>
                                                <td>
                                                    <div style="font-size:.83rem;"><?= htmlspecialchars($dl['staff_name']) ?></div>
                                                    <div style="font-size:.68rem;color:#9ca3af;">
                                                        <?= htmlspecialchars($dl['employee_id'] ?? '') ?>
                                                    </div>
                                                </td>
                                                <td class="text-center" style="font-size:.78rem;">
                                                    <?= $dl['time_in'] ? date('g:i A', strtotime($dl['time_in'])) : '—' ?>
                                                </td>
                                                <td class="text-center" style="font-size:.78rem;">
                                                    <?= $dl['time_out'] ? date('g:i A', strtotime($dl['time_out'])) : '—' ?>
                                                </td>
                                                <td class="text-center">
                                                    <span
                                                        style="font-weight:700;color:<?= (float) $dl['duration_hours'] >= 4 ? '#10b981' : ((float) $dl['duration_hours'] >= 2 ? '#f59e0b' : '#ef4444') ?>;">
                                                        <?= number_format((float) $dl['duration_hours'], 1) ?>h
                                                    </span>
                                                </td>
                                                <td><?= vstBadgeCR($dl['visit_status'] ?? '') ?></td>
                                                <td
                                                    style="font-size:.73rem;color:#6b7280;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                    <?= htmlspecialchars(mb_strimwidth($dl['work_description'] ?? '—', 0, 50, '…')) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <!-- Unlogged planned visits -->
                        <?php if (!empty($unloggedPlans)): ?>
                            <div style="border-top:1px solid #fde68a;background:#fffbeb;">
                                <div style="padding:.6rem 1rem;font-size:.8rem;font-weight:700;color:#92400e;">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    Planned but NOT Logged (<?= count($unloggedPlans) ?> entries)
                                </div>
                                <div class="table-responsive">
                                    <table class="table-mis w-100">
                                        <thead>
                                            <tr>
                                                <th>Planned Date</th>
                                                <th>Day</th>
                                                <th>Staff</th>
                                                <th class="text-center">Time In</th>
                                                <th class="text-center">Hours</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($unloggedPlans as $up): ?>
                                                <tr style="background:#fffbeb;">
                                                    <td style="font-size:.83rem;font-weight:500;white-space:nowrap;">
                                                        <?= date('d M Y', strtotime($up['plan_date'])) ?>
                                                    </td>
                                                    <td style="font-size:.75rem;color:#9ca3af;">
                                                        <?= htmlspecialchars($up['day_of_week'] ?? '') ?>
                                                    </td>
                                                    <td style="font-size:.83rem;"><?= htmlspecialchars($up['staff_name']) ?></td>
                                                    <td class="text-center" style="font-size:.78rem;">
                                                        <?= $up['planned_time_in'] ? date('g:i A', strtotime($up['planned_time_in'])) : '—' ?>
                                                    </td>
                                                    <td class="text-center" style="font-size:.8rem;color:#f59e0b;font-weight:600;">
                                                        <?= number_format((float) $up['planned_hours'], 1) ?>h
                                                    </td>
                                                    <td style="font-size:.73rem;color:#6b7280;">
                                                        <?= htmlspecialchars(mb_strimwidth($up['notes'] ?? '—', 0, 40, '…')) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- ══ TOP CLIENTS CHART ══════════════════════════════════════ -->
            <?php if (!empty($topClientNames)): ?>
                <div class="card-mis mb-4">
                    <div class="card-mis-header">
                        <h5><i class="fas fa-chart-bar text-warning me-2"></i>Top Clients by Hours — <?= $monthLabel ?></h5>
                        <span style="font-size:.75rem;color:#9ca3af;">Top <?= count($topClientNames) ?> of
                            <?= count($clientPerf) ?></span>
                    </div>
                    <div class="card-mis-body" style="height:<?= max(200, count($topClientNames) * 42) ?>px;">
                        <canvas id="clientChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ══ CLIENT-WISE TABLE ═══════════════════════════════════════ -->
            <?php if (!empty($clientPerf)): ?>
                <div class="card-mis mb-4" style="border-top:3px solid #8b5cf6;">
                    <div class="card-mis-header">
                        <h5><i class="fas fa-building text-warning me-2"></i>All Clients — <?= $monthLabel ?></h5>
                        <span style="font-size:.78rem;color:#9ca3af;"><?= count($clientPerf) ?> client(s)</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table-mis w-100">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Client</th>
                                    <th class="text-center">Staff</th>
                                    <th class="text-center">Visits</th>
                                    <th class="text-center">Visited</th>
                                    <th class="text-center">Missed</th>
                                    <th class="text-center">Rescheduled</th>
                                    <th class="text-center">Visit Days</th>
                                    <th class="text-center">Planned Hrs</th>
                                    <th class="text-center">Actual Hrs</th>
                                    <th style="min-width:130px;">Hour Eff.</th>
                                    <th style="min-width:130px;">Visit Eff.</th>
                                    <th>First · Last Visit</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clientPerf as $i => $cp):
                                    [$cEff, $cEffRaw, $cEffCol] = safeEffCR((float) $cp['actual_hours'], (float) $cp['planned_hours']);
                                    [$vcEff, $vcEffRaw, $vcEffCol] = visitEffCR((int) $cp['matched_visits'], (int) $cp['planned_entries']);
                                    ?>
                                    <tr <?= $filterClientId == $cp['client_id'] ? 'style="background:#eff6ff;"' : '' ?>>
                                        <td style="color:#9ca3af;font-size:.75rem;"><?= $i + 1 ?></td>
                                        <td>
                                            <div style="font-weight:600;font-size:.85rem;">
                                                <?= htmlspecialchars($cp['company_name'] ?? '—') ?>
                                            </div>
                                            <div style="font-size:.7rem;color:#9ca3af;">
                                                <?= htmlspecialchars($cp['company_code'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span
                                                style="font-size:.78rem;font-weight:600;color:#8b5cf6;"><?= (int) $cp['staff_count'] ?></span>
                                            <?php if ($cp['staff_names']): ?>
                                                <div style="font-size:.63rem;color:#9ca3af;max-width:100px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                                    title="<?= htmlspecialchars($cp['staff_names']) ?>">
                                                    <?= htmlspecialchars(mb_strimwidth($cp['staff_names'], 0, 18, '…')) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><strong><?= $cp['total_visits'] ?></strong></td>
                                        <td class="text-center">
                                            <span
                                                style="background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:20px;font-size:.74rem;font-weight:600;"><?= (int) $cp['visited'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int) $cp['missed'] > 0): ?>
                                                <span
                                                    style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:20px;font-size:.74rem;font-weight:600;"><?= (int) $cp['missed'] ?></span>
                                            <?php else: ?><span style="color:#d1d5db;font-size:.77rem;">—</span><?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int) $cp['rescheduled'] > 0): ?>
                                                <span
                                                    style="background:#fffbeb;color:#b45309;padding:2px 8px;border-radius:20px;font-size:.74rem;font-weight:600;"><?= (int) $cp['rescheduled'] ?></span>
                                            <?php else: ?><span style="color:#d1d5db;font-size:.77rem;">—</span><?php endif; ?>
                                        </td>
                                        <td class="text-center" style="font-size:.8rem;color:#6b7280;">
                                            <?= (int) $cp['visit_days'] ?>
                                        </td>
                                        <td class="text-center" style="color:#3b82f6;font-weight:600;">
                                            <?= number_format((float) $cp['planned_hours'], 1) ?>h
                                        </td>
                                        <td class="text-center">
                                            <strong
                                                style="color:<?= (float) $cp['actual_hours'] >= 4 ? '#10b981' : ((float) $cp['actual_hours'] >= 2 ? '#f59e0b' : '#6b7280') ?>;">
                                                <?= number_format((float) $cp['actual_hours'], 1) ?>h
                                            </strong>
                                        </td>
                                        <!-- Hour efficiency -->
                                        <td>
                                            <?php if ((float) $cp['planned_hours'] > 0): ?>
                                                <div style="display:flex;align-items:center;gap:.4rem;">
                                                    <div
                                                        style="flex:1;background:#f1f5f9;border-radius:99px;height:5px;overflow:hidden;">
                                                        <div
                                                            style="width:<?= $cEff ?>%;height:100%;background:<?= $cEffCol ?>;border-radius:99px;">
                                                        </div>
                                                    </div>
                                                    <span
                                                        style="font-size:.72rem;font-weight:700;color:<?= $cEffCol ?>;min-width:34px;text-align:right;"><?= $cEff ?>%</span>
                                                </div>
                                                <?php if ($cEffRaw > 100): ?>
                                                    <div style="font-size:.62rem;color:#f59e0b;margin-top:2px;">⚠ <?= $cEffRaw ?>% raw
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?><span style="font-size:.74rem;color:#9ca3af;">No
                                                    plan</span><?php endif; ?>
                                        </td>
                                        <!-- Visit efficiency -->
                                        <td>
                                            <?php if ((int) $cp['planned_entries'] > 0): ?>
                                                <div style="display:flex;align-items:center;gap:.4rem;">
                                                    <div
                                                        style="flex:1;background:#f1f5f9;border-radius:99px;height:5px;overflow:hidden;">
                                                        <div
                                                            style="width:<?= $vcEff ?>%;height:100%;background:<?= $vcEffCol ?>;border-radius:99px;">
                                                        </div>
                                                    </div>
                                                    <span
                                                        style="font-size:.72rem;font-weight:700;color:<?= $vcEffCol ?>;min-width:34px;text-align:right;"><?= $vcEff ?>%</span>
                                                </div>
                                                <div style="font-size:.63rem;color:#9ca3af;margin-top:2px;">
                                                    <?= $cp['matched_visits'] ?>/<?= $cp['planned_entries'] ?> visits
                                                </div>
                                            <?php else: ?><span style="font-size:.74rem;color:#9ca3af;">No
                                                    plan</span><?php endif; ?>
                                        </td>
                                        <td style="font-size:.75rem;color:#6b7280;white-space:nowrap;">
                                            <?= $cp['first_visit'] ? date('d M', strtotime($cp['first_visit'])) : '—' ?>
                                            <?php if ($cp['first_visit'] && $cp['last_visit'] && $cp['first_visit'] !== $cp['last_visit']): ?>
                                                <span style="color:#d1d5db;"> →
                                                </span><?= date('d M', strtotime($cp['last_visit'])) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="?month=<?= $month ?>&client_id=<?= $cp['client_id'] ?>"
                                                style="font-size:.72rem;color:#3b82f6;text-decoration:none;">
                                                <i class="fas fa-search-plus"></i> Drill
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f9fafb;font-weight:700;">
                                    <td colspan="3" style="padding:10px 14px;font-size:.82rem;color:#374151;">
                                        <i class="fas fa-calculator me-1 text-warning"></i>TOTAL
                                    </td>
                                    <td class="text-center"><?= (int) ($kpi['total_logs'] ?? 0) ?></td>
                                    <td class="text-center" style="color:#15803d;"><?= (int) ($kpi['visited'] ?? 0) ?></td>
                                    <td class="text-center" style="color:#b91c1c;"><?= (int) ($kpi['missed'] ?? 0) ?></td>
                                    <td class="text-center" style="color:#b45309;"><?= (int) ($kpi['rescheduled'] ?? 0) ?>
                                    </td>
                                    <td></td>
                                    <td class="text-center" style="color:#3b82f6;">
                                        <?= number_format(array_sum(array_column($clientPerf, 'planned_hours')), 1) ?>h
                                    </td>
                                    <td class="text-center" style="color:#c9a84c;"><?= number_format($totalHours, 1) ?>h
                                    </td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="card-mis mb-4">
                    <div class="card-mis-body" style="text-align:center;padding:3rem;color:#9ca3af;">
                        <i class="fas fa-building"
                            style="font-size:2rem;margin-bottom:.75rem;opacity:.3;display:block;"></i>
                        No client data found for the selected filters.
                    </div>
                </div>
            <?php endif; ?>

        </div><!-- /padding -->
        <?php include '../../includes/footer.php'; ?>
    </div>
</div>

<script>
    <?php if (!empty($topClientNames)): ?>
        new Chart(document.getElementById('clientChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($topClientNames) ?>,
                datasets: [
                    {
                        label: 'Actual Hours',
                        data: <?= json_encode($topClientHours) ?>,
                        backgroundColor: 'rgba(139,92,246,.65)', borderColor: '#8b5cf6',
                        borderWidth: 1.5, borderRadius: 5,
                    },
                    {
                        label: 'Visits',
                        data: <?= json_encode($topClientVisits) ?>,
                        backgroundColor: 'rgba(201,168,76,.4)', borderColor: '#c9a84c',
                        borderWidth: 1.5, borderRadius: 5,
                    }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: { size: 11 } } } },
                scales: {
                    x: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 } } },
                    y: { grid: { display: false }, ticks: { font: { size: 11 } } }
                }
            }
        });
    <?php endif; ?>
// TomSelect on client filter
    let clientTs = null;
    <?php if (!$__isBranchManager): ?>
    clientTs = new TomSelect('#filterClient', {
        placeholder: 'Search by name, code or PAN…',
        allowEmptyOption: true,
        maxOptions: 500,
        searchField: ['text'],
        score: function (search) {
            const s = search.toLowerCase();
            return function (item) {
                const text = (item.text || '').toLowerCase();
                const code = (item.$option?.dataset?.code || '').toLowerCase();
                const pan = (item.$option?.dataset?.pan || '').toLowerCase();
                return (text.includes(s) || code.includes(s) || pan.includes(s)) ? 1 : 0;
            };
        },
        render: {
            option: function (data, escape) {
                const code = data.$option?.dataset?.code || '';
                const pan = data.$option?.dataset?.pan || '';
                const name = escape(data.text.split(' — ')[0]);
                return `<div style="padding:3px 2px;">
                <div style="font-weight:600;font-size:.83rem;">${name}</div>
                <div style="font-size:.7rem;color:#9ca3af;display:flex;gap:8px;margin-top:1px;">
                    ${code ? `<span><i class="fas fa-tag" style="font-size:.6rem;"></i> ${escape(code)}</span>` : ''}
                    ${pan ? `<span><i class="fas fa-id-card" style="font-size:.6rem;"></i> PAN: ${escape(pan)}</span>` : ''}
                </div>
            </div>`;
            },
            item: function (data, escape) {
                const pan = data.$option?.dataset?.pan || '';
                const name = escape(data.text.split(' — ')[0]);
                return pan
                    ? `<div>${name} <span style="font-size:.7rem;color:#9ca3af;">(PAN: ${escape(pan)})</span></div>`
                    : `<div>${name}</div>`;
            }
        },
        onChange: function () { applyFilters(); }
    });
    <?php endif; ?>

    function applyFilters() {
        const month = document.getElementById('filterMonth').value;
        const client = clientTs ? clientTs.getValue() : '';
        const staff = document.getElementById('filterStaff').value;
        const vstatus = document.getElementById('filterVisitStatus').value;
        const from = document.getElementById('filterFrom').value;
        const to = document.getElementById('filterTo').value;
        const p = new URLSearchParams({ month });
        if (client) p.set('client_id', client);
        if (staff) p.set('staff_id', staff);
        if (vstatus) p.set('visit_status', vstatus);
        if (from) p.set('from', from);
        if (to) p.set('to', to);
        location.href = 'client_report.php?' + p.toString();
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>