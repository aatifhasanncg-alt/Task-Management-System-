<?php
// executive/tasks/assign.php — Executive can assign tasks cross-branch
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/mailer.php';
requireExecutive();

$db = getDB();
$user = currentUser();
$pageTitle = 'Assign Task';
$auditors = $db->query("SELECT * FROM auditors WHERE is_active=1 ORDER BY auditor_name")->fetchAll();
$branches = $db->query("SELECT id,branch_name,city FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
$depts = $db->query("SELECT id,dept_name,dept_code,color,icon FROM departments WHERE is_active=1 ORDER BY dept_name")->fetchAll();
$companies = $db->query("SELECT id,company_name,pan_number FROM companies WHERE is_active=1 ORDER BY company_name LIMIT 200")->fetchAll();
$years = $db->query("
    SELECT fy_code
    FROM fiscal_years 
    WHERE is_active = 1
    ORDER BY fy_code DESC
")->fetchAll(PDO::FETCH_COLUMN);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $deptId = (int) ($_POST['dept_id'] ?? 0);
    $branchId = (int) ($_POST['branch_id'] ?? 0);
    $companyId = (int) ($_POST['company_id'] ?? 0) ?: null;
    $assignedTo = (int) ($_POST['assigned_to'] ?? 0) ?: null;
    $auditorId = (int) ($_POST['auditor_id'] ?? 0) ?: null;
    $auditNature = $_POST['audit_nature'] ?? null;
    $status_id = (int)($_POST['status_id'] ?? 0);
    $priority = $_POST['priority'] ?? 'medium';
    $dueDate = $_POST['due_date'] ?? null;
    $fiscalYear = $_POST['fiscal_year'] ?? getCurrentFiscalYear($db);
    $remarks = trim($_POST['remarks'] ?? '');

    if (!$title)
        $errors[] = 'Task title is required.';
    if (!$deptId)
        $errors[] = 'Department is required.';
    if (!$branchId)
        $errors[] = 'Branch is required.';
    if (!$status_id || !array_key_exists($status_id, TASK_STATUSES)) {
        $errors[] = 'Invalid status.';
    }

    if (!$errors) {
        $ins = $db->prepare("INSERT INTO tasks(
            title, description, department_id, branch_id, company_id,
            created_by, assigned_to, auditor_id, audit_nature,
            status_id, priority, due_date, fiscal_year, remarks, current_dept_id
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $ins->execute([
            $title, $desc, $deptId, $branchId, $companyId,
            $user['id'], $assignedTo, $auditorId, $auditNature,
            $status_id, $priority, $dueDate ?: null, $fiscalYear, $remarks, $deptId
        ]);
        $taskId = (int) $db->lastInsertId();

        // Log workflow: created
        $db->prepare("INSERT INTO task_workflow(task_id,action,from_user_id,to_user_id,new_status,remarks) VALUES(?,?,?,?,?,?)")
            ->execute([$taskId, 'created', $user['id'], $assignedTo, 'Pending', $remarks]);

        // Save dept-specific fields
        $deptCode = '';
        foreach ($depts as $d) {
            if ($d['id'] == $deptId) {
                $deptCode = $d['dept_code'];
                break;
            }
        }
        $deptTableMap = ['TAX' => 'task_tax', 'RETAIL' => 'task_retail', 'CORP' => 'task_corporate', 'BANK' => 'task_banking'];
        if (isset($deptTableMap[$deptCode]) && !empty($_POST['dept_fields'])) {
            $cols = array_keys($_POST['dept_fields']);
            $vals = array_values($_POST['dept_fields']);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $colStr = 'task_id,' . implode(',', array_map(fn($c) => "`{$c}`", $cols));
            $db->prepare("INSERT INTO {$deptTableMap[$deptCode]}({$colStr}) VALUES(?,{$ph})")->execute(array_merge([$taskId], $vals));
        }

        // Notify assigned staff
        if ($assignedTo) {
            $db->prepare("INSERT INTO notifications(user_id,title,message,type,link) VALUES(?,?,?,?,?)")
                ->execute([$assignedTo, "New Task Assigned", "Task assigned: {$title}", 'task', APP_URL . "/staff/tasks/view.php?id={$taskId}"]);
            $assignedUser = $db->prepare("SELECT * FROM users WHERE id=?");
            $assignedUser->execute([$assignedTo]);
            $assignedUser = $assignedUser->fetch();
            if ($assignedUser)
                emailTaskAssigned($assignedUser, ['id' => $taskId, 'task_number' => 'Pending', 'title' => $title, 'department' => $deptCode, 'status' => $status, 'due_date' => $dueDate]);
        }

        logActivity("Assigned task: {$title}", 'tasks', "id={$taskId}");
        setFlash('success', 'Task assigned successfully.');
        header('Location:index.php');
        exit;
    }
}

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">
            <div class="page-hero">
                <div class="page-hero-badge"><i class="fas fa-plus-circle"></i> Assign</div>
                <h4>Assign New Task</h4>
                <p>Create and assign a task to any branch and department.</p>
            </div>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger rounded-3 mb-3">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                    </ul>
                </div><?php endif; ?>
            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-info-circle text-warning me-2"></i>Task Details</h5>
                            </div>
                            <div class="card-mis-body">
                                <div class="row g-3">
                                    <div class="col-12"><label class="form-label-mis">Task Title <span
                                                style="color:#ef4444;">*</span></label>
                                        <input type="text" name="title" class="form-control" maxlength="255"
                                            value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-4"><label class="form-label-mis">Department <span
                                                style="color:#ef4444;">*</span></label>
                                        <select name="dept_id" id="dept_id" class="form-select"
                                            onchange="onDeptChange()" required>
                                            <option value="">-- Select --</option>
                                            <?php foreach ($depts as $d): ?>
                                                <option value="<?= $d['id'] ?>" data-code="<?= $d['dept_code'] ?>"
                                                    <?= ($_POST['dept_id'] ?? '') == $d['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($d['dept_name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4"><label class="form-label-mis">Branch <span
                                                style="color:#ef4444;">*</span></label>
                                        <select name="branch_id" id="branch_id" class="form-select"
                                            onchange="loadStaff()" required>
                                            <option value="">-- Select --</option>
                                            <?php foreach ($branches as $b): ?>
                                                <option value="<?= $b['id'] ?>"
                                                    <?= ($_POST['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($b['branch_name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4"><label class="form-label-mis">Assign To</label>
                                        <select name="assigned_to" id="assigned_to_sel" class="form-select">
                                            <option value="">-- Select Staff --</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Audit Nature</label>
                                        <select name="audit_nature" id="audit_nature" class="form-select" onchange="loadAuditors()">
                                            <option value="">-- Select --</option>
                                            <option value="countable">Countable</option>
                                            <option value="uncountable">Uncountable</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label-mis">Auditor</label>
                                        <select name="auditor_id" id="auditor_id" class="form-select" onchange="updateAuditorCount()">
                                            <option value="">-- Select Auditor --</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3"><label class="form-label-mis">Status</label>
                                        <select name="status_id" class="form-select">
                                            <?php foreach (TASK_STATUSES as $k => $s): ?>
                                                <option value="<?= $k ?>"
                                                    <?= (int)($_POST['status_id'] ?? 0) === (int)$k ? 'selected' : '' ?>>
                                                </option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3"><label class="form-label-mis">Priority</label>
                                        <select name="priority" class="form-select">
                                            <?php foreach (TASK_PRIORITIES as $k => $p): ?>
                                                <option value="<?= $k ?>"
                                                    <?= ($_POST['priority'] ?? 'medium') === $k ? 'selected' : '' ?>><?= $p['label'] ?>
                                                </option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3"><label class="form-label-mis">Due Date</label>
                                        <input type="date" name="due_date" class="form-control"
                                            value="<?= htmlspecialchars($_POST['due_date'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-3"><label class="form-label-mis">Fiscal Year</label>
                                        <select name="fiscal_year" class="form-select">
                                            <?php $currentFY = getCurrentFiscalYear($db); ?>
                                            <?php foreach ($years as $y): ?>
                                                <option value="<?= htmlspecialchars($y) ?>"
                                                    <?= (($_POST['fiscal_year'] ?? $currentFY) === $y) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($y) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6"><label class="form-label-mis">Company</label>
                                        <select name="company_id" class="form-select">
                                            <option value="">-- No Company --</option>
                                            <?php foreach ($companies as $c): ?>
                                                <option value="<?= $c['id'] ?>"
                                                    <?= ($_POST['company_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($c['company_name']) ?>    <?= $c['pan_number'] ? ' (' . $c['pan_number'] . ')' : '' ?>
                                                </option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12"><label class="form-label-mis">Description</label>
                                        <textarea name="description" class="form-control"
                                            rows="3"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-12"><label class="form-label-mis">Remarks / Initial
                                            Instructions</label>
                                        <textarea name="remarks" class="form-control" rows="2"
                                            placeholder="Initial remarks or instructions..."><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Dept-specific fields (loaded via JS) -->
                        <div id="dept_fields_wrap" style="display:none;" class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5 id="dept_fields_title"><i
                                        class="fas fa-layer-group text-warning me-2"></i>Department Fields</h5>
                            </div>
                            <div class="card-mis-body">
                                <div id="dept_fields_inner" class="row g-3"></div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 mb-4">
                            <button type="submit" class="btn-gold btn"><i class="fas fa-paper-plane me-2"></i>Assign
                                Task</button>
                            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card-mis p-3" style="border-left:3px solid var(--gold);">
                            <p style="font-size:.8rem;font-weight:600;"><i
                                    class="fas fa-info-circle text-warning me-1"></i>Tips</p>
                            <ul style="font-size:.78rem;color:#6b7280;padding-left:1rem;">
                                <li>Select department first to load dept-specific fields.</li>
                                <li>Select branch then department to filter staff list.</li>
                                <li>Remarks are sent to the assigned staff member.</li>
                                <li>Email + app notification is sent on assignment.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <?php include '../../includes/footer.php'; ?>
        <script>
            function onDeptChange() { loadStaff(); loadDeptFields(); }
            function loadStaff() {
                const b = document.getElementById('branch_id').value;
                const d = document.getElementById('dept_id').value;
                if (!b && !d) return;
                fetch(`${APP_URL}/ajax/get_staff_by_admin.php?branch_id=${b}&dept_id=${d}`)
                    .then(r => r.json()).then(data => {
                        const s = document.getElementById('assigned_to_sel');
                        s.innerHTML = '<option value="">-- Select Staff --</option>';
                        data.forEach(u => { s.innerHTML += `<option value="${u.id}">${u.full_name} (${u.employee_id || ''})</option>`; });
                    });
            }
            function loadDeptFields() {
                const sel = document.getElementById('dept_id');
                const opt = sel.options[sel.selectedIndex];
                if (!opt || !opt.value) return;
                const code = opt.dataset.code || opt.text;
                fetch(`${APP_URL}/ajax/get_task_fields.php?dept_code=${code}`)
                    .then(r => r.json()).then(data => {
                        const wrap = document.getElementById('dept_fields_wrap');
                        const inner = document.getElementById('dept_fields_inner');
                        const title = document.getElementById('dept_fields_title');
                        const allFields = [...data.fields, ...data.custom_fields];
                        if (!allFields.length) { wrap.style.display = 'none'; return; }
                        title.innerHTML = `<i class="fas fa-layer-group text-warning me-2"></i>${opt.text} Fields`;
                        inner.innerHTML = allFields.map(f => fieldHtml(f)).join('');
                        wrap.style.display = 'block';
                    });
            }
            function fieldHtml([key, label, type, opts]) {
                const n = `dept_fields[${key}]`;
                if (type === 'select') {
                    const o = opts.split('|').map(v => `<option value="${v}">${v}</option>`).join('');
                    return `<div class="col-md-4"><label class="form-label-mis">${label}</label><select name="${n}" class="form-select form-select-sm"><option value="">--</option>${o}</select></div>`;
                }
                if (type === 'textarea') return `<div class="col-md-8"><label class="form-label-mis">${label}</label><textarea name="${n}" class="form-control form-control-sm" rows="2"></textarea></div>`;
                if (type === 'checkbox') return `<div class="col-md-4"><div class="form-check mt-4"><input type="checkbox" name="${n}" class="form-check-input" value="1" id="f_${key}"><label class="form-check-label" for="f_${key}">${label}</label></div></div>`;
                return `<div class="col-md-4"><label class="form-label-mis">${label}</label><input type="${type === 'phone' ? 'tel' : type === 'currency' ? 'number' : type}" name="${n}" class="form-control form-control-sm"></div>`;
            }
            function loadAuditors() {
            const nature = document.getElementById('audit_nature').value;

            if (!nature) return;

            fetch(`${APP_URL}/ajax/get_auditors.php?nature=${nature}`)
                .then(res => res.json())
                .then(data => {
                    const select = document.getElementById('auditor_id');
                    select.innerHTML = '<option value="">-- Select Auditor --</option>';

                    data.forEach(a => {
                        const count = nature === 'countable' ? a.countable_count : a.uncountable_count;
                        select.innerHTML += `<option value="${a.id}">
                            ${a.name} (${count})
                        </option>`;
                    });
                });
        }

        function updateAuditorCount() {
            const select = document.getElementById('auditor_id');
            const selected = select.options[select.selectedIndex];

            if (selected) {
                console.log("Selected Auditor:", selected.text);
            }
        }
        </script>