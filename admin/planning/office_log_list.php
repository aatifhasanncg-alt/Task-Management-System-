<?php
/**
 * consulting/office_log_list.php — Admin: All Office Work Logs
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAdmin();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

$deptId = (int) $user['department_id'];
$branchId = (int) $user['branch_id'];

// ── Dept resolution ───────────────────────────────────────────────────────
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
$statusFilter = in_array($_GET['status'] ?? '', ['', 'not_started', 'wip', 'holding', 'completed']) ? ($_GET['status'] ?? '') : '';
$clientFilter = (int) ($_GET['client_id'] ?? 0);
$dateFromFilter = $_GET['date_from'] ?? '';
$dateToFilter = $_GET['date_to'] ?? '';

// ── Scope ──────────────────────────────────────────────────────────────────
$currentRole = $_SESSION['role'] ?? ($user['role'] ?? '');
$isAdmin = in_array($currentRole, ['admin', 'executive', 'superadmin']);

if ($isAdmin) {
    // Users where logged-in user is set as managed_by in `users` table
    $managedUsersQ = $db->prepare("
        SELECT id FROM users
        WHERE is_active = 1
          AND managed_by = ?
    ");
    $managedUsersQ->execute([$uid]);
    $managedUserIds = $managedUsersQ->fetchAll(PDO::FETCH_COLUMN);

    // Users where logged-in user is set as managed_by in `user_department_assignments`
    $udaManagedQ = $db->prepare("
        SELECT DISTINCT uda.user_id FROM user_department_assignments uda
        WHERE uda.managed_by = ?
    ");
    $udaManagedQ->execute([$uid]);
    $udaManagedIds = $udaManagedQ->fetchAll(PDO::FETCH_COLUMN);

    // Also include CON dept users (original scope) merged with managed users
    $conScopeQ = $db->query("
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

    // Intersect: CON users who are also managed by the logged-in user
    // (if logged-in user manages anyone, restrict to those; otherwise fall back to full CON scope)
    $allManagedIds = array_unique(array_merge($managedUserIds, $udaManagedIds));

    if (!empty($allManagedIds)) {
        // Only show logs for CON users that this admin actually manages
        $scopeIds = array_unique(array_merge([$uid], array_intersect($conScopeQ, $allManagedIds)));
    } else {
        // Fallback: no managed_by rows found — show all CON dept users
        $scopeIds = array_unique(array_merge([$uid], $conScopeQ));
    }
} else {
    $scopeIds = [$uid];
}
$inList = implode(',', array_map('intval', $scopeIds)) ?: '0';
// ── WHERE ──────────────────────────────────────────────────────────────────
// Include logs in scope by staff membership OR where the login user is the supervisor
$where = "DATE_FORMAT(owl.log_date,'%Y-%m') = ? AND (owl.user_id IN ({$inList}) OR owl.supervisor_id = ?)";
$params = [$month, $uid];

if ($staffFilter) {
    $where .= " AND owl.user_id = ?";
    $params[] = $staffFilter;
}
if ($statusFilter !== '') {
    $where .= " AND owl.status = ?";
    $params[] = $statusFilter;
}
if ($clientFilter) {
    $where .= " AND owl.client_id = ?";
    $params[] = $clientFilter;
}
if ($dateFromFilter) {
    $where .= " AND owl.log_date >= ?";
    $params[] = $dateFromFilter;
}
if ($dateToFilter) {
    $where .= " AND owl.log_date <= ?";
    $params[] = $dateToFilter;
}

$stmt = $db->prepare("
    SELECT owl.*,
           ROUND(TIME_TO_SEC(TIMEDIFF(owl.time_out, owl.time_in)) / 3600, 2) AS duration_hours,
           c.company_name, c.company_code,
           u.full_name AS staff_name, u.employee_id,
           sv.full_name AS supervisor_name,
           COALESCE(d.dept_name, d2.dept_name)  AS department_name,
           b.branch_name
    FROM office_work_logs owl
    LEFT JOIN companies c ON c.id = owl.client_id
    LEFT JOIN users u ON u.id = owl.user_id
    LEFT JOIN users sv ON sv.id = owl.supervisor_id
    LEFT JOIN departments d ON d.id = owl.department_id
    LEFT JOIN (
        SELECT uda_inner.user_id, MIN(uda_inner.department_id) AS department_id
        FROM user_department_assignments uda_inner
        GROUP BY uda_inner.user_id
    ) uda_primary ON uda_primary.user_id = u.id
    LEFT JOIN departments d2 ON d2.id = uda_primary.department_id
    LEFT JOIN branches b ON b.id = owl.branch_id
    WHERE {$where}
    ORDER BY owl.log_date DESC, owl.time_in DESC
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Client list ───────────────────────────────────────────────────────────
$clientStmt = $db->query("
    SELECT id, company_name, company_code, pan_number
    FROM companies
    ORDER BY company_name ASC
");
$clientList = $clientStmt->fetchAll(PDO::FETCH_ASSOC);

// Keep the key-pair version only for the summary bar count
$clientNames = array_column($clientList, 'company_name', 'id');

// ── Staff list ─────────────────────────────────────────────────────────────
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

// ── KPIs ───────────────────────────────────────────────────────────────────
$stmtKpi = $db->prepare("
    SELECT COUNT(*) AS total_logs,
           ROUND(SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) / 3600, 2) AS total_hours,
           COUNT(DISTINCT client_id)   AS unique_clients,
           SUM(status = 'not_started') AS not_started_count,
           SUM(status = 'wip')         AS wip_count,
           SUM(status = 'holding')     AS holding_count,
           SUM(status = 'completed')   AS completed_count
    FROM office_work_logs owl WHERE {$where}
");
$stmtKpi->execute($params);
$kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

// ── Derived ────────────────────────────────────────────────────────────────
$totalLogs = count($logs);
$totalHours = (float) ($kpi['total_hours'] ?? 0);
$hasFilters = $staffFilter || $statusFilter !== '' || $clientFilter || $dateFromFilter || $dateToFilter;

// Group by date for card display
$groupedLogs = [];
foreach ($logs as $l) {
    $groupedLogs[$l['log_date']][] = $l;
}

$pageTitle = 'All Office Work Logs';
include '../../includes/header.php';

function qstr(array $overrides, array $base): string
{
    $p = array_merge($base, $overrides);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== 0 && $v !== null));
}

$baseQ = [
    'month' => $month,
    'staff_id' => $staffFilter ?: '',
    'status' => $statusFilter,
    'client_id' => $clientFilter ?: '',
    'date_from' => $dateFromFilter,
    'date_to' => $dateToFilter,
];
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800&display=swap"
    rel="stylesheet">

<style>
    /* ── Shared tokens ────────────────────────────────────────────────────── */
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

    /* ── Hero (identical to log_list) ────────────────────────────────────── */
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

    /* ── Log cards wrap ──────────────────────────────────────────────────── */
    .log-cards-wrap {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .log-cards-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .9rem 1.2rem;
        border-bottom: 1px solid var(--border);
        gap: .75rem;
        flex-wrap: wrap;
    }

    .log-cards-head h5 {
        font-size: .92rem;
        font-weight: 700;
        color: var(--ink);
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

    /* ── Day group ───────────────────────────────────────────────────────── */
    .day-group {
        padding: .85rem 1.2rem 0;
    }

    .day-header {
        display: flex;
        align-items: center;
        gap: .75rem;
        margin-bottom: .65rem;
    }

    .day-label {
        font-size: .76rem;
        font-weight: 700;
        color: var(--ink);
        white-space: nowrap;
    }

    .day-today-tag {
        font-size: .62rem;
        font-weight: 700;
        background: #fef3c7;
        color: #d97706;
        padding: .1rem .4rem;
        border-radius: 5px;
    }

    .day-line {
        flex: 1;
        height: 1px;
        background: var(--border);
    }

    .day-meta {
        font-size: .7rem;
        color: var(--muted);
        white-space: nowrap;
    }

    /* ── Office log card ─────────────────────────────────────────────────── */
    .office-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 1rem 1.1rem;
        margin-bottom: .6rem;
        position: relative;
        transition: box-shadow .15s, transform .1s;
    }

    .office-card:hover {
        box-shadow: 0 3px 14px rgba(15, 23, 42, .08);
        transform: translateY(-1px);
    }

    .office-card-accent {
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        border-radius: 10px 0 0 10px;
    }

    .office-card-body {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .office-card-left {
        flex: 1;
        min-width: 0;
        padding-left: .5rem;
    }

    .office-card-right {
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-shrink: 0;
    }

    .client-name {
        font-size: .9rem;
        font-weight: 700;
        color: var(--ink);
    }

    .client-code {
        font-size: .67rem;
        background: #f1f5f9;
        color: var(--muted);
        border-radius: 5px;
        padding: .1rem .4rem;
        font-weight: 600;
    }

    .staff-tag {
        font-size: .72rem;
        color: var(--muted);
    }

    .staff-tag i {
        color: var(--gold);
    }

    .off-badge {
        font-size: .67rem;
        font-weight: 700;
        padding: .18rem .52rem;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        gap: .28rem;
    }

    .off-badge i {
        font-size: .58rem;
    }

    .log-desc {
        font-size: .79rem;
        color: var(--muted);
        margin-top: .4rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 580px;
    }

    .log-notes {
        font-size: .72rem;
        color: #d97706;
        margin-top: .22rem;
    }

    .log-notes i {
        margin-right: .2rem;
    }

    .time-block {
        text-align: center;
    }

    .time-hours {
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--gold);
        line-height: 1;
    }

    .time-range {
        font-size: .65rem;
        color: var(--muted);
        white-space: nowrap;
        margin-top: .18rem;
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

    /* ── Summary footer bar ──────────────────────────────────────────────── */
    .log-summary-bar {
        padding: .75rem 1.2rem;
        border-top: 1px solid var(--border);
        background: #f8fafc;
        font-size: .77rem;
        color: var(--muted);
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 1.2rem;
        flex-wrap: wrap;
    }

    .log-summary-bar .sum-val {
        color: var(--ink);
        font-weight: 800;
    }

    /* ── Empty state ─────────────────────────────────────────────────────── */
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
    }

    @media (max-width: 768px) {
        .ll-hero {
            padding: 1.1rem 1.2rem;
        }

        .kpi-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .log-desc {
            max-width: 240px;
        }

        .office-card-right {
            width: 100%;
            justify-content: space-between;
            border-top: 1px solid var(--border);
            padding-top: .65rem;
            margin-top: .5rem;
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
                            <i class="fas fa-building"></i> Consulting &nbsp;·&nbsp; Office Logs
                        </div>
                        <h4>All Office Work Logs</h4>
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
                        <a href="log_list.php?month=<?= $month ?>" class="hero-btn">
                            <i class="fas fa-car"></i> Visit Logs
                        </a>
                        <a href="index.php?month=<?= $month ?>" class="hero-btn">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=consulting_performance&view=office&month=<?= urlencode($month) ?>&staff_id=<?= $staffFilter ?>&status=<?= urlencode($statusFilter) ?>"
                            class="hero-btn">
                            <i class="fas fa-file-pdf" style="color:#f87171;"></i> PDF
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=consulting_performance&view=office&month=<?= urlencode($month) ?>&staff_id=<?= $staffFilter ?>&status=<?= urlencode($statusFilter) ?>"
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
                    ['fa-building', '#92400e', '#fef3c7', 'Total Logs', (int) ($kpi['total_logs'] ?? 0)],
                    ['fa-clock', '#b45309', '#fefce8', 'Total Hours', number_format($totalHours, 1) . 'h'],
                    ['fa-briefcase', '#7c3aed', '#ede9fe', 'Clients', (int) ($kpi['unique_clients'] ?? 0)],
                    ['fa-hourglass', '#d97706', '#ffedd5', 'Not Started', (int) ($kpi['not_started_count'] ?? 0)],
                    ['fa-circle-notch', '#2563eb', '#eff6ff', 'WIP', (int) ($kpi['wip_count'] ?? 0)],
                    ['fa-pause-circle', '#d97706', '#ffedd5', 'Holding', (int) ($kpi['holding_count'] ?? 0)],
                    ['fa-check-double', '#059669', '#ecfdf5', 'Completed', (int) ($kpi['completed_count'] ?? 0)],
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
                + (int) ($dateFromFilter !== '') + (int) ($dateToFilter !== '');
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

                    <!-- Staff -->
                    <select id="fStaff" class="ff-input <?= $staffFilter ? 'is-active' : '' ?>" style="width:170px;">
                        <option value="">All Staff</option>
                        <?php foreach ($deptStaff as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $staffFilter == $s['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['full_name']) ?>
                                <?= $s['employee_id'] ? ' — ' . $s['employee_id'] : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="filter-divider"></div>

                    <!-- Status pills -->
                    <span class="filter-section-label">Status:</span>
                    <?php
                    $statusPills = [
                        '' => ['All', '#c9a84c', '#fffbeb', '#e2e8f0'],
                        'wip' => ['◷ WIP', '#2563eb', '#eff6ff', '#2563eb'],
                        'not_started' => ['◷ Not Started', '#d97706', '#ffedd5', '#d97706'],
                        'holding' => ['◷ Holding', '#d97706', '#ffedd5', '#d97706'],
                        'completed' => ['✓✓ Completed', '#059669', '#ecfdf5', '#059669'],
                    ];
                    foreach ($statusPills as $sKey => [$sLabel, $sColor, $sBg, $sBorder]):
                        $isAct = ($statusFilter === $sKey);
                        ?>
                        <a href="<?= qstr(['status' => $sKey], $baseQ) ?>" class="status-pill"
                            style="<?= $isAct ? "border-color:{$sBorder};background:{$sBg};color:{$sColor};" : '' ?>">
                            <?= $sLabel ?>
                        </a>
                    <?php endforeach; ?>

                    <div class="filter-divider"></div>

                    <!-- Client -->
                    <!-- Client -->
                    <?php if (!empty($clientList)): ?>
                        <select id="fClient" class="ff-input <?= $clientFilter ? 'is-active' : '' ?>" style="width:220px;">
                            <option value="">All Clients</option>
                            <?php foreach ($clientList as $cl): ?>
                                <option value="<?= $cl['id'] ?>" data-code="<?= htmlspecialchars($cl['company_code'] ?? '') ?>"
                                    data-pan="<?= htmlspecialchars($cl['pan_number'] ?? '') ?>" <?= $clientFilter == $cl['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cl['company_name']) ?>
                                    <?= $cl['company_code'] ? ' — ' . $cl['company_code'] : '' ?>
                                    <?= $cl['pan_number'] ? ' | ' . $cl['pan_number'] : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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

            <!-- ── Log Cards ─────────────────────────────────────────────── -->
            <div class="log-cards-wrap">
                <div class="log-cards-head">
                    <h5><i class="fas fa-building me-2" style="color:var(--gold);"></i>Office Logs — <?= $monthLabel ?>
                    </h5>
                    <div style="display:flex;align-items:center;gap:.65rem;">
                        <?php if ($hasFilters): ?><span class="filtered-tag"><i
                                    class="fas fa-filter me-1"></i>Filtered</span><?php endif; ?>
                        <span style="font-size:.74rem;color:var(--muted);"><?= $totalLogs ?> records ·
                            <?= number_format($totalHours, 1) ?>h total</span>
                    </div>
                </div>

                <?php if (empty($logs)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h6>No office logs found</h6>
                        <p>Try adjusting your filters or selecting a different month.</p>
                        <?php if ($hasFilters): ?>
                            <a href="?month=<?= $month ?>" class="clear-btn" style="justify-content:center;margin-top:.7rem;">
                                <i class="fas fa-times-circle"></i> Clear all filters
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($groupedLogs as $date => $dayLogs):
                        $dayHours = array_sum(array_column($dayLogs, 'duration_hours'));
                        $isToday = ($date === date('Y-m-d'));
                        ?>
                        <div class="day-group">
                            <div class="day-header">
                                <div class="day-label">
                                    <?= date('l, d M Y', strtotime($date)) ?>
                                    <?php if ($isToday): ?>
                                        <span class="day-today-tag ms-1">Today</span>
                                    <?php endif; ?>
                                </div>
                                <div class="day-line"></div>
                                <div class="day-meta">
                                    <?= count($dayLogs) ?> log<?= count($dayLogs) > 1 ? 's' : '' ?>
                                    &nbsp;·&nbsp;
                                    <strong style="color:var(--ink);"><?= number_format($dayHours, 1) ?>h</strong>
                                </div>
                            </div>

                            <?php foreach ($dayLogs as $l):
                                $st = $l['status'] ?? 'wip';
                                $sMap = [
                                    'not_started' => ['#d97706', '#ffedd5', 'fa-hourglass', 'Not Started'],
                                    'holding' => ['#d97706', '#ffedd5', 'fa-pause-circle', 'Holding'],
                                    'wip' => ['#2563eb', '#eff6ff', 'fa-circle-notch', 'WIP'],
                                    'completed' => ['#059669', '#ecfdf5', 'fa-check-double', 'Completed'],
                                ];
                                [$sCol, $sBg, $sIco, $sLbl] = $sMap[$st] ?? ['#9ca3af', '#f3f4f6', 'fa-circle', ucfirst($st)];
                                $hrs = (float) ($l['duration_hours'] ?? 0);
                                ?>
                                <div class="office-card">
                                    <div class="office-card-accent" style="background:<?= $sCol ?>;"></div>
                                    <div class="office-card-body">

                                        <div class="office-card-left">
                                            <div
                                                style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.3rem;">
                                                <span class="client-name"><?= htmlspecialchars($l['company_name'] ?? '—') ?></span>
                                                <?php if (!empty($l['company_code'])): ?>
                                                    <span class="client-code"><?= htmlspecialchars($l['company_code']) ?></span>
                                                <?php endif; ?>
                                                <span class="off-badge" style="background:<?= $sBg ?>;color:<?= $sCol ?>;">
                                                    <i class="fas <?= $sIco ?>"></i><?= $sLbl ?>
                                                </span>
                                                <span class="staff-tag">
                                                    <i class="fas fa-user me-1"></i>
                                                    <?= htmlspecialchars($l['staff_name'] ?? '—') ?>
                                                    <?php if (!empty($l['employee_id'])): ?>
                                                        <span
                                                            style="color:var(--border);margin:0 .2rem;">·</span><?= htmlspecialchars($l['employee_id']) ?>
                                                    <?php endif; ?>
                                                </span>
                                                <?php if (!empty($l['supervisor_name'])): ?>
                                                    <span class="staff-tag">
                                                        <i class="fas fa-user-shield me-1"></i>
                                                        Supervisor: <?= htmlspecialchars($l['supervisor_name']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="log-desc" title="<?= htmlspecialchars($l['description'] ?? '') ?>">
                                                <?= htmlspecialchars(mb_strimwidth($l['description'] ?? '', 0, 130, '…')) ?>
                                            </div>
                                            <?php if (!empty($l['notes'])): ?>
                                                <div class="log-notes">
                                                    <i class="fas fa-sticky-note"></i>
                                                    <?= htmlspecialchars(mb_strimwidth($l['notes'], 0, 90, '…')) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="office-card-right">
                                            <div class="time-block">
                                                <div class="time-hours"><?= number_format($hrs, 1) ?>h</div>
                                                <div class="time-range">
                                                    <?= $l['time_in'] ? date('H:i', strtotime($l['time_in'])) : '—' ?>
                                                    &nbsp;–&nbsp;
                                                    <?= $l['time_out'] ? date('H:i', strtotime($l['time_out'])) : '—' ?>
                                                </div>
                                            </div>
                                            <div style="display:flex;gap:5px;">
                                                <a href="office_log_view.php?id=<?= $l['id'] ?>" class="act-btn" title="View"
                                                    style="background:#ecfeff;border-color:#a5f3fc;color:#0e7490;">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if ((int) $l['user_id'] === $uid): ?>
                                                    <a href="office_log_edit.php?id=<?= $l['id'] ?>" class="act-btn" title="Edit"
                                                        style="background:#fefce8;border-color:#fde68a;color:#92400e;">
                                                        <i class="fas fa-pencil-alt"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                    <!-- Summary bar -->
                    <div class="log-summary-bar">
                        <span>Total: <span class="sum-val"><?= $totalLogs ?></span> records</span>
                        <span>Hours: <span class="sum-val"
                                style="color:#b45309;"><?= number_format($totalHours, 1) ?>h</span></span>
                        <span>Not Started: <span class="sum-val"
                                style="color:#6b7280;"><?= (int) ($kpi['not_started_count'] ?? 0) ?></span></span>
                        <span>WIP: <span class="sum-val"
                                style="color:#2563eb;"><?= (int) ($kpi['wip_count'] ?? 0) ?></span></span>
                        <span>Holding: <span class="sum-val"
                                style="color:#d97706;"><?= (int) ($kpi['holding_count'] ?? 0) ?></span></span>
                        <span>Completed: <span class="sum-val"
                                style="color:#059669;"><?= (int) ($kpi['completed_count'] ?? 0) ?></span></span>
                        <span>Clients: <span class="sum-val" style="color:#7c3aed;"><?= count($clientNames) ?></span></span>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
function applyFilter() {
    const m  = document.getElementById('fMonth').value;
    const s  = document.getElementById('fStaff')?._tomSelect?.getValue()  ?? document.getElementById('fStaff')?.value ?? '';
    const cl = document.getElementById('fClient')?._tomSelect?.getValue() ?? document.getElementById('fClient')?.value ?? '';
    const df = document.getElementById('fDateFrom')?.value ?? '';
    const dt = document.getElementById('fDateTo')?.value   ?? '';
    const cur    = new URLSearchParams(window.location.search);
    const status = cur.get('status') ?? '';
    const p = new URLSearchParams();
    p.set('month', m);
    if (s)      p.set('staff_id',  s);
    if (status) p.set('status',    status);
    if (cl)     p.set('client_id', cl);
    if (df)     p.set('date_from', df);
    if (dt)     p.set('date_to',   dt);
    location.href = '?' + p.toString();
}

document.addEventListener("DOMContentLoaded", function () {

    /* ── Staff Tom Select ── */
    new TomSelect("#fStaff", {
        allowEmptyOption: true,
        maxOptions: 200,
        searchField: ['text'],   // searches the full option text including employee_id
        render: {
            option: function(data, escape) {
                var parts = data.text.split(' — ');
                var name  = escape(parts[0].trim());
                var empId = parts[1] ? parts[1].trim() : '';
                return '<div style="padding:6px 10px;line-height:1.4;">' +
                    '<div style="font-weight:600;font-size:.82rem;">' + name + '</div>' +
                    (empId ? '<div style="font-size:.7rem;color:#6b7280;">ID: ' + escape(empId) + '</div>' : '') +
                    '</div>';
            },
            item: function(data, escape) {
                var parts = data.text.split(' — ');
                return '<div>' + escape(parts[0].trim()) + '</div>';
            }
        },
        onChange: function() { applyFilter(); }
    });

    /* ── Client Tom Select ── */
    new TomSelect("#fClient", {
        allowEmptyOption: true,
        maxOptions: 500,
        searchField: ['text'],   // searches full text: name — code | PAN
        render: {
            option: function(data, escape) {
                // Parse: "Company Name — CODE | PAN123"
                var raw   = data.text;
                var name  = raw.split(' — ')[0].trim();
                var rest  = raw.includes(' — ') ? raw.split(' — ').slice(1).join(' — ').trim() : '';
                var code  = '', pan = '';
                if (rest) {
                    var ps = rest.split(' | ');
                    code = ps[0] ? ps[0].trim() : '';
                    pan  = ps[1] ? ps[1].trim() : '';
                }
                return '<div style="padding:6px 10px;line-height:1.4;">' +
                    '<div style="font-weight:600;font-size:.82rem;">' + escape(name) + '</div>' +
                    '<div style="font-size:.7rem;color:#6b7280;">' +
                        (code ? '<span style="margin-right:8px;">Code: ' + escape(code) + '</span>' : '') +
                        (pan  ? '<span>PAN: ' + escape(pan)  + '</span>' : '') +
                    '</div>' +
                    '</div>';
            },
            item: function(data, escape) {
                var name = data.text.split(' — ')[0].trim();
                return '<div>' + escape(name) + '</div>';
            }
        },
        onChange: function() { applyFilter(); }
    });

});
</script>
<?php include '../../includes/footer.php'; ?>