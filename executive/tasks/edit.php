<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db   = getDB();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error', 'Invalid task.'); header('Location: index.php'); exit; }

// Fetch task with status via join
$taskStmt = $db->prepare("
    SELECT t.*,
           ts.status_name AS status,
           d.dept_name, d.dept_code
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN departments d  ON d.id  = t.department_id
    WHERE t.id = ? AND t.is_active = 1
");
$taskStmt->execute([$id]);
$task = $taskStmt->fetch();
if (!$task) { setFlash('error', 'Task not found.'); header('Location: index.php'); exit; }

$pageTitle = 'Edit Task: ' . $task['task_number'];

// Lookups
$companies   = $db->query("SELECT id, company_name FROM companies WHERE is_active=1 ORDER BY company_name")->fetchAll();

// All statuses from DB — this ensures ALL statuses show including Done
$allStatuses = $db->query("SELECT id, status_name FROM task_status ORDER BY id")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title      = trim($_POST['title']       ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $statusName = $_POST['status']           ?? '';
    $priority   = $_POST['priority']         ?? $task['priority'];
    $dueDate    = $_POST['due_date']         ?? null;
    $fy         = $_POST['fiscal_year']      ?? $task['fiscal_year'];
    $remarks    = trim($_POST['remarks']     ?? '');
    $companyId  = (int)($_POST['company_id'] ?? 0) ?: null;

    if (!$title)       $errors[] = 'Title is required.';
    if (!$statusName)  $errors[] = 'Status is required.';

    // Resolve status_id from status_name
    $statusId = null;
    if ($statusName) {
        $stRow = $db->prepare("SELECT id FROM task_status WHERE status_name = ?");
        $stRow->execute([$statusName]);
        $statusId = (int)($stRow->fetchColumn() ?: 0);
        if (!$statusId) $errors[] = 'Invalid status selected.';
    }

    if (!$errors) {
        // Update task — status_id not status
        $db->prepare("
            UPDATE tasks SET
                title       = ?,
                description = ?,
                status_id   = ?,
                priority    = ?,
                due_date    = ?,
                fiscal_year = ?,
                remarks     = ?,
                company_id  = ?,
                updated_at  = NOW()
            WHERE id = ?
        ")->execute([
            $title, $desc, $statusId, $priority,
            $dueDate ?: null, $fy, $remarks, $companyId, $id
        ]);

        // Log status change in workflow if changed
        if ($statusName !== $task['status']) {
            try {
                $db->prepare("
                    INSERT INTO task_workflow
                    (task_id, action, from_user_id, old_status, new_status, remarks)
                    VALUES (?, 'status_changed', ?, ?, ?, ?)
                ")->execute([
                    $id,
                    $user['id'],
                    $task['status'],
                    $statusName,
                    $remarks
                ]);
            } catch (Exception $e) {}

            // Notify assigned staff
            if ($task['assigned_to']) {
                notify(
                    $task['assigned_to'],
                    'Task Status Updated',
                    "Task {$task['task_number']} status changed to \"{$statusName}\" by {$user['full_name']}.",
                    'status',
                    APP_URL . '/staff/tasks/view.php?id=' . $id
                );
            }
        }

        logActivity("Edited task #{$id}", 'tasks');
        setFlash('success', 'Task updated successfully.');
        header('Location: view.php?id=' . $id); exit;
    }
}

// Merge for form repopulation
$f = array_merge($task, $_POST);

// Sidebar based on role
$sidebarRole = $user['role'] === 'executive' ? 'executive' : 'admin';

include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php include "../../includes/sidebar_{$sidebarRole}.php"; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<?= flashHtml() ?>

