<?php
/**
 * consulting/executive/index.php — Executive Dashboard
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

$branchId = (int) $user['branch_id'];
$selectedBranch = $_GET['branch'] ?? 'all';

$branchFilterSQL = $selectedBranch !== 'all' ? "AND wl.branch_id  = " . (int) $selectedBranch : '';
$branchFilterSQLWP = $selectedBranch !== 'all' ? "AND wp.branch_id  = " . (int) $selectedBranch : '';
$branchFilterSQLU = $selectedBranch !== 'all' ? "AND u.branch_id   = " . (int) $selectedBranch : '';
$branchFilterSQLCO = $selectedBranch !== 'all' ? "AND owl.branch_id = " . (int) $selectedBranch : '';

$now = new DateTime();
$month = $_GET['month'] ?? $now->format('Y-m');
$monthDate = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthEnd = $monthDate->format('Y-m-t');
$monthLabel = $monthDate->format('F Y');

/* ── FIELD KPIs ── */
$totalLogs = (int) $db->query("SELECT COUNT(*) FROM work_logs wl WHERE 1=1 {$branchFilterSQL} AND wl.month_year='{$month}'")->fetchColumn();
$totalHours = (float) $db->query("SELECT COALESCE(SUM(wl.duration_hours),0) FROM work_logs wl WHERE 1=1 {$branchFilterSQL} AND wl.month_year='{$month}'")->fetchColumn();
$totalClients = (int) $db->query("SELECT COUNT(DISTINCT wl.client_id) FROM work_logs wl WHERE 1=1 {$branchFilterSQL} AND wl.month_year='{$month}'")->fetchColumn();

$activeStaff = (int) $db->query("
    SELECT COUNT(DISTINCT wl.user_id) FROM work_logs wl
    WHERE wl.month_year='{$month}' {$branchFilterSQL}
      AND wl.user_id IN (
          SELECT u.id FROM users u JOIN departments d ON d.id=u.department_id AND d.dept_code='CON' WHERE u.is_active=1
          UNION
          SELECT uda.user_id FROM user_department_assignments uda JOIN departments d ON d.id=uda.department_id AND d.dept_code='CON'
      )")->fetchColumn();

$visitedCnt = (int) $db->query("SELECT COUNT(*) FROM work_logs wl WHERE 1=1 {$branchFilterSQL} AND wl.month_year='{$month}' AND wl.visit_status='visited'")->fetchColumn();
$missedCnt = (int) $db->query("SELECT COUNT(*) FROM work_logs wl WHERE 1=1 {$branchFilterSQL} AND wl.month_year='{$month}' AND wl.visit_status='missed'")->fetchColumn();
$rescheduledCnt = (int) $db->query("SELECT COUNT(*) FROM work_logs wl WHERE 1=1 {$branchFilterSQL} AND wl.month_year='{$month}' AND wl.visit_status='rescheduled'")->fetchColumn();

$pendingApprovals = (int) $db->query("SELECT COUNT(*) FROM work_plans wp WHERE 1=1 {$branchFilterSQLWP} AND wp.status='submitted'")->fetchColumn();

$plannedHours = (float) $db->query("
    SELECT COALESCE(SUM(wpe.planned_hours),0)
    FROM work_plan_entries wpe JOIN work_plans wp ON wp.id=wpe.plan_id
    WHERE 1=1 {$branchFilterSQLWP} AND wp.plan_month='{$monthStart}'")->fetchColumn();

$rawEff = $plannedHours > 0 ? round(($totalHours / $plannedHours) * 100) : 0;
$efficiency = min($rawEff, 100);

/* ── OFFICE KPIs ── */
$officeLogCount = (int) $db->query("SELECT COUNT(*) FROM office_work_logs owl WHERE 1=1 {$branchFilterSQLCO} AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'")->fetchColumn();
$officeCompleted = (int) $db->query("SELECT COUNT(*) FROM office_work_logs owl WHERE 1=1 {$branchFilterSQLCO} AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}' AND owl.status='completed'")->fetchColumn();
$officeWip = (int) $db->query("SELECT COUNT(*) FROM office_work_logs owl WHERE 1=1 {$branchFilterSQLCO} AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}' AND owl.status='wip'")->fetchColumn();
$officeHolding = (int) $db->query("SELECT COUNT(*) FROM office_work_logs owl WHERE 1=1 {$branchFilterSQLCO} AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}' AND owl.status='holding'")->fetchColumn();
$officeNotStart = (int) $db->query("SELECT COUNT(*) FROM office_work_logs owl WHERE 1=1 {$branchFilterSQLCO} AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}' AND owl.status='not_started'")->fetchColumn();
$officeClients = (int) $db->query("SELECT COUNT(DISTINCT owl.client_id) FROM office_work_logs owl WHERE 1=1 {$branchFilterSQLCO} AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'")->fetchColumn();
$officeHours = (float) $db->query("SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE,owl.time_in,owl.time_out)/60),0) FROM office_work_logs owl WHERE 1=1 {$branchFilterSQLCO} AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'")->fetchColumn();

/* ── STAFF PERFORMANCE ── */
/* ── STAFF PERFORMANCE ── */
$staffPerf = $db->query("
    SELECT u.id, u.full_name, u.employee_id,
           COUNT(DISTINCT wl.id)              AS log_count,
           COALESCE(SUM(wl.duration_hours),0) AS actual_hours,
           SUM(wl.visit_status='visited')      AS visited,
           SUM(wl.visit_status='missed')       AS missed,
           SUM(wl.visit_status='rescheduled')  AS rescheduled,
           COUNT(DISTINCT wl.client_id)        AS unique_clients,
           COUNT(DISTINCT wl.log_date)         AS active_days,
           /* office */
           (SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE,owl.time_in,owl.time_out)/60.0),0)
            FROM office_work_logs owl
            WHERE owl.user_id=u.id
              AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
              " . ($selectedBranch !== 'all' ? "AND owl.branch_id=" . (int) $selectedBranch : "") . "
           ) AS office_hours,
           (SELECT COUNT(*)
            FROM office_work_logs owl
            WHERE owl.user_id=u.id
              AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
              " . ($selectedBranch !== 'all' ? "AND owl.branch_id=" . (int) $selectedBranch : "") . "
           ) AS office_logs
    FROM users u
    LEFT JOIN work_logs wl
           ON wl.user_id=u.id
          AND wl.month_year='{$month}'
          " . ($selectedBranch !== 'all' ? "AND wl.branch_id=" . (int) $selectedBranch : "") . "
    WHERE u.is_active=1
      AND (
          /* self */
          u.id = {$uid}
          OR
          /* directly managed */
          u.managed_by = {$uid}
          OR
          /* managed via UDA */
          u.id IN (
              SELECT uda.user_id FROM user_department_assignments uda
              WHERE uda.managed_by = {$uid}
          )
          OR
          /* in CON dept (executive sees all CON staff) */
          u.id IN (
              SELECT u2.id FROM users u2
              JOIN departments d ON d.id=u2.department_id AND d.dept_code='CON'
              WHERE u2.is_active=1
              UNION
              SELECT uda2.user_id FROM user_department_assignments uda2
              JOIN departments d ON d.id=uda2.department_id AND d.dept_code='CON'
          )
      )
    GROUP BY u.id, u.full_name, u.employee_id
    ORDER BY actual_hours DESC
")->fetchAll(PDO::FETCH_ASSOC);

