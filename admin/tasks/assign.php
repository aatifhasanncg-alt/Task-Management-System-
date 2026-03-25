<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAdmin();

$db   = getDB();
$user = currentUser();

$adminStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$adminStmt->execute([$user['id']]);
$adminUser = $adminStmt->fetch();

$pageTitle = 'Assign Task';

$fys = $db->query("
    SELECT id, fy_code, fy_label, is_current
    FROM fiscal_years
    WHERE is_active = 1
    ORDER BY fy_code DESC
")->fetchAll(PDO::FETCH_ASSOC);

$currentFy = '';
foreach ($fys as $fy) {
    if ($fy['is_current']) { $currentFy = $fy['fy_code']; break; }
}
if (!$currentFy && !empty($fys)) $currentFy = $fys[0]['fy_code'];

// ── Dropdowns ─────────────────────────────────────────────────────────────────
if (isExecutive()) {
    $depts     = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY dept_name")->fetchAll();
    $branches  = $db->query("SELECT * FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
    $companies = $db->query("SELECT id, company_name FROM companies WHERE is_active=1 ORDER BY company_name")->fetchAll();
} else {
    $deptStmt = $db->prepare("SELECT * FROM departments WHERE id = ? AND is_active = 1");
    $deptStmt->execute([$adminUser['department_id']]);
    $depts = $deptStmt->fetchAll();

    // Admin can see all branches
    $branches = $db->query("SELECT * FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();

    // Companies from admin's own branch only
    $companiesStmt = $db->prepare("SELECT id, company_name FROM companies WHERE is_active=1 AND branch_id = ? ORDER BY company_name");
    $companiesStmt->execute([$adminUser['branch_id']]);
    $companies = $companiesStmt->fetchAll();
}

// ── Staff list — dept-wide, grouped by branch ─────────────────────────────────
if (isExecutive()) {
    $staffList = $db->query("
        SELECT u.*, b.branch_name, d.dept_name FROM users u
        LEFT JOIN branches b    ON b.id = u.branch_id
        LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN roles r       ON r.id = u.role_id
        WHERE r.role_name = 'staff' AND u.is_active = 1
        ORDER BY u.full_name
    ")->fetchAll();
} else {
    $st = $db->prepare("
        SELECT u.*, b.branch_name, d.dept_name FROM users u
        LEFT JOIN branches b    ON b.id = u.branch_id
        LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN roles r       ON r.id = u.role_id
        WHERE r.role_name     = 'staff'
          AND u.is_active     = 1
          AND u.department_id = ?
        ORDER BY b.branch_name, u.full_name
    ");
    $st->execute([$adminUser['department_id']]);
    $staffList = $st->fetchAll();
}

$errors = [];

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title    = trim($_POST['title']       ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $priority = $_POST['priority']         ?? 'medium';
    $dueDate  = $_POST['due_date']         ?? null;
    $fy       = trim($_POST['fiscal_year'] ?? $currentFy);
    $remarks  = trim($_POST['remarks']     ?? '');
    $assignTo = (int)($_POST['assigned_to']  ?? 0) ?: null;
    $compId   = (int)($_POST['company_id']   ?? 0) ?: null;
    $status   = $_POST['status']             ?? 'Not Started';

    if (isExecutive()) {
        $deptId   = (int)($_POST['department_id'] ?? 0);
        $branchId = (int)($_POST['branch_id']     ?? 0);
    } else {
        $deptId   = (int)$adminUser['department_id'];
        $branchId = (int)$adminUser['branch_id'];
    }

    // Dept code for task number trigger
    $deptCode = '';
    foreach ($depts as $d) {
        if ($d['id'] == $deptId) { $deptCode = $d['dept_code']; break; }
    }

    // Validate
    if (!$title)    $errors[] = 'Task title is required.';
    if (!$deptId)   $errors[] = 'Department is required.';
    if (!$branchId) $errors[] = 'Branch is required.';
    if (!$fy)       $errors[] = 'Fiscal year is required.';

    // Validate FY is in our list
    $validFys = array_column($fys, 'fy_code');
    if ($fy && !in_array($fy, $validFys)) $errors[] = 'Selected fiscal year is invalid.';

    if (!isExecutive()) {
        if ($deptId !== (int)$adminUser['department_id'])
            $errors[] = 'You can only assign tasks to your own department.';

        if ($assignTo) {
            $staffCheck = $db->prepare("
                SELECT id FROM users
                WHERE id = ? AND department_id = ? AND is_active = 1
            ");
            $staffCheck->execute([$assignTo, $adminUser['department_id']]);
            if (!$staffCheck->fetch())
                $errors[] = 'Selected staff does not belong to your department.';
        }
    }

    $validStatuses = array_column(
        $db->query("SELECT status_name FROM task_status")->fetchAll(),
        'status_name'
    );
    if (!in_array($status, $validStatuses)) $errors[] = 'Invalid status.';

    if (!$errors) {
        $statusRow = $db->prepare("SELECT id FROM task_status WHERE status_name = ?");
        $statusRow->execute([$status]);
        $statusId = (int)($statusRow->fetchColumn() ?: 1);

        $ins = $db->prepare("
            INSERT INTO tasks
            (title, description, department_id, branch_id, company_id,
             created_by, assigned_to, status_id, priority,
             due_date, fiscal_year, remarks)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $title, $desc, $deptId, $branchId, $compId,
            $user['id'], $assignTo, $statusId, $priority,
            $dueDate ?: null, $fy, $remarks,
        ]);
        $taskId = $db->lastInsertId();

        // Notify assigned staff
        if ($assignTo) {
            // Fetch task_number generated by DB trigger after insert
            $tnStmt = $db->prepare("SELECT task_number FROM tasks WHERE id = ?");
            $tnStmt->execute([$taskId]);
            $taskNumber = $tnStmt->fetchColumn() ?: "T-{$taskId}";

            // Fetch company name if linked
            $companyName = '';
            if ($compId) {
                $cnStmt = $db->prepare("SELECT company_name FROM companies WHERE id = ?");
                $cnStmt->execute([$compId]);
                $companyName = $cnStmt->fetchColumn() ?: '';
            }

            // Fetch assigned staff name
            $staffNameStmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ?");
            $staffNameStmt->execute([$assignTo]);
            $staffRow = $staffNameStmt->fetch();

            // Build message with full context
            $notifMessage = "Task #{$taskNumber}";
            if ($companyName) $notifMessage .= " — {$companyName}";
            $notifMessage .= " has been assigned to you";
            if ($dueDate) $notifMessage .= " · Due " . date('M j, Y', strtotime($dueDate));
            $notifMessage .= ".";

            notify(
                $assignTo,
                "New Task: {$title}",
                $notifMessage,
                'task',
                APP_URL . '/staff/tasks/view.php?id=' . $taskId,
                true,          // send email
                [
                    'template'     => 'task_assigned',
                    'task_number'  => $taskNumber,
                    'company_name' => $companyName,
                    'task'         => [
                        'id'          => $taskId,
                        'task_number' => $taskNumber,
                        'title'       => $title,
                        'department'  => $deptCode,
                        'status'      => $status,
                        'priority'    => $priority,
                        'due_date'    => $dueDate,
                        'fiscal_year' => $fy,
                        'company'     => $companyName,
                        'remarks'     => $remarks,
                    ],
                ]
            );
        }

        logActivity("Assigned task: {$title}", 'tasks', "id={$taskId}");
        setFlash('success', "Task assigned successfully! Add department-specific details below.");
        header('Location: view.php?id=' . $taskId);
        exit;
    }
}

// For re-populating on validation error
$selectedFy    = $_POST['fiscal_year'] ?? $currentFy;
$allStatusOpts = $db->query("SELECT status_name FROM task_status ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php include '../../includes/sidebar_admin.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<div class="page-hero">
    <div class="page-hero-badge"><i class="fas fa-plus-circle"></i> New Task</div>
    <h4>Assign Task</h4>
    <p>Create the task here — then fill department-specific details on the <strong>task view page</strong>.</p>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger rounded-3">
    <strong>Please fix:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?= flashHtml() ?>

<form method="POST" id="assignForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

    <div class="row g-4">
    <div class="col-lg-8">
        <div class="card-mis mb-4">
            <div class="card-mis-header">
                <h5><i class="fas fa-info-circle text-warning me-2"></i>Task Details</h5>
            </div>
            <div class="card-mis-body">
                <div class="row g-3">

                    <!-- Title -->
                    <div class="col-12">
                        <label class="form-label-mis">Task Title <span class="required-star">*</span></label>
                        <input type="text" name="title" class="form-control"
                               placeholder="e.g. VAT Filing for Alpha Retail — FY 2081/82"
                               value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                    </div>

                    <!-- Department -->
                    <div class="col-md-4">
                        <label class="form-label-mis">Department <span class="required-star">*</span></label>
                        <?php if (isExecutive()): ?>
                        <select name="department_id" class="form-select" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($depts as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= ($_POST['department_id'] ?? '') == $d['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['dept_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="hidden" name="department_id" value="<?= $adminUser['department_id'] ?>">
                        <input type="text" class="form-control"
                               value="<?= htmlspecialchars($depts[0]['dept_name'] ?? '') ?>"
                               readonly style="background:#f9fafb;cursor:not-allowed;">
                        <?php endif; ?>
                    </div>

                    <!-- Branch -->
                    <div class="col-md-4">
                        <label class="form-label-mis">Branch <span class="required-star">*</span></label>
                        <?php if (isExecutive()): ?>
                        <select name="branch_id" class="form-select" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($branches as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= ($_POST['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['branch_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="hidden" name="branch_id" value="<?= $adminUser['branch_id'] ?>">
                        <input type="text" class="form-control"
                               value="<?= htmlspecialchars($branches[array_search($adminUser['branch_id'], array_column($branches,'id'))]['branch_name'] ?? '') ?>"
                               readonly style="background:#f9fafb;cursor:not-allowed;">
                        <?php endif; ?>
                    </div>

                    <!-- Company -->
                    <div class="col-md-4">
                        <label class="form-label-mis">Company / Client</label>
                        <select name="company_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach ($companies as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($_POST['company_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['company_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status -->
                    <div class="col-md-4">
                        <label class="form-label-mis">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach ($allStatusOpts as $sn):
                                if ($sn === 'Corporate Team') continue; ?>
                            <option value="<?= htmlspecialchars($sn) ?>"
                                <?= ($_POST['status'] ?? 'Not Started') === $sn ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sn) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Priority -->
                    <div class="col-md-4">
                        <label class="form-label-mis">Priority</label>
                        <select name="priority" class="form-select">
                            <?php foreach (TASK_PRIORITIES as $key => $p): ?>
                            <option value="<?= $key ?>" <?= ($_POST['priority'] ?? 'medium') === $key ? 'selected' : '' ?>>
                                <?= $p['label'] ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Due Date -->
                    <div class="col-md-4">
                        <label class="form-label-mis">Due Date</label>
                        <input type="date" name="due_date" class="form-control"
                               value="<?= htmlspecialchars($_POST['due_date'] ?? '') ?>">
                    </div>

                    <!-- ── Fiscal Year — SELECT from fiscal_years DB table ──── -->
                    <div class="col-md-4">
                        <label class="form-label-mis">
                            Fiscal Year <span class="required-star">*</span>
                        </label>
                        <select name="fiscal_year" class="form-select" required>
                            <option value="">-- Select FY --</option>
                            <?php foreach ($fys as $fy): ?>
                            <option
                                value="<?= htmlspecialchars($fy['fy_code']) ?>"
                                <?= $selectedFy === $fy['fy_code'] ? 'selected' : '' ?>
                                <?= $fy['is_current'] ? 'style="font-weight:700;color:#16a34a;"' : '' ?>>
                                <?= htmlspecialchars($fy['fy_label'] ?: $fy['fy_code']) ?>
                                <?= $fy['is_current'] ? ' ★' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="font-size:.65rem;color:#9ca3af;">
                            <i class="fas fa-database me-1"></i>From fiscal_years table · ★ = current year
                        </small>
                    </div>

                    <!-- Assign To — dept-wide, grouped by branch -->
                    <div class="col-md-8">
                        <label class="form-label-mis">
                            Assign To
                        </label>
                        <select name="assigned_to" class="form-select">
                            <option value="">-- Unassigned --</option>
                            <?php
                            $byBranch = [];
                            foreach ($staffList as $s) {
                                $byBranch[$s['branch_name']][] = $s;
                            }
                            foreach ($byBranch as $brName => $staffInBranch):
                            ?>
                            <optgroup label="<?= htmlspecialchars($brName) ?>">
                                <?php foreach ($staffInBranch as $s): ?>
                                <option value="<?= $s['id'] ?>"
                                    <?= ($_POST['assigned_to'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['full_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label>Audit Nature</label>
                        <select id="audit_nature" class="form-select" onchange="loadAuditors()">
                            <option value="">-- Select --</option>
                            <option value="countable">Countable</option>
                            <option value="uncountable">Uncountable</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label>Auditor</label>
                        <select id="auditor_id" name="auditor_id" class="form-select">
                            <option value="">-- Select Auditor --</option>
                        </select>
                    </div>

                    <!-- Description -->
                    <div class="col-12">
                        <label class="form-label-mis">Description</label>
                        <textarea name="description" class="form-control" rows="2"
                                  placeholder="Brief description of the task"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    </div>

                    <!-- Remarks -->
                    <div class="col-12">
                        <label class="form-label-mis">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2"
                                  placeholder="Any internal remarks"><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">

        <div class="card-mis mb-3">
            <div class="card-mis-header"><h5>Actions</h5></div>
            <div class="card-mis-body">
                <button type="submit" class="btn-gold btn w-100 mb-2">
                    <i class="fas fa-save me-2"></i>Assign Task
                </button>
                <a href="index.php" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-times me-2"></i>Cancel
                </a>
            </div>
        </div>

        <!-- Current FY badge -->
        <div class="card-mis mb-3" style="border-left:3px solid #16a34a;">
            <div class="card-mis-body" style="padding:.85rem 1rem;">
                <div style="font-size:.72rem;font-weight:700;color:#16a34a;text-transform:uppercase;
                            letter-spacing:.05em;margin-bottom:.35rem;">
                    <i class="fas fa-calendar-check me-1"></i>Current Fiscal Year
                </div>
                <div style="font-size:1.1rem;font-weight:800;color:#1f2937;">
                    <?= htmlspecialchars($currentFy) ?>
                </div>
                <div style="font-size:.68rem;color:#9ca3af;margin-top:.2rem;">
                    Auto-selected · change if filing for another year
                </div>
            </div>
        </div>

        <!-- Next steps -->
        <div class="card-mis mb-3" style="border-left:3px solid #3b82f6;">
            <div class="card-mis-body" style="padding:1rem;">
                <p style="font-size:.8rem;font-weight:700;color:#3b82f6;margin-bottom:.6rem;">
                    <i class="fas fa-route me-1"></i>After Assigning
                </p>
                <div style="font-size:.78rem;color:#374151;line-height:1.7;">
                    <div style="display:flex;align-items:baseline;gap:.4rem;margin-bottom:.35rem;">
                        <span style="background:#0a0f1e;color:#c9a84c;padding:.1rem .45rem;
                                     border-radius:4px;font-size:.68rem;font-weight:700;flex-shrink:0;">
                            <i class="fas fa-eye me-1"></i>VIEW
                        </span>
                        Fill dept-specific fields (Tax details, Banking checklist, Finance payment, etc.) and update work status
                    </div>
                    <div style="display:flex;align-items:baseline;gap:.4rem;">
                        <span style="background:#f59e0b;color:#fff;padding:.1rem .45rem;
                                     border-radius:4px;font-size:.68rem;font-weight:700;flex-shrink:0;">
                            <i class="fas fa-pen me-1"></i>EDIT
                        </span>
                        Change title, priority, due date, reassign staff, switch company, update remarks
                    </div>
                </div>
            </div>
        </div>

        <!-- Status reference -->
        <div class="card-mis p-3" style="border-left:3px solid var(--gold);">
            <p style="font-size:.8rem;font-weight:600;margin-bottom:.5rem;">Status Reference</p>
            <?php foreach ($allStatusOpts as $sn):
                if ($sn === 'Corporate Team') continue;
                $col = defined('TASK_STATUSES') && isset(TASK_STATUSES[$sn]) ? TASK_STATUSES[$sn]['color'] : '#9ca3af';
            ?>
            <div class="d-flex align-items-center gap-2 mb-1">
                <div style="width:8px;height:8px;border-radius:50%;background:<?= $col ?>;flex-shrink:0;"></div>
                <span style="font-size:.78rem;color:#6b7280;"><?= htmlspecialchars($sn) ?></span>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
    </div><!-- row -->
</form>

</div><!-- padding -->
</div><!-- main-content -->
</div><!-- app-wrapper -->
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
                let count = 0;

                if (nature === 'countable') {
                    count = a.countable_count;
                } else if (nature === 'uncountable') {
                    count = a.uncountable_count;
                }

                select.innerHTML += `
                    <option value="${a.id}">
                        ${a.name} (${count})
                    </option>
                `;
            });
        });
}
</script>
<?php include '../../includes/footer.php'; ?>