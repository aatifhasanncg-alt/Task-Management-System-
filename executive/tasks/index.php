<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$user = currentUser();
$pageTitle = 'All Tasks';

// Filters
$filterDept = $_GET['dept'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterBranch = (int) ($_GET['branch_id'] ?? 0);
$filterStaff = (int) ($_GET['staff_id'] ?? 0);
$filterCompany = (int) ($_GET['company_id'] ?? 0);
$filterOverdue = isset($_GET['overdue']) && $_GET['overdue'] == 1;
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$filterFY = trim($_GET['fy'] ?? '');
$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['t.is_active = 1'];
$params = [];

// dept_code not department_code
if ($filterDept) {
    $where[] = 'd.dept_code = ?';
    $params[] = strtoupper($filterDept);
}
// status via join
if ($filterStatus) {
    $where[] = 'ts.status_name = ?';
    $params[] = $filterStatus;
}
if ($filterBranch) {
    $where[] = 't.branch_id = ?';
    $params[] = $filterBranch;
}
if ($filterStaff) {
    $where[] = 't.assigned_to = ?';
    $params[] = $filterStaff;
}
if ($filterFY) {
    $where[] = 't.fiscal_year = ?';
    $params[] = $filterFY;
}
if ($filterCompany) {
    $where[] = 't.company_id = ?';
    $params[] = $filterCompany;
}
if ($filterOverdue) {
    $where[] = 't.due_date < CURDATE()';
    $where[] = 'ts.status_name != ?';
    $params[] = 'Done';
}
if ($filterDateFrom) {
    $where[] = 't.created_at >= ?';
    $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo) {
    $where[] = 't.created_at <= ?';
    $params[] = $filterDateTo . ' 23:59:59';
}
if ($search) {
    $where[] = '(t.title LIKE ? OR t.task_number LIKE ? OR c.company_name LIKE ? OR u.full_name LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}

$whereStr = implode(' AND ', $where);
$fiscalYears = $db->query("
    SELECT fy_code 
    FROM fiscal_years WHERE is_active=1
    ORDER BY fy_code DESC
")->fetchAll(PDO::FETCH_COLUMN);
// Count
$countSt = $db->prepare("
    SELECT COUNT(*) FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN departments d  ON d.id  = t.department_id
    LEFT JOIN companies c    ON c.id  = t.company_id
    LEFT JOIN users u        ON u.id  = t.assigned_to
    WHERE {$whereStr}
");
$countSt->execute($params);
$total = (int) $countSt->fetchColumn();
$pages = (int) ceil($total / $perPage);

// Task list — dept_name not department_name, status via join, assigned_by → created_by
$taskSt = $db->prepare("
    SELECT t.*,
           ts.status_name  AS status,
           d.dept_name, d.dept_code, d.color,
           b.branch_name,
           c.company_name,
           cb.full_name AS created_by_name,
           at.full_name AS assigned_to_name
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id  = t.status_id
    LEFT JOIN departments d  ON d.id   = t.department_id
    LEFT JOIN branches b     ON b.id   = t.branch_id
    LEFT JOIN companies c    ON c.id   = t.company_id
    LEFT JOIN users cb       ON cb.id  = t.created_by
    LEFT JOIN users at       ON at.id  = t.assigned_to
    LEFT JOIN users u        ON u.id   = t.assigned_to
    WHERE {$whereStr}
    ORDER BY t.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$taskSt->execute($params);
$tasks = $taskSt->fetchAll();
$statuses = $db->query("
    SELECT id, status_name, color, bg_color, icon
    FROM task_status
    ORDER BY id ASC
")->fetchAll();
// Status counts — via task_status join
$stmt = $db->query("
    SELECT ts.status_name, COUNT(t.id) as total
    FROM task_status ts
    LEFT JOIN tasks t 
        ON t.status_id = ts.id AND t.is_active = 1
    GROUP BY ts.status_name
");
$tabCounts = [];
foreach ($stmt->fetchAll() as $row) {
    $tabCounts[$row['status_name']] = $row['total'];
}

// Dropdowns — dept_name not department_name, dept_code not department_code
$allDepts = $db->query("
    SELECT id, dept_name, dept_code
    FROM departments
    WHERE is_active = 1 AND dept_name !='CORE ADMIN'
    ORDER BY dept_name
")->fetchAll();

$allBranches = $db->query("
    SELECT id, branch_name FROM branches
    WHERE is_active = 1
    ORDER BY branch_name
")->fetchAll();

// Staff — join roles not u.role
$allStaff = $db->query("
    SELECT u.id, u.full_name FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.role_name = 'staff' AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

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
                        <div class="page-hero-badge"><i class="fas fa-crown"></i> Executive View</div>
                        <h4>All Tasks <?= $filterDept ? '— ' . strtoupper($filterDept) : '' ?></h4>
                        <p><?= number_format($total) ?> tasks across all branches and departments</p>
                    </div>
                    <a href="assign.php" class="btn-gold btn">
                        <i class="fas fa-plus me-1"></i>Assign Task
                    </a>
                </div>
            </div>

            <!-- Status tabs -->
            <div class="d-flex gap-2 flex-wrap mb-3">
                <a href="index.php?<?= http_build_query(['search' => $search, 'dept' => $filterDept, 'branch_id' => $filterBranch]) ?>"
                    class="btn btn-sm <?= !$filterStatus ? 'btn-navy' : 'btn-outline-secondary' ?>">
                    All (<?= array_sum($tabCounts) ?>)
                </a>
                <?php foreach ($statuses as $s):
                    $k = $s['status_name'];
                    $color = $s['color'] ?? '#9ca3af';
                    $isActive = ($filterStatus === $k);
                    ?>
                    <a href="index.php?<?= http_build_query([
                        'status' => $k,
                        'dept' => $filterDept,
                        'branch_id' => $filterBranch,
                        'search' => $search
                    ]) ?>" class="btn btn-sm" style="
        border:1px solid <?= $color ?>;
        color:<?= $isActive ? '#fff' : $color ?>;
        background:<?= $isActive ? $color : 'transparent' ?>;
   ">

                        <i class="fas <?= $s['icon'] ?>"></i>
                        <?= htmlspecialchars($s['status_name']) ?>
                        (<?= $tabCounts[$k] ?? 0 ?>)

                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <!-- Context banner — outside the form -->
            <?php if ($filterCompany || $filterOverdue): ?>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;
                            padding:.6rem 1rem;margin-bottom:.75rem;font-size:.82rem;color:#1d4ed8;
                            display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                    <i class="fas fa-filter"></i>
                    <?php if ($filterCompany):
                        try {
                            $coStmt = $db->prepare("SELECT company_name FROM companies WHERE id=?");
                            $coStmt->execute([$filterCompany]);
                            $coName = $coStmt->fetchColumn() ?: "ID #{$filterCompany}";
                        } catch (Exception $e) {
                            $coName = "ID #{$filterCompany}";
                        }
                        ?>
                        Showing tasks for company: <strong><?= htmlspecialchars($coName) ?></strong>
                    <?php endif; ?>
                    <?php if ($filterOverdue): ?>
                        <span style="color:#dc2626;font-weight:600;">
                            <i class="fas fa-triangle-exclamation me-1"></i>Overdue tasks only
                        </span>
                    <?php endif; ?>
                    <a href="index.php" style="margin-left:auto;font-size:.75rem;color:#9ca3af;text-decoration:none;">
                        <i class="fas fa-times me-1"></i>Clear filters
                    </a>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filter-bar mb-4">
                <form method="GET" class="row g-2 align-items-end flex-nowrap">

                    <?php if ($filterStatus): ?>
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
                    <?php endif; ?>
                    <?php if ($filterCompany): ?>
                        <input type="hidden" name="company_id" value="<?= $filterCompany ?>">
                    <?php endif; ?>
                    <?php if ($filterOverdue): ?>
                        <input type="hidden" name="overdue" value="1">
                    <?php endif; ?>

                    <div class="col">
                        <label class="form-label-mis">Search</label>
                        <input type="text" name="search" class="form-control form-control-sm"
                            placeholder="Task #, title, company…" value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col">
                        <label class="form-label-mis">Department</label>
                        <select name="dept" class="form-select form-select-sm">
                            <option value="">All Depts</option>
                            <?php foreach ($allDepts as $d): ?>
                                <option value="<?= strtolower($d['dept_code']) ?>"
                                    <?= $filterDept === strtolower($d['dept_code']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['dept_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
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
                    <div class="col">
                        <label class="form-label-mis">FY</label>
                        <select name="fy" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach ($fiscalYears as $fy): ?>
                                <option value="<?= $fy ?>" <?= $filterFY === $fy ? 'selected' : '' ?>><?= $fy ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <label class="form-label-mis">Date From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm"
                            value="<?= htmlspecialchars($filterDateFrom) ?>">
                    </div>
                    <div class="col">
                        <label class="form-label-mis">Date To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm"
                            value="<?= htmlspecialchars($filterDateTo) ?>">
                    </div>
                    <div class="col-auto d-flex gap-1 align-items-end">
                        <button type="submit" class="btn btn-gold btn-sm">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>

                </form>
            </div>

            <!-- Task table -->
            <div class="card-mis">
                <div class="card-mis-header">
                    <h5><i class="fas fa-list-check text-warning me-2"></i>Tasks</h5>
                    <small class="text-muted"><?= $total ?> results</small>
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
                                <th>Priority</th>
                                <th>Due</th>
                                <th>FY</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tasks)): ?>
                                <tr>
                                    <td colspan="10" class="empty-state">
                                        <i class="fas fa-list-check"></i>No tasks found
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($tasks as $t):
                                $sClass = 'status-' . strtolower(str_replace(' ', '-', $t['status'] ?? ''));
                                $overdue = $t['due_date']
                                    && strtotime($t['due_date']) < time()
                                    && !in_array($t['status'], ['Done', 'Next Year']);
                                ?>
                                <tr <?= $overdue ? 'style="background:#fef8f8;"' : '' ?>>
                                    <td>
                                        <span class="task-number"><?= htmlspecialchars($t['task_number']) ?></span>
                                        <?php if ($overdue): ?>
                                            <div style="font-size:.62rem;color:#ef4444;font-weight:700;">⚠ OVERDUE</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div
                                            style="font-weight:500;font-size:.87rem;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                            <?= htmlspecialchars($t['title']) ?>
                                        </div>
                                        <?php if ($t['company_name']): ?>
                                            <div style="font-size:.73rem;color:#9ca3af;">
                                                <?= htmlspecialchars($t['company_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span
                                            style="font-size:.75rem;background:<?= htmlspecialchars($t['color'] ?? '#ccc') ?>22;color:<?= htmlspecialchars($t['color'] ?? '#666') ?>;padding:.2rem .55rem;border-radius:99px;border:1px solid <?= htmlspecialchars($t['color'] ?? '#ccc') ?>44;">
                                            <?= htmlspecialchars($t['dept_name'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($t['branch_name'] ?? '—') ?></td>
                                    <td>
                                        <?php if ($t['assigned_to_name']): ?>
                                            <div class="d-flex align-items-center gap-1">
                                                <div class="avatar-circle"
                                                    style="width:22px;height:22px;font-size:.62rem;flex-shrink:0;">
                                                    <?= strtoupper(substr($t['assigned_to_name'], 0, 2)) ?>
                                                </div>
                                                <span style="font-size:.82rem;">
                                                    <?= htmlspecialchars(explode(' ', $t['assigned_to_name'])[0]) ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;font-size:.8rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $sClass ?>">
                                            <?= htmlspecialchars($t['status'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-size:.78rem;font-weight:600;color:<?= [
                                            'urgent' => '#ef4444',
                                            'high' => '#f59e0b',
                                            'medium' => '#3b82f6',
                                            'low' => '#9ca3af',
                                        ][$t['priority']] ?? '#9ca3af' ?>;">
                                            <?= ucfirst($t['priority']) ?>
                                        </span>
                                    </td>
                                    <td style="font-size:.8rem;<?= $overdue ? 'color:#ef4444;font-weight:600;' : '' ?>">
                                        <?= $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—' ?>
                                    </td>
                                    <td style="font-size:.78rem;color:#9ca3af;">
                                        <?= htmlspecialchars($t['fiscal_year'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <a href="view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary"
                                                title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="<?= APP_URL ?>/executive/tasks/edit.php?id=<?= $t['id'] ?>"
                                                class="btn btn-sm btn-outline-warning" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                        </div>
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
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link"
                                            href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹</a>
                                    </li>
                                <?php endif; ?>
                                <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                                    <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                                        <a class="page-link"
                                            href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($page < $pages): ?>
                                    <li class="page-item">
                                        <a class="page-link"
                                            href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">›</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <?php include '../../includes/footer.php'; ?>