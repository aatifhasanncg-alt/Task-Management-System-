<?php
/**
 * it/issue_create.php — Any user can raise an IT issue
 */
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';
require_once '../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];
$role = $user['role_name'] ?? 'staff';

// Resolve IT department id
$itDeptStmt = $db->query("SELECT id FROM departments WHERE dept_code = 'IT' LIMIT 1");
$itDeptId = (int) $itDeptStmt->fetchColumn();

// Moved OUTSIDE the POST block so it's always available for the dropdown
$validCategories = [
    'Task System',
    'Computer or Laptop Issue',
    'Printer Issue',
    'Other Software Issue',
    'Client Software Issue',
    'Network/Internet Issue',
    'Email Issue',
    'Hardware Issue',
    'Other',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title = trim($_POST['title'] ?? '');
    $category = $_POST['issue_category'] ?? '';
    $description = trim($_POST['detailed_description'] ?? '');
    $severity = $_POST['severity'] ?? 'Medium';

    if (!$title)
        $errors[] = 'Please give the issue a short title.';
    if (!in_array($category, $validCategories, true))
        $errors[] = 'Please select a valid issue category.';
    if (!$description)
        $errors[] = 'Please describe the issue.';
    if (!in_array($severity, ['Low', 'Medium', 'High', 'Critical'], true))
        $severity = 'Medium';

if (!$errors) {
        $db->beginTransaction();
        try {
            $openStatusId = (int) $db->query("SELECT id FROM task_status WHERE status_name='Not Started' LIMIT 1")->fetchColumn();

            $insTask = $db->prepare("
                INSERT INTO tasks
                (title, description, department_id, branch_id, created_by, status_id, priority)
                VALUES (?,?,?,?,?,?,?)
            ");
            $priorityMap = ['Low' => 'low', 'Medium' => 'medium', 'High' => 'high', 'Critical' => 'urgent'];
            $insTask->execute([
                $title,
                $description,
                $itDeptId,
                !empty($user['branch_id']) ? (int)$user['branch_id'] : 1,
                $uid,
                $openStatusId,
                $priorityMap[$severity] ?? 'medium',
            ]);
            $taskId = (int) $db->lastInsertId();

            // Row-level lock instead of LOCK TABLES — safe inside a transaction
            $lastToken = $db->query("
                SELECT token_number FROM task_it
                WHERE token_number LIKE 'IT-%'
                ORDER BY CAST(SUBSTRING(token_number, 4) AS UNSIGNED) DESC
                LIMIT 1
                FOR UPDATE
            ")->fetchColumn();

            $nextSeq = $lastToken ? ((int) substr($lastToken, 3)) + 1 : 1;
            $tokenNumber = 'IT-' . str_pad((string) $nextSeq, 6, '0', STR_PAD_LEFT);

            $insIt = $db->prepare("
                INSERT INTO task_it
                (task_id, token_number, token_raiser_id, department_id, branch_id,
                issue_category, detailed_description, severity, is_resolved)
                VALUES (?,?,?,?,?,?,?,?,0)
            ");
            $insIt->execute([
                $taskId,
                $tokenNumber,
                $uid,
                !empty($user['department_id']) ? (int)$user['department_id'] : 1,
                !empty($user['branch_id']) ? (int)$user['branch_id'] : 1,
                $category,
                $description,
                $severity,
            ]);
            $itId = (int) $db->lastInsertId();


            $db->prepare("
                INSERT INTO task_workflow (task_id, action, from_user_id, new_status)
                VALUES (?, 'created', ?, 'Open')
            ")->execute([$taskId, $uid]);

            // 4. Notify everyone in IT dept (primary or UDA) + IT department managers
            // 4. Notify everyone in IT dept (primary or UDA) + IT department managers
            $itStaffStmt = $db->prepare("
                SELECT DISTINCT u.id, r.role_name AS role
                FROM users u
                JOIN roles r ON r.id = u.role_id
                LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
                WHERE u.is_active = 1
                AND (u.department_id = ? OR uda.department_id = ?)
            ");
            $itStaffStmt->execute([$itDeptId, $itDeptId]);
            $notifyRows = $itStaffStmt->fetchAll(PDO::FETCH_ASSOC);

            // Managers/admins who have been granted access to the IT department
            $mgrStmt = $db->prepare("
                SELECT DISTINCT u.id, r.role_name AS role
                FROM admin_department_access ada
                JOIN users u ON u.id = ada.admin_id
                JOIN roles r ON r.id = u.role_id
                WHERE ada.department_id = ?
                AND u.is_active = 1
                AND r.role_name IN ('manager', 'admin')
            ");
            $mgrStmt->execute([$itDeptId]);
            $mgrRows = $mgrStmt->fetchAll(PDO::FETCH_ASSOC);

            // Merge & dedupe by user id, keeping first-seen role
            $notifyMap = [];
            foreach (array_merge($notifyRows, $mgrRows) as $row) {
                $notifyMap[(int)$row['id']] = $row['role'];
            }

            foreach ($notifyMap as $nid => $nrole) {
                if ($nid === $uid)
                    continue;
                notify(
                    $nid,
                    'New IT Issue Raised',
                    $user['full_name'] . ' raised an IT issue: ' . $title . ' (' . $severity . ')',
                    'task',
                    APP_URL . '/' . $nrole . '/tasks/view.php?id=' . $taskId,
                    true,
                    ['template' => 'it_issue']
                );
            }

            $db->commit();
            logActivity('Raised IT issue #' . $taskId, 'it_support');
            setFlash('success', 'Issue raised successfully! IT support has been notified.');
            header('Location: ' . APP_URL . 'includes/issue_view.php?id=' . $taskId);
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to raise issue: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Raise IT Issue';
include '../includes/header.php';

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
            <div class="page-hero-badge"><i class="fas fa-headset"></i> IT Support</div>
            <h4 class="mb-1">Raise an IT Issue</h4>
            <p class="mb-0" style="color:#6b7280;">
                Describe the problem you're facing and IT support will be notified immediately.
            </p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger mt-3">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card-mis mt-3">
            <div class="card-mis-header">
                <h5><i class="fas fa-triangle-exclamation text-warning me-2"></i>Issue Details</h5>
            </div>

            <div style="padding:1.5rem;">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                    <div class="row g-3">

                        <div class="col-md-12">
                            <label class="form-label-mis">Issue Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control form-control-sm"
                                placeholder="e.g. Printer not responding on 2nd floor"
                                value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">Issue Category <span class="text-danger">*</span></label>
                            <select name="issue_category" class="form-select form-select-sm" required>
                                <option value="" disabled <?= empty($_POST['issue_category']) ? 'selected' : '' ?>>
                                    Select category...
                                </option>
                                <?php foreach ($validCategories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>"
                                        <?= (($_POST['issue_category'] ?? '') === $cat) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">Severity</label>
                            <select name="severity" class="form-select form-select-sm">
                                <?php foreach (['Low', 'Medium', 'High', 'Critical'] as $sev): ?>
                                    <option value="<?= $sev ?>"
                                        <?= (($_POST['severity'] ?? 'Medium') === $sev) ? 'selected' : '' ?>>
                                        <?= $sev ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Critical = work completely blocked</small>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label-mis">Detailed Description <span class="text-danger">*</span></label>
                            <textarea name="detailed_description" rows="5" class="form-control form-control-sm"
                                placeholder="What happened? When did it start? Any error messages?"
                                required><?= htmlspecialchars($_POST['detailed_description'] ?? '') ?></textarea>
                        </div>

                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="issue_list.php" class="btn btn-outline-secondary btn-sm">Cancel</a>
                        <button type="submit" class="btn btn-gold btn-sm">
                            <i class="fas fa-paper-plane me-1"></i> Submit Issue
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>