<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAdmin();

$db = getDB();
$user = currentUser();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}

// Fetch staff profile
$staffStmt = $db->prepare("
    SELECT u.*,
           r.role_name,
           d.dept_name, d.color AS dept_color,
           b.branch_name,
           m.full_name AS managed_by_name
    FROM users u
    LEFT JOIN roles r       ON r.id = u.role_id
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN branches b    ON b.id = u.branch_id
    LEFT JOIN users m       ON m.id = u.managed_by
    WHERE u.id = ? AND u.is_active = 1
");
$staffStmt->execute([$id]);
$staff = $staffStmt->fetch();
if (!$staff) {
    setFlash('error', 'Staff not found.');
    header('Location: index.php');
    exit;
}

// Security: admin can only view staff from their branch & dept
if (!isExecutive()) {
    $adminStmt = $db->prepare("SELECT branch_id, department_id FROM users WHERE id = ?");
    $adminStmt->execute([$user['id']]);
    $adminUser = $adminStmt->fetch();
    if (
        $staff['branch_id'] != $adminUser['branch_id'] ||
        $staff['department_id'] != $adminUser['department_id']
    ) {
        setFlash('error', 'Access denied.');
        header('Location: index.php');
        exit;
    }
}

// All tasks assigned to this staff
$taskStmt = $db->prepare("
    SELECT t.*,
           ts.status_name AS status,
           d.dept_name, d.color,
           c.company_name
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN departments d  ON d.id  = t.department_id
    LEFT JOIN companies c    ON c.id  = t.company_id
    WHERE t.assigned_to = ? AND t.is_active = 1
    ORDER BY t.created_at DESC
");
$taskStmt->execute([$id]);
$tasks = $taskStmt->fetchAll();

// Task stats
$totalTasks = count($tasks);
$statusCounts = [];
foreach ($tasks as $t) {
    $statusCounts[$t['status']] = ($statusCounts[$t['status']] ?? 0) + 1;
}
$openTasks = $totalTasks - ($statusCounts['Done'] ?? 0) - ($statusCounts['Next Year'] ?? 0);
$doneTasks = $statusCounts['Done'] ?? 0;
$overdueTasks = 0;
foreach ($tasks as $t) {
    if ($t['due_date'] && strtotime($t['due_date']) < time() && $t['status'] !== 'Done') {
        $overdueTasks++;
    }
}

// Monthly task completion (last 6 months)
$monthlyStmt = $db->prepare("
    SELECT 
        DATE_FORMAT(t.created_at, '%b %Y') AS month_label,
        DATE_FORMAT(t.created_at, '%Y-%m') AS month_key,
        COUNT(*) AS total,
        SUM(CASE WHEN ts.status_name = 'Done' THEN 1 ELSE 0 END) AS done
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE t.assigned_to = ? 
    AND t.is_active = 1
    AND t.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
");
$monthlyStmt->execute([$id]);
$monthly = $monthlyStmt->fetchAll();

// Retail tasks finalised by this staff
$finalisedStmt = $db->prepare("
    SELECT tr.*, t.task_number, t.title, c.company_name,
           ws.status_name AS work_status_name,
           fs.status_name AS finalisation_status_name
    FROM task_retail tr
    JOIN tasks t             ON t.id   = tr.task_id
    LEFT JOIN companies c    ON c.id   = t.company_id
    LEFT JOIN task_status ws ON ws.id  = tr.work_status_id
    LEFT JOIN task_status fs ON fs.id  = tr.finalisation_status_id
    WHERE tr.finalised_by = ? AND t.is_active = 1
    ORDER BY t.created_at DESC
    LIMIT 10
");
$finalisedStmt->execute([$id]);
$finalisedTasks = $finalisedStmt->fetchAll();

$pageTitle = 'Staff: ' . $staff['full_name'];
$statuses = $db->query("
    SELECT id, status_name, color
    FROM task_status
    ORDER BY id ASC
")->fetchAll();
include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div style="padding:1.5rem 0;">
            <?= flashHtml() ?>

            <!-- Back -->
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
                <a href="<?= APP_URL ?>/admin/tasks/assign.php?assigned_to=<?= $id ?>" class="btn btn-gold btn-sm">
                    <i class="fas fa-plus me-1"></i>Assign Task
                </a>
            </div>

            <div class="row g-4">

                <!-- ── LEFT COLUMN ── -->
                <div class="col-lg-8">

                    <!-- Profile Card -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <div class="d-flex align-items-center gap-3">
                                <div class="avatar-circle"
                                    style="width:56px;height:56px;font-size:1.1rem;flex-shrink:0;">
                                    <?php
                                    $parts = explode(' ', $staff['full_name']);
                                    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                                    echo $initials;
                                    ?>
                                </div>
                                <div>
                                    <h5 style="margin:0;font-size:1rem;"><?= htmlspecialchars($staff['full_name']) ?>
                                    </h5>
                                    <div style="font-size:.75rem;color:#9ca3af;">
                                        <?= htmlspecialchars($staff['employee_id'] ?? '') ?>
                                        · <?= htmlspecialchars($staff['role_name'] ?? '') ?>
                                    </div>
                                </div>
                            </div>
                            <span
                                style="background:<?= htmlspecialchars($staff['dept_color'] ?? '#ccc') ?>22;color:<?= htmlspecialchars($staff['dept_color'] ?? '#666') ?>;font-size:.75rem;padding:.3rem .7rem;border-radius:99px;">
                                <?= htmlspecialchars($staff['dept_name'] ?? '') ?>
                            </span>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3">
                                <?php
                                $profileFields = [
                                    'Full Name' => $staff['full_name'],
                                    'Employee ID' => $staff['employee_id'] ?? '—',
                                    'Email' => $staff['email'],
                                    'Phone' => $staff['phone'] ?? '—',
                                    'Department' => $staff['dept_name'] ?? '—',
                                    'Branch' => $staff['branch_name'] ?? '—',
                                    'Managed By' => $staff['managed_by_name'] ?? '—',
                                    'Joining Date' => $staff['joining_date'] ? date('d M Y', strtotime($staff['joining_date'])) : '—',
                                    'Address' => $staff['address'] ?? '—',
                                    'Emergency Contact' => $staff['emergency_contact'] ?? '—',
                                    'Status' => $staff['is_active'] ? 'Active' : 'Inactive',
                                    'Last Login' => $staff['last_login'] ? date('d M Y, H:i', strtotime($staff['last_login'])) : 'Never',
                                ];
                                foreach ($profileFields as $label => $val):
                                    ?>
                                    <div class="col-md-4">
                                        <div
                                            style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                            <?= $label ?></div>
                                        <div style="font-size:.87rem;margin-top:.2rem;color:#1f2937;">
                                            <?= htmlspecialchars($val) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Performance Chart -->
                    <?php if (!empty($monthly)): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-chart-bar text-warning me-2"></i>Monthly Performance (Last 6 Months)
                                </h5>
                            </div>
                            <div class="card-mis-body">
                                <div style="height:220px;position:relative;">
                                    <canvas id="monthlyChart"></canvas>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Tasks Table -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-list-check text-warning me-2"></i>Assigned Tasks (<?= $totalTasks ?>)
                            </h5>
                        </div>
                        <div class="table-responsive">
                            <table class="table-mis w-100">
                                <thead>
                                    <tr>
                                        <th>Task #</th>
                                        <th>Title</th>
                                        <th>Company</th>
                                        <th>Status</th>
                                        <th>Priority</th>
                                        <th>Due Date</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($tasks)): ?>
                                        <tr>
                                            <td colspan="7" class="empty-state"><i class="fas fa-list-check"></i>No tasks
                                                assigned</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($tasks as $t):
                                        $sClass = 'status-' . strtolower(str_replace(' ', '-', $t['status']));
                                        $overdue = $t['due_date'] && strtotime($t['due_date']) < time() && $t['status'] !== 'Done';
                                        ?>
                                        <tr <?= $overdue ? 'style="background:#fef2f2;"' : '' ?>>
                                            <td>
                                                <span class="task-number"><?= htmlspecialchars($t['task_number']) ?></span>
                                                <?php if ($overdue): ?>
                                                    <div style="font-size:.65rem;color:#ef4444;font-weight:600;">OVERDUE</div>
                                                <?php endif; ?>
                                            </td>
                                            <td
                                                style="font-size:.87rem;font-weight:500;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                <?= htmlspecialchars($t['title']) ?>
                                            </td>
                                            <td style="font-size:.8rem;color:#6b7280;">
                                                <?= htmlspecialchars($t['company_name'] ?? '—') ?>
                                            </td>
                                            <td><span
                                                    class="status-badge <?= $sClass ?>"><?= htmlspecialchars($t['status']) ?></span>
                                            </td>
                                            <td><span
                                                    class="status-badge priority-<?= $t['priority'] ?>"><?= ucfirst($t['priority']) ?></span>
                                            </td>
                                            <td
                                                style="font-size:.78rem;<?= $overdue ? 'color:#ef4444;font-weight:600;' : 'color:#9ca3af;' ?>">
                                                <?= $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—' ?>
                                            </td>
                                            <td>
                                                <a href="<?= APP_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>"
                                                    class="btn btn-sm btn-outline-secondary">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Finalised Retail Tasks -->
                    <?php if (!empty($finalisedTasks)): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-check-double text-warning me-2"></i>Retail Tasks Finalised by This
                                    Staff</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table-mis w-100">
                                    <thead>
                                        <tr>
                                            <th>Task #</th>
                                            <th>Title</th>
                                            <th>Company</th>
                                            <th>Work Status</th>
                                            <th>Finalisation</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($finalisedTasks as $f): ?>
                                            <tr>
                                                <td><span class="task-number"><?= htmlspecialchars($f['task_number']) ?></span>
                                                </td>
                                                <td
                                                    style="font-size:.87rem;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                    <?= htmlspecialchars($f['title']) ?>
                                                </td>
                                                <td style="font-size:.8rem;color:#6b7280;">
                                                    <?= htmlspecialchars($f['company_name'] ?? '—') ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-wip">
                                                        <?= htmlspecialchars($f['work_status_name'] ?? '—') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-file-returned">
                                                        <?= htmlspecialchars($f['finalisation_status_name'] ?? '—') ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                </div><!-- end col-lg-8 -->

                <!-- ── RIGHT COLUMN ── -->
                <div class="col-lg-4">

                    <!-- Performance Summary -->
                    <div class="card-mis mb-3">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-chart-pie text-warning me-2"></i>Performance</h5>
                        </div>
                        <div class="card-mis-body">

                            <!-- KPI cards -->
                            <div class="row g-2 mb-3">
                                <?php foreach ([
                                    ['Total', $totalTasks, '#3b82f6', '#eff6ff', 'fa-list-check'],
                                    ['Open', $openTasks, '#f59e0b', '#fffbeb', 'fa-spinner'],
                                    ['Done', $doneTasks, '#10b981', '#ecfdf5', 'fa-check-circle'],
                                    ['Overdue', $overdueTasks, '#ef4444', '#fef2f2', 'fa-exclamation-circle'],
                                ] as [$label, $val, $color, $bg, $icon]): ?>
                                    <div class="col-6">
                                        <div
                                            style="background:<?= $bg ?>;border-radius:10px;padding:.75rem;text-align:center;">
                                            <i class="fas <?= $icon ?>" style="color:<?= $color ?>;font-size:1.1rem;"></i>
                                            <div
                                                style="font-size:1.4rem;font-weight:700;color:<?= $color ?>;line-height:1.2;margin-top:.2rem;">
                                                <?= $val ?></div>
                                            <div style="font-size:.7rem;color:#6b7280;"><?= $label ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Status breakdown bars -->
                            <?php if ($totalTasks > 0): ?>
                                <div style="border-top:1px solid #f3f4f6;padding-top:1rem;">

                                    <?php foreach ($statuses as $s):
                                        $k = $s['status_name'];
                                        $cnt = $statusCounts[$k] ?? 0;

                                        if (!$cnt)
                                            continue;

                                        $pct = $totalTasks > 0 ? round(($cnt / $totalTasks) * 100) : 0;
                                        $color = $s['color'] ?? '#9ca3af';
                                        ?>

                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between mb-1" style="font-size:.75rem;">
                                                <span style="color:#1f2937;">
                                                    <?= htmlspecialchars($s['status_name']) ?>
                                                </span>
                                                <span style="color:#9ca3af;">
                                                    <?= $cnt ?> (<?= $pct ?>%)
                                                </span>
                                            </div>

                                            <div style="background:#f3f4f6;border-radius:99px;height:5px;">
                                                                                <div style="
                                                width:<?= $pct ?>%;
                                                background:<?= $color ?>;
                                                height:100%;
                                                border-radius:99px;
                                            "></div>
                                            </div>
                                        </div>

                                    <?php endforeach; ?>

                                </div>
                            <?php endif; ?>

                            <!-- Completion rate -->
                            <?php if ($totalTasks > 0):
                                $completionRate = round(($doneTasks / $totalTasks) * 100);
                                ?>
                                <div
                                    style="border-top:1px solid #f3f4f6;padding-top:1rem;margin-top:.5rem;text-align:center;">
                                    <div style="font-size:.75rem;color:#9ca3af;margin-bottom:.5rem;">Completion Rate</div>
                                    <div style="position:relative;display:inline-block;">
                                        <svg width="80" height="80" viewBox="0 0 80 80">
                                            <circle cx="40" cy="40" r="32" fill="none" stroke="#f3f4f6" stroke-width="8" />
                                            <circle cx="40" cy="40" r="32" fill="none" stroke="#10b981" stroke-width="8"
                                                stroke-dasharray="<?= round(2 * 3.14159 * 32 * $completionRate / 100) ?> 201"
                                                stroke-linecap="round" transform="rotate(-90 40 40)" />
                                        </svg>
                                        <div
                                            style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:.85rem;font-weight:700;color:#10b981;">
                                            <?= $completionRate ?>%
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Staff Quick Info -->
                    <div class="card-mis p-3" style="font-size:.82rem;color:#6b7280;">
                        <div class="mb-2"><strong>Employee ID:</strong>
                            <?= htmlspecialchars($staff['employee_id'] ?? '—') ?></div>
                        <div class="mb-2"><strong>Department:</strong>
                            <?= htmlspecialchars($staff['dept_name'] ?? '—') ?></div>
                        <div class="mb-2"><strong>Branch:</strong> <?= htmlspecialchars($staff['branch_name'] ?? '—') ?>
                        </div>
                        <div class="mb-2"><strong>Joined:</strong>
                            <?= $staff['joining_date'] ? date('d M Y', strtotime($staff['joining_date'])) : '—' ?></div>
                        <div class="mb-2"><strong>Managed By:</strong>
                            <?= htmlspecialchars($staff['managed_by_name'] ?? '—') ?></div>
                        <div><strong>Last Login:</strong>
                            <?= $staff['last_login'] ? date('d M Y, H:i', strtotime($staff['last_login'])) : 'Never' ?>
                        </div>
                    </div>

                </div><!-- end col-lg-4 -->

            </div><!-- end row -->
        </div>

        <?php if (!empty($monthly)): ?>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const ctx = document.getElementById('monthlyChart');
                    if (!ctx) return;

                    const data = <?= json_encode($monthly) ?>;
                    if (!data.length) return;

                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.map(d => d.month_label),
                            datasets: [
                                {
                                    label: 'Assigned',
                                    data: data.map(d => parseInt(d.total)),
                                    backgroundColor: '#3b82f6',
                                    borderRadius: 5,
                                    borderSkipped: false,
                                },
                                {
                                    label: 'Done',
                                    data: data.map(d => parseInt(d.done)),
                                    backgroundColor: '#10b981',
                                    borderRadius: 5,
                                    borderSkipped: false,
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top',
                                    labels: { font: { size: 11 }, usePointStyle: true }
                                },
                                tooltip: {
                                    callbacks: {
                                        afterBody: function (items) {
                                            const idx = items[0].dataIndex;
                                            const total = parseInt(data[idx].total);
                                            const done = parseInt(data[idx].done);
                                            const pct = total > 0 ? Math.round((done / total) * 100) : 0;
                                            return [`Completion: ${pct}%`];
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { display: false },
                                    ticks: { font: { size: 11 } }
                                },
                                y: {
                                    beginAtZero: true,
                                    grid: { color: '#f3f4f6' },
                                    ticks: { stepSize: 1, font: { size: 11 } }
                                }
                            }
                        }
                    });
                });
            </script>
        <?php endif; ?>

        <?php include '../../includes/footer.php'; ?>