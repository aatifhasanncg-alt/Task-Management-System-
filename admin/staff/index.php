<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAdmin();

$db   = getDB();
$user = currentUser();

// Fetch full admin profile for scoping
$adminStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$adminStmt->execute([$user['id']]);
$adminUser = $adminStmt->fetch();

$pageTitle = 'My Staff';

// Filters
$search    = trim($_GET['search']    ?? '');
$filterD   = (int)($_GET['dept_id']  ?? 0);
$filterB   = (int)($_GET['branch_id']?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 20;
$offset    = ($page - 1) * $perPage;

// Base WHERE — admin sees only their branch & dept staff
$where  = ['r.role_name = \'staff\'', 'u.is_active = 1'];
$params = [];

if (!isExecutive()) {
    $where[]  = 'u.branch_id = ?';
    $params[] = $adminUser['branch_id'];
    $where[]  = 'u.department_id = ?';
    $params[] = $adminUser['department_id'];
}

if ($search) {
    $where[]  = '(u.full_name LIKE ? OR u.email LIKE ? OR u.employee_id LIKE ? OR u.phone LIKE ?)';
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($filterD) { $where[] = 'u.department_id = ?'; $params[] = $filterD; }
if ($filterB) { $where[] = 'u.branch_id = ?';     $params[] = $filterB; }

$ws = implode(' AND ', $where);

// Total count
$countSt = $db->prepare("
    SELECT COUNT(*) FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE {$ws}
");
$countSt->execute($params);
$total = (int)$countSt->fetchColumn();
$pages = (int)ceil($total / $perPage);

// Staff list with task counts
$staffStmt = $db->prepare("
    SELECT u.*,
           r.role_name,
           d.dept_name,
           b.branch_name,
           m.full_name AS managed_by_name,
           COUNT(DISTINCT t.id) AS total_tasks,
           SUM(CASE WHEN ts.status_name NOT IN ('Done','Next Year') THEN 1 ELSE 0 END) AS open_tasks,
           SUM(CASE WHEN ts.status_name = 'Done' THEN 1 ELSE 0 END) AS done_tasks
    FROM users u
    LEFT JOIN roles r       ON r.id  = u.role_id
    LEFT JOIN departments d ON d.id  = u.department_id
    LEFT JOIN branches b    ON b.id  = u.branch_id
    LEFT JOIN users m       ON m.id  = u.managed_by
    LEFT JOIN tasks t       ON t.assigned_to = u.id AND t.is_active = 1
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE {$ws}
    GROUP BY u.id
    ORDER BY u.full_name ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$staffStmt->execute($params);
$staffList = $staffStmt->fetchAll();

// Dropdowns for filter
$allDepts   = $db->query("SELECT id, dept_name FROM departments WHERE is_active=1 ORDER BY dept_name")->fetchAll();
$allBranches= $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();

include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php include '../../includes/sidebar_admin.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>

<div style="padding:1.5rem 0;">

<?= flashHtml() ?>

<div class="page-hero">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="page-hero-badge"><i class="fas fa-users"></i> Staff</div>
            <h4>My Staff</h4>
            <p><?= number_format($total) ?> staff members found</p>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="filter-bar mb-4 w-100">
    <form method="GET" class="row g-2 align-items-end w-100">
        <div class="col-md-4">
            <label class="form-label-mis">Search</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="Name, email, employee ID, phone..."
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        <?php if (isExecutive()): ?>
        <div class="col-md-3">
            <label class="form-label-mis">Department</label>
            <select name="dept_id" class="form-select form-select-sm">
                <option value="">All Departments</option>
                <?php foreach ($allDepts as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $filterD == $d['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['dept_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
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
        <?php endif; ?>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-gold btn-sm w-100"><i class="fas fa-filter"></i> Filter</button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
        </div>
    </form>
</div>

<!-- Staff Cards -->
<?php if (!empty($staffList)): ?>
<div class="row g-3 mb-4">
    <?php foreach ($staffList as $s): ?>
    <div class="col-md-4 col-lg-3">
        <div class="card-mis h-100" style="padding:0;">
            <div style="padding:1.25rem;">
                <!-- Avatar + Name -->
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="avatar-circle avatar-sm flex-shrink-0" style="width:44px;height:44px;font-size:.9rem;">
                        <?php
                        $parts    = explode(' ', $s['full_name']);
                        $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                        echo $initials;
                        ?>
                    </div>
                    <div style="min-width:0;">
                        <div style="font-size:.88rem;font-weight:600;color:#1f2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?= htmlspecialchars($s['full_name']) ?>
                        </div>
                        <div style="font-size:.72rem;color:#9ca3af;">
                            <?= htmlspecialchars($s['employee_id'] ?? '') ?>
                        </div>
                    </div>
                </div>

                <!-- Info -->
                <div style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">
                    <div class="mb-1">
                        <i class="fas fa-building me-1" style="width:14px;color:#c9a84c;"></i>
                        <?= htmlspecialchars($s['dept_name'] ?? '—') ?>
                    </div>
                    <div class="mb-1">
                        <i class="fas fa-code-branch me-1" style="width:14px;color:#c9a84c;"></i>
                        <?= htmlspecialchars($s['branch_name'] ?? '—') ?>
                    </div>
                    <?php if ($s['email']): ?>
                    <div class="mb-1">
                        <i class="fas fa-envelope me-1" style="width:14px;color:#c9a84c;"></i>
                        <span style="font-size:.72rem;"><?= htmlspecialchars($s['email']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($s['phone']): ?>
                    <div>
                        <i class="fas fa-phone me-1" style="width:14px;color:#c9a84c;"></i>
                        <?= htmlspecialchars($s['phone']) ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Task stats -->
                <div style="background:#f9fafb;border-radius:8px;padding:.6rem .75rem;display:flex;gap:.75rem;">
                    <div style="text-align:center;flex:1;">
                        <div style="font-size:1.1rem;font-weight:700;color:#f59e0b;"><?= $s['open_tasks'] ?></div>
                        <div style="font-size:.65rem;color:#9ca3af;">Open</div>
                    </div>
                    <div style="width:1px;background:#e5e7eb;"></div>
                    <div style="text-align:center;flex:1;">
                        <div style="font-size:1.1rem;font-weight:700;color:#10b981;"><?= $s['done_tasks'] ?></div>
                        <div style="font-size:.65rem;color:#9ca3af;">Done</div>
                    </div>
                    <div style="width:1px;background:#e5e7eb;"></div>
                    <div style="text-align:center;flex:1;">
                        <div style="font-size:1.1rem;font-weight:700;color:#3b82f6;"><?= $s['total_tasks'] ?></div>
                        <div style="font-size:.65rem;color:#9ca3af;">Total</div>
                    </div>
                </div>
            </div>

            <!-- Footer actions -->
            <div style="border-top:1px solid #f3f4f6;padding:.65rem 1.25rem;display:flex;gap:.5rem;">
                <a href="<?= APP_URL ?>/admin/tasks/index.php?staff_id=<?= $s['id'] ?>"
                   class="btn btn-sm btn-outline-secondary flex-grow-1" style="font-size:.75rem;">
                    <i class="fas fa-list-check me-1"></i>Tasks
                </a>
                <a href="view.php?id=<?= $s['id'] ?>"
                   class="btn btn-sm btn-gold flex-grow-1" style="font-size:.75rem;">
                    <i class="fas fa-eye me-1"></i>View
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php else: ?>
<div class="card-mis">
    <div class="card-mis-body text-center py-5" style="color:#9ca3af;">
        <i class="fas fa-users fa-2x mb-2 d-block"></i>
        <p style="font-size:.9rem;margin:0;">No staff found.</p>
    </div>
</div>
<?php endif; ?>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="d-flex justify-content-between align-items-center mt-3">
    <small class="text-muted">
        Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?>
    </small>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>">
                        <?= $p ?>
                    </a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
</div>
<?php endif; ?>

</div>
<?php include '../../includes/footer.php'; ?>