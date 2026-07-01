<?php
/**
 * consulting/log_list.php — Admin: All Visit & Office Logs
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAdmin();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

// ── Department resolution ──────────────────────────────────────────────────
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
} elseif ($__udaCons) {
    $deptId = (int) $__udaCons['id'];
}

// ── Filters ────────────────────────────────────────────────────────────────
$now = new DateTime();
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : $now->format('Y-m');
$monthDate = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: $now;
$monthLabel = $monthDate->format('F Y');

$staffFilter = (int) ($_GET['staff_id'] ?? 0);
$statusFilter = in_array($_GET['visit_status'] ?? '', ['visited', 'missed', 'rescheduled', 'not_started', 'wip', 'holding', 'completed', ''])
    ? ($_GET['visit_status'] ?? '') : '';
$clientFilter = (int) ($_GET['client_id'] ?? 0);
$dateFromFilter = $_GET['date_from'] ?? '';
$dateToFilter = $_GET['date_to'] ?? '';
$typeFilter = in_array($_GET['log_type'] ?? '', ['visit', 'office', '']) ? ($_GET['log_type'] ?? '') : '';

// ── Scope ─────────────────────────────────────────────────────────────────
$currentRole = $_SESSION['role'] ?? ($user['role'] ?? '');
$isAdmin = in_array($currentRole, ['admin', 'executive', 'superadmin']);

if ($isAdmin) {
    $scopeRows = $db->query("
        SELECT DISTINCT u.id FROM users u
        WHERE u.is_active = 1
          AND (
              u.id = {$uid}
              OR u.id IN (
                  SELECT u2.id FROM users u2
                  JOIN departments d ON d.id = u2.department_id AND d.dept_code = 'CON'
                  WHERE u2.is_active = 1
                  UNION
                  SELECT uda.user_id FROM user_department_assignments uda
                  JOIN departments d ON d.id = uda.department_id AND d.dept_code = 'CON'
              )
          )
    ")->fetchAll(PDO::FETCH_COLUMN);
    $scopeIds = array_unique(array_merge([$uid], $scopeRows));
} else {
    $scopeIds = [$uid];
}
$inList = implode(',', array_map('intval', $scopeIds)) ?: '0';

// ── WHERE clauses ─────────────────────────────────────────────────────────
// ── WHERE clauses ─────────────────────────────────────────────────────────
$whereVisit = "wl.month_year = ? AND wl.user_id IN ({$inList}) AND wl.user_id != ?";
$whereOffice = "DATE_FORMAT(owl.log_date,'%Y-%m') = ? AND owl.user_id IN ({$inList}) AND owl.user_id != ?";
$whereKpi = "wl.month_year = ? AND wl.department_id = ? AND wl.user_id IN ({$inList}) AND wl.user_id != ?";
$paramsVisit = [$month, $uid];
$paramsOffice = [$month, $uid];
$paramsKpi = [$month, $deptId, $uid];

if ($staffFilter) {
    $whereVisit .= " AND wl.user_id = ?";
    $whereOffice .= " AND owl.user_id = ?";
    $whereKpi .= " AND wl.user_id = ?";
    $paramsVisit[] = $staffFilter;
    $paramsOffice[] = $staffFilter;
    $paramsKpi[] = $staffFilter;
}

if ($statusFilter !== '') {
    if (in_array($statusFilter, ['visited', 'missed', 'rescheduled'])) {
        $whereVisit .= " AND wl.visit_status = ?";
        $whereOffice .= " AND 1=0";
        $whereKpi .= " AND wl.visit_status = ?";
        $paramsVisit[] = $statusFilter;
        $paramsKpi[] = $statusFilter;
    } elseif (in_array($statusFilter, ['not_started', 'wip', 'holding', 'completed'])) {
        $whereOffice .= " AND owl.status = ?";
        $whereVisit .= " AND 1=0";
        $paramsOffice[] = $statusFilter;
    }
}

if ($clientFilter) {
    $whereVisit .= " AND wl.client_id = ?";
    $whereOffice .= " AND owl.client_id = ?";
    $paramsVisit[] = $clientFilter;
    $paramsOffice[] = $clientFilter;
}
if ($dateFromFilter) {
    $whereVisit .= " AND wl.log_date >= ?";
    $whereOffice .= " AND owl.log_date >= ?";
    $paramsVisit[] = $dateFromFilter;
    $paramsOffice[] = $dateFromFilter;
}
if ($dateToFilter) {
    $whereVisit .= " AND wl.log_date <= ?";
    $whereOffice .= " AND owl.log_date <= ?";
    $paramsVisit[] = $dateToFilter;
    $paramsOffice[] = $dateToFilter;
}
if ($typeFilter === 'visit') {
    $whereOffice .= " AND 1=0";
} elseif ($typeFilter === 'office') {
    $whereVisit .= " AND 1=0";
}

// ── Main query ─────────────────────────────────────────────────────────────
$stmt = $db->prepare("
(
    SELECT wl.id, wl.client_id, wl.user_id, wl.log_date, wl.time_in, wl.time_out,
       wl.duration_hours,
       wl.visit_status AS status,
       wl.work_description AS description,
       c.company_name, c.company_code,
       u.full_name AS staff_name, u.employee_id,
       su.full_name AS supervisor_name,
       d.dept_name AS department_name,
       'VISIT' AS log_type, wl.day_of_week
    FROM work_logs wl
    LEFT JOIN companies c ON c.id = wl.client_id
    LEFT JOIN users u ON u.id = wl.user_id
    LEFT JOIN users su ON su.id = wl.supervisor_id
    LEFT JOIN user_department_assignments uda 
        ON uda.user_id = u.id
    LEFT JOIN departments d 
        ON d.id = uda.department_id
    WHERE {$whereVisit}
        AND (
                u.id = ?
                OR wl.supervisor_id = ?
                OR u.managed_by = ?
                OR uda.managed_by = ?
            )
)
UNION ALL
(
    SELECT owl.id, owl.client_id, owl.user_id, owl.log_date, owl.time_in, owl.time_out,
       ROUND(TIME_TO_SEC(TIMEDIFF(owl.time_out, owl.time_in)) / 3600, 2),
       owl.status, owl.description,
       c.company_name, c.company_code,
       u.full_name AS staff_name, u.employee_id,
       sv.full_name AS supervisor_name,
       d.dept_name AS department_name,
       'OFFICE' AS log_type, NULL AS day_of_week
    FROM office_work_logs owl
    LEFT JOIN companies c ON c.id = owl.client_id
    LEFT JOIN users u ON u.id = owl.user_id
    LEFT JOIN users sv ON sv.id = owl.supervisor_id
    LEFT JOIN user_department_assignments uda 
        ON uda.user_id = u.id
    LEFT JOIN departments d 
        ON d.id = uda.department_id
    WHERE {$whereOffice}
      AND (
            u.id = ?
            OR owl.supervisor_id = ?
            OR u.managed_by = ?
            OR uda.managed_by = ?
          )
)
ORDER BY log_date DESC, time_in DESC
");
$stmt->execute(array_merge(
    $paramsVisit,
    [$uid, $uid, $uid, $uid],
    $paramsOffice,
    [$uid, $uid, $uid, $uid]
));
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Derived data ───────────────────────────────────────────────────────────
// ── Client list (ALL companies) ───────────────────────────────────────────
$clientStmt = $db->query("
    SELECT id, company_name, company_code, pan_number
    FROM companies
    ORDER BY company_name ASC
");
$clientList = $clientStmt->fetchAll(PDO::FETCH_ASSOC);
$clientNames = array_column($clientList, 'company_name', 'id'); // kept for count()

$st1 = $db->prepare("
    SELECT DISTINCT u.id, u.full_name, u.employee_id
    FROM users u
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    WHERE u.is_active = 1
      AND (
            u.id = ?
            OR u.managed_by = ?
            OR uda.managed_by = ?
          )
      AND (
            u.department_id = ?
            OR uda.department_id = ?
          )
    ORDER BY u.full_name
");

$st1->execute([
    $uid,
    $uid,
    $uid,
    $deptId,
    $deptId
]);

$deptStaff = $st1->fetchAll(PDO::FETCH_ASSOC);


$stmtKpi = $db->prepare("
    SELECT COUNT(*) AS total_visit_logs,
           COALESCE(SUM(duration_hours),0) AS total_hours,
           COUNT(DISTINCT client_id)       AS unique_clients,
           SUM(visit_status='visited')     AS visited,
           SUM(visit_status='missed')      AS missed,
           SUM(visit_status='rescheduled') AS rescheduled
    FROM work_logs wl WHERE {$whereKpi}
");
$stmtKpi->execute($paramsKpi);
$kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

$totalLogs = count($logs);
$visitCount = array_sum(array_map(fn($l) => $l['log_type'] === 'VISIT' ? 1 : 0, $logs));
$totalHours = array_sum(array_column($logs, 'duration_hours'));
$hasFilters = $clientFilter || $dateFromFilter || $dateToFilter || $staffFilter || $statusFilter || $typeFilter;

$pageTitle = 'All Visit Logs';
include '../../includes/header.php';

function vstBadge(string $s): string
{
    $map = [
        'visited' => ['#ecfdf5', '#059669', 'fa-check-circle', 'Visited'],
        'missed' => ['#fef2f2', '#dc2626', 'fa-times-circle', 'Missed'],
        'rescheduled' => ['#fffbeb', '#d97706', 'fa-redo-alt', 'Rescheduled'],
        'not_started' => ['#ffedd5', '#d97706', 'fa-hourglass', 'Not Started'],
        'wip' => ['#eff6ff', '#2563eb', 'fa-circle-notch', 'WIP'],
        'holding' => ['#ffedd5', '#d97706', 'fa-pause-circle', 'Holding'],
        'completed' => ['#f0fdf4', '#16a34a', 'fa-check-double', 'Completed'],
    ];
    [$bg, $col, $ico, $lbl] = $map[$s] ?? ['#f3f4f6', '#6b7280', 'fa-circle', ucfirst($s ?: '—')];
    return "<span class='vst-badge' style='background:{$bg};color:{$col};'><i class='fas {$ico}'></i>{$lbl}</span>";
}

function qstr(array $overrides, array $base): string
{
    $p = array_merge($base, $overrides);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== 0 && $v !== null));
}

$baseQ = [
    'month' => $month,
    'log_type' => $typeFilter,
    'staff_id' => $staffFilter ?: '',
    'visit_status' => $statusFilter,
    'client_id' => $clientFilter ?: '',
    'date_from' => $dateFromFilter,
    'date_to' => $dateToFilter,
];
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800&display=swap"
    rel="stylesheet">

<style>
    /* ── Shared design tokens ─────────────────────────────────────────────── */
    :root {
        --gold: #c9a84c;
        --gold-lt: #fefce8;
        --ink: #0f172a;
        --muted: #64748b;
        --border: #e2e8f0;
        --surface: #ffffff;
        --hover: #f8fafc;
        --radius: 12px;
        --shadow: 0 1px 4px rgba(15, 23, 42, .06), 0 4px 16px rgba(15, 23, 42, .04);
    }

    body {
        font-family: 'DM Sans', sans-serif;
    }

    /* ── Hero ────────────────────────────────────────────────────────────── */
    .ll-hero {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 55%, #0f3460 100%);
        border-radius: 16px;
        padding: 1.6rem 2rem;
        color: #fff;
        margin-bottom: 1.25rem;
        position: relative;
        overflow: hidden;
    }

    .ll-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/svg%3E");
        pointer-events: none;
    }

    .ll-hero-inner {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .ll-hero-badge {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        background: rgba(201, 168, 76, .18);
        border: 1px solid rgba(201, 168, 76, .35);
        color: #fbbf24;
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .07em;
        text-transform: uppercase;
        padding: .22rem .65rem;
        border-radius: 99px;
        margin-bottom: .55rem;
    }

    .ll-hero h4 {
        font-size: 1.4rem;
        font-weight: 800;
        margin: 0 0 .25rem;
        color: #fff;
        letter-spacing: -.02em;
    }

    .ll-hero-meta {
        font-size: .8rem;
        color: #94a3b8;
        margin: 0;
        display: flex;
        align-items: center;
        gap: .5rem;
        flex-wrap: wrap;
    }

    .ll-hero-meta i {
        color: #fbbf24;
    }

    .ll-hero-meta .dot {
        color: #475569;
    }

    .hero-actions {
        display: flex;
        gap: .5rem;
        flex-wrap: wrap;
        align-items: flex-start;
    }

    .hero-btn {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .38rem .85rem;
        font-size: .74rem;
        font-weight: 600;
        border-radius: 9px;
        border: 1.5px solid rgba(255, 255, 255, .15);
        background: rgba(255, 255, 255, .07);
        color: #e2e8f0;
        text-decoration: none;
        transition: background .15s, border-color .15s;
        white-space: nowrap;
    }

    .hero-btn:hover {
        background: rgba(255, 255, 255, .14);
        border-color: rgba(255, 255, 255, .28);
        color: #fff;
    }

    /* ── KPI grid ────────────────────────────────────────────────────────── */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(148px, 1fr));
        gap: .7rem;
        margin-bottom: 1.1rem;
    }

    .kpi-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: .95rem 1.05rem;
        display: flex;
        align-items: center;
        gap: .8rem;
        box-shadow: var(--shadow);
        transition: transform .15s, box-shadow .15s;
        cursor: default;
    }

    .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 20px rgba(15, 23, 42, .09);
    }

    .kpi-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: .9rem;
    }

    .kpi-val {
        font-size: 1.35rem;
        font-weight: 800;
        color: var(--ink);
        line-height: 1;
    }

    .kpi-lbl {
        font-size: .65rem;
        color: var(--muted);
        margin-top: .18rem;
        text-transform: uppercase;
        letter-spacing: .05em;
        font-weight: 600;
    }

    /* ── Filter panel ────────────────────────────────────────────────────── */
    .filter-panel {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: .85rem 1.1rem;
        margin-bottom: 1.1rem;
        box-shadow: var(--shadow);
    }

    .filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: .45rem;
        align-items: center;
    }

    .filter-section-label {
        font-size: .68rem;
        font-weight: 700;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .06em;
        white-space: nowrap;
    }

    .filter-divider {
        width: 1px;
        height: 20px;
        background: var(--border);
        flex-shrink: 0;
        margin: 0 .1rem;
    }

    .type-toggle {
        display: flex;
        background: #f1f5f9;
        border-radius: 9px;
        padding: 3px;
        gap: 3px;
    }

    .type-toggle a {
        padding: .28rem .7rem;
        font-size: .72rem;
        font-weight: 700;
        border-radius: 7px;
        text-decoration: none;
        color: var(--muted);
        transition: all .15s;
        white-space: nowrap;
    }

    .type-toggle a.active {
        background: var(--surface);
        color: var(--ink);
        box-shadow: 0 1px 4px rgba(0, 0, 0, .1);
    }

    .status-pill {
        font-size: .7rem;
        font-weight: 700;
        padding: .2rem .6rem;
        border-radius: 99px;
        text-decoration: none;
        border: 1.5px solid var(--border);
        background: var(--surface);
        color: var(--muted);
        transition: all .15s;
        white-space: nowrap;
    }

    .status-pill:hover {
        opacity: .85;
        text-decoration: none;
    }

    .ff-input {
        font-size: .77rem;
        padding: .28rem .65rem;
        border: 1.5px solid var(--border);
        border-radius: 8px;
        background: var(--surface);
        color: var(--ink);
        height: 33px;
        outline: none;
        transition: border-color .15s;
        font-family: inherit;
    }

    .ff-input:focus {
        border-color: var(--gold);
    }

    .ff-input.is-active {
        border-color: var(--gold);
        background: var(--gold-lt);
    }

    .filter-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 17px;
        height: 17px;
        background: var(--gold);
        color: #fff;
        border-radius: 99px;
        font-size: .6rem;
        font-weight: 800;
    }

    .clear-btn {
        font-size: .71rem;
        font-weight: 700;
        color: #dc2626;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        padding: .22rem .5rem;
        border-radius: 6px;
        transition: background .15s;
    }

    .clear-btn:hover {
        background: #fef2f2;
        text-decoration: none;
    }

    /* ── Table wrap ──────────────────────────────────────────────────────── */
    .log-table-wrap {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .log-table-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .9rem 1.2rem;
        border-bottom: 1px solid var(--border);
        gap: .75rem;
        flex-wrap: wrap;
    }

    .log-table-head h5 {
        font-size: .92rem;
        font-weight: 700;
        color: var(--ink);
        margin: 0;
    }

    table.ll-tbl {
        width: 100%;
        border-collapse: collapse;
    }

    table.ll-tbl thead tr {
        background: #f8fafc;
        border-bottom: 1px solid var(--border);
    }

    table.ll-tbl th {
        padding: .6rem .85rem;
        font-size: .66rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--muted);
        white-space: nowrap;
    }

    table.ll-tbl td {
        padding: .65rem .85rem;
        font-size: .79rem;
        color: var(--ink);
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    table.ll-tbl tbody tr:last-child td {
        border-bottom: none;
    }

    table.ll-tbl tbody tr:hover td {
        background: var(--hover);
    }

    table.ll-tbl tfoot tr td {
        background: #f8fafc;
        border-top: 2px solid var(--border);
        font-weight: 700;
    }

    .type-badge {
        font-size: .66rem;
        font-weight: 700;
        padding: .16rem .5rem;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        gap: .28rem;
    }

    .type-visit {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .type-office {
        background: #fef3c7;
        color: #92400e;
    }

    .vst-badge {
        font-size: .67rem;
        font-weight: 700;
        padding: .16rem .52rem;
        border-radius: 99px;
        display: inline-flex;
        align-items: center;
        gap: .28rem;
        white-space: nowrap;
    }

    .vst-badge i {
        font-size: .58rem;
    }

    .hrs-good {
        color: #16a34a;
        font-weight: 700;
    }

    .hrs-warn {
        color: #d97706;
        font-weight: 700;
    }

    .hrs-low {
        color: #dc2626;
        font-weight: 700;
    }

    .act-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 27px;
        height: 27px;
        border-radius: 7px;
        border: 1px solid;
        text-decoration: none;
        font-size: .73rem;
        transition: opacity .15s, transform .1s;
    }

    .act-btn:hover {
        opacity: .78;
        transform: scale(1.06);
    }

    .empty-state {
        text-align: center;
        padding: 3.5rem 1rem;
        color: var(--muted);
    }

    .empty-state i {
        font-size: 2.2rem;
        opacity: .2;
        display: block;
        margin-bottom: .7rem;
    }

    .empty-state h6 {
        font-size: .92rem;
        font-weight: 600;
        color: var(--ink);
        margin-bottom: .35rem;
    }

    .empty-state p {
        font-size: .78rem;
        margin: 0;
    }

    .filtered-tag {
        font-size: .7rem;
        font-weight: 700;
        color: var(--gold);
        background: var(--gold-lt);
        padding: .18rem .55rem;
        border-radius: 6px;
    }

    .ts-dropdown .option.active,
    .ts-dropdown .option:hover {
        background: var(--gold) !important;
        color: #fff !important;
    }

    .ts-wrapper.focus .ts-control {
        border-color: var(--gold) !important;
        box-shadow: none !important;
    }

    .ts-wrapper .ts-control {
        border: 1.5px solid var(--border);
        border-radius: 8px;
        font-size: .77rem;
        padding: 3px 8px;
        height: 33px;
        font-family: inherit;
        background: var(--surface);
    }

    /* ── DataTables pagination fix ───────────────────────────────────────────── */
    #logsTable_wrapper .dataTables_paginate .paginate_button {
        background: var(--surface) !important;
        border: 1.5px solid var(--border) !important;
        color: var(--ink) !important;
        border-radius: 7px !important;
        padding: .25rem .6rem !important;
        margin: 0 2px !important;
        font-size: .78rem !important;
        font-weight: 600 !important;
        box-shadow: none !important;
    }

    #logsTable_wrapper .dataTables_paginate .paginate_button:hover {
        background: var(--gold-lt) !important;
        border-color: var(--gold) !important;
        color: var(--gold) !important;
    }

    #logsTable_wrapper .dataTables_paginate .paginate_button.current,
    #logsTable_wrapper .dataTables_paginate .paginate_button.current:hover {
        background: var(--gold) !important;
        border-color: var(--gold) !important;
        color: #fff !important;
    }

    #logsTable_wrapper .dataTables_paginate .paginate_button.disabled,
    #logsTable_wrapper .dataTables_paginate .paginate_button.disabled:hover {
        background: #f8fafc !important;
        border-color: var(--border) !important;
        color: #cbd5e1 !important;
        cursor: not-allowed !important;
    }

    @media (max-width: 768px) {
        .ll-hero {
            padding: 1.1rem 1.2rem;
        }

        .kpi-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        table.ll-tbl th:nth-child(6),
        table.ll-tbl td:nth-child(6),
        table.ll-tbl th:nth-child(7),
        table.ll-tbl td:nth-child(7),
        table.ll-tbl th:nth-child(10),
        table.ll-tbl td:nth-child(10) {
            display: none;
        }
    }
