<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireManager();

$db = getDB();
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
    if ($fy['is_current']) {
        $currentFy = $fy['fy_code'];
        break;
    }
}
if (!$currentFy && !empty($fys))
    $currentFy = $fys[0]['fy_code'];

// ── Dropdowns ─────────────────────────────────────────────────────────────────
// ── Detect branch manager ──────────────────────────────────────────────────
$adminDeptCodeStmt = $db->prepare("SELECT d.dept_code FROM departments d WHERE d.id = ?");
$adminDeptCodeStmt->execute([$adminUser['department_id']]);
$adminDeptCodeCheck = $adminDeptCodeStmt->fetchColumn() ?: '';
$isBranchManager = ($adminDeptCodeCheck === 'CORE');
$crossDepts = CROSS_DEPT_ASSIGN[$adminUser['id']] ?? [];

// UDA managers can assign to any branch
$udaBranchCheck = $db->prepare("SELECT COUNT(*) FROM user_department_assignments WHERE user_id = ?");
$udaBranchCheck->execute([$adminUser['id']]);
$hasUdaDepts = (int) $udaBranchCheck->fetchColumn() > 0;
$canAssignAnyBranch = $hasUdaDepts && !$isBranchManager;

// Check UDA early — needed for USE_AJAX_STAFF JS constant
$udaCheck = $db->prepare("SELECT COUNT(*) FROM user_department_assignments WHERE user_id = ?");
$udaCheck->execute([$adminUser['id']]);
$hasUda = (int) $udaCheck->fetchColumn() > 0;

// Never use hasCrossDeptAccess when UDA exists — handle both together in else branch
$hasCrossDeptAccess = !empty($crossDepts) && !$isBranchManager && !isExecutive() && !$hasUda;

