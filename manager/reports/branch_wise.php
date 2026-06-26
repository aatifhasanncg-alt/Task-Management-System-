<?php
// manager/reports/branch_wise.php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireManager();

$db   = getDB();
$user = currentUser();
$pageTitle = 'Branch-wise Tasks';

$adminDeptId = (int) ($user['department_id'] ?? 0);

// ── Dept scope: own dept + UDA depts (no role/CORE branching) ─────────────────
$udaStmt = $db->prepare("SELECT department_id FROM user_department_assignments WHERE user_id = ?");
$udaStmt->execute([$user['id']]);
$udaDeptIds = array_column($udaStmt->fetchAll(PDO::FETCH_ASSOC), 'department_id');

$scopedDeptIds = array_unique(array_filter(array_merge([$adminDeptId], $udaDeptIds)));

$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate   = $_GET['to']   ?? date('Y-m-d');

$branchData = [];
$totalTasks = 0;

if (!empty($scopedDeptIds)) {
    $deptPh = implode(',', array_fill(0, count($scopedDeptIds), '?'));

    $stmt = $db->prepare("
        SELECT b.id, b.branch_name, COUNT(t.id) AS task_count
        FROM branches b
        LEFT JOIN tasks t
            ON  t.branch_id = b.id
            AND t.is_active = 1
            AND t.department_id IN ({$deptPh})
            AND t.created_at BETWEEN ? AND ?
        WHERE b.is_active = 1
        GROUP BY b.id, b.branch_name
        ORDER BY task_count DESC, b.branch_name ASC
    ");
    $params = array_merge($scopedDeptIds, [$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
    $stmt->execute($params);
    $branchData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalTasks = array_sum(array_column($branchData, 'task_count'));
}

// Department names for the "scope" hint shown to the user
$deptNamesStmt = $db->prepare("
    SELECT dept_name FROM departments
    WHERE id IN (" . (!empty($scopedDeptIds) ? implode(',', array_fill(0, count($scopedDeptIds), '?')) : '0') . ")
    ORDER BY dept_name
");
$deptNamesStmt->execute($scopedDeptIds);
$scopedDeptNames = $deptNamesStmt->fetchAll(PDO::FETCH_COLUMN);

include '../../includes/header.php';
?>
<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_manager.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <div class="page-hero">
                <div class="d-flex justify-content-between flex-wrap gap-3 align-items-center">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-chart-pie"></i> Reports</div>
                        <h4>Branch-wise Tasks</h4>
                        <p>
                            <?= date('d M Y', strtotime($fromDate)) ?> — <?= date('d M Y', strtotime($toDate)) ?>
                            <?php if (!empty($scopedDeptNames)): ?>
                                <span style="font-size:.73rem;color:#c9a84c;margin-left:.5rem;">
                                    · <?= htmlspecialchars(implode(', ', $scopedDeptNames)) ?>
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-bar mb-4 w-100">
                <form method="GET" class="row g-2 align-items-end w-100">
                    <div class="col-md-2">
                        <label class="form-label-mis">From</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">To</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-gold btn-sm w-100">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                        <a href="branch_wise.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>

            <?php if (empty($scopedDeptIds)): ?>
                <div class="card-mis">
                    <div class="card-mis-body" style="padding:2rem;text-align:center;color:#9ca3af;">
                        <i class="fas fa-building fa-2x mb-2 d-block"></i>
                        No department scope found for your account.
                    </div>
                </div>
            <?php elseif ($totalTasks === 0): ?>
                <div class="card-mis">
                    <div class="card-mis-body" style="padding:2rem;text-align:center;color:#9ca3af;">
                        <i class="fas fa-chart-pie fa-2x mb-2 d-block"></i>
                        No tasks found for this date range.
                    </div>
                </div>
            <?php else: ?>

                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="card-mis h-100">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-chart-pie text-warning me-2"></i>Tasks by Branch</h5>
                            </div>
                            <div class="card-mis-body">
                                <div id="branchDonut" style="width:100%;height:380px;"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card-mis h-100">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-list text-warning me-2"></i>Breakdown</h5>
                                <small class="text-muted"><?= number_format($totalTasks) ?> total tasks</small>
                            </div>
                            <div class="card-mis-body" style="padding:0;">
                                <table class="table-mis w-100">
                                    <thead>
                                        <tr>
                                            <th>Branch</th>
                                            <th class="text-center">Tasks</th>
                                            <th class="text-center">Share</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($branchData as $b):
                                            if ((int) $b['task_count'] === 0) continue;
                                            $share = $totalTasks > 0 ? round(($b['task_count'] / $totalTasks) * 100, 1) : 0;
                                        ?>
                                        <tr>
                                            <td style="font-size:.85rem;"><?= htmlspecialchars($b['branch_name']) ?></td>
                                            <td class="text-center" style="font-weight:700;"><?= (int) $b['task_count'] ?></td>
                                            <td class="text-center" style="color:#6b7280;font-size:.82rem;"><?= $share ?>%</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </div>
        <?php include '../../includes/footer.php'; ?>
    </div>
</div>

<?php if (!empty($scopedDeptIds) && $totalTasks > 0): ?>
<script>
    google.charts.load('current', { packages: ['corechart'] });
    google.charts.setOnLoadCallback(drawBranchDonut);

    function drawBranchDonut() {
        const rows = <?= json_encode(array_values(array_map(
            fn($b) => [$b['branch_name'], (int) $b['task_count']],
            array_filter($branchData, fn($b) => (int) $b['task_count'] > 0)
        ))) ?>;

        const data = google.visualization.arrayToDataTable(
            [['Branch', 'Tasks']].concat(rows)
        );

        const options = {
            pieHole: 0.45,
            chartArea: { width: '90%', height: '85%' },
            legend: { position: 'right', textStyle: { fontSize: 12 } },
            colors: ['#c9a84c', '#3b82f6', '#10b981', '#8b5cf6', '#ef4444', '#f59e0b', '#06b6d4', '#ec4899', '#84cc16', '#6366f1'],
            tooltip: { text: 'both' },
            pieSliceText: 'percentage',
            fontName: 'inherit',
        };

        const chart = new google.visualization.PieChart(document.getElementById('branchDonut'));
        chart.draw(data, options);

        window.addEventListener('resize', function () {
            chart.draw(data, options);
        });
    }
</script>
<?php endif; ?>