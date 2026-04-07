<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db   = getDB();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error', 'Invalid task.'); header('Location: index.php'); exit; }

// Fetch task
$taskStmt = $db->prepare("
    SELECT t.*,
           ts.status_name AS status,
           d.dept_name, d.dept_code,
           b.branch_name,
           c.company_name,
           au.auditor_name,
           asgn.full_name AS assigned_to_name
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id  = t.status_id
    LEFT JOIN departments d  ON d.id   = t.department_id
    LEFT JOIN branches    b  ON b.id   = t.branch_id
    LEFT JOIN companies   c  ON c.id   = t.company_id
    LEFT JOIN auditors    au ON au.id  = t.auditor_id
    LEFT JOIN users    asgn  ON asgn.id = t.assigned_to
    WHERE t.id = ? AND t.is_active = 1
");
$taskStmt->execute([$id]);
$task = $taskStmt->fetch();
if (!$task) { setFlash('error', 'Task not found.'); header('Location: index.php'); exit; }

$pageTitle = 'Edit Task: ' . $task['task_number'];

// ── Lookups ───────────────────────────────────────────────────────────────────
$companies = $db->query("
    SELECT id, company_name,
           COALESCE(pan_number,'') AS pan_number,
           COALESCE(company_code,'') AS company_code
    FROM companies
    WHERE is_active=1
    ORDER BY company_name
")->fetchAll();

$allStatuses = $db->query("
    SELECT id, status_name, color, bg_color, icon
    FROM task_status ORDER BY id
")->fetchAll();

$fyList = $db->query("
    SELECT fy_code, fy_label, is_current
    FROM fiscal_years WHERE is_active=1
    ORDER BY fy_code DESC
")->fetchAll();

// Staff for assigned_to — all staff in this task's department
$staffList = $db->prepare("
    SELECT u.id, u.full_name, u.employee_id, b.branch_name
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    LEFT JOIN roles r    ON r.id = u.role_id
    WHERE r.role_name IN ('staff','admin')
      AND u.is_active = 1
      AND u.department_id = ?
    ORDER BY u.full_name
");
$staffList->execute([$task['department_id']]);
$staffList = $staffList->fetchAll();

// Auditors — fiscal-year aware
$fyId = $db->query("SELECT id FROM fiscal_years WHERE is_current=1 LIMIT 1")->fetchColumn();
$audStmt = $db->prepare("
    SELECT a.id, a.auditor_name,
           COALESCE(q.countable_count,   0)                    AS countable_count,
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

$errors = [];

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_task'])) {
    verifyCsrf();

    $title       = trim($_POST['title']        ?? '');
    $desc        = trim($_POST['description']  ?? '');
    $priority    = $_POST['priority']          ?? 'medium';
    $dueDate     = $_POST['due_date']          ?: null;
    $fy          = trim($_POST['fiscal_year']  ?? '');
    $remarks     = trim($_POST['remarks']      ?? '');
    $assignTo    = (int)($_POST['assigned_to'] ?? 0) ?: null;
    $compId      = (int)($_POST['company_id']  ?? 0) ?: null;
    $statusName  = trim($_POST['status']       ?? '');
    $raw = trim($_POST['audit_nature'] ?? '');
    $auditNature = $raw !== '' ? (strtolower($raw) === 'n/a' ? 'N/A' : strtolower($raw)) : null;
    $auditorId   = (int)($_POST['auditor_id']  ?? 0) ?: null;
    if ($auditNature === 'N/A') {
        $auditorId = null;
    }
    // Validate
    if (!$title)      $errors[] = 'Task title is required.';
    if (!$statusName) $errors[] = 'Status is required.';

    $statusId = null;
    if ($statusName) {
        $stRow = $db->prepare("SELECT id FROM task_status WHERE status_name = ?");
        $stRow->execute([$statusName]);
        $statusId = (int)($stRow->fetchColumn() ?: 0);
        if (!$statusId) $errors[] = 'Invalid status selected.';
    }

    // Auditor cap check
    if (!$errors && $auditorId && $auditNature === 'countable') {
        $oldAuditNatureLower = strtolower($task['audit_nature'] ?? '');
        if ($auditorId != (int)$task['auditor_id'] || $auditNature !== $oldAuditNatureLower) {
            $capStmt = $db->prepare("
                SELECT a.auditor_name,
                       COALESCE(q.max_countable_override, a.max_countable) AS cap,
                       COALESCE(q.countable_count, 0)                      AS used
                FROM auditors a
                LEFT JOIN auditor_yearly_quota q
                       ON q.auditor_id = a.id AND q.fiscal_year_id = ?
                WHERE a.id = ?
            ");
            $capStmt->execute([$fyId ?: 0, $auditorId]);
            $capData = $capStmt->fetch();
            if ($capData && (int)$capData['used'] >= (int)$capData['cap']) {
                $errors[] = "Auditor \"{$capData['auditor_name']}\" has reached their limit ({$capData['cap']}).";
            }
        }
    }

    if (!$errors) {
        $oldAuditorId   = (int)($task['auditor_id'] ?? 0);
        $oldAuditNature = strtolower($task['audit_nature'] ?? '');

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

        // Sync auditor_yearly_quota (trigger only fires on INSERT, not UPDATE)
        $auditorChanged = $auditorId  !== $oldAuditorId;
        $natureChanged  = $auditNature !== $oldAuditNature;

        if ($fyId && ($auditorChanged || $natureChanged)) {
            // Decrement old
            if ($oldAuditorId && $oldAuditNature) {
                $col = $oldAuditNature === 'countable' ? 'countable_count' : 'uncountable_count';
                $db->prepare("
                    INSERT INTO auditor_yearly_quota
                        (auditor_id, fiscal_year_id, countable_count, uncountable_count)
                    VALUES (?, ?, 0, 0)
                    ON DUPLICATE KEY UPDATE {$col} = GREATEST(0, {$col} - 1)
                ")->execute([$oldAuditorId, $fyId]);
            }
            // Increment new
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

        // Workflow log if status changed
        if ($statusName !== $task['status']) {
            try {
                $db->prepare("
                    INSERT INTO task_workflow
                    (task_id, action, from_user_id, old_status, new_status, remarks)
                    VALUES (?, 'status_changed', ?, ?, ?, ?)
                ")->execute([$id, $user['id'], $task['status'], $statusName, $remarks]);
            } catch (Exception $e) {}

            if ($task['assigned_to']) {
                notify(
                    $task['assigned_to'],
                    'Task Status Updated',
                    "Task {$task['task_number']} status changed to \"{$statusName}\".",
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

// For repopulation — POST values override DB values
$f = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? array_merge($task, $_POST)
    : $task;

$sidebarRole = $user['role'] === 'executive' ? 'executive' : 'admin';

include '../../includes/header.php';
?>

<!-- Tom Select -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

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
    <input type="hidden" name="csrf_token"  value="<?= csrfToken() ?>">
    <input type="hidden" name="update_task" value="1">

    <div class="row g-4">

        <!-- ── LEFT ── -->
        <div class="col-lg-8">
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-edit text-warning me-2"></i>Edit Details</h5>
                </div>
                <div class="card-mis-body">
                    <div class="row g-3">

                        <!-- Title -->
                        <div class="col-12">
                            <label class="form-label-mis">
                                Title <span class="required-star">*</span>
                            </label>
                            <input type="text" name="title" class="form-control"
                                   value="<?= htmlspecialchars($f['title'] ?? '') ?>" required>
                        </div>

                        <!-- Status -->
                        <div class="col-md-4">
                            <label class="form-label-mis">
                                Status <span class="required-star">*</span>
                            </label>
                            <select name="status" class="form-select">
                                <?php
                                $currentStatus = $f['status'] ?? $task['status'] ?? '';
                                foreach ($allStatuses as $ts):
                                    $sel = $currentStatus === $ts['status_name'] ? 'selected' : '';
                                ?>
                                <option value="<?= htmlspecialchars($ts['status_name']) ?>"
                                        <?= $sel ?>>
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
                                <option value="">-- Select FY --</option>
                                <?php
                                $selectedFy = $f['fiscal_year'] ?? $task['fiscal_year'] ?? '';
                                foreach ($fyList as $fy):
                                    $sel = $selectedFy === $fy['fy_code'] ? 'selected' : '';
                                ?>
                                <option value="<?= htmlspecialchars($fy['fy_code']) ?>"
                                        <?= $sel ?>
                                        <?= $fy['is_current'] ? 'style="font-weight:700;color:#16a34a;"' : '' ?>>
                                    <?= htmlspecialchars($fy['fy_label'] ?: $fy['fy_code']) ?>
                                    <?= $fy['is_current'] ? ' ★ Current' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Company — Tom Select with PAN + code search -->
                        <div class="col-md-8">
                            <label class="form-label-mis">Company / Client</label>
                            <?php
                            // Use POST value on validation error, otherwise DB value
                            $selectedCompany = isset($_POST['company_id'])
                                ? (int)$_POST['company_id']
                                : (int)($task['company_id'] ?? 0);
                            ?>
                            <select name="company_id" id="company_select" class="form-select">
                                <option value="">-- None --</option>
                                <?php foreach ($companies as $c):
                                    $meta = [];
                                    if (!empty($c['pan_number']))  $meta[] = $c['pan_number'];
                                    if (!empty($c['company_code'])) $meta[] = $c['company_code'];
                                    $metaStr = $meta ? ' — ' . implode(' | ', $meta) : '';
                                    $sel = $selectedCompany === (int)$c['id'] ? 'selected' : '';
                                ?>
                                <option value="<?= $c['id'] ?>" <?= $sel ?>
                                        data-meta="<?= htmlspecialchars($metaStr) ?>">
                                    <?= htmlspecialchars($c['company_name'] . $metaStr) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Assigned To — Tom Select -->
                        <div class="col-md-6">
                            <label class="form-label-mis">
                                Assigned To
                                <span style="font-size:.68rem;color:#9ca3af;margin-left:.3rem;">
                                    — <?= htmlspecialchars($task['dept_name']) ?> staff
                                </span>
                            </label>
                            <?php
                            $selectedAssign = isset($_POST['assigned_to'])
                                ? (int)$_POST['assigned_to']
                                : (int)($task['assigned_to'] ?? 0);
                            ?>
                            <select name="assigned_to" id="assigned_to_sel" class="form-select">
                                <option value="">-- Unassigned --</option>
                                <?php foreach ($staffList as $s):
                                    $sel = $selectedAssign === (int)$s['id'] ? 'selected' : '';
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

                        <!-- Audit Nature -->
                        <div class="col-md-3">
                            <label class="form-label-mis">Audit Nature</label>
                            <?php
                            $currentNature = strtolower(
                                $f['audit_nature'] ?? $task['audit_nature'] ?? ''
                            );
                            ?>
                            <select name="audit_nature" id="audit_nature"
                                    class="form-select" onchange="loadAuditors(this.value)">
                                <option value="">-- Select --</option>
                                <option value="countable"
                                    <?= $currentNature === 'countable' ? 'selected' : '' ?>>
                                    Countable
                                </option>
                                <option value="uncountable"
                                    <?= $currentNature === 'uncountable' ? 'selected' : '' ?>>
                                    Uncountable
                                </option>
                                <option value="N/A" <?= strtoupper($currentNature) === 'N/A' ? 'selected' : '' ?>>N/A</option>
                            </select>
                        </div>

                        <!-- Auditor -->
                        <div class="col-md-9" id="auditor-wrap"
                             style="<?= ($currentNature && $currentNature !== 'n/a') ? '' : 'display:none;' ?>">
                            <label class="form-label-mis">
                                Auditor
                                <span id="auditor-limit-note"
                                      style="font-size:.72rem;color:#9ca3af;margin-left:.3rem;">
                                    <?= $currentNature === 'countable' ? '(limit applies)' : '' ?>
                                </span>
                            </label>
                            <select name="auditor_id" id="auditor_id" class="form-select">
                                <option value="">-- Select Auditor --</option>
                                <?php
                                $selectedAuditor = (int)($task['auditor_id'] ?? 0);
                                foreach ($currentAuditors as $a):
                                    $atLimit = $currentNature === 'countable'
                                        && (int)$a['countable_count'] >= (int)$a['max_limit']
                                        && $a['id'] != $selectedAuditor;

                                    $count = $currentNature === 'countable'
                                        ? $a['countable_count']
                                        : $a['uncountable_count'];

                                    $label = $a['auditor_name'];
                                    if ($currentNature === 'countable')
                                        $label .= " ({$a['countable_count']} / {$a['max_limit']})";
                                    else
                                        $label .= " ({$a['uncountable_count']})";
                                    if ($atLimit) $label .= ' — FULL';
                                ?>
                                <option value="<?= $a['id'] ?>"
                                        <?= $selectedAuditor === (int)$a['id'] ? 'selected' : '' ?>
                                        <?= $atLimit ? 'disabled' : '' ?>
                                        data-countable="<?= $a['countable_count'] ?>"
                                        data-uncountable="<?= $a['uncountable_count'] ?>"
                                        data-limit="<?= $a['max_limit'] ?>">
                                    <?= htmlspecialchars($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Capacity bar -->
                            <div id="auditor-capacity" style="margin-top:.4rem;display:none;">
                                <div class="d-flex justify-content-between mb-1"
                                     style="font-size:.72rem;color:#6b7280;">
                                    <span id="capacity-label">Capacity</span>
                                    <span id="capacity-text"></span>
                                </div>
                                <div style="background:#f3f4f6;border-radius:99px;height:5px;">
                                    <div id="capacity-bar"
                                         style="height:100%;border-radius:99px;
                                                background:#10b981;transition:.3s;width:0%;"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="col-12">
                            <label class="form-label-mis">Description</label>
                            <textarea name="description" class="form-control"
                                      rows="3"><?= htmlspecialchars($f['description'] ?? '') ?></textarea>
                        </div>

                        <!-- Remarks -->
                        <div class="col-12">
                            <label class="form-label-mis">Remarks</label>
                            <textarea name="remarks" class="form-control"
                                      rows="2"><?= htmlspecialchars($f['remarks'] ?? '') ?></textarea>
                        </div>

                    </div>
                </div>
            </div>

            <div class="d-flex gap-3">
                <button type="submit" name="update_task" value="1" class="btn btn-gold">
                    <i class="fas fa-save me-2"></i>Save Changes
                </button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>

        <!-- ── RIGHT ── -->
        <div class="col-lg-4">

            <!-- Task meta -->
            <div class="card-mis mb-3 p-3" style="font-size:.82rem;color:#6b7280;">
                <div class="mb-2">
                    <strong>Task #:</strong> <?= htmlspecialchars($task['task_number']) ?>
                </div>
                <div class="mb-2">
                    <strong>Department:</strong> <?= htmlspecialchars($task['dept_name'] ?? '—') ?>
                </div>
                <div class="mb-2">
                    <strong>Branch:</strong> <?= htmlspecialchars($task['branch_name'] ?? '—') ?>
                </div>
                <div class="mb-2">
                    <strong>Current Status:</strong>
                    <span style="font-weight:600;">
                        <?= htmlspecialchars($task['status'] ?? '—') ?>
                    </span>
                </div>
                <?php if ($task['auditor_name']): ?>
                <div class="mb-2">
                    <strong>Auditor:</strong> <?= htmlspecialchars($task['auditor_name']) ?>
                </div>
                <div class="mb-2">
                    <strong>Audit Nature:</strong>
                    <span style="text-transform:capitalize;">
                        <?= htmlspecialchars($task['audit_nature'] ?? '—') ?>
                    </span>
                </div>
                <?php endif; ?>
                <div style="color:#9ca3af;font-size:.75rem;margin-top:.5rem;
                            padding:.5rem;background:#f9fafb;border-radius:6px;">
                    <i class="fas fa-info-circle me-1"></i>
                    Department and Branch are locked. Use Transfer to move task.
                </div>
            </div>

            <!-- Status reference -->
            <div class="card-mis p-3" style="border-left:3px solid var(--gold);">
                <p class="mb-2" style="font-size:.8rem;font-weight:600;">Status Reference</p>
                <?php foreach ($allStatuses as $ts):
                    $color = $ts['color']    ?? '#9ca3af';
                    $bg    = $ts['bg_color'] ?? '#f3f4f6';
                    $icon  = $ts['icon']     ?? 'fa-circle-dot';
                ?>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span style="background:<?= $bg ?>;color:<?= $color ?>;
                                 padding:.15rem .5rem;border-radius:99px;
                                 font-size:.72rem;font-weight:600;
                                 display:inline-flex;align-items:center;gap:.3rem;">
                        <i class="fas <?= htmlspecialchars($icon) ?>" style="font-size:.65rem;"></i>
                        <?= htmlspecialchars($ts['status_name']) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>
</form>

</div>
</div>
</div>

<script>
// ── Tom Select: Company ───────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    new TomSelect('#company_select', {
        placeholder: 'Search company, PAN or code…',
        allowEmptyOption: true,
        maxOptions: 500,
        searchField: ['text'],
        render: {
            option: function(data, escape) {
                const parts  = data.text.split(' — ');
                const name   = parts[0] || '';
                const meta   = parts[1] || '';
                return `<div style="padding:.4rem .2rem;">
                    <div style="font-weight:600;font-size:.87rem;">${escape(name)}</div>
                    ${meta ? `<div style="font-size:.75rem;color:#6b7280;">${escape(meta)}</div>` : ''}
                </div>`;
            },
            item: function(data, escape) {
                const name = data.text.split(' — ')[0];
                return `<div>${escape(name)}</div>`;
            }
        }
    });

    // ── Tom Select: Assigned To ───────────────────────────────────────────────
    new TomSelect('#assigned_to_sel', {
        placeholder: 'Search staff by name or ID…',
        allowEmptyOption: true,
        maxOptions: 300,
        searchField: ['text'],
        render: {
            option: function(data, escape) {
                const parts  = data.text.split(' — ');
                const name   = parts[0] || '';
                const branch = parts[1] || '';
                return `<div style="padding:.4rem .2rem;">
                    <div style="font-weight:600;font-size:.87rem;">${escape(name)}</div>
                    ${branch ? `<div style="font-size:.75rem;color:#6b7280;">${escape(branch)}</div>` : ''}
                </div>`;
            },
            item: function(data, escape) {
                const name = data.text.split(' — ')[0];
                return `<div>${escape(name)}</div>`;
            }
        }
    });

    // Init capacity bar if auditor already selected
    const nature = document.getElementById('audit_nature').value;
    if (nature) {
        document.getElementById('auditor-wrap').style.display = 'block';
        document.getElementById('auditor-limit-note').textContent =
            nature === 'countable' ? '(limit applies)' : '(no limit)';
        updateCapacityBar();
    }
});

// ── Load auditors via AJAX on nature change ───────────────────────────────────
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
            sel.innerHTML = '<option value="">-- Select Auditor --</option>';
            data.forEach(a => {
                const atLimit = nature === 'countable' && a.at_limit;
                const label   = nature === 'countable'
                    ? `${a.auditor_name} (${a.countable_count} / ${a.max_limit})${atLimit ? ' — FULL' : ''}`
                    : `${a.auditor_name} (${a.uncountable_count} tasks)`;

                const opt         = document.createElement('option');
                opt.value         = a.id;
                opt.text          = label;
                opt.disabled      = atLimit;
                opt.dataset.countable   = a.countable_count;
                opt.dataset.uncountable = a.uncountable_count;
                opt.dataset.limit       = a.max_limit;
                sel.appendChild(opt);
            });
            updateCapacityBar();
        })
        .catch(() => {
            sel.innerHTML = '<option value="">Error loading auditors</option>';
        });
}

// ── Capacity bar ──────────────────────────────────────────────────────────────
function updateCapacityBar() {
    const nature  = document.getElementById('audit_nature').value;
    const sel     = document.getElementById('auditor_id');
    const capDiv  = document.getElementById('auditor-capacity');
    const opt     = sel.options[sel.selectedIndex];

    if (!opt || !opt.value || !nature) { capDiv.style.display = 'none'; return; }

    const used  = parseInt(opt.dataset.countable  || 0);
    const limit = parseInt(opt.dataset.limit || 0);
    const unc   = parseInt(opt.dataset.uncountable || 0);

    if (nature === 'uncountable') {
        document.getElementById('capacity-label').textContent = 'Uncountable tasks assigned';
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

document.getElementById('auditor_id').addEventListener('change', updateCapacityBar);
</script>

<?php include '../../includes/footer.php'; ?>