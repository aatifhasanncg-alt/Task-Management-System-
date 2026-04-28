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
    $where[] = '(u.department_id = ? OR EXISTS (
        SELECT 1 FROM user_department_assignments uda
        WHERE uda.user_id = u.id AND uda.department_id = ?
    ))';
    $params[] = $filterD;
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

// Fetch all statuses for per-dept task breakdown
$allStatuses = $db->query("
    SELECT id, status_name, color, bg_color
    FROM task_status
    WHERE status_name != 'Corporate Team'
    ORDER BY id
")->fetchAll();

// Fetch UDA dept names + per-dept task stats per user
$staffIds = array_column($staffList, 'id');
$udaByUser = [];  // user_id => [['dept_name'=>..., 'dept_id'=>...], ...]
$deptStatsByUser = []; // user_id => [dept_id => [status_name => count]]

if (!empty($staffIds)) {
    $inList = implode(',', array_fill(0, count($staffIds), '?'));

    // UDA dept names
    $udaStmt = $db->prepare("
        SELECT uda.user_id, d.id AS dept_id, d.dept_name, d.color
        FROM user_department_assignments uda
        JOIN departments d ON d.id = uda.department_id
        WHERE uda.user_id IN ({$inList})
        ORDER BY d.dept_name
    ");
    $udaStmt->execute($staffIds);
    foreach ($udaStmt->fetchAll() as $row) {
        $udaByUser[$row['user_id']][] = $row;
    }

    // Per-dept task stats for each user (primary dept + UDA depts)
    $taskStatStmt = $db->prepare("
        SELECT
            t.assigned_to AS user_id,
            t.department_id,
            d.dept_name,
            d.color AS dept_color,
            ts.status_name,
            ts.color AS status_color,
            ts.bg_color AS status_bg,
            COUNT(DISTINCT t.id) AS cnt
        FROM tasks t
        JOIN departments d  ON d.id  = t.department_id
        JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active = 1
          AND t.assigned_to IN ({$inList})
          AND ts.status_name != 'Corporate Team'
        GROUP BY t.assigned_to, t.department_id, d.dept_name, d.color,
                 ts.status_name, ts.color, ts.bg_color
        ORDER BY d.dept_name, ts.id
    ");
    $taskStatStmt->execute($staffIds);
    foreach ($taskStatStmt->fetchAll() as $row) {
        $uid = $row['user_id'];
        $did = $row['department_id'];
        $deptStatsByUser[$uid][$did]['dept_name'] = $row['dept_name'];
        $deptStatsByUser[$uid][$did]['dept_color'] = $row['dept_color'];
        $deptStatsByUser[$uid][$did]['statuses'][$row['status_name']] = [
            'cnt' => (int) $row['cnt'],
            'color' => $row['status_color'],
            'bg' => $row['status_bg'],
        ];
    }
}

$allBranches = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
$allDepts = $db->query("SELECT id, dept_name FROM departments WHERE is_active=1 AND dept_name!='CORE ADMIN' ORDER BY dept_name")->fetchAll();

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php require_once '../../includes/topbar.php'; ?>
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
                                <th>Departments</th>
                                <th>Tasks</th>
                                <th style="min-width:220px;">Per-Dept Task Breakdown</th>
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
                                    <td style="font-size:.82rem;">
                                        <!-- Primary dept -->
                                        <span style="background:#fef3c7;color:#92400e;padding:.15rem .5rem;
                 border-radius:99px;font-size:.7rem;font-weight:700;
                 display:inline-block;margin-bottom:.2rem;">
                                            ★ <?= htmlspecialchars($u['dept_name'] ?? '—') ?>
                                        </span>
                                        <!-- UDA depts -->
                                        <?php foreach ($udaByUser[$u['id']] ?? [] as $uda): ?>
                                            <?php if ($uda['dept_id'] != $u['department_id']): ?>
                                                <span style="background:#eff6ff;color:#3b82f6;padding:.15rem .5rem;
                     border-radius:99px;font-size:.68rem;font-weight:600;
                     display:inline-block;margin-bottom:.2rem;">
                                                    <?= htmlspecialchars($uda['dept_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-wip"><?= $u['task_total'] ?> total</span>
                                        <span class="status-badge status-done ms-1"><?= $u['task_done'] ?> done</span>
                                    </td>
                                    <!-- Per-dept breakdown -->
                                    <td style="min-width:220px;padding:.5rem .75rem;">
                                        <?php
                                        $userDeptStats = $deptStatsByUser[$u['id']] ?? [];
                                        if (empty($userDeptStats)):
                                            ?>
                                            <span style="font-size:.72rem;color:#d1d5db;">No tasks</span>
                                        <?php else: ?>
                                            <?php foreach ($userDeptStats as $did => $deptData):
                                                $deptTotal = array_sum(array_column($deptData['statuses'], 'cnt'));
                                                $doneCnt = 0;
                                                foreach ($deptData['statuses'] as $sn => $sv) {
                                                    if (strtolower($sn) === 'done')
                                                        $doneCnt = $sv['cnt'];
                                                }
                                                $donePct = $deptTotal > 0 ? round(($doneCnt / $deptTotal) * 100) : 0;
                                                $dColor = $deptData['dept_color'] ?: '#9ca3af';
                                                ?>
                                                <div style="margin-bottom:.6rem;background:#f9fafb;border-radius:8px;
                    padding:.45rem .6rem;border-left:3px solid <?= htmlspecialchars($dColor) ?>;">
                                                    <div style="font-size:.7rem;font-weight:700;color:#374151;margin-bottom:.3rem;">
                                                        <?= htmlspecialchars($deptData['dept_name']) ?>
                                                        <span
                                                            style="font-weight:400;color:#9ca3af;margin-left:.3rem;">(<?= $deptTotal ?>)</span>
                                                    </div>
                                                    <div style="display:flex;flex-wrap:wrap;gap:.2rem;margin-bottom:.3rem;">
                                                        <?php foreach ($deptData['statuses'] as $sn => $sv): ?>
                                                            <span style="background:<?= htmlspecialchars($sv['bg'] ?: '#f3f4f6') ?>;
                             color:<?= htmlspecialchars($sv['color'] ?: '#6b7280') ?>;
                             font-size:.65rem;font-weight:700;
                             padding:.1rem .4rem;border-radius:99px;">
                                                                <?= htmlspecialchars($sn) ?>: <?= $sv['cnt'] ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div style="background:#e5e7eb;border-radius:99px;height:4px;overflow:hidden;">
                                                        <div style="width:<?= $donePct ?>%;height:100%;border-radius:99px;
                             background:<?= htmlspecialchars($dColor) ?>;"></div>
                                                    </div>
                                                    <div style="font-size:.62rem;color:#9ca3af;text-align:right;margin-top:.1rem;">
                                                        <?= $donePct ?>% done
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
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

                                        <div class="d-flex gap-1">
                                            <a href="<?= APP_URL ?>/executive/staff/view.php?id=<?= $u['id'] ?>"
                                                class="btn btn-sm btn-outline-secondary" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (isCoreAdmin()): ?>
                                                <a href="<?= APP_URL ?>/executive/staff/edit.php?id=<?= $u['id'] ?>"
                                                    class="btn btn-sm btn-outline-warning" title="Edit">
                                                    <i class="fas fa-pen"></i>
                                                </a>
                                            </div>
                                        <?php endif; ?>
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