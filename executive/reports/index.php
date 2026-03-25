<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$pageTitle = 'Executive Reports';

// Tasks by Department — dept_name not department_name
$deptData = $db->query("
    SELECT d.dept_name, COUNT(t.id) as total
    FROM departments d
    LEFT JOIN tasks t ON t.department_id = d.id AND t.is_active = 1
    WHERE d.is_active = 1
    GROUP BY d.id, d.dept_name
    ORDER BY total DESC
")->fetchAll();

// Tasks by Branch
$branchData = $db->query("
    SELECT b.branch_name, COUNT(t.id) as total
    FROM branches b
    LEFT JOIN tasks t ON t.branch_id = b.id AND t.is_active = 1
    WHERE b.is_active = 1
    GROUP BY b.id, b.branch_name
    ORDER BY total DESC
")->fetchAll();

// Tasks by Status — join task_status, no t.status column
$statusData = $db->query("
    SELECT ts.status_name AS status, COUNT(t.id) as cnt
    FROM task_status ts
    LEFT JOIN tasks t ON t.status_id = ts.id AND t.is_active = 1
    GROUP BY ts.id, ts.status_name
    ORDER BY cnt DESC
")->fetchAll();

// Staff performance — join roles, task_status
$staffData = $db->query("
    SELECT u.full_name,
           COUNT(t.id) as total,
           SUM(CASE WHEN ts.status_name = 'Done' THEN 1 ELSE 0 END) as done
    FROM users u
    LEFT JOIN roles r        ON r.id  = u.role_id
    LEFT JOIN tasks t        ON t.assigned_to = u.id AND t.is_active = 1
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE r.role_name = 'staff' AND u.is_active = 1
    GROUP BY u.id, u.full_name
    ORDER BY total DESC
    LIMIT 10
")->fetchAll();

// Monthly trend
$monthlyData = $db->query("
    SELECT DATE_FORMAT(created_at, '%b %Y') as month,
           DATE_FORMAT(created_at, '%Y-%m') as ym,
           COUNT(*) as cnt
    FROM tasks
    WHERE is_active = 1
    AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY ym, month
    ORDER BY ym ASC
")->fetchAll();

// Summary stats — all via task_status join or roles join
$stats = [
    'total'     => $db->query("SELECT COUNT(*) FROM tasks WHERE is_active=1")->fetchColumn(),

    'completed' => $db->query("
        SELECT COUNT(*) FROM tasks t
        JOIN task_status ts ON ts.id = t.status_id
        WHERE ts.status_name = 'Done' AND t.is_active = 1
    ")->fetchColumn(),

    'wip'       => $db->query("
        SELECT COUNT(*) FROM tasks t
        JOIN task_status ts ON ts.id = t.status_id
        WHERE ts.status_name = 'WIP' AND t.is_active = 1
    ")->fetchColumn(),

    'companies' => $db->query("SELECT COUNT(*) FROM companies WHERE is_active=1")->fetchColumn(),

    'staff'     => $db->query("
        SELECT COUNT(*) FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE r.role_name = 'staff' AND u.is_active = 1
    ")->fetchColumn(),

    'branches'  => $db->query("SELECT COUNT(*) FROM branches WHERE is_active=1")->fetchColumn(),
];

include '../../includes/header.php';
?>
<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>

<div class="app-wrapper">
<?php include '../../includes/sidebar_executive.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<div class="page-hero">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="page-hero-badge"><i class="fas fa-chart-pie"></i> Executive Reports</div>
            <h4>Analytics Dashboard</h4>
            <p>Visual overview of all tasks, departments, branches, and staff performance.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="department_wise.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-layer-group me-1"></i>By Dept
            </a>
            <a href="branch_wise.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-map-marker-alt me-1"></i>By Branch
            </a>
            <a href="staff_wise.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-users me-1"></i>By Staff
            </a>
            <a href="company_wise.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-building me-1"></i>By Company
            </a>
            <a href="date_wise.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-calendar me-1"></i>By Date
            </a>
        </div>
    </div>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['Total Tasks',   $stats['total'],     '#3b82f6', '#eff6ff',  'fa-list-check'],
        ['Completed',     $stats['completed'], '#10b981', '#ecfdf5',  'fa-check-circle'],
        ['In Progress',   $stats['wip'],       '#f59e0b', '#fffbeb',  'fa-spinner'],
        ['Companies',     $stats['companies'], '#c9a84c', '#fefce8',  'fa-building'],
        ['Staff Members', $stats['staff'],     '#ec4899', '#fdf2f8',  'fa-users'],
        ['Branches',      $stats['branches'],  '#8b5cf6', '#f5f3ff',  'fa-map-marker-alt'],
    ] as [$lbl, $val, $col, $bg, $ic]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:<?= $bg ?>;color:<?= $col ?>;">
                <i class="fas <?= $ic ?>"></i>
            </div>
            <div class="stat-card-value" style="color:<?= $col ?>;"><?= number_format($val) ?></div>
            <div class="stat-card-label"><?= $lbl ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts Row 1 -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card-mis">
            <div class="card-mis-header">
                <h5><i class="fas fa-layer-group text-warning me-2"></i>Tasks by Department</h5>
            </div>
            <div class="card-mis-body">
                <div id="chart_dept" style="height:280px;"></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-mis">
            <div class="card-mis-header">
                <h5><i class="fas fa-map-marker-alt text-warning me-2"></i>Tasks by Branch</h5>
            </div>
            <div class="card-mis-body">
                <div id="chart_branch" style="height:280px;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row 2 -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card-mis">
            <div class="card-mis-header">
                <h5><i class="fas fa-tag text-warning me-2"></i>Tasks by Status</h5>
            </div>
            <div class="card-mis-body">
                <div id="chart_status" style="height:260px;"></div>
                <div id="status_legend" class="d-flex flex-wrap gap-2 mt-2"></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-mis">
            <div class="card-mis-header">
                <h5><i class="fas fa-chart-line text-warning me-2"></i>Monthly Task Trend</h5>
            </div>
            <div class="card-mis-body">
                <div id="chart_trend" style="height:280px;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Staff Performance Chart -->
<div class="card-mis mb-4">
    <div class="card-mis-header">
        <h5><i class="fas fa-users text-warning me-2"></i>Staff Performance</h5>
    </div>
    <div class="card-mis-body">
        <div id="chart_staff" style="height:300px;"></div>
    </div>
</div>

<!-- Sub-report links -->
<div class="row g-3">
    <?php foreach ([
        ['department_wise.php', 'fa-layer-group',    '#f59e0b', 'Department-wise', 'Tasks breakdown per department'],
        ['branch_wise.php',     'fa-map-marker-alt', '#3b82f6', 'Branch-wise',     'Performance across all branches'],
        ['staff_wise.php',      'fa-users',          '#10b981', 'Staff Performance','Individual completion rates'],
        ['company_wise.php',    'fa-building',       '#8b5cf6', 'Company Workflow', 'Tasks per company + history'],
        ['date_wise.php',       'fa-calendar-alt',   '#ec4899', 'Date Trends',      'Task volume by date range'],
        ['summary.php',         'fa-landmark',       '#1f2937', 'Banking Report',  'Bank reference']
    ] as [$href, $ic, $col, $title, $desc]): ?>
    <div class="col-md-4">
        <a href="<?= $href ?>" class="d-block p-3 rounded-3 text-decoration-none"
           style="background:white;border:1px solid #e5e7eb;transition:.2s;"
           onmouseover="this.style.borderColor='<?= $col ?>'"
           onmouseout="this.style.borderColor='#e5e7eb'">
            <div class="d-flex align-items-center gap-3">
                <div style="width:40px;height:40px;border-radius:10px;background:<?= $col ?>22;color:<?= $col ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas <?= $ic ?>"></i>
                </div>
                <div>
                    <div style="font-size:.88rem;font-weight:600;color:#1f2937;"><?= $title ?></div>
                    <div style="font-size:.75rem;color:#9ca3af;"><?= $desc ?></div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

</div>
<?php include '../../includes/footer.php'; ?>

<script>
google.charts.load('current', {'packages': ['corechart', 'bar']});
google.charts.setOnLoadCallback(drawCharts);

const STATUS_COLORS = {
    'Not Started' : '#9ca3af',
    'Pending'     : '#ef4444',
    'WIP'         : '#f59e0b',
    'HBC'         : '#3b82f6',
    'Next Year'   : '#8b5cf6',
    'Done'        : '#10b981',
};

function drawCharts() {

    // Department Pie
    const deptData = google.visualization.arrayToDataTable([
        ['Department', 'Tasks'],
        <?php foreach ($deptData as $d): ?>
        ['<?= addslashes($d['dept_name']) ?>', <?= (int)$d['total'] ?>],
        <?php endforeach; ?>
    ]);
    new google.visualization.PieChart(document.getElementById('chart_dept'))
        .draw(deptData, {
            backgroundColor: 'transparent',
            legend: { position: 'right', textStyle: { fontSize: 12 } },
            colors: ['#f59e0b', '#3b82f6', '#8b5cf6', '#10b981', '#ec4899', '#06b6d4'],
            chartArea: { width: '85%', height: '85%' },
            pieHole: 0.4,
        });

    // Branch Bar
    const branchData = google.visualization.arrayToDataTable([
        ['Branch', 'Tasks', { role: 'style' }],
        <?php foreach ($branchData as $b): ?>
        ['<?= addslashes($b['branch_name']) ?>', <?= (int)$b['total'] ?>, 'color:#c9a84c'],
        <?php endforeach; ?>
    ]);
    new google.visualization.ColumnChart(document.getElementById('chart_branch'))
        .draw(branchData, {
            backgroundColor: 'transparent',
            legend: { position: 'none' },
            chartArea: { width: '80%', height: '75%' },
            vAxis: { minValue: 0 },
            bar: { groupWidth: '50%' },
        });

    // Status Donut
    const statusData = google.visualization.arrayToDataTable([
        ['Status', 'Count'],
        <?php foreach ($statusData as $s): ?>
        ['<?= addslashes($s['status']) ?>', <?= (int)$s['cnt'] ?>],
        <?php endforeach; ?>
    ]);
    const statusColors = <?= json_encode(array_column($statusData, 'status')) ?>
        .map(s => STATUS_COLORS[s] || '#9ca3af');

    new google.visualization.PieChart(document.getElementById('chart_status'))
        .draw(statusData, {
            backgroundColor: 'transparent',
            legend: { position: 'none' },
            colors: statusColors,
            chartArea: { width: '80%', height: '85%' },
            pieHole: 0.5,
        });

    // Status legend
    const leg = document.getElementById('status_legend');
    <?php foreach ($statusData as $s): ?>
    leg.innerHTML += `<div style="display:flex;align-items:center;gap:.3rem;font-size:.75rem;">
        <div style="width:8px;height:8px;border-radius:50%;background:${STATUS_COLORS['<?= addslashes($s['status']) ?>'] || '#9ca3af'};flex-shrink:0;"></div>
        <span style="color:#6b7280;"><?= htmlspecialchars($s['status']) ?> (<?= $s['cnt'] ?>)</span>
    </div>`;
    <?php endforeach; ?>

    // Monthly Trend Line
    const trendData = google.visualization.arrayToDataTable([
        ['Month', 'Tasks Created'],
        <?php foreach ($monthlyData as $m): ?>
        ['<?= addslashes($m['month']) ?>', <?= (int)$m['cnt'] ?>],
        <?php endforeach; ?>
    ]);
    new google.visualization.LineChart(document.getElementById('chart_trend'))
        .draw(trendData, {
            backgroundColor: 'transparent',
            colors: ['#c9a84c'],
            lineWidth: 3,
            pointSize: 6,
            chartArea: { width: '85%', height: '75%' },
            legend: { position: 'none' },
            vAxis: { minValue: 0 },
        });

    // Staff Bar
    const staffData = google.visualization.arrayToDataTable([
        ['Staff', 'Assigned', 'Done'],
        <?php foreach ($staffData as $s): ?>
        ['<?= addslashes(explode(' ', $s['full_name'])[0]) ?>', <?= (int)$s['total'] ?>, <?= (int)$s['done'] ?>],
        <?php endforeach; ?>
    ]);
    new google.visualization.ColumnChart(document.getElementById('chart_staff'))
        .draw(staffData, {
            backgroundColor: 'transparent',
            colors: ['#3b82f6', '#10b981'],
            chartArea: { width: '85%', height: '70%' },
            legend: { position: 'top' },
            bar: { groupWidth: '60%' },
            vAxis: { minValue: 0 },
        });
}
</script>