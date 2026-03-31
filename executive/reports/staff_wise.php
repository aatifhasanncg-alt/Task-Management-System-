<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db        = getDB();
$pageTitle = 'Staff Performance Report';

$fromDate     = $_GET['from']      ?? date('Y-m-01');
$toDate       = $_GET['to']        ?? date('Y-m-d');
$filterDept   = (int)($_GET['dept_id']   ?? 0);
$filterBranch = (int)($_GET['branch_id'] ?? 0);

$where  = ['t.created_at BETWEEN ? AND ?', 't.is_active = 1'];
$params = [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'];

if ($filterDept)   { $where[] = 'u.department_id = ?'; $params[] = $filterDept; }
if ($filterBranch) { $where[] = 'u.branch_id = ?';     $params[] = $filterBranch; }

$ws = implode(' AND ', $where);

// Fetch all task statuses for dynamic status columns
$allStatuses = $db->query("
    SELECT status_name,
           COALESCE(color,    '#9ca3af') AS color,
           COALESCE(bg_color, '#f3f4f6') AS bg_color,
           COALESCE(icon,     'fa-circle-dot') AS icon
    FROM task_status ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

// Build dynamic SUM columns
$sumCols = '';
foreach ($allStatuses as $st) {
    $safe     = preg_replace('/[^a-z0-9_]/', '_', strtolower($st['status_name']));
    $escaped  = addslashes($st['status_name']);
    $sumCols .= "SUM(CASE WHEN ts.status_name = '{$escaped}' THEN 1 ELSE 0 END) AS `status_{$safe}`,\n";
}

$staffStmt = $db->prepare("
    SELECT u.id, u.full_name, u.employee_id,
           b.branch_name, d.dept_name, d.color AS dept_color,
           COUNT(t.id)                                            AS total,
           SUM(CASE WHEN ts.status_name = 'Done' THEN 1 ELSE 0 END) AS done,
           SUM(CASE WHEN t.due_date < CURDATE()
               AND ts.status_name != 'Done'      THEN 1 ELSE 0 END) AS overdue,
           {$sumCols}
           MAX(t.created_at) AS last_task_date
    FROM users u
    LEFT JOIN roles r        ON r.id  = u.role_id
    LEFT JOIN branches b     ON b.id  = u.branch_id
    LEFT JOIN departments d  ON d.id  = u.department_id
    LEFT JOIN tasks t        ON t.assigned_to = u.id AND {$ws}
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE r.role_name = 'staff' AND u.is_active = 1
    GROUP BY u.id, u.full_name, u.employee_id,
             b.branch_name, d.dept_name, d.color
    ORDER BY done DESC, total DESC
");
$staffStmt->execute($params);
$staffReport = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

// Summary stats
$totalStaff    = count($staffReport);
$totalTasks    = array_sum(array_column($staffReport, 'total'));
$totalDone     = array_sum(array_column($staffReport, 'done'));
$totalOverdue  = array_sum(array_column($staffReport, 'overdue'));
$overallPct    = $totalTasks > 0 ? round(($totalDone / $totalTasks) * 100) : 0;

// Top performer
$topPerformer  = null;
foreach ($staffReport as $s) {
    if ($s['total'] > 0) { $topPerformer = $s; break; }
}

$allDepts    = $db->query("SELECT id, dept_name FROM departments WHERE is_active=1 AND dept_name !='CORE ADMIN' ORDER BY dept_name")->fetchAll();
$allBranches = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();

include '../../includes/header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<div class="app-wrapper">
<?php include '../../includes/sidebar_executive.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<!-- ── Hero ──────────────────────────────────────────────────────────────── -->
<div class="page-hero">
    <div class="d-flex justify-content-between flex-wrap gap-3 align-items-center">
        <div>
            <div class="page-hero-badge"><i class="fas fa-users"></i> Reports</div>
            <h4>Staff Performance</h4>
            <p><?= date('d M Y', strtotime($fromDate)) ?> — <?= date('d M Y', strtotime($toDate)) ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="index.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Back
            </a>
            <a href="<?= APP_URL ?>/exports/export_excel.php?module=staff_wise&from=<?= $fromDate ?>&to=<?= $toDate ?>&dept_id=<?= $filterDept ?>&branch_id=<?= $filterBranch ?>"
               class="btn btn-success btn-sm">
                <i class="fas fa-file-excel me-1"></i>Excel
            </a>
            <a href="<?= APP_URL ?>/exports/export_pdf.php?module=staff_wise&from=<?= $fromDate ?>&to=<?= $toDate ?>&dept_id=<?= $filterDept ?>&branch_id=<?= $filterBranch ?>"
               class="btn btn-danger btn-sm">
                <i class="fas fa-file-pdf me-1"></i>PDF
            </a>
        </div>
    </div>
</div>

<!-- ── Summary Cards ─────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['icon' => 'fa-users',          'color' => '#3b82f6', 'bg' => '#eff6ff', 'label' => 'Active Staff',      'value' => $totalStaff],
        ['icon' => 'fa-list-check',     'color' => '#8b5cf6', 'bg' => '#f5f3ff', 'label' => 'Total Tasks',       'value' => $totalTasks],
        ['icon' => 'fa-circle-check',   'color' => '#10b981', 'bg' => '#ecfdf5', 'label' => 'Completed',         'value' => $totalDone],
        ['icon' => 'fa-triangle-exclamation', 'color' => '#ef4444', 'bg' => '#fef2f2', 'label' => 'Overdue', 'value' => $totalOverdue],
    ];
    foreach ($cards as $c):
    ?>
    <div class="col-6 col-md-3">
        <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;
                    padding:1.1rem 1.2rem;display:flex;align-items:center;gap:.9rem;">
            <div style="width:42px;height:42px;border-radius:10px;background:<?= $c['bg'] ?>;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas <?= $c['icon'] ?>" style="color:<?= $c['color'] ?>;font-size:1rem;"></i>
            </div>
            <div>
                <div style="font-size:1.45rem;font-weight:800;color:#1f2937;line-height:1.1;">
                    <?= number_format($c['value']) ?>
                </div>
                <div style="font-size:.73rem;color:#9ca3af;margin-top:.1rem;"><?= $c['label'] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Overall completion meter -->
<div class="card-mis mb-4" style="border-left:4px solid #10b981;">
    <div class="card-mis-body" style="padding:.9rem 1.2rem;">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div style="font-size:.82rem;font-weight:600;color:#374151;">
                <i class="fas fa-gauge-high me-2" style="color:#10b981;"></i>
                Overall Completion Rate
                <?php if ($topPerformer): ?>
                <span style="font-size:.72rem;color:#9ca3af;font-weight:400;margin-left:.75rem;">
                    Top: <strong style="color:#10b981;"><?= htmlspecialchars(explode(' ', $topPerformer['full_name'])[0]) ?></strong>
                    (<?= $topPerformer['total'] > 0 ? round(($topPerformer['done']/$topPerformer['total'])*100) : 0 ?>%)
                </span>
                <?php endif; ?>
            </div>
            <span style="font-size:1.1rem;font-weight:800;color:#10b981;"><?= $overallPct ?>%</span>
        </div>
        <div style="background:#f3f4f6;border-radius:99px;height:7px;margin-top:.6rem;">
            <div style="width:<?= $overallPct ?>%;background:linear-gradient(90deg,#10b981,#34d399);
                        height:100%;border-radius:99px;transition:width .6s ease;"></div>
        </div>
        <div style="font-size:.7rem;color:#9ca3af;margin-top:.3rem;">
            <?= $totalDone ?> of <?= $totalTasks ?> tasks completed across <?= $totalStaff ?> staff
        </div>
    </div>
</div>

<!-- ── Filters ───────────────────────────────────────────────────────────── -->
<div class="filter-bar mb-4 w-100">
    <form method="GET" class="row g-2 align-items-end w-100">
        <div class="col-md-2">
            <label class="form-label-mis">From</label>
            <input type="date" name="from" class="form-control form-control-sm" value="<?= $fromDate ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label-mis">To</label>
            <input type="date" name="to" class="form-control form-control-sm" value="<?= $toDate ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label-mis">Department</label>
            <select name="dept_id" class="form-select form-select-sm">
                <option value="">All Depts</option>
                <?php foreach ($allDepts as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $filterDept == $d['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['dept_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label-mis">Branch</label>
            <select name="branch_id" class="form-select form-select-sm">
                <option value="">All Branches</option>
                <?php foreach ($allBranches as $b): ?>
                <option value="<?= $b['id'] ?>" <?= $filterBranch == $b['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($b['branch_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-gold btn-sm w-100">
                <i class="fas fa-filter me-1"></i>Filter
            </button>
            <a href="staff_wise.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-times"></i>
            </a>
        </div>
    </form>
</div>

<!-- ── Chart ─────────────────────────────────────────────────────────────── -->
<?php if (!empty($staffReport)): ?>
<div class="row g-3 mb-4">

    <!-- Bar chart: task breakdown per staff -->
    <div class="col-lg-8">
        <div class="card-mis h-100">
            <div class="card-mis-header">
                <h5><i class="fas fa-chart-bar text-warning me-2"></i>Task Breakdown by Staff</h5>
            </div>
            <div class="card-mis-body">
                <div style="height:280px;position:relative;">
                    <canvas id="staffBarChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Donut: overall status distribution -->
    <div class="col-lg-4">
        <div class="card-mis h-100">
            <div class="card-mis-header">
                <h5><i class="fas fa-chart-pie text-warning me-2"></i>Status Distribution</h5>
            </div>
            <div class="card-mis-body d-flex flex-column align-items-center justify-content-center">
                <div style="height:200px;position:relative;width:100%;">
                    <canvas id="statusDonut"></canvas>
                </div>
                <!-- Legend -->
                <div style="margin-top:.75rem;width:100%;">
                    <?php foreach ($allStatuses as $st):
                        $safe  = preg_replace('/[^a-z0-9_]/', '_', strtolower($st['status_name']));
                        $count = array_sum(array_column($staffReport, 'status_' . $safe));
                        if (!$count) continue;
                    ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;
                                padding:.2rem 0;font-size:.75rem;">
                        <div style="display:flex;align-items:center;gap:.4rem;">
                            <div style="width:10px;height:10px;border-radius:50%;
                                        background:<?= htmlspecialchars($st['color']) ?>;flex-shrink:0;"></div>
                            <i class="fas <?= htmlspecialchars($st['icon']) ?>"
                               style="font-size:.65rem;color:<?= htmlspecialchars($st['color']) ?>;"></i>
                            <span style="color:#374151;"><?= htmlspecialchars($st['status_name']) ?></span>
                        </div>
                        <span style="font-weight:600;color:#1f2937;"><?= $count ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

</div>
<?php endif; ?>

<!-- ── Staff Table ───────────────────────────────────────────────────────── -->
<div class="card-mis mb-4">
    <div class="card-mis-header">
        <h5><i class="fas fa-users text-warning me-2"></i>Staff Performance Details</h5>
        <small class="text-muted"><?= $totalStaff ?> staff members</small>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th style="width:36px;">#</th>
                    <th>Staff</th>
                    <th>Dept / Branch</th>
                    <th class="text-center">Total</th>
                    <?php foreach ($allStatuses as $st): ?>
                    <th class="text-center" title="<?= htmlspecialchars($st['status_name']) ?>"
                        style="min-width:54px;">
                        <i class="fas <?= htmlspecialchars($st['icon']) ?>"
                           style="color:<?= htmlspecialchars($st['color']) ?>;font-size:.75rem;"></i>
                    </th>
                    <?php endforeach; ?>
                    <th style="min-width:160px;">Completion</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staffReport)): ?>
                <tr>
                    <td colspan="<?= 5 + count($allStatuses) ?>" class="empty-state">
                        <i class="fas fa-users"></i> No staff data found
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($staffReport as $i => $s):
                    $pct        = $s['total'] > 0 ? round(($s['done'] / $s['total']) * 100) : 0;
                    $barColor   = $pct >= 80 ? '#10b981' : ($pct >= 50 ? '#f59e0b' : '#ef4444');
                    $initials   = strtoupper(substr($s['full_name'], 0, 1) . (strpos($s['full_name'], ' ') ? substr($s['full_name'], strpos($s['full_name'], ' ') + 1, 1) : ''));
                ?>
                <tr>
                    <td style="color:#9ca3af;font-size:.75rem;"><?= $i + 1 ?></td>

                    <!-- Staff -->
                    <td>
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;">
                            <div style="display:flex;align-items:center;gap:.6rem;">
                                <div style="width:34px;height:34px;border-radius:50%;
                                            background:<?= htmlspecialchars($s['dept_color'] ?? '#c9a84c') ?>22;
                                            color:<?= htmlspecialchars($s['dept_color'] ?? '#c9a84c') ?>;
                                            display:flex;align-items:center;justify-content:center;
                                            font-size:.72rem;font-weight:700;flex-shrink:0;">
                                    <?= $initials ?>
                                </div>
                                <div>
                                    <div style="font-size:.87rem;font-weight:500;color:#1f2937;">
                                        <?= htmlspecialchars($s['full_name']) ?>
                                    </div>
                                    <?php if ($s['employee_id']): ?>
                                    <div style="font-size:.68rem;color:#9ca3af;">
                                        <?= htmlspecialchars($s['employee_id']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="<?= APP_URL ?>/executive/staff/view.php?id=<?= $s['id'] ?>"
                               class="btn btn-sm btn-outline-secondary"
                               style="padding:.2rem .45rem;flex-shrink:0;">
                                <i class="fas fa-eye" style="font-size:.7rem;"></i>
                            </a>
                        </div>
                    </td>

                    <!-- Dept / Branch -->
                    <td>
                        <span style="font-size:.73rem;
                                     background:<?= htmlspecialchars($s['dept_color'] ?? '#ccc') ?>22;
                                     color:<?= htmlspecialchars($s['dept_color'] ?? '#666') ?>;
                                     padding:.2rem .55rem;border-radius:99px;
                                     display:inline-block;margin-bottom:.2rem;">
                            <?= htmlspecialchars($s['dept_name'] ?? '—') ?>
                        </span>
                        <div style="font-size:.68rem;color:#9ca3af;">
                            <?= htmlspecialchars(strtok($s['branch_name'] ?? '—', ' ')) ?>
                        </div>
                    </td>

                    <!-- Total -->
                    <td class="text-center">
                        <span style="font-size:.9rem;font-weight:700;color:#1f2937;">
                            <?= $s['total'] ?>
                        </span>
                    </td>

                    <!-- Dynamic status columns -->
                    <?php foreach ($allStatuses as $st):
                        $safe  = preg_replace('/[^a-z0-9_]/', '_', strtolower($st['status_name']));
                        $count = (int)($s['status_' . $safe] ?? 0);
                    ?>
                    <td class="text-center">
                        <?php if ($count > 0): ?>
                        <span style="background:<?= htmlspecialchars($st['bg_color']) ?>;
                                     color:<?= htmlspecialchars($st['color']) ?>;
                                     padding:.15rem .45rem;border-radius:99px;
                                     font-size:.72rem;font-weight:600;display:inline-block;">
                            <?= $count ?>
                        </span>
                        <?php else: ?>
                        <span style="color:#e5e7eb;font-size:.75rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>

                    <!-- Completion bar -->
                    <td style="min-width:160px;">
                        <?php if ($s['total'] > 0): ?>
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            <div style="flex:1;background:#f3f4f6;border-radius:99px;height:6px;overflow:hidden;">
                                <div style="width:<?= $pct ?>%;background:<?= $barColor ?>;
                                            height:100%;border-radius:99px;transition:width .4s;"></div>
                            </div>
                            <span style="font-size:.72rem;font-weight:700;color:<?= $barColor ?>;
                                         white-space:nowrap;min-width:38px;text-align:right;">
                                <?= $pct ?>%
                            </span>
                        </div>
                        <div style="font-size:.65rem;color:#9ca3af;margin-top:.15rem;">
                            <?= $s['done'] ?> / <?= $s['total'] ?> done
                            <?php if ($s['overdue'] > 0): ?>
                            &nbsp;<span style="color:#ef4444;font-weight:600;">
                                ⚠ <?= $s['overdue'] ?> overdue
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <span style="font-size:.75rem;color:#9ca3af;">No tasks</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>

            </tbody>
        </table>
    </div>
</div>

</div><!-- padding -->
<?php include '../../includes/footer.php'; ?>
</div><!-- main-content -->
</div><!-- app-wrapper -->

<?php if (!empty($staffReport)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Data prep ─────────────────────────────────────────────────────────────
    const staff = <?= json_encode(array_map(function($s) use ($allStatuses) {
        $row = [
            'name'    => explode(' ', $s['full_name'])[0],
            'total'   => (int)$s['total'],
            'done'    => (int)$s['done'],
            'overdue' => (int)$s['overdue'],
            'statuses' => [],
        ];
        foreach ($allStatuses as $st) {
            $safe = preg_replace('/[^a-z0-9_]/', '_', strtolower($st['status_name']));
            $row['statuses'][$st['status_name']] = (int)($s['status_' . $safe] ?? 0);
        }
        return $row;
    }, $staffReport)) ?>;

    const statusMeta = <?= json_encode(array_values($allStatuses)) ?>;

    // Only top 15 by total for readability
    const top = [...staff].sort((a,b) => b.total - a.total).slice(0, 15);

    // ── Bar Chart ─────────────────────────────────────────────────────────────
    new Chart(document.getElementById('staffBarChart'), {
        type: 'bar',
        data: {
            labels: top.map(d => d.name),
            datasets: statusMeta.map(st => ({
                label:           st.status_name,
                backgroundColor: st.color,
                borderRadius:    3,
                data: top.map(d => d.statuses[st.status_name] ?? 0),
            }))
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        font: { size: 11 },
                        padding: 14,
                        filter: item => {
                            const total = top.reduce((sum, d) =>
                                sum + (d.statuses[item.text] ?? 0), 0);
                            return total > 0;
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        footer: (items) => {
                            const total = items.reduce((s, i) => s + i.raw, 0);
                            return 'Total: ' + total;
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false },
                    ticks: { font: { size: 11 } }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    grid: { color: '#f3f4f6' },
                    ticks: { stepSize: 1, font: { size: 11 } }
                }
            }
        }
    });

    // ── Donut Chart ───────────────────────────────────────────────────────────
    const donutTotals = statusMeta.map(st => ({
        label: st.status_name,
        color: st.color,
        value: staff.reduce((sum, d) => sum + (d.statuses[st.status_name] ?? 0), 0),
    })).filter(d => d.value > 0);

    new Chart(document.getElementById('statusDonut'), {
        type: 'doughnut',
        data: {
            labels: donutTotals.map(d => d.label),
            datasets: [{
                data:            donutTotals.map(d => d.value),
                backgroundColor: donutTotals.map(d => d.color),
                borderWidth:     2,
                borderColor:     '#fff',
                hoverOffset:     6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.label}: ${ctx.raw} tasks`
                    }
                }
            }
        },
        plugins: [{
            // Centre text
            id: 'centreText',
            afterDraw(chart) {
                const { ctx, chartArea: { top, bottom, left, right } } = chart;
                const cx = (left + right) / 2;
                const cy = (top + bottom) / 2;
                ctx.save();
                ctx.textAlign    = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle    = '#1f2937';
                ctx.font         = 'bold 22px sans-serif';
                ctx.fillText(<?= $totalTasks ?>, cx, cy - 8);
                ctx.fillStyle = '#9ca3af';
                ctx.font      = '11px sans-serif';
                ctx.fillText('total tasks', cx, cy + 12);
                ctx.restore();
            }
        }]
    });

});
</script>
<?php endif; ?>