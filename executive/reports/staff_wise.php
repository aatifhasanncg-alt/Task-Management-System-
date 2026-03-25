<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$pageTitle = 'Staff Performance Report';

$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');
$filterDept = (int) ($_GET['dept_id'] ?? 0);
$filterBranch = (int) ($_GET['branch_id'] ?? 0);

$where = ['t.created_at BETWEEN ? AND ?', 't.is_active = 1'];
$params = [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'];

if ($filterDept) {
    $where[] = 'u.department_id = ?';
    $params[] = $filterDept;
}
if ($filterBranch) {
    $where[] = 'u.branch_id = ?';
    $params[] = $filterBranch;
}

$ws = implode(' AND ', $where);

$staffReport = $db->prepare("
    SELECT u.full_name, u.employee_id,
           b.branch_name, d.dept_name, d.color,
           COUNT(t.id) as total,
           SUM(CASE WHEN ts.status_name = 'WIP'      THEN 1 ELSE 0 END) as wip,
           SUM(CASE WHEN ts.status_name = 'Pending'  THEN 1 ELSE 0 END) as pending,
           SUM(CASE WHEN ts.status_name = 'HBC'      THEN 1 ELSE 0 END) as hbc,
           SUM(CASE WHEN ts.status_name = 'Done'     THEN 1 ELSE 0 END) as done,
           SUM(CASE WHEN t.due_date < CURDATE()
               AND ts.status_name != 'Done'          THEN 1 ELSE 0 END) as overdue
    FROM users u
    LEFT JOIN roles r        ON r.id  = u.role_id
    LEFT JOIN branches b     ON b.id  = u.branch_id
    LEFT JOIN departments d  ON d.id  = u.department_id
    LEFT JOIN tasks t        ON t.assigned_to = u.id AND {$ws}
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE r.role_name = 'staff' AND u.is_active = 1
    GROUP BY u.id, u.full_name, u.employee_id, b.branch_name, d.dept_name, d.color
    ORDER BY done DESC, total DESC
");
$staffReport->execute($params);
$staffReport = $staffReport->fetchAll();

$allDepts = $db->query("SELECT id, dept_name FROM departments WHERE is_active=1 ORDER BY dept_name")->fetchAll();
$allBranches = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();

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
                    <div class="page-hero-badge"><i class="fas fa-users"></i> Reports</div>
                    <h4>Staff Performance</h4>
                    <p><?= date('d M Y', strtotime($fromDate)) ?> — <?= date('d M Y', strtotime($toDate)) ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="index.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=staff_wise&from=<?= $fromDate ?>&to=<?= $toDate ?>&dept_id=<?= $filterDept ?>&branch_id=<?= $filterBranch ?>"
                            class="btn btn-success btn-sm">
                            <i class="fas fa-file-excel me-1"></i>Export Excel
                        </a>

                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=staff_wise&from=<?= $fromDate ?>&to=<?= $toDate ?>&dept_id=<?= $filterDept ?>&branch_id=<?= $filterBranch ?>"
                            class="btn btn-danger btn-sm">
                            <i class="fas fa-file-pdf me-1"></i>Export PDF
                        </a>
                    </div>
                </div>
            </div>

            <!-- Filters -->
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
                        <button type="submit" class="btn btn-gold btn-sm w-100"><i class="fas fa-filter"></i> Filter</button>
                        <a href="staff_wise.php" class="btn btn-outline-secondary btn-sm"><i
                                class="fas fa-times"></i></a>
                    </div>
                </form>
            </div>

            <!-- Staff Table -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-users text-warning me-2"></i>Staff Performance</h5>
                    <small class="text-muted"><?= count($staffReport) ?> staff members</small>
                </div>
                <div class="table-responsive">
                    <table class="table-mis w-100">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Staff</th>
                                <th>Department</th>
                                <th>Branch</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">WIP</th>
                                <th class="text-center">Pending</th>
                                <th class="text-center">HBC</th>
                                <th class="text-center">Done</th>
                                <th class="text-center">Overdue</th>
                                <th>Completion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staffReport)): ?>
                                <tr>
                                    <td colspan="11" class="empty-state">No staff data found</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($staffReport as $i => $s):
                                $pct = $s['total'] > 0 ? round(($s['done'] / $s['total']) * 100) : 0;
                                ?>
                                <tr>
                                    <td style="font-size:.78rem;color:#9ca3af;"><?= $i + 1 ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-circle avatar-sm flex-shrink-0">
                                                <?= strtoupper(substr($s['full_name'], 0, 2)) ?>
                                            </div>
                                            <div>
                                                <div style="font-size:.87rem;font-weight:500;">
                                                    <?= htmlspecialchars($s['full_name']) ?>
                                                </div>
                                                <div style="font-size:.72rem;color:#9ca3af;">
                                                    <?= htmlspecialchars($s['employee_id'] ?? '') ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span
                                            style="font-size:.75rem;background:<?= htmlspecialchars($s['color'] ?? '#ccc') ?>22;color:<?= htmlspecialchars($s['color'] ?? '#666') ?>;padding:.2rem .55rem;border-radius:99px;">
                                            <?= htmlspecialchars($s['dept_name'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($s['branch_name'] ?? '—') ?></td>
                                    <td class="text-center fw-bold"><?= $s['total'] ?></td>
                                    <td class="text-center"><span class="status-badge status-wip"><?= $s['wip'] ?></span>
                                    </td>
                                    <td class="text-center"><span
                                            class="status-badge status-pending"><?= $s['pending'] ?></span></td>
                                    <td class="text-center"><span class="status-badge status-hbc"><?= $s['hbc'] ?></span>
                                    </td>
                                    <td class="text-center"><span class="status-badge status-done"><?= $s['done'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($s['overdue'] > 0): ?>
                                            <span
                                                style="background:#fef2f2;color:#ef4444;padding:.2rem .55rem;border-radius:99px;font-size:.75rem;font-weight:600;">
                                                <?= $s['overdue'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;font-size:.78rem;">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="min-width:130px;">
                                        <div class="d-flex align-items-center gap-2">
                                            <div style="flex:1;background:#f3f4f6;border-radius:99px;height:6px;">
                                                <div
                                                    style="width:<?= $pct ?>%;background:#10b981;height:100%;border-radius:99px;">
                                                </div>
                                            </div>
                                            <span style="font-size:.72rem;color:#6b7280;flex-shrink:0;"><?= $pct ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Chart -->
            <?php if (!empty($staffReport)): ?>
                <div class="card-mis mb-4">
                    <div class="card-mis-header">
                        <h5><i class="fas fa-chart-bar text-warning me-2"></i>Staff Comparison Chart</h5>
                    </div>
                    <div class="card-mis-body">
                        <div style="height:320px;position:relative;">
                            <canvas id="staffChart"></canvas>
                        </div>
                    </div>
                </div>

                <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const data = <?= json_encode(array_map(fn($s) => [
                            'name' => explode(' ', $s['full_name'])[0],
                            'wip' => (int) $s['wip'],
                            'pending' => (int) $s['pending'],
                            'hbc' => (int) $s['hbc'],
                            'done' => (int) $s['done'],
                            'overdue' => (int) $s['overdue'],
                        ], $staffReport)) ?>;

                        new Chart(document.getElementById('staffChart'), {
                            type: 'bar',
                            data: {
                                labels: data.map(d => d.name),
                                datasets: [
                                    { label: 'WIP', data: data.map(d => d.wip), backgroundColor: '#f59e0b', borderRadius: 4 },
                                    { label: 'Pending', data: data.map(d => d.pending), backgroundColor: '#ef4444', borderRadius: 4 },
                                    { label: 'HBC', data: data.map(d => d.hbc), backgroundColor: '#8b5cf6', borderRadius: 4 },
                                    { label: 'Done', data: data.map(d => d.done), backgroundColor: '#10b981', borderRadius: 4 },
                                    { label: 'Overdue', data: data.map(d => d.overdue), backgroundColor: '#dc2626', borderRadius: 4 },
                                ]
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                interaction: { mode: 'index', intersect: false },
                                plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: { size: 11 } } } },
                                scales: {
                                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
                                    y: { stacked: true, beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { stepSize: 1 } }
                                }
                            }
                        });
                    });
                </script>
            <?php endif; ?>

        </div>
        <?php include '../../includes/footer.php'; ?>