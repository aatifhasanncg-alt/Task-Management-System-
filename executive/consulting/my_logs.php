<?php
/**
 * consulting/staff/log_list.php — Staff: My Logs (Visit + Office)
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

// ── Dept resolution (same as before) ─────────────────────────
$__deptMeta = $db->prepare("
    SELECT d.dept_code, d.dept_name
    FROM departments d
    WHERE d.id = ?
");
$__deptMeta->execute([$user['department_id']]);
$__deptMeta = $__deptMeta->fetch(PDO::FETCH_ASSOC);
$__primaryDeptCode = $__deptMeta['dept_code'] ?? '';
$__primaryDeptName = $__deptMeta['dept_name'] ?? '';

$__isConsultingPrimary = ($__primaryDeptCode === 'CON'
    || stripos($__primaryDeptName, 'consult') !== false);

$__udaConsStmt = $db->prepare("
    SELECT d.id, d.dept_code FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ? AND (d.dept_code = 'CON'
        OR d.dept_name LIKE '%consult%')
    LIMIT 1
");
$__udaConsStmt->execute([$uid]);
$__udaConsDept = $__udaConsStmt->fetch(PDO::FETCH_ASSOC);

if ($__isConsultingPrimary) {
    $deptId = (int) $user['department_id'];
} elseif ($__udaConsDept) {
    $deptId = (int) $__udaConsDept['id'];
} else {
    $deptId = (int) $user['department_id'];
}

// ── Filters ───────────────────────────────────────────────────
$now = new DateTime();
$month = $_GET['month'] ?? $now->format('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = $now->format('Y-m');
}

$monthDate = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: $now;
$prevMonth = (clone $monthDate)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthDate)->modify('+1 month')->format('Y-m');
$monthLabel = $monthDate->format('F Y');

// log_type: '' = all, 'visit' = visit logs, 'office' = office logs
$filterType = $_GET['log_type'] ?? '';
$filterStatus = $_GET['status'] ?? '';   // visit: visited|missed  /  office: not_started|wip|holding|completed

if (!in_array($filterType, ['', 'visit', 'office']))
    $filterType = '';

// ── Fetch VISIT logs ──────────────────────────────────────────
$visitLogs = [];
if ($filterType === '' || $filterType === 'visit') {
    $vWhere = ['wl.user_id = ?', 'wl.month_year = ?']; // ← removed department_id filter
    $vParams = [$uid, $month];

    if ($filterStatus && in_array($filterStatus, ['visited', 'missed', 'rescheduled'])) {
        $vWhere[] = 'wl.visit_status = ?';
        $vParams[] = $filterStatus;
    }

    $vSql = "
        SELECT wl.id, wl.log_date, wl.time_in, wl.time_out,
               wl.duration_hours, wl.visit_status AS status_key,
               wl.work_description AS description,
               NULL AS notes,
               c.company_name, c.company_code,
               'visit' AS log_type,
               wl.day_of_week,
               sv.full_name AS supervisor_name
        FROM work_logs wl
        LEFT JOIN companies c  ON c.id  = wl.client_id
        LEFT JOIN users     sv ON sv.id = wl.supervisor_id
        WHERE " . implode(' AND ', $vWhere) . "
        ORDER BY wl.log_date DESC, wl.time_in DESC
    ";
    $vSt = $db->prepare($vSql);
    $vSt->execute($vParams);
    $visitLogs = $vSt->fetchAll();
}

// ── Fetch OFFICE logs ─────────────────────────────────────────
$officeLogs = [];
if ($filterType === '' || $filterType === 'office') {
    $oWhere = ['owl.user_id = ?', "DATE_FORMAT(owl.log_date,'%Y-%m') = ?"];
    $oParams = [$uid, $month];

    if ($filterStatus && in_array($filterStatus, ['not_started', 'wip', 'holding', 'completed'])) {
        $oWhere[] = 'owl.status = ?';
        $oParams[] = $filterStatus;
    }

    $oSql = "
        SELECT owl.id, owl.log_date, owl.time_in, owl.time_out,
               ROUND(TIME_TO_SEC(TIMEDIFF(owl.time_out, owl.time_in)) / 3600, 2) AS duration_hours,
               owl.status AS status_key,
               owl.description,
               owl.notes,
               c.company_name, c.company_code,
               'office' AS log_type,
               NULL AS day_of_week
        FROM office_work_logs owl
        JOIN companies c ON c.id = owl.client_id
        WHERE " . implode(' AND ', $oWhere) . "
        ORDER BY owl.log_date DESC, owl.time_in DESC
    ";
    $oSt = $db->prepare($oSql);
    $oSt->execute($oParams);
    $officeLogs = $oSt->fetchAll();
}

// ── Merge + sort ──────────────────────────────────────────────
$allLogs = array_merge($visitLogs, $officeLogs);
usort(
    $allLogs,
    fn($a, $b) =>
    strcmp($b['log_date'] . $b['time_in'], $a['log_date'] . $a['time_in'])
);

// ── KPIs (always unfiltered for the month) ────────────────────
// Visit KPIs
$vKpi = $db->prepare("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(duration_hours), 0) AS hours,
        SUM(visit_status='visited')     AS visited,
        SUM(visit_status='missed')      AS missed,
        SUM(visit_status='rescheduled') AS rescheduled
    FROM work_logs
    WHERE user_id = ? AND month_year = ?
"); // ← removed department_id = ?
$vKpi->execute([$uid, $month]); // ← removed $deptId
$vKpi = $vKpi->fetch();

// Office KPIs
$oKpi = $db->prepare("
    SELECT
        COUNT(*) AS total,
        ROUND(SUM(TIME_TO_SEC(TIMEDIFF(time_out,time_in)))/3600,2) AS hours,
        sum(status='not_started') AS not_started,
        SUM(status='wip')       AS wip,
        SUM(status='holding')   AS holding,
        SUM(status='completed') AS completed
    FROM office_work_logs
    WHERE user_id=? AND DATE_FORMAT(log_date,'%Y-%m')=?
");
$oKpi->execute([$uid, $month]);
$oKpi = $oKpi->fetch();

$totalLogs = ($vKpi['total'] ?? 0) + ($oKpi['total'] ?? 0);
$totalHours = round(($vKpi['hours'] ?? 0) + ($oKpi['hours'] ?? 0), 1);

// ── Status meta ───────────────────────────────────────────────
$visitStatusMeta = [
    'visited' => ['label' => 'Visited', 'color' => '#10b981', 'bg' => '#f0fdf4', 'icon' => 'fa-check-circle'],
    'missed' => ['label' => 'Missed', 'color' => '#ef4444', 'bg' => '#fef2f2', 'icon' => 'fa-times-circle'],
    'rescheduled' => ['label' => 'Rescheduled', 'color' => '#f59e0b', 'bg' => '#fffbeb', 'icon' => 'fa-calendar-alt'],
];
$officeStatusMeta = [
    'not_started' => ['label' => 'Not Started', 'color' => '#6b7280', 'bg' => '#f3f4f6', 'icon' => 'fa-clock'],
    'wip' => ['label' => 'WIP', 'color' => '#3b82f6', 'bg' => '#eff6ff', 'icon' => 'fa-spinner'],
    'holding' => ['label' => 'Holding', 'color' => '#6b7280', 'bg' => '#f3f4f6', 'icon' => 'fa-pause-circle'],
    'completed' => ['label' => 'Completed', 'color' => '#10b981', 'bg' => '#f0fdf4', 'icon' => 'fa-check-circle'],
];

// All statuses shown in filter depend on active type
$statusFilters = [];
if ($filterType === '' || $filterType === 'visit') {
    $statusFilters += $visitStatusMeta;
}
if ($filterType === '' || $filterType === 'office') {
    $statusFilters += $officeStatusMeta;
}
// deduplicate completed if both types shown
if ($filterType === '') {
    $statusFilters = [
        'visited' => $visitStatusMeta['visited'],
        'missed' => $visitStatusMeta['missed'],
        'rescheduled' => $visitStatusMeta['rescheduled'],
        'not_started' => $officeStatusMeta['not_started'],
        'wip' => $officeStatusMeta['wip'],
        'holding' => $officeStatusMeta['holding'],
        'completed' => $officeStatusMeta['completed'],
    ];
}

$pageTitle = 'My Logs';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="<?= APP_URL ?>/staff/planning/consulting.css">

<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<style>
    /* DataTables pagination fix */
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

    .dataTables_wrapper .dataTables_paginate .paginate_button.disabled,
    .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
        background: #f9fafb !important;
        border-color: #e5e7eb !important;
        color: #d1d5db !important;
        cursor: not-allowed;
    }

    .dataTables_wrapper .dataTables_filter input {
        border: 1.5px solid #e5e7eb;
        border-radius: 6px;
        padding: 5px 10px;
        font-size: .8rem;
        outline: none;
        margin-left: 6px;
    }

    .dataTables_wrapper .dataTables_filter input:focus {
        border-color: #c9a84c;
    }

    .dataTables_wrapper .dataTables_length select {
        border: 1.5px solid #e5e7eb;
        border-radius: 6px;
        padding: 4px 8px;
        font-size: .8rem;
        margin: 0 4px;
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

    .cn-table {
        table-layout: auto;
        word-break: break-word;
    }

    .cn-panel {
        overflow: hidden;
    }

    /* Type toggle pills */
    .type-toggle {
        display: inline-flex;
        background: #f3f4f6;
        border-radius: 10px;
        padding: 3px;
        gap: 2px;
    }

    .type-toggle a {
        font-size: .78rem;
        font-weight: 700;
        padding: .3rem .85rem;
        border-radius: 8px;
        text-decoration: none;
        color: #6b7280;
        transition: all .15s;
    }

    .type-toggle a.active-type {
        background: #fff;
        color: #1f2937;
        box-shadow: 0 1px 4px rgba(0, 0, 0, .08);
    }

    .type-toggle a.active-visit {
        color: #10b981;
    }

    .type-toggle a.active-office {
        color: #3b82f6;
    }

    .type-toggle a.active-all {
        color: #c9a84c;
    }

    /* Log type badge */
    .log-type-badge-visit {
        background: #f0fdf4;
        color: #10b981;
    }

    .log-type-badge-office {
        background: #eff6ff;
        color: #3b82f6;
    }

    .log-type-badge {
        font-size: .65rem;
        font-weight: 700;
        padding: .15rem .45rem;
        border-radius: 4px;
        white-space: nowrap;
    }
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <!-- ── PAGE HERO ── -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge">
                            <i class="fas fa-history"></i> Logs
                        </div>
                        <h4>My Logs</h4>
                        <p><?= htmlspecialchars($user['full_name']) ?> · <?= $monthLabel ?></p>
                    </div>

                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="month" class="form-control form-control-sm" style="width:155px;"
                            value="<?= $month ?>"
                            onchange="location='?month='+this.value+'&log_type=<?= $filterType ?>&status=<?= $filterStatus ?>'">

                        <a href="log_create.php?month=<?= $month ?>" class="btn-gold btn btn-sm">
                            <i class="fas fa-plus me-1"></i> Log Visit
                        </a>
                        <a href="office_log_create.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-plus me-1"></i> Log Office Work
                        </a>
                        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- ── KPI ROW ── -->
            <div class="kpi-row mb-4">
                <div class="kpi-tile" style="--kpi-color:#c9a84c;">
                    <div class="kpi-icon"><i class="fas fa-list" style="color:#c9a84c;"></i></div>
                    <div class="kpi-val"><?= $totalLogs ?></div>
                    <div class="kpi-label">Total Logs</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#8b5cf6;">
                    <div class="kpi-icon"><i class="fas fa-clock" style="color:#8b5cf6;"></i></div>
                    <div class="kpi-val"><?= number_format($totalHours, 1) ?>h</div>
                    <div class="kpi-label">Total Hours</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#10b981;">
                    <div class="kpi-icon"><i class="fas fa-car" style="color:#10b981;"></i></div>
                    <div class="kpi-val"><?= $vKpi['total'] ?? 0 ?></div>
                    <div class="kpi-label">Visit Logs</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#3b82f6;">
                    <div class="kpi-icon"><i class="fas fa-building" style="color:#3b82f6;"></i></div>
                    <div class="kpi-val"><?= $oKpi['total'] ?? 0 ?></div>
                    <div class="kpi-label">Office Logs</div>
                </div>
            </div>

            <!-- ── FILTERS ── -->
            <div class="cn-panel mb-3">
                <div style="padding:12px 16px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;">

                    <!-- Log type toggle -->
                    <div class="type-toggle">
                        <?php
                        $typeLinks = [
                            '' => ['label' => 'All', 'icon' => 'fa-layer-group', 'cls' => 'active-all'],
                            'visit' => ['label' => 'Visit', 'icon' => 'fa-car', 'cls' => 'active-visit'],
                            'office' => ['label' => 'Office', 'icon' => 'fa-building', 'cls' => 'active-office'],
                        ];
                        foreach ($typeLinks as $tKey => $tMeta):
                            $isActive = ($filterType === $tKey);
                            ?>
                            <a href="?month=<?= $month ?>&log_type=<?= $tKey ?>"
                                class="<?= $isActive ? 'active-type ' . $tMeta['cls'] : '' ?>">
                                <i class="fas <?= $tMeta['icon'] ?> me-1"></i><?= $tMeta['label'] ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div style="width:1px;height:22px;background:#e5e7eb;"></div>

                    <!-- Status pills -->
                    <span style="font-size:.78rem;font-weight:600;color:#9ca3af;">Status:</span>

                    <a href="?month=<?= $month ?>&log_type=<?= $filterType ?>" style="font-size:.75rem;padding:.25rem .7rem;border-radius:99px;text-decoration:none;
                              border:1.5px solid <?= !$filterStatus ? '#c9a84c' : '#e5e7eb' ?>;
                              background:<?= !$filterStatus ? '#fffbeb' : '#fff' ?>;
                              color:<?= !$filterStatus ? '#c9a84c' : '#6b7280' ?>;font-weight:600;">
                        All
                    </a>

                    <?php foreach ($statusFilters as $sKey => $sm): ?>
                        <a href="?month=<?= $month ?>&log_type=<?= $filterType ?>&status=<?= $sKey ?>" style="font-size:.75rem;padding:.25rem .7rem;border-radius:99px;text-decoration:none;
                              border:1.5px solid <?= $filterStatus === $sKey ? $sm['color'] : '#e5e7eb' ?>;
                              background:<?= $filterStatus === $sKey ? $sm['bg'] : '#fff' ?>;
                              color:<?= $filterStatus === $sKey ? $sm['color'] : '#6b7280' ?>;font-weight:600;">
                            <i class="fas <?= $sm['icon'] ?> me-1"></i><?= $sm['label'] ?>
                        </a>
                    <?php endforeach; ?>

                </div>
            </div>

            <!-- ── LOG TABLE ── -->
            <div class="cn-panel">
                <div class="cn-panel-hd">
                    <span class="cn-panel-title">
                        <i class="fas fa-table me-2" style="color:var(--gold)"></i>
                        <?php if ($filterType === 'visit'): ?>
                            Visit Logs
                        <?php elseif ($filterType === 'office'): ?>
                            Office Work Logs
                        <?php else: ?>
                            All Logs
                        <?php endif; ?>
                        — <?= $monthLabel ?>
                        <span style="font-size:.75rem;font-weight:500;color:#9ca3af;margin-left:6px;">
                            (<?= count($allLogs) ?> records)
                        </span>
                    </span>
                </div>

                <?php if (empty($allLogs)): ?>
                    <div class="card-mis-body">
                        <div style="padding:40px;text-align:center;color:#9ca3af;">
                            <i class="fas fa-history" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
                            <div style="font-size:.85rem;font-weight:600;margin-bottom:4px;">
                                No logs for <?= $monthLabel ?>
                            </div>
                            <div style="font-size:.78rem;margin-bottom:12px;">
                                <?= ($filterStatus || $filterType) ? 'Try clearing the filters or start' : 'Start' ?>
                                logging your work.
                            </div>
                            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                                <a href="log_create.php?month=<?= $month ?>" class="cn-btn cn-btn-gold"
                                    style="display:inline-flex;">
                                    <i class="fas fa-plus me-2"></i>Log Visit
                                </a>
                                <a href="office_log_create.php" class="cn-btn cn-btn-out" style="display:inline-flex;">
                                    <i class="fas fa-plus me-2"></i>Log Office Work
                                </a>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <div style="padding:0;overflow-x:auto;">
                        <table class="cn-table w-100" id="logsTable">
                            <thead>
                                <tr>
                                    <th style="width:110px;">Date</th>
                                    <th style="width:90px;">Type</th>
                                    <th>Client</th>
                                    <th style="width:120px;">Supervisor</th>
                                    <th class="text-center" style="width:88px;">Time In</th>
                                    <th class="text-center" style="width:88px;">Time Out</th>
                                    <th class="text-center" style="width:65px;">Hours</th>
                                    <th class="text-center" style="width:95px;">Status</th>
                                    <th style="width:200px;">Description</th>
                                    <th class="text-center" style="width:80px;">Action</th>
                                </tr>
                                </thead>
                            <tbody>
                                <?php foreach ($allLogs as $l):
                                    $isVisit = ($l['log_type'] === 'visit');
                                    $statusKey = $l['status_key'] ?? '';
                                    if ($isVisit) {
                                        $sm = $visitStatusMeta[$statusKey] ?? ['label' => $statusKey, 'color' => '#9ca3af', 'bg' => '#f9fafb', 'icon' => 'fa-circle'];
                                    } else {
                                        $sm = $officeStatusMeta[$statusKey] ?? ['label' => $statusKey, 'color' => '#9ca3af', 'bg' => '#f9fafb', 'icon' => 'fa-circle'];
                                    }
                                    $hoursVal = (float) $l['duration_hours'];
                                    ?>
                                    <tr>
                                        <!-- Date -->
                                        <td>
                                            <strong style="font-size:.82rem;">
                                                <?= date('d M Y', strtotime($l['log_date'])) ?>
                                            </strong>
                                            <?php if ($l['day_of_week']): ?>
                                                <div style="font-size:.7rem;color:#9ca3af;"><?= $l['day_of_week'] ?></div>
                                            <?php else: ?>
                                                <div style="font-size:.7rem;color:#9ca3af;">
                                                    <?= date('D', strtotime($l['log_date'])) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Type -->
                                        <td>
                                            <?php if ($isVisit): ?>
                                                <span class="log-type-badge log-type-badge-visit">
                                                    <i class="fas fa-car me-1"></i>Visit
                                                </span>
                                            <?php else: ?>
                                                <span class="log-type-badge log-type-badge-office">
                                                    <i class="fas fa-building me-1"></i>Office
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Client -->
                                        <td>
                                            <div style="font-weight:600;font-size:.82rem;">
                                                <?= htmlspecialchars($l['company_name'] ?? '—') ?>
                                            </div>
                                            <div style="font-size:.7rem;color:#9ca3af;">
                                                <?= htmlspecialchars($l['company_code'] ?? '') ?>
                                            </div>
                                        </td>
                                        <!-- Supervisor -->
                                        <td style="font-size:.78rem;">
                                            <?php if ($isVisit && !empty($l['supervisor_name'])): ?>
                                                <span style="font-weight:600;color:#374151;">
                                                    <?= htmlspecialchars($l['supervisor_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color:#d1d5db;">—</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Time In -->
                                        <td class="text-center" style="font-size:.81rem;">
                                            <?= $l['time_in'] ? date('h:i A', strtotime($l['time_in'])) : '—' ?>
                                        </td>

                                        <!-- Time Out -->
                                        <td class="text-center" style="font-size:.81rem;">
                                            <?= $l['time_out'] ? date('h:i A', strtotime($l['time_out'])) : '—' ?>
                                        </td>

                                        <!-- Hours -->
                                        <td class="text-center">
                                            <strong style="color:<?= $isVisit ? hoursColor($hoursVal) : '#8b5cf6' ?>">
                                                <?= number_format($hoursVal, 1) ?>h
                                            </strong>
                                        </td>

                                        <!-- Status -->
                                        <td class="text-center">
                                            <span style="font-size:.72rem;font-weight:700;
                                                     background:<?= $sm['bg'] ?>;
                                                     color:<?= $sm['color'] ?>;
                                                     border-radius:5px;padding:.2rem .5rem;
                                                     white-space:nowrap;display:inline-block;">
                                                <i class="fas <?= $sm['icon'] ?> me-1" style="font-size:.6rem;"></i>
                                                <?= $sm['label'] ?>
                                            </span>
                                        </td>

                                        <!-- Description -->
                                        <td style="font-size:.77rem;color:#6b7280;max-width:200px;">
                                            <?= htmlspecialchars(mb_strimwidth($l['description'] ?? '', 0, 70, '…')) ?>
                                            <?php if (!empty($l['notes'])): ?>
                                                <div style="font-size:.7rem;color:#f59e0b;margin-top:2px;">
                                                    <i class="fas fa-sticky-note me-1"></i>
                                                    <?= htmlspecialchars(mb_strimwidth($l['notes'], 0, 50, '…')) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Action -->
                                        <!-- Action -->
                                        <td class="text-center">
                                            <?php if ($isVisit): ?>
                                                <a href="log_view.php?id=<?= $l['id'] ?>" class="cn-btn cn-btn-out cn-btn-sm"
                                                    title="View" style="margin-right:2px;">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="log_edit.php?id=<?= $l['id'] ?>" class="cn-btn cn-btn-gold cn-btn-sm"
                                                    title="Edit Visit Log">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="office_log_view.php?id=<?= $l['id'] ?>" class="cn-btn cn-btn-out cn-btn-sm"
                                                    title="View" style="margin-right:2px;">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="office_log_edit.php?id=<?= $l['id'] ?>"
                                                    class="cn-btn cn-btn-gold cn-btn-sm" title="Edit Office Log">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            </div><!-- /cn-panel -->

        </div>

    </div><!-- /main-content -->
</div><!-- /app-wrapper -->

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
    $(document).ready(function () {
        if ($('#logsTable tbody tr').length > 0) {
            $('#logsTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: { search: 'Search logs:' }
            });
        }
    });
</script>
<?php include '../../includes/footer.php'; ?>