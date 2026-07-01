<?php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';
require_once '../config/helpers.php';
requireAnyRole();

$db   = getDB();
$user = currentUser();
$uid  = (int) $user['id'];
$role = $user['role_name'] ?? 'staff';

$taskId = (int) ($_GET['id'] ?? 0);
if (!$taskId) { header('Location: issue_list.php'); exit; }

$itDeptId = (int) $db->query("SELECT id FROM departments WHERE dept_code='IT' LIMIT 1")->fetchColumn();

// Is this user IT staff/manager (primary dept or UDA)?
$itAccessStmt = $db->prepare("
    SELECT 1 FROM users u
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    WHERE u.id = ? AND (u.department_id = ? OR uda.department_id = ?)
");
$itAccessStmt->execute([$uid, $itDeptId, $itDeptId]);
$isItStaff = (bool) $itAccessStmt->fetchColumn();

$isItManager = false;
if (function_exists('hasAdminDeptAccess')) {
    $isItManager = $isItStaff && (hasAdminDeptAccess($db, $uid) || in_array($role, ['admin','executive'], true));
}

// Fetch issue
$stmt = $db->prepare("
    SELECT t.*, ti.*, ts.status_name, ts.color, ts.bg_color,
           raiser.full_name AS raiser_name, raiser.id AS raiser_id,
           assignee.full_name AS assignee_name
    FROM tasks t
    JOIN task_it ti ON ti.task_id = t.id
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN users raiser ON raiser.id = ti.token_raiser_id
    LEFT JOIN users assignee ON assignee.id = ti.assigned_it_staff
    WHERE t.id = ?
");
$stmt->execute([$taskId]);
$issue = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$issue) { setFlash('error', 'Issue not found.'); header('Location: issue_list.php'); exit; }

// Permission: raiser, or IT staff/manager, or admin/executive can view
$canView = $isItStaff || (int)$issue['token_raiser_id'] === $uid || in_array($role, ['admin','executive'], true);
if (!$canView) { setFlash('error', 'You do not have access to this issue.'); header('Location: ../dashboard.php'); exit; }

$errors = [];

// Handle assignment (the "link" step) — IT staff/manager only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $isItStaff) {
    verifyCsrf();
    $action = $_POST['action'];

    if ($action === 'assign') {
        $assigneeId = (int) ($_POST['assigned_it_staff'] ?? 0);
        if ($assigneeId) {
            $db->prepare("UPDATE task_it SET assigned_it_staff = ?, assigned_at = NOW() WHERE task_id = ?")
               ->execute([$assigneeId, $taskId]);
            $db->prepare("UPDATE tasks SET assigned_to = ? WHERE id = ?")
               ->execute([$assigneeId, $taskId]);
            $db->prepare("INSERT INTO task_workflow (task_id, action, from_user_id, to_user_id, new_status) VALUES (?, 'assigned', ?, ?, 'In Progress')")
               ->execute([$taskId, $uid, $assigneeId]);

            $inProgId = (int) $db->query("SELECT id FROM task_status WHERE status_name='In Progress' LIMIT 1")->fetchColumn();
            if ($inProgId) {
                $db->prepare("UPDATE tasks SET status_id = ? WHERE id = ?")->execute([$inProgId, $taskId]);
            }

            if ($assigneeId !== $uid) {
                notify($assigneeId, 'IT Issue Assigned to You',
                    'You have been assigned issue ' . $issue['token_number'] . ': ' . $issue['title'],
                    'task', APP_URL . '/it/issue_view.php?id=' . $taskId, true, ['template' => 'it_issue']);
            }
            notify((int)$issue['token_raiser_id'], 'Your IT Issue Is Being Handled',
                $issue['token_number'] . ' has been assigned and is now in progress.',
                'task', APP_URL . '/it/issue_view.php?id=' . $taskId, true, ['template' => 'it_issue']);

            setFlash('success', 'Issue assigned.');
        }
    }

    if ($action === 'resolve') {
        $resolution = trim($_POST['resolution'] ?? '');
        if ($resolution) {
            $db->prepare("UPDATE task_it SET resolution = ?, resolution_date = NOW(), is_resolved = 1 WHERE task_id = ?")
               ->execute([$resolution, $taskId]);
            $doneId = (int) $db->query("SELECT id FROM task_status WHERE status_name='Done' LIMIT 1")->fetchColumn();
            if ($doneId) $db->prepare("UPDATE tasks SET status_id = ? WHERE id = ?")->execute([$doneId, $taskId]);
            $db->prepare("INSERT INTO task_workflow (task_id, action, from_user_id, new_status) VALUES (?, 'resolved', ?, 'Done')")
               ->execute([$taskId, $uid]);

            notify((int)$issue['token_raiser_id'], 'IT Issue Resolved',
                $issue['token_number'] . ' has been marked resolved.',
                'task', APP_URL . '/it/issue_view.php?id=' . $taskId, true, ['template' => 'it_issue']);

            setFlash('success', 'Issue marked resolved.');
        } else {
            $errors[] = 'Please describe the resolution.';
        }
    }

    if (!$errors) { header('Location: issue_view.php?id=' . $taskId); exit; }
}

