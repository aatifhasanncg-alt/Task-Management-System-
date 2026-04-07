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

// Fetch task
$taskStmt = $db->prepare("
    SELECT t.*,
           ts.status_name AS status,
           d.dept_name, d.dept_code, d.color,
           b.branch_name,
           c.company_name,
           at2.full_name AS assigned_to_name,
           au.auditor_name
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN departments d  ON d.id  = t.department_id
    LEFT JOIN branches b     ON b.id  = t.branch_id
    LEFT JOIN companies c    ON c.id  = t.company_id
    LEFT JOIN users at2      ON at2.id = t.assigned_to
    LEFT JOIN auditors au    ON au.id  = t.auditor_id
    WHERE t.id = ? AND t.is_active = 1
");
$taskStmt->execute([$id]);
$task = $taskStmt->fetch();
if (!$task) { setFlash('error', 'Task not found.'); header('Location: index.php'); exit; }

// ── Load lookups ──────────────────────────────────────────────────────────────
$taskStatuses = $db->query("SELECT id, status_name FROM task_status ORDER BY id")->fetchAll();
$companiesStmt = $db->prepare("
    SELECT id, company_name,
           COALESCE(pan_number,'')   AS pan_number,
           COALESCE(company_code,'') AS company_code
    FROM companies
    WHERE is_active=1 AND branch_id = ?
    ORDER BY company_name
");
$companiesStmt->execute([$adminUser['branch_id']]);
$companies = $companiesStmt->fetchAll();


$allDepts = $db->query("SELECT * FROM departments WHERE is_active=1 AND dept_name != 'CORE ADMIN' ORDER BY dept_name")->fetchAll();

// Transfer staff — admins only (exclude CORE), filtered by dept in JS
$transferStaff = $db->query("
    SELECT u.id, u.full_name, u.employee_id, b.branch_name, d.dept_name, d.dept_code
    FROM users u
    LEFT JOIN branches b    ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN roles r       ON r.id = u.role_id
    WHERE r.role_name = 'admin'
      AND u.is_active = 1
      AND d.dept_code != 'CORE'
    ORDER BY u.full_name
")->fetchAll();

// All STAFF in this task's department (any branch) for assignment
$deptStaff = $db->prepare("
    SELECT u.id, u.full_name, u.employee_id, b.branch_name
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    LEFT JOIN roles r    ON r.id = u.role_id
    WHERE r.role_name = 'staff'
      AND u.is_active  = 1
      AND u.department_id = ?
    ORDER BY u.full_name
");
$deptStaff->execute([$task['department_id']]);
$deptStaff = $deptStaff->fetchAll();

// Fiscal years
$fiscalYears = $db->query("
    SELECT fy_code, fy_label, is_current
    FROM fiscal_years WHERE is_active=1 ORDER BY fy_code DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Auditors — loaded fresh for current audit_nature (also via AJAX on change)
$currentAuditors = [];
if ($task['audit_nature']) {
    $fyId = $db->query("SELECT id FROM fiscal_years WHERE is_current=1 LIMIT 1")->fetchColumn();
    $audStmt = $db->prepare("
        SELECT a.id, a.auditor_name,
               COALESCE(q.countable_count, 0)                      AS countable_count,
               COALESCE(q.uncountable_count, 0)                    AS uncountable_count,
               COALESCE(q.max_countable_override, a.max_countable) AS max_limit
        FROM auditors a
        LEFT JOIN auditor_yearly_quota q
               ON q.auditor_id = a.id AND q.fiscal_year_id = ?
        WHERE a.is_active = 1
        ORDER BY a.auditor_name
    ");
    $audStmt->execute([$fyId ?: 0]);
    $currentAuditors = $audStmt->fetchAll();
}

$errors = [];

// ── POST: Update main task ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_task'])) {
    verifyCsrf();

    $title       = trim($_POST['title']       ?? '');
    $desc        = trim($_POST['description'] ?? '');
    $priority    = $_POST['priority']         ?? 'medium';
    $dueDate     = $_POST['due_date']         ?: null;
    $fy          = trim($_POST['fiscal_year'] ?? '');
    $remarks     = trim($_POST['remarks']     ?? '');
    $assignTo    = (int)($_POST['assigned_to']  ?? 0) ?: null;
    $compId      = (int)($_POST['company_id']   ?? 0) ?: null;
    $status      = $_POST['status']            ?? '';
    $raw = trim($_POST['audit_nature'] ?? '');
    $auditNature = $raw !== '' ? (strtolower($raw) === 'n/a' ? 'N/A' : strtolower($raw)) : null;
    $auditorId   = (int)($_POST['auditor_id']  ?? 0) ?: null;

    $oldAuditorId   = (int)($task['auditor_id']   ?? 0);
    $oldAuditNature = strtolower($task['audit_nature'] ?? '');
    $auditorChanged = $auditorId  !== $oldAuditorId;
    $natureChanged  = $auditNature !== $oldAuditNature;

    if (!$title) $errors[] = 'Task title is required.';

    // Cap check — separate from brace nesting
    if (!$errors && $auditNature === 'countable' && ($auditorChanged || $natureChanged) && $auditorId) {
        $fyId = $db->query("SELECT id FROM fiscal_years WHERE is_current=1 LIMIT 1")->fetchColumn();
        $capStmt = $db->prepare("
            SELECT a.auditor_name,
                   COALESCE(q.max_countable_override, a.max_countable) AS cap,
                   COALESCE(q.countable_count, 0) AS used
            FROM auditors a
            LEFT JOIN auditor_yearly_quota q
                   ON q.auditor_id = a.id AND q.fiscal_year_id = ?
            WHERE a.id = ?
        ");
        $capStmt->execute([$fyId ?: 0, $auditorId]);
        $capData = $capStmt->fetch();
        if ($capData && (int)$capData['used'] >= (int)$capData['cap']) {
            $errors[] = "Auditor \"{$capData['auditor_name']}\" has reached their countable limit ({$capData['cap']}) for this fiscal year.";
        }
    }

    if (!$errors) {
        $stRow = $db->prepare("SELECT id FROM task_status WHERE status_name = ?");
        $stRow->execute([$status]);
        $statusId = (int)($stRow->fetchColumn() ?: 1);

        $db->prepare("
            UPDATE tasks SET
                title        = ?, description  = ?, company_id   = ?,
                assigned_to  = ?, status_id    = ?, priority     = ?,
                due_date     = ?, fiscal_year  = ?, remarks      = ?,
                audit_nature = ?, auditor_id   = ?, updated_at   = NOW()
            WHERE id = ?
        ")->execute([
            $title, $desc, $compId, $assignTo, $statusId,
            $priority, $dueDate, $fy, $remarks,
            $auditNature ?: null, $auditorId, $id,
        ]);

        $fyId = $db->query("SELECT id FROM fiscal_years WHERE is_current=1 LIMIT 1")->fetchColumn();

        if ($fyId && ($auditorChanged || $natureChanged)) {
            if ($oldAuditorId && $oldAuditNature) {
                $col = $oldAuditNature === 'countable' ? 'countable_count' : 'uncountable_count';
                $db->prepare("
                    INSERT INTO auditor_yearly_quota
                        (auditor_id, fiscal_year_id, countable_count, uncountable_count)
                    VALUES (?, ?, 0, 0)
                    ON DUPLICATE KEY UPDATE {$col} = GREATEST(0, {$col} - 1)
                ")->execute([$oldAuditorId, $fyId]);
            }
            if ($auditorId && $auditNature) {
                $col = $auditNature === 'countable' ? 'countable_count' : 'uncountable_count';
                $db->prepare("
                    INSERT INTO auditor_yearly_quota
                        (auditor_id, fiscal_year_id, countable_count, uncountable_count)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE {$col} = {$col} + 1
                ")->execute([
                    $auditorId, $fyId,
                    $auditNature === 'countable'   ? 1 : 0,
                    $auditNature === 'uncountable' ? 1 : 0,
                ]);
            }
        }

        if ($assignTo && $assignTo != $task['assigned_to']) {
            try {
                $db->prepare("
                    INSERT INTO task_workflow
                    (task_id, action, from_user_id, to_user_id,
                     from_dept_id, to_dept_id, old_status, new_status, remarks)
                    VALUES (?, 'assigned', ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $id, $user['id'], $assignTo,
                    $task['department_id'], $task['department_id'],
                    $task['status'], $status, $remarks
                ]);
            } catch (Exception $e) {}

            notify($assignTo, 'Task Assigned to You',
                "Task {$task['task_number']} has been assigned to you.",
                'task', APP_URL . '/admin/tasks/view.php?id=' . $id);
        }

        logActivity("Task updated: {$task['task_number']}", 'tasks');
        setFlash('success', 'Task updated successfully.');
        header("Location: edit.php?id={$id}"); exit;
    }
}
// ── POST: Transfer department ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_dept'])) {
    verifyCsrf();
    $newDeptId    = (int)($_POST['new_dept_id']    ?? 0);
    $newAssignTo  = (int)($_POST['new_assigned_to'] ?? 0) ?: null;
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
                department_id   = ?,
                current_dept_id = ?,
                assigned_to     = ?,
                status_id       = ?,
                updated_at      = NOW()
            WHERE id = ?
        ")->execute([$newDeptId, $newDeptId, $newAssignTo, $notStartedId, $id]);

        try {
            $db->prepare("
                INSERT INTO task_workflow
                (task_id, action, from_user_id, to_user_id,
                 from_dept_id, to_dept_id, old_status, new_status, remarks)
                VALUES (?, 'transferred_dept', ?, ?, ?, ?, ?, 'Not Started', ?)
            ")->execute([
                $id, $user['id'], $newAssignTo,
                $task['department_id'], $newDeptId,
                $task['status'], $transferNote
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
                        'id'          => $id,
                        'task_number' => $task['task_number'],
                        'title'       => $task['title'],
                        'department'  => $newDept['dept_name'],
                        'status'      => 'Not Started',
                        'due_date'    => $task['due_date'],
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

$pageTitle = 'Edit Task: ' . $task['task_number'];
include '../../includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

<div class="app-wrapper">
<?php include '../../includes/sidebar_admin.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<?= flashHtml() ?>

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
    <div class="col-lg-8">

        <div class="card-mis mb-4">
            <div class="card-mis-header">
                <h5><i class="fas fa-info-circle text-warning me-2"></i>Task Details</h5>
            </div>
            <div class="card-mis-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token"  value="<?= csrfToken() ?>">
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
                            <select name="company_id" id="company_select" class="form-select">
                                <option value="">-- None --</option>
                                <?php foreach ($companies as $c):
                                    $meta = [];
                                    if (!empty($c['pan_number']))   $meta[] = $c['pan_number'];
                                    if (!empty($c['company_code'])) $meta[] = $c['company_code'];
                                    $metaStr = $meta ? ' — ' . implode(' | ', $meta) : '';
                                    $sel = ((int)($task['company_id'] ?? 0) === (int)$c['id']) ? 'selected' : '';
                                ?>
                                <option value="<?= $c['id'] ?>" <?= $sel ?>>
                                    <?= htmlspecialchars($c['company_name'] . $metaStr) ?>
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
                                <option value="<?= htmlspecialchars($fy['fy_code']) ?>"
                                    <?= ($task['fiscal_year'] ?? '') === $fy['fy_code'] ? 'selected' : '' ?>
                                    <?= $fy['is_current'] ? 'style="font-weight:700;color:#16a34a;"' : '' ?>>
                                    <?= htmlspecialchars($fy['fy_label'] ?: $fy['fy_code']) ?>
                                    <?= $fy['is_current'] ? ' ★ Current' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- ── Assign To: ALL staff of this department ── -->
                        <div class="col-md-8">
                        <label class="form-label-mis">Assign To
                            <span style="font-size:.72rem;color:#9ca3af;">
                                — all <?= htmlspecialchars($task['dept_name']) ?> staff
                            </span>
                        </label>
                       
                        <select name="assigned_to" id="assigned_to_sel" class="form-select">
                                <option value="">-- Unassigned --</option>
                                <?php foreach ($deptStaff as $s):
                                    $sel = ((int)($task['assigned_to'] ?? 0) === (int)$s['id']) ? 'selected' : '';
                                    $label = $s['full_name'];
                                    if ($s['employee_id'])   $label .= ' (' . $s['employee_id'] . ')';
                                    if ($s['branch_name'])   $label .= ' — ' . $s['branch_name'];
                                ?>
                                <option value="<?= $s['id'] ?>" <?= $sel ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                    </div>

                        <!-- ── Audit Nature ── -->
                         <?php if (($task['dept_code'] ?? '') !== 'FIN'): ?>
                        <div class="col-md-4">
                            <label class="form-label-mis">Audit Nature</label>
                            <select name="audit_nature" id="audit_nature"
                                class="form-select" onchange="loadAuditors(this.value)">
                            <option value="">-- Select --</option>
                            <option value="countable"
                                <?= strtolower($task['audit_nature'] ?? '') === 'countable' ? 'selected' : '' ?>>
                                Countable
                            </option>
                            <option value="uncountable"
                                <?= strtolower($task['audit_nature'] ?? '') === 'uncountable' ? 'selected' : '' ?>>
                                Uncountable
                            </option>
                            <option value="N/A"
                                <?= strtoupper($task['audit_nature'] ?? '') === 'N/A' ? 'selected' : '' ?>>
                                N/A
                            </option>
                        </select>
                        </div>

                        <!-- ── Auditor ── -->
                         <?php
                        $showAuditorWrap = !empty($task['audit_nature']) && strtoupper($task['audit_nature']) !== 'N/A';
                        ?>
                        <div class="col-md-8" id="auditor-wrap"
                             style="<?= $task['audit_nature'] ? '' : 'display:none;' ?>">
                            <label class="form-label-mis">Auditor
                                <span id="auditor-limit-note"
                                      style="font-size:.72rem;color:#9ca3af;margin-left:.3rem;"></span>
                            </label>
                            <select name="auditor_id" id="auditor_id" class="form-select">
                                <option value="">-- Select Auditor --</option>
                                <?php foreach ($currentAuditors as $a):
                                    $nature  = strtolower($task['audit_nature'] ?? '');
                                    $count   = ($nature === 'countable') ? $a['countable_count'] : $a['uncountable_count'];
                                    $atLimit = ($nature === 'countable')
                                        && $a['countable_count'] >= $a['max_limit']
                                        && $a['id'] != $task['auditor_id'];
                                ?>
                                    <option value="<?= $a['id'] ?>"
                                        <?= $task['auditor_id'] == $a['id'] ? 'selected' : '' ?>
                                        <?= $atLimit ? 'disabled' : '' ?>
                                        data-countable="<?= $a['countable_count'] ?>"
                                        data-uncountable="<?= $a['uncountable_count'] ?>"
                                        data-limit="<?= $a['max_limit'] ?>">
                                        <?= htmlspecialchars($a['auditor_name']) ?>
                                        (<?= strtolower($task['audit_nature'] ?? '') === 'countable' ? $a['countable_count'] : $a['uncountable_count'] ?>
                                             <?= strtolower($task['audit_nature'] ?? '') === 'countable' ? '/ ' . $a['max_limit'] : '' ?>)
                                        <?= $atLimit ? '— FULL' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <!-- Capacity bar for selected auditor -->
                            <div id="auditor-capacity" style="margin-top:.4rem;display:none;">
                                <div class="d-flex justify-content-between mb-1" style="font-size:.72rem;color:#6b7280;">
                                    <span id="capacity-label">Capacity</span>
                                    <span id="capacity-text"></span>
                                </div>
                                <div style="background:#f3f4f6;border-radius:99px;height:5px;">
                                    <div id="capacity-bar"
                                         style="height:100%;border-radius:99px;background:#10b981;transition:.3s;width:0%;"></div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
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

        <!-- Dept detail link -->
        <div class="card-mis" style="border-left:3px solid <?= htmlspecialchars($task['color'] ?? '#c9a84c') ?>;">
            <div class="card-mis-body d-flex align-items-center justify-content-between gap-3 py-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:40px;height:40px;border-radius:10px;
                                background:<?= htmlspecialchars($task['color'] ?? '#c9a84c') ?>22;
                                display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-layer-group"
                           style="color:<?= htmlspecialchars($task['color'] ?? '#c9a84c') ?>;"></i>
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
                   style="background:<?= htmlspecialchars($task['color'] ?? '#c9a84c') ?>;color:white;
                          border-radius:6px;padding:.3rem .8rem;font-size:.78rem;white-space:nowrap;">
                    <i class="fas fa-eye me-1"></i>View & Edit Details
                </a>
            </div>
        </div>

    </div><!-- end col-lg-8 -->

    <!-- ── RIGHT SIDEBAR ── -->
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
            <?php if ($task['auditor_name']): ?>
            <div class="mb-2"><strong>Auditor:</strong> <?= htmlspecialchars($task['auditor_name']) ?></div>
            <div class="mb-2"><strong>Audit Nature:</strong>
                <span style="text-transform:capitalize;"><?= htmlspecialchars($task['audit_nature'] ?? '—') ?></span>
            </div>
            <?php endif; ?>
            <div class="mb-2"><strong>Created:</strong> <?= date('d M Y', strtotime($task['created_at'])) ?></div>
            <div><strong>Updated:</strong> <?= date('d M Y', strtotime($task['updated_at'])) ?></div>
        </div>

        <!-- Transfer to Department -->
        <div class="card-mis mb-3" style="border-left:3px solid #8b5cf6;">
            <div class="card-mis-header">
                <h5><i class="fas fa-exchange-alt me-2" style="color:#8b5cf6;"></i>Transfer to Department</h5>
            </div>
            <div class="card-mis-body">
                <p style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">
                    Transfer this task to another department for further processing.
                </p>
                <form method="POST" onsubmit="return confirm('Transfer this task?');">
                    <input type="hidden" name="csrf_token"    value="<?= csrfToken() ?>">
                    <input type="hidden" name="transfer_dept" value="1">
                    <div class="row g-2">

                        <div class="col-12">
                            <label class="form-label-mis">Transfer To</label>
                            <select name="new_dept_id" id="transferDept"
                                    class="form-select form-select-sm"
                                    required onchange="filterTransferStaff()">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($allDepts as $d): ?>
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
                                <span id="staffFilterNote" style="font-size:.7rem;color:#9ca3af;">
                                    (select dept first)
                                </span>
                            </label>
                            <select name="new_assigned_to" id="transferStaff"
                                    class="form-select form-select-sm">
                                <option value="">-- Unassigned --</option>
                                <?php foreach ($transferStaff as $s): ?>
                                    <option value="<?= $s['id'] ?>"
                                            data-deptcode="<?= htmlspecialchars($s['dept_code']) ?>">
                                        <?= htmlspecialchars($s['full_name']) ?>
                                        <?= !empty($s['employee_id']) ? ' (' . $s['employee_id'] . ')' : '' ?>
                                        — <?= htmlspecialchars($s['branch_name']) ?>
                                        (<?= htmlspecialchars($s['dept_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label-mis">Transfer Note</label>
                            <textarea name="transfer_note" class="form-control form-control-sm"
                                rows="2" placeholder="Reason / instructions for next dept…"></textarea>
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

    </div><!-- end col-lg-4 -->
</div><!-- end row -->
</div>

<?php include '../../includes/footer.php'; ?>
<script>
// ── Store all transfer staff for filtering ────────────────────────────────────
const allTransferStaff = <?= json_encode(array_map(fn($s) => [
    'id'       => $s['id'],
    'name'     => $s['full_name'],
    'employee' => $s['employee_id'] ?? '',
    'branch'   => $s['branch_name'],
    'dept'     => $s['dept_name'],
    'deptcode' => $s['dept_code'],
], $transferStaff)) ?>;

let transferTs = null;

document.addEventListener('DOMContentLoaded', function () {

    // ── Company ───────────────────────────────────────────────────────────────
    const companyTs = new TomSelect('#company_select', {
        placeholder: 'Search company, PAN or code…',
        allowEmptyOption: true,
        maxOptions: 500,
        searchField: ['text'],
        render: {
            option: function(data, escape) {
                const parts = data.text.split(' — ');
                return `<div style="padding:.4rem .2rem;">
                    <div style="font-weight:600;font-size:.87rem;">${escape(parts[0] || '')}</div>
                    ${parts[1] ? `<div style="font-size:.75rem;color:#6b7280;">${escape(parts[1])}</div>` : ''}
                </div>`;
            },
            item: function(data, escape) {
                return `<div>${escape(data.text.split(' — ')[0])}</div>`;
            }
        }
    });
    const preCompany = '<?= (int)($task['company_id'] ?? 0) ?>';
    if (preCompany && preCompany !== '0') companyTs.setValue(preCompany, true);

    // ── Assign To ─────────────────────────────────────────────────────────────
    const staffTs = new TomSelect('#assigned_to_sel', {
        placeholder: 'Search staff by name or ID…',
        allowEmptyOption: true,
        maxOptions: 300,
        searchField: ['text'],
        render: {
            option: function(data, escape) {
                const parts = data.text.split(' — ');
                return `<div style="padding:.4rem .2rem;">
                    <div style="font-weight:600;font-size:.87rem;">${escape(parts[0] || '')}</div>
                    ${parts[1] ? `<div style="font-size:.75rem;color:#6b7280;">${escape(parts[1])}</div>` : ''}
                </div>`;
            },
            item: function(data, escape) {
                return `<div>${escape(data.text.split(' — ')[0])}</div>`;
            }
        }
    });
    const preStaff = '<?= (int)($task['assigned_to'] ?? 0) ?>';
    if (preStaff && preStaff !== '0') staffTs.setValue(preStaff, true);

    // ── Transfer Staff (init with all staff, filter on dept change) ───────────
    filterTransferStaff();

    // ── Audit capacity bar on load ────────────────────────────────────────────
    const auditNatureEl = document.getElementById('audit_nature');
    if (auditNatureEl?.value) {
        const auditorWrap = document.getElementById('auditor-wrap');
        if (auditorWrap) auditorWrap.style.display = 'block';
        const limitNote = document.getElementById('auditor-limit-note');
        if (limitNote) limitNote.textContent = auditNatureEl.value === 'countable' ? '(limit applies)' : '(no limit)';
        updateCapacityBar();
    }

    // ── Auditor change ────────────────────────────────────────────────────────
    const auditorSel = document.getElementById('auditor_id');
    if (auditorSel) auditorSel.addEventListener('change', updateCapacityBar);
});

// ── Transfer dept filter ──────────────────────────────────────────────────────
function filterTransferStaff() {
    const sel      = document.getElementById('transferDept');
    const option   = sel?.options[sel.selectedIndex];
    const deptCode = option?.dataset.code ?? '';
    const deptName = option?.dataset.name ?? '';
    const note     = document.getElementById('staffFilterNote');

    if (note) note.textContent = deptCode ? `(${deptName} admins)` : '(select dept first)';

    // Destroy existing Tom Select instance
    if (transferTs) { transferTs.destroy(); transferTs = null; }

    // Rebuild select options filtered by department
    const filtered = deptCode
        ? allTransferStaff.filter(s => s.deptcode === deptCode)
        : allTransferStaff;

    const select = document.getElementById('transferStaff');
    select.innerHTML = '<option value="">-- Unassigned --</option>';
    filtered.forEach(s => {
        const opt  = document.createElement('option');
        opt.value  = s.id;
        opt.text   = `${s.name}${s.employee ? ' (' + s.employee + ')' : ''} — ${s.branch} (${s.dept})`;
        select.appendChild(opt);
    });

    transferTs = new TomSelect('#transferStaff', {
        placeholder: 'Search by name, ID or department...',
        allowEmptyOption: true,
        maxOptions: 500,
        searchField: ['text'],
        render: {
            option: function(data, escape) {
                const parts = data.text.split(' — ');
                return `<div style="padding:.4rem .2rem;">
                    <div style="font-weight:600;font-size:.87rem;">${escape(parts[0] || '')}</div>
                    ${parts[1] ? `<div style="font-size:.75rem;color:#6b7280;">${escape(parts[1])}</div>` : ''}
                </div>`;
            },
            item: function(data, escape) {
                return `<div>${escape(data.text.split(' — ')[0])}</div>`;
            }
        }
    });
}

// ── Load auditors via AJAX ────────────────────────────────────────────────────
function loadAuditors(nature) {
    const wrap   = document.getElementById('auditor-wrap');
    const sel    = document.getElementById('auditor_id');
    const capDiv = document.getElementById('auditor-capacity');
    if (!wrap || !sel) return;

    if (!nature || nature === 'N/A') {
        wrap.style.display = 'none';
        sel.innerHTML = '<option value="">-- Select Auditor --</option>';
        if (capDiv) capDiv.style.display = 'none';
        return;
    }
    wrap.style.display = 'block';
    document.getElementById('auditor-limit-note').textContent =
        nature === 'countable' ? '(limit applies)' : '(no limit)';

    sel.innerHTML = '<option value="">Loading…</option>';

    fetch(`<?= APP_URL ?>/ajax/get_auditors.php?nature=${encodeURIComponent(nature)}`)
        .then(r => r.json())
        .then(data => {
            if (!Array.isArray(data)) throw new Error('Invalid data');
            sel.innerHTML = '<option value="">-- Select Auditor --</option>';
            data.forEach(a => {
                const atLimit = nature === 'countable' && a.at_limit;
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.text  = nature === 'countable'
                    ? `${a.auditor_name} (${a.countable_count} / ${a.max_limit})${atLimit ? ' — FULL' : ''}`
                    : `${a.auditor_name} (${a.uncountable_count} tasks)`;
                opt.disabled = atLimit;
                opt.dataset.countable   = a.countable_count;
                opt.dataset.uncountable = a.uncountable_count;
                opt.dataset.limit       = a.max_limit;
                sel.appendChild(opt);
            });
            updateCapacityBar();
        })
        .catch(() => { sel.innerHTML = '<option value="">Error loading auditors</option>'; });
}

// ── Capacity bar ──────────────────────────────────────────────────────────────
function updateCapacityBar() {
    const natureEl = document.getElementById('audit_nature');
    const sel      = document.getElementById('auditor_id');
    const capDiv   = document.getElementById('auditor-capacity');
    if (!natureEl || !sel || !capDiv) return;

    const nature = natureEl.value;
    const opt    = sel.options[sel.selectedIndex];
    if (!opt?.value || !nature) { capDiv.style.display = 'none'; return; }

    const used  = parseInt(opt.dataset.countable  || 0);
    const limit = parseInt(opt.dataset.limit       || 0);
    const unc   = parseInt(opt.dataset.uncountable || 0);

    if (nature === 'uncountable') {
        document.getElementById('capacity-label').textContent = 'Uncountable tasks';
        document.getElementById('capacity-text').textContent  = unc;
        document.getElementById('capacity-bar').style.width   = '0%';
        capDiv.style.display = 'block';
        return;
    }

    const pct   = limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0;
    const color = pct >= 100 ? '#ef4444' : pct >= 80 ? '#f59e0b' : '#10b981';
    document.getElementById('capacity-label').textContent    = 'Countable capacity used';
    document.getElementById('capacity-text').textContent     = `${used} / ${limit} (${pct}%)`;
    document.getElementById('capacity-bar').style.width      = pct + '%';
    document.getElementById('capacity-bar').style.background = color;
    capDiv.style.display = 'block';
}
</script>