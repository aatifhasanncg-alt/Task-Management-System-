<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$user = currentUser();
updateActiveAt($db, (int)$user['id']);
$pageTitle = 'Executive Dashboard';

// ── Task statuses from DB ──
$statusRows = $db->query("
    SELECT id, status_name, color, bg_color, icon
    FROM task_status
    ORDER BY id ASC
")->fetchAll();

$statusMeta = [];
foreach ($statusRows as $sr) {
    $statusMeta[$sr['status_name']] = $sr;
}

// Tasks by status
$tSt = $db->query("
    SELECT ts.status_name AS status, COUNT(t.id) as cnt
    FROM task_status ts
    LEFT JOIN tasks t ON t.status_id = ts.id AND t.is_active = 1
    GROUP BY ts.id, ts.status_name
");
$byStatus = array_column($tSt->fetchAll(), 'cnt', 'status');
$stats['total_tasks'] = array_sum($byStatus);

$stats['companies'] = $db->query("SELECT COUNT(*) FROM companies WHERE is_active=1")->fetchColumn();
$stats['staff'] = $db->query("
    SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
    WHERE r.role_name = 'staff' AND u.is_active = 1
")->fetchColumn();
$stats['admins'] = $db->query("
    SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
    WHERE r.role_name = 'admin' AND u.is_active = 1
")->fetchColumn();

// ── Dept tasks: one dynamic CASE column per status ──
$statusCases = '';
foreach ($statusRows as $sr) {
    $sn = addslashes($sr['status_name']);
    $statusCases .= "SUM(CASE WHEN ts.status_name = '{$sn}' THEN 1 ELSE 0 END) AS `status_{$sr['id']}`,\n";
}

$deptTasks = $db->query("
    SELECT d.dept_name, d.color, d.icon,
           COUNT(t.id) as total,
           {$statusCases}
           SUM(CASE WHEN ts.status_name = 'Done' THEN 1 ELSE 0 END) as done
    FROM departments d
    LEFT JOIN tasks t        ON t.department_id = d.id AND t.is_active = 1
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE d.is_active = 1 AND d.dept_name != 'Core Admin'
    GROUP BY d.id, d.dept_name, d.color, d.icon
    ORDER BY total DESC
")->fetchAll();

// Branch tasks
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

// Recent tasks
$recentTasks = $db->query("
    SELECT t.id, t.task_number, t.title,
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

// Top staff
$topStaff = $db->query("
    SELECT u.full_name, u.employee_id,
           b.branch_name, d.dept_name,
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

    /* Clickable stat cards */
    a.stat-card-link {
        text-decoration: none;
        color: inherit;
        display: block;
    }

    a.stat-card-link .stat-card {
        transition: transform .15s, box-shadow .15s;
        cursor: pointer;
    }

    a.stat-card-link:hover .stat-card {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, .1);
    }

    /* Dept status pill */
    .dept-status-pill {
        display: inline-block;
        min-width: 22px;
        text-align: center;
        font-size: .68rem;
        font-weight: 700;
        padding: .12rem .38rem;
        border-radius: 99px;
    }

    .dept-status-zero-border {
        border: 1px dashed #d1d5db;
        color: #9ca3af;
        background: transparent;
        padding: 2px 6px;
        border-radius: 10px;
        font-size: 0.7rem;
    }

    /* Recent tasks overflow */
    .rt-table {
        table-layout: fixed;
        width: 100%;
    }

    .rt-table td {
        vertical-align: middle;
    }

    .ellipsis {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: block;
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
                        <a href="<?= APP_URL ?>/executive/tasks/assign.php"
                            class="btn-gold btn d-flex align-items-center gap-2">
                            <i class="fas fa-plus"></i> Assign Task
                        </a>
                        <a href="<?= APP_URL ?>/executive/reports/index.php"
                            class="btn btn-outline-light btn-sm d-flex align-items-center gap-2">
                            <i class="fas fa-chart-bar"></i> Reports
                        </a>
                        <?php if (isCoreAdmin()): ?>
                            <div class="dropdown">
                                <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2"
                                    type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-cog"></i>
                                    <span>Settings</span>
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end shadow p-2" style="min-width:220px;">

                                    <!-- Header -->
                                    <li class="px-3 py-2 text-muted small fw-semibold">
                                        <i class="fas fa-sliders-h me-1"></i> System Configuration
                                    </li>

                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>

                                    <!-- Items -->
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                            href="<?= APP_URL ?>/executive/settings/task_status.php">
                                            <i class="fas fa-tasks text-primary"></i>
                                            <span>Task Status</span>
                                        </a>
                                    </li>

                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                            href="<?= APP_URL ?>/executive/settings/corporate_grades.php">
                                            <i class="fas fa-chart-line text-success"></i>
                                            <span>Corporate Grades</span>
                                        </a>
                                    </li>

                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                            href="<?= APP_URL ?>/executive/settings/fiscal_year.php">
                                            <i class="fas fa-calendar-alt text-warning"></i>
                                            <span>Fiscal Year</span>
                                        </a>
                                    </li>

                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                            href="<?= APP_URL ?>/executive/settings/industry.php">
                                            <i class="fas fa-building text-secondary"></i>
                                            <span>Industry</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                            href="<?= APP_URL ?>/executive/settings/type.php">
                                            <i class="fas fa-tags text-info"></i>
                                            <span>Company Type</span>
                                        </a>
                                    </li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>

                                    <li class="px-3 py-1 text-muted small fw-semibold">
                                        <i class="fas fa-sitemap me-1"></i> Organization
                                    </li>

                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                            href="<?= APP_URL ?>/executive/settings/department.php">
                                            <i class="fas fa-layer-group"></i>
                                            <span>Departments</span>
                                        </a>
                                    </li>

                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                            href="<?= APP_URL ?>/executive/settings/branch.php">
                                            <i class="fas fa-code-branch text-success"></i>
                                            <span>Branches</span>
                                        </a>
                                    </li>



                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── Task Status Cards ── -->
            <h5 class="mb-3">Task Status</h5>
            <div class="row g-3 mb-4">
                <?php foreach ($statusRows as $sr):
                    $cnt = $byStatus[$sr['status_name']] ?? 0;
                    $color = $sr['color'] ?: '#6b7280';
                    $bg = $sr['bg_color'] ?: '#f3f4f6';
                    $rawIcon = trim($sr['icon'] ?: 'fa-circle');
                    $iClass = str_starts_with($rawIcon, 'fa') ? $rawIcon : 'fa-' . $rawIcon;
                    $link = APP_URL . '/executive/tasks/index.php?status=' . urlencode($sr['status_name']);
                    ?>
                    <div class="col-6 col-md-3 col-xl-2">
                        <a href="<?= $link ?>" class="stat-card-link">
                            <div class="stat-card">
                                <div class="stat-card-icon"
                                    style="background:<?= htmlspecialchars($bg) ?>;color:<?= htmlspecialchars($color) ?>;">
                                    <i class="fas <?= htmlspecialchars($iClass) ?>"></i>
                                </div>
                                <div class="stat-card-value" style="color:<?= htmlspecialchars($color) ?>;">
                                    <?= number_format($cnt) ?>
                                </div>
                                <div class="stat-card-label"><?= htmlspecialchars($sr['status_name']) ?></div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ── Overview Cards ── -->
            <h5 class="mb-3">Overview</h5>
            <div class="row g-3 mb-4">
                <?php
                $otherCards = [
                    ['Tasks Total', $stats['total_tasks'], 'fa-layer-group', '#2563eb', '#eff6ff', APP_URL . '/executive/tasks/index.php'],
                    ['Companies', $stats['companies'], 'fa-building', '#0ea5e9', '#ecfeff', APP_URL . '/executive/companies/index.php'],
                    ['Staff', $stats['staff'], 'fa-users', '#14b8a6', '#f0fdfa', APP_URL . '/executive/staff/index.php?role=staff'],
                    ['Admins', $stats['admins'], 'fa-user-shield', '#db2777', '#fdf2f8', APP_URL . '/executive/staff/index.php?role=admin'],
                ];
                foreach ($otherCards as [$label, $val, $icon, $color, $bg, $link]): ?>
                    <div class="col-6 col-md-3 col-xl-3">
                        <a href="<?= $link ?>" class="stat-card-link">
                            <div class="stat-card">
                                <div class="stat-card-icon" style="background:<?= $bg ?>;color:<?= $color ?>;">
                                    <i class="fas <?= $icon ?>"></i>
                                </div>
                                <div class="stat-card-value" style="color:<?= $color ?>;"><?= number_format($val) ?></div>
                                <div class="stat-card-label"><?= $label ?></div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ── Tasks by Department — FULL WIDTH, all statuses ── -->
            <div class="mb-4">
                <div class="card-mis">
                    <div class="card-mis-header">
                        <h5><i class="fas fa-layer-group text-warning me-2"></i>Tasks by Department</h5>
                        <small class="text-muted"><?= count($deptTasks) ?> departments</small>
                    </div>
                    <div class="table-responsive">
                        <table class="table-mis w-100" style="font-size:.78rem;">
                            <thead>
                                <tr>
                                    <th style="min-width:130px;">Department</th>
                                    <th class="text-center" style="width:52px;">Total</th>
                                    <?php foreach ($statusRows as $sr):
                                        $sc = $sr['color'] ?: '#9ca3af'; ?>
                                        <th class="text-center" style="width:60px;">
                                            <span
                                                style="color:<?= htmlspecialchars($sc) ?>;font-size:.65rem;font-weight:700;white-space:nowrap;">
                                                <?= htmlspecialchars($sr['status_name']) ?>
                                            </span>
                                        </th>
                                    <?php endforeach; ?>
                                    <th style="min-width:100px;">Progress</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deptTasks as $dt):
                                    $doneVal = (int) ($dt['done'] ?? 0);
                                    $pct = $dt['total'] ? round(($doneVal / $dt['total']) * 100) : 0;
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
                                        <?php foreach ($statusRows as $sr):
                                            $colKey = 'status_' . $sr['id'];
                                            $colVal = (int) ($dt[$colKey] ?? 0);
                                            $sc = $sr['color'] ?: '#9ca3af';
                                            $sbg = $sr['bg_color'] ?: '#f3f4f6';
                                            ?>
                                            <td class="text-center">
                                                <?php if ($colVal > 0): ?>
                                                    <span class="dept-status-pill"
                                                        style="background:<?= htmlspecialchars($sbg) ?>;color:<?= htmlspecialchars($sc) ?>;">
                                                        <?= $colVal ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="dept-status-zero-border">0</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td>
                                            <div style="background:#f3f4f6;border-radius:50px;height:5px;overflow:hidden;">
                                                <div
                                                    style="width:<?= $pct ?>%;background:<?= htmlspecialchars($dt['color']) ?>;height:5px;border-radius:50px;transition:width .4s;">
                                                </div>
                                            </div>
                                            <div style="font-size:.65rem;color:#9ca3af;margin-top:2px;"><?= $pct ?>% done
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($deptTasks)): ?>
                                    <tr>
                                        <td colspan="<?= 3 + count($statusRows) ?>" class="empty-state">No department data
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ── Recent Tasks (full width) ── -->
            <div class="mb-4">
                <div class="card-mis">
                    <div class="card-mis-header">
                        <h5><i class="fas fa-clock text-warning me-2"></i>Recent Tasks</h5>
                        <a href="<?= APP_URL ?>/executive/tasks/index.php" class="btn btn-sm btn-outline-secondary">View
                            All</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table-mis rt-table">

                            <colgroup>
                                <col> <!-- Task # -->
                                <col> <!-- Title (flex) -->
                                <col style="width:90px;"> <!-- Dept -->
                                <col style="width:120px;"> <!-- Branch -->
                                <col style="width:120px;"> <!-- Assigned -->
                                <col style="width:150px;"> <!-- Status -->
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Task #</th>
                                    <th>Title / Company</th>
                                    <th>Dept</th>
                                    <th>Branch</th>
                                    <th>Assigned</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentTasks)): ?>
                                    <tr>
                                        <td colspan="6" class="empty-state"><i class="fas fa-list-check"></i>No tasks yet
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($recentTasks as $t):
                                    $sClass = 'status-' . strtolower(str_replace(' ', '-', $t['status']));
                                    $sMeta = $statusMeta[$t['status']] ?? null;
                                    $rawIco = trim($sMeta['icon'] ?? 'fa-circle');
                                    $sIco = str_starts_with($rawIco, 'fa') ? $rawIco : 'fa-' . $rawIco;
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="task-number ellipsis" style="font-size:.74rem;"
                                                title="<?= htmlspecialchars($t['task_number']) ?>">
                                                <?= htmlspecialchars($t['task_number']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="ellipsis" style="font-size:.85rem;font-weight:500;"
                                                title="<?= htmlspecialchars($t['title']) ?>">
                                                <?= htmlspecialchars($t['title']) ?>
                                            </div>
                                            <?php if ($t['company_name']): ?>
                                                <div class="ellipsis" style="font-size:.7rem;color:#9ca3af;"
                                                    title="<?= htmlspecialchars($t['company_name']) ?>">
                                                    <?= htmlspecialchars($t['company_name']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="ellipsis"
                                                style="font-size:.7rem;
                                                  background:<?= htmlspecialchars($t['color'] ?? '#ccc') ?>22;
                                                  color:<?= htmlspecialchars($t['color'] ?? '#666') ?>;
                                                  padding:.18rem .42rem;border-radius:99px;display:inline-block;max-width:100%;"
                                                title="<?= htmlspecialchars($t['dept_name'] ?? '') ?>">
                                                <?= htmlspecialchars($t['dept_name'] ?? '—') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="ellipsis" style="font-size:.78rem;"
                                                title="<?= htmlspecialchars($t['branch_name'] ?? '') ?>">
                                                <?= htmlspecialchars(strtok($t['branch_name'], ' ') ?? '—') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($t['assigned_to_name']): ?>
                                                <div class="d-flex align-items-center gap-1" style="min-width:0;">
                                                    <div class="avatar-circle"
                                                        style="width:20px;height:20px;font-size:.55rem;flex-shrink:0;">
                                                        <?= strtoupper(substr($t['assigned_to_name'], 0, 2)) ?>
                                                    </div>
                                                    <span class="ellipsis" style="font-size:.78rem;"
                                                        title="<?= htmlspecialchars($t['assigned_to_name']) ?>">
                                                        <?= htmlspecialchars(explode(' ', $t['assigned_to_name'])[0]) ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span style="color:#9ca3af;font-size:.75rem;"><i
                                                        class="fas fa-minus"></i></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $sClass ?>" style="display:inline-flex;align-items:center;gap:.25rem;
                                                         font-size:.67rem;white-space:nowrap;">
                                                <i class="fas <?= htmlspecialchars($sIco) ?>"
                                                    style="font-size:.52rem;flex-shrink:0;"></i>
                                                <span class="ellipsis"><?= htmlspecialchars($t['status']) ?></span>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ── Top Performers + Tasks by Branch (side by side) ── -->
            <div class="row g-4">

                <!-- Top Performers -->
                <div class="col-lg-5">
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
                                    <div class="flex-grow-1" style="min-width:0;">
                                        <div class="ellipsis" style="font-size:.85rem;font-weight:500;">
                                            <?= htmlspecialchars(explode(' ', $s['full_name'])[0]) ?>
                                        </div>
                                        <div class="ellipsis" style="font-size:.72rem;color:#9ca3af;">
                                            <?= htmlspecialchars($s['branch_name'] ?? '') ?>
                                            <?php if ($s['dept_name']): ?> ·
                                                <?= htmlspecialchars($s['dept_name']) ?>     <?php endif; ?>
                                        </div>
                                        <div
                                            style="background:#f3f4f6;border-radius:50px;height:4px;margin-top:.3rem;overflow:hidden;">
                                            <div
                                                style="width:<?= $pct ?>%;background:linear-gradient(90deg,#c9a84c,#e8c96a);height:4px;border-radius:50px;">
                                            </div>
                                        </div>
                                    </div>
                                    <div style="text-align:right;flex-shrink:0;">
                                        <div style="font-size:.95rem;font-weight:700;color:#10b981;"><?= $s['done'] ?></div>
                                        <div style="font-size:.7rem;color:#9ca3af;">done</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Tasks by Branch -->
                <div class="col-lg-7">
                    <div class="card-mis h-100">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-map-marker-alt text-warning me-2"></i>Tasks by Branch</h5>
                        </div>
                        <div class="card-mis-body">
                            <?php foreach ($branchTasks as $bt):
                                $pct = $bt['total'] ? round(($bt['done'] / $bt['total']) * 100) : 0;
                                ?>
                                <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
                                    <div style="width:40px;height:40px;border-radius:10px;
                                                background:<?= $bt['is_head_office'] ? '#fef9ec' : '#f0f4f8' ?>;
                                                display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <i class="fas <?= $bt['is_head_office'] ? 'fa-building-columns' : 'fa-map-marker-alt' ?>"
                                            style="color:<?= $bt['is_head_office'] ? '#c9a84c' : '#6b7280' ?>;font-size:.85rem;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span style="font-weight:600;font-size:.88rem;">
                                                <?= htmlspecialchars($bt['branch_name']) ?>
                                                <?php if ($bt['is_head_office']): ?>
                                                    <span class="ms-1"
                                                        style="font-size:.65rem;background:#fefce8;color:#ca8a04;border-radius:50px;padding:.1rem .45rem;font-weight:600;">HO</span>
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
                                        <div class="d-flex gap-3 flex-wrap" style="font-size:.72rem;color:#9ca3af;">
                                            <span>WIP: <strong style="color:#f59e0b;"><?= $bt['wip'] ?></strong></span>
                                            <span>Pending: <strong
                                                    style="color:#ef4444;"><?= $bt['pending'] ?></strong></span>
                                            <span>Done: <strong style="color:#10b981;"><?= $bt['done'] ?></strong></span>
                                            <span style="margin-left:auto;color:#6b7280;"><?= $pct ?>% complete</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($branchTasks)): ?>
                                <div class="text-center py-4" style="color:#9ca3af;font-size:.83rem;">No branch data</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div><!-- /row -->

        </div>
        <?php include '../../includes/footer.php'; ?>
    </div>
</div>