<?php
/**
 * consulting/executive/log_list.php — Executive: All Staff Visit Logs + Office Logs
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireExecutive();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

$now = new DateTime();
$month = $_GET['month'] ?? $now->format('Y-m');
$staffId = (int) ($_GET['staff_id'] ?? 0);
$clientId = (int) ($_GET['client_id'] ?? 0);
$fieldStatus = $_GET['field_status'] ?? '';   // visited | missed | rescheduled
$officeStatus = $_GET['office_status'] ?? '';   // wip | completed
$weekNum = (int) ($_GET['week'] ?? 0);
$logType = $_GET['log_type'] ?? '';   // '' = all | 'field' | 'office'
$branchId = (int) ($_GET['branch_id'] ?? 0);

$monthDate = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: $now;
$monthLabel = $monthDate->format('F Y');
$branchList = $db->query("
    SELECT id, branch_name, branch_code
    FROM branches
    WHERE is_active = 1
    ORDER BY branch_name
")->fetchAll();
/* ══════════════════════════════════════════════════
   KPIs  (both tables unioned for totals)
   Office status: wip | completed  (real column value)
   Field status:  visited | missed | rescheduled
══════════════════════════════════════════════════ */
$kpiParams = [$month, $month];
$kpiStaffW = $staffId ? " AND user_id = ?" : "";
$kpiClientW = $clientId ? " AND client_id = ?" : "";
$kpiBranchW = $branchId ? " AND branch_id = ?" : "";
if ($staffId) {
    $kpiParams[] = $staffId;
    $kpiParams[] = $staffId;
}
if ($clientId) {
    $kpiParams[] = $clientId;
    $kpiParams[] = $clientId;
}
if ($branchId) {
    $kpiParams[] = $branchId;
    $kpiParams[] = $branchId;
}

$kpiSQL = "
    SELECT
        COUNT(*) AS total_logs,
        COALESCE(SUM(duration_hours), 0) AS total_hours,
        SUM(CASE WHEN log_source = 'field'  AND visit_status = 'visited'      THEN 1 ELSE 0 END) AS field_visited,
        SUM(CASE WHEN log_source = 'field'  AND visit_status = 'missed'       THEN 1 ELSE 0 END) AS field_missed,
        SUM(CASE WHEN log_source = 'field'  AND visit_status = 'rescheduled'  THEN 1 ELSE 0 END) AS field_rescheduled,
        SUM(CASE WHEN log_source = 'office' AND office_status = 'completed'   THEN 1 ELSE 0 END) AS office_completed,
        SUM(CASE WHEN log_source = 'office' AND office_status = 'not_started' THEN 1 ELSE 0 END) AS office_not_started,
        SUM(CASE WHEN log_source = 'office' AND office_status = 'wip'         THEN 1 ELSE 0 END) AS office_wip,
        SUM(CASE WHEN log_source = 'office' AND office_status = 'holding'     THEN 1 ELSE 0 END) AS office_holding,
        SUM(CASE WHEN log_source = 'office' THEN 1 ELSE 0 END) AS office_cnt,
        SUM(CASE WHEN log_source = 'field'  THEN 1 ELSE 0 END) AS field_cnt
    FROM (
        SELECT user_id, client_id, branch_id, duration_hours,
               visit_status, NULL AS office_status, 'field' AS log_source
        FROM work_logs
        WHERE month_year = ? {$kpiStaffW} {$kpiClientW} {$kpiBranchW}

        UNION ALL

        SELECT user_id, client_id, branch_id,
               ROUND(TIME_TO_SEC(TIMEDIFF(time_out, time_in)) / 3600, 2) AS duration_hours,
               NULL AS visit_status, status AS office_status, 'office' AS log_source
        FROM office_work_logs
        WHERE DATE_FORMAT(log_date, '%Y-%m') = ? {$kpiStaffW} {$kpiClientW} {$kpiBranchW}
    ) AS combined
";
$kpiStmt = $db->prepare($kpiSQL);
$kpiStmt->execute($kpiParams);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);
$totalLogs = (int) ($kpi['total_logs'] ?? 0);
$totalHours = (float) ($kpi['total_hours'] ?? 0);
$fieldVisited = (int) ($kpi['field_visited'] ?? 0);
$fieldMissed = (int) ($kpi['field_missed'] ?? 0);
$fieldRescheduled = (int) ($kpi['field_rescheduled'] ?? 0);
$officeCompleted = (int) ($kpi['office_completed'] ?? 0);
$officeNotStarted = (int) ($kpi['office_not_started'] ?? 0);
$officeWip = (int) ($kpi['office_wip'] ?? 0);
$officeHolding = (int) ($kpi['office_holding'] ?? 0);
$officeCnt = (int) ($kpi['office_cnt'] ?? 0);
$fieldCnt = (int) ($kpi['field_cnt'] ?? 0);

