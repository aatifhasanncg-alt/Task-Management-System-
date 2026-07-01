<?php
// it/issue_list.php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';
require_once '../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];
$role = $user['role_name'] ?? 'staff';
$userBranchId = (int) ($user['branch_id'] ?? 0);
$itDeptId = (int) $db->query("SELECT id FROM departments WHERE dept_code='IT' LIMIT 1")->fetchColumn();

$itAccessStmt = $db->prepare("
    SELECT 1 FROM users u
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    WHERE u.id = ? AND (u.department_id = ? OR uda.department_id = ?)
");
$itAccessStmt->execute([$uid, $itDeptId, $itDeptId]);
$isItStaff = (bool) $itAccessStmt->fetchColumn();
$taskStatuses = $db->query("SELECT id, status_name FROM task_status ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$where = [];
$params = [];

// IT-dept / UDA-IT staff always see everything — overrides role-based scoping.
if (!$isItStaff) {
    if ($role === 'admin') {
        // Admins are scoped to their own branch, based on the assignee's branch.
        // Unassigned tickets remain visible so newly-raised issues aren't
        // hidden from admins before triage.
        $where[] = "(assignee.branch_id = ? OR ti.assigned_it_staff IS NULL)";
        $params[] = $userBranchId;
    } elseif ($role === 'staff') {
        // Staff see only issues assigned to them — not issues they raised.
        $where[] = "ti.assigned_it_staff = ?";
        $params[] = $uid;
    }
    // manager and executive: no filter, see all issues.
}

if ($search !== '') {
    $where[] = "(t.title LIKE ? OR ti.token_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status !== '') {
    $where[] = "ts.status_name = ?";
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$sql = "
    SELECT t.id, t.title, t.created_at, ts.status_name, ts.color, ts.bg_color,
           ti.token_number, ti.severity, ti.issue_category,
           raiser.full_name AS raiser_name, assignee.full_name AS assignee_name,
           d.dept_name, d.dept_code,
           b.branch_name
    FROM tasks t
    JOIN task_it ti ON ti.task_id = t.id
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN users raiser ON raiser.id = ti.token_raiser_id
    LEFT JOIN users assignee ON assignee.id = ti.assigned_it_staff
    LEFT JOIN departments d ON d.id = ti.department_id
    LEFT JOIN branches b ON b.id = ti.branch_id
    $whereSql
    ORDER BY t.created_at DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'IT Issues';
include __DIR__ . '/../includes/header.php';

switch ($role) {
    case 'admin':
        include __DIR__ . '/../includes/sidebar_admin.php';
        break;
    case 'executive':
        include __DIR__ . '/../includes/sidebar_executive.php';
        break;
    case 'manager':
        include __DIR__ . '/../includes/sidebar_manager.php';
        break;
    default:
        include __DIR__ . '/../includes/sidebar_staff.php';
        break;
}
?>
<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>
    <div style="padding:1.5rem 0;">
        <div class="page-hero">
            <div>
                <div class="page-hero-badge"><i class="fas fa-headset"></i> IT Support</div>
                <h4 class="mb-1">
                    <?= $isItStaff ? 'All IT Issues' : ($role === 'admin' ? 'Branch IT Issues' : 'My Assigned Issues') ?>
                </h4>
                <p class="mb-0" style="color:#6b7280;"><?= count($issues) ?> total</p>
            </div>
        </div>

        <form method="GET" class="filter-bar row g-2 align-items-end my-3">
            <div class="col-md-4">
                <label class="form-label-mis">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                    value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label-mis">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($taskStatuses as $ts): ?>
                        <option value="<?= htmlspecialchars($ts['status_name']) ?>" <?= $status === $ts['status_name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ts['status_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-gold btn-sm w-100">Filter</button>
            </div>
        </form>

        <div class="card-mis">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Token</th>
                        <th>Title</th>
                        <th>Department</th>
                        <th>Branch</th>
                        <th>Raised By</th>
                        <th>Category</th>
                        <th>Severity</th>
                        <th>Assignee</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($issues as $i): ?>
                        <tr>
                            <td><?= htmlspecialchars($i['token_number']) ?></td>
                            <td><?= htmlspecialchars($i['title']) ?></td>
                            <td><?= htmlspecialchars($i['dept_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($i['branch_name'] ?? 'N/A') ?></td>
                            <?php if ($isItStaff): ?>
                                <td><?= htmlspecialchars($i['raiser_name']) ?></td><?php endif; ?>
                            <td><?= htmlspecialchars($i['issue_category']) ?></td>
                            <td><?= htmlspecialchars($i['severity']) ?></td>
                            <td><?= htmlspecialchars($i['assignee_name'] ?? 'Unassigned') ?></td>
                            <td><span
                                    style="background:<?= htmlspecialchars($i['bg_color'] ?? '#f3f4f6') ?>;
                            color:<?= htmlspecialchars($i['color'] ?? '#6b7280') ?>;padding:.2rem .6rem;border-radius:6px;font-size:.75rem;">
                                    <?= htmlspecialchars($i['status_name'] ?? 'Open') ?></span></td>
                            <td><?= date('M j, Y', strtotime($i['created_at'])) ?></td>
                            <td><a href="../<?= $role ?>/tasks/view.php?id=<?= $i['id'] ?>"
                                    class="btn btn-sm btn-outline-secondary">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>