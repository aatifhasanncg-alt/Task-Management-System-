<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$pageTitle = 'All Staff';

$search = trim($_GET['search'] ?? '');
$filterB = (int) ($_GET['branch_id'] ?? 0);
$filterD = (int) ($_GET['dept_id'] ?? 0);
$filterR = trim($_GET['role'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['u.is_active = 1'];
$params = [];

if ($search) {
    $where[] = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.employee_id LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($filterB) {
    $where[] = 'u.branch_id = ?';
    $params[] = $filterB;
}
if ($filterD) {
    $where[] = 'u.department_id = ?';
    $params[] = $filterD;
}
if ($filterR) {
    $where[] = 'r.role_name = ?';
    $params[] = $filterR;
} // ← JOIN roles

$ws = implode(' AND ', $where);

// Count
$cnt = $db->prepare("
    SELECT COUNT(*) FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE {$ws}
");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$pages = (int) ceil($total / $perPage);

// Staff list
$userStmt = $db->prepare("
    SELECT u.*,
           r.role_name,
           b.branch_name,
           d.dept_name,
           um.full_name AS managed_by_name,
           COUNT(DISTINCT t.id) AS task_total,
           SUM(CASE WHEN ts.status_name = 'Done' THEN 1 ELSE 0 END) AS task_done
    FROM users u
    LEFT JOIN roles r        ON r.id  = u.role_id
    LEFT JOIN branches b     ON b.id  = u.branch_id
    LEFT JOIN departments d  ON d.id  = u.department_id
    LEFT JOIN users um       ON um.id = u.managed_by
    LEFT JOIN tasks t        ON t.assigned_to = u.id AND t.is_active = 1
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE {$ws}
    GROUP BY u.id, u.full_name, u.username, u.email, u.employee_id,
             u.phone, u.branch_id, u.department_id, u.managed_by,
             u.is_active, u.joining_date, u.role_id,
             r.role_name, b.branch_name, d.dept_name, um.full_name
    ORDER BY r.role_name, b.branch_name, u.full_name
    LIMIT {$perPage} OFFSET {$offset}
");
$userStmt->execute($params);
$staffList = $userStmt->fetchAll();

$allBranches = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
$allDepts = $db->query("SELECT id, dept_name FROM departments WHERE is_active=1 AND dept_name!='CORE ADMIN' ORDER BY dept_name")->fetchAll();

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <div class="page-hero">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-users"></i> Staff</div>
                        <h4>All Staff Members</h4>
                        <p><?= number_format($total) ?> users across all branches</p>
                    </div>
                    <?php if (isCoreAdmin()): ?>
                        <a href="add.php" class="btn btn-gold">
                            <i class="fas fa-plus me-1"></i>Add Staff
                        </a>
                    <?php endif; ?>
                </div>
            </div>


            <?= flashHtml() ?>

            <!-- Filters -->
            <div class="filter-bar mb-4">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label-mis">Search</label>
                        <input type="text" name="search" class="form-control form-control-sm"
                            placeholder="Name, email, employee ID..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">Branch</label>
                        <select name="branch_id" class="form-select form-select-sm">
                            <option value="">All Branches</option>
                            <?php foreach ($allBranches as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $filterB == $b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">Department</label>
                        <select name="dept_id" class="form-select form-select-sm">
                            <option value="">All Depts</option>
                            <?php foreach ($allDepts as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= $filterD == $d['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['dept_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">Role</label>
                        <select name="role" class="form-select form-select-sm">
                            <option value="">All Roles</option>
                            <option value="executive" <?= $filterR === 'executive' ? 'selected' : '' ?>>Executive</option>
                            <option value="admin" <?= $filterR === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="staff" <?= $filterR === 'staff' ? 'selected' : '' ?>>Staff</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-1">
                        <button type="submit" class="btn btn-gold btn-sm w-100">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- Table -->
            <div class="card-mis">
                <div class="card-mis-header">
                    <h5><i class="fas fa-users text-warning me-2"></i>Staff Directory</h5>
                    <small class="text-muted"><?= $total ?> users</small>
                </div>
                <div class="table-responsive">
                    <table class="table-mis w-100">
                        <thead>
                            <tr>
                                <th>Staff Member</th>
                                <th>Role</th>
                                <th>Branch</th>
                                <th>Department</th>
                                <th>Tasks</th>
                                <th>Completion</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staffList)): ?>
                                <tr>
                                    <td colspan="8" class="empty-state">
                                        <i class="fas fa-users"></i>No users found
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($staffList as $u):
                                $pct = $u['task_total'] ? round(($u['task_done'] / $u['task_total']) * 100) : 0;
                                $roleColors = [
                                    'executive' => '#c9a84c',
                                    'admin' => '#3b82f6',
                                    'staff' => '#10b981',
                                ];
                                $rc = $roleColors[$u['role_name']] ?? '#9ca3af';
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-circle avatar-sm flex-shrink-0"
                                                style="background:<?= $rc ?>22;color:<?= $rc ?>;border:1px solid <?= $rc ?>44;">
                                                <?= strtoupper(substr($u['full_name'], 0, 2)) ?>
                                            </div>
                                            <div>
                                                <div style="font-size:.87rem;font-weight:500;">
                                                    <?= htmlspecialchars($u['full_name']) ?>
                                                </div>
                                                <div style="font-size:.72rem;color:#9ca3af;">
                                                    <?= htmlspecialchars($u['email'] ?? '') ?>
                                                </div>
                                                <?php if ($u['employee_id']): ?>
                                                    <div style="font-size:.7rem;color:#c9a84c;">
                                                        <?= htmlspecialchars($u['employee_id']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span
                                            style="font-size:.78rem;font-weight:700;color:<?= $rc ?>;background:<?= $rc ?>15;padding:.2rem .6rem;border-radius:50px;">
                                            <?= ucfirst($u['role_name'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td style="font-size:.83rem;"><?= htmlspecialchars($u['branch_name'] ?? '—') ?></td>
                                    <td style="font-size:.83rem;"><?= htmlspecialchars($u['dept_name'] ?? '—') ?></td>
                                    <td>
                                        <span class="status-badge status-wip"><?= $u['task_total'] ?> total</span>
                                        <span class="status-badge status-done ms-1"><?= $u['task_done'] ?> done</span>
                                    </td>
                                    <td style="min-width:120px;">
                                        <div class="d-flex align-items-center gap-2">
                                            <div style="flex:1;background:#f3f4f6;border-radius:99px;height:6px;">
                                                <div
                                                    style="width:<?= $pct ?>%;height:100%;border-radius:99px;background:<?= $pct >= 75 ? '#10b981' : ($pct >= 40 ? '#f59e0b' : '#ef4444') ?>;">
                                                </div>
                                            </div>
                                            <span style="font-size:.72rem;color:#6b7280;flex-shrink:0;"><?= $pct ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($u['is_active']): ?>
                                            <span style="font-size:.75rem;color:#10b981;font-weight:600;">
                                                <i class="fas fa-circle me-1" style="font-size:.5rem;"></i>Active
                                            </span>
                                        <?php else: ?>
                                            <span style="font-size:.75rem;color:#ef4444;font-weight:600;">
                                                <i class="fas fa-circle me-1" style="font-size:.5rem;"></i>Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                         <?php if (isCoreAdmin()): ?>
                                        <div class="d-flex gap-1">
                                            <a href="<?= APP_URL ?>/executive/staff/view.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-secondary"
                                                title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="<?= APP_URL ?>/executive/staff/edit.php?id=<?= $u['id'] ?>"
                                                class="btn btn-sm btn-outline-warning" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                        </div>
                                        <?php endif;?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center px-4 py-3 border-top">
                        <small class="text-muted">
                            Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?>
                        </small>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php for ($p = 1; $p <= $pages; $p++): ?>
                                    <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                                        <a class="page-link"
                                            href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>">
                                            <?= $p ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <?php include '../../includes/footer.php'; ?>