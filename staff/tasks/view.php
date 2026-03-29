<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}

// Fetch task
$task = $db->prepare("
    SELECT t.*,
           d.dept_name, d.dept_code, d.color,
           b.branch_name,
           c.company_name,
           ts.status_name AS status,
           cb.full_name AS created_by_name,
           at.full_name AS assigned_to_name
    FROM tasks t
    LEFT JOIN departments d  ON d.id = t.department_id
    LEFT JOIN branches b     ON b.id = t.branch_id
    LEFT JOIN companies c    ON c.id = t.company_id
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN users cb       ON cb.id = t.created_by
    LEFT JOIN users at       ON at.id = t.assigned_to
    WHERE t.id = ? AND t.is_active = 1
");
$task->execute([$id]);
$task = $task->fetch();

if (!$task) {
    setFlash('error', 'Task not found.');
    header('Location: index.php');
    exit;
}

// Security: staff can only view their own assigned tasks
if (!isAdmin() && !isExecutive() && $task['assigned_to'] != $user['id']) {
    setFlash('error', 'Access denied.');
    header('Location: index.php');
    exit;
}

// Fetch current staff profile for scoping
$staffProfileStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$staffProfileStmt->execute([$user['id']]);
$staffProfile = $staffProfileStmt->fetch();

// Load retail detail
$detail = null;
if ($task['dept_code'] === 'RETAIL') {
    try {
        $dSt = $db->prepare("
            SELECT tr.*,
                   ct.type_name   AS company_type_name,
                   ft.type_name   AS file_type_name,
                   pv.type_name   AS pan_vat_name,
                   vc.value       AS vat_client_value,
                   at2.type_name  AS audit_type_name,
                   ws.status_name AS work_status_name,
                   fs.status_name AS finalisation_status_name,
                   tc.status_name AS tax_clearance_status_name,
                   bs.value       AS backup_status_value,
                   au.full_name   AS retail_assigned_to_name,
                   fb.full_name   AS finalised_by_name
            FROM task_retail tr
            LEFT JOIN company_types ct ON ct.id = tr.company_type_id
            LEFT JOIN file_types ft    ON ft.id = tr.file_type_id
            LEFT JOIN pan_vat_types pv ON pv.id = tr.pan_vat_id
            LEFT JOIN yes_no vc        ON vc.id = tr.vat_client_id
            LEFT JOIN audit_types at2  ON at2.id = tr.audit_type_id
            LEFT JOIN task_status ws   ON ws.id = tr.work_status_id
            LEFT JOIN task_status fs   ON fs.id = tr.finalisation_status_id
            LEFT JOIN task_status tc   ON tc.id = tr.tax_clearance_status_id
            LEFT JOIN yes_no bs        ON bs.id = tr.backup_status_id
            LEFT JOIN users au         ON au.id = tr.assigned_to
            LEFT JOIN users fb         ON fb.id = tr.finalised_by
            WHERE tr.task_id = ?
        ");
        $dSt->execute([$id]);
        $detail = $dSt->fetch();
    } catch (Exception $e) {
        $detail = null;
    }
} else {
    $detailTableMap = [
        'TAX' => 'task_tax',
        'BANK' => 'task_banking',
        'CORP' => 'task_finance',
        'HR' => 'task_hr',
        'OPS' => 'task_operations',
    ];
    $detailTable = $detailTableMap[$task['dept_code']] ?? null;
    if ($detailTable) {
        try {
            $dSt = $db->prepare("SELECT * FROM {$detailTable} WHERE task_id = ?");
            $dSt->execute([$id]);
            $detail = $dSt->fetch();
        } catch (Exception $e) {
            $detail = null;
        }
    }
}

// Load task statuses
$taskStatuses = $db->query("SELECT id, status_name FROM task_status ORDER BY id")->fetchAll();

// Staff in same branch & dept for transfer
$sameBranchStaff = $db->prepare("
    SELECT u.id, u.full_name, u.employee_id
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE r.role_name = 'staff'
    AND u.is_active = 1
    AND u.branch_id = ?
    AND u.department_id = ?
    AND u.id != ?
    ORDER BY u.full_name
");
$sameBranchStaff->execute([
    $staffProfile['branch_id'],
    $staffProfile['department_id'],
    $user['id']
]);
$sameBranchStaff = $sameBranchStaff->fetchAll();

// Workflow history
$workflowStmt = $db->prepare("
    SELECT tw.*,
           fu.full_name AS from_user_name,
           tu.full_name AS to_user_name,
           fd.dept_name AS from_dept_name,
           td.dept_name AS to_dept_name
    FROM task_workflow tw
    LEFT JOIN users fu       ON fu.id = tw.from_user_id
    LEFT JOIN users tu       ON tu.id = tw.to_user_id
    LEFT JOIN departments fd ON fd.id = tw.from_dept_id
    LEFT JOIN departments td ON td.id = tw.to_dept_id
    WHERE tw.task_id = ?
    ORDER BY tw.created_at DESC
    LIMIT 10
");
$workflowStmt->execute([$id]);
$workflow = $workflowStmt->fetchAll();

// Comments
$comments = [];
try {
    $commentSt = $db->prepare("
        SELECT tc.*, u.full_name FROM task_comments tc
        LEFT JOIN users u ON u.id = tc.user_id
        WHERE tc.task_id = ? ORDER BY tc.created_at ASC
    ");
    $commentSt->execute([$id]);
    $comments = $commentSt->fetchAll();
} catch (Exception $e) {
    $comments = [];
}

// ── HANDLE: Update work status ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_work_status'])) {
    verifyCsrf();
    $newWorkStatusId = (int) ($_POST['work_status_id'] ?? 0);
    $workNotes = trim($_POST['work_notes'] ?? '');

    if ($newWorkStatusId && $task['dept_code'] === 'RETAIL') {

        // Get status name to sync main task
        $stNameStmt = $db->prepare("SELECT status_name FROM task_status WHERE id = ?");
        $stNameStmt->execute([$newWorkStatusId]);
        $newWorkStatusName = $stNameStmt->fetchColumn();

        // Update retail work status + notes
        $db->prepare("
            UPDATE task_retail SET
                work_status_id = ?,
                notes          = ?
            WHERE task_id = ?
        ")->execute([$newWorkStatusId, $workNotes ?: null, $id]);

        // Sync main task status to match work status
        $db->prepare("
            UPDATE tasks SET status_id = ?, updated_at = NOW() WHERE id = ?
        ")->execute([$newWorkStatusId, $id]);

        // If Done — set completed_date
        if ($newWorkStatusName === 'Done') {
            $db->prepare("
                UPDATE task_retail SET completed_date = CURDATE() WHERE task_id = ?
            ")->execute([$id]);
        }

        // Log workflow
        try {
            $db->prepare("
                INSERT INTO task_workflow
                (task_id, action, from_user_id, old_status, new_status, remarks)
                VALUES (?, 'status_changed', ?, ?, ?, ?)
            ")->execute([
                        $id,
                        $user['id'],
                        $task['status'],
                        $newWorkStatusName,
                        $workNotes
                    ]);
        } catch (Exception $e) {
        }

        // Notify admin if Done
        // Notify admin on every status change
        if (!empty($task['created_by'])) {
            $workMsg = "Task #{$task['task_number']}";
            if (!empty($task['company_name']))
                $workMsg .= " ({$task['company_name']})";
            $workMsg .= " — work status updated to \"{$newWorkStatusName}\" by {$staffProfile['full_name']}.";
            if ($workNotes)
                $workMsg .= "\n\nNote: {$workNotes}";
            if ($newWorkStatusName === 'Done') {
                $workMsg .= "\n\nYou can now review and transfer this task to another department if needed.";
            }

            notify(
                (int) $task['created_by'],
                $newWorkStatusName === 'Done'
                ? "Task Completed: {$task['task_number']}"
                : "Work Status Updated: {$task['task_number']}",
                $workMsg,
                'status',
                APP_URL . '/admin/tasks/view.php?id=' . $id,
                true,
                [
                    'template' => 'task_status_changed',
                    'task' => [
                        'id' => $id,
                        'task_number' => $task['task_number'],
                        'title' => $task['title'],
                        'old_status' => $task['status'],
                        'new_status' => $newWorkStatusName,
                        'due_date' => $task['due_date'] ?? null,
                        'company' => $task['company_name'] ?? '',
                        'priority' => $task['priority'] ?? '',
                    ],
                ]
            );
        }

        logActivity("Work status updated: {$task['task_number']} → {$newWorkStatusName}", 'tasks');
        setFlash('success', 'Work status updated.' . ($newWorkStatusName === 'Done' ? ' Admin has been notified.' : ''));
        header("Location: view.php?id={$id}");
        exit;
    }
}

// ── HANDLE: Transfer to another staff ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_staff'])) {
    verifyCsrf();
    $newStaffId = (int) ($_POST['new_staff_id'] ?? 0);
    $transferNote = trim($_POST['transfer_note'] ?? '');

    if (!$newStaffId) {
        setFlash('error', 'Please select a staff member.');
        header("Location: view.php?id={$id}");
        exit;
    }

    // Verify staff is same branch & dept
    $verifyStmt = $db->prepare("
        SELECT id, full_name, email FROM users
        WHERE id = ? AND branch_id = ? AND department_id = ? AND is_active = 1
    ");
    $verifyStmt->execute([
        $newStaffId,
        $staffProfile['branch_id'],
        $staffProfile['department_id']
    ]);
    $newStaff = $verifyStmt->fetch();

    if (!$newStaff) {
        setFlash('error', 'Invalid staff selection.');
        header("Location: view.php?id={$id}");
        exit;
    }

    // Update assigned_to AND save transfer note as remarks on the task
    $db->prepare("
        UPDATE tasks SET
            assigned_to = ?,
            remarks     = ?,
            updated_at  = NOW()
        WHERE id = ?
    ")->execute([
                $newStaffId,
                $transferNote ?: $task['remarks'],
                $id
            ]);

    // Log workflow
    try {
        $db->prepare("
            INSERT INTO task_workflow
            (task_id, action, from_user_id, to_user_id,
             from_dept_id, to_dept_id, old_status, new_status, remarks)
            VALUES (?, 'transferred_staff', ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
                    $id,
                    $user['id'],
                    $newStaffId,
                    $task['department_id'],
                    $task['department_id'],
                    $task['status'],
                    $task['status'],
                    $transferNote
                ]);
    } catch (Exception $e) {
    }

    // Notify new staff with the note as their work instruction
    notify(
        $newStaffId,
        'Task Assigned to You',
        "Task {$task['task_number']} — \"{$task['title']}\" has been transferred to you by {$staffProfile['full_name']}." .
        ($transferNote ? "\n\n📋 Work Instructions:\n{$transferNote}" : ''),
        'transfer',
        APP_URL . '/staff/tasks/view.php?id=' . $id
    );

    logActivity("Task transferred to staff: {$task['task_number']}", 'tasks');
    setFlash('success', "Task transferred to {$newStaff['full_name']} successfully.");
    header('Location: index.php');
    exit;
}

// ── HANDLE: Mark as completed ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed'])) {
    verifyCsrf();
    $completionNote = trim($_POST['completion_note'] ?? '');

    // Get Done status id
    $doneId = $db->query("SELECT id FROM task_status WHERE status_name = 'Done'")->fetchColumn();

    // Update main task status
    $db->prepare("
        UPDATE tasks SET
            status_id  = ?,
            remarks    = ?,
            updated_at = NOW()
        WHERE id = ?
    ")->execute([
                $doneId,
                $completionNote ?: $task['remarks'],
                $id
            ]);

    // Update retail detail if applicable
    if ($task['dept_code'] === 'RETAIL' && $detail) {
        // Get Done status for retail fields too
        $db->prepare("
            UPDATE task_retail SET
                work_status_id         = ?,
                finalisation_status_id = ?,
                completed_date         = CURDATE(),
                notes                  = ?
            WHERE task_id = ?
        ")->execute([
                    $doneId,
                    $doneId,
                    $completionNote ?: $detail['notes'],
                    $id
                ]);
    }

    // Log workflow
    try {
        $db->prepare("
            INSERT INTO task_workflow
            (task_id, action, from_user_id, old_status, new_status, remarks)
            VALUES (?, 'completed', ?, ?, 'Done', ?)
        ")->execute([
                    $id,
                    $user['id'],
                    $task['status'],
                    $completionNote
                ]);
    } catch (Exception $e) {
    }

    // Notify admin
    notify(
        $task['created_by'],
        'Task Completed ✓',
        "Task {$task['task_number']} — \"{$task['title']}\" has been completed by {$staffProfile['full_name']}." .
        ($completionNote ? "\n\n📋 Completion Note:\n{$completionNote}" : '') .
        "\n\nPlease review and transfer to the next department if required.",
        'status',
        APP_URL . '/admin/tasks/view.php?id=' . $id
    );

    logActivity("Task completed: {$task['task_number']}", 'tasks');
    setFlash('success', 'Task marked as completed. Admin has been notified.');
    header("Location: view.php?id={$id}");
    exit;
}
// ── HANDLE: Update main status ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    verifyCsrf();
    $newStatus = $_POST['new_status'] ?? '';
    // Validate against DB — not TASK_STATUSES keys which may differ
    $validStatuses = array_column(
        $db->query("SELECT status_name FROM task_status")->fetchAll(),
        'status_name'
    );
    if (in_array($newStatus, $validStatuses)) {
        $stRow = $db->prepare("SELECT id FROM task_status WHERE status_name = ?");
        $stRow->execute([$newStatus]);
        $newStatusId = (int) ($stRow->fetchColumn() ?: 1);
        $db->prepare("UPDATE tasks SET status_id=?, updated_at=NOW() WHERE id=?")
            ->execute([$newStatusId, $id]);

        // Log workflow
        try {
            $db->prepare("INSERT INTO task_workflow(task_id,action,from_user_id,old_status,new_status)
                          VALUES(?,?,?,?,?)")
                ->execute([$id, 'status_changed', $user['id'], $task['status'], $newStatus]);
        } catch (Exception $e) {
        }

        // Build notification message
        $msg = "Task #{$task['task_number']}";
        if (!empty($task['company_name']))
            $msg .= " ({$task['company_name']})";
        $msg .= " — status changed from \"{$task['status']}\" to \"{$newStatus}\" by {$staffProfile['full_name']}.";

        // Notify task creator/admin
        if (!empty($task['created_by']) && $task['created_by'] != $user['id']) {
            notify(
                (int) $task['created_by'],
                "Status Updated: {$task['task_number']}",
                $msg,
                'status',
                APP_URL . '/admin/tasks/view.php?id=' . $id,
                true,
                [
                    'template' => 'task_status_changed',
                    'task' => [
                        'id' => $id,
                        'task_number' => $task['task_number'],
                        'title' => $task['title'],
                        'old_status' => $task['status'],
                        'new_status' => $newStatus,
                        'due_date' => $task['due_date'] ?? null,
                        'company' => $task['company_name'] ?? '',
                        'priority' => $task['priority'] ?? '',
                    ],
                ]
            );
        }

        logActivity("Status update: {$task['task_number']} → {$newStatus}", 'tasks');
        setFlash('success', 'Status updated.');
        header("Location: view.php?id={$id}");
        exit;
    }
}

// ── HANDLE: Comment ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    verifyCsrf();
    $comment = trim($_POST['comment'] ?? '');
    if ($comment) {
        try {
            $db->prepare("INSERT INTO task_comments (task_id,user_id,comment) VALUES (?,?,?)")
                ->execute([$id, $user['id'], $comment]);
        } catch (Exception $e) {
        }
        header("Location: view.php?id={$id}#comments");
        exit;
    }
}

