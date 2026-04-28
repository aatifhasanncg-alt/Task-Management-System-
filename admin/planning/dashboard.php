<?php
/**
 * consulting/dashboard.php — Performance Dashboard
 * Shows logged-in user's own performance: client-wise, date-wise, totals
 * Admin: also sees team summary
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db          = getDB();
$user        = currentUser();
$uid         = (int)$user['id'];
$currentRole = $_SESSION['role'] ?? ($user['role'] ?? '');
$isAdmin     = in_array($currentRole, ['admin', 'executive']);
$deptId      = (int)$user['department_id'];

$branchId    = (int)$user['branch_id'];
// ── UDA consulting dept detection ─────────────────────────────
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
} elseif ($__isCoreAdmin && $__udaCons) {
    $deptId = (int) $__udaCons['id'];
} elseif ($__udaCons) {
    $deptId = (int) $__udaCons['id'];
}
// $branchId stays unchanged — always use user's actual branch

$now       = new DateTime();
$month     = $_GET['month'] ?? $now->format('Y-m');
$monthDate = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthLabel = $monthDate->format('F Y');

// ── Planned hours for the month (for the logged-in user) ─────────────────────
$plannedHours = (float)$db->prepare("
    SELECT COALESCE(SUM(wpe.planned_hours), 0)
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    WHERE wpe.assigned_to = ? AND wp.plan_month = ? AND wp.department_id = ?
")->execute([$uid, $monthDate->format('Y-m-01'), $deptId])
    ? $db->query("
        SELECT COALESCE(SUM(wpe.planned_hours), 0)
        FROM work_plan_entries wpe
        JOIN work_plans wp ON wp.id = wpe.plan_id
        WHERE wpe.assigned_to = {$uid}
          AND wp.plan_month = '{$monthDate->format('Y-m-01')}'
          AND wp.department_id = {$deptId}
    ")->fetchColumn()
    : 0;

// ── Total actual hours (logged-in user, this month) ───────────────────────────
$actualHours = (float)$db->query("
    SELECT COALESCE(SUM(duration_hours), 0)
    FROM work_logs
    WHERE user_id = {$uid} AND month_year = '{$month}' AND department_id = {$deptId}
")->fetchColumn();

$efficiencyRaw = $plannedHours > 0 ? round(($actualHours / $plannedHours) * 100, 1) : 0;
$efficiency    = min($efficiencyRaw, 100); // capped at 100% for display

// ── Visit status breakdown ────────────────────────────────────────────────────
$vstRows = $db->query("
    SELECT visit_status, COUNT(*) AS cnt
    FROM work_logs
    WHERE user_id = {$uid} AND month_year = '{$month}' AND department_id = {$deptId}
    GROUP BY visit_status
")->fetchAll();
$vst = [];
foreach ($vstRows as $r) $vst[$r['visit_status']] = (int)$r['cnt'];
$visited     = $vst['visited']     ?? 0;
$missed      = $vst['missed']      ?? 0;
$rescheduled = $vst['rescheduled'] ?? 0;
$totalLogs   = $visited + $missed + $rescheduled;

// ── Unique clients visited ────────────────────────────────────────────────────
$uniqueClients = (int)$db->query("
    SELECT COUNT(DISTINCT client_id)
    FROM work_logs
    WHERE user_id = {$uid} AND month_year = '{$month}' AND department_id = {$deptId}
")->fetchColumn();

// ── CLIENT-WISE PERFORMANCE ───────────────────────────────────────────────────
$clientPerf = $db->query("
    SELECT
        c.id AS client_id,
        c.company_name,
        c.company_code,
        COUNT(wl.id)                            AS total_visits,
        SUM(wl.visit_status = 'visited')        AS visited,
        SUM(wl.visit_status = 'missed')         AS missed,
        SUM(wl.visit_status = 'rescheduled')    AS rescheduled,
        COALESCE(SUM(wl.duration_hours), 0)     AS actual_hours,
        COALESCE(
            (SELECT SUM(wpe.planned_hours)
             FROM work_plan_entries wpe
             JOIN work_plans wp ON wp.id = wpe.plan_id
             WHERE wpe.assigned_to = {$uid}
               AND wpe.client_id   = c.id
               AND wp.plan_month   = '{$monthDate->format('Y-m-01')}'
               AND wp.department_id = {$deptId}
            ), 0
        ) AS planned_hours,
        MIN(wl.log_date) AS first_visit,
        MAX(wl.log_date) AS last_visit
    FROM work_logs wl
    LEFT JOIN companies c ON c.id = wl.client_id
    WHERE wl.user_id = {$uid}
      AND wl.month_year = '{$month}'
      AND wl.department_id = {$deptId}
    GROUP BY c.id, c.company_name, c.company_code
    ORDER BY actual_hours DESC, total_visits DESC
")->fetchAll();

// ── DATE-WISE PERFORMANCE ─────────────────────────────────────────────────────
$datePerf = $db->query("
    SELECT
        wl.log_date,
        wl.day_of_week,
        COUNT(wl.id)                            AS total_visits,
        SUM(wl.visit_status = 'visited')        AS visited,
        SUM(wl.visit_status = 'missed')         AS missed,
        SUM(wl.visit_status = 'rescheduled')    AS rescheduled,
        COALESCE(SUM(wl.duration_hours), 0)     AS actual_hours,
        GROUP_CONCAT(c.company_name ORDER BY wl.time_in SEPARATOR ', ') AS clients_visited
    FROM work_logs wl
    LEFT JOIN companies c ON c.id = wl.client_id
    WHERE wl.user_id = {$uid}
      AND wl.month_year = '{$month}'
      AND wl.department_id = {$deptId}
    GROUP BY wl.log_date, wl.day_of_week
    ORDER BY wl.log_date ASC
")->fetchAll();

// ── MONTHLY TREND (last 6 months) ─────────────────────────────────────────────
$trendRows = $db->query("
    SELECT
        month_year,
        COALESCE(SUM(duration_hours), 0) AS actual_hours,
        COUNT(*) AS total_logs,
        SUM(visit_status = 'visited') AS visited
    FROM work_logs
    WHERE user_id = {$uid} AND department_id = {$deptId}
    GROUP BY month_year
    ORDER BY month_year DESC
    LIMIT 6
")->fetchAll();
$trendRows = array_reverse($trendRows);

// ── TEAM SUMMARY (admin view of own subordinates) ────────────────────────────
$teamRows = [];
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
    $scopeIds = buildScopeList($db, $user, true);
    $inList   = implode(',', array_filter($scopeIds, fn($id) => $id !== $uid));
    if ($inList) {
        $teamRows = $db->query("
            SELECT
                u.full_name,
                u.employee_id,
                COUNT(wl.id)                         AS total_logs,
                COALESCE(SUM(wl.duration_hours), 0)  AS actual_hours,
                SUM(wl.visit_status = 'visited')     AS visited,
                SUM(wl.visit_status = 'missed')      AS missed,
                COUNT(DISTINCT wl.client_id)         AS unique_clients
            FROM users u
            LEFT JOIN work_logs wl ON wl.user_id = u.id
              AND wl.month_year = '{$month}'
              AND wl.department_id = {$deptId}
            WHERE u.id IN ({$inList}) AND u.is_active = 1
            GROUP BY u.id, u.full_name, u.employee_id
            ORDER BY actual_hours DESC
        ")->fetchAll();
    }
}

// Chart data
$chartLabels = array_column($trendRows, 'month_year');
$chartHours  = array_map(fn($r) => (float)$r['actual_hours'], $trendRows);
$chartLogs   = array_map(fn($r) => (int)$r['total_logs'],     $trendRows);


$pageTitle = 'Performance Dashboard';
include '../../includes/header.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<div class="app-wrapper">
    <?php include $isAdmin ? '../../includes/sidebar_admin.php' : '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <!-- ── Page Hero ── -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-chart-bar"></i> Performance</div>
                        <h4>Performance Dashboard</h4>
                        <p>
                            <?= htmlspecialchars($user['full_name']) ?> ·
                            <?= $isAdmin ? 'Admin View' : 'My Performance' ?> ·
                            <?= $monthLabel ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="month" class="form-control form-control-sm" style="width:155px;"
                               value="<?= $month ?>" onchange="location='?month='+this.value">
                        <?php if ($isAdmin): ?>
                            <a href="log_list.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-history me-1"></i>All Logs
                            </a>
                            <a href="plan_list.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-list me-1"></i>All Plans
                            </a>
                        <?php else: ?>
                            <a href="staff/plan_create.php" class="btn-gold btn btn-sm">
                                <i class="fas fa-plus me-1"></i>New Plan
                            </a>
                            <a href="staff/log_create.php" class="btn-gold btn btn-sm">
                                <i class="fas fa-clock me-1"></i>Log Visit
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── KPI Row ── -->
            <div class="kpi-row mb-4">
                <div class="kpi-tile" style="--kpi-color:#3b82f6;">
                    <div class="kpi-icon"><i class="fas fa-clipboard-list" style="color:#3b82f6;"></i></div>
                    <div class="kpi-val"><?= $totalLogs ?></div>
                    <div class="kpi-label">Total Visits</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#c9a84c;">
                    <div class="kpi-icon"><i class="fas fa-clock" style="color:#c9a84c;"></i></div>
                    <div class="kpi-val"><?= number_format($actualHours, 1) ?>h</div>
                    <div class="kpi-label">Actual Hours</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#8b5cf6;">
                    <div class="kpi-icon"><i class="fas fa-building" style="color:#8b5cf6;"></i></div>
                    <div class="kpi-val"><?= $uniqueClients ?></div>
                    <div class="kpi-label">Clients Visited</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#10b981;">
                    <div class="kpi-icon"><i class="fas fa-check-circle" style="color:#10b981;"></i></div>
                    <div class="kpi-val"><?= $visited ?></div>
                    <div class="kpi-label">Visited</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#ef4444;">
                    <div class="kpi-icon"><i class="fas fa-times-circle" style="color:#ef4444;"></i></div>
                    <div class="kpi-val"><?= $missed ?></div>
                    <div class="kpi-label">Missed</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#f59e0b;">
                    <div class="kpi-icon"><i class="fas fa-redo" style="color:#f59e0b;"></i></div>
                    <div class="kpi-val"><?= $rescheduled ?></div>
                    <div class="kpi-label">Rescheduled</div>
                </div>
                <?php $effColor = $efficiency >= 80 ? '#10b981' : ($efficiency >= 50 ? '#f59e0b' : '#ef4444'); ?>
                <div class="kpi-tile" style="--kpi-color:<?= $effColor ?>;">
                    <div class="kpi-icon">
                        <i class="fas fa-tachometer-alt" style="color:<?= $effColor ?>;"></i>
                    </div>
                    <div class="kpi-val" style="color:<?= $effColor ?>;">
                        <?= $efficiency ?>%
                    </div>
                    <div class="kpi-label">Efficiency</div>
                    <div class="kpi-delta" style="color:#9ca3af;font-size:.7rem;">
                        <?= number_format($plannedHours, 1) ?>h planned
                    </div>
                    <?php if ($efficiencyRaw > 100): ?>
                    <div style="font-size:.65rem;color:#f59e0b;margin-top:3px;font-weight:600;">
                        ⚠ <?= $efficiencyRaw ?>% actual (over-delivered)
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Planned vs Actual + Visit breakdown ── -->
            <div class="row g-4 mb-4">
                <div class="col-lg-8">
                    <div class="card-mis h-100">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-chart-line text-warning me-2"></i>Hours Trend — Last 6 Months</h5>
                        </div>
                        <div class="card-mis-body" style="height:270px;">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card-mis h-100">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-chart-pie text-warning me-2"></i>Visit Breakdown</h5>
                        </div>
                        <div class="card-mis-body">
                            <!-- Planned vs Actual bar -->
                            <?php
                            $maxH = max($plannedHours, $actualHours, 1);
                            $pw   = round(($plannedHours / $maxH) * 100);
                            $aw   = round(($actualHours  / $maxH) * 100);
                            $ec   = $efficiency >= 80 ? '#10b981' : ($efficiency >= 50 ? '#f59e0b' : '#ef4444');
                            ?>
                            <div style="margin-bottom:16px;">
                                <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#9ca3af;margin-bottom:4px;">
                                    <span>Planned</span>
                                    <strong style="color:#3b82f6;"><?= number_format($plannedHours, 1) ?>h</strong>
                                </div>
                                <div style="background:#f1f5f9;border-radius:99px;height:7px;overflow:hidden;">
                                    <div style="width:<?= $pw ?>%;height:100%;background:#3b82f6;border-radius:99px;"></div>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#9ca3af;margin:8px 0 4px;">
                                    <span>Actual</span>
                                    <strong style="color:<?= $ec ?>;"><?= number_format($actualHours, 1) ?>h</strong>
                                </div>
                                <div style="background:#f1f5f9;border-radius:99px;height:7px;overflow:hidden;">
                                    <div style="width:<?= $aw ?>%;height:100%;background:<?= $ec ?>;border-radius:99px;"></div>
                                </div>
                            </div>
                            <hr style="margin:12px 0;">
                            <!-- Status breakdown bar -->
                            <?php if ($totalLogs > 0): ?>
                            <div style="font-size:.72rem;color:#9ca3af;margin-bottom:5px;">Visit Status Distribution</div>
                            <div style="display:flex;border-radius:6px;overflow:hidden;height:12px;margin-bottom:6px;">
                                <?php if ($visited):     ?><div style="flex:<?= $visited ?>;background:#10b981;" title="Visited: <?= $visited ?>"></div><?php endif; ?>
                                <?php if ($missed):      ?><div style="flex:<?= $missed ?>;background:#ef4444;"  title="Missed: <?= $missed ?>"></div><?php endif; ?>
                                <?php if ($rescheduled): ?><div style="flex:<?= $rescheduled ?>;background:#f59e0b;" title="Rescheduled: <?= $rescheduled ?>"></div><?php endif; ?>
                            </div>
                            <div style="display:flex;gap:10px;font-size:.72rem;flex-wrap:wrap;">
                                <span style="color:#10b981;">● Visited <?= $visited ?> (<?= $totalLogs ? round($visited/$totalLogs*100) : 0 ?>%)</span>
                                <span style="color:#ef4444;">● Missed <?= $missed ?></span>
                                <span style="color:#f59e0b;">● Rescheduled <?= $rescheduled ?></span>
                            </div>
                            <?php else: ?>
                            <div style="text-align:center;color:#9ca3af;font-size:.82rem;padding:12px 0;">
                                No logs recorded this month
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Client-wise Performance ── -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-building text-warning me-2"></i>Client-wise Performance — <?= $monthLabel ?></h5>
                    <span style="font-size:.78rem;color:#9ca3af;"><?= count($clientPerf) ?> clients</span>
                </div>
                <?php if (empty($clientPerf)): ?>
                <div class="card-mis-body">
                    <div class="empty-state">
                        <i class="fas fa-building"></i>
                        <h6>No client visits logged for <?= $monthLabel ?></h6>
                        <p>Start logging your client visits to see performance data here.</p>
                    </div>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table-mis w-100" id="clientTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Client</th>
                                <th class="text-center">Visits</th>
                                <th class="text-center">Visited</th>
                                <th class="text-center">Missed</th>
                                <th class="text-center">Rescheduled</th>
                                <th class="text-center">Planned Hrs</th>
                                <th class="text-center">Actual Hrs</th>
                                <th class="text-center">Efficiency</th>
                                <th>First Visit</th>
                                <th>Last Visit</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($clientPerf as $i => $cp):
                            $effRaw = (float)$cp['planned_hours'] > 0
                                ? round(((float)$cp['actual_hours'] / (float)$cp['planned_hours']) * 100)
                                : null;
                            $eff      = $effRaw !== null ? min($effRaw, 100) : null;
                            $effColor = $eff === null ? '#9ca3af' : ($eff >= 80 ? '#10b981' : ($eff >= 50 ? '#f59e0b' : '#ef4444'));
                        ?>
                        <tr>
                            <td style="color:#9ca3af;font-size:.75rem;"><?= $i + 1 ?></td>
                            <td>
                                <div style="font-weight:600;font-size:.85rem;"><?= htmlspecialchars($cp['company_name'] ?? '—') ?></div>
                                <div style="font-size:.7rem;color:#9ca3af;"><?= htmlspecialchars($cp['company_code'] ?? '') ?></div>
                            </td>
                            <td class="text-center"><strong><?= $cp['total_visits'] ?></strong></td>
                            <td class="text-center">
                                <span style="background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;">
                                    <?= $cp['visited'] ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($cp['missed'] > 0): ?>
                                <span style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;">
                                    <?= $cp['missed'] ?>
                                </span>
                                <?php else: ?>
                                <span style="color:#d1d5db;font-size:.78rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($cp['rescheduled'] > 0): ?>
                                <span style="background:#fffbeb;color:#b45309;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;">
                                    <?= $cp['rescheduled'] ?>
                                </span>
                                <?php else: ?>
                                <span style="color:#d1d5db;font-size:.78rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center" style="color:#3b82f6;font-weight:600;">
                                <?= number_format((float)$cp['planned_hours'], 1) ?>h
                            </td>
                            <td class="text-center">
                                <strong style="color:<?= hoursColor((float)$cp['actual_hours']) ?>;">
                                    <?= number_format((float)$cp['actual_hours'], 1) ?>h
                                </strong>
                            </td>
                            <td class="text-center">
                                <?php if ($eff !== null): ?>
                                <div style="display:flex;align-items:center;gap:5px;justify-content:center;">
                                    <div style="flex:1;max-width:55px;background:#f1f5f9;border-radius:99px;height:5px;overflow:hidden;">
                                        <div style="width:<?= $eff ?>%;height:100%;background:<?= $effColor ?>;border-radius:99px;"></div>
                                    </div>
                                    <span style="font-size:.75rem;font-weight:700;color:<?= $effColor ?>;"><?= $eff ?>%</span>
                                </div>
                                <?php if ($effRaw > 100): ?>
                                <div style="font-size:.63rem;color:#f59e0b;margin-top:2px;">⚠ <?= $effRaw ?>% actual</div>
                                <?php endif; ?>
                                <?php else: ?>
                                <span style="font-size:.75rem;color:#9ca3af;">No plan</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.78rem;color:#6b7280;white-space:nowrap;">
                                <?= $cp['first_visit'] ? date('d M', strtotime($cp['first_visit'])) : '—' ?>
                            </td>
                            <td style="font-size:.78rem;color:#6b7280;white-space:nowrap;">
                                <?= $cp['last_visit'] ? date('d M', strtotime($cp['last_visit'])) : '—' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#f9fafb;font-weight:700;">
                                <td colspan="2" style="padding:10px 14px;font-size:.82rem;color:#374151;">
                                    <i class="fas fa-calculator me-1 text-warning"></i>TOTAL
                                </td>
                                <td class="text-center"><?= $totalLogs ?></td>
                                <td class="text-center" style="color:#15803d;"><?= $visited ?></td>
                                <td class="text-center" style="color:#b91c1c;"><?= $missed ?></td>
                                <td class="text-center" style="color:#b45309;"><?= $rescheduled ?></td>
                                <td class="text-center" style="color:#3b82f6;"><?= number_format($plannedHours, 1) ?>h</td>
                                <td class="text-center" style="color:#c9a84c;"><?= number_format($actualHours, 1) ?>h</td>
                                <td class="text-center" style="color:<?= $efficiency >= 80 ? '#10b981' : ($efficiency >= 50 ? '#f59e0b' : '#ef4444') ?>;">
                                    <?= $efficiency ?>%<?= $efficiencyRaw > 100 ? ' <span style="font-size:.68rem;color:#f59e0b;">(⚠ ' . $efficiencyRaw . '% actual)</span>' : '' ?>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Date-wise Performance ── -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-calendar-day text-warning me-2"></i>Date-wise Performance — <?= $monthLabel ?></h5>
                    <span style="font-size:.78rem;color:#9ca3af;"><?= count($datePerf) ?> active day(s)</span>
                </div>
                <?php if (empty($datePerf)): ?>
                <div class="card-mis-body">
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h6>No date-wise data for <?= $monthLabel ?></h6>
                    </div>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table-mis w-100" id="dateTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Day</th>
                                <th class="text-center">Visits</th>
                                <th class="text-center">Visited</th>
                                <th class="text-center">Missed</th>
                                <th class="text-center">Rescheduled</th>
                                <th class="text-center">Hours</th>
                                <th>Clients</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $runHours = 0;
                        foreach ($datePerf as $dp):
                            $runHours += (float)$dp['actual_hours'];
                            $dayVisitRate = $dp['total_visits'] > 0
                                ? round(($dp['visited'] / $dp['total_visits']) * 100)
                                : 0;
                        ?>
                        <tr>
                            <td>
                                <strong style="font-size:.85rem;"><?= date('d M Y', strtotime($dp['log_date'])) ?></strong>
                            </td>
                            <td>
                                <span style="font-size:.78rem;color:#6b7280;"><?= $dp['day_of_week'] ?></span>
                            </td>
                            <td class="text-center"><strong><?= $dp['total_visits'] ?></strong></td>
                            <td class="text-center">
                                <span style="background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;">
                                    <?= $dp['visited'] ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($dp['missed'] > 0): ?>
                                <span style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;">
                                    <?= $dp['missed'] ?>
                                </span>
                                <?php else: ?>
                                <span style="color:#d1d5db;font-size:.78rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($dp['rescheduled'] > 0): ?>
                                <span style="background:#fffbeb;color:#b45309;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;">
                                    <?= $dp['rescheduled'] ?>
                                </span>
                                <?php else: ?>
                                <span style="color:#d1d5db;font-size:.78rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div style="display:flex;align-items:center;gap:5px;justify-content:center;">
                                    <strong style="color:<?= hoursColor((float)$dp['actual_hours']) ?>;">
                                        <?= number_format((float)$dp['actual_hours'], 1) ?>h
                                    </strong>
                                    <span style="font-size:.68rem;color:#9ca3af;">(<?= number_format($runHours, 1) ?>h total)</span>
                                </div>
                            </td>
                            <td style="font-size:.77rem;color:#6b7280;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                title="<?= htmlspecialchars($dp['clients_visited'] ?? '') ?>">
                                <?= htmlspecialchars(mb_strimwidth($dp['clients_visited'] ?? '—', 0, 50, '…')) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#f9fafb;font-weight:700;">
                                <td colspan="2" style="padding:10px 14px;font-size:.82rem;color:#374151;">
                                    <i class="fas fa-calculator me-1 text-warning"></i>TOTAL
                                </td>
                                <td class="text-center"><?= $totalLogs ?></td>
                                <td class="text-center" style="color:#15803d;"><?= $visited ?></td>
                                <td class="text-center" style="color:#b91c1c;"><?= $missed ?></td>
                                <td class="text-center" style="color:#b45309;"><?= $rescheduled ?></td>
                                <td class="text-center" style="color:#c9a84c;"><?= number_format($actualHours, 1) ?>h</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Monthly History ── -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-history text-warning me-2"></i>My Monthly Performance History</h5>
                </div>
                <?php if (empty($trendRows)): ?>
                <div class="card-mis-body">
                    <div class="empty-state">
                        <i class="fas fa-chart-line"></i>
                        <h6>No historical data yet</h6>
                    </div>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table-mis w-100" id="selfTable">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th class="text-center">Total Logs</th>
                                <th class="text-center">Visited</th>
                                <th class="text-center">Actual Hours</th>
                                <th>Visit Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_reverse($trendRows) as $tr):
                            $vRate = $tr['total_logs'] > 0
                                ? round(($tr['visited'] / $tr['total_logs']) * 100)
                                : 0;
                            $vrColor = $vRate >= 80 ? '#10b981' : ($vRate >= 50 ? '#f59e0b' : '#ef4444');
                        ?>
                        <tr <?= $tr['month_year'] === $month ? 'style="background:rgba(201,168,76,.04);"' : '' ?>>
                            <td>
                                <strong style="<?= $tr['month_year'] === $month ? 'color:#c9a84c;' : '' ?>">
                                    <?= date('F Y', strtotime($tr['month_year'] . '-01')) ?>
                                    <?= $tr['month_year'] === $month ? ' ← current' : '' ?>
                                </strong>
                            </td>
                            <td class="text-center"><?= $tr['total_logs'] ?></td>
                            <td class="text-center" style="color:#15803d;font-weight:600;"><?= $tr['visited'] ?></td>
                            <td class="text-center" style="color:#c9a84c;font-weight:700;"><?= number_format((float)$tr['actual_hours'], 1) ?>h</td>
                            <td>
                                <div class="perf-bar">
                                    <div class="perf-bar-track">
                                        <div class="perf-bar-fill" style="width:<?= $vRate ?>%;background:<?= $vrColor ?>;"></div>
                                    </div>
                                    <span style="font-size:.78rem;font-weight:700;color:<?= $vrColor ?>;min-width:35px;"><?= $vRate ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Team Summary (admin only) ── -->
            <?php if ($isAdmin && !empty($teamRows)): ?>
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-users text-warning me-2"></i>Team Performance — <?= $monthLabel ?></h5>
                    <span style="font-size:.78rem;color:#9ca3af;"><?= count($teamRows) ?> staff member(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table-mis w-100" id="staffTable">
                        <thead>
                            <tr>
                                <th>Staff</th>
                                <th class="text-center">Total Logs</th>
                                <th class="text-center">Visited</th>
                                <th class="text-center">Missed</th>
                                <th class="text-center">Clients</th>
                                <th class="text-center">Actual Hours</th>
                                <th>Visit Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($teamRows as $tr):
                            $vr = $tr['total_logs'] > 0
                                ? round(($tr['visited'] / $tr['total_logs']) * 100)
                                : 0;
                            $vrC = $vr >= 80 ? '#10b981' : ($vr >= 50 ? '#f59e0b' : '#ef4444');
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:.85rem;"><?= htmlspecialchars($tr['full_name']) ?></div>
                                <div style="font-size:.7rem;color:#9ca3af;"><?= htmlspecialchars($tr['employee_id'] ?? '') ?></div>
                            </td>
                            <td class="text-center"><?= $tr['total_logs'] ?></td>
                            <td class="text-center" style="color:#15803d;font-weight:600;"><?= $tr['visited'] ?></td>
                            <td class="text-center" style="color:<?= $tr['missed'] > 0 ? '#b91c1c' : '#9ca3af' ?>;font-weight:<?= $tr['missed'] > 0 ? '600' : '400' ?>;">
                                <?= $tr['missed'] ?: '—' ?>
                            </td>
                            <td class="text-center"><?= $tr['unique_clients'] ?></td>
                            <td class="text-center" style="color:#c9a84c;font-weight:700;"><?= number_format((float)$tr['actual_hours'], 1) ?>h</td>
                            <td>
                                <?php if ($tr['total_logs'] > 0): ?>
                                <div class="perf-bar">
                                    <div class="perf-bar-track">
                                        <div class="perf-bar-fill" style="width:<?= $vr ?>%;background:<?= $vrC ?>;"></div>
                                    </div>
                                    <span style="font-size:.78rem;font-weight:700;color:<?= $vrC ?>;min-width:35px;"><?= $vr ?>%</span>
                                </div>
                                <?php else: ?>
                                <span style="font-size:.78rem;color:#9ca3af;">No logs</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php elseif ($isAdmin): ?>
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-users text-warning me-2"></i>Team Performance — <?= $monthLabel ?></h5>
                </div>
                <div class="card-mis-body">
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h6>No team data for <?= $monthLabel ?></h6>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /padding wrapper -->
        <?php include '../../includes/footer.php'; ?>
    </div><!-- /main-content -->
</div><!-- /app-wrapper -->

<script>
const chartLabels = <?= json_encode($chartLabels) ?>;
const chartHours  = <?= json_encode($chartHours) ?>;
const chartLogs   = <?= json_encode($chartLogs) ?>;

new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: chartLabels,
        datasets: [
            {
                label: 'Actual Hours',
                data: chartHours,
                backgroundColor: 'rgba(201,168,76,0.7)',
                borderColor: '#c9a84c',
                borderWidth: 1.5,
                borderRadius: 5,
                yAxisID: 'y'
            },
            {
                label: 'Total Visits',
                data: chartLogs,
                type: 'line',
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59,130,246,0.12)',
                borderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                tension: 0.3,
                fill: false,
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            x: {
                ticks: { color: '#4b5563', font: { size: 11 } },
                grid: { display: false }
            },
            y: {
                position: 'left',
                beginAtZero: true,
                ticks: { color: '#c9a84c', font: { size: 11 }, callback: v => v + 'h' },
                grid: { color: '#f1f5f9' }
            },
            y1: {
                position: 'right',
                beginAtZero: true,
                ticks: { color: '#3b82f6', font: { size: 11 } },
                grid: { drawOnChartArea: false }
            }
        },
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: { font: { size: 11 }, boxWidth: 12, color: '#374151' }
            }
        }
    }
});
</script>