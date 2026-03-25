<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAdmin();

$db   = getDB();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// Fetch full admin profile
$adminStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$adminStmt->execute([$user['id']]);
$adminUser = $adminStmt->fetch();

// Fetch task with status via join
// Find the task fetch query and add this join (it may already be there):
$taskStmt = $db->prepare("
    SELECT t.*,
           ts.status_name AS status,
           d.dept_name, d.dept_code, d.color,
           b.branch_name,
           c.company_name,
           at.full_name AS assigned_to_name   -- ← add this
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN departments d  ON d.id  = t.department_id
    LEFT JOIN branches b     ON b.id  = t.branch_id
    LEFT JOIN companies c    ON c.id  = t.company_id
    LEFT JOIN users at       ON at.id = t.assigned_to   -- ← add this
    WHERE t.id = ? AND t.is_active = 1
");
$taskStmt->execute([$id]);
$task = $taskStmt->fetch();
if (!$task) { setFlash('error', 'Task not found.'); header('Location: index.php'); exit; }



// ── Load lookups ──
$taskStatuses = $db->query("SELECT id, status_name FROM task_status ORDER BY id")->fetchAll();
$companies    = $db->query("SELECT id, company_name FROM companies WHERE is_active=1 ORDER BY company_name")->fetchAll();

// All departments for transfer (exclude current)
$allDepts = $db->query("
    SELECT * FROM departments WHERE is_active=1 ORDER BY dept_name
")->fetchAll();

// Transfer staff — admins only, filtered by dept in JS
$transferStaff = $db->query("
    SELECT u.id, u.full_name, b.branch_name, d.dept_name, d.dept_code
    FROM users u
    LEFT JOIN branches b    ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN roles r       ON r.id = u.role_id
    WHERE r.role_name = 'admin'
    AND u.is_active = 1
    AND d.dept_code != 'CORE'
    ORDER BY u.full_name
")->fetchAll();

// Scoped staff for task assignment
$scopedStaff = $db->prepare("
    SELECT u.id, u.full_name, b.branch_name FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    LEFT JOIN roles r    ON r.id = u.role_id
    WHERE r.role_name = 'staff' AND u.is_active = 1
    AND u.branch_id = ? AND u.department_id = ?
    ORDER BY u.full_name
");
$scopedStaff->execute([$adminUser['branch_id'], $adminUser['department_id']]);
$scopedStaff = $scopedStaff->fetchAll();


$errors = [];

// ── POST: Update main task ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_task'])) {
    verifyCsrf();
    $title    = trim($_POST['title'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $dueDate  = $_POST['due_date'] ?: null;
    $fy       = trim($_POST['fiscal_year'] ?? '');
    $remarks  = trim($_POST['remarks'] ?? '');
    $assignTo = (int)($_POST['assigned_to'] ?? 0) ?: null;
    $compId   = (int)($_POST['company_id']  ?? 0) ?: null;
    $status   = $_POST['status'] ?? '';

    if (!$title) $errors[] = 'Task title is required.';

    if (!$errors) {
        $stRow = $db->prepare("SELECT id FROM task_status WHERE status_name = ?");
        $stRow->execute([$status]);
        $statusId = (int)($stRow->fetchColumn() ?: 1);

        $db->prepare("
            UPDATE tasks SET
                title=?, description=?, company_id=?,
                assigned_to=?, status_id=?, priority=?,
                due_date=?, fiscal_year=?, remarks=?,
                updated_at=NOW()
            WHERE id=?
        ")->execute([$title,$desc,$compId,$assignTo,$statusId,$priority,$dueDate,$fy,$remarks,$id]);

        logActivity("Task updated: {$task['task_number']}", 'tasks');
        setFlash('success', 'Task updated successfully.');
        header("Location: edit.php?id={$id}"); exit;
    }
}

// ── POST: Transfer department ── (works for ALL departments)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_dept'])) {
    verifyCsrf();
    $newDeptId    = (int)($_POST['new_dept_id']    ?? 0);
    $newAssignTo  = (int)($_POST['new_assigned_to']?? 0) ?: null;
    $transferNote = trim($_POST['transfer_note']   ?? '');

    if (!$newDeptId) {
        $errors[] = 'Please select a department to transfer to.';
    } else {
        $newDeptStmt = $db->prepare("SELECT dept_code, dept_name FROM departments WHERE id=?");
        $newDeptStmt->execute([$newDeptId]);
        $newDept = $newDeptStmt->fetch();

        $notStartedId = $db->query("SELECT id FROM task_status WHERE status_name='Not Started'")->fetchColumn();

        $db->prepare("
            UPDATE tasks SET
                department_id=?, current_dept_id=?,
                assigned_to=?, status_id=?, updated_at=NOW()
            WHERE id=?
        ")->execute([$newDeptId, $newDeptId, $newAssignTo, $notStartedId, $id]);

        try {
            $db->prepare("
                INSERT INTO task_workflow
                (task_id, action, from_user_id, to_user_id,
                 from_dept_id, to_dept_id, old_status, new_status, remarks)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $id, 'transferred_dept', $user['id'], $newAssignTo,
                $task['department_id'], $newDeptId,
                $task['status'], 'Not Started', $transferNote
            ]);
        } catch (Exception $e) {}

        if ($newAssignTo) {
            notify(
                $newAssignTo,
                'Task Transferred to You',
                "Task {$task['task_number']} has been transferred to {$newDept['dept_name']} and assigned to you." .
                ($transferNote ? "\n\nNote: {$transferNote}" : ''),
                'transfer',
                APP_URL . '/staff/tasks/view.php?id=' . $id,
                true,
                [
                    'template' => 'task_transferred',
                    'task'     => [
                        'id'         => $id,
                        'task_number'=> $task['task_number'],
                        'title'      => $task['title'],
                        'department' => $newDept['dept_name'],
                        'status'     => 'Not Started',
                        'due_date'   => $task['due_date'],
                    ],
                    'remarks' => $transferNote,
                ]
            );
        }

        logActivity("Task transferred: {$task['task_number']} → {$newDept['dept_name']}", 'tasks');
        setFlash('success', "Task transferred to {$newDept['dept_name']} successfully.");
        header("Location: view.php?id={$id}"); exit;
    }
}
$fiscalYears = $db->query("
    SELECT fy_code
    FROM fiscal_years 
    ORDER BY fy_code DESC
")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Edit Task: ' . $task['task_number'];
include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php include '../../includes/sidebar_admin.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<?= flashHtml() ?>

<!-- Back -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Back to View
    </a>
    <span class="task-number"><?= htmlspecialchars($task['task_number']) ?></span>
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

<div class="row g-4">

    <!-- ── LEFT COLUMN ── -->
   <!-- ── LEFT COLUMN ── -->
                    <div class="col-lg-8">

                        <!-- Main Task Details — the only editable section here -->
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-info-circle text-warning me-2"></i>Task Details</h5>
                            </div>
                            <div class="card-mis-body">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="update_task" value="1">
                                    <div class="row g-3">

                                        <div class="col-12">
                                            <label class="form-label-mis">Task Title <span class="required-star">*</span></label>
                                            <input type="text" name="title" class="form-control"
                                                value="<?= htmlspecialchars($_POST['title'] ?? $task['title']) ?>" required>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label-mis">Department</label>
                                            <input type="text" class="form-control"
                                                value="<?= htmlspecialchars($task['dept_name']) ?>"
                                                readonly style="background:#f9fafb;cursor:not-allowed;">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label-mis">Branch</label>
                                            <input type="text" class="form-control"
                                                value="<?= htmlspecialchars($task['branch_name']) ?>"
                                                readonly style="background:#f9fafb;cursor:not-allowed;">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label-mis">Company / Client</label>
                                            <select name="company_id" class="form-select">
                                                <option value="">-- None --</option>
                                                <?php foreach ($companies as $c): ?>
                                                    <option value="<?= $c['id'] ?>"
                                                        <?= ($task['company_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($c['company_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label-mis">Status</label>
                                            <select name="status" class="form-select">
                                                <?php foreach ($taskStatuses as $ts): ?>
                                                    <option value="<?= htmlspecialchars($ts['status_name']) ?>"
                                                        <?= ($task['status'] ?? '') === $ts['status_name'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($ts['status_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label-mis">Priority</label>
                                            <select name="priority" class="form-select">
                                                <?php foreach (TASK_PRIORITIES as $key => $p): ?>
                                                    <option value="<?= $key ?>"
                                                        <?= ($task['priority'] ?? '') === $key ? 'selected' : '' ?>>
                                                        <?= $p['label'] ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label-mis">Due Date</label>
                                            <input type="date" name="due_date" class="form-control"
                                                value="<?= htmlspecialchars($task['due_date'] ?? '') ?>">
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label-mis">Fiscal Year</label>
                                            <select name="fiscal_year" class="form-select">
                                                <option value="">-- Select FY --</option>
                                                <?php foreach ($fiscalYears as $fy): ?>
                                                    <option value="<?= $fy ?>"
                                                        <?= ($task['fiscal_year'] ?? '') === $fy ? 'selected' : '' ?>>
                                                        <?= $fy ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-8">
                                            <label class="form-label-mis">Assign To</label>
                                            <select name="assigned_to" class="form-select">
                                                <option value="">-- Unassigned --</option>
                                                <?php foreach ($scopedStaff as $s): ?>
                                                    <option value="<?= $s['id'] ?>"
                                                        <?= ($task['assigned_to'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($s['full_name']) ?>
                                                        — <?= htmlspecialchars($s['branch_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label-mis">Description</label>
                                            <textarea name="description" class="form-control"
                                                    rows="2"><?= htmlspecialchars($task['description'] ?? '') ?></textarea>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label-mis">Remarks</label>
                                            <textarea name="remarks" class="form-control"
                                                    rows="2"><?= htmlspecialchars($task['remarks'] ?? '') ?></textarea>
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" class="btn btn-gold btn-sm">
                                                <i class="fas fa-save me-1"></i>Update Task
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Dept detail info — links to view page -->
                        <div class="card-mis" style="border-left:3px solid <?= htmlspecialchars($task['color'] ?? '#c9a84c') ?>;">
                            <div class="card-mis-body d-flex align-items-center justify-content-between gap-3 py-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div style="width:40px;height:40px;border-radius:10px;background:<?= htmlspecialchars($task['color'] ?? '#c9a84c') ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <i class="fas fa-layer-group" style="color:<?= htmlspecialchars($task['color'] ?? '#c9a84c') ?>;"></i>
                                    </div>
                                    <div>
                                        <div style="font-size:.88rem;font-weight:600;color:#1f2937;">
                                            <?= htmlspecialchars($task['dept_name']) ?> Details
                                        </div>
                                        <div style="font-size:.75rem;color:#9ca3af;margin-top:.1rem;">
                                            Department-specific fields are managed from the task view page
                                        </div>
                                    </div>
                                </div>
                                <a href="view.php?id=<?= $id ?>"
                                class="btn btn-sm flex-shrink-0"
                                style="background:<?= htmlspecialchars($task['color'] ?? '#c9a84c') ?>;color:white;border-radius:6px;padding:.3rem .8rem;font-size:.78rem;white-space:nowrap;">
                                    <i class="fas fa-eye me-1"></i>View & Edit Details
                                </a>
                            </div>
                        </div>

                    </div><!-- end col-lg-8 -->

            <!-- ── RIGHT SIDEBAR ── always visible for ALL departments ── -->
            <div class="col-lg-4">
        <!-- Task Info -->
                <div class="card-mis mb-3 p-3" style="font-size:.82rem;color:#6b7280;">
                    <div class="mb-2"><strong>Task #:</strong> <?= htmlspecialchars($task['task_number']) ?></div>
                    <div class="mb-2"><strong>Department:</strong> <?= htmlspecialchars($task['dept_name']) ?></div>
                    <div class="mb-2"><strong>Branch:</strong> <?= htmlspecialchars($task['branch_name']) ?></div>
                    <div class="mb-2"><strong>Status:</strong>
                        <span class="status-badge status-<?= strtolower(str_replace(' ','-',$task['status'])) ?>">
                            <?= htmlspecialchars($task['status']) ?>
                        </span>
                    </div>
                    <div class="mb-2"><strong>Created:</strong> <?= date('d M Y', strtotime($task['created_at'])) ?></div>
                    <div><strong>Updated:</strong> <?= date('d M Y', strtotime($task['updated_at'])) ?></div>
                </div>

                <!-- ── TRANSFER TO ANOTHER DEPT — shown for ALL departments ── -->
                <div class="card-mis mb-3" style="border-left:3px solid #8b5cf6;">
                    <div class="card-mis-header">
                        <h5><i class="fas fa-exchange-alt me-2" style="color:#8b5cf6;"></i>Transfer to Department</h5>
                    </div>
                    <div class="card-mis-body">
                        <p style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">
                            Transfer this task to another department for further processing.
                        </p>
                        <form method="POST" onsubmit="return confirm('Are you sure you want to transfer this task?');">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="transfer_dept" value="1">
                            <div class="row g-2">

                                <div class="col-12">
                                    <label class="form-label-mis">Transfer To</label>
                                    <select name="new_dept_id" class="form-select form-select-sm"
                                            id="transferDept" required onchange="filterTransferStaff()">
                                        <option value="">-- Select Department --</option>
                                        <?php foreach ($allDepts as $d): ?>
                                            <?php if ($d['id'] == $task['department_id']) continue; ?>
                                            <?php if ($d['dept_code'] === 'CORE') continue; ?>
                                            <option value="<?= $d['id'] ?>"
                                                    data-code="<?= htmlspecialchars($d['dept_code']) ?>"
                                                    data-name="<?= htmlspecialchars($d['dept_name']) ?>">
                                                <?= htmlspecialchars($d['dept_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label-mis">
                                        Assign To Admin
                                        <span style="font-size:.7rem;color:#9ca3af;" id="staffFilterNote">(select dept first)</span>
                                    </label>
                                    <select name="new_assigned_to" class="form-select form-select-sm" id="transferStaff">
                                        <option value="">-- Unassigned --</option>
                                        <?php foreach ($transferStaff as $s): ?>
                                            <option value="<?= $s['id'] ?>"
                                                    data-deptcode="<?= htmlspecialchars($s['dept_code']) ?>">
                                                <?= htmlspecialchars($s['full_name']) ?>
                                                — <?= htmlspecialchars($s['branch_name']) ?>
                                                (<?= htmlspecialchars($s['dept_name']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label-mis">Transfer Note</label>
                                    <textarea name="transfer_note" class="form-control form-control-sm" rows="2"
                                            placeholder="Reason / instructions for next dept…"></textarea>
                                </div>

                                <div class="col-12 mt-1">
                                    <button type="submit" class="btn w-100 btn-sm"
                                            style="background:#8b5cf6;color:#fff;border:none;border-radius:8px;padding:.5rem;">
                                        <i class="fas fa-exchange-alt me-1"></i>Transfer Task
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

        <!-- Status Reference -->
        <div class="card-mis p-3" style="border-left:3px solid var(--gold);">
            <p class="mb-2" style="font-size:.8rem;font-weight:600;">Status Reference</p>
            <?php foreach (TASK_STATUSES as $k => $s): ?>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <div style="width:8px;height:8px;border-radius:50%;background:<?= $s['color'] ?>;flex-shrink:0;"></div>
                    <span style="font-size:.78rem;color:#6b7280;"><?= $s['label'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>

    </div><!-- end col-lg-4 -->

</div><!-- end row -->
</div>

<script>
function loadAuditors() {
    const nature = document.getElementById('audit_nature').value;

    if (!nature) return;

    fetch(`${APP_URL}/ajax/get_auditors.php?nature=${nature}`)
        .then(res => res.json())
        .then(data => {
            const select = document.getElementById('auditor_id');

            select.innerHTML = '<option value="">-- Select Auditor --</option>';

            data.forEach(a => {
                let count = nature === 'countable'
                    ? a.countable_count
                    : a.uncountable_count;

                select.innerHTML += `
                    <option value="${a.id}">
                        ${a.auditor_name} (${count})
                    </option>
                `;
            });
        })
        .catch(err => console.error("Error loading auditors:", err));
}
</script>

<?php include '../../includes/footer.php'; ?>