$pageTitle = 'Task: ' . $task['task_number'];
$sClass = 'status-' . strtolower(str_replace(' ', '-', $task['status'] ?? ''));
$isMyTask = $task['assigned_to'] == $user['id'];
$isDone = $task['status'] === 'Done';

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div style="padding:1.5rem 0;">
            <?= flashHtml() ?>

            <!-- Back -->
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
                <div class="d-flex align-items-center gap-2">
                    <span class="task-number"><?= htmlspecialchars($task['task_number']) ?></span>
                    <span class="status-badge <?= $sClass ?>"><?= htmlspecialchars($task['status'] ?? '') ?></span>
                </div>
            </div>

            <div class="row g-4">

                <!-- ── LEFT COLUMN ── -->
                <div class="col-lg-8">

                    <!-- Task Info -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <div>
                                <span
                                    class="task-number d-block mb-1"><?= htmlspecialchars($task['task_number']) ?></span>
                                <h5 style="font-size:1.1rem;"><?= htmlspecialchars($task['title']) ?></h5>
                            </div>
                            <?php
                            $isOverdue = $task['due_date'] && strtotime($task['due_date']) < time() && !$isDone;
                            if ($isOverdue): ?>
                                <span class="badge" style="background:#fef2f2;color:#ef4444;font-size:.75rem;">
                                    ⚠️ OVERDUE
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3">
                                <?php
                                $infoFields = [
                                    'Department' => htmlspecialchars($task['dept_name'] ?? '—'),
                                    'Branch' => htmlspecialchars($task['branch_name'] ?? '—'),
                                    'Company' => htmlspecialchars($task['company_name'] ?? '—'),
                                    'Assigned By' => htmlspecialchars($task['created_by_name'] ?? '—'),
                                    'Assigned To' => htmlspecialchars($task['assigned_to_name'] ?? 'Unassigned'),
                                    'Priority' => '<span class="status-badge priority-' . $task['priority'] . '">' . ucfirst($task['priority']) . '</span>',
                                    'Due Date' => '<span style="' . ($isOverdue ? 'color:#ef4444;font-weight:600;' : '') . '">' .
                                        ($task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : '—') . '</span>',
                                    'Fiscal Year' => htmlspecialchars($task['fiscal_year'] ?? '—'),
                                    'Created' => date('d M Y, H:i', strtotime($task['created_at'])),
                                ];
                                foreach ($infoFields as $label => $val):
                                    ?>
                                    <div class="col-md-4">
                                        <div
                                            style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                            <?= $label ?>
                                        </div>
                                        <div style="font-size:.9rem;margin-top:.2rem;"><?= $val ?></div>
                                    </div>
                                <?php endforeach; ?>

                                <?php if ($task['description']): ?>
                                    <div class="col-12">
                                        <div
                                            style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                            Description</div>
                                        <div style="font-size:.88rem;margin-top:.2rem;">
                                            <?= nl2br(htmlspecialchars($task['description'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($task['remarks']): ?>
                                    <div class="col-12">
                                        <div
                                            style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                            Remarks</div>
                                        <div style="font-size:.88rem;margin-top:.2rem;">
                                            <?= nl2br(htmlspecialchars($task['remarks'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Retail Details (read-only) -->
                    <?php if ($task['dept_code'] === 'RETAIL' && $detail): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-store text-warning me-2"></i>Retail Details</h5>
                            </div>
                            <div class="card-mis-body">
                                <div class="row g-3">
                                    <?php
                                    $retailFields = [
                                        'Firm Name' => $detail['firm_name'] ?? '—',
                                        'Company Type' => $detail['company_type_name'] ?? '—',
                                        'File Type' => $detail['file_type_name'] ?? '—',
                                        'PAN / VAT' => $detail['pan_vat_name'] ?? '—',
                                        'VAT Client' => $detail['vat_client_value'] ?? '—',
                                        'Return Type' => $detail['return_type'] ?? '—',
                                        'Fiscal Year' => $detail['fiscal_year'] ?? '—',
                                        'No. of Audit Years' => $detail['no_of_audit_year'] ?? '—',
                                        'PAN No' => $detail['pan_no'] ?? '—',
                                        'Assigned Date' => $detail['assigned_date'] ? date('d M Y', strtotime($detail['assigned_date'])) : '—',
                                        'Audit Type' => $detail['audit_type_name'] ?? '—',
                                        'ECD' => $detail['ecd'] ? date('d M Y', strtotime($detail['ecd'])) : '—',
                                        'Opening Due' => $detail['opening_due'] ?? '—',
                                        'Work Status' => $detail['work_status_name'] ?? '—',
                                        'Finalisation Status' => $detail['finalisation_status_name'] ?? '—',
                                        'Finalised By' => $detail['finalised_by_name'] ?? '—',
                                        'Completed Date' => $detail['completed_date'] ? date('d M Y', strtotime($detail['completed_date'])) : '—',
                                        'Tax Clearance Status' => $detail['tax_clearance_status_name'] ?? '—',
                                        'Backup Status' => $detail['backup_status_value'] ?? '—',
                                        'Follow-up Date' => $detail['follow_up_date'] ? date('d M Y', strtotime($detail['follow_up_date'])) : '—',
                                        'Notes' => $detail['notes'] ?? '—',
                                    ];
                                    foreach ($retailFields as $label => $val):
                                        ?>
                                        <div class="col-md-4">
                                            <div
                                                style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                                <?= $label ?>
                                            </div>
                                            <div style="font-size:.88rem;margin-top:.2rem;"><?= htmlspecialchars($val) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($detail): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i
                                        class="fas fa-table text-warning me-2"></i><?= htmlspecialchars($task['dept_name']) ?>
                                    Details</h5>
                            </div>
                            <div class="card-mis-body">
                                <div class="row g-3">
                                    <?php foreach ($detail as $key => $val):
                                        if (in_array($key, ['id', 'task_id']) || $val === null || $val === '')
                                            continue;
                                        $label = ucwords(str_replace('_', ' ', $key));
                                        ?>
                                        <div class="col-md-4">
                                            <div
                                                style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                                <?= $label ?>
                                            </div>
                                            <div style="font-size:.88rem;margin-top:.2rem;"><?= htmlspecialchars($val) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Workflow History -->
                    <?php if (!empty($workflow)): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-code-branch text-warning me-2"></i>Workflow History</h5>
                            </div>
                            <div class="card-mis-body">
                                <div style="padding-left:1rem;">
                                    <?php foreach ($workflow as $w): ?>
                                        <div
                                            style="position:relative;margin-bottom:1rem;padding-left:1.2rem;border-left:2px solid #f3f4f6;">
                                            <div
                                                style="position:absolute;left:-6px;top:4px;width:10px;height:10px;border-radius:50%;background:#c9a84c;border:2px solid #fff;">
                                            </div>
                                            <div
                                                style="font-size:.82rem;font-weight:600;color:#1f2937;text-transform:capitalize;">
                                                <?= htmlspecialchars(str_replace('_', ' ', $w['action'])) ?>
                                            </div>
                                            <div style="font-size:.75rem;color:#6b7280;margin-top:.1rem;">
                                                <?php if ($w['from_user_name']): ?>
                                                    by <?= htmlspecialchars($w['from_user_name']) ?>
                                                <?php endif; ?>
                                                <?php if ($w['to_user_name']): ?>
                                                    → <?= htmlspecialchars($w['to_user_name']) ?>
                                                <?php endif; ?>
                                                <?php if ($w['from_dept_name'] && $w['to_dept_name'] && $w['from_dept_name'] !== $w['to_dept_name']): ?>
                                                    · <?= htmlspecialchars($w['from_dept_name']) ?>
                                                    <i class="fas fa-arrow-right mx-1" style="font-size:.65rem;"></i>
                                                    <?= htmlspecialchars($w['to_dept_name']) ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($w['remarks']): ?>
                                                <div style="font-size:.73rem;color:#9ca3af;font-style:italic;margin-top:.1rem;">
                                                    "<?= htmlspecialchars($w['remarks']) ?>"
                                                </div>
                                            <?php endif; ?>
                                            <div style="font-size:.7rem;color:#d1d5db;margin-top:.1rem;">
                                                <?= date('d M Y, H:i', strtotime($w['created_at'])) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Comments -->
                    <div class="card-mis" id="comments">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-comments text-warning me-2"></i>Comments (<?= count($comments) ?>)</h5>
                        </div>
                        <div class="card-mis-body">
                            <?php foreach ($comments as $c): ?>
                                <div class="d-flex gap-3 mb-3">
                                    <div class="avatar-circle avatar-sm flex-shrink-0">
                                        <?= strtoupper(substr($c['full_name'], 0, 2)) ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex gap-2 align-items-center">
                                            <strong
                                                style="font-size:.85rem;"><?= htmlspecialchars($c['full_name']) ?></strong>
                                            <span
                                                style="font-size:.72rem;color:#9ca3af;"><?= date('M j, Y H:i', strtotime($c['created_at'])) ?></span>
                                        </div>
                                        <div
                                            style="font-size:.88rem;margin-top:.2rem;background:#f9fafb;padding:.6rem .9rem;border-radius:8px;">
                                            <?= nl2br(htmlspecialchars($c['comment'])) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($comments)): ?>
                                <div class="text-muted text-center py-3" style="font-size:.85rem;">No comments yet.</div>
                            <?php endif; ?>
                            <form method="POST" class="mt-3 d-flex gap-2">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="add_comment" value="1">
                                <input type="text" name="comment" class="form-control" placeholder="Add a comment…"
                                    required>
                                <button type="submit" class="btn btn-gold btn-sm flex-shrink-0">Post</button>
                            </form>
                        </div>
                    </div>

                </div><!-- end col-lg-8 -->

                <!-- ── RIGHT COLUMN ── -->
                <div class="col-lg-4">

                    <?php if ($isMyTask && !$isDone): ?>

                        <!-- 1. Update Work Status -->
                        <?php if ($task['dept_code'] === 'RETAIL' && $detail): ?>
                            <div class="card-mis mb-3" style="border-left:3px solid #f59e0b;">
                                <div class="card-mis-header">
                                    <h5><i class="fas fa-tasks text-warning me-2"></i>Update Work Status</h5>
                                </div>
                                <div class="card-mis-body">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="update_work_status" value="1">
                                        <div class="mb-3">
                                            <label class="form-label-mis">Work Status</label>
                                            <select name="work_status_id" class="form-select form-select-sm" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach ($taskStatuses as $ts): ?>
                                                    <option value="<?= $ts['id'] ?>" <?= ($detail['work_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($ts['status_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label-mis">Notes</label>
                                            <textarea name="work_notes" class="form-control form-control-sm" rows="2"
                                                placeholder="Add a note about your progress..."><?= htmlspecialchars($detail['notes'] ?? '') ?></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-gold w-100 btn-sm">
                                            <i class="fas fa-save me-1"></i>Save Work Status
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Non-retail status update -->
                            <div class="card-mis mb-3" style="border-left:3px solid #f59e0b;">
                                <div class="card-mis-header">
                                    <h5><i class="fas fa-circle-dot text-warning me-2"></i>Update Status</h5>
                                </div>
                                <div class="card-mis-body">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <div class="mb-3">
                                            <?php foreach ($taskStatuses as $ts):
                                                $statusKey = strtolower(str_replace(' ', '-', $ts['status_name']));
                                                $isChecked = ($task['status'] ?? '') === $ts['status_name'];
                                                $statusColor = $ts['color'] ?? '#9ca3af';
                                                ?>
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="radio" name="new_status"
                                                        value="<?= htmlspecialchars($ts['status_name']) ?>" id="st_<?= $ts['id'] ?>"
                                                        <?= $isChecked ? 'checked' : '' ?>>
                                                    <label class="form-check-label d-flex align-items-center gap-2"
                                                        for="st_<?= $ts['id'] ?>">
                                                        <span style="
                        display:inline-flex;
                        align-items:center;
                        gap:.4rem;
                        font-size:.78rem;
                        font-weight:600;
                        color:<?= $statusColor ?>;
                        background:<?= $statusColor ?>18;
                        padding:.25rem .65rem;
                        border-radius:99px;
                        border:1px solid <?= $statusColor ?>44;
                    ">
                                                            <span
                                                                style="width:6px;height:6px;border-radius:50%;background:<?= $statusColor ?>;flex-shrink:0;"></span>
                                                            <?= htmlspecialchars($ts['status_name']) ?>
                                                        </span>
                                                        <?php if ($isChecked): ?>
                                                            <span style="font-size:.68rem;color:#9ca3af;">(current)</span>
                                                        <?php endif; ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="submit" class="btn btn-gold w-100 btn-sm">
                                            <i class="fas fa-save me-1"></i>Update Status
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- 2. Transfer to Another Staff -->
                        <?php if (!empty($sameBranchStaff)): ?>
                            <div class="card-mis mb-3" style="border-left:3px solid #3b82f6;">
                                <div class="card-mis-header">
                                    <h5><i class="fas fa-exchange-alt me-2" style="color:#3b82f6;"></i>Transfer to Staff</h5>
                                </div>
                                <div class="card-mis-body">
                                    <p style="font-size:.77rem;color:#9ca3af;margin-bottom:.75rem;">
                                        Transfer this task to another staff member in your branch and department.
                                    </p>
                                    <form method="POST" onsubmit="return confirm('Transfer this task to selected staff?');">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="transfer_staff" value="1">
                                        <div class="mb-2">
                                            <label class="form-label-mis">Select Staff</label>
                                            <select name="new_staff_id" class="form-select form-select-sm" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach ($sameBranchStaff as $s): ?>
                                                    <option value="<?= $s['id'] ?>">
                                                        <?= htmlspecialchars($s['full_name']) ?>
                                                        (<?= htmlspecialchars($s['employee_id'] ?? '') ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label-mis">Transfer Note</label>
                                            <textarea name="transfer_note" class="form-control form-control-sm" rows="2"
                                                placeholder="What work has been done / what needs to be done..."></textarea>
                                        </div>
                                        <button type="submit" class="btn w-100 btn-sm"
                                            style="background:#3b82f6;color:#fff;border:none;border-radius:8px;padding:.5rem;">
                                            <i class="fas fa-exchange-alt me-1"></i>Transfer Task
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- 3. Mark as Completed — notifies admin -->
                        <div class="card-mis mb-3" style="border-left:3px solid #10b981;">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-check-double me-2" style="color:#10b981;"></i>Mark as Completed</h5>
                            </div>
                            <div class="card-mis-body">
                                <p style="font-size:.77rem;color:#9ca3af;margin-bottom:.75rem;">
                                    Mark this task as fully completed. The admin will be notified and can then transfer it
                                    to the next department if needed.
                                </p>
                                <form method="POST"
                                    onsubmit="return confirm('Mark this task as completed? Admin will be notified.');">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="mark_completed" value="1">
                                    <div class="mb-2">
                                        <label class="form-label-mis">Completion Note</label>
                                        <textarea name="completion_note" class="form-control form-control-sm" rows="2"
                                            placeholder="Summary of work completed..."></textarea>
                                    </div>
                                    <button type="submit" class="btn w-100 btn-sm"
                                        style="background:#10b981;color:#fff;border:none;border-radius:8px;padding:.5rem;">
                                        <i class="fas fa-check-double me-1"></i>Complete & Notify Admin
                                    </button>
                                </form>
                            </div>
                        </div>

                    <?php elseif ($isDone): ?>
                        <!-- Task is completed -->
                        <div class="card-mis mb-3" style="border-left:3px solid #10b981;">
                            <div class="card-mis-body text-center py-4">
                                <i class="fas fa-check-circle fa-2x mb-2 d-block" style="color:#10b981;"></i>
                                <div style="font-size:.9rem;font-weight:600;color:#10b981;">Task Completed</div>
                                <div style="font-size:.78rem;color:#9ca3af;margin-top:.3rem;">
                                    Admin has been notified and will handle next steps.
                                </div>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Task assigned to someone else — read only -->
                        <div class="card-mis mb-3" style="border-left:3px solid #9ca3af;">
                            <div class="card-mis-body text-center py-4">
                                <i class="fas fa-eye fa-2x mb-2 d-block" style="color:#9ca3af;"></i>
                                <div style="font-size:.88rem;color:#6b7280;">
                                    This task is assigned to<br>
                                    <strong><?= htmlspecialchars($task['assigned_to_name'] ?? '—') ?></strong>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Task Meta -->
                    <div class="card-mis p-3" style="font-size:.8rem;color:#6b7280;">
                        <div class="mb-2"><strong>Task #:</strong> <?= htmlspecialchars($task['task_number']) ?></div>
                        <div class="mb-2"><strong>Dept:</strong> <?= htmlspecialchars($task['dept_name'] ?? '—') ?>
                        </div>
                        <div class="mb-2"><strong>Priority:</strong>
                            <span class="status-badge priority-<?= $task['priority'] ?>">
                                <?= ucfirst($task['priority']) ?>
                            </span>
                        </div>
                        <div class="mb-2"><strong>Due Date:</strong>
                            <span style="<?= $isOverdue ? 'color:#ef4444;font-weight:600;' : '' ?>">
                                <?= $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : '—' ?>
                                <?= $isOverdue ? ' ⚠️' : '' ?>
                            </span>
                        </div>
                        <div class="mb-2"><strong>Created:</strong> <?= date('d M Y', strtotime($task['created_at'])) ?>
                        </div>
                        <div><strong>Updated:</strong> <?= date('d M Y', strtotime($task['updated_at'])) ?></div>
                    </div>

                </div><!-- end col-lg-4 -->

            </div><!-- end row -->
        </div>
        <?php include '../../includes/footer.php'; ?>