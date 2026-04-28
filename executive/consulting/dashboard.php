<?php
/**
 * consulting/executive/index.php — Executive Dashboard
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];


$branchId = (int)$user['branch_id'];
$deptId   = (int)$user['department_id'];
$selectedBranch = $_GET['branch'] ?? 'all';

$branchFilterSQL = '';
if ($selectedBranch !== 'all') {
    $branchFilterSQL = "AND branch_id=".(int)$selectedBranch;
}
$now        = new DateTime();
$month      = $_GET['month'] ?? $now->format('Y-m');
$monthDate  = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthLabel = $monthDate->format('F Y');

// ── BRANCH-WIDE KPIs ────────────────────────────────────────────
$totalLogs = (int)$db->query("
    SELECT COUNT(*) FROM work_logs
    WHERE 1=1 $branchFilterSQL AND month_year='{$month}'
")->fetchColumn();

$totalHours = (float)$db->query("
    SELECT COALESCE(SUM(duration_hours),0) FROM work_logs
    WHERE 1=1 $branchFilterSQL AND month_year='{$month}'
")->fetchColumn();

$totalClients = (int)$db->query("
    SELECT COUNT(DISTINCT client_id) FROM work_logs
    WHERE 1=1 $branchFilterSQL AND month_year='{$month}'
")->fetchColumn();

// NEW
$activeStaff = (int)$db->query("
    SELECT COUNT(DISTINCT wl.user_id)
    FROM work_logs wl
    WHERE wl.month_year = '{$month}'
      $branchFilterSQL
      AND wl.user_id IN (
          -- Primary department on users table
          SELECT u.id FROM users u
          JOIN departments d ON d.id = u.department_id AND d.dept_code = 'CON'
          WHERE u.is_active = 1
          UNION
          -- Secondary/multi department assignments
          SELECT uda.user_id FROM user_department_assignments uda
          JOIN departments d ON d.id = uda.department_id AND d.dept_code = 'CON'
      )
")->fetchColumn();

$visitedCnt     = (int)$db->query("SELECT COUNT(*) FROM work_logs WHERE 1=1 $branchFilterSQL AND month_year='{$month}' AND visit_status='visited'")->fetchColumn();
$missedCnt      = (int)$db->query("SELECT COUNT(*) FROM work_logs WHERE 1=1 $branchFilterSQL AND month_year='{$month}' AND visit_status='missed'")->fetchColumn();
$rescheduledCnt = (int)$db->query("SELECT COUNT(*) FROM work_logs WHERE 1=1 $branchFilterSQL AND month_year='{$month}' AND visit_status='rescheduled'")->fetchColumn();

$pendingApprovals = (int)$db->query("
    SELECT COUNT(*) FROM work_plans
    WHERE 1=1 $branchFilterSQL AND status='submitted'
")->fetchColumn();

// Branch planned hours this month
$plannedHours = (float)$db->query("
    SELECT COALESCE(SUM(wpe.planned_hours),0)
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id=wpe.plan_id
    WHERE 1=1 $branchFilterSQL AND wp.plan_month='{$monthStart}'
")->fetchColumn();

$rawEff   = $plannedHours > 0 ? round(($totalHours / $plannedHours) * 100) : 0;
$efficiency = min($rawEff, 100);

// ── STAFF PERFORMANCE TABLE ─────────────────────────────────────
$staffPerf = $db->query("
    SELECT u.id, u.full_name, u.employee_id,
           COUNT(wl.id)                          AS log_count,
           COALESCE(SUM(wl.duration_hours),0)    AS actual_hours,
           SUM(wl.visit_status='visited')        AS visited,
           SUM(wl.visit_status='missed')         AS missed,
           SUM(wl.visit_status='rescheduled')    AS rescheduled,
           COUNT(DISTINCT wl.client_id)          AS unique_clients
    FROM users u
    LEFT JOIN work_logs wl
           ON wl.user_id = u.id
           AND wl.month_year = '{$month}'
           AND wl.branch_id = {$branchId}
    WHERE u.is_active = 1
      $branchFilterSQL
      AND u.id IN (
          SELECT u2.id FROM users u2
          JOIN departments d ON d.id = u2.department_id AND d.dept_code = 'CON'
          WHERE u2.is_active = 1
          UNION
          SELECT uda.user_id FROM user_department_assignments uda
          JOIN departments d ON d.id = uda.department_id AND d.dept_code = 'CON'
      )
    GROUP BY u.id, u.full_name, u.employee_id
    ORDER BY actual_hours DESC
")->fetchAll();

// planned hours per staff
$staffPlanned = $db->query("
    SELECT wpe.assigned_to, COALESCE(SUM(wpe.planned_hours),0) AS planned
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    WHERE wp.plan_month = '{$monthStart}'
      $branchFilterSQL
      AND wpe.assigned_to IN (
          SELECT u.id FROM users u
          JOIN departments d ON d.id = u.department_id AND d.dept_code = 'CON'
          WHERE u.is_active = 1
          UNION
          SELECT uda.user_id FROM user_department_assignments uda
          JOIN departments d ON d.id = uda.department_id AND d.dept_code = 'CON'
      )
    GROUP BY wpe.assigned_to
")->fetchAll(PDO::FETCH_KEY_PAIR);

// ── CLIENT VISIT SUMMARY ─────────────────────────────────────────
$clientSummary = $db->query("
    SELECT c.id, c.company_name, c.company_code,
           COUNT(wl.id)                       AS total_visits,
           COUNT(DISTINCT wl.user_id)         AS staff_count,
           COALESCE(SUM(wl.duration_hours),0) AS total_hours,
           SUM(wl.visit_status='visited')     AS visited,
           SUM(wl.visit_status='missed')      AS missed,
           MAX(wl.log_date)                   AS last_visit
    FROM companies c
    LEFT JOIN work_logs wl ON wl.client_id=c.id AND wl.month_year='{$month}' AND wl.branch_id={$branchId}
    WHERE c.branch_id={$branchId} AND c.is_active=1
    GROUP BY c.id
    ORDER BY total_visits DESC
    LIMIT 10
")->fetchAll();

// Staff who visited each client
$clientStaff = $db->query("
    SELECT wl.client_id, u.full_name, COUNT(*) cnt
    FROM work_logs wl
    JOIN users u ON u.id=wl.user_id
    WHERE wl.month_year='{$month}' AND wl.branch_id={$branchId}
    GROUP BY wl.client_id, u.id
")->fetchAll();
$csMap = [];
foreach ($clientStaff as $cs) {
    $csMap[$cs['client_id']][] = $cs['full_name'];
}

// ── PENDING APPROVAL QUEUE ───────────────────────────────────────
$pendingPlans = $db->query("
    SELECT wp.*, u.full_name, u.employee_id,
           COUNT(wpe.id) entry_count,
           COALESCE(SUM(wpe.planned_hours),0) planned_hours
    FROM work_plans wp
    JOIN users u ON u.id=wp.user_id
    LEFT JOIN work_plan_entries wpe ON wpe.plan_id=wp.id
    WHERE wp.branch_id={$branchId} AND wp.status='submitted'
    GROUP BY wp.id
    ORDER BY wp.created_at ASC
    LIMIT 8
")->fetchAll();

// ── MONTHLY TREND (last 6 months) ────────────────────────────────
$trend = $db->query("
    SELECT month_year,
           COUNT(*) AS logs,
           COALESCE(SUM(duration_hours),0) AS hours,
           SUM(visit_status='visited') AS visited,
           SUM(visit_status='missed')  AS missed
    FROM work_logs
    WHERE 1=1 $branchFilterSQL
    GROUP BY month_year
    ORDER BY month_year DESC
    LIMIT 6
")->fetchAll();
$trend = array_reverse($trend);

// ── UNVISITED CLIENTS (0 logs this month) ───────────────────────
$unvisited = $db->query("
    SELECT c.company_name, c.company_code
    FROM companies c
    WHERE 1=1 $branchFilterSQL AND c.is_active=1
      AND c.id NOT IN (
          SELECT DISTINCT client_id FROM work_logs
          WHERE month_year='{$month}' $branchFilterSQL
      )
    LIMIT 8
")->fetchAll();

// ── STAFF WITH NO LOGS ───────────────────────────────────────────
$noLogStaff = $db->query("
    SELECT DISTINCT u.full_name, u.employee_id
    FROM users u
    WHERE u.is_active = 1
      $branchFilterSQL
      AND u.id IN (
          SELECT u2.id FROM users u2
          JOIN departments d ON d.id = u2.department_id AND d.dept_code = 'CON'
          WHERE u2.is_active = 1
          UNION
          SELECT uda.user_id FROM user_department_assignments uda
          JOIN departments d ON d.id = uda.department_id AND d.dept_code = 'CON'
      )
      AND u.id NOT IN (
          SELECT DISTINCT user_id FROM work_logs
          WHERE month_year = '{$month}'
            $branchFilterSQL
      )
")->fetchAll();

$pageTitle = 'Executive Dashboard';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="../../../staff/planning/consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
<style>
.exec-section-title {
    font-size:.78rem;font-weight:700;color:#9ca3af;
    text-transform:uppercase;letter-spacing:.06em;
    margin:0 0 12px 0;
}
.staff-eff-bar {
    height:5px;border-radius:99px;background:#f1f5f9;overflow:hidden;margin-top:4px;
}
.staff-eff-fill { height:100%;border-radius:99px; }
.alert-chip {
    display:inline-flex;align-items:center;gap:5px;
    padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;
}
.trend-bar-wrap { display:flex;align-items:flex-end;gap:6px;height:60px; }
.trend-bar { flex:1;border-radius:4px 4px 0 0;min-width:24px;position:relative;cursor:pointer; }
.trend-label { font-size:.65rem;color:#9ca3af;text-align:center;margin-top:4px; }
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
                        <h4>Executive Dashboard</h4>
                        <p><?= htmlspecialchars($user['full_name']) ?> · <?= $monthLabel ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="month" class="form-control form-control-sm" style="width:155px;"
                            value="<?= $month ?>" onchange="location='?month='+this.value">
                        <?php if ($pendingApprovals > 0): ?>
                        <a href="plans.php?status=submitted&month=<?= $month ?>" class="btn btn-sm btn-warning">
                            <i class="fas fa-bell me-1"></i> <?= $pendingApprovals ?> Pending
                        </a>
                        <?php endif; ?>
                        <a href="create_plan.php" class="btn btn-sm btn-gold">
                            <i class="fas fa-plus me-1"></i> Create Plan
                        </a>
                        <a href="staff_report.php?month=<?= $month ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-chart-bar me-1"></i> Reports
                        </a>
                    </div>
                    <select class="form-control form-control-sm" style="width:170px;"
    onchange="location='?month=<?= $month ?>&branch='+this.value">

    <option value="all" <?= $selectedBranch=='all'?'selected':'' ?>>All Branches</option>

    <?php
    $branches = $db->query("SELECT id, branch_name FROM branches ORDER BY branch_name")->fetchAll();
    foreach ($branches as $b):
    ?>
        <option value="<?= $b['id'] ?>" <?= $selectedBranch==$b['id']?'selected':'' ?>>
            <?= htmlspecialchars($b['branch_name']) ?>
        </option>
    <?php endforeach; ?>
</select>
                </div>
            </div>

            <!-- KPI ROW -->
            <div class="kpi-row mb-4">
                <div class="kpi-tile" style="--kpi-color:#3b82f6;">
                    <div class="kpi-icon"><i class="fas fa-users" style="color:#3b82f6;"></i></div>
                    <div class="kpi-val"><?= $activeStaff ?></div>
                    <div class="kpi-label">Active Staff</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#8b5cf6;">
                    <div class="kpi-icon"><i class="fas fa-clipboard-list" style="color:#8b5cf6;"></i></div>
                    <div class="kpi-val"><?= $totalLogs ?></div>
                    <div class="kpi-label">Total Logs</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#c9a84c;">
                    <div class="kpi-icon"><i class="fas fa-clock" style="color:#c9a84c;"></i></div>
                    <div class="kpi-val"><?= number_format($totalHours,1) ?>h</div>
                    <div class="kpi-label">Hours Logged</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#0ea5e9;">
                    <div class="kpi-icon"><i class="fas fa-building" style="color:#0ea5e9;"></i></div>
                    <div class="kpi-val"><?= $totalClients ?></div>
                    <div class="kpi-label">Clients Reached</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#10b981;">
                    <div class="kpi-icon"><i class="fas fa-check-circle" style="color:#10b981;"></i></div>
                    <div class="kpi-val"><?= $visitedCnt ?></div>
                    <div class="kpi-label">Visited</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#ef4444;">
                    <div class="kpi-icon"><i class="fas fa-times-circle" style="color:#ef4444;"></i></div>
                    <div class="kpi-val"><?= $missedCnt ?></div>
                    <div class="kpi-label">Missed</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#f59e0b;">
                    <div class="kpi-icon"><i class="fas fa-bell" style="color:#f59e0b;"></i></div>
                    <div class="kpi-val"><?= $pendingApprovals ?></div>
                    <div class="kpi-label">Pending Approval</div>
                </div>
                <?php $ec = $rawEff >= 80 ? '#10b981' : ($rawEff >= 50 ? '#f59e0b' : '#ef4444'); ?>
                <div class="kpi-tile" style="--kpi-color:<?= $ec ?>;">
                    <div class="kpi-icon"><i class="fas fa-tachometer-alt" style="color:<?= $ec ?>;"></i></div>
                    <div class="kpi-val" style="color:<?= $ec ?>;"><?= $efficiency ?>%</div>
                    <div class="kpi-label">Team Efficiency</div>
                    <div class="kpi-delta" style="color:#9ca3af;font-size:.68rem;"><?= number_format($plannedHours,1) ?>h planned</div>
                </div>
            </div>

            <!-- ROW 1: Progress + Trend -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

                <!-- Planned vs Actual -->
                <div class="cn-panel">
                    <div class="cn-panel-hd">
                        <span class="cn-panel-title"><i class="fas fa-chart-line me-2" style="color:var(--gold)"></i>Planned vs Actual</span>
                        <span style="font-size:.72rem;color:#9ca3af;"><?= $monthLabel ?></span>
                    </div>
                    <div style="padding:14px 16px;">
                        <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#9ca3af;margin-bottom:4px;">
                            <span>Planned</span><strong style="color:#3b82f6;"><?= number_format($plannedHours,1) ?>h</strong>
                        </div>
                        <div style="background:#f1f5f9;border-radius:99px;height:8px;overflow:hidden;margin-bottom:12px;">
                            <div style="width:100%;height:100%;background:#3b82f6;border-radius:99px;"></div>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#9ca3af;margin-bottom:4px;">
                            <span>Actual</span><strong style="color:<?= $ec ?>;"><?= number_format($totalHours,1) ?>h</strong>
                        </div>
                        <div style="background:#f1f5f9;border-radius:99px;height:8px;overflow:hidden;margin-bottom:14px;">
                            <?php $aw = $plannedHours > 0 ? min(round(($totalHours/$plannedHours)*100),100) : 0; ?>
                            <div style="width:<?= $aw ?>%;height:100%;background:<?= $ec ?>;border-radius:99px;"></div>
                        </div>
                        <?php if ($totalLogs > 0): ?>
                        <div style="font-size:.72rem;color:#9ca3af;margin-bottom:5px;">Visit Status</div>
                        <div style="display:flex;border-radius:6px;overflow:hidden;height:10px;margin-bottom:6px;">
                            <?php if ($visitedCnt): ?><div style="flex:<?= $visitedCnt ?>;background:#10b981;" title="Visited"></div><?php endif; ?>
                            <?php if ($missedCnt): ?><div style="flex:<?= $missedCnt ?>;background:#ef4444;" title="Missed"></div><?php endif; ?>
                            <?php if ($rescheduledCnt): ?><div style="flex:<?= $rescheduledCnt ?>;background:#f59e0b;" title="Rescheduled"></div><?php endif; ?>
                        </div>
                        <div style="display:flex;gap:12px;font-size:.72rem;flex-wrap:wrap;">
                            <span style="color:#10b981;">● Visited <?= $visitedCnt ?></span>
                            <span style="color:#ef4444;">● Missed <?= $missedCnt ?></span>
                            <span style="color:#f59e0b;">● Rescheduled <?= $rescheduledCnt ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Monthly Trend Chart -->
                <div class="cn-panel">
                    <div class="cn-panel-hd">
                        <span class="cn-panel-title"><i class="fas fa-chart-bar me-2" style="color:var(--gold)"></i>Monthly Trend</span>
                        <span style="font-size:.72rem;color:#9ca3af;">Last 6 months</span>
                    </div>
                    <div style="padding:14px 16px;">
                        <?php if (empty($trend)): ?>
                        <div style="text-align:center;color:#9ca3af;font-size:.8rem;padding:20px 0;">No data yet</div>
                        <?php else:
                            $maxH2 = max(array_column($trend,'hours') ?: [1]);
                        ?>
                        <div class="trend-bar-wrap">
                            <?php foreach ($trend as $t):
                                $barH = $maxH2 > 0 ? round(($t['hours']/$maxH2)*56) : 0;
                                $barH = max($barH, 3);
                                $bc   = $t['visited'] >= $t['missed'] ? '#10b981' : '#ef4444';
                            ?>
                            <div style="flex:1;display:flex;flex-direction:column;align-items:center;">
                                <div title="<?= $t['month_year'] ?>: <?= number_format($t['hours'],1) ?>h · <?= $t['logs'] ?> logs"
                                     class="trend-bar"
                                     style="height:<?= $barH ?>px;background:<?= $bc ?>;opacity:.85;">
                                </div>
                                <div class="trend-label"><?= date('M', strtotime($t['month_year'].'-01')) ?></div>
                                <div style="font-size:.6rem;color:#c9a84c;font-weight:700;"><?= number_format($t['hours'],0) ?>h</div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="display:flex;gap:10px;font-size:.7rem;margin-top:10px;flex-wrap:wrap;">
                            <span style="color:#10b981;">● More visited months</span>
                            <span style="color:#ef4444;">● More missed months</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ROW 2: Staff Performance + Pending Approvals -->
            <div style="display:grid;grid-template-columns:1fr 340px;gap:16px;margin-bottom:16px;">

                <!-- Staff Performance -->
                <div class="cn-panel">
                    <div class="cn-panel-hd" style="justify-content:space-between;">
                        <span class="cn-panel-title"><i class="fas fa-users me-2" style="color:var(--gold)"></i>Staff Performance</span>
                        <a href="staff_report.php?month=<?= $month ?>" style="font-size:.75rem;color:#3b82f6;">
                            Full Report <i class="fas fa-chevron-right" style="font-size:.65rem;"></i>
                        </a>
                    </div>
                    <div style="padding:0;">
                        <table class="cn-table">
                            <thead>
                                <tr>
                                    <th>Staff</th>
                                    <th class="text-center">Logs</th>
                                    <th class="text-center">Hours</th>
                                    <th class="text-center">Visited</th>
                                    <th class="text-center">Missed</th>
                                    <th class="text-center">Clients</th>
                                    <th style="width:100px;">Efficiency</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($staffPerf as $sp):
                                $planned = $staffPlanned[$sp['id']] ?? 0;
                                $eff = $planned > 0 ? min(round(($sp['actual_hours']/$planned)*100),100) : 0;
                                $effC = $eff >= 80 ? '#10b981' : ($eff >= 50 ? '#f59e0b' : '#ef4444');
                                $noLogs = ($sp['log_count'] == 0);
                            ?>
                            <tr style="<?= $noLogs ? 'opacity:.55;' : '' ?>">
                                <td>
                                    <div style="font-weight:600;font-size:.82rem;"><?= htmlspecialchars($sp['full_name']) ?></div>
                                    <div style="font-size:.68rem;color:#9ca3af;"><?= htmlspecialchars($sp['employee_id'] ?? '') ?></div>
                                    <?php if ($noLogs): ?>
                                    <span class="alert-chip" style="background:#fef2f2;color:#ef4444;margin-top:2px;">
                                        <i class="fas fa-exclamation-circle"></i> No logs
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><strong><?= $sp['log_count'] ?></strong></td>
                                <td class="text-center"><strong style="color:#c9a84c;"><?= number_format($sp['actual_hours'],1) ?>h</strong></td>
                                <td class="text-center"><span style="color:#10b981;font-weight:700;"><?= $sp['visited'] ?></span></td>
                                <td class="text-center"><span style="color:#ef4444;font-weight:700;"><?= $sp['missed'] ?></span></td>
                                <td class="text-center"><?= $sp['unique_clients'] ?></td>
                                <td>
                                    <?php if ($planned > 0): ?>
                                    <div style="font-size:.72rem;font-weight:700;color:<?= $effC ?>;"><?= $eff ?>%</div>
                                    <div class="staff-eff-bar">
                                        <div class="staff-eff-fill" style="width:<?= $eff ?>%;background:<?= $effC ?>;"></div>
                                    </div>
                                    <?php else: ?>
                                    <span style="font-size:.7rem;color:#d1d5db;">No plan</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($staffPerf)): ?>
                            <tr><td colspan="7" style="text-align:center;color:#9ca3af;font-size:.8rem;padding:20px;">No staff data this month</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Right column -->
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <!-- Pending Approvals -->
                    <div class="cn-panel">
                        <div class="cn-panel-hd" style="justify-content:space-between;">
                            <span class="cn-panel-title"><i class="fas fa-bell me-2" style="color:var(--gold)"></i>Pending Approvals</span>
                            <a href="plans.php?status=submitted" style="font-size:.75rem;color:#3b82f6;">View all</a>
                        </div>
                        <div style="padding:10px 14px;display:flex;flex-direction:column;gap:8px;">
                        <?php if (empty($pendingPlans)): ?>
                            <div style="text-align:center;color:#9ca3af;font-size:.8rem;padding:16px 0;">
                                <i class="fas fa-check-circle" style="color:#10b981;font-size:1.4rem;display:block;margin-bottom:6px;"></i>
                                All caught up!
                            </div>
                        <?php else: ?>
                            <?php foreach ($pendingPlans as $pp): ?>
                            <div style="background:#f9fafb;border-radius:8px;padding:9px 11px;border-left:3px solid #3b82f6;">
                                <div style="font-size:.8rem;font-weight:700;"><?= htmlspecialchars($pp['full_name']) ?></div>
                                <div style="font-size:.7rem;color:#9ca3af;margin-bottom:6px;">
                                    Week <?= $pp['week_number'] ?> · <?= $pp['entry_count'] ?> entries · <?= number_format($pp['planned_hours'],1) ?>h
                                </div>
                                <div style="display:flex;gap:6px;">
                                    <a href="plan_view.php?id=<?= $pp['id'] ?>" class="cn-btn cn-btn-blue cn-btn-sm" style="flex:1;justify-content:center;">
                                        <i class="fas fa-eye"></i> Review
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- At-Risk Alerts -->
                    <?php if (!empty($noLogStaff) || !empty($unvisited)): ?>
                    <div class="cn-panel">
                        <div class="cn-panel-hd">
                            <span class="cn-panel-title"><i class="fas fa-exclamation-triangle me-2" style="color:#ef4444"></i>Alerts</span>
                        </div>
                        <div style="padding:10px 14px;display:flex;flex-direction:column;gap:10px;">
                            <?php if (!empty($noLogStaff)): ?>
                            <div>
                                <div style="font-size:.72rem;font-weight:700;color:#ef4444;margin-bottom:5px;">
                                    <i class="fas fa-user-times me-1"></i>Staff with No Logs
                                </div>
                                <?php foreach ($noLogStaff as $ns): ?>
                                <div style="font-size:.75rem;padding:2px 0;color:#374151;">
                                    <?= htmlspecialchars($ns['full_name']) ?>
                                    <span style="color:#9ca3af;"><?= $ns['employee_id'] ? '· '.$ns['employee_id'] : '' ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($unvisited)): ?>
                            <div>
                                <div style="font-size:.72rem;font-weight:700;color:#f59e0b;margin-bottom:5px;">
                                    <i class="fas fa-building me-1"></i>Unvisited Clients
                                </div>
                                <?php foreach ($unvisited as $uv): ?>
                                <div style="font-size:.75rem;padding:2px 0;color:#374151;">
                                    <?= htmlspecialchars($uv['company_name']) ?>
                                    <span style="color:#9ca3af;"><?= $uv['company_code'] ? '· '.$uv['company_code'] : '' ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- ROW 3: Client Visit Summary -->
            <div class="cn-panel mb-4">
                <div class="cn-panel-hd" style="justify-content:space-between;">
                    <span class="cn-panel-title"><i class="fas fa-building me-2" style="color:var(--gold)"></i>Client Visit Summary — <?= $monthLabel ?></span>
                    <a href="client_report.php?month=<?= $month ?>" style="font-size:.75rem;color:#3b82f6;">
                        Full Report <i class="fas fa-chevron-right" style="font-size:.65rem;"></i>
                    </a>
                </div>
                <div style="padding:0;">
                    <table class="cn-table">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th class="text-center">Visits</th>
                                <th class="text-center">Hours</th>
                                <th class="text-center">Visited</th>
                                <th class="text-center">Missed</th>
                                <th class="text-center">Last Visit</th>
                                <th>Staff Who Visited</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($clientSummary as $cs): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:.82rem;"><?= htmlspecialchars($cs['company_name']) ?></div>
                                <div style="font-size:.68rem;color:#9ca3af;"><?= htmlspecialchars($cs['company_code'] ?? '') ?></div>
                            </td>
                            <td class="text-center"><strong><?= $cs['total_visits'] ?></strong></td>
                            <td class="text-center"><strong style="color:#c9a84c;"><?= number_format($cs['total_hours'],1) ?>h</strong></td>
                            <td class="text-center"><span style="color:#10b981;font-weight:700;"><?= $cs['visited'] ?></span></td>
                            <td class="text-center"><span style="color:#ef4444;font-weight:700;"><?= $cs['missed'] ?></span></td>
                            <td class="text-center" style="font-size:.78rem;color:#6b7280;">
                                <?= $cs['last_visit'] ? date('d M Y', strtotime($cs['last_visit'])) : '—' ?>
                            </td>
                            <td style="font-size:.75rem;color:#374151;">
                                <?php
                                $names = $csMap[$cs['id']] ?? [];
                                echo $names ? implode(', ', array_map('htmlspecialchars', array_slice($names,0,3))) : '<span style="color:#d1d5db;">None</span>';
                                if (count($names) > 3) echo ' <span style="color:#9ca3af;">+'.( count($names)-3).' more</span>';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($clientSummary)): ?>
                        <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:20px;font-size:.8rem;">No client data this month</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>