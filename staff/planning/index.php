<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

$deptId = (int) $user['department_id'];
$branchId = (int) $user['branch_id'];

// Month
$now = new DateTime();
$month = $_GET['month'] ?? $now->format('Y-m');
$monthDate = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthLabel = $monthDate->format('F Y');

// ── SELF ONLY KPIs ─────────────────────────────
$totalPlans = (int) $db->query("
    SELECT COUNT(*) FROM work_plans
    WHERE plan_month='{$monthStart}'
      AND user_id={$uid}
")->fetchColumn();

$totalLogs = (int) $db->query("
    SELECT COUNT(*) FROM work_logs
    WHERE month_year='{$month}'
      AND user_id={$uid}
")->fetchColumn();

$totalHours = (float) $db->query("
    SELECT COALESCE(SUM(duration_hours),0) FROM work_logs
    WHERE month_year='{$month}'
      AND user_id={$uid}
")->fetchColumn();

$totalClients = (int) $db->query("
    SELECT COUNT(DISTINCT client_id) FROM work_logs
    WHERE month_year='{$month}'
      AND user_id={$uid}
")->fetchColumn();

// Visit status
$vstRows = $db->query("
    SELECT visit_status, COUNT(*) cnt
    FROM work_logs
    WHERE month_year='{$month}' AND user_id={$uid}
    GROUP BY visit_status
")->fetchAll();

$vst = [];
foreach ($vstRows as $r)
    $vst[$r['visit_status']] = $r['cnt'];

$visited = $vst['visited'] ?? 0;
$missed = $vst['missed'] ?? 0;
$rescheduled = $vst['rescheduled'] ?? 0;

// Planned hours
$plannedHours = (float) $db->query("
    SELECT COALESCE(SUM(wpe.planned_hours),0)
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    WHERE wpe.assigned_to={$uid}
      AND wp.plan_month='{$monthStart}'
")->fetchColumn();

$rawEfficiency = $plannedHours > 0 ? round(($totalHours / $plannedHours) * 100) : 0;
$efficiency = min($rawEfficiency, 100);
$overDelivered = $rawEfficiency > 100;
// Office log KPIs
$officeTotalLogs = (int)$db->query("
    SELECT COUNT(*) FROM office_work_logs
    WHERE user_id={$uid} AND DATE_FORMAT(log_date,'%Y-%m')='{$month}'
")->fetchColumn();

$officeTotalHours = (float)$db->query("
    SELECT COALESCE(ROUND(SUM(TIME_TO_SEC(TIMEDIFF(time_out,time_in)))/3600,2),0)
    FROM office_work_logs
    WHERE user_id={$uid} AND DATE_FORMAT(log_date,'%Y-%m')='{$month}'
")->fetchColumn();

$officeStatusRows = $db->query("
    SELECT status, COUNT(*) cnt
    FROM office_work_logs
    WHERE user_id={$uid} AND DATE_FORMAT(log_date,'%Y-%m')='{$month}'
    GROUP BY status
")->fetchAll();
$officeStatus = [];
foreach ($officeStatusRows as $r) $officeStatus[$r['status']] = (int)$r['cnt'];
$officeWip       = $officeStatus['wip']         ?? 0;
$officeCompleted = $officeStatus['completed']    ?? 0;
$officeHolding   = $officeStatus['holding']      ?? 0;
$officeNotStarted= $officeStatus['not_started']  ?? 0;

$officeTotalClients = (int)$db->query("
    SELECT COUNT(DISTINCT client_id) FROM office_work_logs
    WHERE user_id={$uid} AND DATE_FORMAT(log_date,'%Y-%m')='{$month}'
")->fetchColumn();

// Combined totals
$combinedTotalLogs  = $totalLogs + $officeTotalLogs;
$combinedTotalHours = $totalHours + $officeTotalHours;
$combinedClients    = (int)$db->query("
    SELECT COUNT(DISTINCT client_id) FROM (
        SELECT client_id FROM work_logs
        WHERE user_id={$uid} AND month_year='{$month}'
        UNION ALL
        SELECT client_id FROM office_work_logs
        WHERE user_id={$uid} AND DATE_FORMAT(log_date,'%Y-%m')='{$month}'
    ) combined
")->fetchColumn();

// Recent office logs
$recentOfficeLogs = $db->query("
    SELECT owl.*, c.company_name
    FROM office_work_logs owl
    LEFT JOIN companies c ON c.id=owl.client_id
    WHERE owl.user_id={$uid} AND DATE_FORMAT(owl.log_date,'%Y-%m')='{$month}'
    ORDER BY owl.log_date DESC LIMIT 8
")->fetchAll();

// Weekly hours breakdown (visit + office combined)
$weeklyBreakdown = $db->query("
    SELECT week_number,
           COALESCE(SUM(duration_hours),0) AS visit_hours,
           COUNT(*) AS visit_logs
    FROM work_logs
    WHERE user_id={$uid} AND month_year='{$month}'
    GROUP BY week_number
    ORDER BY week_number
")->fetchAll();

$weeklyOffice = $db->query("
    SELECT CEIL(DAY(log_date)/7) AS week_number,
           COALESCE(ROUND(SUM(TIME_TO_SEC(TIMEDIFF(time_out,time_in)))/3600,2),0) AS office_hours,
           COUNT(*) AS office_logs
    FROM office_work_logs
    WHERE user_id={$uid} AND DATE_FORMAT(log_date,'%Y-%m')='{$month}'
    GROUP BY week_number
    ORDER BY week_number
")->fetchAll();

// Merge weekly data
$weeklyData = [];
foreach ($weeklyBreakdown as $w) {
    $weeklyData[$w['week_number']] = [
        'visit_hours'  => (float)$w['visit_hours'],
        'office_hours' => 0,
        'visit_logs'   => (int)$w['visit_logs'],
        'office_logs'  => 0,
    ];
}
foreach ($weeklyOffice as $w) {
    $wn = $w['week_number'];
    if (!isset($weeklyData[$wn])) {
        $weeklyData[$wn] = ['visit_hours'=>0,'office_hours'=>0,'visit_logs'=>0,'office_logs'=>0];
    }
    $weeklyData[$wn]['office_hours'] = (float)$w['office_hours'];
    $weeklyData[$wn]['office_logs']  = (int)$w['office_logs'];
}
ksort($weeklyData);
// Recent logs
$recentLogs = $db->query("
    SELECT wl.*, c.company_name
    FROM work_logs wl
    LEFT JOIN companies c ON c.id=wl.client_id
    WHERE wl.month_year='{$month}' AND wl.user_id={$uid}
    ORDER BY wl.log_date DESC LIMIT 8
")->fetchAll();

// Plans
$activePlans = $db->query("
    SELECT wp.*, COUNT(wpe.id) entry_count
    FROM work_plans wp
    LEFT JOIN work_plan_entries wpe ON wpe.plan_id=wp.id
    WHERE wp.plan_month='{$monthStart}' AND wp.user_id={$uid}
    GROUP BY wp.id ORDER BY wp.week_number
")->fetchAll();

$pageTitle = 'My Work Dashboard';
include '../../includes/header.php';
?>

<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">



<div class="app-wrapper">
    <?php include '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div class="cn-wrap">
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-briefcase"></i> Consulting</div>
                        <h4>My Work Dashboard</h4>
                        <p><?= htmlspecialchars($user['full_name']) ?> · <?= $monthLabel ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="month" class="form-control form-control-sm" style="width:150px;"
                            value="<?= $month ?>" onchange="location='?month='+this.value">
                        <a href="plan_create.php" class="btn btn-sm btn-gold">
                            <i class="fas fa-plus me-1"></i> Create Plan
                        </a>
                        <a href="log_create.php" class="btn btn-sm btn-gold">
                            <i class="fas fa-clock me-1"></i> Log Work
                        </a>
                    </div>
                </div>
            </div>

            <!-- KPIs -->
            <div class="kpi-row mb-4">
                <div class="kpi-tile" style="--kpi-color:#3b82f6;">
                    <div class="kpi-icon"><i class="fas fa-calendar-check" style="color:#3b82f6;"></i></div>
                    <div class="kpi-val"><?= $totalPlans ?></div>
                    <div class="kpi-label">Plans</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#8b5cf6;">
                    <div class="kpi-icon"><i class="fas fa-clipboard-list" style="color:#8b5cf6;"></i></div>
                    <div class="kpi-val"><?= $combinedTotalLogs ?></div>
                    <div class="kpi-label">Total Logs</div>
                    <div class="kpi-delta" style="color:#9ca3af;font-size:.68rem;">
                        <?= $totalLogs ?> visit · <?= $officeTotalLogs ?> office
                    </div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#c9a84c;">
                    <div class="kpi-icon"><i class="fas fa-clock" style="color:#c9a84c;"></i></div>
                    <div class="kpi-val"><?= number_format($combinedTotalHours, 1) ?>h</div>
                    <div class="kpi-label">Total Hours</div>
                    <div class="kpi-delta" style="color:#9ca3af;font-size:.68rem;">
                        <?= number_format($totalHours,1) ?>h visit · <?= number_format($officeTotalHours,1) ?>h office
                    </div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#8b5cf6;">
                    <div class="kpi-icon"><i class="fas fa-building" style="color:#8b5cf6;"></i></div>
                    <div class="kpi-val"><?= $combinedClients ?></div>
                    <div class="kpi-label">Clients</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#10b981;">
                    <div class="kpi-icon"><i class="fas fa-car" style="color:#10b981;"></i></div>
                    <div class="kpi-val"><?= $visited ?></div>
                    <div class="kpi-label">Visited</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#ef4444;">
                    <div class="kpi-icon"><i class="fas fa-times-circle" style="color:#ef4444;"></i></div>
                    <div class="kpi-val"><?= $missed ?></div>
                    <div class="kpi-label">Missed</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#3b82f6;">
                    <div class="kpi-icon"><i class="fas fa-building" style="color:#3b82f6;"></i></div>
                    <div class="kpi-val"><?= $officeCompleted ?></div>
                    <div class="kpi-label">Office Done</div>
                    <div class="kpi-delta" style="color:#9ca3af;font-size:.68rem;">
                        <?= $officeWip ?> WIP · <?= $officeHolding ?> holding
                    </div>
                </div>
                <?php $effColor = $rawEfficiency >= 80 ? '#10b981' : ($rawEfficiency >= 50 ? '#f59e0b' : '#ef4444'); ?>
                <div class="kpi-tile" style="--kpi-color:<?= $effColor ?>;">
                    <div class="kpi-icon"><i class="fas fa-tachometer-alt" style="color:<?= $effColor ?>;"></i></div>
                    <div class="kpi-val" style="color:<?= $effColor ?>;">
                        <?= $efficiency ?>%
                        <?php if ($overDelivered): ?>
                            <span style="font-size:.6rem;background:#dcfce7;color:#15803d;padding:1px 5px;border-radius:4px;vertical-align:middle;">+<?= $rawEfficiency - 100 ?>%</span>
                        <?php endif; ?>
                    </div>
                    <div class="kpi-label">Efficiency</div>
                    <div class="kpi-delta" style="color:#9ca3af;font-size:.7rem;">
                        <?= number_format($plannedHours, 1) ?>h planned
                    </div>
                </div>
            </div>

            <!-- PROGRESS -->
            <!-- PROGRESS + CHARTS -->
            <?php
            $maxH = max($plannedHours, $combinedTotalHours, 1);
            $pw   = round(($plannedHours       / $maxH) * 100);
            $aw   = round(($combinedTotalHours  / $maxH) * 100);
            $vw   = round(($totalHours          / $maxH) * 100);
            $ow   = round(($officeTotalHours    / $maxH) * 100);
            $ec   = $rawEfficiency >= 80 ? '#10b981' : ($rawEfficiency >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <div class="row g-4 mb-4">

                <!-- Planned vs Actual -->
                <div class="col-lg-5">
                    <div class="card-mis h-100">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-chart-line text-warning me-2"></i>Hours Overview</h5>
                            <span style="font-size:.78rem;color:#9ca3af;"><?= $monthLabel ?></span>
                        </div>
                        <div class="card-mis-body">
                            <?php foreach ([
                                ['Planned',      $plannedHours,      $pw,  '#3b82f6'],
                                ['Visit Actual', $totalHours,        $vw,  '#10b981'],
                                ['Office Actual',$officeTotalHours,  $ow,  '#8b5cf6'],
                                ['Combined',     $combinedTotalHours,$aw,  $ec],
                            ] as [$label, $val, $width, $color]): ?>
                            <div style="margin-bottom:10px;">
                                <div style="display:flex;justify-content:space-between;font-size:.74rem;color:#9ca3af;margin-bottom:3px;">
                                    <span><?= $label ?></span>
                                    <strong style="color:<?= $color ?>;"><?= number_format($val,1) ?>h</strong>
                                </div>
                                <div style="background:#f1f5f9;border-radius:99px;height:7px;overflow:hidden;">
                                    <div style="width:<?= $width ?>%;height:100%;background:<?= $color ?>;border-radius:99px;transition:width .4s;"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>

                            <?php if ($combinedTotalLogs > 0): ?>
                            <hr style="margin:12px 0;">
                            <div style="font-size:.72rem;color:#9ca3af;margin-bottom:5px;">Visit Status</div>
                            <div style="display:flex;border-radius:6px;overflow:hidden;height:10px;margin-bottom:6px;">
                                <?php if ($visited):     ?><div style="flex:<?= $visited ?>;background:#10b981;" title="Visited"></div><?php endif; ?>
                                <?php if ($missed):      ?><div style="flex:<?= $missed ?>;background:#ef4444;"  title="Missed"></div><?php endif; ?>
                                <?php if ($rescheduled): ?><div style="flex:<?= $rescheduled ?>;background:#f59e0b;" title="Rescheduled"></div><?php endif; ?>
                            </div>
                            <div style="display:flex;gap:10px;font-size:.7rem;flex-wrap:wrap;margin-bottom:10px;">
                                <span style="color:#10b981;">● Visited <?= $visited ?> (<?= $totalLogs ? round($visited/$totalLogs*100) : 0 ?>%)</span>
                                <span style="color:#ef4444;">● Missed <?= $missed ?></span>
                                <span style="color:#f59e0b;">● Rescheduled <?= $rescheduled ?></span>
                            </div>
                            <div style="font-size:.72rem;color:#9ca3af;margin-bottom:5px;">Office Status</div>
                            <div style="display:flex;border-radius:6px;overflow:hidden;height:10px;margin-bottom:6px;">
                                <?php if ($officeCompleted):  ?><div style="flex:<?= $officeCompleted ?>;background:#10b981;"  title="Completed"></div><?php endif; ?>
                                <?php if ($officeWip):        ?><div style="flex:<?= $officeWip ?>;background:#3b82f6;"        title="WIP"></div><?php endif; ?>
                                <?php if ($officeHolding):    ?><div style="flex:<?= $officeHolding ?>;background:#f59e0b;"    title="Holding"></div><?php endif; ?>
                                <?php if ($officeNotStarted): ?><div style="flex:<?= $officeNotStarted ?>;background:#9ca3af;" title="Not Started"></div><?php endif; ?>
                            </div>
                            <div style="display:flex;gap:10px;font-size:.7rem;flex-wrap:wrap;">
                                <span style="color:#10b981;">● Done <?= $officeCompleted ?></span>
                                <span style="color:#3b82f6;">● WIP <?= $officeWip ?></span>
                                <span style="color:#f59e0b;">● Holding <?= $officeHolding ?></span>
                                <span style="color:#9ca3af;">● Not Started <?= $officeNotStarted ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Weekly breakdown chart -->
                <div class="col-lg-7">
                    <div class="card-mis h-100">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-chart-bar text-warning me-2"></i>Weekly Breakdown</h5>
                            <span style="font-size:.78rem;color:#9ca3af;"><?= $monthLabel ?></span>
                        </div>
                        <div class="card-mis-body" style="height:220px;">
                            <canvas id="weeklyChart"></canvas>
                        </div>
                    </div>
                </div>

            </div>

            <!-- GRID -->
            <div class="row g-4 mb-4">

                <!-- PLANS -->
                <div class="col-lg-6">
                    <div class="card-mis h-100">
                        <div class="card-mis-header d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-calendar-week text-warning me-2"></i>My Plans This Month</h5>
                            <a href="plan_create.php" class="btn btn-sm btn-gold">
                                <i class="fas fa-plus me-1"></i>New
                            </a>
                        </div>
                        <?php if (empty($activePlans)): ?>
                            <div class="card-mis-body text-center text-muted py-4">
                                <i class="fas fa-calendar-plus fa-2x mb-2 opacity-25"></i><br>
                                No plans yet this month.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-mis w-100">
                                    <thead>
                                        <tr>
                                            <th>Week</th>
                                            <th class="text-center">Entries</th>
                                            <th class="text-center">Status</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activePlans as $p): ?>
                                            <tr>
                                                <td><strong style="color:#c9a84c;">Week <?= $p['week_number'] ?></strong>
                                                    <div style="font-size:.7rem;color:#9ca3af;">
                                                        <?= date('d M', strtotime($p['week_start_date'])) ?> –
                                                        <?= date('d M', strtotime($p['week_end_date'])) ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <span
                                                        style="background:#f0fdf4;color:#15803d;padding:2px 10px;border-radius:20px;font-size:.75rem;font-weight:600;">
                                                        <?= $p['entry_count'] ?> entries
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?php
                                                    $sc = ['draft' => '#9ca3af', 'submitted' => '#3b82f6', 'approved' => '#10b981', 'rejected' => '#ef4444'];
                                                    $sc2 = ['draft' => '#f3f4f6', 'submitted' => '#eff6ff', 'approved' => '#f0fdf4', 'rejected' => '#fef2f2'];
                                                    $st = $p['status'] ?? 'draft';
                                                    ?>
                                                    <span
                                                        style="background:<?= $sc2[$st] ?? '#f3f4f6' ?>;color:<?= $sc[$st] ?? '#9ca3af' ?>;
                                         padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:600;text-transform:capitalize;">
                                                        <?= $st ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="plan_view.php?id=<?= $p['id'] ?>"
                                                        style="font-size:.75rem;color:#3b82f6;">
                                                        View <i class="fas fa-chevron-right" style="font-size:.65rem;"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- LOGS TABS -->
                <div class="col-lg-6">
                    <div class="card-mis h-100">
                        <div class="card-mis-header d-flex justify-content-between align-items-center">
                            <div style="display:flex;gap:6px;">
                                <button onclick="showTab('visit')" id="tabVisit"
                                    style="font-size:.75rem;font-weight:700;padding:.25rem .75rem;border-radius:6px;border:1.5px solid #c9a84c;background:#c9a84c;color:#fff;cursor:pointer;">
                                    <i class="fas fa-car me-1"></i>Visit
                                </button>
                                <button onclick="showTab('office')" id="tabOffice"
                                    style="font-size:.75rem;font-weight:700;padding:.25rem .75rem;border-radius:6px;border:1.5px solid #e5e7eb;background:#fff;color:#6b7280;cursor:pointer;">
                                    <i class="fas fa-building me-1"></i>Office
                                </button>
                            </div>
                            <div style="display:flex;gap:6px;">
                                <a href="log_create.php" class="btn btn-sm btn-gold">
                                    <i class="fas fa-plus me-1"></i>Visit
                                </a>
                                <a href="office_log_create.php" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-plus me-1"></i>Office
                                </a>
                            </div>
                        </div>

                        <!-- Visit Logs Tab -->
                        <div id="panelVisit">
                        <?php if (empty($recentLogs)): ?>
                            <div class="card-mis-body text-center text-muted py-4">
                                <i class="fas fa-clock fa-2x mb-2 opacity-25"></i><br>
                                No visit logs this month.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-mis w-100">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Client</th>
                                            <th class="text-center">Hours</th>
                                            <th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentLogs as $l):
                                            $vs = $l['visit_status'] ?? 'visited';
                                            $vsColor = ['visited'=>'#10b981','missed'=>'#ef4444','rescheduled'=>'#f59e0b'];
                                            $vsBg    = ['visited'=>'#f0fdf4','missed'=>'#fef2f2','rescheduled'=>'#fffbeb'];
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong style="font-size:.82rem;"><?= date('d M', strtotime($l['log_date'])) ?></strong>
                                                    <div style="font-size:.68rem;color:#9ca3af;"><?= date('D', strtotime($l['log_date'])) ?></div>
                                                </td>
                                                <td style="font-size:.82rem;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                    <?= htmlspecialchars($l['company_name'] ?? '—') ?>
                                                </td>
                                                <td class="text-center">
                                                    <strong style="color:#c9a84c;"><?= number_format((float)$l['duration_hours'],1) ?>h</strong>
                                                </td>
                                                <td class="text-center">
                                                    <span style="background:<?= $vsBg[$vs]??'#f3f4f6' ?>;color:<?= $vsColor[$vs]??'#9ca3af' ?>;
                                                        padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:600;text-transform:capitalize;">
                                                        <?= $vs ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        </div>

                        <!-- Office Logs Tab -->
                        <div id="panelOffice" style="display:none;">
                        <?php if (empty($recentOfficeLogs)): ?>
                            <div class="card-mis-body text-center text-muted py-4">
                                <i class="fas fa-building fa-2x mb-2 opacity-25"></i><br>
                                No office logs this month.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-mis w-100">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Client</th>
                                            <th class="text-center">Hours</th>
                                            <th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentOfficeLogs as $l):
                                            $os = $l['status'] ?? 'not_started';
                                            $osColor = ['completed'=>'#10b981','wip'=>'#3b82f6','holding'=>'#f59e0b','not_started'=>'#9ca3af'];
                                            $osBg    = ['completed'=>'#f0fdf4','wip'=>'#eff6ff','holding'=>'#fffbeb','not_started'=>'#f3f4f6'];
                                            $hrs = $l['time_in'] && $l['time_out']
                                                ? round((strtotime($l['time_out']) - strtotime($l['time_in']))/3600, 1)
                                                : 0;
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong style="font-size:.82rem;"><?= date('d M', strtotime($l['log_date'])) ?></strong>
                                                    <div style="font-size:.68rem;color:#9ca3af;"><?= date('D', strtotime($l['log_date'])) ?></div>
                                                </td>
                                                <td style="font-size:.82rem;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                    <?= htmlspecialchars($l['company_name'] ?? '—') ?>
                                                </td>
                                                <td class="text-center">
                                                    <strong style="color:#8b5cf6;"><?= number_format($hrs,1) ?>h</strong>
                                                </td>
                                                <td class="text-center">
                                                    <span style="background:<?= $osBg[$os]??'#f3f4f6' ?>;color:<?= $osColor[$os]??'#9ca3af' ?>;
                                                        padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:600;text-transform:capitalize;">
                                                        <?= str_replace('_',' ',$os) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        </div>

                    </div>
                </div>

            </div>

        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
// Tab switcher
function showTab(tab) {
    document.getElementById('panelVisit').style.display  = tab === 'visit'  ? '' : 'none';
    document.getElementById('panelOffice').style.display = tab === 'office' ? '' : 'none';
    const gold  = '#c9a84c', white = '#fff', grey = '#6b7280', border = '#e5e7eb';
    const tv = document.getElementById('tabVisit');
    const to = document.getElementById('tabOffice');
    if (tab === 'visit') {
        tv.style.cssText = 'font-size:.75rem;font-weight:700;padding:.25rem .75rem;border-radius:6px;border:1.5px solid '+gold+';background:'+gold+';color:'+white+';cursor:pointer;';
        to.style.cssText = 'font-size:.75rem;font-weight:700;padding:.25rem .75rem;border-radius:6px;border:1.5px solid '+border+';background:'+white+';color:'+grey+';cursor:pointer;';
    } else {
        to.style.cssText = 'font-size:.75rem;font-weight:700;padding:.25rem .75rem;border-radius:6px;border:1.5px solid #3b82f6;background:#3b82f6;color:'+white+';cursor:pointer;';
        tv.style.cssText = 'font-size:.75rem;font-weight:700;padding:.25rem .75rem;border-radius:6px;border:1.5px solid '+border+';background:'+white+';color:'+grey+';cursor:pointer;';
    }
}

// Weekly chart
const weeklyData = <?= json_encode(array_values($weeklyData)) ?>;
const weekLabels = <?= json_encode(array_map(fn($k) => 'Week '.$k, array_keys($weeklyData))) ?>;

new Chart(document.getElementById('weeklyChart'), {
    type: 'bar',
    data: {
        labels: weekLabels,
        datasets: [
            {
                label: 'Visit Hours',
                data: weeklyData.map(w => w.visit_hours),
                backgroundColor: 'rgba(201,168,76,0.8)',
                borderColor: '#c9a84c',
                borderWidth: 1.5,
                borderRadius: 5,
                stack: 'hours'
            },
            {
                label: 'Office Hours',
                data: weeklyData.map(w => w.office_hours),
                backgroundColor: 'rgba(139,92,246,0.8)',
                borderColor: '#8b5cf6',
                borderWidth: 1.5,
                borderRadius: 5,
                stack: 'hours'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: {
                stacked: true,
                ticks: { color:'#4b5563', font:{ size:11 } },
                grid: { display: false }
            },
            y: {
                stacked: true,
                beginAtZero: true,
                ticks: { color:'#6b7280', font:{ size:11 }, callback: v => v+'h' },
                grid: { color:'#f1f5f9' }
            }
        },
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: { font:{ size:11 }, boxWidth:12, color:'#374151' }
            }
        }
    }
});
</script>