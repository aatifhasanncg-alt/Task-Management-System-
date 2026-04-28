<?php
/**
 * planning/reports.php — Performance Reports
 * Daily / Weekly / Monthly / Client-wise / Staff-wise
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];
$role = $user['role_name'] ?? '';
$isExecutive = ($role === 'executive');
$isAdmin     = ($role === 'admin');
$isStaff     = ($role === 'staff');
$deptId   = (int) ($user['department_id'] ?? 0);
$branchId = (int) ($user['branch_id']     ?? 0);

$now     = new DateTime();
$month   = $_GET['month'] ?? $now->format('Y-m');
$view    = $_GET['view']  ?? 'monthly';
$weekNum = (int) ($_GET['week'] ?? 1);
$staffFilter = $isExecutive || $isAdmin ? ((int) ($_GET['staff_id'] ?? 0) ?: null) : $uid;

$monthDate  = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthEnd   = $monthDate->format('Y-m-t');
$monthLabel = $monthDate->format('F Y');

// ── Scope filter ──────────────────────────────────────────────
$scopeWhere  = '';
$scopeParams = [];

$scopeWhere .= " AND wl.user_id IN (
    SELECT u2.id FROM users u2
    JOIN departments d ON d.id = u2.department_id AND d.dept_code = 'CON'
    WHERE u2.is_active = 1
    UNION
    SELECT uda.user_id FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id AND d.dept_code = 'CON'
    UNION
    SELECT {$uid}
)";

if (!$isExecutive && !$isAdmin) {
    $scopeWhere .= " AND wl.department_id = {$deptId} AND wl.branch_id = {$branchId}";
}
if ($staffFilter) {
    $scopeWhere .= " AND wl.user_id = {$staffFilter}";
}

// ── MONTHLY summary per staff ─────────────────────────────────
$monthlySt = $db->prepare("
    SELECT wl.user_id, u.full_name, u.employee_id,
           COUNT(wl.id)                         AS total_visits,
           COUNT(DISTINCT wl.client_id)         AS unique_clients,
           COALESCE(SUM(wl.duration_hours), 0)  AS total_hours,
           SUM(wl.visit_status='visited')       AS visited_count,
           SUM(wl.visit_status='missed')        AS missed_count,
           COUNT(DISTINCT wl.log_date)          AS active_days
    FROM work_logs wl
    LEFT JOIN users u ON u.id = wl.user_id
    WHERE wl.month_year = ? {$scopeWhere}
    GROUP BY wl.user_id, u.full_name, u.employee_id
    ORDER BY total_hours DESC
");
$monthlySt->execute([$month, ...$scopeParams]);
$monthlyData = $monthlySt->fetchAll();

// ── CLIENT summary ────────────────────────────────────────────
$clientSt = $db->prepare("
    SELECT wl.client_id, c.company_name, c.company_code,
           COUNT(wl.id)                        AS total_visits,
           COALESCE(SUM(wl.duration_hours), 0) AS total_hours,
           COUNT(DISTINCT wl.user_id)          AS staff_count,
           MAX(wl.log_date)                    AS last_visit
    FROM work_logs wl
    LEFT JOIN companies c ON c.id = wl.client_id
    WHERE wl.month_year = ? {$scopeWhere}
    GROUP BY wl.client_id, c.company_name, c.company_code
    ORDER BY total_hours DESC
");
$clientSt->execute([$month, ...$scopeParams]);
$clientData = $clientSt->fetchAll();

// ── DAILY breakdown ───────────────────────────────────────────
$dailySt = $db->prepare("
    SELECT wl.log_date, wl.day_of_week,
           COUNT(wl.id)                        AS visits,
           COALESCE(SUM(wl.duration_hours), 0) AS total_hours,
           COUNT(DISTINCT wl.client_id)        AS clients,
           COUNT(DISTINCT wl.user_id)          AS staff_count
    FROM work_logs wl
    WHERE wl.month_year = ? {$scopeWhere}
    GROUP BY wl.log_date, wl.day_of_week
    ORDER BY wl.log_date ASC
");
$dailySt->execute([$month, ...$scopeParams]);
$dailyData = $dailySt->fetchAll();

// ── WHO visited which CLIENT ──────────────────────────────────
$whoVisitedSt = $db->prepare("
    SELECT wl.client_id, c.company_name, wl.user_id, u.full_name AS staff_name,
           wl.log_date, wl.duration_hours, wl.time_in, wl.time_out, wl.visit_status
    FROM work_logs wl
    LEFT JOIN companies c ON c.id = wl.client_id
    LEFT JOIN users u     ON u.id = wl.user_id
    WHERE wl.month_year = ? {$scopeWhere}
    ORDER BY c.company_name ASC, wl.log_date ASC
    LIMIT 500
");
$whoVisitedSt->execute([$month, ...$scopeParams]);
$whoVisited = $whoVisitedSt->fetchAll();

// ── VISIT STATUS totals for donut ────────────────────────────
$visitedTotal    = array_sum(array_column($monthlyData, 'visited_count'));
$missedTotal     = array_sum(array_column($monthlyData, 'missed_count'));
$grandVisits     = array_sum(array_column($monthlyData, 'total_visits'));
$rescheduledTotal = $grandVisits - $visitedTotal - $missedTotal;

// Grand totals
$grandHours   = array_sum(array_column($monthlyData, 'total_hours'));
$grandClients = count($clientData);

// ── Last 6 months trend ───────────────────────────────────────
$trendData = $db->query("
    SELECT month_year,
           COUNT(*) AS logs,
           COALESCE(SUM(duration_hours),0) AS hours,
           SUM(visit_status='visited') AS visited,
           SUM(visit_status='missed')  AS missed
    FROM work_logs wl
    WHERE wl.user_id IN (
        SELECT u2.id FROM users u2
        JOIN departments d ON d.id = u2.department_id AND d.dept_code = 'CON'
        WHERE u2.is_active = 1
        UNION
        SELECT uda.user_id FROM user_department_assignments uda
        JOIN departments d ON d.id = uda.department_id AND d.dept_code = 'CON'
        UNION SELECT {$uid}
    )
    GROUP BY month_year
    ORDER BY month_year DESC
    LIMIT 6
")->fetchAll();
$trendData = array_reverse($trendData);

// ── Staff filter dropdown ─────────────────────────────────────
$allStaff = $db->query("
    SELECT DISTINCT u.id, u.full_name
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

$pageTitle = 'Performance Reports — ' . $monthLabel;
include '../../includes/header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<div class="app-wrapper">
<?php include ($isExecutive ? '../../includes/sidebar_executive.php' : '../../includes/sidebar.php'); ?>
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
            <p><?= htmlspecialchars($user['full_name']) ?> · <?= $monthLabel ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <input type="month" class="form-control form-control-sm" style="width:150px;"
                   value="<?= $month ?>" onchange="setParam('month',this.value)">
            <a href="export.php?type=pdf&view=performance&month=<?= urlencode($month) ?>&staff_id=<?= $staffFilter ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-file-pdf me-1" style="color:#ef4444;"></i>PDF
            </a>
            <a href="export.php?type=excel&view=performance&month=<?= urlencode($month) ?>&staff_id=<?= $staffFilter ?>"
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
            <?php if ($isExecutive || $isAdmin): ?>
            <div class="col-md-3">
                <label class="form-label-mis">Staff</label>
                <select id="fStaff" class="form-select form-select-sm" onchange="setParam('staff_id',this.value)">
                    <option value="">— All Staff —</option>
                    <?php foreach ($allStaff as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $staffFilter == $s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['full_name']) ?>
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
<div style="display:flex;gap:0;border-bottom:2px solid #f3f4f6;margin-bottom:20px;overflow-x:auto;">
<?php
$tabs = ['monthly' => '<i class="fas fa-user-clock me-1"></i>Monthly', 'daily' => '<i class="fas fa-calendar-day me-1"></i>Daily', 'client' => '<i class="fas fa-building me-1"></i>Client-wise'];
if ($isExecutive || $isAdmin) $tabs['who'] = '<i class="fas fa-search me-1"></i>Who Visited';
foreach ($tabs as $k => $lbl):
    $active = $view === $k;
?>
<a href="?month=<?= urlencode($month) ?>&view=<?= $k ?>&staff_id=<?= $staffFilter ?>"
   style="padding:.6rem 1.1rem;font-size:.82rem;font-weight:600;text-decoration:none;white-space:nowrap;
          border-bottom:2.5px solid <?= $active ? '#c9a84c' : 'transparent' ?>;
          color:<?= $active ? '#c9a84c' : '#6b7280' ?>;margin-bottom:-2px;
          transition:color .15s;">
    <?= $lbl ?>
</a>
<?php endforeach; ?>
</div>

<!-- ══ KPI CARDS ═════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
<?php
$kpiCards = [
    ['fa-clock',        '#3b82f6', '#eff6ff', 'Total Hours',    number_format($grandHours,1).'h'],
    ['fa-clipboard-list','#8b5cf6','#f5f3ff', 'Total Visits',   $grandVisits],
    ['fa-building',     '#0ea5e9', '#e0f2fe', 'Clients',        $grandClients],
    ['fa-users',        '#c9a84c', '#fefce8', 'Staff Active',   count($monthlyData)],
    ['fa-check-circle', '#10b981', '#ecfdf5', 'Visited',        $visitedTotal],
    ['fa-times-circle', '#ef4444', '#fef2f2', 'Missed',         $missedTotal],
];
foreach ($kpiCards as [$icon,$col,$bg,$lbl,$val]):
?>
<div class="col-6 col-md-2">
    <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;
                padding:1rem 1.1rem;display:flex;align-items:center;gap:.75rem;
                box-shadow:0 1px 3px rgba(0,0,0,.04);">
        <div style="width:40px;height:40px;border-radius:10px;background:<?= $bg ?>;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas <?= $icon ?>" style="color:<?= $col ?>;font-size:.95rem;"></i>
        </div>
        <div>
            <div style="font-size:1.3rem;font-weight:800;color:#1f2937;line-height:1.1;"><?= $val ?></div>
            <div style="font-size:.68rem;color:#9ca3af;margin-top:.1rem;"><?= $lbl ?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ══ CHARTS ROW ════════════════════════════════════════════ -->
<div class="row g-3 mb-4">

    <!-- Trend chart -->
    <div class="col-md-5">
        <div class="card-mis h-100">
            <div class="card-mis-header">
                <h5><i class="fas fa-chart-line text-warning me-2"></i>6-Month Trend</h5>
            </div>
            <div class="card-mis-body" style="height:220px;">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Donut visit status -->
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
                <h5><i class="fas fa-users text-warning me-2"></i>Staff Hours</h5>
            </div>
            <div class="card-mis-body" style="height:220px;">
                <canvas id="staffChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ══ MONTHLY SUMMARY TAB ═══════════════════════════════════ -->
<?php if ($view === 'monthly'): ?>
<div class="card-mis mb-4" style="border-top:3px solid #c9a84c;">
    <div class="card-mis-header">
        <h5><i class="fas fa-user-clock text-warning me-2"></i>Monthly Staff Summary — <?= htmlspecialchars($monthLabel) ?></h5>
        <span style="font-size:.78rem;color:#9ca3af;"><?= count($monthlyData) ?> staff member(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Staff</th>
                    <th>Emp ID</th>
                    <th class="text-center">Total Hrs</th>
                    <th class="text-center">Visits</th>
                    <th class="text-center">Clients</th>
                    <th class="text-center">Active Days</th>
                    <th class="text-center">Visited</th>
                    <th class="text-center">Missed</th>
                    <th style="min-width:120px;">Hrs Bar</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($monthlyData)): ?>
                <tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:2rem;font-size:.84rem;">
                    No logs for <?= htmlspecialchars($monthLabel) ?>
                </td></tr>
            <?php else:
                $maxHrs = max(array_column($monthlyData,'total_hours')) ?: 1;
                foreach ($monthlyData as $i => $r):
                    $pct = round(($r['total_hours']/$maxHrs)*100);
                    $hCol = $pct >= 75 ? '#10b981' : ($pct >= 40 ? '#f59e0b' : '#ef4444');
            ?>
            <tr>
                <td style="color:#9ca3af;font-size:.75rem;"><?= $i+1 ?></td>
                <td>
                    <div style="font-weight:600;font-size:.85rem;"><?= htmlspecialchars($r['full_name'] ?? '—') ?></div>
                </td>
                <td style="font-size:.75rem;color:#9ca3af;"><?= htmlspecialchars($r['employee_id'] ?? '—') ?></td>
                <td class="text-center">
                    <strong style="color:#c9a84c;"><?= number_format($r['total_hours'],2) ?>h</strong>
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
                <td>
                    <div style="display:flex;align-items:center;gap:.4rem;">
                        <div style="flex:1;background:#f1f5f9;border-radius:99px;height:6px;overflow:hidden;">
                            <div style="width:<?= $pct ?>%;background:<?= $hCol ?>;height:100%;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:.7rem;font-weight:700;color:<?= $hCol ?>;min-width:28px;"><?= $pct ?>%</span>
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
                    <td class="text-center"><?= $grandVisits ?></td>
                    <td class="text-center"><?= $grandClients ?></td>
                    <td></td>
                    <td class="text-center" style="color:#15803d;"><?= $visitedTotal ?></td>
                    <td class="text-center" style="color:#b91c1c;"><?= $missedTotal ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ══ DAILY TAB ══════════════════════════════════════════════ -->
<?php elseif ($view === 'daily'): ?>

<!-- Day-of-week heatmap -->
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
                        margin-bottom:.3rem;font-weight:700;font-size:.85rem;opacity:<?= $pct>0?(.3+($pct/100)*.7):1 ?>;">
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
                    <th>Date</th><th>Day</th><th class="text-center">Visits</th>
                    <th class="text-center">Hours</th><th class="text-center">Clients</th>
                    <th class="text-center">Staff</th><th style="min-width:130px;">Bar</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($dailyData)): ?>
                <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:2rem;">No daily logs found</td></tr>
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
                <td class="text-center"><strong style="color:#c9a84c;"><?= number_format($r['total_hours'],2) ?>h</strong></td>
                <td class="text-center"><?= $r['clients'] ?></td>
                <td class="text-center"><?= $r['staff_count'] ?></td>
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

<!-- ══ CLIENT-WISE TAB ════════════════════════════════════════ -->
<?php elseif ($view === 'client'): ?>

<!-- Top clients chart -->
<?php if (!empty($clientData)):
    $topN = array_slice($clientData, 0, 8);
    $topNames = array_map(fn($c) => mb_strimwidth($c['company_name']??'—',0,18,'…'), $topN);
    $topHours = array_map(fn($c) => (float)$c['total_hours'], $topN);
    $topVisits = array_map(fn($c) => (int)$c['total_visits'], $topN);
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
                    <th class="text-center">Staff</th><th>Last Visit</th>
                    <th style="min-width:120px;">Bar</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($clientData)): ?>
                <tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:2rem;">No client data found</td></tr>
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
                <td class="text-center"><strong style="color:#c9a84c;"><?= number_format($r['total_hours'],2) ?>h</strong></td>
                <td class="text-center"><strong><?= $r['total_visits'] ?></strong></td>
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

<!-- ══ WHO VISITED TAB ════════════════════════════════════════ -->
<?php elseif ($view === 'who' && ($isExecutive || $isAdmin)): ?>
<div class="card-mis mb-4" style="border-top:3px solid #0ea5e9;">
    <div class="card-mis-header">
        <h5><i class="fas fa-search text-warning me-2"></i>Who Visited Each Client</h5>
        <span style="font-size:.78rem;color:#9ca3af;"><?= count($whoVisited) ?> record(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>Client</th><th>Staff</th><th>Date</th>
                    <th class="text-center">Time In</th><th class="text-center">Time Out</th>
                    <th class="text-center">Hours</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($whoVisited)): ?>
                <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:2rem;">No data found</td></tr>
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
                <td><strong style="font-size:.83rem;"><?= htmlspecialchars(mb_strimwidth($r['company_name']??'—',0,25,'…')) ?></strong></td>
                <td style="font-size:.82rem;"><?= htmlspecialchars($r['staff_name']??'—') ?></td>
                <td style="font-size:.8rem;white-space:nowrap;"><?= date('d M Y',strtotime($r['log_date'])) ?></td>
                <td class="text-center" style="font-size:.78rem;"><?= $r['time_in']  ? date('g:i A',strtotime($r['time_in']))  : '—' ?></td>
                <td class="text-center" style="font-size:.78rem;"><?= $r['time_out'] ? date('g:i A',strtotime($r['time_out'])) : '—' ?></td>
                <td class="text-center">
                    <strong style="color:<?= (float)$r['duration_hours']>=4?'#10b981':((float)$r['duration_hours']>=2?'#f59e0b':'#6b7280') ?>;">
                        <?= number_format((float)$r['duration_hours'],2) ?>h
                    </strong>
                </td>
                <td>
                    <span style="background:<?= $vbg ?>;color:<?= $vcol ?>;padding:.15rem .55rem;border-radius:99px;
                                 font-size:.72rem;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;">
                        <i class="fas <?= $vico ?>" style="font-size:.6rem;"></i><?= $vlbl ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

</div><!-- /padding -->
</div></div><!-- /main-content /app-wrapper -->

<?php include '../../includes/footer.php'; ?>

<script>
// ── Trend Chart ───────────────────────────────────────────────
const trendLabels = <?= json_encode(array_map(fn($t) => date('M', strtotime($t['month_year'].'-01')), $trendData)) ?>;
const trendHours  = <?= json_encode(array_map(fn($t) => (float)$t['hours'], $trendData)) ?>;
const trendVisits = <?= json_encode(array_map(fn($t) => (int)$t['logs'], $trendData)) ?>;

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: trendLabels,
        datasets: [
            {
                label: 'Hours',
                data: trendHours,
                borderColor: '#c9a84c',
                backgroundColor: 'rgba(201,168,76,.1)',
                fill: true,
                tension: .4,
                pointBackgroundColor: '#c9a84c',
                pointRadius: 4,
                yAxisID: 'y'
            },
            {
                label: 'Visits',
                data: trendVisits,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59,130,246,.08)',
                fill: true,
                tension: .4,
                pointBackgroundColor: '#3b82f6',
                pointRadius: 4,
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', labels: { usePointStyle: true, font: { size: 10 } } }
        },
        scales: {
            y:  { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 } }, title: { display: true, text: 'Hours', font: { size: 10 } } },
            y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { font: { size: 10 } }, title: { display: true, text: 'Visits', font: { size: 10 } } }
        }
    }
});

// ── Donut Chart ───────────────────────────────────────────────
new Chart(document.getElementById('donutChart'), {
    type: 'doughnut',
    data: {
        labels: ['Visited', 'Missed', 'Rescheduled'],
        datasets: [{
            data: [<?= $visitedTotal ?>, <?= $missedTotal ?>, <?= $rescheduledTotal ?>],
            backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
            borderWidth: 2,
            borderColor: '#fff',
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        plugins: {
            legend: { position: 'bottom', labels: { usePointStyle: true, font: { size: 10 }, padding: 10 } }
        }
    }
});

// ── Staff Hours Bar ───────────────────────────────────────────
<?php if (!empty($monthlyData)):
    $topStaff = array_slice($monthlyData, 0, 6);
?>
new Chart(document.getElementById('staffChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($s) => explode(' ', $s['full_name'])[0], $topStaff)) ?>,
        datasets: [{
            label: 'Hours',
            data: <?= json_encode(array_map(fn($s) => round((float)$s['total_hours'],1), $topStaff)) ?>,
            backgroundColor: [
                'rgba(201,168,76,.75)','rgba(59,130,246,.75)','rgba(16,185,129,.75)',
                'rgba(139,92,246,.75)','rgba(239,68,68,.75)','rgba(14,165,233,.75)'
            ],
            borderRadius: 6,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 } } }
        }
    }
});
<?php endif; ?>

// ── Client chart (client tab) ─────────────────────────────────
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
                backgroundColor: 'rgba(139,92,246,.7)',
                borderRadius: 5, borderWidth: 0
            },
            {
                label: 'Visits',
                data: <?= json_encode(array_map(fn($c) => (int)$c['total_visits'], $topN)) ?>,
                backgroundColor: 'rgba(201,168,76,.5)',
                borderRadius: 5, borderWidth: 0
            }
        ]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
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