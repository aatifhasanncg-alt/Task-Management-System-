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
                    <div class="kpi-val"><?= $totalLogs ?></div>
                    <div class="kpi-label">Total Logs</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#c9a84c;">
                    <div class="kpi-icon"><i class="fas fa-clock" style="color:#c9a84c;"></i></div>
                    <div class="kpi-val"><?= number_format($totalHours, 1) ?>h</div>
                    <div class="kpi-label">Hours Logged</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#8b5cf6;">
                    <div class="kpi-icon"><i class="fas fa-building" style="color:#8b5cf6;"></i></div>
                    <div class="kpi-val"><?= $totalClients ?></div>
                    <div class="kpi-label">Clients</div>
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
                <?php $effColor = $rawEfficiency >= 80 ? '#10b981' : ($rawEfficiency >= 50 ? '#f59e0b' : '#ef4444'); ?>
                <div class="kpi-tile" style="--kpi-color:<?= $effColor ?>;">
                    <div class="kpi-icon"><i class="fas fa-tachometer-alt" style="color:<?= $effColor ?>;"></i></div>
                    <div class="kpi-val" style="color:<?= $effColor ?>;">
                        <?= $efficiency ?>%
                        <?php if ($overDelivered): ?>
                            <span
                                style="font-size:.6rem;background:#dcfce7;color:#15803d;padding:1px 5px;border-radius:4px;vertical-align:middle;">+<?= $rawEfficiency - 100 ?>%</span>
                        <?php endif; ?>
                    </div>
                    <div class="kpi-label">Efficiency</div>
                    <div class="kpi-delta" style="color:#9ca3af;font-size:.7rem;">
                        <?= number_format($plannedHours, 1) ?>h planned
                        <?php if ($overDelivered): ?> · over target<?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- PROGRESS -->
            <?php
            $maxH = max($plannedHours, $totalHours, 1);
            $pw = round(($plannedHours / $maxH) * 100);
            $aw = round(($totalHours / $maxH) * 100);
            $ec = $rawEfficiency >= 80 ? '#10b981' : ($rawEfficiency >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-chart-line text-warning me-2"></i>Planned vs Actual Hours</h5>
                    <span style="font-size:.78rem;color:#9ca3af;"><?= $monthLabel ?></span>
                </div>
                <div class="card-mis-body">
                    <div
                        style="display:flex;justify-content:space-between;font-size:.75rem;color:#9ca3af;margin-bottom:4px;">
                        <span>Planned</span>
                        <strong style="color:#3b82f6;"><?= number_format($plannedHours, 1) ?>h</strong>
                    </div>
                    <div style="background:#f1f5f9;border-radius:99px;height:8px;overflow:hidden;margin-bottom:12px;">
                        <div style="width:<?= $pw ?>%;height:100%;background:#3b82f6;border-radius:99px;"></div>
                    </div>
                    <div
                        style="display:flex;justify-content:space-between;font-size:.75rem;color:#9ca3af;margin-bottom:4px;">
                        <span>Actual</span>
                        <strong style="color:<?= $ec ?>;"><?= number_format($totalHours, 1) ?>h</strong>
                    </div>
                    <div style="background:#f1f5f9;border-radius:99px;height:8px;overflow:hidden;">
                        <div style="width:<?= $aw ?>%;height:100%;background:<?= $ec ?>;border-radius:99px;"></div>
                    </div>
                    <?php if ($totalLogs > 0): ?>
                        <hr style="margin:14px 0;">
                        <div style="font-size:.72rem;color:#9ca3af;margin-bottom:5px;">Visit Status Distribution</div>
                        <div style="display:flex;border-radius:6px;overflow:hidden;height:10px;margin-bottom:6px;">
                            <?php if ($visited): ?>
                                <div style="flex:<?= $visited ?>;background:#10b981;" title="Visited"></div><?php endif; ?>
                            <?php if ($missed): ?>
                                <div style="flex:<?= $missed ?>;background:#ef4444;" title="Missed"></div><?php endif; ?>
                            <?php if ($rescheduled): ?>
                                <div style="flex:<?= $rescheduled ?>;background:#f59e0b;" title="Rescheduled"></div>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;gap:12px;font-size:.72rem;flex-wrap:wrap;">
                            <span style="color:#10b981;">● Visited <?= $visited ?>
                                (<?= $totalLogs ? round($visited / $totalLogs * 100) : 0 ?>%)</span>
                            <span style="color:#ef4444;">● Missed <?= $missed ?></span>
                            <span style="color:#f59e0b;">● Rescheduled <?= $rescheduled ?></span>
                        </div>
                    <?php endif; ?>
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

                <!-- LOGS -->
                <div class="col-lg-6">
                    <div class="card-mis h-100">
                        <div class="card-mis-header d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-history text-warning me-2"></i>Recent Logs</h5>
                            <a href="log_create.php" class="btn btn-sm btn-gold">
                                <i class="fas fa-plus me-1"></i>Log Visit
                            </a>
                        </div>
                        <?php if (empty($recentLogs)): ?>
                            <div class="card-mis-body text-center text-muted py-4">
                                <i class="fas fa-clock fa-2x mb-2 opacity-25"></i><br>
                                No logs recorded this month.
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
                                            $vsColor = ['visited' => '#10b981', 'missed' => '#ef4444', 'rescheduled' => '#f59e0b'];
                                            $vsBg = ['visited' => '#f0fdf4', 'missed' => '#fef2f2', 'rescheduled' => '#fffbeb'];
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong
                                                        style="font-size:.82rem;"><?= date('d M', strtotime($l['log_date'])) ?></strong>
                                                    <div style="font-size:.68rem;color:#9ca3af;">
                                                        <?= date('D', strtotime($l['log_date'])) ?>
                                                    </div>
                                                </td>
                                                <td
                                                    style="font-size:.82rem;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                    <?= htmlspecialchars($l['company_name'] ?? '—') ?>
                                                </td>
                                                <td class="text-center">
                                                    <strong
                                                        style="color:#c9a84c;"><?= number_format((float) $l['duration_hours'], 1) ?>h</strong>
                                                </td>
                                                <td class="text-center">
                                                    <span
                                                        style="background:<?= $vsBg[$vs] ?? '#f3f4f6' ?>;color:<?= $vsColor[$vs] ?? '#9ca3af' ?>;
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
                </div>

            </div>

        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>