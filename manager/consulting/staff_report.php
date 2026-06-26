<?php
/**
 * planning/reports.php — Performance Reports
 * Aligned with actual DB schema (work_logs, office_work_logs, user_department_assignments)
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireManager();

$db       = getDB();
$user     = currentUser();
$uid      = (int)$user['id'];
$role     = $user['role_name'] ?? '';
$isExecutive = ($role === 'executive');
$isAdmin     = ($role === 'admin');
$isManager     = ($role === 'manager');
$branchId    = (int)($user['branch_id']     ?? 0);
$deptId      = (int)($user['department_id'] ?? 0);

$now     = new DateTime();
$month   = $_GET['month']    ?? $now->format('Y-m');
$view    = $_GET['view']     ?? 'monthly';
$staffFilter = ($isExecutive || $isAdmin || $isManager)
    ? ((int)($_GET['staff_id'] ?? 0) ?: null)
    : $uid;
$selectedBranch = ($isExecutive || $isAdmin || $isManager)
    ? ((int)($_GET['branch'] ?? 0) ?: 0)
    : $branchId;

$monthDate  = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthEnd   = $monthDate->format('Y-m-t');
$monthLabel = $monthDate->format('F Y');

/* ──────────────────────────────────────────────────────────────
   STAFF SCOPE
   CON-dept staff via both department_id on users AND
   user_department_assignments. Executive sees all branches,
   staff sees only their own branch.
────────────────────────────────────────────────────────────── */
$branchScopeSQL = '';
if (!$isExecutive && !$isAdmin && !$isManager) {
    $branchScopeSQL = "AND u.branch_id = {$branchId}";
} elseif ($selectedBranch) {
    $branchScopeSQL = "AND u.branch_id = {$selectedBranch}";
}