$staffPlanned = $db->query("
    SELECT wpe.assigned_to,
           COALESCE(SUM(wpe.planned_hours),0) AS planned,
           COUNT(DISTINCT wpe.id)              AS planned_visits
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    WHERE wp.plan_month = '{$monthStart}'
      " . ($selectedBranch !== 'all' ? "AND wp.branch_id=" . (int) $selectedBranch : "") . "
    GROUP BY wpe.assigned_to
")->fetchAll(PDO::FETCH_ASSOC);

/* Re-index by assigned_to for easy lookup */
$staffPlannedMap = array_column($staffPlanned, 'planned', 'assigned_to');
$staffPlannedVMap = array_column($staffPlanned, 'planned_visits', 'assigned_to');

/* ── CLIENT SUMMARY ── */
$clientSummary = $db->query("
    SELECT c.id, c.company_name, c.company_code,
           COUNT(wl.id) AS total_visits,
           COALESCE(SUM(wl.duration_hours),0) AS total_hours,
           SUM(wl.visit_status='visited') AS visited,
           SUM(wl.visit_status='missed')  AS missed,
           MAX(wl.log_date) AS last_visit
    FROM companies c
    LEFT JOIN work_logs wl ON wl.client_id=c.id AND wl.month_year='{$month}' AND wl.branch_id={$branchId}
    WHERE c.branch_id={$branchId} AND c.is_active=1
    GROUP BY c.id ORDER BY total_visits DESC LIMIT 10")->fetchAll();

/* ── OFFICE LOGS TABLE ── */
$officeLogs = $db->query("
    SELECT owl.*, u.full_name, u.employee_id AS emp_id, c.company_name, c.company_code, d.dept_name
    FROM office_work_logs owl
    JOIN users u ON u.id=owl.user_id
    JOIN companies c ON c.id=owl.client_id
    JOIN departments d ON d.id=owl.department_id
    WHERE 1=1 {$branchFilterSQLCO} AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
    ORDER BY owl.log_date DESC, owl.time_in DESC LIMIT 15")->fetchAll();

/* ── PENDING APPROVALS ── */
$pendingPlans = $db->query("
    SELECT wp.*, u.full_name, u.employee_id, COUNT(wpe.id) entry_count, COALESCE(SUM(wpe.planned_hours),0) planned_hours
    FROM work_plans wp JOIN users u ON u.id=wp.user_id
    LEFT JOIN work_plan_entries wpe ON wpe.plan_id=wp.id
    WHERE wp.branch_id={$branchId} AND wp.status='submitted'
    GROUP BY wp.id ORDER BY wp.created_at ASC LIMIT 8")->fetchAll();

/* ── 6-MONTH TREND ── */
$trend = $db->query("
    SELECT wl.month_year, COUNT(*) AS logs, COALESCE(SUM(wl.duration_hours),0) AS hours,
           SUM(wl.visit_status='visited') AS visited, SUM(wl.visit_status='missed') AS missed
    FROM work_logs wl WHERE 1=1 {$branchFilterSQL}
    GROUP BY wl.month_year ORDER BY wl.month_year DESC LIMIT 6")->fetchAll();
$trend = array_reverse($trend);

$officeTrend = $db->query("
    SELECT DATE_FORMAT(owl.log_date,'%Y-%m') AS month_year,
           COUNT(*) AS logs,
           COALESCE(SUM(TIMESTAMPDIFF(MINUTE,owl.time_in,owl.time_out)/60),0) AS hours
    FROM office_work_logs owl WHERE 1=1 {$branchFilterSQLCO}
    GROUP BY DATE_FORMAT(owl.log_date,'%Y-%m') ORDER BY month_year DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$officeTrend = array_reverse($officeTrend);

/* ── ALERTS ── */
$noLogStaff = $db->query("
    SELECT DISTINCT u.full_name, u.employee_id FROM users u
    WHERE u.is_active=1 {$branchFilterSQLU}
      AND u.id IN (
          SELECT u2.id FROM users u2 JOIN departments d ON d.id=u2.department_id AND d.dept_code='CON' WHERE u2.is_active=1
          UNION SELECT uda.user_id FROM user_department_assignments uda JOIN departments d ON d.id=uda.department_id AND d.dept_code='CON'
      )
      AND u.id NOT IN (SELECT DISTINCT wl.user_id FROM work_logs wl WHERE wl.month_year='{$month}' {$branchFilterSQL})")->fetchAll();

$unvisited = $db->query("
    SELECT c.company_name, c.company_code FROM companies c
    WHERE c.branch_id={$branchId} AND c.is_active=1
      AND c.id NOT IN (SELECT DISTINCT wl.client_id FROM work_logs wl WHERE wl.month_year='{$month}' AND wl.branch_id={$branchId})
    LIMIT 8")->fetchAll();

/* ── BUILD JS DATA ARRAYS ── */
$trendLabels = json_encode(array_map(fn($t) => date('M', strtotime($t['month_year'] . '-01')), $trend));
$trendFieldHours = json_encode(array_map(fn($t) => round((float) $t['hours'], 1), $trend));
$trendFieldVisit = json_encode(array_map(fn($t) => (int) $t['visited'], $trend));
$trendFieldMiss = json_encode(array_map(fn($t) => (int) $t['missed'], $trend));

$trendOfficeMapped = [];
foreach ($trend as $t) {
    $found = array_filter($officeTrend, fn($o) => $o['month_year'] === $t['month_year']);
    $trendOfficeMapped[] = $found ? round((float) array_values($found)[0]['hours'], 1) : 0;
}
$trendOfficeHours = json_encode($trendOfficeMapped);

$clientNames = json_encode(array_map(fn($c) => mb_strimwidth($c['company_name'], 0, 18, '…'), $clientSummary));
$clientVisits = json_encode(array_map(fn($c) => (int) $c['visited'], $clientSummary));
$clientMissed = json_encode(array_map(fn($c) => (int) $c['missed'], $clientSummary));
$clientHours = json_encode(array_map(fn($c) => round((float) $c['total_hours'], 1), $clientSummary));

/* ── BUILD JS DATA ARRAYS ── */
$staffNames = json_encode(array_map(fn($s) => explode(' ', $s['full_name'])[0], $staffPerf));
$staffHours = json_encode(array_map(fn($s) => round((float) $s['actual_hours'], 1), $staffPerf));
$staffOfficeHours = json_encode(array_map(fn($s) => round((float) ($s['office_hours'] ?? 0), 1), $staffPerf));
$staffPlannedArr = json_encode(array_map(fn($s) => round((float) ($staffPlannedMap[$s['id']] ?? 0), 1), $staffPerf));
$staffPlannedVArr = json_encode(array_map(fn($s) => (int) ($staffPlannedVMap[$s['id']] ?? 0), $staffPerf));
$staffVisited = json_encode(array_map(fn($s) => (int) $s['visited'], $staffPerf));
$staffMissed = json_encode(array_map(fn($s) => (int) $s['missed'], $staffPerf));
$staffRescheduled = json_encode(array_map(fn($s) => (int) $s['rescheduled'], $staffPerf));

$branches = $db->query("SELECT id, branch_name FROM branches ORDER BY branch_name")->fetchAll();

$pageTitle = 'Executive Dashboard';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="../../../staff/planning/consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap"
    rel="stylesheet">

<style>
    /* ═══════════════════════════════════════════
   EXECUTIVE DASHBOARD — LAYOUT RESET
   Key fix: override .main-content so it
   doesn't fight with the sidebar width,
   and use padding-based layout instead of
   relying on the outer grid to size panels.
═══════════════════════════════════════════ */

    :root {
        --gold: #c9a84c;
        --gold-lt: #fdf6e3;
        --blue: #378ADD;
        --blue-lt: #E6F1FB;
        --green: #1D9E75;
        --green-lt: #E1F5EE;
        --green-dk: #0F6E56;
        --red: #E24B4A;
        --red-lt: #FCEBEB;
        --red-dk: #A32D2D;
        --amber: #BA7517;
        --amber-lt: #FAEEDA;
        --purple: #7F77DD;
        --purple-lt: #EEEDFE;
        --gray-50: #f9fafb;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-400: #9ca3af;
        --gray-600: #6b7280;
        --gray-800: #1f2937;
        --radius-sm: 6px;
        --radius-md: 10px;
        --radius-lg: 14px;
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, .06);
    }

    *,
    *::before,
    *::after {
        box-sizing: border-box;
    }

    /* ── Critical layout fix ── */
    .db-wrap {
        padding: 0 0 2.5rem;
        font-family: 'DM Sans', sans-serif;
        width: 100%;
        min-width: 0;
        overflow-x: hidden;
    }

    /* Ensure main-content doesn't overflow the viewport */
    .main-content {
        min-width: 0 !important;
        overflow-x: hidden !important;
        width: 100% !important;
    }

    /* ── Section divider ── */
    .sec-div {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 1.75rem 0 1.1rem;
    }

    .sec-div::before,
    .sec-div::after {
        content: '';
        flex: 1;
        height: 1px;
        background: var(--gray-200);
    }

    .sec-div-label {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 11px;
        font-weight: 600;
        color: var(--gray-600);
        text-transform: uppercase;
        letter-spacing: .07em;
        white-space: nowrap;
    }

    /* ── KPI grid ── */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
        gap: 8px;
        margin-bottom: 1.25rem;
    }

    .kpi-tile {
        background: #fff;
        border: 1px solid var(--gray-200);
        border-radius: var(--radius-md);
        padding: 12px 14px;
        position: relative;
        overflow: hidden;
        transition: box-shadow .2s;
        min-width: 0;
    }

    .kpi-tile:hover {
        box-shadow: var(--shadow-sm);
    }

    .kpi-tile-accent {
        position: absolute;
        top: 0;
        left: 0;
        width: 3px;
        height: 100%;
        border-radius: 3px 0 0 3px;
    }

    .kpi-icon {
        font-size: 15px;
        margin-bottom: 6px;
        opacity: .85;
    }

    .kpi-val {
        font-size: 21px;
        font-weight: 600;
        color: var(--gray-800);
        line-height: 1;
        font-family: 'DM Mono', monospace;
    }

    .kpi-lbl {
        font-size: 11px;
        color: var(--gray-400);
        margin-top: 4px;
        font-weight: 500;
    }

    .kpi-sub {
        font-size: 10px;
        color: var(--gray-400);
        margin-top: 2px;
    }

    /* ── Panel ── */
    .panel {
        background: #fff;
        border: 1px solid var(--gray-200);
        border-radius: var(--radius-lg);
        overflow: hidden;
        box-shadow: var(--shadow-sm);
        min-width: 0;
    }

    .panel-hd {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        border-bottom: 1px solid var(--gray-100);
    }

    .panel-title {
        font-size: 12.5px;
        font-weight: 600;
        color: var(--gray-800);
        display: flex;
        align-items: center;
        gap: 7px;
    }

    .panel-sub {
        font-size: 11px;
        color: var(--gray-400);
    }

    /* ── THE KEY GRID FIX ──
   Use margin-bottom on each grid wrapper instead of on .panel
   so panels inside grids don't add extra space.
   All children get min-width:0 to allow shrinking.
── */
    .db-grid-3 {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
        margin-bottom: 1.25rem;
        width: 100%;
    }

    .db-grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 14px;
        margin-bottom: 1.25rem;
        width: 100%;
    }

    .db-grid-main {
        display: grid;
        grid-template-columns: 1fr 320px;
        gap: 14px;
        margin-bottom: 1.25rem;
        width: 100%;
    }

    .db-grid-3>*,
    .db-grid-2>*,
    .db-grid-main>* {
        min-width: 0;
        overflow: hidden;
    }

    /* ── Chart wrapper ── */
    .chart-box {
        position: relative;
        width: 100%;
    }

    .chart-box canvas {
        display: block !important;
        width: 100% !important;
    }

    /* ── Bar rows ── */
    .bar-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 9px;
        font-size: 12px;
    }

    .bar-lbl {
        flex-shrink: 0;
        font-size: 11px;
        font-weight: 500;
        color: var(--gray-600);
        width: 80px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .bar-track {
        flex: 1;
        min-width: 0;
        height: 7px;
        background: var(--gray-100);
        border-radius: 99px;
        overflow: hidden;
    }

    .bar-fill {
        height: 100%;
        border-radius: 99px;
        transition: width .5s cubic-bezier(.4, 0, .2, 1);
    }

    .bar-val {
        flex-shrink: 0;
        width: 40px;
        text-align: right;
        font-weight: 600;
        color: var(--gray-800);
        font-family: 'DM Mono', monospace;
        font-size: 11px;
    }

    /* ── Legend ── */
    .legend-row {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        padding: 8px 16px 12px;
        font-size: 11px;
        color: var(--gray-600);
    }

    .leg-item {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .leg-dot {
        width: 8px;
        height: 8px;
        border-radius: 2px;
        flex-shrink: 0;
    }

    /* ── Visit status donut panel inner ── */
    .donut-wrap {
        padding: 14px 16px;
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: nowrap;
    }

    .donut-canvas-wrap {
        position: relative;
        flex-shrink: 0;
        width: 90px;
        height: 90px;
    }

    .donut-legend {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 7px;
    }

    .donut-leg-row {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 11px;
        min-width: 0;
    }

    .donut-center {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        pointer-events: none;
    }

    /* ── Pills ── */
    .pill {
        display: inline-block;
        font-size: 10px;
        font-weight: 600;
        padding: 2px 7px;
        border-radius: 99px;
        letter-spacing: .02em;
        flex-shrink: 0;
    }

    .pill-v {
        background: var(--green-lt);
        color: var(--green-dk);
    }

    .pill-m {
        background: var(--red-lt);
        color: var(--red-dk);
    }

    .pill-a {
        background: var(--amber-lt);
        color: var(--amber);
    }

    .pill-b {
        background: var(--blue-lt);
        color: #185FA5;
    }

    .pill-g {
        background: var(--gray-100);
        color: var(--gray-600);
    }

    .pill-p {
        background: var(--purple-lt);
        color: #534AB7;
    }

    /* ── Staff list ── */
    .staff-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 0;
        border-bottom: 1px solid var(--gray-100);
    }

    .staff-item:last-child {
        border-bottom: none;
    }

    .avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 600;
        flex-shrink: 0;
        font-family: 'DM Mono', monospace;
    }

    .eff-bar {
        height: 3px;
        border-radius: 99px;
        background: var(--gray-100);
        margin-top: 4px;
        width: 64px;
        overflow: hidden;
    }

    .eff-fill {
        height: 100%;
        border-radius: 99px;
        transition: width .5s;
    }

    /* ── Alert items ── */
    .alert-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 7px 10px;
        border-radius: var(--radius-sm);
        margin-bottom: 5px;
        font-size: 12px;
        font-weight: 500;
    }

    /* ── Office logs table ── */
    .ol-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }

    .ol-table th {
        padding: 9px 12px;
        background: var(--gray-50);
        color: var(--gray-600);
        font-weight: 600;
        font-size: 11px;
        text-align: left;
        border-bottom: 1px solid var(--gray-200);
    }

    .ol-table td {
        padding: 9px 12px;
        border-bottom: 1px solid var(--gray-100);
        color: var(--gray-800);
        vertical-align: middle;
    }

    .ol-table tr:last-child td {
        border-bottom: none;
    }

    .ol-table tr:hover td {
        background: var(--gray-50);
    }

    /* ── Pending plan card ── */
    .pend-card {
        background: var(--gray-50);
        border-radius: var(--radius-md);
        padding: 10px 12px;
        border-left: 3px solid var(--blue);
        margin-bottom: 8px;
    }

    .pend-card:last-child {
        margin-bottom: 0;
    }

    /* ── Responsive ── */
    @media (max-width: 1100px) {
        .db-grid-3 {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 900px) {

        .db-grid-3,
        .db-grid-2,
        .db-grid-main {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <!-- ══ PAGE HERO ══ -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-briefcase"></i> Executive · Consulting</div>
                        <h4>Executive Dashboard</h4>
                        <p><?= htmlspecialchars($user['full_name']) ?> · <?= $monthLabel ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="month" class="form-control form-control-sm" style="width:155px;"
                            value="<?= $month ?>"
                            onchange="location='?month='+this.value+'&branch=<?= $selectedBranch ?>'">
                        <select class="form-control form-control-sm" style="width:170px;"
                            onchange="location='?month=<?= $month ?>&branch='+this.value">
                            <option value="all" <?= $selectedBranch === 'all' ? 'selected' : '' ?>>All Branches</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $selectedBranch == $b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($pendingApprovals > 0): ?>
                            <a href="plans.php?status=submitted&month=<?= $month ?>" class="btn btn-sm btn-warning">
                                <i class="fas fa-bell me-1"></i><?= $pendingApprovals ?> Pending
                            </a>
                        <?php endif; ?>
                        <a href="create_plan.php" class="btn btn-sm btn-gold">
                            <i class="fas fa-plus me-1"></i> Create Plan
                        </a>
                        <a href="staff_report.php?month=<?= $month ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-chart-bar me-1"></i> Reports
                        </a>
                    </div>
                </div>
            </div>

            <!-- ══════════ SECTION 1 — FIELD VISITS ══════════ -->
            <div class="sec-div">
                <span class="sec-div-label">
                    <i class="fas fa-car" style="color:var(--gold)"></i> Field visit overview
                </span>
            </div>

            <?php
            $effColor = $rawEff >= 80 ? 'var(--green)' : ($rawEff >= 50 ? 'var(--amber)' : 'var(--red)');
            $fieldKpis = [
                ['icon' => 'fa-users', 'val' => $activeStaff, 'lbl' => 'Active Staff', 'color' => 'var(--blue)'],
                ['icon' => 'fa-clipboard-list', 'val' => number_format($totalLogs), 'lbl' => 'Field Logs', 'color' => 'var(--purple)'],
                ['icon' => 'fa-clock', 'val' => number_format($totalHours, 1) . 'h', 'lbl' => 'Field Hours', 'color' => 'var(--gold)'],
                ['icon' => 'fa-building', 'val' => $totalClients, 'lbl' => 'Clients Reached', 'color' => '#0ea5e9'],
                ['icon' => 'fa-check-circle', 'val' => $visitedCnt, 'lbl' => 'Visited', 'color' => 'var(--green)'],
                ['icon' => 'fa-times-circle', 'val' => $missedCnt, 'lbl' => 'Missed', 'color' => 'var(--red)'],
                ['icon' => 'fa-calendar-alt', 'val' => $rescheduledCnt, 'lbl' => 'Rescheduled', 'color' => 'var(--amber)'],
                ['icon' => 'fa-bell', 'val' => $pendingApprovals, 'lbl' => 'Pending Approval', 'color' => '#f59e0b'],
                ['icon' => 'fa-tachometer-alt', 'val' => $efficiency . '%', 'lbl' => 'Team Efficiency', 'color' => $effColor, 'sub' => number_format($plannedHours, 1) . 'h planned'],
            ];
            ?>
            <div class="kpi-grid mb-3">
                <?php foreach ($fieldKpis as $k): ?>
                    <div class="kpi-tile">
                        <div class="kpi-tile-accent" style="background:<?= $k['color'] ?>"></div>
                        <div class="kpi-icon"><i class="fas <?= $k['icon'] ?>" style="color:<?= $k['color'] ?>"></i></div>
                        <div class="kpi-val" style="color:<?= $k['color'] ?>"><?= $k['val'] ?></div>
                        <div class="kpi-lbl"><?= $k['lbl'] ?></div>
                        <?php if (!empty($k['sub'])): ?>
                            <div class="kpi-sub"><?= $k['sub'] ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 3-col row: Visit Status Donut | Planned vs Actual | Monthly Trend -->
            <div class="db-grid-3">

                <!-- Visit Status Donut -->
                <div class="panel">
                    <div class="panel-hd">
                        <span class="panel-title"><i class="fas fa-chart-pie" style="color:var(--gold)"></i> Visit
                            Status</span>
                        <span class="panel-sub"><?= $monthLabel ?></span>
                    </div>
                    <div class="donut-wrap">
                        <div class="donut-canvas-wrap">
                            <canvas id="donutChart" width="90" height="90"></canvas>
                            <div class="donut-center">
                                <span
                                    style="font-size:18px;font-weight:600;color:var(--gray-800);font-family:'DM Mono',monospace"><?= $visitedCnt + $missedCnt + $rescheduledCnt ?></span>
                                <span style="font-size:9px;color:var(--gray-400)">total</span>
                            </div>
                        </div>
                        <div class="donut-legend">
                            <?php
                            $total = $visitedCnt + $missedCnt + $rescheduledCnt ?: 1;
                            $statusRows = [
                                ['Visited', $visitedCnt, '#1D9E75', 'var(--green-lt)', 'var(--green-dk)'],
                                ['Missed', $missedCnt, '#E24B4A', 'var(--red-lt)', 'var(--red-dk)'],
                                ['Rescheduled', $rescheduledCnt, '#BA7517', 'var(--amber-lt)', 'var(--amber)'],
                            ];
                            foreach ($statusRows as [$lbl, $cnt, $bg, $bglt, $txtc]): ?>
                                <div class="donut-leg-row">
                                    <span
                                        style="width:7px;height:7px;border-radius:2px;background:<?= $bg ?>;flex-shrink:0"></span>
                                    <span
                                        style="color:var(--gray-600);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $lbl ?></span>
                                    <span
                                        style="font-weight:600;color:var(--gray-800);font-family:'DM Mono',monospace;margin-right:4px"><?= $cnt ?></span>
                                    <span class="pill"
                                        style="background:<?= $bglt ?>;color:<?= $txtc ?>"><?= round($cnt / $total * 100) ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Planned vs Actual -->
                <div class="panel">
                    <div class="panel-hd">
                        <span class="panel-title"><i class="fas fa-sliders-h" style="color:var(--gold)"></i> Planned vs
                            Actual</span>
                    </div>
                    <div style="padding:14px 16px">
                        <?php
                        $pvaRows = [
                            ['Planned', $plannedHours, $plannedHours, 'var(--blue)'],
                            ['Field actual', $totalHours, $plannedHours, $effColor],
                            ['Office hrs', $officeHours, $plannedHours, 'var(--green)'],
                        ];
                        foreach ($pvaRows as [$lbl, $val, $base, $color]):
                            $w = $base > 0 ? min(round($val / $base * 100), 100) : 0;
                            ?>
                            <div class="bar-row">
                                <span class="bar-lbl"><?= $lbl ?></span>
                                <div class="bar-track">
                                    <div class="bar-fill" style="width:<?= $w ?>%;background:<?= $color ?>"></div>
                                </div>
                                <span class="bar-val"><?= number_format($val, 1) ?>h</span>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($totalLogs > 0): ?>
                            <div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--gray-100)">
                                <div style="font-size:10px;color:var(--gray-400);margin-bottom:5px;font-weight:500">Visit
                                    breakdown</div>
                                <div style="display:flex;border-radius:5px;overflow:hidden;height:7px">
                                    <?php if ($visitedCnt): ?>
                                        <div style="flex:<?= $visitedCnt ?>;background:var(--green)"></div><?php endif; ?>
                                    <?php if ($missedCnt): ?>
                                        <div style="flex:<?= $missedCnt ?>;background:var(--red)"></div><?php endif; ?>
                                    <?php if ($rescheduledCnt): ?>
                                        <div style="flex:<?= $rescheduledCnt ?>;background:var(--amber)"></div><?php endif; ?>
                                </div>
                                <div style="display:flex;gap:8px;margin-top:5px;font-size:10px;flex-wrap:wrap">
                                    <span style="color:var(--green-dk)">● Visited <?= $visitedCnt ?></span>
                                    <span style="color:var(--red-dk)">● Missed <?= $missedCnt ?></span>
                                    <span style="color:var(--amber)">● Rescheduled <?= $rescheduledCnt ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Monthly Trend sparkline -->
                <div class="panel">
                    <div class="panel-hd">
                        <span class="panel-title"><i class="fas fa-chart-bar" style="color:var(--gold)"></i> Monthly
                            Trend</span>
                        <span class="panel-sub">Last 6 months</span>
                    </div>
                    <div class="chart-box" style="height:150px;padding:12px 14px 0">
                        <canvas id="fieldTrendMini"></canvas>
                    </div>
                    <div class="legend-row">
                        <span class="leg-item"><span class="leg-dot" style="background:var(--blue)"></span>Field
                            hrs</span>
                        <span class="leg-item"><span class="leg-dot" style="background:var(--green)"></span>Office
                            hrs</span>
                    </div>
                </div>
            </div><!-- /db-grid-3 field -->

            <!-- ══════════ SECTION 2 — OFFICE WORK ══════════ -->
            <div class="sec-div">
                <span class="sec-div-label">
                    <i class="fas fa-laptop" style="color:#3b82f6"></i> Office work overview
                </span>
            </div>

            <?php
            $officeKpis = [
                ['icon' => 'fa-file-alt', 'val' => number_format($officeLogCount), 'lbl' => 'Office Logs', 'color' => 'var(--blue)'],
                ['icon' => 'fa-hourglass', 'val' => number_format($officeHours, 1) . 'h', 'lbl' => 'Office Hours', 'color' => 'var(--gold)'],
                ['icon' => 'fa-building', 'val' => $officeClients, 'lbl' => 'Clients Served', 'color' => '#0ea5e9'],
                ['icon' => 'fa-check-double', 'val' => $officeCompleted, 'lbl' => 'Completed', 'color' => 'var(--green)'],
                ['icon' => 'fa-spinner', 'val' => $officeWip, 'lbl' => 'In Progress', 'color' => 'var(--amber)'],
                ['icon' => 'fa-pause-circle', 'val' => $officeHolding, 'lbl' => 'Holding', 'color' => 'var(--purple)'],
                ['icon' => 'fa-circle', 'val' => $officeNotStart, 'lbl' => 'Not Started', 'color' => 'var(--gray-400)'],
            ];
            ?>
            <div class="kpi-grid mb-3">
                <?php foreach ($officeKpis as $k): ?>
                    <div class="kpi-tile">
                        <div class="kpi-tile-accent" style="background:<?= $k['color'] ?>"></div>
                        <div class="kpi-icon"><i class="fas <?= $k['icon'] ?>" style="color:<?= $k['color'] ?>"></i></div>
                        <div class="kpi-val" style="color:<?= $k['color'] ?>"><?= $k['val'] ?></div>
                        <div class="kpi-lbl"><?= $k['lbl'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 2-col: Office Status Bars | Office Completion Donut -->
            <div class="db-grid-2">

                <div class="panel">
                    <div class="panel-hd">
                        <span class="panel-title"><i class="fas fa-tasks" style="color:var(--gold)"></i> Office Logs by
                            Status</span>
                        <span class="panel-sub"><?= $monthLabel ?></span>
                    </div>
                    <div style="padding:16px">
                        <?php
                        $maxO = max($officeCompleted, $officeWip, $officeHolding, $officeNotStart, 1);
                        $offRows = [
                            ['Completed', $officeCompleted, 'var(--green)'],
                            ['In Progress', $officeWip, 'var(--amber)'],
                            ['Holding', $officeHolding, 'var(--purple)'],
                            ['Not Started', $officeNotStart, 'var(--gray-400)'],
                        ];
                        foreach ($offRows as [$lbl, $v, $c]):
                            $w = round($v / $maxO * 100);
                            ?>
                            <div class="bar-row">
                                <span class="bar-lbl"><?= $lbl ?></span>
                                <div class="bar-track">
                                    <div class="bar-fill" style="width:<?= $w ?>%;background:<?= $c ?>"></div>
                                </div>
                                <span class="bar-val"><?= $v ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Office completion donut -->
                <div class="panel">
                    <div class="panel-hd">
                        <span class="panel-title"><i class="fas fa-chart-pie" style="color:var(--gold)"></i> Completion
                            Rate</span>
                    </div>
                    <div class="donut-wrap">
                        <div class="donut-canvas-wrap" style="width:100px;height:100px">
                            <canvas id="officeDonut" width="100" height="100"></canvas>
                            <div class="donut-center">
                                <?php $compRate = $officeLogCount > 0 ? round($officeCompleted / $officeLogCount * 100) : 0; ?>
                                <span
                                    style="font-size:17px;font-weight:600;color:var(--green);font-family:'DM Mono',monospace"><?= $compRate ?>%</span>
                                <span style="font-size:9px;color:var(--gray-400)">done</span>
                            </div>
                        </div>
                        <div class="donut-legend">
                            <?php
                            $offDonutRows = [
                                ['Completed', 'var(--green)', $officeCompleted],
                                ['WIP', 'var(--amber)', $officeWip],
                                ['Holding', 'var(--purple)', $officeHolding],
                                ['Not started', 'var(--gray-400)', $officeNotStart],
                            ];
                            foreach ($offDonutRows as [$l, $c, $v]): ?>
                                <div class="donut-leg-row">
                                    <span
                                        style="width:7px;height:7px;border-radius:2px;background:<?= $c ?>;flex-shrink:0"></span>
                                    <span style="color:var(--gray-600);flex:1"><?= $l ?></span>
                                    <span
                                        style="font-weight:600;color:var(--gray-800);font-family:'DM Mono',monospace"><?= $v ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div><!-- /db-grid-2 office -->

            <!-- Office logs table -->
            <div class="panel mb-3">
                <div class="panel-hd">
                    <span class="panel-title"><i class="fas fa-table" style="color:var(--gold)"></i> Recent Office
                        Logs</span>
                    <span class="panel-sub"><?= $officeLogCount ?> entries · <?= $monthLabel ?></span>
                </div>
                <?php if (empty($officeLogs)): ?>
                    <div style="padding:40px;text-align:center;color:var(--gray-400);font-size:13px">
                        <i class="fas fa-laptop" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.3"></i>
                        No office logs this month
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto">
                        <table class="ol-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Staff</th>
                                    <th>Client</th>
                                    <th>Dept</th>
                                    <th>Time In</th>
                                    <th>Time Out</th>
                                    <th style="text-align:center">Hours</th>
                                    <th>Description</th>
                                    <th style="text-align:center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($officeLogs as $ol):
                                    $hrs = 0;
                                    if ($ol['time_in'] && $ol['time_out'])
                                        $hrs = (strtotime($ol['time_out']) - strtotime($ol['time_in'])) / 3600;
                                    $statusMap = [
                                        'completed' => ['class' => 'pill-v', 'lbl' => 'Completed'],
                                        'wip' => ['class' => 'pill-a', 'lbl' => 'WIP'],
                                        'holding' => ['class' => 'pill-p', 'lbl' => 'Holding'],
                                        'not_started' => ['class' => 'pill-g', 'lbl' => 'Not Started'],
                                    ];
                                    $sm = $statusMap[$ol['status']] ?? $statusMap['not_started'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600;font-size:12px">
                                                <?= date('d M', strtotime($ol['log_date'])) ?>
                                            </div>
                                            <div style="font-size:10px;color:var(--gray-400)">
                                                <?= date('D', strtotime($ol['log_date'])) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600"><?= htmlspecialchars($ol['full_name']) ?></div>
                                            <div style="font-size:10px;color:var(--gray-400)">
                                                <?= htmlspecialchars($ol['emp_id'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600"><?= htmlspecialchars($ol['company_name']) ?></div>
                                            <div style="font-size:10px;color:var(--gray-400)">
                                                <?= htmlspecialchars($ol['company_code'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td style="color:var(--gray-600);font-size:11px">
                                            <?= htmlspecialchars($ol['dept_name']) ?>
                                        </td>
                                        <td style="font-family:'DM Mono',monospace;font-size:11px">
                                            <?= $ol['time_in'] ? date('h:i A', strtotime($ol['time_in'])) : '—' ?>
                                        </td>
                                        <td style="font-family:'DM Mono',monospace;font-size:11px">
                                            <?= $ol['time_out'] ? date('h:i A', strtotime($ol['time_out'])) : '—' ?>
                                        </td>
                                        <td style="text-align:center">
                                            <strong
                                                style="color:var(--gold);font-family:'DM Mono',monospace"><?= number_format($hrs, 1) ?>h</strong>
                                        </td>
                                        <td style="color:var(--gray-600);max-width:180px;overflow:hidden">
                                            <div
                                                style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:180px">
                                                <?= htmlspecialchars(mb_strimwidth($ol['description'] ?? '', 0, 60, '…')) ?>
                                            </div>
                                        </td>
                                        <td style="text-align:center">
                                            <span class="pill <?= $sm['class'] ?>"><?= $sm['lbl'] ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ══════════ SECTION 3 — PERFORMANCE ══════════ -->
            <div class="sec-div">
                <span class="sec-div-label">
                    <i class="fas fa-chart-line" style="color:#8b5cf6"></i> Performance analysis
                </span>
            </div>

            <!-- Full trend line chart -->
            <div class="panel mb-3">
                <div class="panel-hd">
                    <span class="panel-title"><i class="fas fa-chart-line" style="color:var(--gold)"></i> Field vs
                        Office Hours Trend</span>
                    <span class="panel-sub">Last 6 months</span>
                </div>
                <div class="chart-box" style="height:200px;padding:14px 16px 0">
                    <canvas id="trendFull"></canvas>
                </div>
                <div class="legend-row" style="margin-top:4px">
                    <span class="leg-item"><span class="leg-dot" style="background:var(--blue)"></span>Field
                        hours</span>
                    <span class="leg-item"><span class="leg-dot" style="background:var(--green)"></span>Office
                        hours</span>
                    <span class="leg-item"><span class="leg-dot" style="background:#E24B4A"></span>Missed visits</span>
                </div>
            </div>

            <!-- Staff Performance + Right sidebar -->
            <div class="db-grid-main">

                <!-- Staff Performance -->
                <div class="panel">
                    <div class="panel-hd">
                        <span class="panel-title"><i class="fas fa-users" style="color:var(--gold)"></i> Staff
                            Performance</span>
                        <a href="staff_report.php?month=<?= $month ?>" style="font-size:11px;color:var(--blue)">
                            Full Report <i class="fas fa-chevron-right" style="font-size:.6rem"></i>
                        </a>
                    </div>
                    <?php $staffH = max(count($staffPerf) * 36 + 60, 120); ?>
                    <!-- Hours chart -->
                    <div
                        style="padding:8px 14px 0;font-size:10px;font-weight:600;color:var(--gray-400);text-transform:uppercase;letter-spacing:.06em">
                        Hours (Field + Office vs Planned)
                    </div>
                    <div class="chart-box" style="height:<?= $staffH ?>px;padding:4px 14px 0">
                        <canvas id="staffChart"></canvas>
                    </div>
                    <div class="legend-row" style="padding-bottom:0">
                        <span class="leg-item"><span class="leg-dot" style="background:var(--blue)"></span>Field
                            hours</span>
                        <span class="leg-item"><span class="leg-dot" style="background:var(--green)"></span>Office
                            hours</span>
                        <span class="leg-item"><span class="leg-dot"
                                style="background:var(--gray-200)"></span>Planned</span>
                    </div>
                    <!-- Visits chart -->
                    <div
                        style="padding:8px 14px 0;font-size:10px;font-weight:600;color:var(--gray-400);text-transform:uppercase;letter-spacing:.06em">
                        Visit Status (Planned vs Visited vs Missed)
                    </div>
                    <div class="chart-box" style="height:<?= $staffH ?>px;padding:4px 14px 8px">
                        <canvas id="staffVisitChart"></canvas>
                    </div>
                    <div class="legend-row">
                        <span class="leg-item"><span class="leg-dot" style="background:var(--gray-200)"></span>Planned
                            visits</span>
                        <span class="leg-item"><span class="leg-dot"
                                style="background:var(--green)"></span>Visited</span>
                        <span class="leg-item"><span class="leg-dot" style="background:var(--red)"></span>Missed</span>
                        <span class="leg-item"><span class="leg-dot"
                                style="background:var(--amber)"></span>Rescheduled</span>
                    </div>
                    <div style="padding:4px 16px 12px">
                        <?php foreach ($staffPerf as $sp):
                            $pl = $staffPlannedMap[$sp['id']] ?? 0;
                            $eff = $pl > 0 ? min(round($sp['actual_hours'] / $pl * 100), 100) : 0;
                            $ec = $eff >= 80 ? 'var(--green)' : ($eff >= 50 ? 'var(--amber)' : 'var(--red)');
                            $initials = implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), explode(' ', $sp['full_name'])));
                            $colors = ['#378ADD', '#1D9E75', '#BA7517', '#7F77DD', '#E24B4A', '#D4537E', '#639922', '#0F6E56'];
                            $c = $colors[abs(crc32($sp['full_name'])) % count($colors)];
                            ?>
                            <div class="staff-item">
                                <div class="avatar" style="background:<?= $c ?>22;color:<?= $c ?>">
                                    <?= htmlspecialchars(substr($initials, 0, 2)) ?>
                                </div>
                                <div style="flex:1;min-width:0">
                                    <div
                                        style="font-size:12.5px;font-weight:600;color:var(--gray-800);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                        <?= htmlspecialchars($sp['full_name']) ?>
                                    </div>
                                    <div style="font-size:10px;color:var(--gray-400)">
                                        <?= htmlspecialchars($sp['employee_id'] ?? '') ?>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:6px;margin-top:3px">
                                        <div class="eff-bar">
                                            <div class="eff-fill" style="width:<?= $eff ?>%;background:<?= $ec ?>"></div>
                                        </div>
                                        <span style="font-size:10px;color:<?= $ec ?>;font-weight:600"><?= $eff ?>%</span>
                                    </div>
                                </div>
                                <div style="text-align:right;flex-shrink:0;margin-left:8px">
                                    <div
                                        style="font-size:13px;font-weight:600;color:var(--gold);font-family:'DM Mono',monospace">
                                        <?= number_format($sp['actual_hours'], 1) ?>h
                                    </div>
                                    <div style="display:flex;gap:4px;margin-top:3px;justify-content:flex-end">
                                        <span class="pill pill-v"><?= $sp['visited'] ?>v</span>
                                        <span class="pill pill-m"><?= $sp['missed'] ?>m</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($staffPerf)): ?>
                            <div style="text-align:center;padding:20px;color:var(--gray-400);font-size:12px">No staff data
                                this month</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right column: Pending + Alerts -->
                <div style="display:flex;flex-direction:column;gap:14px;min-width:0">

                    <div class="panel">
                        <div class="panel-hd">
                            <span class="panel-title"><i class="fas fa-bell" style="color:var(--gold)"></i> Pending
                                Approvals</span>
                            <a href="plans.php?status=submitted" style="font-size:11px;color:var(--blue)">View all</a>
                        </div>
                        <div style="padding:10px 12px">
                            <?php if (empty($pendingPlans)): ?>
                                <div style="text-align:center;padding:18px 0;color:var(--gray-400);font-size:12px">
                                    <i class="fas fa-check-circle"
                                        style="color:var(--green);font-size:1.6rem;display:block;margin-bottom:6px"></i>
                                    All caught up!
                                </div>
                            <?php else: ?>
                                <?php foreach ($pendingPlans as $pp): ?>
                                    <div class="pend-card">
                                        <div style="font-size:12.5px;font-weight:600;color:var(--gray-800)">
                                            <?= htmlspecialchars($pp['full_name']) ?>
                                        </div>
                                        <div style="font-size:10px;color:var(--gray-400);margin-bottom:7px">
                                            Week <?= $pp['week_number'] ?> · <?= $pp['entry_count'] ?> entries ·
                                            <?= number_format($pp['planned_hours'], 1) ?>h
                                        </div>
                                        <a href="plan_view.php?id=<?= $pp['id'] ?>"
                                            style="display:block;text-align:center;background:var(--blue);color:#fff;
                                          padding:5px;border-radius:var(--radius-sm);font-size:11px;font-weight:600;text-decoration:none">
                                            <i class="fas fa-eye me-1"></i> Review
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($noLogStaff) || !empty($unvisited)): ?>
                        <div class="panel">
                            <div class="panel-hd">
                                <span class="panel-title"><i class="fas fa-exclamation-triangle"
                                        style="color:var(--red)"></i> Alerts</span>
                            </div>
                            <div style="padding:10px 12px">
                                <?php if (!empty($noLogStaff)): ?>
                                    <div style="font-size:11px;font-weight:600;color:var(--red);margin-bottom:6px">
                                        <i class="fas fa-user-times me-1"></i>Staff with no logs
                                    </div>
                                    <?php foreach ($noLogStaff as $ns): ?>
                                        <div class="alert-item" style="background:var(--red-lt)">
                                            <i class="fas fa-circle" style="font-size:6px;color:var(--red)"></i>
                                            <span
                                                style="color:var(--red-dk);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($ns['full_name']) ?></span>
                                            <span
                                                style="font-size:10px;color:var(--gray-400)"><?= $ns['employee_id'] ?? '' ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <?php if (!empty($unvisited)): ?>
                                    <div style="font-size:11px;font-weight:600;color:var(--amber);margin:10px 0 6px">
                                        <i class="fas fa-building me-1"></i>Unvisited clients
                                    </div>
                                    <?php foreach ($unvisited as $uv): ?>
                                        <div class="alert-item" style="background:var(--amber-lt)">
                                            <i class="fas fa-circle" style="font-size:6px;color:var(--amber)"></i>
                                            <span
                                                style="color:#633806;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($uv['company_name']) ?></span>
                                            <span
                                                style="font-size:10px;color:var(--gray-400)"><?= $uv['company_code'] ?? '' ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div><!-- /db-grid-main -->

            <!-- Client visit chart -->
            <div class="panel mb-4">
                <div class="panel-hd">
                    <span class="panel-title"><i class="fas fa-building" style="color:var(--gold)"></i> Client Visit
                        Summary — <?= $monthLabel ?></span>
                    <a href="client_report.php?month=<?= $month ?>" style="font-size:11px;color:var(--blue)">
                        Full Report <i class="fas fa-chevron-right" style="font-size:.6rem"></i>
                    </a>
                </div>
                <div class="chart-box"
                    style="height:<?= max(count($clientSummary) * 36 + 80, 140) ?>px;padding:14px 16px 0">
                    <canvas id="clientChart"></canvas>
                </div>
                <div class="legend-row" style="margin-top:4px">
                    <span class="leg-item"><span class="leg-dot" style="background:var(--green)"></span>Visited</span>
                    <span class="leg-item"><span class="leg-dot" style="background:var(--red)"></span>Missed</span>
                    <span class="leg-item"><span class="leg-dot" style="background:var(--blue)"></span>Hours</span>
                </div>
            </div>

        </div><!-- /db-wrap -->
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
    const STAFF_OFFICE_HOURS = <?= $staffOfficeHours ?>;
    const STAFF_PLANNED_V = <?= $staffPlannedVArr ?>;
    const STAFF_RESCHEDULED = <?= $staffRescheduled ?>;
    const TREND_LABELS = <?= $trendLabels ?>;
    const TREND_FIELD_HOURS = <?= $trendFieldHours ?>;
    const TREND_FIELD_VISIT = <?= $trendFieldVisit ?>;
    const TREND_FIELD_MISS = <?= $trendFieldMiss ?>;
    const TREND_OFF_HOURS = <?= $trendOfficeHours ?>;
    const CLIENT_NAMES = <?= $clientNames ?>;
    const CLIENT_VISITED = <?= $clientVisits ?>;
    const CLIENT_MISSED = <?= $clientMissed ?>;
    const CLIENT_HOURS = <?= $clientHours ?>;
    const STAFF_NAMES = <?= $staffNames ?>;
    const STAFF_HOURS = <?= $staffHours ?>;
    const STAFF_PLANNED = <?= $staffPlannedArr ?>;
    const STAFF_VISITED = <?= $staffVisited ?>;
    const STAFF_MISSED = <?= $staffMissed ?>;

    const C = {
        blue: '#378ADD', green: '#1D9E75', red: '#E24B4A',
        amber: '#BA7517', purple: '#7F77DD', gold: '#c9a84c',
        gray: '#e5e7eb', grayDk: '#9ca3af',
        blueLt: 'rgba(55,138,221,.15)', greenLt: 'rgba(29,158,117,.12)',
        redLt: 'rgba(226,75,74,.12)', amberLt: 'rgba(186,117,23,.12)',
    };

    const base = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } };

    /* Visit status donut */
    new Chart(document.getElementById('donutChart'), {
        type: 'doughnut',
        data: {
            labels: ['Visited', 'Missed', 'Rescheduled'],
            datasets: [{
                data: [<?= $visitedCnt ?>, <?= $missedCnt ?>, <?= $rescheduledCnt ?>],
                backgroundColor: [C.green, C.red, C.amber], borderWidth: 3, borderColor: '#fff', hoverOffset: 4
            }]
        },
        options: {
            ...base, cutout: '76%',
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => `${ctx.label}: ${ctx.parsed}` } } }
        }
    });

    /* Office completion donut */
    new Chart(document.getElementById('officeDonut'), {
        type: 'doughnut',
        data: {
            labels: ['Completed', 'WIP', 'Holding', 'Not Started'],
            datasets: [{
                data: [<?= $officeCompleted ?>, <?= $officeWip ?>, <?= $officeHolding ?>, <?= $officeNotStart ?>],
                backgroundColor: [C.green, C.amber, C.purple, '#d1d5db'], borderWidth: 3, borderColor: '#fff', hoverOffset: 4
            }]
        },
        options: { ...base, cutout: '76%' }
    });

    /* Mini trend bar */
    new Chart(document.getElementById('fieldTrendMini'), {
        type: 'bar',
        data: {
            labels: TREND_LABELS,
            datasets: [
                { label: 'Field hours', data: TREND_FIELD_HOURS, backgroundColor: C.blueLt, borderColor: C.blue, borderWidth: 1.5, borderRadius: 4 },
                { label: 'Office hours', data: TREND_OFF_HOURS, backgroundColor: C.greenLt, borderColor: C.green, borderWidth: 1.5, borderRadius: 4 },
            ]
        },
        options: {
            ...base,
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 }, color: C.grayDk } },
                y: { grid: { color: 'rgba(0,0,0,.04)' }, ticks: { font: { size: 10 }, color: C.grayDk, callback: v => v + 'h' }, beginAtZero: true }
            },
            plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } }
        }
    });

    /* Full trend line */
    new Chart(document.getElementById('trendFull'), {
        type: 'line',
        data: {
            labels: TREND_LABELS,
            datasets: [
                { label: 'Field hours', data: TREND_FIELD_HOURS, borderColor: C.blue, backgroundColor: C.blueLt, fill: true, tension: .35, pointRadius: 4, pointBackgroundColor: C.blue, borderWidth: 2 },
                { label: 'Office hours', data: TREND_OFF_HOURS, borderColor: C.green, backgroundColor: C.greenLt, fill: true, tension: .35, pointRadius: 4, pointBackgroundColor: C.green, borderWidth: 2, borderDash: [5, 3], segment: { borderDash: ctx => [5, 3] } },
                { label: 'Missed', data: TREND_FIELD_MISS, borderColor: C.red, backgroundColor: C.redLt, fill: false, tension: .35, pointRadius: 4, pointBackgroundColor: C.red, borderWidth: 1.5, borderDash: [3, 3] },
            ]
        },
        options: {
            ...base,
            scales: {
                x: { grid: { color: 'rgba(0,0,0,.03)' }, ticks: { font: { size: 11 }, color: C.grayDk } },
                y: { grid: { color: 'rgba(0,0,0,.04)' }, ticks: { font: { size: 11 }, color: C.grayDk, callback: v => v + 'h' }, beginAtZero: true }
            },
            plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } }
        }
    });

    /* ── Staff Hours chart (Field + Office stacked vs Planned) ── */
    new Chart(document.getElementById('staffChart'), {
        type: 'bar',
        data: {
            labels: STAFF_NAMES,
            datasets: [
                {
                    label: 'Planned',
                    data: STAFF_PLANNED,
                    backgroundColor: C.gray,
                    borderRadius: 4,
                    borderSkipped: false,
                    stack: 'planned',
                    order: 2,
                },
                {
                    label: 'Field Hours',
                    data: STAFF_HOURS,
                    backgroundColor: C.blue,
                    borderRadius: 4,
                    borderSkipped: false,
                    stack: 'actual',
                    order: 1,
                },
                {
                    label: 'Office Hours',
                    data: STAFF_OFFICE_HOURS,
                    backgroundColor: C.green,
                    borderRadius: 4,
                    borderSkipped: false,
                    stack: 'actual',
                    order: 1,
                },
            ]
        },
        options: {
            ...base,
            indexAxis: 'y',
            scales: {
                x: {
                    stacked: true,
                    grid: { color: 'rgba(0,0,0,.04)' },
                    ticks: { font: { size: 10 }, color: C.grayDk, callback: v => v + 'h' },
                    beginAtZero: true,
                },
                y: {
                    stacked: true,
                    grid: { display: false },
                    ticks: { font: { size: 11 }, color: '#374151' }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index', intersect: false,
                    callbacks: {
                        afterBody: items => {
                            const i = items[0].dataIndex;
                            const total = (STAFF_HOURS[i] || 0) + (STAFF_OFFICE_HOURS[i] || 0);
                            const pl = STAFF_PLANNED[i] || 0;
                            const eff = pl > 0 ? Math.round(total / pl * 100) : 0;
                            return `Total: ${total.toFixed(1)}h | Eff: ${Math.min(eff, 100)}%`;
                        }
                    }
                }
            }
        }
    });

    /* ── Staff Visit chart (Planned vs Visited vs Missed vs Rescheduled) ── */
    new Chart(document.getElementById('staffVisitChart'), {
        type: 'bar',
        data: {
            labels: STAFF_NAMES,
            datasets: [
                {
                    label: 'Planned Visits',
                    data: STAFF_PLANNED_V,
                    backgroundColor: C.gray,
                    borderRadius: 4,
                    borderSkipped: false,
                    stack: 'planned',
                    order: 2,
                },
                {
                    label: 'Visited',
                    data: STAFF_VISITED,
                    backgroundColor: C.green,
                    borderRadius: 4,
                    borderSkipped: false,
                    stack: 'visits',
                    order: 1,
                },
                {
                    label: 'Missed',
                    data: STAFF_MISSED,
                    backgroundColor: C.red,
                    borderRadius: 4,
                    borderSkipped: false,
                    stack: 'visits',
                    order: 1,
                },
                {
                    label: 'Rescheduled',
                    data: STAFF_RESCHEDULED,
                    backgroundColor: C.amber,
                    borderRadius: 4,
                    borderSkipped: false,
                    stack: 'visits',
                    order: 1,
                },
            ]
        },
        options: {
            ...base,
            indexAxis: 'y',
            scales: {
                x: {
                    stacked: true,
                    grid: { color: 'rgba(0,0,0,.04)' },
                    ticks: { font: { size: 10 }, color: C.grayDk, stepSize: 1 },
                    beginAtZero: true,
                },
                y: {
                    grid: { display: false },
                    ticks: { font: { size: 11 }, color: '#374151' }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index', intersect: false,
                    callbacks: {
                        afterBody: items => {
                            const i = items[0].dataIndex;
                            const pl = STAFF_PLANNED_V[i] || 0;
                            const vis = STAFF_VISITED[i] || 0;
                            const eff = pl > 0 ? Math.round(Math.min(vis / pl * 100, 100)) : 0;
                            return `Visit eff: ${eff}% (${vis}/${pl} planned)`;
                        }
                    }
                }
            }
        }
    });

    /* Client stacked bar */
    new Chart(document.getElementById('clientChart'), {
        type: 'bar',
        data: {
            labels: CLIENT_NAMES,
            datasets: [
                { label: 'Visited', data: CLIENT_VISITED, backgroundColor: C.green, stack: 's', borderRadius: 4 },
                { label: 'Missed', data: CLIENT_MISSED, backgroundColor: C.red, stack: 's', borderRadius: 4 },
            ]
        },
        options: {
            ...base, indexAxis: 'y',
            scales: {
                x: { stacked: true, grid: { color: 'rgba(0,0,0,.04)' }, ticks: { font: { size: 10 }, color: C.grayDk, stepSize: 1 }, beginAtZero: true },
                y: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 }, color: '#374151' } }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index', intersect: false,
                    callbacks: { afterBody: items => { const i = items[0].dataIndex; return `Hours: ${CLIENT_HOURS[i]}h`; } }
                }
            }
        }
    });

    /* Force resize after page fully loads */
    window.addEventListener('load', () => {
        Object.values(Chart.instances).forEach(c => c.resize());
    });
</script>

<?php include '../../includes/footer.php'; ?>