// ── Dropdowns ─────────────────────────────────────────────────────────────
if (isExecutive()) {
    $depts = $db->query("SELECT * FROM departments WHERE is_active=1 AND dept_code NOT IN ('CON','CORE') ORDER BY dept_name")->fetchAll();
    $branches = $db->query("SELECT * FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
    $companies = $db->query("
        SELECT id, company_name,
            COALESCE(pan_number,'')   AS pan_number,
            COALESCE(company_code,'') AS company_code
        FROM companies WHERE is_active=1 AND branch_id = {$adminUser['branch_id']}
        ORDER BY company_name
    ")->fetchAll();
} elseif ($isBranchManager) {
    // Branch Manager: can pick ANY dept, but branch is locked to theirs
    $depts = $db->query("
        SELECT * FROM departments 
        WHERE is_active=1 AND dept_code NOT IN ('CON','CORE')
        ORDER BY dept_name
    ")->fetchAll();
    $branches = $db->query("SELECT * FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
    $companiesStmt = $db->prepare("
        SELECT id, company_name, pan_number, company_code FROM companies
        WHERE is_active=1 AND branch_id = ?
        ORDER BY company_name
    ");
    $companiesStmt->execute([$adminUser['branch_id']]);
    $companies = $companiesStmt->fetchAll();
} elseif ($hasCrossDeptAccess) {
    // Own dept + permitted depts
    $allowedCodes = array_merge([$adminDeptCodeCheck], $crossDepts);
    $inList = implode(',', array_fill(0, count($allowedCodes), '?'));
    $deptStmt = $db->prepare("
        SELECT * FROM departments 
        WHERE is_active = 1 AND dept_code IN ({$inList})
        ORDER BY dept_name
    ");
    $deptStmt->execute($allowedCodes);
    $depts = $deptStmt->fetchAll();

    $branches = $db->query("SELECT * FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();

    // Branch is locked to the admin's own branch for cross-dept-config admins
    // (they are NOT branch managers and NOT UDA-any-branch), so companies
    // must be scoped to that same branch — otherwise the dropdown showed
    // every company across every branch regardless of where the task lands.
    $companiesStmt = $db->prepare("
        SELECT id, company_name, pan_number, company_code FROM companies
        WHERE is_active=1 AND branch_id = ?
        ORDER BY company_name
    ");
    $companiesStmt->execute([$adminUser['branch_id']]);
    $companies = $companiesStmt->fetchAll();
} else {
    // Own dept + UDA depts + any crossDept config depts (all merged)
    $crossDeptIds = [];
    if (!empty($crossDepts)) {
        $cpStmt = $db->prepare(
            "SELECT id FROM departments WHERE dept_code IN (" .
            implode(',', array_fill(0, count($crossDepts), '?')) .
            ") AND is_active = 1"
        );
        $cpStmt->execute($crossDepts);
        $crossDeptIds = array_column($cpStmt->fetchAll(), 'id');
    }

    $deptStmt = $db->prepare("
        SELECT DISTINCT d.*,
            CASE WHEN d.id = ? THEN 1 ELSE 0 END AS is_own_dept
        FROM departments d
        WHERE d.is_active = 1
          AND d.dept_code NOT IN ('CON','CORE')
          AND (
              d.id = ?
              OR d.id IN (
                  SELECT department_id
                  FROM user_department_assignments
                  WHERE user_id = ?
              )
              " . (!empty($crossDeptIds) ?
        "OR d.id IN (" . implode(',', array_fill(0, count($crossDeptIds), '?')) . ")"
        : "") . "
          )
        ORDER BY is_own_dept DESC, d.dept_name ASC
    ");

    $params = [
        $adminUser['department_id'],  // CASE
        $adminUser['department_id'],  // own dept
        $adminUser['id'],             // UDA
    ];
    if (!empty($crossDeptIds)) {
        $params = array_merge($params, $crossDeptIds);
    }

    $deptStmt->execute($params);
    $depts = $deptStmt->fetchAll();

    $branches = $db->query("SELECT * FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
    if ($canAssignAnyBranch) {
        // UDA manager can see all companies across branches
        $companiesStmt = $db->prepare("SELECT id, company_name, pan_number, company_code FROM companies WHERE is_active=1 ORDER BY company_name");
        $companiesStmt->execute();
    } else {
        $companiesStmt = $db->prepare("SELECT id, company_name, pan_number, company_code FROM companies WHERE is_active=1 AND branch_id = ? ORDER BY company_name");
        $companiesStmt->execute([$adminUser['branch_id']]);
    }
    $companies = $companiesStmt->fetchAll();
}

// ── Staff list ────────────────────────────────────────────────────────────
// CORE admin and executive: use AJAX (dept-filtered). Only pre-populate on POST error.
// Regular admin: always server-side (dept+branch fixed).
$staffList = [];

if (!isExecutive() && !$isBranchManager) {
    if ($hasCrossDeptAccess) {
        // AJAX will handle it — just pre-load own dept staff for initial render
        $st = $db->prepare("
            SELECT DISTINCT u.id, u.full_name, u.employee_id, b.branch_name, d.dept_name
            FROM users u
            LEFT JOIN branches    b   ON b.id = u.branch_id
            LEFT JOIN departments d   ON d.id = u.department_id
            LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
            JOIN roles            r   ON r.id = u.role_id
            WHERE r.role_name IN ('staff','admin','manager')
              AND u.is_active  = 1
              AND (
                  u.department_id = ?
                  OR uda.department_id = ?
              )
            ORDER BY u.full_name
        ");
        $st->execute([$adminUser['department_id'], $adminUser['department_id']]);
        $staffList = $st->fetchAll();
    } else {
        $initialDeptId = (int) (($_POST['department_id'] ?? 0) ?: $adminUser['department_id']);

        $st = $db->prepare("
            SELECT
                u.id, u.full_name, u.employee_id, b.branch_name,
                d.dept_name AS primary_dept_name, d.dept_code AS primary_dept_code,
                NULL AS secondary_dept_name, 'primary' AS match_type
            FROM users u
            LEFT JOIN branches    b ON b.id = u.branch_id
            LEFT JOIN departments d ON d.id = u.department_id
            JOIN roles            r ON r.id = u.role_id
            WHERE r.role_name IN ('staff','admin','manager')
              AND u.is_active     = 1
              AND u.department_id = ?
            
            UNION
            
            SELECT
                u.id, u.full_name, u.employee_id, b.branch_name,
                uda_d.dept_name AS primary_dept_name, d.dept_code AS primary_dept_code,
                d.dept_name AS secondary_dept_name, 'secondary' AS match_type
            FROM users u
            LEFT JOIN branches    b     ON b.id = u.branch_id
            LEFT JOIN departments d     ON d.id = u.department_id
            JOIN user_department_assignments uda  ON uda.user_id = u.id
            JOIN departments uda_d ON uda_d.id = uda.department_id
            JOIN roles        r    ON r.id = u.role_id
            WHERE r.role_name IN ('staff','admin','manager')
              AND u.is_active       = 1
              AND uda.department_id = ?
              AND u.department_id  != ?
            
            ORDER BY full_name
            ");
        $st->execute([
            $initialDeptId,   // own dept
            $initialDeptId,   // UDA dept
            $initialDeptId,
        ]);
        $staffList = $st->fetchAll();
    }
}

$errors = [];

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $dueDate = $_POST['due_date'] ?? null;
    $fy = trim($_POST['fiscal_year'] ?? $currentFy);
    $remarks = trim($_POST['remarks'] ?? '');
    $assignTo = (int) ($_POST['assigned_to'] ?? 0) ?: null;
    $compId = (int) ($_POST['company_id'] ?? 0) ?: null;
    $status = $_POST['status'] ?? 'Not Started';
    // Normalize to lowercase to match ENUM('countable','uncountable')
    $raw = trim($_POST['audit_nature'] ?? '');
    $auditNature = $raw !== '' ? (strtolower($raw) === 'n/a' ? 'N/A' : strtolower($raw)) : null;
    $auditorId = (int) ($_POST['auditor_id'] ?? 0) ?: null;

    if (isExecutive()) {
        $deptId = (int) ($_POST['department_id'] ?? 0);
        $branchId = (int) ($_POST['branch_id'] ?? 0);
    } elseif ($isBranchManager) {
        $deptId = (int) ($_POST['department_id'] ?? 0); // selectable
        $branchId = (int) $adminUser['branch_id'];          // always locked
    } elseif ($hasCrossDeptAccess) {
        $deptId = (int) ($_POST['department_id'] ?? $adminUser['department_id']);
        $branchId = (int) ($_POST['branch_id'] ?? 0) ?: (int) $adminUser['branch_id']; // now selectable
    } else {
        // Regular admin with potentially multi-dept — read from POST if available
        $deptId = (int) (($_POST['department_id'] ?? 0) ?: $adminUser['department_id']);
        $branchId = (int) ($_POST['branch_id'] ?? 0) ?: (int) $adminUser['branch_id']; // now selectable
    }
    $deptCode = '';
    foreach ($depts as $d) {
        if ($d['id'] == $deptId) {
            $deptCode = $d['dept_code'];
            break;
        }
    }

    // ── Validate ──────────────────────────────────────────────────────────────
    if (!$title)
        $errors[] = 'Task title is required.';
    if (!$deptId)
        $errors[] = 'Department is required.';
    if (!$branchId)
        $errors[] = 'Branch is required.';
    if (!$fy)
        $errors[] = 'Fiscal year is required.';

    $validFys = array_column($fys, 'fy_code');
    if ($fy && !in_array($fy, $validFys))
        $errors[] = 'Selected fiscal year is invalid.';

    if (!isExecutive() && !$isBranchManager) {
        if ($hasCrossDeptAccess) {
            $allowedCodes = array_merge([$adminDeptCodeCheck], $crossDepts);
            if (!in_array($deptCode, $allowedCodes))
                $errors[] = 'You are not permitted to assign tasks to that department.';
        } else {
            if ($deptId !== (int) $adminUser['department_id']) {
                // Check UDA rows
                $udaDeptCheck = $db->prepare("
            SELECT COUNT(*) FROM user_department_assignments
            WHERE user_id = ? AND department_id = ?
        ");
                $udaDeptCheck->execute([$user['id'], $deptId]);
                $inUda = (int) $udaDeptCheck->fetchColumn() > 0;

                // Check crossDept config
                $inCross = false;
                if (!empty($crossDepts)) {
                    $crossCodeCheck = $db->prepare("SELECT dept_code FROM departments WHERE id = ?");
                    $crossCodeCheck->execute([$deptId]);
                    $deptCodeForCheck = $crossCodeCheck->fetchColumn();
                    $inCross = in_array($deptCodeForCheck, $crossDepts);
                }

                if (!$inUda && !$inCross) {
                    $errors[] = 'You can only assign tasks to your own department or your assigned departments.';
                }
            }
        }
    }

    // CORE admin: assigned user must be in same branch (any dept)
    if ($isBranchManager && $assignTo && $assignTo !== (int) $adminUser['id']) {
        $staffCheck = $db->prepare("
                SELECT u.id FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE u.id = ? AND u.branch_id = ? AND u.is_active = 1
                AND r.role_name IN ('staff','admin','manager')
            ");
        $staffCheck->execute([$assignTo, $adminUser['branch_id']]);
        if (!$staffCheck->fetch())
            $errors[] = 'Selected staff does not belong to your branch.';
    }

    $validStatuses = array_column(
        $db->query("SELECT status_name FROM task_status")->fetchAll(),
        'status_name'
    );
    if (!in_array($status, $validStatuses))
        $errors[] = 'Invalid status.';

    // ── Auditor cap check BEFORE insert ──────────────────────────────────────
    if (!$errors && $auditorId && $auditNature === 'countable') {
        $fyRow = $db->query("SELECT id FROM fiscal_years WHERE is_current=1 LIMIT 1")->fetchColumn();

        $capStmt = $db->prepare("
            SELECT
                a.auditor_name,
                COALESCE(q.max_countable_override, a.max_countable) AS cap,
                COALESCE(q.countable_count, 0)                      AS used
            FROM auditors a
            LEFT JOIN auditor_yearly_quota q
                   ON q.auditor_id     = a.id
                  AND q.fiscal_year_id = ?
            WHERE a.id = ?
        ");
        $capStmt->execute([$fyRow ?: 0, $auditorId]);
        $capData = $capStmt->fetch();

        if ($capData && (int) $capData['used'] >= (int) $capData['cap']) {
            $errors[] = "Auditor \"{$capData['auditor_name']}\" has reached their countable limit
                         ({$capData['cap']}) for this fiscal year.";
        }
    }

    // ── Insert ────────────────────────────────────────────────────────────────
    if (!$errors) {
        $statusRow = $db->prepare("SELECT id FROM task_status WHERE status_name = ?");
        $statusRow->execute([$status]);
        $statusId = (int) ($statusRow->fetchColumn() ?: 1);

        $ins = $db->prepare("
            INSERT INTO tasks
            (title, description, department_id, branch_id, company_id,
             created_by, assigned_to, status_id, priority,
             due_date, fiscal_year, remarks, audit_nature, auditor_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $title,
            $desc,
            $deptId,
            $branchId,
            $compId,
            $user['id'],
            $assignTo,
            $statusId,
            $priority,
            $dueDate ?: null,
            $fy,
            $remarks,
            $auditNature,
            $auditorId,
        ]);
        $taskId = $db->lastInsertId();

        // ── NO manual auditor counter update ──────────────────────────────────
        // DB trigger trg_auditor_quota_increment fires AFTER INSERT on tasks
        // and handles auditor_yearly_quota automatically.

        // ── Notify assigned staff ─────────────────────────────────────────────
        if ($assignTo) {
            $tnStmt = $db->prepare("SELECT task_number FROM tasks WHERE id = ?");
            $tnStmt->execute([$taskId]);
            $taskNumber = $tnStmt->fetchColumn() ?: "T-{$taskId}";

            $companyName = '';
            if ($compId) {
                $cnStmt = $db->prepare("SELECT company_name FROM companies WHERE id = ?");
                $cnStmt->execute([$compId]);
                $companyName = $cnStmt->fetchColumn() ?: '';
            }

            $notifMessage = "Task #{$taskNumber}";
            if ($companyName)
                $notifMessage .= " — {$companyName}";
            $notifMessage .= " has been assigned to you";
            if ($dueDate)
                $notifMessage .= " · Due " . date('M j, Y', strtotime($dueDate));
            $notifMessage .= ".";
            $assigneeStmt = $db->prepare("
                SELECT r.role_name FROM users u
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE u.id = ?
            ");
            $assigneeStmt->execute([$assignTo]);
            $assigneeRole = $assigneeStmt->fetchColumn();

            $role = strtolower($assigneeRole);

            $rolePathMap = [
                'admin' => 'admin',
                'executive' => 'admin',
                'manager' => 'manager',
                'staff' => 'staff',
            ];

            $basePath = $rolePathMap[$role] ?? 'staff';

            $taskUrl = APP_URL . '/' . $basePath . '/tasks/view.php?id=' . $taskId;

            notify(
                $assignTo,
                "New Task: {$title}",
                $notifMessage,
                'task',
                $taskUrl,
                true,
                [
                    'template' => 'task_assigned',
                    'task' => [
                        'id' => $taskId,
                        'task_number' => $taskNumber,
                        'title' => $title,
                        'department' => $deptCode,
                        'status' => $status,
                        'priority' => $priority,
                        'due_date' => $dueDate,
                        'fiscal_year' => $fy,
                        'company' => $companyName,
                        'remarks' => $remarks,
                    ],
                ]
            );
        }

        logActivity("Assigned task: {$title}", 'tasks', "id={$taskId}");
        setFlash('success', 'Task assigned successfully! Add department-specific details below.');
        header('Location: view.php?id=' . $taskId);
        exit;
    }
}

$selectedFy = $_POST['fiscal_year'] ?? $currentFy;
$allStatusOpts = $db->query("SELECT status_name FROM task_status ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_manager.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <div class="page-hero">
                <div class="page-hero-badge"><i class="fas fa-plus-circle"></i> New Task</div>
                <h4>Assign Task</h4>
                <p>Create the task here — then fill department-specific details on the <strong>task view page</strong>.
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger rounded-3">
                    <strong>Please fix:</strong>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
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

                                    <div class="col-12">
                                        <label class="form-label-mis">Task Title <span
                                                class="required-star">*</span></label>
                                        <input type="text" name="title" class="form-control"
                                            placeholder="e.g. VAT Filing for Alpha Retail — FY 2081/82"
                                            value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                                    </div>

                                    <!-- Department field -->
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Department <span
                                                class="required-star">*</span></label>
                                        <!-- // In the department select HTML, change the selected check: -->
                                        <?php if (isExecutive() || $isBranchManager || $hasCrossDeptAccess): ?>
                                            <select name="department_id" class="form-select" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach ($depts as $d): ?>
                                                    <option value="<?= $d['id'] ?>"
                                                        data-code="<?= htmlspecialchars($d['dept_code']) ?>" <?= (
                                                              ($_POST['department_id'] ?? $adminUser['department_id']) == $d['id']  // ← default to own dept
                                                          ) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($d['dept_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>

                                            <?php if (!$hasUda && count($depts) === 1): ?>
                                                <!--{{-- Truly single dept, no UDA at all — lock it --}}-->
                                                <input type="hidden" name="department_id" value="<?= $depts[0]['id'] ?>">
                                                <input type="text" class="form-control"
                                                    value="<?= htmlspecialchars($depts[0]['dept_name'] ?? '') ?>" readonly
                                                    style="background:#f9fafb;cursor:not-allowed;">
                                            <?php else: ?>
                                                <select name="department_id" id="dept_select_regular" class="form-select"
                                                    required>
                                                    <option value="">-- Select Department --</option>
                                                    <?php foreach ($depts as $d): ?>
                                                        <option value="<?= $d['id'] ?>"
                                                            data-code="<?= htmlspecialchars($d['dept_code']) ?>"
                                                            <?= (($_POST['department_id'] ?? $adminUser['department_id']) == $d['id']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($d['dept_name']) ?>
                                                            <?= ($d['id'] == $adminUser['department_id']) ? ' ★' : ' · Other' ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if ($hasUda): ?>
                                                    <small style="font-size:.65rem;color:#8b5cf6;">
                                                        <i class="fas fa-info-circle me-1"></i>
                                                        Your dept ★ + Other-assigned departments
                                                    </small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Branch field -->
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Branch <span
                                                class="required-star">*</span></label>
                                        <?php if (isExecutive()): ?>
                                            <select name="branch_id" class="form-select" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach ($branches as $b): ?>
                                                    <option value="<?= $b['id'] ?>" <?= ($_POST['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($b['branch_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif ($isBranchManager): ?>
                                            <!-- Branch Manager: branch always locked to their own branch -->
                                            <input type="hidden" name="branch_id" value="<?= $adminUser['branch_id'] ?>">
                                            <?php
                                            $lockedBranchName = '';
                                            foreach ($branches as $b) {
                                                if ($b['id'] == $adminUser['branch_id']) {
                                                    $lockedBranchName = $b['branch_name'];
                                                    break;
                                                }
                                            }
                                            ?>
                                            <input type="text" class="form-control"
                                                value="<?= htmlspecialchars($lockedBranchName) ?>" readonly
                                                style="background:#f9fafb;cursor:not-allowed;">
                                        <?php else: ?>
                                            <!-- Cross-dept-access admin AND regular admin: branch selectable -->
                                            <select name="branch_id" class="form-select" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach ($branches as $b): ?>
                                                    <option value="<?= $b['id'] ?>" <?= (($_POST['branch_id'] ?? $adminUser['branch_id']) == $b['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($b['branch_name']) ?>
                                                        <?= ($b['id'] == $adminUser['branch_id']) ? ' ★' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label-mis">Company / Client</label>
                                        <select name="company_id" id="company_select" class="form-select">
                                            <option value="">-- None --</option>
                                            <?php foreach ($companies as $c): ?>
                                                <option value="<?= $c['id'] ?>" <?= ($_POST['company_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>

                                                    <?= htmlspecialchars($c['company_name']) ?>
                                                    <?php if (!empty($c['pan_number']) || !empty($c['company_code'])): ?>
                                                        —
                                                        <?php if (!empty($c['pan_number'])): ?>
                                                            <?= htmlspecialchars($c['pan_number']) ?>
                                                        <?php endif; ?>

                                                        <?php if (!empty($c['company_code'])): ?>
                                                            | <?= htmlspecialchars($c['company_code']) ?>
                                                        <?php endif; ?>
                                                    <?php endif; ?>

                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label-mis">Status</label>
                                        <select name="status" class="form-select">
                                            <?php foreach ($allStatusOpts as $sn):
                                                if ($sn === 'Corporate Team')
                                                    continue; ?>
                                                <option value="<?= htmlspecialchars($sn) ?>" <?= ($_POST['status'] ?? 'Not Started') === $sn ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($sn) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

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

                                    <div class="col-md-4">
                                        <label class="form-label-mis">Due Date</label>
                                        <input type="date" name="due_date" class="form-control"
                                            value="<?= htmlspecialchars($_POST['due_date'] ?? '') ?>">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label-mis">
                                            Fiscal Year <span class="required-star">*</span>
                                        </label>
                                        <select name="fiscal_year" class="form-select" required>
                                            <option value="">-- Select FY --</option>
                                            <?php foreach ($fys as $fy): ?>
                                                <option value="<?= htmlspecialchars($fy['fy_code']) ?>"
                                                    <?= $selectedFy === $fy['fy_code'] ? 'selected' : '' ?>
                                                    <?= $fy['is_current'] ? 'style="font-weight:700;color:#16a34a;"' : '' ?>>
                                                    <?= htmlspecialchars($fy['fy_label'] ?: $fy['fy_code']) ?>
                                                    <?= $fy['is_current'] ? ' ★' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small style="font-size:.65rem;color:#9ca3af;">
                                            <i class="fas fa-database me-1"></i>From fiscal_years table · ★ = current
                                            year
                                        </small>
                                    </div>

                                    <div class="col-md-8">
                                        <label class="form-label-mis">
                                            Assign To
                                            <?php
                                            $regularAdminMultiDept = (!isExecutive() && !$isBranchManager && !$hasCrossDeptAccess && (count($depts) > 1 || $hasUda));
                                            if ($isBranchManager || $hasCrossDeptAccess || $regularAdminMultiDept): ?>
                                                <span style="font-size:.65rem;color:#8b5cf6;margin-left:.3rem;">
                                                    <i class="fas fa-filter me-1"></i>filtered by selected department
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                        <select name="assigned_to" id="assigned_to_select" class="form-select">
                                            <option value="">-- Unassigned --</option>
                                            <!-- Self always shown regardless of dept filter -->
                                            <option value="<?= $adminUser['id'] ?>" <?= ($_POST['assigned_to'] ?? '') == $adminUser['id'] ? 'selected' : '' ?>
                                                style="font-weight:700;color:#16a34a;">
                                                ★ Assign to myself (<?= htmlspecialchars($adminUser['full_name']) ?>)
                                            </option>
                                            <?php if (!empty($staffList)): ?>
                                                <?php
                                                $byGroup = [];
                                                foreach ($staffList as $s) {
                                                    if ((int) $s['id'] === (int) $adminUser['id'])
                                                        continue;
                                                    $gk = $s['primary_dept_name'] ?? 'No Department';
                                                    $byGroup[$gk][] = $s;
                                                }
                                                foreach ($byGroup as $grpLabel => $grpStaff):
                                                    ?>
                                                    <optgroup label="<?= htmlspecialchars($grpLabel) ?>">
                                                        <?php foreach ($grpStaff as $s): ?>
                                                            <option value="<?= $s['id'] ?>" <?= ($_POST['assigned_to'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($s['full_name']) ?>
                                                                <?= !empty($s['employee_id']) ? ' (' . $s['employee_id'] . ')' : '' ?>
                                                                <?php if (!empty($s['secondary_dept_name'])): ?>
                                                                    — also: <?= htmlspecialchars($s['secondary_dept_name']) ?>
                                                                <?php endif; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                        <div id="staff-loading"
                                            style="display:none;font-size:.72rem;color:#6b7280;margin-top:.3rem;">
                                            <i class="fas fa-spinner fa-spin me-1"></i>Loading staff…
                                        </div>
                                        <div id="staff-hint" style="font-size:.68rem;color:#9ca3af;margin-top:.25rem;">
                                            <?php if ($isBranchManager || $hasCrossDeptAccess): ?>
                                                <i class="fas fa-info-circle me-1"></i>
                                                <?= $hasCrossDeptAccess ? 'Showing staff of selected department' : 'Select a department above to see filtered staff' ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div id="audit-fields-wrapper" style="display:contents;">
                                        <!-- Audit Nature: always rendered, JS toggleAuditFields() hides for FIN -->
                                        <div class="col-md-6">
                                            <label class="form-label-mis">Audit Nature</label>
                                            <select name="audit_nature" id="audit_nature" class="form-select"
                                                onchange="loadAuditors()">
                                                <option value="">-- Select --</option>
                                                <option value="countable" <?= ($_POST['audit_nature'] ?? '') === 'countable' ? 'selected' : '' ?>>
                                                    Countable
                                                </option>
                                                <option value="uncountable" <?= ($_POST['audit_nature'] ?? '') === 'uncountable' ? 'selected' : '' ?>>
                                                    Uncountable
                                                </option>
                                                <option value="N/A" <?= ($_POST['audit_nature'] ?? '') === 'N/A' ? 'selected' : '' ?>>
                                                    N/A
                                                </option>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label-mis">Auditor</label>
                                            <select name="auditor_id" id="auditor_id" class="form-select">
                                                <option value="">-- Select Auditor --</option>
                                            </select>
                                            <div id="auditor-capacity" style="margin-top:.4rem;display:none;">
                                                <div class="d-flex justify-content-between mb-1"
                                                    style="font-size:.72rem;color:#6b7280;">
                                                    <span id="capacity-label">Capacity</span>
                                                    <span id="capacity-text"></span>
                                                </div>
                                                <div style="background:#f3f4f6;border-radius:99px;height:5px;">
                                                    <div id="capacity-bar" style="height:100%;border-radius:99px;background:#10b981;
                                            transition:.3s;width:0%;"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label-mis">Description</label>
                                        <textarea name="description" id="description" class="form-control" rows="2"
                                            maxlength="500"
                                            placeholder="Brief description of the task"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                        <small id="description_count"
                                            style="font-size:.7rem;color:#9ca3af;float:right;"></small>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label-mis">Remarks</label>
                                        <textarea name="remarks" id="remarks" class="form-control" rows="2"
                                            maxlength="300"
                                            placeholder="Any internal remarks"><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
                                        <small id="remarks_count"
                                            style="font-size:.7rem;color:#9ca3af;float:right;"></small>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card-mis mb-3">
                            <div class="card-mis-header">
                                <h5>Actions</h5>
                            </div>
                            <div class="card-mis-body">
                                <button type="submit" id="assignSubmitBtn" class="btn-gold btn w-100 mb-2">
                                    <span id="assignBtnIcon"><i class="fas fa-save me-2"></i>Assign Task</span>
                                    <span id="assignBtnLoading" style="display:none;">
                                        <span class="spinner-border spinner-border-sm me-2" role="status"
                                            aria-hidden="true"></span>
                                        Assigning...
                                    </span>
                                </button>
                                <a href="index.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                            </div>
                        </div>

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
                                        Fill dept-specific fields and update work status
                                    </div>
                                    <div style="display:flex;align-items:baseline;gap:.4rem;">
                                        <span style="background:#f59e0b;color:#fff;padding:.1rem .45rem;
                                     border-radius:4px;font-size:.68rem;font-weight:700;flex-shrink:0;">
                                            <i class="fas fa-pen me-1"></i>EDIT
                                        </span>
                                        Change title, priority, due date, reassign staff
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card-mis p-3" style="border-left:3px solid var(--gold);">
                            <p style="font-size:.8rem;font-weight:600;margin-bottom:.5rem;">Status Reference</p>
                            <?php
                            $statusRef = $db->query("SELECT status_name, color FROM task_status ORDER BY id")->fetchAll();
                            foreach ($statusRef as $sr):
                                if ($sr['status_name'] === 'Corporate Team')
                                    continue;
                                ?>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <div style="width:8px;height:8px;border-radius:50%;
                            background:<?= htmlspecialchars($sr['color'] ?? '#9ca3af') ?>;flex-shrink:0;"></div>
                                    <span
                                        style="font-size:.78rem;color:#6b7280;"><?= htmlspecialchars($sr['status_name']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </form>

        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
    const IS_BRANCH_MANAGER = <?= $isBranchManager ? 'true' : 'false' ?>;
    const IS_EXECUTIVE = <?= isExecutive() ? 'true' : 'false' ?>;
    const REGULAR_ADMIN_MULTI_DEPT = <?= (!isExecutive() && !$isBranchManager && !$hasCrossDeptAccess && count($depts) > 1) ? 'true' : 'false' ?>;
    const USE_AJAX_STAFF = <?= ($isBranchManager || isExecutive() || $hasCrossDeptAccess || count($depts) > 1 || $hasUda) ? 'true' : 'false' ?>;
    const ADMIN_BRANCH_ID = <?= (int) $adminUser['branch_id'] ?>;
    const ADMIN_USER_ID = <?= (int) $adminUser['id'] ?>;
    const ADMIN_USER_NAME = <?= json_encode($adminUser['full_name']) ?>;
    const AJAX_URL = '<?= APP_URL ?>/ajax/get_staff_by_executive.php';
    const PREV_ASSIGNED = '<?= (int) ($_POST['assigned_to'] ?? 0) ?>';

    let assignedToTS = null;

    document.addEventListener('DOMContentLoaded', function () {

        // Company TomSelect
        new TomSelect('#company_select', {
            placeholder: 'Search by name, PAN or code...',
            allowEmptyOption: true,
            maxOptions: 500,
            searchField: ['text'],
            render: {
                option: function (data, escape) {
                    const parts = data.text.split('—');
                    return `<div style="padding:6px 0;">
                    <div style="margin-left:10px;font-weight:600;">${escape(parts[0]?.trim() ?? '')}</div>
                    ${parts[1] ? `<div style="margin-left:10px;font-size:12px;color:#6b7280;">${escape(parts[1].trim())}</div>` : ''}
                </div>`;
                },
                item: function (data, escape) { return `<div>${escape(data.text)}</div>`; }
            }
        });

        // Assigned To TomSelect (initial — will be re-init after AJAX rebuild)
        // DO NOT init TomSelect here when AJAX will load immediately —
        // it will be inited inside rebuildSelect() after data arrives
        if (!USE_AJAX_STAFF) {
            assignedToTS = buildAssignedToTS();
        }

        const deptSel = document.querySelector('select[name="department_id"]');

        if (USE_AJAX_STAFF && deptSel) {
            deptSel.addEventListener('change', function () {
                const deptId = this.value;
                const deptCode = this.options[this.selectedIndex]?.dataset?.code ?? '';
                toggleAuditFields(deptCode);
                if (deptId) {
                    loadStaff(deptId);
                } else {
                    rebuildSelect([]);
                    updateHint(0);
                }
            });

            // Always fire on load for whichever dept is selected
            if (deptSel.value) {
                const code = deptSel.options[deptSel.selectedIndex]?.dataset?.code ?? '';
                toggleAuditFields(code);
                loadStaff(deptSel.value);
            } else {
                // No dept selected yet — auto-select own dept and load its staff
                const ownDeptOpt = [...deptSel.options].find(
                    o => o.value === String(<?= (int) $adminUser['department_id'] ?>)
                );
                if (ownDeptOpt) {
                    deptSel.value = ownDeptOpt.value;
                    toggleAuditFields(ownDeptOpt.dataset?.code ?? '');
                    loadStaff(ownDeptOpt.value);
                }
            }
        } else if (!USE_AJAX_STAFF) {
            // Single dept locked as hidden input — audit toggle only
            const deptInput = document.querySelector('[name="department_id"]');
            if (deptInput && deptInput.tagName !== 'SELECT') {
                const singleCode = <?= json_encode($depts[0]['dept_code'] ?? '') ?>;
                toggleAuditFields(singleCode);
            }
        }

        // Auditor
        const auditorEl = document.getElementById('auditor_id');
        if (auditorEl) auditorEl.addEventListener('change', updateCapacityBar);

        // Also watch dept select for audit toggle (non-AJAX path)
        const anyDeptSel = document.querySelector('select[name="department_id"]');
        if (anyDeptSel) {
            anyDeptSel.addEventListener('change', function () {
                toggleAuditFields(this.options[this.selectedIndex]?.dataset?.code ?? '');
            });
            toggleAuditFields(anyDeptSel.options[anyDeptSel.selectedIndex]?.dataset?.code ?? '');
        }
    });

    // ── Build TomSelect on the assigned_to select ─────────────────────────────────
    function buildAssignedToTS() {
        if (assignedToTS) { try { assignedToTS.destroy(); } catch (e) { } }
        return new TomSelect('#assigned_to_select', {
            placeholder: 'Search by name or employee ID...',
            allowEmptyOption: true,
            maxOptions: 500,
            searchField: ['text'],
            render: {
                option: function (data, escape) {
                    const isSelf = String(data.value) === String(ADMIN_USER_ID);
                    return `<div style="padding:4px 0;${isSelf ? 'color:#16a34a;font-weight:700;' : ''}">
                    <div style="margin-left:10px;">${isSelf ? '★ ' : ''}${escape(data.text)}</div>
                </div>`;
                }
            }
        });
    }

    // ── Load staff via AJAX ───────────────────────────────────────────────────────
    function loadStaff(deptId) {
        document.getElementById('staff-loading').style.display = 'block';
        const hint = document.getElementById('staff-hint');
        if (hint) hint.style.display = 'none';

        fetch(`${AJAX_URL}?dept_id=${encodeURIComponent(deptId)}&caller_id=${ADMIN_USER_ID}&branch_id=${ADMIN_BRANCH_ID}`)
            .then(r => r.json())
            .then(data => {
                document.getElementById('staff-loading').style.display = 'none';
                rebuildSelect(data);
                updateHint(data.filter(s => !s.is_self).length);
            })
            .catch(() => {
                document.getElementById('staff-loading').style.display = 'none';
                const hint = document.getElementById('staff-hint');
                if (hint) hint.innerHTML = '<i class="fas fa-exclamation-triangle text-danger me-1"></i>Failed to load staff';
            });
    }

    // ── Rebuild native <select> then re-init TomSelect ───────────────────────────
    function rebuildSelect(staffList) {
        const sel = document.getElementById('assigned_to_select');

        // destroy TomSelect safely
        if (assignedToTS) {
            assignedToTS.destroy();
            assignedToTS = null;
        }

        sel.innerHTML = '';

        // default
        sel.insertAdjacentHTML('beforeend', `<option value="">-- Unassigned --</option>`);

        // self
        sel.insertAdjacentHTML('beforeend', `
        <option value="${ADMIN_USER_ID}" style="font-weight:700;color:#16a34a;">
            ★ Assign to myself (${ADMIN_USER_NAME})
        </option>
    `);

        // group
        const byDept = {};

        (staffList || []).forEach(s => {
            if (s.is_self) return;

            const dept = s.dept_name || 'No Department';
            if (!byDept[dept]) byDept[dept] = [];

            byDept[dept].push(s);
        });

        for (const dept in byDept) {
            let optgroup = document.createElement('optgroup');
            optgroup.label = dept;

            byDept[dept].forEach(s => {
                let opt = document.createElement('option');
                opt.value = s.id;
                let label = `${s.full_name}${s.employee_id ? ' (' + s.employee_id + ')' : ''}`;
                if (s.secondary_dept_name) label += ` — also: ${s.secondary_dept_name}`;
                opt.textContent = label;
                optgroup.appendChild(opt);
            });

            sel.appendChild(optgroup);
        }

        // IMPORTANT: re-init AFTER DOM update
        setTimeout(() => {
            assignedToTS = new TomSelect('#assigned_to_select', {
                allowEmptyOption: true,
                maxOptions: 500,
                searchField: ['text']
            });
        }, 50);
    }

    function updateHint(count) {
        const hint = document.getElementById('staff-hint');
        if (!hint) return;
        hint.style.display = 'block';
        hint.innerHTML = count > 0
            ? `<i class="fas fa-users me-1"></i>${count} staff found in this department`
            : '<i class="fas fa-info-circle me-1"></i>No staff found — you can still assign to yourself';
    }

    // ── Toggle audit fields ───────────────────────────────────────────────────────
    function toggleAuditFields(deptCode) {
        const wrapper = document.getElementById('audit-fields-wrapper');
        if (wrapper) wrapper.style.display = deptCode === 'FIN' ? 'none' : 'contents';
    }

    // ── Auditor loader ────────────────────────────────────────────────────────────
    function loadAuditors() {
        const nature = document.getElementById('audit_nature')?.value;
        const select = document.getElementById('auditor_id');
        const capDiv = document.getElementById('auditor-capacity');
        if (!nature || nature === 'N/A') {
            if (select) select.innerHTML = '<option value="">-- Select Auditor --</option>';
            if (capDiv) capDiv.style.display = 'none';
            return;
        }
        select.innerHTML = '<option value="">Loading…</option>';
        fetch('<?= APP_URL ?>/ajax/get_auditors.php?nature=' + encodeURIComponent(nature))
            .then(r => r.json())
            .then(data => {
                select.innerHTML = '<option value="">-- Select Auditor --</option>';
                data.forEach(a => {
                    const atLimit = nature === 'countable' && a.at_limit;
                    const label = nature === 'countable'
                        ? `${a.auditor_name} (${a.countable_count} / ${a.max_limit})${atLimit ? ' — FULL' : ''}`
                        : `${a.auditor_name} (${a.uncountable_count} tasks)`;
                    const opt = document.createElement('option');
                    opt.value = a.id; opt.text = label; opt.disabled = atLimit;
                    opt.dataset.countable = a.countable_count;
                    opt.dataset.uncountable = a.uncountable_count;
                    opt.dataset.limit = a.max_limit;
                    select.appendChild(opt);
                });
                updateCapacityBar();
            })
            .catch(() => { select.innerHTML = '<option value="">Error loading</option>'; });
    }
    function loadCompaniesForBranch(branchId) {
        if (!branchId) return;
        const sel = document.getElementById('company_select');
        if (!sel || !sel.tomselect) return;
        fetch(`<?= APP_URL ?>/ajax/get_companies_by_branch.php?branch_id=${encodeURIComponent(branchId)}`)
            .then(r => r.json())
            .then(data => {
                const ts = sel.tomselect;
                ts.clearOptions();
                ts.addOption({ value: '', text: '-- None --' });
                data.forEach(c => {
                    let label = c.company_name;
                    if (c.pan_number || c.company_code) label += ' — ' + [c.pan_number, c.company_code].filter(Boolean).join(' | ');
                    ts.addOption({ value: c.id, text: label });
                });
                ts.refreshOptions(false);
            })
            .catch(() => { });
    }
    function updateCapacityBar() {
        const nature = document.getElementById('audit_nature')?.value;
        const select = document.getElementById('auditor_id');
        const capDiv = document.getElementById('auditor-capacity');
        const opt = select?.options[select.selectedIndex];
        if (!opt || !opt.value || !nature) { if (capDiv) capDiv.style.display = 'none'; return; }
        if (nature === 'uncountable') {
            document.getElementById('capacity-label').textContent = 'Uncountable tasks';
            document.getElementById('capacity-text').textContent = opt.dataset.uncountable;
            document.getElementById('capacity-bar').style.width = '0%';
            capDiv.style.display = 'block'; return;
        }
        const used = parseInt(opt.dataset.countable || 0);
        const limit = parseInt(opt.dataset.limit || 0);
        const pct = limit > 0 ? Math.min(100, Math.round(used / limit * 100)) : 0;
        const color = pct >= 100 ? '#ef4444' : pct >= 80 ? '#f59e0b' : '#10b981';
        document.getElementById('capacity-label').textContent = 'Countable capacity used';
        document.getElementById('capacity-text').textContent = `${used} / ${limit} (${pct}%)`;
        document.getElementById('capacity-bar').style.width = pct + '%';
        document.getElementById('capacity-bar').style.background = color;
        capDiv.style.display = 'block';
    }
    // ADD inside the document.addEventListener('DOMContentLoaded', ...) block:
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
    // ADD at the end of the existing <script> block (identical logic to Document 4 — dept/branch may be select or locked hidden input):
    document.getElementById('assignForm').addEventListener('submit', function (e) {
        let valid = true;

        const titleInput = document.querySelector('input[name="title"]');
        if (!titleInput.value.trim()) { valid = false; titleInput.classList.add('is-invalid'); }
        else titleInput.classList.remove('is-invalid');

        const deptEl = document.querySelector('[name="department_id"]');
        if (deptEl && deptEl.tagName === 'SELECT') {
            if (!deptEl.value) { valid = false; deptEl.classList.add('is-invalid'); }
            else deptEl.classList.remove('is-invalid');
        }

        const branchEl = document.querySelector('[name="branch_id"]');
        if (branchEl && branchEl.tagName === 'SELECT') {
            if (!branchEl.value) { valid = false; branchEl.classList.add('is-invalid'); }
            else branchEl.classList.remove('is-invalid');
        }

        const fySel = document.querySelector('select[name="fiscal_year"]');
        if (fySel && !fySel.value) { valid = false; fySel.classList.add('is-invalid'); }
        else if (fySel) fySel.classList.remove('is-invalid');

        if (!valid) {
            e.preventDefault();
            const firstInvalid = document.querySelector('.is-invalid');
            if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }

        const btn = document.getElementById('assignSubmitBtn');
        btn.disabled = true;
        btn.style.opacity = '0.75';
        btn.style.cursor = 'not-allowed';
        document.getElementById('assignBtnIcon').style.display = 'none';
        document.getElementById('assignBtnLoading').style.display = 'inline-flex';
        document.getElementById('assignBtnLoading').style.alignItems = 'center';
    });
</script>
<?php include '../../includes/footer.php'; ?>