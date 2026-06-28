<?php
// executive/tasks/assign.php — Executive can assign tasks cross-branch
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/mailer.php';
requireExecutive();

$db = getDB();
$user = currentUser();
$pageTitle = 'Assign Task';
$auditors = $db->query("SELECT * FROM auditors WHERE is_active=1 ORDER BY auditor_name")->fetchAll();
$branches = $db->query("SELECT id,branch_name,city FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
$depts = $db->query("SELECT id,dept_name,dept_code,color,icon FROM departments WHERE is_active=1 AND dept_name !='Core Admin' ORDER BY dept_name")->fetchAll();
$companies = $db->query("SELECT id,company_name,pan_number, company_code FROM companies WHERE is_active=1 ORDER BY company_name LIMIT 200")->fetchAll();
$years = $db->query("
    SELECT fy_code
    FROM fiscal_years 
    WHERE is_active = 1
    ORDER BY fy_code DESC
")->fetchAll(PDO::FETCH_COLUMN);
$errors = [];
$allStatusOpts = $db->query("SELECT status_name FROM task_status ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $deptId = (int) ($_POST['dept_id'] ?? 0);
    $branchId = (int) ($_POST['branch_id'] ?? 0);
    $companyId = (int) ($_POST['company_id'] ?? 0) ?: null;
    $assignedTo = (int) ($_POST['assigned_to'] ?? 0) ?: null;
    $auditorId = (int) ($_POST['auditor_id'] ?? 0) ?: null;
    $raw = trim($_POST['audit_nature'] ?? '');
    $auditNature = $raw !== '' ? (strtolower($raw) === 'n/a' ? 'N/A' : strtolower($raw)) : null;
    if ($auditNature === 'N/A') {
        $auditorId = null;
    }
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
    if (!$status_id) {
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
        $taskId = $db->lastInsertId();
        if ($auditorId && $auditNature) {
            $fyId = $db->query("SELECT id FROM fiscal_years WHERE is_current=1 LIMIT 1")->fetchColumn();
            if ($fyId) {
                $col = strtolower($auditNature) === 'countable' ? 'countable_count' : 'uncountable_count';
                $db->prepare("
                    INSERT INTO auditor_yearly_quota
                        (auditor_id, fiscal_year_id, countable_count, uncountable_count)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        {$col} = {$col} + 1
                ")->execute([
                    $auditorId, $fyId,
                    strtolower($auditNature) === 'countable'   ? 1 : 0,
                    strtolower($auditNature) === 'uncountable' ? 1 : 0,
                ]);
            }
        }

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
            $tnStmt = $db->prepare("SELECT task_number FROM tasks WHERE id = ?");
            $tnStmt->execute([$taskId]);
            $taskNumber = $tnStmt->fetchColumn() ?: "T-{$taskId}";

            $companyName = '';
            if ($companyId) {
                $cnStmt = $db->prepare("SELECT company_name FROM companies WHERE id = ?");
                $cnStmt->execute([$companyId]);
                $companyName = $cnStmt->fetchColumn() ?: '';
            }

            $notifMsg = "Task #{$taskNumber}";
            if ($companyName) $notifMsg .= " — {$companyName}";
            $notifMsg .= " has been assigned to you";
            if ($dueDate) $notifMsg .= " · Due " . date('M j, Y', strtotime($dueDate));
            $notifMsg .= ".";
            $roleStmt = $db->prepare("
                SELECT r.role_name 
                FROM users u 
                JOIN roles r ON r.id = u.role_id 
                WHERE u.id = ?
            ");
            $roleStmt->execute([$assignedTo]);
            $role = strtolower($roleStmt->fetchColumn() ?: 'staff');

            $rolePathMap = [
                'admin'     => 'admin',
                'executive' => 'executive',
                'manager'   => 'manager',
                'staff'     => 'staff',
            ];

            $basePath = $rolePathMap[$role] ?? 'staff';

            $taskUrl = APP_URL . '/' . $basePath . '/tasks/view.php?id=' . $taskId;
            notify(
                $assignedTo,
                "New Task: {$title}",
                $notifMsg,
                'task',
                $taskUrl,
                true,
                [
                    'template'     => 'task_assigned',
                    'task_number'  => $taskNumber,
                    'company_name' => $companyName,
                    'task'         => [
                        'id'          => $taskId,
                        'task_number' => $taskNumber,
                        'title'       => $title,
                        'department'  => $deptCode,
                        'status'      => $status_id,
                        'priority'    => $priority,
                        'due_date'    => $dueDate,
                        'fiscal_year' => $fiscalYear,
                        'company'     => $companyName,
                        'remarks'     => $remarks,
                    ],
                ]
            );
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
            <form method="POST" id="assignForm" novalidate>
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
                                        <input type="text" name="title" id="title" class="form-control" maxlength="255"
                                            value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                                        <div class="invalid-feedback-mis" id="err_title" style="color:#ef4444;font-size:.72rem;display:none;"></div>
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
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Assign To <span style="color:#ef4444;">*</span></label>
                                        <select name="assigned_to" id="assigned_to_sel" class="form-select" required>
                                            <option value="">-- Select Staff --</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Audit Nature</label>
                                        <select name="audit_nature" id="audit_nature" class="form-select" onchange="loadAuditors()">
                                            <option value="">-- Select --</option>
                                            <option value="countable"   <?= ($_POST['audit_nature'] ?? '') === 'countable'   ? 'selected' : '' ?>>Countable</option>
                                            <option value="uncountable" <?= ($_POST['audit_nature'] ?? '') === 'uncountable' ? 'selected' : '' ?>>Uncountable</option>
                                            <option value="N/A" <?= ($_POST['audit_nature'] ?? '') === 'N/A' ? 'selected' : '' ?>>N/A</option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label-mis">Auditor</label>
                                        <select name="auditor_id" id="auditor_id" class="form-select">
                                            <option value="">-- Select Auditor --</option>
                                        </select>
                                        <small id="auditor_limit_desc" class="text-muted d-block mt-1" style="font-size:.72rem;"></small>
                                    </div>
                                    <!-- Capacity Bar -->
                                    <div id="auditor-capacity" style="margin-top:.4rem;display:none;">
                                        <div class="d-flex justify-content-between mb-1" style="font-size:.72rem;color:#6b7280;">
                                            <span id="capacity-label">Capacity</span>
                                            <span id="capacity-text"></span>
                                        </div>
                                        <div style="background:#f3f4f6;border-radius:99px;height:5px;">
                                            <div id="capacity-bar"
                                                style="height:100%;border-radius:99px;background:#10b981;width:0%;"></div>
                                        </div>
                                    </div>
                                   <div class="col-md-4">
                                        <label class="form-label-mis">Status <span style="color:#ef4444;">*</span></label>
                                        <select name="status_id" class="form-select" required>
                                            <option value="">-- Select Status --</option>
                                            <?php 
                                            $statusList = $db->query("SELECT id, status_name FROM task_status ORDER BY id")->fetchAll();
                                            foreach ($statusList as $s): 
                                            ?>
                                                <option value="<?= $s['id'] ?>"
                                                    <?= ($_POST['status_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($s['status_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
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
                                    <div class="col-md-3">
                                        <label class="form-label-mis">Due Date <span style="color:#ef4444;">*</span></label>
                                        <input type="date" name="due_date" id="due_date" class="form-control"
                                            value="<?= htmlspecialchars($_POST['due_date'] ?? '') ?>" required>
                                        <div class="invalid-feedback-mis" id="err_due_date" style="color:#ef4444;font-size:.72rem;display:none;"></div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label-mis">Fiscal Year <span style="color:#ef4444;">*</span></label>
                                        <select name="fiscal_year" class="form-select" required>
                                            <?php $currentFY = getCurrentFiscalYear($db); ?>
                                            <?php foreach ($years as $y): ?>
                                                <option value="<?= htmlspecialchars($y) ?>"
                                                    <?= (($_POST['fiscal_year'] ?? $currentFY) === $y) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($y) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
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
                                                $sel = ((int)($_POST['company_id'] ?? 0) === (int)$c['id']) ? 'selected' : '';
                                            ?>
                                            <option value="<?= $c['id'] ?>" <?= $sel ?>>
                                                <?= htmlspecialchars($c['company_name'] . $metaStr) ?>
                                            </option>
                                            <?php endforeach; ?>

                                        </select>
                                    </div>
                                    <div class="col-12"><label class="form-label-mis">Description</label>
                                        <textarea name="description" id="description" class="form-control"
                                            rows="3" maxlength="500"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                        <small id="description_count" style="font-size:.7rem;color:#9ca3af;float:right;"></small>
                                    </div>
                                    <div class="col-12"><label class="form-label-mis">Remarks / Initial
                                            Instructions</label>
                                        <textarea name="remarks" id="remarks" class="form-control" rows="2" maxlength="300"
                                            placeholder="Initial remarks or instructions..."><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
                                        <small id="remarks_count" style="font-size:.7rem;color:#9ca3af;float:right;"></small>
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
                            <button type="submit" id="assignSubmitBtn" class="btn-gold btn">
                                <span id="assignBtnIcon"><i class="fas fa-paper-plane me-2"></i>Assign Task</span>
                                <span id="assignBtnLoading" style="display:none;">
                                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                    Assigning Task...
                                </span>
                            </button>
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
        <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
        <?php include '../../includes/footer.php'; ?>
        <script>
        // ── Tom Select init ───────────────────────────────────────────────────
        let tomCompany = null;
        let tomStaff   = null;


        document.addEventListener('DOMContentLoaded', function () {
            new TomSelect('#company_select', {
                placeholder: 'Search by name, PAN or code...',
                allowEmptyOption: true,
                maxOptions: 500,

                // This ensures search uses the full visible text
                searchField: ["text"],

                 render: {
                    option: function(data, escape) {
                        return `
                            <div style="padding:6px 0;">
                                <div style="margin-left:10px; font-weight:600;">
                                    ${escape(data.text.split('—')[0])}
                                </div>
                                <div style="margin-left:10px; font-size:12px;color:#6b7280;">
                                    ${escape(data.text.includes('—') ? data.text.split('—')[1] : '')}
                                </div>
                            </div>
                        `;
                    },
                    item: function(data, escape) {
                        return `<div>${escape(data.text)}</div>`;
                    }
                }
            });
        });

        function onDeptChange() { loadStaff(); loadDeptFields(); }

        function loadStaff() {
    const b = document.getElementById('branch_id').value;
    const d = document.getElementById('dept_id').value;
    if (!b || !d) return;   // require BOTH now, since executive file needs both

    fetch(`<?= APP_URL ?>/ajax/get_staff_by_executive.php?branch_id=${b}&dept_id=${d}`)
        .then(r => r.json())
        .then(data => {
            if (tomStaff) { tomStaff.destroy(); tomStaff = null; }

            const s = document.getElementById('assigned_to_sel');
            s.innerHTML = '<option value="">-- Select Staff --</option>';
            data.forEach(u => {
                let label = `${u.full_name}${u.employee_id ? ' (' + u.employee_id + ')' : ''}`;
                if (u.branch_name) label += ` — ${u.branch_name}`;
                if (u.dept_code)   label += ` (${u.dept_code})`;
                s.innerHTML += `<option value="${u.id}">${label}</option>`;
            });

            tomStaff = new TomSelect('#assigned_to_sel', {
                placeholder: 'Search by name...',
                allowEmptyOption: true,
                maxOptions: 200,
            });
        });
}

        function loadDeptFields() {
            const sel = document.getElementById('dept_id');
            const opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) return;
            const code = opt.dataset.code || opt.text;
            fetch(`<?= APP_URL ?>/ajax/get_task_fields.php?dept_code=${code}`)
                .then(r => r.json()).then(data => {
                    const wrap  = document.getElementById('dept_fields_wrap');
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

        // ── Load auditors ─────────────────────────────────────────────────────
        function loadAuditors() {
            const nature = document.getElementById('audit_nature').value;
            const select = document.getElementById('auditor_id');
            const capDiv = document.getElementById('auditor-capacity');
            if (!nature || nature === 'N/A') {
                wrap.style.display = 'none';
                select.innerHTML = '<option value="">-- Select Auditor --</option>';
                if (capDiv) capDiv.style.display = 'none';
                return;
            }
            if (!nature) {
                select.innerHTML = '<option value="">-- Select Auditor --</option>';
                capDiv.style.display = 'none';
                return;
            }

            select.innerHTML = '<option value="">Loading…</option>';

            fetch(`<?= APP_URL ?>/ajax/get_auditors.php?nature=${encodeURIComponent(nature)}`)
                .then(res => res.json())
                .then(data => {
                    select.innerHTML = '<option value="">-- Select Auditor --</option>';
                    const descEl = document.getElementById('auditor_limit_desc');
                    if (nature === 'countable') {
                        descEl.textContent = `Countable limit per auditor varies — shown as (used / max) below`;
                    } else {
                        descEl.textContent = `Uncountable has no fixed cap — count shown is current workload`;
                    }
                    data.forEach(a => { 
                        const atLimit = nature === 'countable' && a.at_limit;
                        const label   = nature === 'countable'
                            ? `${a.auditor_name} (${a.countable_count} / ${a.max_limit})${atLimit ? ' — FULL' : ''}`
                            : `${a.auditor_name} (${a.uncountable_count} tasks)`;

                        const opt     = document.createElement('option');
                        opt.value     = a.id;
                        opt.text      = label;
                        opt.disabled  = atLimit;
                        opt.dataset.countable   = a.countable_count;
                        opt.dataset.uncountable = a.uncountable_count;
                        opt.dataset.limit       = a.max_limit;
                        select.appendChild(opt);
                    });
                    updateCapacityBar();
                })
                .catch(() => {
                    select.innerHTML = '<option value="">Error loading auditors</option>';
                });
        }

        // ── Capacity bar ──────────────────────────────────────────────────────
        function updateCapacityBar() {
            const nature  = document.getElementById('audit_nature').value;
            const select  = document.getElementById('auditor_id');
            const capDiv  = document.getElementById('auditor-capacity');
            const opt     = select.options[select.selectedIndex];

            if (!opt || !opt.value || !nature) {
                capDiv.style.display = 'none';
                return;
            }

            if (nature === 'uncountable') {
                document.getElementById('capacity-label').textContent = 'Uncountable tasks';
                document.getElementById('capacity-text').textContent  = opt.dataset.uncountable;
                document.getElementById('capacity-bar').style.width   = '0%';
                capDiv.style.display = 'block';
                return;
            }

            const used  = parseInt(opt.dataset.countable || 0);
            const limit = parseInt(opt.dataset.limit || 0);
            const pct   = limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0;
            const color = pct >= 100 ? '#ef4444' : pct >= 80 ? '#f59e0b' : '#10b981';

            document.getElementById('capacity-label').textContent    = 'Countable capacity used';
            document.getElementById('capacity-text').textContent     = `${used} / ${limit} (${pct}%)`;
            document.getElementById('capacity-bar').style.width      = pct + '%';
            document.getElementById('capacity-bar').style.background = color;
            capDiv.style.display = 'block';
            document.getElementById('auditor_limit_desc').textContent =
                `${opt.text.split('(')[0].trim()} has used ${used} of ${limit} countable slots this fiscal year.`;
        }

        document.getElementById('auditor_id').addEventListener('change', updateCapacityBar);

        const requiredFields = [
            { id: 'title',           label: 'Task title' },
            { id: 'dept_id',         label: 'Department' },
            { id: 'branch_id',       label: 'Branch' },
            { id: 'assigned_to_sel', label: 'Assignee' },
            { id: 'due_date',        label: 'Due date' },
        ];

        function validateForm(e) {
            let valid = true;

            requiredFields.forEach(f => {
                const el = document.getElementById(f.id);
                const err = document.getElementById('err_' + f.id);
                if (!el) return;
                if (!el.value || el.value.trim() === '') {
                    valid = false;
                    el.classList.add('is-invalid');
                    if (err) { err.textContent = `${f.label} is required.`; err.style.display = 'block'; }
                } else {
                    el.classList.remove('is-invalid');
                    if (err) err.style.display = 'none';
                }
            });

            // status_id select has no id on it currently — give it one (see step 4) then validate same way
            const status = document.querySelector('select[name="status_id"]');
            if (status) {
                const errS = document.getElementById('err_status_id');
                if (!status.value) {
                    valid = false;
                    status.classList.add('is-invalid');
                    if (errS) { errS.textContent = 'Status is required.'; errS.style.display = 'block'; }
                } else {
                    status.classList.remove('is-invalid');
                    if (errS) errS.style.display = 'none';
                }
            }

            // auditor required only if nature isn't N/A or empty
            const nature = document.getElementById('audit_nature').value;
            if (nature && nature !== 'N/A') {
                const auditor = document.getElementById('auditor_id');
                if (!auditor.value) {
                    valid = false;
                    auditor.classList.add('is-invalid');
                }
            }

            if (!valid) {
                e.preventDefault();
                e.stopPropagation();
                const firstInvalid = document.querySelector('.is-invalid');
                if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            // Validation passed — lock the button so it can't be clicked again
            const btn = document.getElementById('assignSubmitBtn');
            btn.disabled = true;
            btn.style.opacity = '0.75';
            btn.style.cursor = 'not-allowed';
            document.getElementById('assignBtnIcon').style.display = 'none';
            document.getElementById('assignBtnLoading').style.display = 'inline-flex';
            document.getElementById('assignBtnLoading').style.alignItems = 'center';

            return true;
        }

        document.getElementById('assignForm').addEventListener('submit', validateForm);
        bindCounter('description', 'description_count', 500);
bindCounter('remarks', 'remarks_count', 300);
        // ADD this function once in each file's script section:
        function bindCounter(textareaId, counterId, max) {
            const ta = document.getElementById(textareaId);
            const counter = document.getElementById(counterId);
            if (!ta || !counter) return;
            const update = () => {
                const len = ta.value.length;
                counter.textContent = `${len}/${max}`;
                counter.style.color = len >= max ? '#ef4444' : (len >= max * 0.9 ? '#f59e0b' : '#9ca3af');
            };
            ta.addEventListener('input', update);
            update();
        }
        </script>