/* ══════════════════════════════════════════════════
   Staff list
══════════════════════════════════════════════════ */
$staffList = $db->query("
    SELECT DISTINCT u.id, u.full_name, u.employee_id
    FROM users u
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
    ORDER BY u.full_name
")->fetchAll();

/* ══════════════════════════════════════════════════
   Client list
══════════════════════════════════════════════════ */
$clientList = $db->query("
    SELECT id, company_name, company_code, pan_number
    FROM companies
    WHERE is_active = 1
    ORDER BY company_name
")->fetchAll();

/* ══════════════════════════════════════════════════
   Build combined query
   fieldStatus  → applied only to work_logs (visit_status col)
   officeStatus → applied only to office_work_logs (status col)
══════════════════════════════════════════════════ */
$where1 = ["wl.month_year = ?"];
$where2 = ["DATE_FORMAT(owl.log_date, '%Y-%m') = ?"];
$params1 = [$month];
$params2 = [$month];

if ($staffId) {
    $where1[] = "wl.user_id = ?";
    $params1[] = $staffId;
    $where2[] = "owl.user_id = ?";
    $params2[] = $staffId;
}
if ($clientId) {
    $where1[] = "wl.client_id = ?";
    $params1[] = $clientId;
    $where2[] = "owl.client_id = ?";
    $params2[] = $clientId;
}
// Field-only status filter
if ($fieldStatus) {
    $where1[] = "wl.visit_status = ?";
    $params1[] = $fieldStatus;
}
// Office-only status filter (maps to the `status` column: wip | completed)
if ($officeStatus) {
    $where2[] = "owl.status = ?";
    $params2[] = $officeStatus;
}
if ($weekNum) {
    $where1[] = "wl.week_number = ?";
    $params1[] = $weekNum;
    $where2[] = "WEEK(owl.log_date, 1) - WEEK(DATE_FORMAT(owl.log_date,'%Y-%m-01'), 1) + 1 = ?";
    $params2[] = $weekNum;
}
if ($branchId) {
    $where1[] = "wl.branch_id = ?";
    $params1[] = $branchId;
    $where2[] = "owl.branch_id = ?";
    $params2[] = $branchId;
}
$sql1 = "
    SELECT
        wl.id,
        'field'            AS log_source,
        wl.log_date,
        wl.day_of_week,
        wl.week_number,
        wl.time_in,
        wl.time_out,
        wl.duration_hours,
        wl.work_description,
        NULL               AS description,
        NULL               AS notes,
        NULL               AS office_status,
        wl.visit_status,
        wl.rescheduled_to_entry_id,
        wl.created_at,
        u.full_name,
        u.employee_id,
        c.company_name,
        c.company_code,
        c.pan_number,
        d.dept_name,
        b.branch_name,
        sv.full_name         AS supervisor_name,
        rpe.plan_date        AS reschedule_date,
        rpe.planned_time_in  AS reschedule_time_in,
        rpe.planned_time_out AS reschedule_time_out,
        rpe.planned_hours    AS reschedule_hours,
        rpe.notes            AS reschedule_notes
    FROM work_logs wl
    JOIN users      u ON u.id = wl.user_id
    JOIN companies  c ON c.id = wl.client_id
    LEFT JOIN work_plan_entries rpe
    ON rpe.id = wl.rescheduled_to_entry_id
    LEFT JOIN departments d ON d.id = wl.department_id
    LEFT JOIN branches    b ON b.id = wl.branch_id
    LEFT JOIN users       sv ON sv.id = wl.supervisor_id
    WHERE " . implode(' AND ', $where1);

$sql2 = "
    SELECT
        owl.id,
        'office'           AS log_source,
        owl.log_date,
        DAYNAME(owl.log_date) AS day_of_week,
        WEEK(owl.log_date, 1) - WEEK(DATE_FORMAT(owl.log_date,'%Y-%m-01'), 1) + 1 AS week_number,
        owl.time_in,
        owl.time_out,
        ROUND(TIME_TO_SEC(TIMEDIFF(owl.time_out, owl.time_in)) / 3600, 2) AS duration_hours,
        NULL               AS work_description,
        owl.description,
        owl.notes,
        owl.status         AS office_status,
        NULL               AS visit_status,
        NULL               AS rescheduled_to_entry_id,
        owl.created_at,
        u.full_name,
        u.employee_id,
        c.company_name,
        c.company_code,
        c.pan_number,
        d.dept_name,
        b.branch_name,
        sv.full_name       AS supervisor_name,
        NULL               AS reschedule_date,
        NULL               AS reschedule_time_in,
        NULL               AS reschedule_time_out,
        NULL               AS reschedule_hours,
        NULL               AS reschedule_notes
    FROM office_work_logs owl
    JOIN users      u ON u.id = owl.user_id
    JOIN companies  c ON c.id = owl.client_id
    LEFT JOIN departments d ON d.id = owl.department_id
    LEFT JOIN branches    b ON b.id = owl.branch_id
    LEFT JOIN users       sv ON sv.id = owl.supervisor_id
    WHERE " . implode(' AND ', $where2);

// Determine which legs to include:
// - Explicit log_type filter always wins
// - If only officeStatus set, skip field leg; if only fieldStatus set, skip office leg
$includeField = true;
$includeOffice = true;

if ($logType === 'field') {
    $includeOffice = false;
}
if ($logType === 'office') {
    $includeField = false;
}

// If user filtered office status but didn't pick log_type=office, still hide field leg? No —
// they may want both. But if ONLY officeStatus is set (no fieldStatus) with logType='',
// we still show field rows (unfiltered by office status) + filtered office rows. That's correct.

if ($includeField && $includeOffice) {
    $unionSQL = "({$sql1}) UNION ALL ({$sql2})";
    $allParams = array_merge($params1, $params2);
} elseif ($includeField) {
    $unionSQL = $sql1;
    $allParams = $params1;
} else {
    $unionSQL = $sql2;
    $allParams = $params2;
}

$finalSQL = "SELECT * FROM ({$unionSQL}) AS combined ORDER BY log_date DESC, time_in DESC";
$stmt = $db->prepare($finalSQL);
$stmt->execute($allParams);
$logs = $stmt->fetchAll();

$pageTitle = 'All Visit Logs';
include '../../includes/header.php';
?>

<link rel="stylesheet" href="<?= APP_URL ?>/staff/planning/consulting.css">

<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">

<style>
    /* ── Filter bar ── */
    .filter-bar {
        background: #f9fafb;
        border-radius: 10px;
        padding: 12px 14px;
        margin-bottom: 16px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }

    /* ── Tom Select ── */
    .ts-dropdown .option.active,
    .ts-dropdown .option:hover {
        background: #c9a84c !important;
        color: #fff !important;
    }

    .ts-wrapper.focus .ts-control {
        border-color: #c9a84c !important;
        box-shadow: none !important;
    }

    #staffSelect+.ts-wrapper,
    #clientSelect+.ts-wrapper {
        width: 100% !important;
        min-width: 200px;
    }

    .ts-wrapper .ts-control {
        border: 1.5px solid #e5e7eb;
        border-radius: 6px;
        font-size: .8rem;
        padding: 5px 10px;
    }

    /* ── DataTables ── */
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 32px;
        padding: 0 10px;
        margin: 0 2px;
        border-radius: 6px;
        border: 1.5px solid #e5e7eb !important;
        background: #fff !important;
        color: #374151 !important;
        font-size: .8rem;
        font-weight: 600;
        cursor: pointer;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button.current,
    .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
        background: #c9a84c !important;
        border-color: #c9a84c !important;
        color: #fff !important;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background: #f9fafb !important;
        border-color: #c9a84c !important;
        color: #c9a84c !important;
    }

    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
        border: 1.5px solid #e5e7eb;
        border-radius: 6px;
        padding: 5px 10px;
        font-size: .8rem;
        margin-left: 6px;
    }

    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter {
        font-size: .8rem;
        color: #6b7280;
        padding: 10px 16px;
    }

    .dataTables_wrapper .dataTables_paginate {
        padding: 10px 16px;
    }

    /* ── Source badge ── */
    .badge-field {
        background: #dbeafe;
        color: #1d4ed8;
        border-radius: 999px;
        padding: 2px 9px;
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .3px;
    }

    .badge-office {
        background: #fce7f3;
        color: #be185d;
        border-radius: 999px;
        padding: 2px 9px;
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .3px;
    }

    /* ── View button ── */
    .btn-view-log {
        border: none;
        background: none;
        cursor: pointer;
        color: #c9a84c;
        font-size: .8rem;
        padding: 3px 7px;
        border-radius: 5px;
        transition: background .15s;
    }

    .btn-view-log:hover {
        background: #fef9ec;
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

    /* ══════════════════════════════════════════════
   LOG DETAIL MODAL
══════════════════════════════════════════════ */
    .log-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: rgba(0, 0, 0, .45);
        align-items: center;
        justify-content: center;
    }

    .log-modal-overlay.active {
        display: flex;
    }

    .log-modal {
        background: #fff;
        border-radius: 14px;
        width: 560px;
        max-width: 96vw;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
        animation: modalIn .22s ease;
    }

    @keyframes modalIn {
        from {
            opacity: 0;
            transform: translateY(18px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .log-modal-header {
        padding: 18px 22px 14px;
        border-bottom: 1px solid #f3f4f6;
        display: flex;
        align-items: flex-start;
        gap: 12px;
    }

    .log-modal-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }

    .log-modal-icon.field {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .log-modal-icon.office {
        background: #fce7f3;
        color: #be185d;
    }

    .log-modal-title {
        font-size: .95rem;
        font-weight: 700;
        color: #111827;
    }

    .log-modal-sub {
        font-size: .75rem;
        color: #9ca3af;
        margin-top: 2px;
    }

    .log-modal-close {
        margin-left: auto;
        background: none;
        border: none;
        font-size: 1.1rem;
        color: #9ca3af;
        cursor: pointer;
        padding: 4px;
        border-radius: 6px;
        transition: color .15s;
    }

    .log-modal-close:hover {
        color: #ef4444;
    }

    .log-modal-body {
        padding: 18px 22px;
    }

    .log-detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px 20px;
    }

    .log-detail-item label {
        display: block;
        font-size: .68rem;
        color: #9ca3af;
        text-transform: uppercase;
        letter-spacing: .5px;
        margin-bottom: 3px;
    }

    .log-detail-item .val {
        font-size: .83rem;
        font-weight: 600;
        color: #1f2937;
    }

    .log-detail-item.full {
        grid-column: 1 / -1;
    }

    .log-desc-box {
        background: #f9fafb;
        border-radius: 8px;
        padding: 10px 14px;
        font-size: .8rem;
        color: #374151;
        line-height: 1.6;
        white-space: pre-wrap;
    }

    .log-modal-footer {
        padding: 14px 22px;
        border-top: 1px solid #f3f4f6;
        display: flex;
        justify-content: flex-end;
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
                        <div class="page-hero-badge"><i class="fas fa-briefcase"></i> Executive · Consulting</div>
                        <h4>All Visit & Office Logs</h4>
                        <p>Field visits + in-office client work · <?= $monthLabel ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- KPI ROW -->
            <div class="kpi-row mb-4">
                <div class="kpi-tile" style="--kpi-color:#3b82f6;">
                    <div class="kpi-icon"><i class="fas fa-list" style="color:#3b82f6;"></i></div>
                    <div class="kpi-val"><?= $totalLogs ?></div>
                    <div class="kpi-label">Total Logs</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#c9a84c;">
                    <div class="kpi-icon"><i class="fas fa-clock" style="color:#c9a84c;"></i></div>
                    <div class="kpi-val"><?= number_format($totalHours, 1) ?>h</div>
                    <div class="kpi-label">Total Hours</div>
                </div>
                <!-- Field sub-counts -->
                <div class="kpi-tile" style="--kpi-color:#1d4ed8;">
                    <div class="kpi-icon"><i class="fas fa-car" style="color:#1d4ed8;"></i></div>
                    <div class="kpi-val"><?= $fieldCnt ?></div>
                    <div class="kpi-label">Field Visits
                        <span style="font-size:.65rem;display:block;color:#6b7280;margin-top:2px;">
                            ✅ <?= $fieldVisited ?> · ❌ <?= $fieldMissed ?> · 🔄 <?= $fieldRescheduled ?>
                        </span>
                    </div>
                </div>
                <!-- Office sub-counts -->
                <div class="kpi-tile" style="--kpi-color:#be185d;">
                    <div class="kpi-icon"><i class="fas fa-building" style="color:#be185d;"></i></div>
                    <div class="kpi-val"><?= $officeCnt ?></div>
                    <div class="kpi-label">Office Work
                        <span style="font-size:.65rem;display:block;color:#6b7280;margin-top:2px;">
                            ✔ <?= $officeCompleted ?> · ⏳ <?= $officeWip ?> . ▶️ <?= $officeNotStarted ?> · ⏸
                            <?= $officeHolding ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- FILTER BAR -->
            <form method="GET" class="filter-bar">

                <!-- Month -->
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Month</label>
                    <input type="month" name="month" class="cn-input" style="width:145px;" value="<?= $month ?>">
                </div>

                <!-- Log Type -->
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Type</label>
                    <select name="log_type" class="cn-input" style="width:120px;">
                        <option value="" <?= $logType === '' ? 'selected' : '' ?>>All Types</option>
                        <option value="field" <?= $logType === 'field' ? 'selected' : '' ?>>🚗 Field</option>
                        <option value="office" <?= $logType === 'office' ? 'selected' : '' ?>>🏢 Office</option>
                    </select>
                </div>

                <!-- Staff -->
                <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:200px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Staff</label>
                    <select name="staff_id" id="staffSelect" style="width:100%;">
                        <option value="">All Staff</option>
                        <?php foreach ($staffList as $sl): ?>
                            <option value="<?= $sl['id'] ?>" <?= $staffId == $sl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sl['full_name']) ?>
                                <?= $sl['employee_id'] ? ' — ' . htmlspecialchars($sl['employee_id']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Client -->
                <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:200px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Client</label>
                    <select name="client_id" id="clientSelect" style="width:100%;">
                        <option value="">All Clients</option>
                        <?php foreach ($clientList as $cl): ?>
                            <option value="<?= $cl['id'] ?>" <?= $clientId == $cl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cl['company_name']) ?>
                                <?= $cl['company_code'] ? ' — ' . htmlspecialchars($cl['company_code']) : '' ?>
                                <?= $cl['pan_number'] ? ' | PAN: ' . htmlspecialchars($cl['pan_number']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Branch -->
                <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:180px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Branch</label>
                    <select name="branch_id" id="branchSelect" style="width:100%;">
                        <option value="">All Branches</option>
                        <?php foreach ($branchList as $br): ?>
                            <option value="<?= $br['id'] ?>" <?= $branchId == $br['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($br['branch_name']) ?>
                                <?= $br['branch_code'] ? ' — ' . htmlspecialchars($br['branch_code']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Field Status — shown when type = all or field -->
                <div id="fieldStatusWrap" style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Field Status</label>
                    <select name="field_status" id="fieldStatusSelect" class="cn-input" style="width:145px;">
                        <option value="">All</option>
                        <option value="visited" <?= $fieldStatus === 'visited' ? 'selected' : '' ?>>✅ Visited</option>
                        <option value="missed" <?= $fieldStatus === 'missed' ? 'selected' : '' ?>>❌ Missed</option>
                        <option value="rescheduled" <?= $fieldStatus === 'rescheduled' ? 'selected' : '' ?>>🔄 Rescheduled
                        </option>
                    </select>
                </div>

                <!-- Office Status — shown when type = all or office -->
                <div id="officeStatusWrap" style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Office Status</label>
                    <select name="office_status" id="officeStatusSelect" class="cn-input" style="width:140px;">
                        <option value="">All</option>
                        <option value="not_started" <?= $officeStatus === 'not_started' ? 'selected' : '' ?>>▶️ Not Started
                        </option>
                        <option value="wip" <?= $officeStatus === 'wip' ? 'selected' : '' ?>>⏳ WIP</option>
                        <option value="holding" <?= $officeStatus === 'holding' ? 'selected' : '' ?>>⏸ Holding</option>
                        <option value="completed" <?= $officeStatus === 'completed' ? 'selected' : '' ?>>✔ Completed
                        </option>
                    </select>
                </div>

                <!-- Week -->
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Week</label>
                    <select name="week" class="cn-input" style="width:95px;">
                        <option value="">All</option>
                        <?php for ($w = 1; $w <= 5; $w++): ?>
                            <option value="<?= $w ?>" <?= $weekNum == $w ? 'selected' : '' ?>>Week <?= $w ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <button type="submit" class="cn-btn cn-btn-blue cn-btn-sm">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="log_list.php" class="cn-btn cn-btn-out cn-btn-sm">
                    <i class="fas fa-times"></i> Clear
                </a>

                <div style="margin-left:auto;display:flex;gap:6px;">
                    <a href="<?= APP_URL ?>/exports/export_excel.php?module=consulting_performance&view=who&month=<?= urlencode($month) ?>&staff_id=<?= $staffId ?>&client_id=<?= $clientId ?>&field_status=<?= urlencode($fieldStatus) ?>&office_status=<?= urlencode($officeStatus) ?>&week=<?= $weekNum ?>&log_type=<?= urlencode($logType) ?>"
                        class="cn-btn cn-btn-out cn-btn-sm">
                        <i class="fas fa-file-excel" style="color:#10b981;"></i> Excel
                    </a>
                    <a href="<?= APP_URL ?>/exports/export_pdf.php?module=consulting_performance&view=who&month=<?= urlencode($month) ?>&staff_id=<?= $staffId ?>&client_id=<?= $clientId ?>&field_status=<?= urlencode($fieldStatus) ?>&office_status=<?= urlencode($officeStatus) ?>&week=<?= $weekNum ?>&log_type=<?= urlencode($logType) ?>"
                        class="cn-btn cn-btn-out cn-btn-sm">
                        <i class="fas fa-file-pdf" style="color:#ef4444;"></i> PDF
                    </a>
                </div>
            </form>

            <!-- LOG TABLE -->
            <div class="cn-panel">
                <div style="padding:0;overflow-x:auto;">
                    <table class="cn-table w-100" id="logsTable">
                        <thead>
                            <tr>
                                <th style="width:40px;">Type</th>
                                <th style="width:100px;">Date</th>
                                <th style="width:130px;">Staff</th>
                                <th style="width:140px;">Client</th>
                                <th class="text-center" style="width:90px;">Time In</th>
                                <th class="text-center" style="width:90px;">Time Out</th>
                                <th class="text-center" style="width:70px;">Hours</th>
                                <th class="text-center" style="width:85px;">Status</th>
                                <th>Description</th>
                                <th style="width:100px;">Logged At</th>
                                <th style="width:50px;" class="text-center">View</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="10" style="text-align:center;color:#9ca3af;padding:30px;font-size:.83rem;">
                                        No logs found for the selected filters.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $l):
                                    $isOffice = ($l['log_source'] === 'office');
                                    $desc = $isOffice
                                        ? ($l['description'] ?? '')
                                        : ($l['work_description'] ?? '');
                                    $notes = $l['notes'] ?? '';

                                    $modalData = htmlspecialchars(json_encode([
                                        'source' => $l['log_source'],
                                        'log_date' => date('d M Y', strtotime($l['log_date'])),
                                        'day_of_week' => $l['day_of_week'] ?? '',
                                        'week_number' => $l['week_number'] ?? '',
                                        'full_name' => $l['full_name'],
                                        'employee_id' => $l['employee_id'] ?? '',
                                        'company_name' => $l['company_name'],
                                        'company_code' => $l['company_code'] ?? '',
                                        'pan_number' => $l['pan_number'] ?? '',
                                        'dept_name' => $l['dept_name'] ?? '',
                                        'branch_name' => $l['branch_name'] ?? '',
                                        'time_in' => $l['time_in'] ? date('h:i A', strtotime($l['time_in'])) : '—',
                                        'time_out' => $l['time_out'] ? date('h:i A', strtotime($l['time_out'])) : '—',
                                        'duration' => number_format((float) $l['duration_hours'], 2) . 'h',
                                        'visit_status' => $l['visit_status'] ?? '',
                                        'office_status' => $l['office_status'] ?? '',
                                        'description' => $desc,
                                        'rescheduled_to_entry_id' => $l['rescheduled_to_entry_id'] ?? '',
                                        'reschedule_date' => $l['reschedule_date'] ?? '',
                                        'reschedule_time_in' => $l['reschedule_time_in'] ?? '',
                                        'reschedule_time_out' => $l['reschedule_time_out'] ?? '',
                                        'reschedule_hours' => $l['reschedule_hours'] ?? '',
                                        'reschedule_notes' => $l['reschedule_notes'] ?? '',
                                        'notes' => $notes,
                                        'supervisor_name' => $l['supervisor_name'] ?? '',
                                        'created_at' => $l['created_at'] ?? '',
                                    ]), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($isOffice): ?>
                                                <span class="badge-office" title="Office Work"><i
                                                        class="fas fa-building"></i></span>
                                            <?php else: ?>
                                                <span class="badge-field" title="Field Visit"><i class="fas fa-car"></i></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong
                                                style="font-size:.82rem;"><?= date('d M Y', strtotime($l['log_date'])) ?></strong>
                                            <div style="font-size:.68rem;color:#9ca3af;"><?= $l['day_of_week'] ?? '' ?></div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;font-size:.82rem;">
                                                <?= htmlspecialchars($l['full_name']) ?>
                                            </div>
                                            <div style="font-size:.68rem;color:#9ca3af;">
                                                <?= htmlspecialchars($l['employee_id'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;font-size:.82rem;">
                                                <?= htmlspecialchars($l['company_name']) ?>
                                            </div>
                                            <div style="font-size:.68rem;color:#9ca3af;">
                                                <?= htmlspecialchars($l['company_code'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td class="text-center" style="font-size:.81rem;">
                                            <?= $l['time_in'] ? date('h:i A', strtotime($l['time_in'])) : '—' ?>
                                        </td>
                                        <td class="text-center" style="font-size:.81rem;">
                                            <?= $l['time_out'] ? date('h:i A', strtotime($l['time_out'])) : '—' ?>
                                        </td>
                                        <td class="text-center">
                                            <?= number_format((float) $l['duration_hours'], 1) ?>h
                                        </td>
                                        <td class="text-center">
                                            <?php if ($isOffice): ?>
                                                <?php if (($l['office_status'] ?? '') === 'not_started'): ?>
                                                    <span class="badge"
                                                        style="background:#fee2e2;color:#dc2626;border-radius:999px;padding:2px 9px;font-size:.68rem;font-weight:700;">
                                                        ▶️ Not Started
                                                    </span>
                                                <?php elseif (($l['office_status'] ?? '') === 'holding'): ?>
                                                    <span class="badge"
                                                        style="background:#ede9fe;color:#6d28d9;border-radius:999px;padding:2px 9px;font-size:.68rem;font-weight:700;">
                                                        ▶️ Holding
                                                    </span>
                                                <?php elseif (($l['office_status'] ?? '') === 'completed'): ?>
                                                    <span class="badge"
                                                        style="background:#d1fae5;color:#065f46;border-radius:999px;padding:2px 9px;font-size:.68rem;font-weight:700;">
                                                        <i class="fas fa-check"></i> Completed
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge"
                                                        style="background:#fef9c3;color:#92400e;border-radius:999px;padding:2px 9px;font-size:.68rem;font-weight:700;">
                                                        <i class="fas fa-spinner fa-spin"></i> WIP
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?= visitBadge($l['visit_status']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td
                                            style="font-size:.77rem;color:#6b7280;max-width:220px;word-wrap:break-word;white-space:normal;">
                                            <?= htmlspecialchars(mb_substr($desc, 0, 80)) . (mb_strlen($desc) > 80 ? '…' : '') ?>
                                        </td>
                                        <td style="font-size:.74rem;color:#9ca3af;">
                                            <?= $l['created_at'] ? date('d M Y, h:i A', strtotime($l['created_at'])) : '—' ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn-view-log" data-log='<?= $modalData ?>'
                                                title="View details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($logs)):
                            // Compute totals from filtered result set
                            $totHours = 0;
                            $totField = 0;
                            $totOffice = 0;
                            $totVisited = 0;
                            $totMissed = 0;
                            $totRescheduled = 0;
                            $totCompleted = 0;
                            $totWip = 0;
                            $totHolding = 0;
                            $totNotStarted = 0;
                            foreach ($logs as $l) {
                                $totHours += (float) $l['duration_hours'];
                                if ($l['log_source'] === 'office') {
                                    $totOffice++;
                                    if (($l['office_status'] ?? '') === 'completed')
                                        $totCompleted++;
                                    elseif (($l['office_status'] ?? '') === 'not_started')
                                        $totNotStarted++;
                                    elseif (($l['office_status'] ?? '') === 'holding')
                                        $totHolding++;
                                    else
                                        $totWip++;
                                } else {
                                    $totField++;
                                    if (($l['visit_status'] ?? '') === 'visited')
                                        $totVisited++;
                                    elseif (($l['visit_status'] ?? '') === 'missed')
                                        $totMissed++;
                                    else
                                        $totRescheduled++;
                                }
                            }
                            $totAll = count($logs);
                            ?>
                            <tfoot>
                                <tr style="background:#f8fafc;border-top:2px solid #e5e7eb;">
                                    <td colspan="10" style="padding:14px 18px;">
                                        <div style="display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;">

                                            <!-- Total rows -->
                                            <div style="display:flex;align-items:center;gap:6px;">
                                                <span
                                                    style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Total</span>
                                                <span
                                                    style="font-size:.85rem;font-weight:800;color:#1f2937;"><?= $totAll ?></span>
                                                <span style="font-size:.72rem;color:#9ca3af;">rows</span>
                                            </div>

                                            <span
                                                style="width:1px;height:18px;background:#e5e7eb;display:inline-block;"></span>

                                            <!-- Hours -->
                                            <div style="display:flex;align-items:center;gap:5px;">
                                                <i class="fas fa-clock" style="color:#c9a84c;font-size:.75rem;"></i>
                                                <span
                                                    style="font-size:.85rem;font-weight:800;color:#c9a84c;"><?= number_format($totHours, 1) ?>h</span>
                                                <span style="font-size:.7rem;color:#9ca3af;">total</span>
                                            </div>

                                            <span
                                                style="width:1px;height:18px;background:#e5e7eb;display:inline-block;"></span>

                                            <!-- Field breakdown -->
                                            <?php if ($totField > 0): ?>
                                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                                    <span style="display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:#1d4ed8;
                                 font-size:.7rem;font-weight:700;padding:3px 8px;border-radius:99px;">
                                                        <i class="fas fa-car" style="font-size:.6rem;"></i> <?= $totField ?>
                                                        Field
                                                    </span>
                                                    <?php if ($totVisited > 0): ?>
                                                        <span style="display:inline-flex;align-items:center;gap:3px;background:#dcfce7;color:#166534;
                                 font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:99px;">
                                                            <i class="fas fa-check" style="font-size:.55rem;"></i>
                                                            <?= $totVisited ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($totMissed > 0): ?>
                                                        <span style="display:inline-flex;align-items:center;gap:3px;background:#fee2e2;color:#dc2626;
                                 font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:99px;">
                                                            <i class="fas fa-times" style="font-size:.55rem;"></i> <?= $totMissed ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($totRescheduled > 0): ?>
                                                        <span style="display:inline-flex;align-items:center;gap:3px;background:#fef9c3;color:#854d0e;
                                 font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:99px;">
                                                            <i class="fas fa-redo" style="font-size:.55rem;"></i>
                                                            <?= $totRescheduled ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <span
                                                    style="width:1px;height:18px;background:#e5e7eb;display:inline-block;"></span>
                                            <?php endif; ?>

                                            <!-- Office breakdown -->
                                            <?php if ($totOffice > 0): ?>
                                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                                    <span style="display:inline-flex;align-items:center;gap:4px;background:#fce7f3;color:#be185d;
                                 font-size:.7rem;font-weight:700;padding:3px 8px;border-radius:99px;">
                                                        <i class="fas fa-building" style="font-size:.6rem;"></i>
                                                        <?= $totOffice ?> Office
                                                    </span>
                                                    <?php if ($totCompleted > 0): ?>
                                                        <span style="display:inline-flex;align-items:center;gap:3px;background:#dcfce7;color:#166534;
                                 font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:99px;">
                                                            <i class="fas fa-check-double" style="font-size:.55rem;"></i>
                                                            <?= $totCompleted ?> Done
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($totWip > 0): ?>
                                                        <span style="display:inline-flex;align-items:center;gap:3px;background:#eff6ff;color:#1d4ed8;
                                 font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:99px;">
                                                            <i class="fas fa-spinner" style="font-size:.55rem;"></i> <?= $totWip ?>
                                                            WIP
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($totHolding > 0): ?>
                                                        <span style="display:inline-flex;align-items:center;gap:3px;background:#ede9fe;color:#6d28d9;
                                 font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:99px;">
                                                            <i class="fas fa-pause" style="font-size:.55rem;"></i>
                                                            <?= $totHolding ?> Hold
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($totNotStarted > 0): ?>
                                                        <span style="display:inline-flex;align-items:center;gap:3px;background:#fee2e2;color:#dc2626;
                                 font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:99px;">
                                                            <i class="fas fa-clock" style="font-size:.55rem;"></i>
                                                            <?= $totNotStarted ?> Not Started
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <?php if (!empty($logs)): ?>
                    <!-- Summary bar below table -->
                    <div style="
    display:flex; flex-wrap:wrap; gap:8px 12px;
    padding:12px 18px;
    border-top:1px solid #f3f4f6;
    background:#f8fafc;
    border-radius:0 0 10px 10px;
    align-items:center;
">
                        <!-- Record count -->
                        <div style="display:inline-flex;align-items:center;gap:5px;
                background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:4px 10px;">
                            <i class="fas fa-table-list" style="color:#c9a84c;font-size:.72rem;"></i>
                            <span style="font-size:.78rem;font-weight:800;color:#1f2937;"><?= $totAll ?></span>
                            <span style="font-size:.7rem;color:#9ca3af;">record<?= $totAll != 1 ? 's' : '' ?></span>
                        </div>

                        <!-- Hours -->
                        <div style="display:inline-flex;align-items:center;gap:5px;
                background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:4px 10px;">
                            <i class="fas fa-clock" style="color:#c9a84c;font-size:.72rem;"></i>
                            <span
                                style="font-size:.78rem;font-weight:800;color:#c9a84c;"><?= number_format($totHours, 1) ?>h</span>
                        </div>

                        <?php if ($totField > 0): ?>
                            <!-- Field pills -->
                            <div style="display:inline-flex;align-items:center;gap:5px;flex-wrap:wrap;">
                                <span style="display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:#1d4ed8;
                     font-size:.72rem;font-weight:700;padding:4px 10px;border-radius:8px;">
                                    <i class="fas fa-car" style="font-size:.62rem;"></i> <?= $totField ?> Field
                                </span>
                                <?php if ($totVisited > 0): ?>
                                    <span style="display:inline-flex;align-items:center;gap:3px;background:#dcfce7;color:#166534;
                     font-size:.7rem;font-weight:700;padding:3px 8px;border-radius:6px;">
                                        <i class="fas fa-check" style="font-size:.58rem;"></i> <?= $totVisited ?> Visited
                                    </span>
                                <?php endif; ?>
                                <?php if ($totMissed > 0): ?>
                                    <span style="display:inline-flex;align-items:center;gap:3px;background:#fee2e2;color:#dc2626;
                     font-size:.7rem;font-weight:700;padding:3px 8px;border-radius:6px;">
                                        <i class="fas fa-times" style="font-size:.58rem;"></i> <?= $totMissed ?> Missed
                                    </span>
                                <?php endif; ?>
                                <?php if ($totRescheduled > 0): ?>
                                    <span style="display:inline-flex;align-items:center;gap:3px;background:#fef9c3;color:#854d0e;
                     font-size:.7rem;font-weight:700;padding:3px 8px;border-radius:6px;">
                                        <i class="fas fa-redo" style="font-size:.58rem;"></i> <?= $totRescheduled ?> Rescheduled
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($totOffice > 0): ?>
                            <!-- Office pills -->
                            <div style="display:inline-flex;align-items:center;gap:5px;flex-wrap:wrap;">
                                <span style="display:inline-flex;align-items:center;gap:4px;background:#fce7f3;color:#be185d;
                     font-size:.72rem;font-weight:700;padding:4px 10px;border-radius:8px;">
                                    <i class="fas fa-building" style="font-size:.62rem;"></i> <?= $totOffice ?> Office
                                </span>
                                <?php if ($totCompleted > 0): ?>
                                    <span style="display:inline-flex;align-items:center;gap:3px;background:#dcfce7;color:#166534;
                     font-size:.7rem;font-weight:700;padding:3px 8px;border-radius:6px;">
                                        <i class="fas fa-check-double" style="font-size:.58rem;"></i> <?= $totCompleted ?> Done
                                    </span>
                                <?php endif; ?>
                                <?php if ($totWip > 0): ?>
                                    <span style="display:inline-flex;align-items:center;gap:3px;background:#eff6ff;color:#1d4ed8;
                     font-size:.7rem;font-weight:700;padding:3px 8px;border-radius:6px;">
                                        <i class="fas fa-spinner" style="font-size:.58rem;"></i> <?= $totWip ?> WIP
                                    </span>
                                <?php endif; ?>
                                <?php if ($totHolding > 0): ?>
                                    <span style="display:inline-flex;align-items:center;gap:3px;background:#ede9fe;color:#6d28d9;
                     font-size:.7rem;font-weight:700;padding:3px 8px;border-radius:6px;">
                                        <i class="fas fa-pause" style="font-size:.58rem;"></i> <?= $totHolding ?> Holding
                                    </span>
                                <?php endif; ?>
                                <?php if ($totNotStarted > 0): ?>
                                    <span style="display:inline-flex;align-items:center;gap:3px;background:#fee2e2;color:#dc2626;
                     font-size:.7rem;font-weight:700;padding:3px 8px;border-radius:6px;">
                                        <i class="fas fa-clock" style="font-size:.58rem;"></i> <?= $totNotStarted ?> Not Started
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /cn-wrap -->
    </div><!-- /main-content -->
</div><!-- /app-wrapper -->

<!-- ══════════════════════════════════════════
     LOG DETAIL MODAL
══════════════════════════════════════════ -->
<div class="log-modal-overlay" id="logModal">
    <div class="log-modal" role="dialog" aria-modal="true" aria-labelledby="modalLogTitle">

        <div class="log-modal-header">
            <div class="log-modal-icon" id="modalIcon">
                <i class="fas fa-car"></i>
            </div>
            <div>
                <div class="log-modal-title" id="modalLogTitle">Log Details</div>
                <div class="log-modal-sub" id="modalLogSub"></div>
            </div>
            <button class="log-modal-close" id="modalClose" aria-label="Close">&times;</button>
        </div>

        <div class="log-modal-body">

            <!-- 2-col detail grid -->
            <div class="log-detail-grid" id="modalGrid">
                <!-- injected by JS -->
            </div>
            <div id="modalRescheduleWrap" style="display:none;margin-top:15px;">
                <div style="
        background:#fffbeb;
        border:1px solid #fde68a;
        border-radius:10px;
        padding:12px;
    ">
                    <div style="
            font-size:.8rem;
            font-weight:700;
            color:#b45309;
            margin-bottom:10px;
        ">
                        <i class="fas fa-redo me-1"></i>
                        Rescheduled Details
                    </div>

                    <div id="modalRescheduleContent"></div>
                </div>
            </div>
            <!-- Description -->
            <div class="log-detail-item full mt-3" id="modalDescWrap">
                <label>Description / Work Done</label>
                <div class="log-desc-box" id="modalDesc"></div>
            </div>

            <!-- Notes -->
            <div class="log-detail-item full mt-3" id="modalNotesWrap" style="display:none;">
                <label>Notes</label>
                <div class="log-desc-box" id="modalNotes"></div>
            </div>

        </div>

        <div class="log-modal-footer">
            <button class="cn-btn cn-btn-out cn-btn-sm" id="modalCloseBtn">
                <i class="fas fa-times me-1"></i> Close
            </button>
        </div>

    </div>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {

        /* ── Show/hide status dropdowns based on log_type ── */
        const logTypeSelect = document.querySelector('select[name="log_type"]');
        const fieldStatusWrap = document.getElementById('fieldStatusWrap');
        const officeStatusWrap = document.getElementById('officeStatusWrap');

        function syncStatusDropdowns() {
            const val = logTypeSelect ? logTypeSelect.value : '';
            if (fieldStatusWrap) fieldStatusWrap.style.display = (val === 'office') ? 'none' : 'flex';
            if (officeStatusWrap) officeStatusWrap.style.display = (val === 'field') ? 'none' : 'flex';
        }

        if (logTypeSelect) {
            logTypeSelect.addEventListener('change', syncStatusDropdowns);
            syncStatusDropdowns(); // run on page load to respect current filter
        }

        /* ── Tom Select: Staff ── */
        new TomSelect('#staffSelect', {
            placeholder: 'Search by name or code…',
            maxOptions: 100,
            allowEmptyOption: true,
            searchField: ['text'],
            render: {
                option: function (data, escape) {
                    var parts = data.text.split(' — ');
                    var name = parts[0] ? escape(parts[0].trim()) : escape(data.text);
                    var code = parts[1] ? parts[1].trim() : '';
                    return '<div style="padding:6px 10px;line-height:1.4;">' +
                        '<div style="font-weight:600;font-size:.82rem;">' + name + '</div>' +
                        (code ? '<div style="font-size:.7rem;color:#6b7280;">Code: ' + escape(code) + '</div>' : '') +
                        '</div>';
                },
                item: function (data, escape) { return '<div>' + escape(data.text) + '</div>'; }
            }
        });

        /* ── Tom Select: Client ── */
        new TomSelect('#clientSelect', {
            placeholder: 'Search by name, code or PAN…',
            maxOptions: 200,
            allowEmptyOption: true,
            searchField: ['text'],
            render: {
                option: function (data, escape) {
                    var raw = data.text;
                    var name = raw.split(' — ')[0].trim();
                    var rest = raw.includes(' — ') ? raw.split(' — ').slice(1).join(' — ').trim() : '';
                    var code = '', pan = '';
                    if (rest) { var ps = rest.split(' | PAN: '); code = ps[0].trim(); pan = ps[1] ? ps[1].trim() : ''; }
                    return '<div style="padding:6px 10px;line-height:1.4;">' +
                        '<div style="font-weight:600;font-size:.82rem;">' + escape(name) + '</div>' +
                        '<div style="font-size:.7rem;color:#6b7280;">' +
                        (code ? '<span style="margin-right:8px;">Code: ' + escape(code) + '</span>' : '') +
                        (pan ? '<span>PAN: ' + escape(pan) + '</span>' : '') +
                        '</div></div>';
                },
                item: function (data, escape) {
                    return '<div>' + escape(data.text.split(' — ')[0].trim()) + '</div>';
                }
            }
        });

        /* ── DataTable ── */
        if ($('#logsTable tbody td').length > 1) {
            $('#logsTable').DataTable({
                order: [[1, 'desc']],
                pageLength: 25,
                language: { search: 'Search logs:' }
            });
        }

        /* ══════════════════════════════════════════
           LOG DETAIL MODAL
        ══════════════════════════════════════════ */
        const overlay = document.getElementById('logModal');
        const icon = document.getElementById('modalIcon');
        const title = document.getElementById('modalLogTitle');
        const sub = document.getElementById('modalLogSub');
        const grid = document.getElementById('modalGrid');
        const descBox = document.getElementById('modalDesc');
        const descWrap = document.getElementById('modalDescWrap');
        const notesBox = document.getElementById('modalNotes');
        const notesWrap = document.getElementById('modalNotesWrap');
        const rescheduleWrap =
            document.getElementById('modalRescheduleWrap');

        const rescheduleContent =
            document.getElementById('modalRescheduleContent');

        function openModal(data) {
            const isOffice = data.source === 'office';

            /* Icon & type label */
            icon.className = 'log-modal-icon ' + data.source;
            icon.innerHTML = isOffice ? '<i class="fas fa-building"></i>' : '<i class="fas fa-car"></i>';

            /* Title */
            title.textContent = isOffice ? 'Office Work Log' : 'Field Visit Log';
            sub.textContent = data.log_date + (data.day_of_week ? ' · ' + data.day_of_week : '')
                + (data.week_number ? ' · Week ' + data.week_number : '');

            /* Grid items */
            const statusLabel = isOffice
                ? (data.office_status === 'completed' ? '✔ Completed' : data.office_status === 'wip' ? '⏳ WIP' : data.office_status === 'holding' ? '⏸ Holding' : data.office_status === 'not_started' ? '▶️ Not Started' : '—')
                : (data.visit_status
                    ? data.visit_status.charAt(0).toUpperCase() + data.visit_status.slice(1)
                    : '—');

            const items = [
                { label: 'Staff', val: data.full_name + (data.employee_id ? ' (' + data.employee_id + ')' : '') },
                { label: 'Supervisor', val: data.supervisor_name || '—' },
                { label: 'Client', val: data.company_name + (data.company_code ? ' — ' + data.company_code : '') },
                { label: 'PAN', val: data.pan_number || '—' },
                { label: 'Department', val: data.dept_name || '—' },
                { label: 'Branch', val: data.branch_name || '—' },
                { label: 'Date', val: data.log_date },
                { label: 'Time In', val: data.time_in || '—' },
                { label: 'Time Out', val: data.time_out || '—' },
                { label: 'Duration', val: data.duration },
                { label: 'Logged At', val: data.created_at ? data.created_at : '—' },
                { label: isOffice ? 'Work Status' : 'Visit Status', val: statusLabel },
            ];

            grid.innerHTML = items.map(function (item) {
                return '<div class="log-detail-item">' +
                    '<label>' + item.label + '</label>' +
                    '<div class="val">' + escapeHtml(item.val) + '</div>' +
                    '</div>';
            }).join('');

            /* Description */
            if (data.description && data.description.trim()) {
                descBox.textContent = data.description;
                descWrap.style.display = '';
            } else {
                descWrap.style.display = 'none';
            }

            /* Notes */
            if (data.notes && data.notes.trim()) {
                notesBox.textContent = data.notes;
                notesWrap.style.display = '';
            } else {
                notesWrap.style.display = 'none';
            }
            if (
                data.visit_status === 'rescheduled' &&
                data.reschedule_date
            ) {
                rescheduleContent.innerHTML =
                    '<div class="log-detail-grid">' +

                    '<div class="log-detail-item">' +
                    '<label>New Visit Date</label>' +
                    '<div class="val">' + escapeHtml(data.reschedule_date) + '</div>' +
                    '</div>' +

                    '<div class="log-detail-item">' +
                    '<label>Planned Hours</label>' +
                    '<div class="val">' + escapeHtml(data.reschedule_hours || '-') + 'h</div>' +
                    '</div>' +

                    '<div class="log-detail-item">' +
                    '<label>Time In</label>' +
                    '<div class="val">' +
                    (data.reschedule_time_in
                        ? new Date('2000-01-01 ' + data.reschedule_time_in)
                            .toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true })
                        : '-') +
                    '</div>' +
                    '</div>' +

                    '<div class="log-detail-item">' +
                    '<label>Time Out</label>' +
                    '<div class="val">' +
                    (data.reschedule_time_out
                        ? new Date('2000-01-01 ' + data.reschedule_time_out)
                            .toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true })
                        : '-') +
                    '</div>' +
                    '</div>' +

                    '</div>' +

                    (data.reschedule_notes ?
                        '<div style="margin-top:10px;">' +
                        '<label style="font-size:.7rem;color:#9ca3af;">Notes</label>' +
                        '<div class="log-desc-box">' +
                        escapeHtml(data.reschedule_notes) +
                        '</div></div>'
                        : '');

                rescheduleWrap.style.display = '';
            } else {
                rescheduleWrap.style.display = 'none';
            }
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        /* Attach open listener to all view buttons */
        document.querySelectorAll('.btn-view-log').forEach(function (btn) {
            btn.addEventListener('click', function () {
                try {
                    var data = JSON.parse(this.getAttribute('data-log'));
                    openModal(data);
                } catch (e) {
                    console.error('Failed to parse log data', e);
                }
            });
        });

        document.getElementById('modalClose').addEventListener('click', closeModal);
        document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
    });
    new TomSelect('#branchSelect', {
        placeholder: 'Search branch…',
        maxOptions: 100,
        allowEmptyOption: true,
        searchField: ['text'],
        render: {
            option: function (data, escape) {
                var parts = data.text.split(' — ');
                var name = escape(parts[0].trim());
                var code = parts[1] ? parts[1].trim() : '';
                return '<div style="padding:6px 10px;line-height:1.4;">' +
                    '<div style="font-weight:600;font-size:.82rem;">' + name + '</div>' +
                    (code ? '<div style="font-size:.7rem;color:#6b7280;">Code: ' + escape(code) + '</div>' : '') +
                    '</div>';
            },
            item: function (data, escape) { return '<div>' + escape(data.text.split(' — ')[0].trim()) + '</div>'; }
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>