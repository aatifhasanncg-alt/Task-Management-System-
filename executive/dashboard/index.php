<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$user = currentUser();
$pageTitle = 'Executive Dashboard';

// ── Stats ──
// Tasks by status — join task_status
$tSt = $db->query("
    SELECT ts.status_name AS status, COUNT(t.id) as cnt
    FROM task_status ts
    LEFT JOIN tasks t ON t.status_id = ts.id AND t.is_active = 1
    GROUP BY ts.id, ts.status_name
");
$byStatus = array_column($tSt->fetchAll(), 'cnt', 'status');

$stats['total_tasks'] = array_sum($byStatus);
$stats['hbc'] = $byStatus['HBC'] ?? 0;
$stats['wip'] = $byStatus['WIP'] ?? 0;
$stats['pending'] = $byStatus['Pending'] ?? 0;
$stats['next_year'] = $byStatus['Next Year'] ?? 0;
$stats['done'] = $byStatus['Done'] ?? 0;

// Companies, staff, admins — join roles table
$stats['companies'] = $db->query("SELECT COUNT(*) FROM companies WHERE is_active=1")->fetchColumn();

$stats['staff'] = $db->query("
    SELECT COUNT(*) FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.role_name = 'staff' AND u.is_active = 1
")->fetchColumn();

$stats['admins'] = $db->query("
    SELECT COUNT(*) FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.role_name = 'admin' AND u.is_active = 1
")->fetchColumn();

// Tasks by department — use dept_name, join task_status
$deptTasks = $db->query("
    SELECT d.dept_name, d.color, d.icon,
           COUNT(t.id) as total,
           SUM(CASE WHEN ts.status_name = 'HBC'     THEN 1 ELSE 0 END) as hbc,
           SUM(CASE WHEN ts.status_name = 'WIP'     THEN 1 ELSE 0 END) as wip,
           SUM(CASE WHEN ts.status_name = 'Pending' THEN 1 ELSE 0 END) as pending,
           SUM(CASE WHEN ts.status_name = 'Done'    THEN 1 ELSE 0 END) as done
    FROM departments d
    LEFT JOIN tasks t      ON t.department_id = d.id AND t.is_active = 1
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE d.is_active = 1
      AND d.dept_name != 'Core Admin'
    GROUP BY d.id, d.dept_name, d.color, d.icon
    ORDER BY total DESC
")->fetchAll();
// Tasks by branch — join task_status
$branchTasks = $db->query("
    SELECT b.branch_name, b.city, b.is_head_office,
           COUNT(t.id) as total,
           SUM(CASE WHEN ts.status_name = 'WIP'     THEN 1 ELSE 0 END) as wip,
           SUM(CASE WHEN ts.status_name = 'Pending' THEN 1 ELSE 0 END) as pending,
           SUM(CASE WHEN ts.status_name = 'Done'    THEN 1 ELSE 0 END) as done
    FROM branches b
    LEFT JOIN tasks t        ON t.branch_id = b.id AND t.is_active = 1
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE b.is_active = 1
    GROUP BY b.id, b.branch_name, b.city, b.is_head_office
    ORDER BY total DESC
")->fetchAll();

// Recent tasks — use dept_name, status join
$recentTasks = $db->query("
    SELECT t.*,
           ts.status_name AS status,
           d.dept_name, d.color,
           b.branch_name,
           c.company_name,
           u.full_name AS assigned_to_name
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN departments d  ON d.id  = t.department_id
    LEFT JOIN branches b     ON b.id  = t.branch_id
    LEFT JOIN companies c    ON c.id  = t.company_id
    LEFT JOIN users u        ON u.id  = t.assigned_to
    WHERE t.is_active = 1
    ORDER BY t.created_at DESC
    LIMIT 8
")->fetchAll();

// Top staff — join roles, task_status, use dept_name
$topStaff = $db->query("
    SELECT u.full_name, u.employee_id,
           b.branch_name,
           d.dept_name,
           COUNT(t.id) as total,
           SUM(CASE WHEN ts.status_name = 'Done' THEN 1 ELSE 0 END) as done
    FROM users u
    LEFT JOIN roles r        ON r.id  = u.role_id
    LEFT JOIN branches b     ON b.id  = u.branch_id
    LEFT JOIN departments d  ON d.id  = u.department_id
    LEFT JOIN tasks t        ON t.assigned_to = u.id AND t.is_active = 1
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE r.role_name = 'staff' AND u.is_active = 1
    GROUP BY u.id, u.full_name, u.employee_id, b.branch_name, d.dept_name
    ORDER BY done DESC
    LIMIT 5
")->fetchAll();

include '../../includes/header.php';
?>
<style>
    * {
        overflow: visible !important;
    }
</style>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div style="padding:1.5rem 0;">

            <!-- Page Hero -->
            <div class="page-hero">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-crown"></i> Executive View</div>
                        <h4>Good <?= (date('H') < 12) ? 'Morning' : ((date('H') < 17) ? 'Afternoon' : 'Evening') ?>,
                            <?= htmlspecialchars(explode(' ', $user['full_name'] ?? $user['username'] ?? 'Executive')[0]) ?>
                        </h4>
                        <p>Overview of all tasks, branches, and staff across ASK Global Advisory.</p>
                    </div>

                    <div class="d-flex gap-2">
                        <a href="<?= APP_URL ?>/admin/tasks/assign.php"
                            class="btn-gold btn d-flex align-items-center gap-2">
                            <i class="fas fa-plus"></i> Assign Task
                        </a>
                        <a href="<?= APP_URL ?>/executive/reports/index.php"
                            class="btn btn-outline-light btn-sm d-flex align-items-center gap-2">
                            <i class="fas fa-chart-bar"></i> Reports
                        </a>
                        <?php if (isCoreAdmin()): ?>
                            <div class="dropdown ">
                                <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2"
                                    type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-cog"></i> Settings
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end shadow">
                                    <li>
                                        <a class="dropdown-item" href="<?= APP_URL ?>/executive/settings/task_status.php">
                                            <i class="fas fa-sliders me-2 text-primary"></i> Task Status
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item"
                                            href="<?= APP_URL ?>/executive/settings/corporate_grades.php">
                                            <i class="fas fa-chart-line me-2 text-success"></i> Corporate Grades
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="<?= APP_URL ?>/executive/settings/fiscal_year.php">
                                            <i class="fas fa-calendar-alt me-2 text-warning"></i> Fiscal Year
                                        </a>
                                        <a class="dropdown-item" href="<?= APP_URL ?>/executive/settings/industry.php">
                                            <i class="fas fa-building me-2 text-secondary"></i> Industry
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Main Stat Row -->
            <div class="row g-3 mb-4">
                <?php
                $mainStats = [
                    ['Tasks Total', $stats['total_tasks'], 'fa-list-check', '#3b82f6', '#eff6ff'],
                    ['WIP', $stats['wip'], 'fa-spinner', '#f59e0b', '#fffbeb'],
                    ['Pending', $stats['pending'], 'fa-clock', '#ef4444', '#fef2f2'],
                    ['HBC', $stats['hbc'], 'fa-hourglass', '#8b5cf6', '#f5f3ff'],
                    ['Done', $stats['done'], 'fa-check-circle', '#10b981', '#ecfdf5'],
                    ['Companies', $stats['companies'], 'fa-building', '#c9a84c', '#fefce8'],
                    ['Staff', $stats['staff'], 'fa-users', '#06b6d4', '#ecfeff'],
                    ['Admins', $stats['admins'], 'fa-user-shield', '#ec4899', '#fdf2f8'],
                ];
                foreach ($mainStats as [$label, $val, $icon, $color, $bg]):
                    ?>
                    <div class="col-6 col-md-3 col-xl-3">
                        <div class="stat-card">
                            <div class="stat-card-icon" style="background:<?= $bg ?>;color:<?= $color ?>;">
                                <i class="fas <?= $icon ?>"></i>
                            </div>
                            <div class="stat-card-value" style="color:<?= $color ?>;"><?= number_format($val) ?></div>
                            <div class="stat-card-label"><?= $label ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="row g-4 mb-4">

                <!-- Department Breakdown -->
                <div class="col-lg-7">
                    <div class="card-mis">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-layer-group text-warning me-2"></i>Tasks by Department</h5>
                        </div>
                        <div class="table-responsive">
                            <table class="table-mis w-100">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th class="text-center">Total</th>
                                        <th class="text-center">HBC</th>
                                        <th class="text-center">WIP</th>
                                        <th class="text-center">Pending</th>
                                        <th class="text-center">Done</th>
                                        <th>Progress</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($deptTasks as $dt):
                                        $pct = $dt['total'] ? round(($dt['done'] / $dt['total']) * 100) : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div
                                                        style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($dt['color']) ?>;flex-shrink:0;">
                                                    </div>
                                                    <span
                                                        style="font-weight:500;"><?= htmlspecialchars($dt['dept_name']) ?></span>
                                                </div>
                                            </td>
                                            <td class="text-center fw-bold"><?= $dt['total'] ?></td>
                                            <td class="text-center"><span
                                                    class="status-badge status-hbc"><?= $dt['hbc'] ?></span></td>
                                            <td class="text-center"><span
                                                    class="status-badge status-wip"><?= $dt['wip'] ?></span></td>
                                            <td class="text-center"><span
                                                    class="status-badge status-pending"><?= $dt['pending'] ?></span></td>
                                            <td class="text-center"><span
                                                    class="status-badge status-done"><?= $dt['done'] ?></span></td>
                                            <td style="min-width:100px;">
                                                <div
                                                    style="background:#f3f4f6;border-radius:50px;height:6px;overflow:hidden;">
                                                    <div
                                                        style="width:<?= $pct ?>%;background:<?= htmlspecialchars($dt['color']) ?>;height:6px;border-radius:50px;transition:width .4s;">
                                                    </div>
                                                </div>
                                                <div style="font-size:.7rem;color:#9ca3af;margin-top:2px;"><?= $pct ?>%
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($deptTasks)): ?>
                                        <tr>
                                            <td colspan="7" class="empty-state">No department data</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Branch Breakdown -->
                <div class="col-lg-5">
                    <div class="card-mis h-100">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-map-marker-alt text-warning me-2"></i>Tasks by Branch</h5>
                        </div>
                        <div class="card-mis-body">
                            <?php foreach ($branchTasks as $bt):
                                $pct = $bt['total'] ? round(($bt['done'] / $bt['total']) * 100) : 0;
                                ?>
                                <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
                                    <div
                                        style="width:40px;height:40px;border-radius:10px;background:#f0f4f8;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <i class="fas fa-building" style="color:#6b7280;font-size:.85rem;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between">
                                            <span style="font-weight:600;font-size:.88rem;">
                                                <?= htmlspecialchars($bt['branch_name']) ?>
                                                <?php if ($bt['is_head_office']): ?>
                                                    <span class="ms-1"
                                                        style="font-size:.68rem;background:#fefce8;color:#ca8a04;border-radius:50px;padding:.1rem .5rem;font-weight:600;">HO</span>
                                                <?php endif; ?>
                                            </span>
                                            <span style="font-weight:700;font-size:.9rem;"><?= $bt['total'] ?></span>
                                        </div>
                                        <div
                                            style="background:#f3f4f6;border-radius:50px;height:5px;margin:.4rem 0;overflow:hidden;">
                                            <div
                                                style="width:<?= $pct ?>%;background:linear-gradient(90deg,#c9a84c,#e8c96a);height:5px;border-radius:50px;">
                                            </div>
                                        </div>
                                        <div class="d-flex gap-3" style="font-size:.72rem;color:#9ca3af;">
                                            <span>WIP: <?= $bt['wip'] ?></span>
                                            <span>Pending: <?= $bt['pending'] ?></span>
                                            <span>Done: <?= $bt['done'] ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Recent Tasks + Top Staff -->
            <div class="row g-4">

                <!-- Recent Tasks -->
                <div class="col-lg-8">
                    <div class="card-mis">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-clock text-warning me-2"></i>Recent Tasks</h5>
                            <a href="<?= APP_URL ?>/admin/tasks/index.php" class="btn btn-sm btn-outline-secondary">View
                                All</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table-mis w-100">
                                <thead>
                                    <tr>
                                        <th>Task #</th>
                                        <th>Title / Company</th>
                                        <th>Dept</th>
                                        <th>Branch</th>
                                        <th>Assigned To</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentTasks)): ?>
                                        <tr>
                                            <td colspan="6" class="empty-state"><i class="fas fa-list-check"></i>No tasks
                                                yet</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($recentTasks as $t):
                                        $sClass = 'status-' . strtolower(str_replace(' ', '-', $t['status']));
                                        ?>
                                        <tr>
                                            <td><span class="task-number"><?= htmlspecialchars($t['task_number']) ?></span>
                                            </td>
                                            <td>
                                                <div
                                                    style="font-size:.87rem;font-weight:500;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                    <?= htmlspecialchars($t['title']) ?>
                                                </div>
                                                <?php if ($t['company_name']): ?>
                                                    <div style="font-size:.74rem;color:#9ca3af;">
                                                        <?= htmlspecialchars($t['company_name']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span
                                                    style="font-size:.75rem;background:<?= htmlspecialchars($t['color'] ?? '#ccc') ?>22;color:<?= htmlspecialchars($t['color'] ?? '#666') ?>;padding:.2rem .55rem;border-radius:99px;">
                                                    <?= htmlspecialchars($t['dept_name'] ?? '—') ?>
                                                </span>
                                            </td>
                                            <td style="font-size:.82rem;"><?= htmlspecialchars($t['branch_name'] ?? '—') ?>
                                            </td>
                                            <td>
                                                <?php if ($t['assigned_to_name']): ?>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <div class="avatar-circle avatar-sm"
                                                            style="width:24px;height:24px;font-size:.65rem;">
                                                            <?= strtoupper(substr($t['assigned_to_name'], 0, 2)) ?>
                                                        </div>
                                                        <span
                                                            style="font-size:.82rem;"><?= htmlspecialchars(explode(' ', $t['assigned_to_name'])[0]) ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color:#9ca3af;font-size:.8rem;">Unassigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span
                                                    class="status-badge <?= $sClass ?>"><?= htmlspecialchars($t['status']) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Top Staff -->
                <div class="col-lg-4">
                    <div class="card-mis">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-trophy text-warning me-2"></i>Top Performers</h5>
                        </div>
                        <div class="card-mis-body">
                            <?php if (empty($topStaff)): ?>
                                <div class="empty-state"><i class="fas fa-users"></i>No staff data</div>
                            <?php endif; ?>
                            <?php foreach ($topStaff as $i => $s):
                                $pct = $s['total'] ? round(($s['done'] / $s['total']) * 100) : 0;
                                $medals = ['#c9a84c', '#9ca3af', '#cd7f32'];
                                ?>
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div
                                        style="width:26px;text-align:center;font-weight:700;font-size:.9rem;color:<?= $medals[$i] ?? '#6b7280' ?>;">
                                        #<?= $i + 1 ?>
                                    </div>
                                    <div class="avatar-circle avatar-sm">
                                        <?= strtoupper(substr($s['full_name'], 0, 2)) ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div style="font-size:.85rem;font-weight:500;">
                                            <?= htmlspecialchars(explode(' ', $s['full_name'])[0]) ?>
                                        </div>
                                        <div style="font-size:.72rem;color:#9ca3af;">
                                            <?= htmlspecialchars($s['branch_name'] ?? '') ?>
                                            <?php if ($s['dept_name']): ?>
                                                · <?= htmlspecialchars($s['dept_name']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div
                                            style="background:#f3f4f6;border-radius:50px;height:4px;margin-top:.3rem;overflow:hidden;">
                                            <div
                                                style="width:<?= $pct ?>%;background:linear-gradient(90deg,#c9a84c,#e8c96a);height:4px;border-radius:50px;">
                                            </div>
                                        </div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div style="font-size:.95rem;font-weight:700;color:#10b981;"><?= $s['done'] ?></div>
                                        <div style="font-size:.7rem;color:#9ca3af;">done</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php include '../../includes/footer.php'; ?>