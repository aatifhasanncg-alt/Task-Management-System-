<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$pageTitle = 'Department-wise Report';

// Filters
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');

$deptReport = $db->prepare("
    SELECT d.dept_name, d.color, d.icon,
           COUNT(t.id) as total,
           SUM(CASE WHEN ts.status_name = 'WIP'      THEN 1 ELSE 0 END) as wip,
           SUM(CASE WHEN ts.status_name = 'Pending'  THEN 1 ELSE 0 END) as pending,
           SUM(CASE WHEN ts.status_name = 'HBC'      THEN 1 ELSE 0 END) as hbc,
           SUM(CASE WHEN ts.status_name = 'Done'     THEN 1 ELSE 0 END) as done,
           SUM(CASE WHEN ts.status_name = 'Next Year'THEN 1 ELSE 0 END) as next_year,
           SUM(CASE WHEN t.due_date < CURDATE()
               AND ts.status_name != 'Done'          THEN 1 ELSE 0 END) as overdue
    FROM departments d
    LEFT JOIN tasks t        ON t.department_id = d.id
        AND t.is_active = 1
        AND t.created_at BETWEEN ? AND ?
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE d.is_active = 1
      AND d.dept_name != 'Core Admin'  
    GROUP BY d.id, d.dept_name, d.color, d.icon
    ORDER BY total DESC
");
$deptReport->execute([$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
$deptReport = $deptReport->fetchAll();

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <div class="page-hero">
                <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-layer-group"></i> Reports</div>
                        <h4>Department-wise Performance</h4>
                        <p><?= date('d M Y', strtotime($fromDate)) ?> — <?= date('d M Y', strtotime($toDate)) ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="index.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>

                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=department_wise&from=<?= $fromDate ?>&to=<?= $toDate ?>"
                            class="btn btn-success btn-sm">
                            <i class="fas fa-file-excel me-1"></i>Export Excel
                        </a>

                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=department_wise&from=<?= $fromDate ?>&to=<?= $toDate ?>"
                            class="btn btn-danger btn-sm">
                            <i class="fas fa-file-pdf me-1"></i>Export PDF
                        </a>
                    </div>
            </div>
        </div>
            <?= flashHtml() ?>

        <!-- Filter -->
        <div class="filter-bar mb-4 w-100">
            <form method="GET" class="row g-3 align-items-end w-100">
                <div class="col-md-2">
                    <label class="form-label-mis">From</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= $fromDate ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label-mis">To</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= $toDate ?>">
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-gold btn-sm w-100"><i class="fas fa-filter"></i> Filter</button>
                    <a href="department_wise.php" class="btn btn-outline-secondary btn-sm"><i
                            class="fas fa-times"></i></a>
                </div>
            </form>
        </div>

        <!-- Department Cards -->
        <div class="row g-3 mb-4">
            <?php foreach ($deptReport as $d): ?>
                <div class="col-md-4">
                    <div class="card-mis" style="border-top:3px solid <?= htmlspecialchars($d['color']) ?>;">
                        <div class="card-mis-body">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div
                                    style="width:40px;height:40px;border-radius:10px;background:<?= htmlspecialchars($d['color']) ?>22;color:<?= htmlspecialchars($d['color']) ?>;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas <?= htmlspecialchars($d['icon'] ?? 'fa-briefcase') ?>"></i>
                                </div>
                                <div>
                                    <div style="font-weight:600;font-size:.92rem;">
                                        <?= htmlspecialchars($d['dept_name']) ?>
                                    </div>
                                    <div style="font-size:.75rem;color:#9ca3af;"><?= $d['total'] ?> total tasks</div>
                                </div>
                            </div>
                            <div class="row g-2 text-center">
                                <?php foreach ([
                                    ['WIP', $d['wip'], '#f59e0b'],
                                    ['Pending', $d['pending'], '#ef4444'],
                                    ['HBC', $d['hbc'], '#8b5cf6'],
                                    ['Done', $d['done'], '#10b981'],
                                    ['Next Year', $d['next_year'], '#6b7280'],
                                    ['Overdue', $d['overdue'], '#dc2626'],
                                ] as [$label, $val, $color]): ?>
                                    <div class="col-4">
                                        <div style="font-size:1.1rem;font-weight:700;color:<?= $color ?>;"><?= $val ?></div>
                                        <div style="font-size:.65rem;color:#9ca3af;"><?= $label ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($d['total'] > 0):
                                $pct = round(($d['done'] / $d['total']) * 100);
                                ?>
                                <div style="margin-top:.75rem;">
                                    <div class="d-flex justify-content-between mb-1" style="font-size:.72rem;color:#9ca3af;">
                                        <span>Completion</span><span><?= $pct ?>%</span>
                                    </div>
                                    <div style="background:#f3f4f6;border-radius:99px;height:5px;">
                                        <div
                                            style="width:<?= $pct ?>%;background:<?= htmlspecialchars($d['color']) ?>;height:100%;border-radius:99px;">
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Chart -->
        <div class="card-mis mb-4">
            <div class="card-mis-header">
                <h5><i class="fas fa-chart-bar text-warning me-2"></i>Department Comparison</h5>
            </div>
            <div class="card-mis-body">
                <div style="height:300px;position:relative;">
                    <canvas id="deptChart"></canvas>
                </div>
            </div>
        </div>

    </div>
    <?php include '../../includes/footer.php'; ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const data = <?= json_encode($deptReport) ?>;
            new Chart(document.getElementById('deptChart'), {
                type: 'bar',
                data: {
                    labels: data.map(d => d.dept_name),
                    datasets: [
                        { label: 'WIP', data: data.map(d => d.wip), backgroundColor: '#f59e0b', borderRadius: 4 },
                        { label: 'Pending', data: data.map(d => d.pending), backgroundColor: '#ef4444', borderRadius: 4 },
                        { label: 'HBC', data: data.map(d => d.hbc), backgroundColor: '#8b5cf6', borderRadius: 4 },
                        { label: 'Done', data: data.map(d => d.done), backgroundColor: '#10b981', borderRadius: 4 },
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: { size: 11 } } } },
                    scales: {
                        x: { stacked: true, grid: { display: false } },
                        y: { stacked: true, beginAtZero: true, grid: { color: '#f3f4f6' } }
                    }
                }
            });
        });
    </script>