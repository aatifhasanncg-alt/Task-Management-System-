<?php
// executive/reports/date_wise.php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$pageTitle = 'Date-wise Report';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$groupBy = $_GET['group'] ?? 'day';  // day | week | month

$fmt = match ($groupBy) {
    'month' => "DATE_FORMAT(created_at,'%b %Y')",
    'week' => "CONCAT('Week ', WEEK(created_at), ' ', YEAR(created_at))",
    default => "DATE(created_at)",
};

$trend = $db->prepare("
    SELECT {$fmt} as period, COUNT(*) as created,
           SUM(status_id=8) as completed,
           SUM(status_id=2)       as wip,
           SUM(status_id=3)   as pending
    FROM tasks WHERE is_active=1
      AND created_at BETWEEN ? AND ?
    GROUP BY period ORDER BY MIN(created_at) ASC
");
$trend->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
$trendData = $trend->fetchAll();

include '../../includes/header.php';
?>
<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <div class="page-hero">
                <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-calendar"></i> Date Report</div>
                        <h4>Date-wise Task Report</h4>
                        <p><?= date('d M Y', strtotime($from)) ?> — <?= date('d M Y', strtotime($to)) ?></p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="index.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>

                        <!-- Excel -->
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=date_wise&from=<?= $from ?>&to=<?= $to ?>&group=<?= $groupBy ?>"
                            class="btn btn-success btn-sm">
                            <i class="fas fa-file-excel me-1"></i>Export Excel
                        </a>

                        <!-- PDF -->
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=date_wise&from=<?= $from ?>&to=<?= $to ?>&group=<?= $groupBy ?>"
                            class="btn btn-danger btn-sm">
                            <i class="fas fa-file-pdf me-1"></i>Export PDF
                        </a>
                    </div>
                </div>
            </div>

            <div class="filter-bar mb-4 w-100">
                <form method="GET" class="row g-2 align-items-end w-100">
                    <div class="col-md-2"><label class="form-label-mis">From</label><input type="date" name="from"
                            class="form-control form-control-sm" value="<?= $from ?>"></div>
                    <div class="col-md-2"><label class="form-label-mis">To</label><input type="date" name="to"
                            class="form-control form-control-sm" value="<?= $to ?>"></div>
                    <div class="col-md-2"><label class="form-label-mis">Group By</label>
                        <select name="group" class="form-select form-select-sm">
                            <option value="day" <?= $groupBy === 'day' ? 'selected' : '' ?>>Day</option>
                            <option value="week" <?= $groupBy === 'week' ? 'selected' : '' ?>>Week</option>
                            <option value="month" <?= $groupBy === 'month' ? 'selected' : '' ?>>Month</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end"><button type="submit"
                            class="btn btn-gold btn-sm w-100"><i class="fas fa-filter me-1"></i>Filter</button></div>
                </form>
            </div>

            <!-- Trend chart -->
            <div class="chart-container mb-4">
                <div class="chart-title"><i class="fas fa-chart-line"></i>Task Volume Over Time</div>
                <div id="chart_trend" style="height:300px;"></div>
            </div>

            <!-- Table -->
            <div class="card-mis">
                <div class="card-mis-header">
                    <h5><i class="fas fa-table text-warning me-2"></i>Breakdown</h5>
                </div>
                <div class="table-responsive">
                    <table class="table-mis w-100">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th class="text-center">Created</th>
                                <th class="text-center">Completed</th>
                                <th class="text-center">WIP</th>
                                <th class="text-center">Pending</th>
                                <th>Completion %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trendData as $row):
                                $pct = $row['created'] ? round(($row['completed'] / $row['created']) * 100) : 0;
                                ?>
                                <tr>
                                    <td style="font-weight:500;"><?= htmlspecialchars($row['period']) ?></td>
                                    <td class="text-center fw-bold"><?= $row['created'] ?></td>
                                    <td class="text-center"><span
                                            class="status-badge status-file-returned"><?= $row['completed'] ?></span></td>
                                    <td class="text-center"><span class="status-badge status-wip"><?= $row['wip'] ?></span>
                                    </td>
                                    <td class="text-center"><span
                                            class="status-badge status-pending"><?= $row['pending'] ?></span></td>
                                    <td>
                                        <div class="perf-bar">
                                            <div class="perf-bar-track">
                                                <div class="perf-bar-fill"
                                                    style="width:<?= $pct ?>%;background:<?= $pct >= 75 ? '#10b981' : ($pct >= 40 ? '#f59e0b' : '#ef4444'); ?>;">
                                                </div>
                                            </div>
                                            <span style="font-size:.75rem;font-weight:600;width:35px;"><?= $pct ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($trendData)): ?>
                                <tr>
                                    <td colspan="6" class="empty-state"><i class="fas fa-calendar"></i>No data for this
                                        range</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php include '../../includes/footer.php'; ?>

        <script>
            google.charts.load('current', { packages: ['corechart'] });
            google.charts.setOnLoadCallback(function () {
                const data = google.visualization.arrayToDataTable([
                    ['Period', 'Created', 'Completed', 'WIP'],
                    <?php foreach ($trendData as $r): ?>
                        ['<?= addslashes($r['period']) ?>', <?= (int) $r['created'] ?>, <?= (int) $r['completed'] ?>, <?= (int) $r['wip'] ?>],
                    <?php endforeach; ?>
                ]);
                new google.visualization.LineChart(document.getElementById('chart_trend')).draw(data, {
                    backgroundColor: 'transparent',
                    colors: ['#3b82f6', '#10b981', '#f59e0b'],
                    lineWidth: 3, pointSize: 5,
                    chartArea: { width: '85%', height: '75%' },
                    legend: { position: 'top' },
                    vAxis: { minValue: 0 },
                });
            });
        </script>