// IT staff list for assignment dropdown
$itStaffList = [];
if ($isItStaff) {
    $itStaffList = $db->prepare("
        SELECT DISTINCT u.id, u.full_name FROM users u
        LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
        WHERE u.is_active = 1 AND (u.department_id = ? OR uda.department_id = ?)
        ORDER BY u.full_name
    ");
    $itStaffList->execute([$itDeptId, $itDeptId]);
    $itStaffList = $itStaffList->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Issue ' . $issue['token_number'];
include __DIR__ . '/../includes/header.php';

switch ($role) {
    case 'admin':      include __DIR__ . '/../includes/sidebar_admin.php'; break;
    case 'executive':  include __DIR__ . '/../includes/sidebar_executive.php'; break;
    case 'manager':    include __DIR__ . '/../includes/sidebar_manager.php'; break;
    default:           include __DIR__ . '/../includes/sidebar_staff.php'; break;
}
?>
<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>
    <div style="padding:1.5rem 0;">

        <div class="page-hero">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <div class="page-hero-badge"><i class="fas fa-headset"></i> IT Support</div>
                    <h4 class="mb-1"><?= htmlspecialchars($issue['token_number']) ?> — <?= htmlspecialchars($issue['title']) ?></h4>
                    <p class="mb-0" style="color:#6b7280;">
                        Raised by <?= htmlspecialchars($issue['raiser_name']) ?> on
                        <?= date('M j, Y · g:i A', strtotime($issue['created_at'])) ?>
                    </p>
                </div>
                <span class="badge" style="background:<?= htmlspecialchars($issue['bg_color'] ?? '#f3f4f6') ?>;
                      color:<?= htmlspecialchars($issue['color'] ?? '#6b7280') ?>;padding:.4rem .8rem;">
                    <?= htmlspecialchars($issue['status_name'] ?? 'Open') ?>
                </span>
            </div>
        </div>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card-mis mb-3">
                    <div class="card-mis-header"><h5>Issue Details</h5></div>
                    <div style="padding:1.2rem;">
                        <p><strong>Category:</strong> <?= htmlspecialchars($issue['issue_category']) ?></p>
                        <p><strong>Severity:</strong> <?= htmlspecialchars($issue['severity']) ?></p>
                        <p><strong>Description:</strong><br><?= nl2br(htmlspecialchars($issue['detailed_description'])) ?></p>
                        <?php if ($issue['assignee_name']): ?>
                            <p><strong>Assigned to:</strong> <?= htmlspecialchars($issue['assignee_name']) ?>
                               <?php if ($issue['assigned_at']): ?> on <?= date('M j, Y', strtotime($issue['assigned_at'])) ?><?php endif; ?></p>
                        <?php else: ?>
                            <p><strong>Assigned to:</strong> <span class="text-muted">Unassigned</span></p>
                        <?php endif; ?>
                        <?php if ($issue['is_resolved']): ?>
                            <hr>
                            <p><strong>Resolution:</strong><br><?= nl2br(htmlspecialchars($issue['resolution'])) ?></p>
                            <p class="text-muted small">Resolved on <?= date('M j, Y · g:i A', strtotime($issue['resolution_date'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($isItStaff && !$issue['is_resolved']): ?>
            <div class="col-lg-4">
                <div class="card-mis mb-3">
                    <div class="card-mis-header"><h5>Assign Issue</h5></div>
                    <div style="padding:1.2rem;">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="assign">
                            <select name="assigned_it_staff" class="form-select form-select-sm mb-2" required>
                                <option value="">Select IT staff...</option>
                                <?php foreach ($itStaffList as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= (int)$issue['assigned_it_staff'] === (int)$s['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-gold btn-sm w-100">Assign / Reassign</button>
                        </form>
                    </div>
                </div>

                <div class="card-mis">
                    <div class="card-mis-header"><h5>Mark Resolved</h5></div>
                    <div style="padding:1.2rem;">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="resolve">
                            <textarea name="resolution" class="form-control form-control-sm mb-2" rows="3"
                                placeholder="Describe the resolution..." required></textarea>
                            <button class="btn btn-success btn-sm w-100">Mark as Resolved</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>