$scopeStaff = $db->query("
    SELECT DISTINCT u.id, u.full_name, u.employee_id, b.branch_name
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE u.is_active = 1
      {$branchScopeSQL}
      AND (
          u.id = {$uid}
          OR u.id IN (
              SELECT u2.id FROM users u2
              JOIN departments d ON d.id = u2.department_id
              WHERE d.dept_code = 'CON' AND u2.is_active = 1
              UNION
              SELECT uda.user_id FROM user_department_assignments uda
              JOIN departments d ON d.id = uda.department_id
              WHERE d.dept_code = 'CON'
          )
      )
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

$scopeIds = array_column($scopeStaff, 'id') ?: [0];
if ($staffFilter && in_array($staffFilter, $scopeIds)) {
    $activeIds = [$staffFilter];
} else {
    $activeIds = $scopeIds;
}
$inList = implode(',', array_map('intval', $activeIds));

/* Branch filter for work_logs */
$branchWhereWL  = '';
$branchWhereOWL = '';
if (!$isExecutive && !$isAdmin && !$isManager) {
    $branchWhereWL  = "AND wl.branch_id  = {$branchId}";
    $branchWhereOWL = "AND owl.branch_id = {$branchId}";
} elseif ($selectedBranch) {
    $branchWhereWL  = "AND wl.branch_id  = {$selectedBranch}";
    $branchWhereOWL = "AND owl.branch_id = {$selectedBranch}";
}

/* Optional client filter for work_logs */
$clientFilter   = (int)($_GET['client_id'] ?? 0) ?: null;
$clientWhereWL  = $clientFilter ? "AND wl.client_id  = {$clientFilter}" : '';
$clientWhereOWL = $clientFilter ? "AND owl.client_id = {$clientFilter}" : '';

/* ──────────────────────────────────────────────────────────────
   FIELD (work_logs) KPIs
────────────────────────────────────────────────────────────── */
$fieldKpi = $db->query("
    SELECT
        COUNT(*)                                AS total_logs,
        COALESCE(SUM(duration_hours), 0)        AS total_hours,
        SUM(visit_status = 'visited')           AS visited,
        SUM(visit_status = 'missed')            AS missed,
        SUM(visit_status = 'rescheduled')       AS rescheduled,
        COUNT(DISTINCT client_id)               AS unique_clients,
        COUNT(DISTINCT user_id)                 AS active_staff
    FROM work_logs wl
    WHERE wl.month_year = '{$month}'
      AND wl.user_id IN ({$inList})
      {$branchWhereWL}
      {$clientWhereWL}
")->fetch(PDO::FETCH_ASSOC);

/* ──────────────────────────────────────────────────────────────
   OFFICE (office_work_logs) KPIs
────────────────────────────────────────────────────────────── */
$officeKpi = $db->query("
    SELECT
        COUNT(*)                                AS total_logs,
        SUM(TIMESTAMPDIFF(MINUTE, owl.time_in, owl.time_out) / 60.0) AS total_hours,
        SUM(owl.status = 'completed')           AS completed,
        SUM(owl.status = 'wip')                 AS wip,
        SUM(owl.status = 'holding')             AS holding,
        SUM(owl.status = 'not_started')         AS not_started,
        COUNT(DISTINCT owl.client_id)           AS unique_clients,
        COUNT(DISTINCT owl.user_id)             AS active_staff
    FROM office_work_logs owl
    WHERE owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
      AND owl.user_id IN ({$inList})
      {$branchWhereOWL}
      {$clientWhereOWL}
")->fetch(PDO::FETCH_ASSOC);
$officeHours = round((float)($officeKpi['total_hours'] ?? 0), 1);

/* ──────────────────────────────────────────────────────────────
   PLANNED HOURS (work_plan_entries)
────────────────────────────────────────────────────────────── */
$totalPlanned = (float)$db->query("
    SELECT COALESCE(SUM(wpe.planned_hours), 0)
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    WHERE wp.plan_month = '{$monthStart}'
      AND wpe.assigned_to IN ({$inList})
      " . ($clientFilter ? "AND wpe.client_id = {$clientFilter}" : "") . "
")->fetchColumn();

/* ──────────────────────────────────────────────────────────────
   MONTHLY SUMMARY PER STAFF (field visits)
────────────────────────────────────────────────────────────── */
$monthlyData = $db->query("
    SELECT
        wl.user_id,
        u.full_name,
        u.employee_id,
        b.branch_name,
        COUNT(wl.id)                         AS total_visits,
        COUNT(DISTINCT wl.client_id)         AS unique_clients,
        COALESCE(SUM(wl.duration_hours), 0)  AS total_hours,
        SUM(wl.visit_status = 'visited')     AS visited_count,
        SUM(wl.visit_status = 'missed')      AS missed_count,
        SUM(wl.visit_status = 'rescheduled') AS rescheduled_count,
        COUNT(DISTINCT wl.log_date)          AS active_days
    FROM work_logs wl
    JOIN users u   ON u.id = wl.user_id
    JOIN branches b ON b.id = wl.branch_id
    WHERE wl.month_year = '{$month}'
      AND wl.user_id IN ({$inList})
      {$branchWhereWL}
      {$clientWhereWL}
    GROUP BY wl.user_id, u.full_name, u.employee_id, b.branch_name
    ORDER BY total_hours DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ──────────────────────────────────────────────────────────────
   MONTHLY OFFICE SUMMARY PER STAFF (office_work_logs)
────────────────────────────────────────────────────────────── */
$monthlyOffice = $db->query("
    SELECT
        owl.user_id,
        u.full_name,
        SUM(TIMESTAMPDIFF(MINUTE, owl.time_in, owl.time_out) / 60.0) AS office_hours,
        COUNT(owl.id)                       AS office_logs,
        SUM(owl.status = 'completed')       AS completed,
        SUM(owl.status = 'wip')             AS wip,
        COUNT(DISTINCT owl.client_id)       AS office_clients
    FROM office_work_logs owl
    JOIN users u ON u.id = owl.user_id
    WHERE owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
      AND owl.user_id IN ({$inList})
      {$branchWhereOWL}
    GROUP BY owl.user_id, u.full_name
    ORDER BY office_hours DESC
")->fetchAll(PDO::FETCH_ASSOC);
$officeByUser = [];
foreach ($monthlyOffice as $o) $officeByUser[$o['user_id']] = $o;

/* Planned hours per staff */
$staffPlannedMap = $db->query("
    SELECT wpe.assigned_to, COALESCE(SUM(wpe.planned_hours), 0) AS planned
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    WHERE wp.plan_month = '{$monthStart}'
      AND wpe.assigned_to IN ({$inList})
    GROUP BY wpe.assigned_to
")->fetchAll(PDO::FETCH_KEY_PAIR);

/* ──────────────────────────────────────────────────────────────
   CLIENT SUMMARY
────────────────────────────────────────────────────────────── */
$clientData = $db->query("
    SELECT
        wl.client_id,
        c.company_name,
        c.company_code,
        COUNT(wl.id)                         AS total_visits,
        COALESCE(SUM(wl.duration_hours), 0)  AS total_hours,
        SUM(wl.visit_status = 'visited')     AS visited,
        SUM(wl.visit_status = 'missed')      AS missed,
        COUNT(DISTINCT wl.user_id)           AS staff_count,
        MAX(wl.log_date)                     AS last_visit
    FROM work_logs wl
    JOIN companies c ON c.id = wl.client_id
    WHERE wl.month_year = '{$month}'
      AND wl.user_id IN ({$inList})
      {$branchWhereWL}
    GROUP BY wl.client_id, c.company_name, c.company_code
    ORDER BY total_hours DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ──────────────────────────────────────────────────────────────
   DAILY BREAKDOWN
────────────────────────────────────────────────────────────── */
$dailyData = $db->query("
    SELECT
        wl.log_date,
        wl.day_of_week,
        COUNT(wl.id)                        AS visits,
        COALESCE(SUM(wl.duration_hours), 0) AS total_hours,
        COUNT(DISTINCT wl.client_id)        AS clients,
        COUNT(DISTINCT wl.user_id)          AS staff_count,
        SUM(wl.visit_status = 'visited')    AS visited,
        SUM(wl.visit_status = 'missed')     AS missed
    FROM work_logs wl
    WHERE wl.month_year = '{$month}'
      AND wl.user_id IN ({$inList})
      {$branchWhereWL}
    GROUP BY wl.log_date, wl.day_of_week
    ORDER BY wl.log_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ──────────────────────────────────────────────────────────────
   WHO VISITED (executive/admin/manager only)
────────────────────────────────────────────────────────────── */
$whoVisited = [];
if ($isExecutive || $isAdmin || $isManager) {
    $whoVisited = $db->query("
        SELECT
            wl.client_id, c.company_name, c.company_code,
            wl.user_id, u.full_name AS staff_name, u.employee_id,
            b.branch_name,
            wl.log_date, wl.day_of_week,
            wl.time_in, wl.time_out,
            wl.duration_hours, wl.visit_status,
            wl.work_description
        FROM work_logs wl
        JOIN companies c ON c.id = wl.client_id
        JOIN users u     ON u.id = wl.user_id
        JOIN branches b  ON b.id = wl.branch_id
        WHERE wl.month_year = '{$month}'
          AND wl.user_id IN ({$inList})
          {$branchWhereWL}
          {$clientWhereWL}
        ORDER BY c.company_name ASC, wl.log_date ASC
        LIMIT 500
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/* ──────────────────────────────────────────────────────────────
   OFFICE LOGS TABLE (for office tab)
────────────────────────────────────────────────────────────── */
$officeLogs = $db->query("
    SELECT
        owl.*,
        u.full_name AS staff_name, u.employee_id,
        c.company_name, c.company_code,
        d.dept_name,
        b.branch_name
    FROM office_work_logs owl
    JOIN users u       ON u.id  = owl.user_id
    JOIN companies c   ON c.id  = owl.client_id
    JOIN departments d ON d.id  = owl.department_id
    JOIN branches b    ON b.id  = owl.branch_id
    WHERE owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
      AND owl.user_id IN ({$inList})
      {$branchWhereOWL}
      {$clientWhereOWL}
    ORDER BY owl.log_date DESC, owl.time_in DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

/* ──────────────────────────────────────────────────────────────
   6-MONTH FIELD TREND
────────────────────────────────────────────────────────────── */
$trendData = $db->query("
    SELECT
        month_year,
        COUNT(*)                            AS logs,
        COALESCE(SUM(duration_hours), 0)    AS hours,
        SUM(visit_status = 'visited')       AS visited,
        SUM(visit_status = 'missed')        AS missed
    FROM work_logs wl
    WHERE wl.user_id IN ({$inList})
      {$branchWhereWL}
    GROUP BY month_year
    ORDER BY month_year DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);
$trendData = array_reverse($trendData);

/* 6-month office trend */
$officeTrend = $db->query("
    SELECT
        DATE_FORMAT(owl.log_date, '%Y-%m') AS month_year,
        COUNT(owl.id)                      AS logs,
        COALESCE(SUM(TIMESTAMPDIFF(MINUTE, owl.time_in, owl.time_out) / 60.0), 0) AS hours
    FROM office_work_logs owl
    WHERE owl.user_id IN ({$inList})
      {$branchWhereOWL}
    GROUP BY DATE_FORMAT(owl.log_date, '%Y-%m')
    ORDER BY month_year DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);
$officeTrend = array_reverse($officeTrend);

/* Align office trend to field trend labels */
$officeTrendMapped = [];
foreach ($trendData as $t) {
    $found = array_filter($officeTrend, fn($o) => $o['month_year'] === $t['month_year']);
    $officeTrendMapped[] = $found ? round((float)array_values($found)[0]['hours'], 1) : 0;
}

/* Grand totals */
$grandHours   = (float)($fieldKpi['total_hours']    ?? 0);
$grandVisits  = (int)($fieldKpi['total_logs']        ?? 0);
$grandClients = count($clientData);
$visitedTotal    = (int)($fieldKpi['visited']        ?? 0);
$missedTotal     = (int)($fieldKpi['missed']         ?? 0);
$rescheduledTotal = (int)($fieldKpi['rescheduled']   ?? 0);

/* Branches for filter */
$branches = $db->query("SELECT id, branch_name FROM branches ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Performance Reports — ' . $monthLabel;
include '../../includes/header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

<div class="app-wrapper">
<?php include '../../includes/sidebar_manager.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">
<?= flashHtml() ?>

<!-- ══ HERO ══════════════════════════════════════════════════ -->
<div class="page-hero mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="page-hero-badge">
                <i class="fas fa-chart-bar"></i> <?= ucfirst($role) ?> · Performance Reports
            </div>
            <h4>Performance Reports</h4>
            <p><?= htmlspecialchars($user['full_name']) ?> · <?= $monthLabel ?>
                <?php if ($selectedBranch): ?>
                    <span style="background:#eff6ff;color:#3b82f6;border-radius:99px;padding:.1rem .5rem;font-size:.72rem;margin-left:.3rem;">
                        <?php foreach ($branches as $b) if ($b['id'] == $selectedBranch) echo htmlspecialchars($b['branch_name']); ?>
                    </span>
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <input type="month" class="form-control form-control-sm" style="width:150px;"
                   value="<?= $month ?>" onchange="setParam('month',this.value)">
            <a href="<?= APP_URL ?>/exports/export_pdf.php?module=consulting_performance&month=<?= urlencode($month) ?>&view=<?= urlencode($view) ?>&staff_id=<?= $staffFilter ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-file-pdf me-1" style="color:#ef4444;"></i>PDF
            </a>
            <a href="<?= APP_URL ?>/exports/export_excel.php?module=consulting_performance&month=<?= urlencode($month) ?>&view=<?= urlencode($view) ?>&staff_id=<?= $staffFilter ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-file-excel me-1" style="color:#10b981;"></i>Excel
            </a>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>
</div>

<!-- ══ FILTERS ═══════════════════════════════════════════════ -->
<div class="card-mis mb-4">
    <div class="card-mis-body" style="padding:.75rem 1rem;">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label-mis">Month</label>
                <input type="month" id="fMonth" class="form-control form-control-sm"
                       value="<?= $month ?>" onchange="setParam('month',this.value)">
            </div>
            <?php if ($isExecutive || $isAdmin || $isManager): ?>
            <div class="col-md-2">
                <label class="form-label-mis">Branch</label>
                <select id="fBranch" class="form-select form-select-sm" onchange="setParam('branch',this.value)">
                    <option value="">— All Branches —</option>
                    <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $selectedBranch == $b['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['branch_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label-mis">Staff</label>
                <select id="fStaff" class="form-select form-select-sm" onchange="setParam('staff_id',this.value)">
                    <option value="">— All Staff —</option>
                    <?php foreach ($scopeStaff as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $staffFilter == $s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['full_name']) ?>
                        <?= $s['branch_name'] ? '('.$s['branch_name'].')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-auto ms-auto">
                <a href="reports.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times me-1"></i>Clear
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ══ TABS ══════════════════════════════════════════════════ -->
<div style="display:flex;gap:0;border-bottom:2px solid #f3f4f6;margin-bottom:20px;overflow-x:auto;flex-wrap:nowrap;">
<?php
$tabs = [
    'monthly' => '<i class="fas fa-user-clock me-1"></i>Monthly',
    'daily'   => '<i class="fas fa-calendar-day me-1"></i>Daily',
    'client'  => '<i class="fas fa-building me-1"></i>Client-wise',
    'office'  => '<i class="fas fa-laptop me-1"></i>Office Logs',
];
if ($isExecutive || $isAdmin || $isManager) $tabs['who'] = '<i class="fas fa-search me-1"></i>Who Visited';
foreach ($tabs as $k => $lbl):
    $active = $view === $k;
?>
<a href="?month=<?= urlencode($month) ?>&view=<?= $k ?>&staff_id=<?= $staffFilter ?>&branch=<?= $selectedBranch ?>"
   style="padding:.6rem 1.1rem;font-size:.82rem;font-weight:600;text-decoration:none;white-space:nowrap;
          border-bottom:2.5px solid <?= $active ? '#c9a84c' : 'transparent' ?>;
          color:<?= $active ? '#c9a84c' : '#6b7280' ?>;margin-bottom:-2px;">
    <?= $lbl ?>
</a>
<?php endforeach; ?>
</div>

<!-- ══ KPI CARDS — FIELD ═══════════════════════════════════ -->
<div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;">
    <i class="fas fa-car" style="color:#c9a84c;margin-right:.3rem;"></i>Field Visits
</div>
<div class="row g-3 mb-3">
<?php
$kpiCards = [
    ['fa-clock',         '#3b82f6','#eff6ff', 'Field Hours',    number_format($grandHours,1).'h'],
    ['fa-clipboard-list','#8b5cf6','#f5f3ff', 'Field Logs',     $grandVisits],
    ['fa-building',      '#0ea5e9','#e0f2fe', 'Clients (field)',  $grandClients],
    ['fa-users',         '#c9a84c','#fefce8', 'Staff Active',   count($monthlyData)],
    ['fa-check-circle',  '#10b981','#ecfdf5', 'Visited',        $visitedTotal],
    ['fa-times-circle',  '#ef4444','#fef2f2', 'Missed',         $missedTotal],
    ['fa-redo',          '#f59e0b','#fffbeb', 'Rescheduled',    $rescheduledTotal],
    ['fa-calendar-alt',  '#6366f1','#eef2ff', 'Planned Hours',  number_format($totalPlanned,1).'h'],
];
foreach ($kpiCards as [$icon,$col,$bg,$lbl,$val]):
?>
<div class="col-6 col-md-3 col-lg-2">
    <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;
                padding:.85rem 1rem;display:flex;align-items:center;gap:.65rem;
                box-shadow:0 1px 3px rgba(0,0,0,.04);">
        <div style="width:36px;height:36px;border-radius:9px;background:<?= $bg ?>;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas <?= $icon ?>" style="color:<?= $col ?>;font-size:.88rem;"></i>
        </div>
        <div>
            <div style="font-size:1.2rem;font-weight:800;color:#1f2937;line-height:1.1;"><?= $val ?></div>
            <div style="font-size:.67rem;color:#9ca3af;margin-top:.1rem;"><?= $lbl ?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ══ KPI CARDS — OFFICE ══════════════════════════════════ -->
<div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;margin-top:.25rem;">
    <i class="fas fa-laptop" style="color:#3b82f6;margin-right:.3rem;"></i>Office Work
</div>
<div class="row g-3 mb-4">
<?php
$officeKpiCards = [
    ['fa-file-alt',    '#3b82f6','#eff6ff', 'Office Logs',     (int)($officeKpi['total_logs']    ?? 0)],
    ['fa-clock',       '#c9a84c','#fefce8', 'Office Hours',    number_format($officeHours,1).'h'],
    ['fa-building',    '#0ea5e9','#e0f2fe', 'Clients (office)',(int)($officeKpi['unique_clients'] ?? 0)],
    ['fa-check-double','#10b981','#ecfdf5', 'Completed',       (int)($officeKpi['completed']      ?? 0)],
    ['fa-spinner',     '#f59e0b','#fffbeb', 'In Progress (WIP)',(int)($officeKpi['wip']           ?? 0)],
    ['fa-pause-circle','#8b5cf6','#f5f3ff', 'Holding',         (int)($officeKpi['holding']        ?? 0)],
    ['fa-circle',      '#9ca3af','#f9fafb', 'Not Started',     (int)($officeKpi['not_started']    ?? 0)],
];
foreach ($officeKpiCards as [$icon,$col,$bg,$lbl,$val]):
?>
<div class="col-6 col-md-3 col-lg-2">
    <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;
                padding:.85rem 1rem;display:flex;align-items:center;gap:.65rem;
                box-shadow:0 1px 3px rgba(0,0,0,.04);">
        <div style="width:36px;height:36px;border-radius:9px;background:<?= $bg ?>;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas <?= $icon ?>" style="color:<?= $col ?>;font-size:.88rem;"></i>
        </div>
        <div>
            <div style="font-size:1.2rem;font-weight:800;color:#1f2937;line-height:1.1;"><?= $val ?></div>
            <div style="font-size:.67rem;color:#9ca3af;margin-top:.1rem;"><?= $lbl ?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ══ CHARTS ROW ════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <!-- Trend chart (field + office) -->
    <div class="col-md-5">
        <div class="card-mis h-100">
            <div class="card-mis-header">
                <h5><i class="fas fa-chart-line text-warning me-2"></i>6-Month Trend</h5>
                <div style="display:flex;gap:10px;font-size:.72rem;">
                    <span style="color:#3b82f6;">● Field</span>
                    <span style="color:#10b981;">● Office</span>
                </div>
            </div>
            <div class="card-mis-body" style="height:220px;">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Visit status donut -->
    <div class="col-md-3">
        <div class="card-mis h-100">
            <div class="card-mis-header">
                <h5><i class="fas fa-chart-pie text-warning me-2"></i>Visit Status</h5>
            </div>
            <div class="card-mis-body" style="height:220px;display:flex;align-items:center;justify-content:center;">
                <canvas id="donutChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Staff hours bar -->
    <?php if (!empty($monthlyData)): ?>
    <div class="col-md-4">
        <div class="card-mis h-100">
            <div class="card-mis-header">
                <h5><i class="fas fa-users text-warning me-2"></i>Staff — Field Hours</h5>
            </div>
            <div class="card-mis-body" style="height:220px;">
                <canvas id="staffChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php /* ══════════════════ MONTHLY TAB ══════════════════════ */ if ($view === 'monthly'): ?>

<div class="card-mis mb-4" style="border-top:3px solid #c9a84c;">
    <div class="card-mis-header">
        <h5><i class="fas fa-user-clock text-warning me-2"></i>
            Monthly Staff Summary — <?= htmlspecialchars($monthLabel) ?>
        </h5>
        <span style="font-size:.78rem;color:#9ca3af;"><?= count($monthlyData) ?> staff member(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Staff</th>
                    <th>Branch</th>
                    <th class="text-center">Field Hrs</th>
                    <th class="text-center">Planned Hrs</th>
                    <th class="text-center">Office Hrs</th>
                    <th class="text-center">Visits</th>
                    <th class="text-center">Clients</th>
                    <th class="text-center">Days Active</th>
                    <th class="text-center">Visited</th>
                    <th class="text-center">Missed</th>
                    <th class="text-center">Rescheduled</th>
                    <th style="min-width:120px;">Visit Efficiency</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($monthlyData)): ?>
                <tr><td colspan="13" style="text-align:center;color:#9ca3af;padding:2rem;font-size:.84rem;">
                    No field logs for <?= htmlspecialchars($monthLabel) ?>
                </td></tr>
            <?php else:
                $maxHrs = max(array_column($monthlyData,'total_hours')) ?: 1;
                foreach ($monthlyData as $i => $r):
                    $planned = $staffPlannedMap[$r['user_id']] ?? 0;
                    $eff = $planned > 0 ? min(100, round(($r['total_hours']/$planned)*100)) : 0;
                    $hCol = $eff >= 75 ? '#10b981' : ($eff >= 40 ? '#f59e0b' : '#ef4444');
                    $offRow = $officeByUser[$r['user_id']] ?? null;
            ?>
            <tr>
                <td style="color:#9ca3af;font-size:.75rem;"><?= $i+1 ?></td>
                <td>
                    <div style="font-weight:600;font-size:.85rem;"><?= htmlspecialchars($r['full_name']??'—') ?></div>
                    <div style="font-size:.7rem;color:#9ca3af;"><?= htmlspecialchars($r['employee_id']??'') ?></div>
                </td>
                <td style="font-size:.75rem;">
                    <span style="background:#f5f3ff;color:#8b5cf6;padding:2px 7px;border-radius:6px;font-size:.72rem;">
                        <?= htmlspecialchars($r['branch_name']??'—') ?>
                    </span>
                </td>
                <td class="text-center">
                    <strong style="color:#c9a84c;"><?= number_format($r['total_hours'],1) ?>h</strong>
                </td>
                <td class="text-center" style="color:#3b82f6;"><?= number_format($planned,1) ?>h</td>
                <td class="text-center">
                    <?php if ($offRow): ?>
                    <strong style="color:#10b981;"><?= number_format($offRow['office_hours'],1) ?>h</strong>
                    <div style="font-size:.65rem;color:#9ca3af;"><?= $offRow['office_logs'] ?> logs</div>
                    <?php else: ?>
                    <span style="color:#d1d5db;">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-center"><strong><?= $r['total_visits'] ?></strong></td>
                <td class="text-center"><?= $r['unique_clients'] ?></td>
                <td class="text-center"><?= $r['active_days'] ?></td>
                <td class="text-center">
                    <span style="background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:20px;font-size:.74rem;font-weight:600;">
                        <?= $r['visited_count'] ?>
                    </span>
                </td>
                <td class="text-center">
                    <?php if ((int)$r['missed_count'] > 0): ?>
                    <span style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:20px;font-size:.74rem;font-weight:600;">
                        <?= $r['missed_count'] ?>
                    </span>
                    <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ((int)$r['rescheduled_count'] > 0): ?>
                    <span style="background:#fffbeb;color:#b45309;padding:2px 8px;border-radius:20px;font-size:.74rem;font-weight:600;">
                        <?= $r['rescheduled_count'] ?>
                    </span>
                    <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;align-items:center;gap:.4rem;">
                        <div style="flex:1;background:#f1f5f9;border-radius:99px;height:6px;overflow:hidden;">
                            <div style="width:<?= $eff ?>%;background:<?= $hCol ?>;height:100%;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:.7rem;font-weight:700;color:<?= $hCol ?>;min-width:32px;text-align:right;">
                            <?= $planned > 0 ? $eff.'%' : 'N/A' ?>
                        </span>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($monthlyData)): ?>
            <tfoot>
                <tr style="background:#f9fafb;font-weight:700;">
                    <td colspan="3" style="padding:10px 14px;font-size:.82rem;color:#374151;">
                        <i class="fas fa-calculator me-1 text-warning"></i>TOTAL
                    </td>
                    <td class="text-center" style="color:#c9a84c;"><?= number_format($grandHours,1) ?>h</td>
                    <td class="text-center" style="color:#3b82f6;"><?= number_format($totalPlanned,1) ?>h</td>
                    <td class="text-center" style="color:#10b981;"><?= number_format($officeHours,1) ?>h</td>
                    <td class="text-center"><?= $grandVisits ?></td>
                    <td class="text-center"><?= $grandClients ?></td>
                    <td></td>
                    <td class="text-center" style="color:#15803d;"><?= $visitedTotal ?></td>
                    <td class="text-center" style="color:#b91c1c;"><?= $missedTotal ?></td>
                    <td class="text-center" style="color:#b45309;"><?= $rescheduledTotal ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php /* ══════════════════ DAILY TAB ══════════════════════ */ elseif ($view === 'daily'): ?>

<?php if (!empty($dailyData)):
    $dowTotals = [];
    foreach ($dailyData as $d) {
        $dow = $d['day_of_week'];
        $dowTotals[$dow] = ($dowTotals[$dow] ?? 0) + $d['total_hours'];
    }
?>
<div class="card-mis mb-4">
    <div class="card-mis-header">
        <h5><i class="fas fa-th text-warning me-2"></i>Hours by Day of Week</h5>
    </div>
    <div class="card-mis-body">
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php
        $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $maxDow = max(array_values($dowTotals) ?: [1]);
        foreach ($days as $d):
            $hrs = $dowTotals[$d] ?? 0;
            $pct = $maxDow > 0 ? round(($hrs/$maxDow)*100) : 0;
            $bg  = $pct >= 75 ? '#10b981' : ($pct >= 40 ? '#f59e0b' : ($pct > 0 ? '#3b82f6' : '#f3f4f6'));
            $tc  = $pct > 0 ? '#fff' : '#9ca3af';
        ?>
        <div style="text-align:center;flex:1;min-width:70px;">
            <div style="background:<?= $bg ?>;color:<?= $tc ?>;border-radius:10px;padding:.75rem .5rem;
                        margin-bottom:.3rem;font-weight:700;font-size:.85rem;
                        opacity:<?= $pct>0?(.3+($pct/100)*.7):1 ?>;">
                <?= number_format($hrs,1) ?>h
            </div>
            <div style="font-size:.68rem;color:#6b7280;font-weight:600;"><?= substr($d,0,3) ?></div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card-mis mb-4" style="border-top:3px solid #3b82f6;">
    <div class="card-mis-header">
        <h5><i class="fas fa-calendar-day text-warning me-2"></i>Day-by-Day Activity</h5>
        <span style="font-size:.78rem;color:#9ca3af;"><?= count($dailyData) ?> day(s) with activity</span>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>Date</th><th>Day</th>
                    <th class="text-center">Visits</th><th class="text-center">Hours</th>
                    <th class="text-center">Clients</th><th class="text-center">Staff</th>
                    <th class="text-center">Visited</th><th class="text-center">Missed</th>
                    <th style="min-width:130px;">Bar</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($dailyData)): ?>
                <tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:2rem;">No daily logs found</td></tr>
            <?php else:
                $maxD = max(array_column($dailyData,'total_hours')) ?: 1;
                foreach ($dailyData as $r):
                    $dpct = round(($r['total_hours']/$maxD)*100);
                    $dcol = $dpct>=75?'#10b981':($dpct>=40?'#f59e0b':'#3b82f6');
            ?>
            <tr>
                <td style="font-weight:600;font-size:.83rem;white-space:nowrap;"><?= date('d M Y',strtotime($r['log_date'])) ?></td>
                <td>
                    <span style="background:#eff6ff;color:#3b82f6;padding:2px 8px;border-radius:20px;font-size:.72rem;font-weight:600;">
                        <?= $r['day_of_week'] ?>
                    </span>
                </td>
                <td class="text-center"><strong><?= $r['visits'] ?></strong></td>
                <td class="text-center"><strong style="color:#c9a84c;"><?= number_format($r['total_hours'],1) ?>h</strong></td>
                <td class="text-center"><?= $r['clients'] ?></td>
                <td class="text-center"><?= $r['staff_count'] ?></td>
                <td class="text-center">
                    <span style="background:#f0fdf4;color:#15803d;padding:2px 7px;border-radius:20px;font-size:.72rem;font-weight:600;">
                        <?= $r['visited'] ?>
                    </span>
                </td>
                <td class="text-center">
                    <?php if ((int)$r['missed'] > 0): ?>
                    <span style="background:#fef2f2;color:#b91c1c;padding:2px 7px;border-radius:20px;font-size:.72rem;font-weight:600;">
                        <?= $r['missed'] ?>
                    </span>
                    <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;align-items:center;gap:.4rem;">
                        <div style="flex:1;background:#f1f5f9;border-radius:99px;height:6px;overflow:hidden;">
                            <div style="width:<?= $dpct ?>%;background:<?= $dcol ?>;height:100%;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:.7rem;color:<?= $dcol ?>;font-weight:700;min-width:28px;"><?= $dpct ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php /* ══════════════════ CLIENT TAB ══════════════════════ */ elseif ($view === 'client'): ?>

<?php if (!empty($clientData)):
    $topN = array_slice($clientData, 0, 8);
?>
<div class="card-mis mb-4">
    <div class="card-mis-header">
        <h5><i class="fas fa-chart-bar text-warning me-2"></i>Top Clients by Hours</h5>
        <span style="font-size:.75rem;color:#9ca3af;">Top <?= count($topN) ?> of <?= count($clientData) ?></span>
    </div>
    <div class="card-mis-body" style="height:<?= max(200, count($topN)*40) ?>px;">
        <canvas id="clientChart"></canvas>
    </div>
</div>
<?php endif; ?>

<div class="card-mis mb-4" style="border-top:3px solid #8b5cf6;">
    <div class="card-mis-header">
        <h5><i class="fas fa-building text-warning me-2"></i>Client-wise Summary</h5>
        <span style="font-size:.78rem;color:#9ca3af;"><?= count($clientData) ?> client(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>#</th><th>Code</th><th>Client</th>
                    <th class="text-center">Total Hrs</th><th class="text-center">Visits</th>
                    <th class="text-center">Visited</th><th class="text-center">Missed</th>
                    <th class="text-center">Staff</th><th>Last Visit</th>
                    <th style="min-width:120px;">Bar</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($clientData)): ?>
                <tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:2rem;">No client data found</td></tr>
            <?php else:
                $maxC = max(array_column($clientData,'total_hours')) ?: 1;
                foreach ($clientData as $i => $r):
                    $cpct = round(($r['total_hours']/$maxC)*100);
                    $ccol = $cpct>=75?'#8b5cf6':($cpct>=40?'#3b82f6':'#9ca3af');
            ?>
            <tr>
                <td style="color:#9ca3af;font-size:.75rem;"><?= $i+1 ?></td>
                <td>
                    <span style="background:#f5f3ff;color:#8b5cf6;padding:2px 7px;border-radius:6px;font-size:.72rem;font-weight:600;">
                        <?= htmlspecialchars($r['company_code']??'—') ?>
                    </span>
                </td>
                <td><strong style="font-size:.85rem;"><?= htmlspecialchars($r['company_name']??'—') ?></strong></td>
                <td class="text-center"><strong style="color:#c9a84c;"><?= number_format($r['total_hours'],1) ?>h</strong></td>
                <td class="text-center"><strong><?= $r['total_visits'] ?></strong></td>
                <td class="text-center">
                    <span style="background:#f0fdf4;color:#15803d;padding:2px 7px;border-radius:20px;font-size:.73rem;font-weight:600;">
                        <?= (int)$r['visited'] ?>
                    </span>
                </td>
                <td class="text-center">
                    <?php if ((int)$r['missed'] > 0): ?>
                    <span style="background:#fef2f2;color:#b91c1c;padding:2px 7px;border-radius:20px;font-size:.73rem;font-weight:600;">
                        <?= (int)$r['missed'] ?>
                    </span>
                    <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                </td>
                <td class="text-center"><?= $r['staff_count'] ?></td>
                <td style="font-size:.75rem;color:#6b7280;white-space:nowrap;">
                    <?= $r['last_visit'] ? date('d M Y',strtotime($r['last_visit'])) : '—' ?>
                </td>
                <td>
                    <div style="display:flex;align-items:center;gap:.4rem;">
                        <div style="flex:1;background:#f1f5f9;border-radius:99px;height:6px;overflow:hidden;">
                            <div style="width:<?= $cpct ?>%;background:<?= $ccol ?>;height:100%;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:.7rem;color:<?= $ccol ?>;font-weight:700;min-width:28px;"><?= $cpct ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php /* ══════════════════ OFFICE LOGS TAB ═══════════════════ */ elseif ($view === 'office'): ?>

<!-- Office status summary bars -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card-mis">
            <div class="card-mis-header">
                <h5><i class="fas fa-tasks text-warning me-2"></i>Office Logs by Status</h5>
                <span style="font-size:.78rem;color:#9ca3af;"><?= (int)($officeKpi['total_logs']??0) ?> total</span>
            </div>
            <div class="card-mis-body">
                <?php
                $maxOff = max((int)($officeKpi['completed']??0),(int)($officeKpi['wip']??0),(int)($officeKpi['holding']??0),(int)($officeKpi['not_started']??0),1);
                $offStatusRows = [
                    ['Completed',   (int)($officeKpi['completed']  ??0), '#10b981'],
                    ['WIP',         (int)($officeKpi['wip']        ??0), '#f59e0b'],
                    ['Holding',     (int)($officeKpi['holding']    ??0), '#8b5cf6'],
                    ['Not Started', (int)($officeKpi['not_started']??0), '#9ca3af'],
                ];
                foreach ($offStatusRows as [$lbl,$v,$c]):
                    $w = round($v/$maxOff*100);
                ?>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:9px;font-size:12px;">
                    <span style="width:80px;font-size:11px;font-weight:500;color:#6b7280;"><?= $lbl ?></span>
                    <div style="flex:1;height:7px;background:#f3f4f6;border-radius:99px;overflow:hidden;">
                        <div style="width:<?= $w ?>%;background:<?= $c ?>;height:100%;border-radius:99px;"></div>
                    </div>
                    <span style="width:28px;text-align:right;font-weight:600;color:#1f2937;font-size:12px;"><?= $v ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-mis">
            <div class="card-mis-header">
                <h5><i class="fas fa-users text-warning me-2"></i>Office Hours per Staff</h5>
            </div>
            <div class="card-mis-body" style="height:180px;">
                <canvas id="officeStaffChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="card-mis mb-4" style="border-top:3px solid #3b82f6;">
    <div class="card-mis-header">
        <h5><i class="fas fa-table text-warning me-2"></i>Office Work Logs — <?= $monthLabel ?></h5>
        <span style="font-size:.78rem;color:#9ca3af;"><?= count($officeLogs) ?> entries</span>
    </div>
    <?php if (empty($officeLogs)): ?>
    <div style="padding:3rem;text-align:center;color:#9ca3af;font-size:.84rem;">
        <i class="fas fa-laptop" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
        No office logs found
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>Date</th><th>Day</th><th>Staff</th><th>Client</th><th>Dept</th><th>Branch</th>
                    <th class="text-center">Time In</th><th class="text-center">Time Out</th>
                    <th class="text-center">Hours</th><th>Description</th><th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($officeLogs as $ol):
                $hrs = 0;
                if ($ol['time_in'] && $ol['time_out'])
                    $hrs = (strtotime($ol['time_out']) - strtotime($ol['time_in'])) / 3600;
                $statusMap = [
                    'completed'   => ['#f0fdf4','#15803d','Completed'],
                    'wip'         => ['#fffbeb','#b45309','WIP'],
                    'holding'     => ['#f5f3ff','#6d28d9','Holding'],
                    'not_started' => ['#f9fafb','#9ca3af','Not Started'],
                ];
                [$sbg,$scol,$slbl] = $statusMap[$ol['status']] ?? $statusMap['not_started'];
            ?>
            <tr>
                <td style="font-weight:600;font-size:.82rem;white-space:nowrap;"><?= date('d M Y',strtotime($ol['log_date'])) ?></td>
                <td style="font-size:.72rem;color:#9ca3af;"><?= date('D',strtotime($ol['log_date'])) ?></td>
                <td>
                    <div style="font-size:.83rem;font-weight:600;"><?= htmlspecialchars($ol['staff_name']) ?></div>
                    <div style="font-size:.67rem;color:#9ca3af;"><?= htmlspecialchars($ol['employee_id']??'') ?></div>
                </td>
                <td>
                    <div style="font-size:.83rem;font-weight:600;"><?= htmlspecialchars($ol['company_name']) ?></div>
                    <div style="font-size:.67rem;color:#9ca3af;"><?= htmlspecialchars($ol['company_code']??'') ?></div>
                </td>
                <td style="font-size:.75rem;color:#8b5cf6;"><?= htmlspecialchars($ol['dept_name']) ?></td>
                <td style="font-size:.73rem;color:#6b7280;"><?= htmlspecialchars($ol['branch_name']) ?></td>
                <td class="text-center" style="font-size:.78rem;"><?= $ol['time_in']  ? date('h:i A',strtotime($ol['time_in']))  : '—' ?></td>
                <td class="text-center" style="font-size:.78rem;"><?= $ol['time_out'] ? date('h:i A',strtotime($ol['time_out'])) : '—' ?></td>
                <td class="text-center">
                    <strong style="color:<?= $hrs>=4?'#10b981':($hrs>=2?'#f59e0b':'#6b7280') ?>;">
                        <?= number_format($hrs,1) ?>h
                    </strong>
                </td>
                <td style="font-size:.73rem;color:#6b7280;max-width:200px;">
                    <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:180px;">
                        <?= htmlspecialchars(mb_strimwidth($ol['description']??'—',0,60,'…')) ?>
                    </div>
                    <?php if (!empty($ol['notes'])): ?>
                    <div style="font-size:.63rem;color:#9ca3af;margin-top:2px;">
                        <?= htmlspecialchars(mb_strimwidth($ol['notes'],0,40,'…')) ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <span style="background:<?= $sbg ?>;color:<?= $scol ?>;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:600;">
                        <?= $slbl ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php /* ══════════════════ WHO VISITED TAB ═══════════════════ */ elseif ($view === 'who' && ($isExecutive || $isAdmin || $isManager)): ?>

<div class="card-mis mb-4" style="border-top:3px solid #0ea5e9;">
    <div class="card-mis-header">
        <h5><i class="fas fa-search text-warning me-2"></i>Who Visited Each Client</h5>
        <span style="font-size:.78rem;color:#9ca3af;"><?= count($whoVisited) ?> record(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>Client</th><th>Staff</th><th>Branch</th><th>Date</th><th>Day</th>
                    <th class="text-center">Time In</th><th class="text-center">Time Out</th>
                    <th class="text-center">Hours</th><th>Status</th><th>Description</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($whoVisited)): ?>
                <tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:2rem;">No data found</td></tr>
            <?php else:
                foreach ($whoVisited as $r):
                    $vstMap = [
                        'visited'     => ['#ecfdf5','#10b981','fa-check-circle','Visited'],
                        'missed'      => ['#fef2f2','#ef4444','fa-times-circle','Missed'],
                        'rescheduled' => ['#fffbeb','#f59e0b','fa-redo','Rescheduled'],
                    ];
                    [$vbg,$vcol,$vico,$vlbl] = $vstMap[$r['visit_status']] ?? ['#f9fafb','#9ca3af','fa-circle','—'];
            ?>
            <tr>
                <td>
                    <div style="font-weight:600;font-size:.83rem;"><?= htmlspecialchars(mb_strimwidth($r['company_name']??'—',0,20,'…')) ?></div>
                    <div style="font-size:.67rem;color:#9ca3af;"><?= htmlspecialchars($r['company_code']??'') ?></div>
                </td>
                <td>
                    <div style="font-size:.82rem;"><?= htmlspecialchars($r['staff_name']??'—') ?></div>
                    <div style="font-size:.67rem;color:#9ca3af;"><?= htmlspecialchars($r['employee_id']??'') ?></div>
                </td>
                <td style="font-size:.73rem;color:#8b5cf6;"><?= htmlspecialchars($r['branch_name']??'') ?></td>
                <td style="font-size:.8rem;white-space:nowrap;"><?= date('d M Y',strtotime($r['log_date'])) ?></td>
                <td style="font-size:.72rem;color:#9ca3af;"><?= $r['day_of_week'] ?></td>
                <td class="text-center" style="font-size:.78rem;"><?= $r['time_in']  ? date('g:i A',strtotime($r['time_in']))  : '—' ?></td>
                <td class="text-center" style="font-size:.78rem;"><?= $r['time_out'] ? date('g:i A',strtotime($r['time_out'])) : '—' ?></td>
                <td class="text-center">
                    <strong style="color:<?= (float)$r['duration_hours']>=4?'#10b981':((float)$r['duration_hours']>=2?'#f59e0b':'#6b7280') ?>;">
                        <?= number_format((float)$r['duration_hours'],1) ?>h
                    </strong>
                </td>
                <td>
                    <span style="background:<?= $vbg ?>;color:<?= $vcol ?>;padding:.15rem .55rem;border-radius:99px;
                                 font-size:.72rem;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;">
                        <i class="fas <?= $vico ?>" style="font-size:.6rem;"></i><?= $vlbl ?>
                    </span>
                </td>
                <td style="font-size:.73rem;color:#6b7280;max-width:160px;">
                    <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px;">
                        <?= htmlspecialchars(mb_strimwidth($r['work_description']??'—',0,40,'…')) ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; /* end tabs */ ?>

</div><!-- /padding -->
</div></div><!-- /main-content /app-wrapper -->

<?php include '../../includes/footer.php'; ?>

<script>
/* ── Data ──────────────────────────────────────────────────── */
const TREND_LABELS      = <?= json_encode(array_map(fn($t) => date('M', strtotime($t['month_year'].'-01')), $trendData)) ?>;
const TREND_FIELD_HOURS = <?= json_encode(array_map(fn($t) => (float)$t['hours'], $trendData)) ?>;
const TREND_FIELD_VISITS = <?= json_encode(array_map(fn($t) => (int)$t['logs'], $trendData)) ?>;
const TREND_OFF_HOURS   = <?= json_encode($officeTrendMapped) ?>;

/* ── Trend Chart (field hours + office hours) ─────────────── */
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: TREND_LABELS,
        datasets: [
            {
                label: 'Field Hours',
                data: TREND_FIELD_HOURS,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59,130,246,.1)',
                fill: true, tension: .4,
                pointBackgroundColor: '#3b82f6', pointRadius: 4, borderWidth: 2,
                yAxisID: 'y'
            },
            {
                label: 'Office Hours',
                data: TREND_OFF_HOURS,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16,185,129,.08)',
                fill: true, tension: .4,
                pointBackgroundColor: '#10b981', pointRadius: 4, borderWidth: 2,
                borderDash: [5,3],
                yAxisID: 'y'
            },
            {
                label: 'Visits',
                data: TREND_FIELD_VISITS,
                borderColor: '#c9a84c',
                backgroundColor: 'rgba(201,168,76,.06)',
                fill: false, tension: .4,
                pointBackgroundColor: '#c9a84c', pointRadius: 4, borderWidth: 1.5,
                borderDash: [3,3],
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', labels: { usePointStyle: true, font: { size: 10 }, padding: 12 } }
        },
        scales: {
            y:  { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, callback: v => v+'h' },
                  title: { display: true, text: 'Hours', font: { size: 10 } } },
            y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { font: { size: 10 } },
                  title: { display: true, text: 'Visits', font: { size: 10 } } }
        }
    }
});

/* ── Visit Status Donut ────────────────────────────────────── */
new Chart(document.getElementById('donutChart'), {
    type: 'doughnut',
    data: {
        labels: ['Visited', 'Missed', 'Rescheduled'],
        datasets: [{
            data: [<?= $visitedTotal ?>, <?= $missedTotal ?>, <?= $rescheduledTotal ?>],
            backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
            borderWidth: 2, borderColor: '#fff', hoverOffset: 6
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false, cutout: '70%',
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, font: { size: 10 }, padding: 10 } } }
    }
});

/* ── Staff Field Hours Bar ─────────────────────────────────── */
<?php if (!empty($monthlyData)):
    $topStaff = array_slice($monthlyData, 0, 6);
?>
new Chart(document.getElementById('staffChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($s) => explode(' ', $s['full_name'])[0], $topStaff)) ?>,
        datasets: [{
            label: 'Field Hours',
            data: <?= json_encode(array_map(fn($s) => round((float)$s['total_hours'],1), $topStaff)) ?>,
            backgroundColor: ['rgba(59,130,246,.7)','rgba(16,185,129,.7)','rgba(201,168,76,.7)',
                              'rgba(139,92,246,.7)','rgba(239,68,68,.7)','rgba(14,165,233,.7)'],
            borderRadius: 6, borderWidth: 0
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, callback: v => v+'h' } }
        }
    }
});
<?php endif; ?>

/* ── Office Staff Hours Bar (office tab) ───────────────────── */
<?php if ($view === 'office' && !empty($monthlyOffice)): ?>
new Chart(document.getElementById('officeStaffChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($s) => explode(' ', $s['full_name'])[0], array_slice($monthlyOffice,0,8))) ?>,
        datasets: [{
            label: 'Office Hours',
            data: <?= json_encode(array_map(fn($s) => round((float)$s['office_hours'],1), array_slice($monthlyOffice,0,8))) ?>,
            backgroundColor: 'rgba(16,185,129,.7)',
            borderRadius: 6, borderWidth: 0
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, callback: v => v+'h' } }
        }
    }
});
<?php endif; ?>

/* ── Client Chart (client tab) ─────────────────────────────── */
<?php if ($view === 'client' && !empty($clientData)):
    $topN = array_slice($clientData, 0, 8);
?>
new Chart(document.getElementById('clientChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($c) => mb_strimwidth($c['company_name']??'—',0,18,'…'), $topN)) ?>,
        datasets: [
            {
                label: 'Actual Hours',
                data: <?= json_encode(array_map(fn($c) => (float)$c['total_hours'], $topN)) ?>,
                backgroundColor: 'rgba(139,92,246,.7)', borderRadius: 5, borderWidth: 0
            },
            {
                label: 'Visits',
                data: <?= json_encode(array_map(fn($c) => (int)$c['total_visits'], $topN)) ?>,
                backgroundColor: 'rgba(201,168,76,.5)', borderRadius: 5, borderWidth: 0
            }
        ]
    },
    options: {
        indexAxis: 'y',
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: { size: 10 } } } },
        scales: {
            x: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 } } },
            y: { grid: { display: false }, ticks: { font: { size: 11 } } }
        }
    }
});
<?php endif; ?>

function setParam(key, val) {
    const url = new URL(window.location.href);
    url.searchParams.set(key, val);
    window.location.href = url.toString();
}
</script>