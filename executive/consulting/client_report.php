<?php
/**
 * consulting/executive/client_report.php — Executive: Client-wise Performance Report
 *
 * Shows ALL clients across ALL branches (or filtered branch), with:
 *  ✓ Multi-dept staff via getDepartmentStaff() / user_department_assignments
 *  ✓ Drilldown: day-wise log per client, unlogged planned visits
 *  ✓ Branch filter, staff filter, visit status filter, date range
 *  ✓ Top-clients chart, efficiency bars (hour + visit)
 *  ✓ Notification badge from plan_notifications
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db       = getDB();
$user     = currentUser();
$uid      = (int)$user['id'];
$branchId = (int)$user['branch_id'];
$deptId   = (int)$user['department_id'];

// ── Month / Filters ───────────────────────────────────────────
$now      = new DateTime();
$month    = $_GET['month']    ?? $now->format('Y-m');
$monthDate  = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthEnd   = $monthDate->format('Y-m-t');
$monthLabel = $monthDate->format('F Y');

// Branch filter — executive can see all branches or a specific one
$selectedBranch = (isset($_GET['branch']) && $_GET['branch'] !== 'all')
    ? (int)$_GET['branch']
    : 0;
$filterBranchId = $selectedBranch ?: 0; // 0 = all branches

$filterClientId = (int)($_GET['client_id'] ?? 0) ?: null;
$filterStaffId  = (int)($_GET['staff_id']  ?? 0) ?: null;
$filterStatus   = $_GET['vstatus']  ?? '';
$filterFrom     = $_GET['from']     ?? $monthStart;
$filterTo       = $_GET['to']       ?? $monthEnd;
$filterFrom     = max($filterFrom, $monthStart);
$filterTo       = min($filterTo,   $monthEnd);

// ── Notification badge ────────────────────────────────────────
// NEW
try {
    $notifCount = (int)$db->query("
        SELECT COUNT(*) FROM plan_notifications
        WHERE user_id = {$uid} AND is_read = 0
    ")->fetchColumn();
} catch (Exception $e) {
    $notifCount = 0;
}

// ── All branches for dropdown ─────────────────────────────────
$branches = $db->query("SELECT id, branch_name FROM branches ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC);

// ── Staff scope using getDepartmentStaff() + cross-branch for exec ────────────
// For executive: get staff across the selected branch (or all branches)
// getDepartmentStaff handles multi-dept via user_department_assignments
// NEW — always scope to CON dept from both tables, optionally filter by branch
$branchCondition = $filterBranchId ? "AND u.branch_id = {$filterBranchId}" : '';
$scopeStaff = $db->query("
    SELECT DISTINCT u.id, u.full_name, u.employee_id,
           b.branch_name
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE u.is_active = 1
      {$branchCondition}
      AND (
          u.id = {$uid}
          OR u.id IN (
              -- Primary department on users table
              SELECT u2.id FROM users u2
              JOIN departments d ON d.id = u2.department_id AND d.dept_code = 'CON'
              WHERE u2.is_active = 1
              UNION
              -- Secondary/multi-dept assignments
              SELECT uda.user_id FROM user_department_assignments uda
              JOIN departments d ON d.id = uda.department_id AND d.dept_code = 'CON'
          )
      )
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

$scopeIds = array_column($scopeStaff, 'id');

// Apply staff filter
if ($filterStaffId && in_array($filterStaffId, $scopeIds)) {
    $activeIds = [$filterStaffId];
} else {
    $activeIds = $scopeIds;
}

// Build IN list safely
$inList = empty($activeIds) ? '0' : implode(',', array_map('intval', $activeIds));

// ── Branch WHERE fragment ─────────────────────────────────────
$branchWhere = $filterBranchId ? "AND wl.branch_id = {$filterBranchId}" : '';

// ── KPIs ──────────────────────────────────────────────────────
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
    WHERE wl.month_year = '{$month}'
      AND wl.user_id IN ({$inList})
      {$branchWhere}
      " . ($filterClientId ? "AND wl.client_id={$filterClientId}" : "") . "
      " . ($filterStatus ? "AND wl.visit_status = " . $db->quote($filterStatus) : "") . "
")->fetch(PDO::FETCH_ASSOC);

$totalHours = (float)($kpi['total_hours'] ?? 0);

// ── Total planned hours ───────────────────────────────────────
$totalPlanned = (float)$db->query("
    SELECT COALESCE(SUM(wpe.planned_hours),0)
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    WHERE wp.plan_month = '{$monthStart}'
      AND wpe.assigned_to IN ({$inList})
      " . ($filterClientId ? "AND wpe.client_id={$filterClientId}" : "") . "
")->fetchColumn();

// ── CLIENT-WISE performance ───────────────────────────────────
$clientWhereExtra = '';
if ($filterClientId) $clientWhereExtra .= " AND wl.client_id={$filterClientId}";
if ($filterStatus)   $clientWhereExtra .= " AND wl.visit_status = " . $db->quote($filterStatus);

$clientPerf = $db->query("
    SELECT
        c.id                                        AS client_id,
        c.company_name,
        c.company_code,
        b.branch_name,
        COUNT(wl.id)                                AS total_visits,
        SUM(wl.visit_status='visited')              AS visited,
        SUM(wl.visit_status='missed')               AS missed,
        SUM(wl.visit_status='rescheduled')          AS rescheduled,
        COALESCE(SUM(wl.duration_hours),0)          AS actual_hours,
        COUNT(DISTINCT wl.user_id)                  AS staff_count,
        GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ')
                                                    AS staff_names,
        (SELECT COALESCE(SUM(wpe.planned_hours),0)
         FROM work_plan_entries wpe
         JOIN work_plans wp ON wp.id=wpe.plan_id
         WHERE wpe.client_id=c.id
           AND wp.plan_month='{$monthStart}'
           AND wpe.assigned_to IN ({$inList}))      AS planned_hours,
        (SELECT COUNT(DISTINCT wpe.id)
         FROM work_plan_entries wpe
         JOIN work_plans wp ON wp.id=wpe.plan_id
         WHERE wpe.client_id=c.id
           AND wp.plan_month='{$monthStart}'
           AND wpe.assigned_to IN ({$inList}))      AS planned_entries,
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
           AND wpe2.assigned_to IN ({$inList}))     AS matched_visits,
        MIN(wl.log_date)                            AS first_visit,
        MAX(wl.log_date)                            AS last_visit,
        COUNT(DISTINCT wl.log_date)                 AS visit_days
    FROM work_logs wl
    JOIN companies c ON c.id=wl.client_id
    JOIN users u     ON u.id=wl.user_id
    LEFT JOIN branches b ON b.id=wl.branch_id
    WHERE wl.month_year='{$month}'
      AND wl.user_id IN ({$inList})
      {$branchWhere}
      {$clientWhereExtra}
    GROUP BY c.id, c.company_name, c.company_code, b.branch_name
    ORDER BY actual_hours DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Chart data top 10 ─────────────────────────────────────────
$topClientNames  = [];
$topClientHours  = [];
$topClientVisits = [];
foreach (array_slice($clientPerf, 0, 10) as $cp) {
    $topClientNames[]  = mb_strimwidth($cp['company_name'] ?? '—', 0, 18, '…');
    $topClientHours[]  = (float)$cp['actual_hours'];
    $topClientVisits[] = (int)$cp['total_visits'];
}

// ── Drilldown: day-wise logs for selected client ──────────────
$drilldownLogs = [];
if ($filterClientId) {
    $drilldownLogs = $db->query("
        SELECT wl.log_date, wl.day_of_week, wl.time_in, wl.time_out,
               wl.duration_hours, wl.visit_status, wl.work_description,
               u.full_name AS staff_name, u.employee_id,
               b.branch_name
        FROM work_logs wl
        JOIN users u     ON u.id  = wl.user_id
        JOIN branches b  ON b.id  = wl.branch_id
        WHERE wl.month_year  = '{$month}'
          AND wl.client_id   = {$filterClientId}
          AND wl.user_id IN ({$inList})
          AND wl.log_date BETWEEN '{$filterFrom}' AND '{$filterTo}'
        ORDER BY wl.log_date DESC, wl.time_in ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Drilldown: planned but not yet logged ─────────────────────
$unloggedPlans = [];
if ($filterClientId) {
    $unloggedPlans = $db->query("
        SELECT wpe.plan_date, wpe.day_of_week, wpe.planned_time_in,
               wpe.planned_time_out, wpe.planned_hours, wpe.notes,
               u.full_name AS staff_name, b.branch_name
        FROM work_plan_entries wpe
        JOIN work_plans wp ON wp.id = wpe.plan_id
        JOIN users u       ON u.id  = wpe.assigned_to
        JOIN branches b    ON b.id  = wp.branch_id
        LEFT JOIN work_logs wl
            ON wl.client_id = wpe.client_id
            AND wl.log_date = wpe.plan_date
            AND wl.user_id  = wpe.assigned_to
        WHERE wpe.client_id   = {$filterClientId}
          AND wp.plan_month   = '{$monthStart}'
          AND wpe.assigned_to IN ({$inList})
          AND wl.id IS NULL
          AND wpe.plan_date BETWEEN '{$filterFrom}' AND '{$filterTo}'
        ORDER BY wpe.plan_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Unvisited clients (in scope, not visited this month) ──────
$unvisitedClients = $db->query("
    SELECT c.company_name, c.company_code, b.branch_name
    FROM companies c
    JOIN branches b ON b.id = c.branch_id
    WHERE c.is_active = 1
      " . ($filterBranchId ? "AND c.branch_id={$filterBranchId}" : "") . "
      AND c.id NOT IN (
          SELECT DISTINCT client_id FROM work_logs
          WHERE month_year='{$month}'
            AND user_id IN ({$inList})
            {$branchWhere}
      )
    ORDER BY c.company_name
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// ── Clients for filter dropdown ───────────────────────────────
$clientsForFilter = $db->query("
    SELECT DISTINCT c.id, c.company_name
    FROM companies c
    WHERE c.is_active = 1
      " . ($filterBranchId ? "AND c.branch_id={$filterBranchId}" : "") . "
    ORDER BY c.company_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Helpers ───────────────────────────────────────────────────
function vstBadgeEC(string $s): string {
    $map = [
        'visited'     => ['#ecfdf5','#10b981','fa-check-circle','Visited'],
        'missed'      => ['#fef2f2','#ef4444','fa-times-circle','Missed'],
        'rescheduled' => ['#fffbeb','#f59e0b','fa-redo','Rescheduled'],
    ];
    [$bg,$col,$ico,$lbl] = $map[$s] ?? ['#f9fafb','#9ca3af','fa-circle','—'];
    return "<span style='background:{$bg};color:{$col};padding:.15rem .55rem;border-radius:99px;
            font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;white-space:nowrap;'>
            <i class=\"fas {$ico}\" style=\"font-size:.6rem;\"></i>{$lbl}</span>";
}

function safeEffEC(float $actual, float $planned): array {
    if ($planned <= 0) return [0, 0, '#9ca3af'];
    $raw = round(($actual / $planned) * 100, 1);
    $cap = min($raw, 100);
    $col = $cap >= 80 ? '#10b981' : ($cap >= 50 ? '#f59e0b' : '#ef4444');
    return [$cap, $raw, $col];
}

function visitEffEC(int $matched, int $planned): array {
    if ($planned <= 0) return [0, 0, '#9ca3af'];
    $raw = round(($matched / $planned) * 100, 1);
    $cap = min($raw, 100);
    $col = $cap >= 80 ? '#10b981' : ($cap >= 50 ? '#f59e0b' : '#ef4444');
    return [$cap, $raw, $col];
}

$pageTitle = 'Client Report — ' . $monthLabel;
include '../../includes/header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<div class="app-wrapper">
<?php include '../../includes/sidebar_executive.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">
<?= flashHtml() ?>

<!-- ══ HERO ════════════════════════════════════════════════════ -->
<div class="page-hero mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="page-hero-badge">
                <i class="fas fa-building"></i> Executive · Client Report
                <?php if ($notifCount > 0): ?>
                <span style="background:#ef4444;color:#fff;border-radius:99px;padding:.05rem .42rem;
                             font-size:.65rem;font-weight:700;margin-left:.35rem;"><?= $notifCount ?></span>
                <?php endif; ?>
            </div>
            <h4>Client-wise Performance Report</h4>
            <p>
                <?= htmlspecialchars($user['full_name']) ?> · <?= $monthLabel ?>
                <?php if ($filterBranchId): ?>
                <span style="background:#eff6ff;color:#3b82f6;border-radius:99px;padding:.1rem .55rem;font-size:.72rem;margin-left:.3rem;">
                    <?php foreach ($branches as $b) if ($b['id'] == $filterBranchId) echo htmlspecialchars($b['branch_name']); ?>
                </span>
                <?php else: ?>
                <span style="background:#f3f4f6;color:#6b7280;border-radius:99px;padding:.1rem .55rem;font-size:.72rem;margin-left:.3rem;">
                    All Branches
                </span>
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <input type="month" class="form-control form-control-sm" style="width:150px;"
                   value="<?= $month ?>" onchange="location='?month='+this.value+'&branch=<?= $_GET['branch'] ?? 'all' ?>'">
            <a href="plan_list.php" class="btn btn-outline-secondary btn-sm position-relative">
                <i class="fas fa-check-circle me-1"></i>Approvals
                <?php if ($notifCount > 0): ?>
                <span style="position:absolute;top:-5px;right:-5px;background:#ef4444;color:#fff;
                             border-radius:50%;width:16px;height:16px;font-size:.6rem;font-weight:700;
                             display:flex;align-items:center;justify-content:center;"><?= $notifCount ?></span>
                <?php endif; ?>
            </a>
            <a href="index.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-th-large me-1"></i>Dashboard
            </a>
        </div>
    </div>
</div>

<!-- ══ FILTERS ════════════════════════════════════════════════ -->
<div class="card-mis mb-4">
    <div class="card-mis-body" style="padding:.75rem 1rem;">
        <div class="row g-2 align-items-end">
            <div class="col-md-1">
                <label class="form-label-mis">Month</label>
                <input type="month" id="fMonth" class="form-control form-control-sm"
                       value="<?= $month ?>" onchange="applyFilters()">
            </div>
            <div class="col-md-2">
                <label class="form-label-mis">Branch</label>
                <select id="fBranch" class="form-select form-select-sm" onchange="applyFilters()">
                    <option value="all" <?= !$filterBranchId ? 'selected' : '' ?>>All Branches</option>
                    <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $filterBranchId == $b['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['branch_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label-mis">Client</label>
                <select id="fClient" class="form-select form-select-sm" onchange="applyFilters()">
                    <option value="">— All Clients —</option>
                    <?php foreach ($clientsForFilter as $cf): ?>
                    <option value="<?= $cf['id'] ?>" <?= $filterClientId == $cf['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cf['company_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label-mis">Staff</label>
                <select id="fStaff" class="form-select form-select-sm" onchange="applyFilters()">
                    <option value="">— All Staff —</option>
                    <?php foreach ($scopeStaff as $sf): ?>
                    <option value="<?= $sf['id'] ?>" <?= $filterStaffId == $sf['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sf['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label-mis">Visit Status</label>
                <select id="fStatus" class="form-select form-select-sm" onchange="applyFilters()">
                    <option value="">— All Status —</option>
                    <option value="visited"     <?= $filterStatus=='visited'     ?'selected':'' ?>>Visited</option>
                    <option value="missed"      <?= $filterStatus=='missed'      ?'selected':'' ?>>Missed</option>
                    <option value="rescheduled" <?= $filterStatus=='rescheduled' ?'selected':'' ?>>Rescheduled</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label-mis">Date Range</label>
                <div class="input-group input-group-sm">
                    <input type="date" id="fFrom" class="form-control" value="<?= $filterFrom ?>" onchange="applyFilters()">
                    <input type="date" id="fTo"   class="form-control" value="<?= $filterTo ?>" onchange="applyFilters()">
                </div>
            </div>
            <div class="col-md-1">
                <a href="client_report.php?month=<?= $month ?>&branch=<?= $_GET['branch'] ?? 'all' ?>"
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
$visitPct    = (int)($kpi['total_logs']??0) > 0
    ? min(100, round(($kpi['visited']/$kpi['total_logs'])*100)) : 0;
$visitPctCol = $visitPct>=80?'#10b981':($visitPct>=50?'#f59e0b':'#ef4444');
$kpiCards = [
    ['fa-building',       '#8b5cf6', '#f5f3ff', 'Clients Served',  (int)($kpi['unique_clients']??0)],
    ['fa-users',          '#0ea5e9', '#e0f2fe', 'Active Staff',    (int)($kpi['active_staff']  ??0)],
    ['fa-clock',          '#3b82f6', '#eff6ff', 'Total Hours',     number_format($totalHours,1).'h'],
    ['fa-calendar-alt',   '#c9a84c', '#fefce8', 'Planned Hours',   number_format($totalPlanned,1).'h'],
    ['fa-check-circle',   '#10b981', '#ecfdf5', 'Visited',         (int)($kpi['visited']       ??0)],
    ['fa-times-circle',   '#ef4444', '#fef2f2', 'Missed',          (int)($kpi['missed']        ??0)],
    ['fa-redo',           '#f59e0b', '#fffbeb', 'Rescheduled',     (int)($kpi['rescheduled']   ??0)],
    ['fa-tachometer-alt', $visitPctCol,'#f9fafb','Visit Rate',      $visitPct.'%'],
];
foreach ($kpiCards as [$icon,$col,$bg,$lbl,$val]):
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
            <div style="font-size:1.35rem;font-weight:800;color:#1f2937;line-height:1.1;"><?= $val ?></div>
            <div style="font-size:.7rem;color:#9ca3af;margin-top:.1rem;"><?= $lbl ?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ══ CLIENT DETAIL DRILLDOWN ════════════════════════════════ -->
<?php
$selClient = null;
if ($filterClientId) {
    foreach ($clientPerf as $cp) {
        if ($cp['client_id'] == $filterClientId) { $selClient = $cp; break; }
    }
}
if ($filterClientId && $selClient): ?>
<div class="card-mis mb-4" style="border-left:4px solid #3b82f6;">
    <div class="card-mis-header">
        <h5><i class="fas fa-search text-warning me-2"></i>
            <?= htmlspecialchars($selClient['company_name']) ?>
            <span style="font-size:.72rem;color:#9ca3af;font-weight:400;margin-left:.4rem;">
                <?= htmlspecialchars($selClient['company_code']??'') ?>
            </span>
        </h5>
        <div style="display:flex;align-items:center;gap:.5rem;">
            <span style="font-size:.75rem;color:#9ca3af;"><?= htmlspecialchars($selClient['branch_name']??'') ?></span>
            <a href="client_report.php?month=<?= urlencode($month) ?>&branch=<?= urlencode($_GET['branch']??'all') ?>"
               style="font-size:.72rem;color:#9ca3af;text-decoration:none;border:1px solid #e5e7eb;
                      border-radius:6px;padding:.15rem .5rem;margin-left:.25rem;"
               title="Clear client filter">
                <i class="fas fa-times"></i> Clear
            </a>
            <a href="<?= APP_URL ?>/exports/export_pdf.php?module=consulting_performance&view=who&month=<?= urlencode($month) ?>&client_id=<?= $filterClientId ?>&staff_id=<?= $filterStaffId ?>&from=<?= urlencode($filterFrom) ?>&to=<?= urlencode($filterTo) ?>"
               class="btn btn-outline-secondary btn-sm" style="font-size:.72rem;padding:.2rem .55rem;">
                <i class="fas fa-file-pdf me-1" style="color:#ef4444;"></i>PDF
            </a>
            <a href="<?= APP_URL ?>/exports/export_excel.php?module=consulting_performance&view=who&month=<?= urlencode($month) ?>&client_id=<?= $filterClientId ?>&staff_id=<?= $filterStaffId ?>"
               class="btn btn-outline-secondary btn-sm" style="font-size:.72rem;padding:.2rem .55rem;">
                <i class="fas fa-file-excel me-1" style="color:#10b981;"></i>Excel
            </a>
        </div>
    </div>
    <div class="card-mis-body">
        <div class="row g-3 mb-3">
        <?php foreach ([
            ['fa-clock',       '#3b82f6', number_format((float)$selClient['actual_hours'],1).'h', 'Actual Hours'],
            ['fa-calendar',    '#c9a84c', number_format((float)$selClient['planned_hours'],1).'h','Planned Hours'],
            ['fa-users',       '#8b5cf6', (int)$selClient['staff_count'],   'Staff Involved'],
            ['fa-check-circle','#10b981', (int)$selClient['visited'],        'Visited'],
            ['fa-times-circle','#ef4444', (int)$selClient['missed'],         'Missed'],
            ['fa-calendar-day','#0ea5e9', (int)$selClient['visit_days'],     'Visit Days'],
        ] as [$ico,$col,$val,$lbl]): ?>
        <div class="col-4 col-md-2">
            <div style="text-align:center;background:#f9fafb;border-radius:10px;padding:.8rem .5rem;">
                <i class="fas <?= $ico ?>" style="color:<?= $col ?>;font-size:1rem;margin-bottom:.3rem;display:block;"></i>
                <div style="font-size:1.15rem;font-weight:800;color:#1f2937;"><?= $val ?></div>
                <div style="font-size:.65rem;color:#9ca3af;"><?= $lbl ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php if ($selClient['staff_names']): ?>
        <div style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">
            <i class="fas fa-user-friends me-1 text-warning"></i>
            <strong>Staff:</strong> <?= htmlspecialchars($selClient['staff_names']) ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Day-wise log -->
    <?php if (!empty($drilldownLogs)): ?>
    <div style="border-top:1px solid #f3f4f6;">
        <div style="padding:.55rem 1rem;font-size:.8rem;font-weight:700;color:#374151;background:#f9fafb;">
            <i class="fas fa-calendar-day me-1 text-warning"></i>
            Day-wise Visit Log (<?= count($drilldownLogs) ?> entries)
        </div>
        <div class="table-responsive">
            <table class="table-mis w-100">
                <thead>
                    <tr>
                        <th>Date</th><th>Day</th><th>Branch</th><th>Staff</th>
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
                    <td style="font-size:.82rem;font-weight:500;white-space:nowrap;"><?= date('d M Y', strtotime($dl['log_date'])) ?></td>
                    <td style="font-size:.75rem;color:#9ca3af;"><?= htmlspecialchars($dl['day_of_week']??'') ?></td>
                    <td style="font-size:.74rem;color:#8b5cf6;"><?= htmlspecialchars($dl['branch_name']??'') ?></td>
                    <td>
                        <div style="font-size:.82rem;"><?= htmlspecialchars($dl['staff_name']) ?></div>
                        <div style="font-size:.67rem;color:#9ca3af;"><?= htmlspecialchars($dl['employee_id']??'') ?></div>
                    </td>
                    <td class="text-center" style="font-size:.78rem;"><?= $dl['time_in']  ? date('g:i A',strtotime($dl['time_in']))  : '—' ?></td>
                    <td class="text-center" style="font-size:.78rem;"><?= $dl['time_out'] ? date('g:i A',strtotime($dl['time_out'])) : '—' ?></td>
                    <td class="text-center">
                        <span style="font-weight:700;color:<?= (float)$dl['duration_hours']>=4?'#10b981':((float)$dl['duration_hours']>=2?'#f59e0b':'#ef4444') ?>;">
                            <?= number_format((float)$dl['duration_hours'],1) ?>h
                        </span>
                    </td>
                    <td><?= vstBadgeEC($dl['visit_status']??'') ?></td>
                    <td style="font-size:.73rem;color:#6b7280;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars(mb_strimwidth($dl['work_description']??'—',0,50,'…')) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Unlogged planned -->
    <?php if (!empty($unloggedPlans)): ?>
    <div style="border-top:1px solid #fde68a;background:#fffbeb;">
        <div style="padding:.55rem 1rem;font-size:.8rem;font-weight:700;color:#92400e;">
            <i class="fas fa-exclamation-triangle me-1"></i>
            Planned but NOT Logged (<?= count($unloggedPlans) ?> entries)
        </div>
        <div class="table-responsive">
            <table class="table-mis w-100">
                <thead>
                    <tr><th>Date</th><th>Day</th><th>Branch</th><th>Staff</th>
                        <th class="text-center">Planned In</th>
                        <th class="text-center">Planned Hrs</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($unloggedPlans as $up): ?>
                <tr style="background:#fffbeb;">
                    <td style="font-size:.82rem;font-weight:500;white-space:nowrap;"><?= date('d M Y',strtotime($up['plan_date'])) ?></td>
                    <td style="font-size:.75rem;color:#9ca3af;"><?= htmlspecialchars($up['day_of_week']??'') ?></td>
                    <td style="font-size:.74rem;color:#8b5cf6;"><?= htmlspecialchars($up['branch_name']??'') ?></td>
                    <td style="font-size:.82rem;"><?= htmlspecialchars($up['staff_name']) ?></td>
                    <td class="text-center" style="font-size:.78rem;"><?= $up['planned_time_in'] ? date('g:i A',strtotime($up['planned_time_in'])) : '—' ?></td>
                    <td class="text-center" style="font-size:.8rem;color:#f59e0b;font-weight:600;"><?= number_format((float)$up['planned_hours'],1) ?>h</td>
                    <td style="font-size:.73rem;color:#6b7280;"><?= htmlspecialchars(mb_strimwidth($up['notes']??'—',0,40,'…')) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ TOP CLIENTS CHART ══════════════════════════════════════ -->
<?php if (!empty($topClientNames)): ?>
<div class="card-mis mb-4">
    <div class="card-mis-header">
        <h5><i class="fas fa-chart-bar text-warning me-2"></i>Top Clients by Hours — <?= $monthLabel ?></h5>
        <span style="font-size:.75rem;color:#9ca3af;">
            Top <?= count($topClientNames) ?> of <?= count($clientPerf) ?>
        </span>
    </div>
    <div class="card-mis-body" style="height:<?= max(220, count($topClientNames)*42) ?>px;">
        <canvas id="clientChart"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- ══ CLIENT TABLE ═══════════════════════════════════════════ -->
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
                    <th>Branch</th>
                    <th class="text-center">Staff</th>
                    <th class="text-center">Visits</th>
                    <th class="text-center">Visited</th>
                    <th class="text-center">Missed</th>
                    <th class="text-center">Rescheduled</th>
                    <th class="text-center">Visit Days</th>
                    <th class="text-center">Planned Hrs</th>
                    <th class="text-center">Actual Hrs</th>
                    <th style="min-width:120px;">Hour Eff.</th>
                    <th style="min-width:120px;">Visit Eff.</th>
                    <th>First · Last</th>
                    <th>Drill</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($clientPerf as $i => $cp):
                [$cEff,  $cEffRaw,  $cEffCol]  = safeEffEC((float)$cp['actual_hours'], (float)$cp['planned_hours']);
                [$vcEff, $vcEffRaw, $vcEffCol] = visitEffEC((int)$cp['matched_visits'], (int)$cp['planned_entries']);
                $isSelected = ($filterClientId == $cp['client_id']);
            ?>
            <tr <?= $isSelected ? 'style="background:#eff6ff;"' : '' ?>>
                <td style="color:#9ca3af;font-size:.75rem;"><?= $i+1 ?></td>
                <td>
                    <div style="font-weight:600;font-size:.85rem;"><?= htmlspecialchars($cp['company_name']??'—') ?></div>
                    <div style="font-size:.7rem;color:#9ca3af;"><?= htmlspecialchars($cp['company_code']??'') ?></div>
                </td>
                <td>
                    <span style="font-size:.73rem;background:#f5f3ff;color:#8b5cf6;
                                 padding:.1rem .4rem;border-radius:6px;">
                        <?= htmlspecialchars($cp['branch_name']??'—') ?>
                    </span>
                </td>
                <td class="text-center">
                    <span style="font-size:.78rem;font-weight:600;color:#8b5cf6;"><?= (int)$cp['staff_count'] ?></span>
                    <?php if ($cp['staff_names']): ?>
                    <div style="font-size:.63rem;color:#9ca3af;max-width:90px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                         title="<?= htmlspecialchars($cp['staff_names']) ?>">
                        <?= htmlspecialchars(mb_strimwidth($cp['staff_names'],0,16,'…')) ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="text-center"><strong><?= $cp['total_visits'] ?></strong></td>
                <td class="text-center">
                    <span style="background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:20px;font-size:.74rem;font-weight:600;">
                        <?= (int)$cp['visited'] ?>
                    </span>
                </td>
                <td class="text-center">
                    <?php if ((int)$cp['missed']>0): ?>
                    <span style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:20px;font-size:.74rem;font-weight:600;">
                        <?= (int)$cp['missed'] ?>
                    </span>
                    <?php else: ?><span style="color:#d1d5db;font-size:.77rem;">—</span><?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ((int)$cp['rescheduled']>0): ?>
                    <span style="background:#fffbeb;color:#b45309;padding:2px 8px;border-radius:20px;font-size:.74rem;font-weight:600;">
                        <?= (int)$cp['rescheduled'] ?>
                    </span>
                    <?php else: ?><span style="color:#d1d5db;font-size:.77rem;">—</span><?php endif; ?>
                </td>
                <td class="text-center" style="font-size:.8rem;color:#6b7280;"><?= (int)$cp['visit_days'] ?></td>
                <td class="text-center" style="color:#3b82f6;font-weight:600;"><?= number_format((float)$cp['planned_hours'],1) ?>h</td>
                <td class="text-center">
                    <strong style="color:<?= (float)$cp['actual_hours']>=4?'#10b981':((float)$cp['actual_hours']>=2?'#f59e0b':'#6b7280') ?>;">
                        <?= number_format((float)$cp['actual_hours'],1) ?>h
                    </strong>
                </td>
                <!-- Hour efficiency -->
                <td>
                    <?php if ((float)$cp['planned_hours']>0): ?>
                    <div style="display:flex;align-items:center;gap:.35rem;">
                        <div style="flex:1;background:#f1f5f9;border-radius:99px;height:5px;overflow:hidden;">
                            <div style="width:<?= $cEff ?>%;background:<?= $cEffCol ?>;height:100%;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:.7rem;font-weight:700;color:<?= $cEffCol ?>;min-width:32px;text-align:right;"><?= $cEff ?>%</span>
                    </div>
                    <?php if ($cEffRaw>100): ?>
                    <div style="font-size:.6rem;color:#f59e0b;">⚠ <?= $cEffRaw ?>% raw</div>
                    <?php endif; ?>
                    <?php else: ?><span style="font-size:.72rem;color:#9ca3af;">No plan</span><?php endif; ?>
                </td>
                <!-- Visit efficiency -->
                <td>
                    <?php if ((int)$cp['planned_entries']>0): ?>
                    <div style="display:flex;align-items:center;gap:.35rem;">
                        <div style="flex:1;background:#f1f5f9;border-radius:99px;height:5px;overflow:hidden;">
                            <div style="width:<?= $vcEff ?>%;background:<?= $vcEffCol ?>;height:100%;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:.7rem;font-weight:700;color:<?= $vcEffCol ?>;min-width:32px;text-align:right;"><?= $vcEff ?>%</span>
                    </div>
                    <div style="font-size:.62rem;color:#9ca3af;margin-top:2px;">
                        <?= $cp['matched_visits'] ?>/<?= $cp['planned_entries'] ?> visits
                    </div>
                    <?php else: ?><span style="font-size:.72rem;color:#9ca3af;">No plan</span><?php endif; ?>
                </td>
                <td style="font-size:.75rem;color:#6b7280;white-space:nowrap;">
                    <?= $cp['first_visit'] ? date('d M',strtotime($cp['first_visit'])) : '—' ?>
                    <?php if ($cp['first_visit']&&$cp['last_visit']&&$cp['first_visit']!==$cp['last_visit']): ?>
                    <span style="color:#d1d5db;"> → </span><?= date('d M',strtotime($cp['last_visit'])) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="?month=<?= urlencode($month) ?>&branch=<?= urlencode($_GET['branch']??'all') ?>&client_id=<?= $cp['client_id'] ?><?= $filterStaffId ? '&staff_id='.$filterStaffId : '' ?><?= $filterStatus ? '&vstatus='.urlencode($filterStatus) : '' ?>"
                        style="font-size:.72rem;color:#3b82f6;text-decoration:none;"
                        title="Drill into <?= htmlspecialchars($cp['company_name']??'') ?>">
                            <i class="fas fa-search-plus"></i> <span style="font-size:.68rem;">Drill</span>
                        </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f9fafb;font-weight:700;">
                    <td colspan="4" style="padding:10px 14px;font-size:.82rem;color:#374151;">
                        <i class="fas fa-calculator me-1 text-warning"></i>TOTAL
                    </td>
                    <td class="text-center"><?= (int)($kpi['total_logs']??0) ?></td>
                    <td class="text-center" style="color:#15803d;"><?= (int)($kpi['visited']??0) ?></td>
                    <td class="text-center" style="color:#b91c1c;"><?= (int)($kpi['missed']??0) ?></td>
                    <td class="text-center" style="color:#b45309;"><?= (int)($kpi['rescheduled']??0) ?></td>
                    <td></td>
                    <td class="text-center" style="color:#3b82f6;"><?= number_format(array_sum(array_column($clientPerf,'planned_hours')),1) ?>h</td>
                    <td class="text-center" style="color:#c9a84c;"><?= number_format($totalHours,1) ?>h</td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php else: ?>
<div class="card-mis mb-4">
    <div class="card-mis-body" style="text-align:center;padding:3rem;color:#9ca3af;">
        <i class="fas fa-building" style="font-size:2rem;margin-bottom:.75rem;opacity:.3;display:block;"></i>
        No client data found for selected filters.
    </div>
</div>
<?php endif; ?>

<!-- ══ UNVISITED CLIENTS ═══════════════════════════════════════ -->
<?php if (!empty($unvisitedClients)): ?>
<div class="card-mis mb-4" style="border-top:3px solid #f59e0b;">
    <div class="card-mis-header">
        <h5><i class="fas fa-exclamation-triangle text-warning me-2"></i>
            Clients with NO Visits — <?= $monthLabel ?>
        </h5>
        <span style="font-size:.78rem;color:#9ca3af;"><?= count($unvisitedClients) ?> client(s)</span>
    </div>
    <div class="card-mis-body">
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
        <?php foreach ($unvisitedClients as $uv): ?>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;
                    padding:.4rem .8rem;font-size:.78rem;">
            <span style="font-weight:600;color:#92400e;"><?= htmlspecialchars($uv['company_name']) ?></span>
            <span style="color:#b45309;font-size:.68rem;margin-left:.3rem;"><?= htmlspecialchars($uv['company_code']??'') ?></span>
            <?php if (!$filterBranchId): ?>
            <span style="color:#8b5cf6;font-size:.65rem;margin-left:.3rem;">
                · <?= htmlspecialchars($uv['branch_name']??'') ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /padding -->
<?php include '../../includes/footer.php'; ?>
</div></div>

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
        plugins: { legend: { position:'top', labels:{ usePointStyle:true, font:{size:11} } } },
        scales: {
            x: { beginAtZero:true, grid:{ color:'#f3f4f6' }, ticks:{ font:{size:10} } },
            y: { grid:{ display:false }, ticks:{ font:{size:11} } }
        }
    }
});
<?php endif; ?>

function applyFilters() {
    const p = new URLSearchParams({
        month:  document.getElementById('fMonth').value,
        branch: document.getElementById('fBranch').value,
    });
    const client = document.getElementById('fClient').value;
    const staff  = document.getElementById('fStaff').value;
    const status = document.getElementById('fStatus').value;
    const from   = document.getElementById('fFrom').value;
    const to     = document.getElementById('fTo').value;
    if (client) p.set('client_id', client);
    if (staff)  p.set('staff_id',  staff);
    if (status) p.set('vstatus',   status);
    if (from)   p.set('from', from);
    if (to)     p.set('to',   to);
    location.href = 'client_report.php?' + p.toString();
}
</script>