</style>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 1rem 2rem;">

            <!-- ── Hero ─────────────────────────────────────────────────── -->
            <div class="ll-hero mb-4">
                <div class="ll-hero-inner">
                    <div>
                        <div class="ll-hero-badge">
                            <i class="fas fa-history"></i> Consulting &nbsp;·&nbsp; Logs
                        </div>
                        <h4>All Visit &amp; Office Logs</h4>
                        <p class="ll-hero-meta">
                            <i class="fas fa-user-circle"></i>
                            <?= htmlspecialchars($user['full_name']) ?>
                            <span class="dot">·</span>
                            <i class="fas fa-calendar-alt"></i>
                            <?= $monthLabel ?>
                            <span class="dot">·</span>
                            <i class="fas fa-list-ul"></i>
                            <?= $totalLogs ?> record<?= $totalLogs !== 1 ? 's' : '' ?>
                        </p>
                    </div>
                    <div class="hero-actions">
                        <a href="office_log_list.php?month=<?= $month ?>" class="hero-btn">
                            <i class="fas fa-building"></i> Office Logs
                        </a>
                        <a href="index.php?month=<?= $month ?>" class="hero-btn">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=consulting_performance&view=who&month=<?= urlencode($month) ?>&staff_id=<?= $staffFilter ?>&visit_status=<?= urlencode($statusFilter) ?>"
                            class="hero-btn">
                            <i class="fas fa-file-pdf" style="color:#f87171;"></i> PDF
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=consulting_performance&view=who&month=<?= urlencode($month) ?>&staff_id=<?= $staffFilter ?>&visit_status=<?= urlencode($statusFilter) ?>"
                            class="hero-btn">
                            <i class="fas fa-file-excel" style="color:#4ade80;"></i> Excel
                        </a>
                    </div>
                </div>
            </div>

            <!-- ── KPI Cards ─────────────────────────────────────────────── -->
            <div class="kpi-grid">
                <?php
                $kpiDefs = [
                    ['fa-layer-group', '#2563eb', '#dbeafe', 'Total Logs', $totalLogs],
                    ['fa-car', '#0891b2', '#cffafe', 'Visit Logs', $visitCount],
                    ['fa-building', '#92400e', '#fef3c7', 'Office Logs', $totalLogs - $visitCount],
                    ['fa-clock', '#b45309', '#fefce8', 'Total Hours', number_format($totalHours, 1) . 'h'],
                    ['fa-briefcase', '#7c3aed', '#ede9fe', 'Clients', count($clientNames)],
                    ['fa-check-circle', '#059669', '#ecfdf5', 'Visited', (int) ($kpi['visited'] ?? 0)],
                    ['fa-times-circle', '#dc2626', '#fef2f2', 'Missed', (int) ($kpi['missed'] ?? 0)],
                    ['fa-redo-alt', '#d97706', '#fffbeb', 'Rescheduled', (int) ($kpi['rescheduled'] ?? 0)],
                    ['fa-hourglass', '#d97706', '#ffedd5', 'Not Started', (int) ($kpi['not_started_count'] ?? 0)],
                    ['fa-pause-circle', '#d97706', '#ffedd5', 'Holding', (int) ($kpi['holding_count'] ?? 0)],
                ];
                foreach ($kpiDefs as [$ico, $col, $bg, $lbl, $val]): ?>
                    <div class="kpi-card">
                        <div class="kpi-icon" style="background:<?= $bg ?>;color:<?= $col ?>;"><i
                                class="fas <?= $ico ?>"></i></div>
                        <div>
                            <div class="kpi-val"><?= $val ?></div>
                            <div class="kpi-lbl"><?= $lbl ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ── Filter Panel ───────────────────────────────────────────── -->
            <?php
            $activeFilterCount = (int) ($staffFilter > 0) + (int) ($statusFilter !== '') + (int) ($clientFilter > 0)
                + (int) ($dateFromFilter !== '') + (int) ($dateToFilter !== '') + (int) ($typeFilter !== '');
            ?>
            <div class="filter-panel">
                <div class="filter-row">
                    <span class="filter-section-label">
                        <i class="fas fa-filter me-1"></i>Filter
                        <?php if ($activeFilterCount): ?>
                            <span class="filter-count ms-1"><?= $activeFilterCount ?></span>
                        <?php endif; ?>
                    </span>

                    <input type="month" id="fMonth" class="ff-input" style="width:148px;" value="<?= $month ?>"
                        onchange="applyFilter()">

                    <div class="filter-divider"></div>

                    <!-- Type toggle -->
                    <div class="type-toggle">
                        <a href="<?= qstr(['log_type' => ''], $baseQ) ?>"
                            class="<?= $typeFilter === '' ? 'active' : '' ?>"><i class="fas fa-th-list me-1"></i>All</a>
                        <a href="<?= qstr(['log_type' => 'visit'], $baseQ) ?>"
                            class="<?= $typeFilter === 'visit' ? 'active' : '' ?>"><i
                                class="fas fa-car me-1"></i>Visit</a>
                        <a href="<?= qstr(['log_type' => 'office'], $baseQ) ?>"
                            class="<?= $typeFilter === 'office' ? 'active' : '' ?>"><i
                                class="fas fa-building me-1"></i>Office</a>
                    </div>

                    <div class="filter-divider"></div>

                    <!-- Staff -->
                    <select id="fStaff" class="ff-input <?= $staffFilter ? 'is-active' : '' ?>" style="width:200px;">
                        <option value="">All Staff</option>
                        <?php foreach ($deptStaff as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $staffFilter == $s['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['full_name']) ?>
                                <?= $s['employee_id'] ? ' — ' . htmlspecialchars($s['employee_id']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="filter-divider"></div>

                    <!-- Status pills -->
                    <span class="filter-section-label">Status:</span>
                    <?php
                    $statusPills = [
                        '' => ['All', '#c9a84c', '#fffbeb', '#e2e8f0'],
                        'visited' => ['✓ Visited', '#059669', '#ecfdf5', '#059669'],
                        'missed' => ['✗ Missed', '#dc2626', '#fef2f2', '#dc2626'],
                        'rescheduled' => ['↺ Rescheduled', '#d97706', '#fffbeb', '#d97706'],
                        'not_started' => ['◷ Not Started', '#d97706', '#ffedd5', '#d97706'],
                        'wip' => ['◷ WIP', '#2563eb', '#eff6ff', '#2563eb'],
                        'holding' => ['⏸ Holding', '#d97706', '#ffedd5', '#d97706'],
                        'completed' => ['✓✓ Completed', '#16a34a', '#f0fdf4', '#16a34a'],
                    ];
                    foreach ($statusPills as $sKey => [$sLabel, $sColor, $sBg, $sBorder]):
                        $isAct = ($statusFilter === $sKey);
                        ?>
                        <a href="<?= qstr(['visit_status' => $sKey], $baseQ) ?>" class="status-pill"
                            style="<?= $isAct ? "border-color:{$sBorder};background:{$sBg};color:{$sColor};" : '' ?>">
                            <?= $sLabel ?>
                        </a>
                    <?php endforeach; ?>

                    <div class="filter-divider"></div>

                    <!-- Client -->
                    <?php if (!empty($clientNames)): ?>
                        <?php if (!empty($clientList)): ?>
                            <select id="fClient" class="ff-input <?= $clientFilter ? 'is-active' : '' ?>" style="width:220px;">
                                <option value="">All Clients</option>
                                <?php foreach ($clientList as $cl): ?>
                                    <option value="<?= $cl['id'] ?>" <?= $clientFilter == $cl['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cl['company_name']) ?>
                                        <?= $cl['company_code'] ? ' — ' . htmlspecialchars($cl['company_code']) : '' ?>
                                        <?= $cl['pan_number'] ? ' | ' . htmlspecialchars($cl['pan_number']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Date range -->
                    <input type="date" id="fDateFrom" class="ff-input <?= $dateFromFilter ? 'is-active' : '' ?>"
                        value="<?= htmlspecialchars($dateFromFilter) ?>" onchange="applyFilter()" title="From"
                        style="width:138px;">
                    <span style="font-size:.75rem;color:var(--muted);">→</span>
                    <input type="date" id="fDateTo" class="ff-input <?= $dateToFilter ? 'is-active' : '' ?>"
                        value="<?= htmlspecialchars($dateToFilter) ?>" onchange="applyFilter()" title="To"
                        style="width:138px;">

                    <?php if ($hasFilters): ?>
                        <a href="?month=<?= $month ?>" class="clear-btn">
                            <i class="fas fa-times-circle"></i> Clear
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Table ─────────────────────────────────────────────────── -->
            <div class="log-table-wrap">
                <div class="log-table-head">
                    <h5><i class="fas fa-table me-2" style="color:var(--gold);"></i>Logs — <?= $monthLabel ?></h5>
                    <div style="display:flex;align-items:center;gap:.65rem;">
                        <?php if ($hasFilters): ?><span class="filtered-tag"><i
                                    class="fas fa-filter me-1"></i>Filtered</span><?php endif; ?>
                        <span style="font-size:.74rem;color:var(--muted);"><?= $totalLogs ?> records ·
                            <?= number_format($totalHours, 1) ?>h</span>
                    </div>
                </div>

                <?php if (empty($logs)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h6>No logs found</h6>
                        <p>Try adjusting your filters or selecting a different month.</p>
                        <?php if ($hasFilters): ?>
                            <a href="?month=<?= $month ?>" class="clear-btn" style="justify-content:center;margin-top:.7rem;">
                                <i class="fas fa-times-circle"></i> Clear all filters
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="ll-tbl" id="logsTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Staff</th>
                                    <th>Supervisor</th>
                                    <th>Client</th>
                                    <th class="text-center">Time In</th>
                                    <th class="text-center">Time Out</th>
                                    <th class="text-center">Hours</th>
                                    <th>Status</th>
                                    <th>Description</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $i => $l):
                                    $hrs = (float) $l['duration_hours'];
                                    $descFull = $l['description'] ?? '';
                                    $descShort = mb_strimwidth($descFull, 0, 55, '…');
                                    ?>
                                    <tr>
                                        <td style="color:var(--muted);font-size:.68rem;"><?= $i + 1 ?></td>
                                        <td>
                                            <div style="font-weight:600;white-space:nowrap;">
                                                <?= date('d M Y', strtotime($l['log_date'])) ?>
                                            </div>
                                            <?php if ($l['day_of_week']): ?>
                                                <div style="font-size:.65rem;color:var(--muted);"><?= $l['day_of_week'] ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($l['log_type'] === 'OFFICE'): ?>
                                                <span class="type-badge type-office"><i class="fas fa-building"></i>Office</span>
                                            <?php else: ?>
                                                <span class="type-badge type-visit"><i class="fas fa-car"></i>Visit</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;"><?= htmlspecialchars($l['staff_name'] ?? '—') ?></div>
                                            <div style="font-size:.65rem;color:var(--muted);">
                                                <?= htmlspecialchars($l['employee_id'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;">
                                                <?= htmlspecialchars($l['supervisor_name'] ?? '—') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:500;">
                                                <?= htmlspecialchars(mb_strimwidth($l['company_name'] ?? '—', 0, 24, '…')) ?>
                                            </div>
                                            <div style="font-size:.65rem;color:var(--muted);">
                                                <?= htmlspecialchars($l['company_code'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td class="text-center" style="color:var(--muted);">
                                            <?= $l['time_in'] ? date('g:i A', strtotime($l['time_in'])) : '—' ?>
                                        </td>
                                        <td class="text-center" style="color:var(--muted);">
                                            <?= $l['time_out'] ? date('g:i A', strtotime($l['time_out'])) : '—' ?>
                                        </td>
                                        <td class="text-center"><?= number_format($hrs, 1) ?>h</td>
                                        <td><?= vstBadge($l['status'] ?? '') ?></td>
                                        <td style="max-width:185px;color:var(--muted);"
                                            title="<?= htmlspecialchars($descFull) ?>"><?= htmlspecialchars($descShort) ?></td>
                                        <td>
                                            <div style="display:flex;gap:5px;justify-content:center;">
                                                <?php if ($l['log_type'] === 'OFFICE'): ?>
                                                    <a href="office_log_view.php?id=<?= $l['id'] ?>" class="act-btn" title="View"
                                                        style="background:#ecfeff;border-color:#a5f3fc;color:#0e7490;"><i
                                                            class="fas fa-eye"></i></a>
                                                    <?php if ((int) $l['user_id'] === $uid): ?>
                                                        <a href="office_log_edit.php?id=<?= $l['id'] ?>" class="act-btn" title="Edit"
                                                            style="background:#fefce8;border-color:#fde68a;color:#92400e;"><i
                                                                class="fas fa-pencil-alt"></i></a>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <a href="log_view.php?id=<?= $l['id'] ?>" class="act-btn" title="View"
                                                        style="background:#f0fdf4;border-color:#bbf7d0;color:#166534;"><i
                                                            class="fas fa-eye"></i></a>
                                                    <?php if ((int) $l['user_id'] === $uid): ?>
                                                        <a href="log_edit.php?id=<?= $l['id'] ?>" class="act-btn" title="Edit"
                                                            style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;"><i
                                                                class="fas fa-pencil-alt"></i></a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="7" style="font-size:.77rem;color:var(--muted);">
                                        <?= $totalLogs ?> records &nbsp;·&nbsp; <?= $visitCount ?> visit &nbsp;·&nbsp;
                                        <?= $totalLogs - $visitCount ?> office
                                    </td>
                                    <td class="text-center"><?= number_format($totalHours, 1) ?>h</td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
    $(function () {
        if ($('#logsTable tbody tr').length > 10) {
            $('#logsTable').DataTable({
                pageLength: 25,
                order: [],
                columnDefs: [{ orderable: false, targets: [0, 10] }],
                dom: '<"ll-dt-top"lf>rt<"ll-dt-bot"ip>',
                language: {
                    search: '', searchPlaceholder: 'Quick search…',
                    lengthMenu: 'Show _MENU_ rows',
                    info: '_START_–_END_ of _TOTAL_',
                    paginate: { previous: '‹', next: '›' }
                }
            });
        }
    });
    document.addEventListener('DOMContentLoaded', function () {

        /* ── Staff Tom Select ── */
        new TomSelect('#fStaff', {
            allowEmptyOption: true,
            maxOptions: 200,
            searchField: ['text'],
            render: {
                option: function (data, escape) {
                    var parts = data.text.split(' — ');
                    var name = escape(parts[0].trim());
                    var empId = parts[1] ? parts[1].trim() : '';
                    return '<div style="padding:6px 10px;line-height:1.4;">' +
                        '<div style="font-weight:600;font-size:.82rem;">' + name + '</div>' +
                        (empId ? '<div style="font-size:.7rem;color:#6b7280;">ID: ' + escape(empId) + '</div>' : '') +
                        '</div>';
                },
                item: function (data, escape) {
                    return '<div>' + escape(data.text.split(' — ')[0].trim()) + '</div>';
                }
            },
            onChange: function () { applyFilter(); }
        });

        /* ── Client Tom Select ── */
        new TomSelect('#fClient', {
            allowEmptyOption: true,
            maxOptions: 500,
            searchField: ['text'],
            render: {
                option: function (data, escape) {
                    var raw = data.text;
                    var name = raw.split(' — ')[0].trim();
                    var rest = raw.includes(' — ') ? raw.split(' — ').slice(1).join(' — ').trim() : '';
                    var code = '', pan = '';
                    if (rest) {
                        var ps = rest.split(' | ');
                        code = ps[0] ? ps[0].trim() : '';
                        pan = ps[1] ? ps[1].trim() : '';
                    }
                    return '<div style="padding:6px 10px;line-height:1.4;">' +
                        '<div style="font-weight:600;font-size:.82rem;">' + escape(name) + '</div>' +
                        '<div style="font-size:.7rem;color:#6b7280;">' +
                        (code ? '<span style="margin-right:8px;">Code: ' + escape(code) + '</span>' : '') +
                        (pan ? '<span>PAN: ' + escape(pan) + '</span>' : '') +
                        '</div>' +
                        '</div>';
                },
                item: function (data, escape) {
                    return '<div>' + escape(data.text.split(' — ')[0].trim()) + '</div>';
                }
            },
            onChange: function () { applyFilter(); }
        });

    });
    function applyFilter() {
        const m = document.getElementById('fMonth').value;
        const s = document.getElementById('fStaff')?.value ?? '';
        const cl = document.getElementById('fClient')?.value ?? '';
        const df = document.getElementById('fDateFrom')?.value ?? '';
        const dt = document.getElementById('fDateTo')?.value ?? '';
        const cur = new URLSearchParams(window.location.search);
        const type = cur.get('log_type') ?? '';
        const status = cur.get('visit_status') ?? '';
        const p = new URLSearchParams();
        p.set('month', m);
        if (type) p.set('log_type', type);
        if (s) p.set('staff_id', s);
        if (status) p.set('visit_status', status);
        if (cl) p.set('client_id', cl);
        if (df) p.set('date_from', df);
        if (dt) p.set('date_to', dt);
        location.href = '?' + p.toString();
    }
</script>
<?php include '../../includes/footer.php'; ?>