<div class="page-hero">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="page-hero-badge"><i class="fas fa-edit"></i> Edit Task</div>
            <h4><?= htmlspecialchars($task['task_number']) ?></h4>
            <p><?= htmlspecialchars($task['title']) ?></p>
        </div>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Back to View
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger rounded-3 mb-3">
    <strong>Please fix:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <div class="row g-4">

        <!-- Left -->
        <div class="col-lg-8">
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-edit text-warning me-2"></i>Edit Details</h5>
                </div>
                <div class="card-mis-body">
                    <div class="row g-3">

                        <!-- Title -->
                        <div class="col-12">
                            <label class="form-label-mis">Title <span class="required-star">*</span></label>
                            <input type="text" name="title" class="form-control"
                                   value="<?= htmlspecialchars($f['title'] ?? '') ?>" required>
                        </div>

                        <!-- Status — from DB, all statuses including Done -->
                        <div class="col-md-4">
                            <label class="form-label-mis">Status <span class="required-star">*</span></label>
                            <select name="status" class="form-select">
                                <?php foreach ($allStatuses as $ts):
                                    $statusColor = TASK_STATUSES[$ts['status_name']]['color'] ?? '#9ca3af';
                                    $isSelected  = ($f['status'] ?? $task['status']) === $ts['status_name'];
                                ?>
                                <option value="<?= htmlspecialchars($ts['status_name']) ?>"
                                        <?= $isSelected ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ts['status_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Priority -->
                        <div class="col-md-4">
                            <label class="form-label-mis">Priority</label>
                            <select name="priority" class="form-select">
                                <?php foreach (TASK_PRIORITIES as $k => $p): ?>
                                <option value="<?= $k ?>"
                                    <?= ($f['priority'] ?? '') === $k ? 'selected' : '' ?>>
                                    <?= $p['label'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Due Date -->
                        <div class="col-md-4">
                            <label class="form-label-mis">Due Date</label>
                            <input type="date" name="due_date" class="form-control"
                                   value="<?= htmlspecialchars($f['due_date'] ?? '') ?>">
                        </div>

                        <!-- Fiscal Year -->
                        <div class="col-md-4">
                            <label class="form-label-mis">Fiscal Year</label>
                            <select name="fiscal_year" class="form-select">
                                <?php foreach (['2080/81', '2081/82', '2082/83', '2083/84'] as $y): ?>
                                <option value="<?= $y ?>"
                                    <?= ($f['fiscal_year'] ?? '') === $y ? 'selected' : '' ?>>
                                    <?= $y ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Company -->
                        <div class="col-md-8">
                            <label class="form-label-mis">Company / Client</label>
                            <select name="company_id" class="form-select">
                                <option value="">-- None --</option>
                                <?php foreach ($companies as $c): ?>
                                <option value="<?= $c['id'] ?>"
                                    <?= ($f['company_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['company_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Description -->
                        <div class="col-12">
                            <label class="form-label-mis">Description</label>
                            <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($f['description'] ?? '') ?></textarea>
                        </div>

                        <!-- Remarks -->
                        <div class="col-12">
                            <label class="form-label-mis">Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2"><?= htmlspecialchars($f['remarks'] ?? '') ?></textarea>
                        </div>

                    </div>
                </div>
            </div>

            <div class="d-flex gap-3">
                <button type="submit" class="btn btn-gold">
                    <i class="fas fa-save me-2"></i>Save Changes
                </button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>

        <!-- Right sidebar -->
        <div class="col-lg-4">

            <!-- Task info -->
            <div class="card-mis mb-3 p-3" style="font-size:.82rem;color:#6b7280;">
                <div class="mb-2"><strong>Task #:</strong> <?= htmlspecialchars($task['task_number']) ?></div>
                <div class="mb-2"><strong>Department:</strong> <?= htmlspecialchars($task['dept_name'] ?? '—') ?></div>
                <div class="mb-2"><strong>Current Status:</strong>
                    <?php
                    $curColor = TASK_STATUSES[$task['status']]['color'] ?? '#9ca3af';
                    ?>
                    <span style="color:<?= $curColor ?>;font-weight:600;">
                        <?= htmlspecialchars($task['status']) ?>
                    </span>
                </div>
                <div style="color:#9ca3af;font-size:.78rem;margin-top:.5rem;">
                    Branch, Department, and Assigned Staff cannot be changed here.
                    Use Transfer to reassign.
                </div>
            </div>

            <!-- Status reference -->
            <div class="card-mis p-3" style="border-left:3px solid var(--gold);">
                <p class="mb-2" style="font-size:.8rem;font-weight:600;">Status Reference</p>
                <?php foreach ($allStatuses as $ts):
                    $color = TASK_STATUSES[$ts['status_name']]['color'] ?? '#9ca3af';
                ?>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <div style="width:8px;height:8px;border-radius:50%;background:<?= $color ?>;flex-shrink:0;"></div>
                    <span style="font-size:.78rem;color:#6b7280;"><?= htmlspecialchars($ts['status_name']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>
</form>

</div>
<?php include '../../includes/footer.php'; ?>