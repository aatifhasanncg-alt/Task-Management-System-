<?php
// staff/tasks/today.php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();
if ($_SESSION['role'] !== 'staff') {
    header('Location: ' . APP_URL . '/' . $_SESSION['role'] . '/dashboard/index.php');
    exit;
}

$db = getDB();
$user = currentUser();
$pageTitle = "Today's Tasks";
$today = date('Y-m-d');

$tasks = $db->prepare("
    SELECT t.*, 
           ts.status_name AS status,
           d.dept_name, d.color as dept_color, d.icon as dept_icon,
           c.company_name, b.branch_name,
           u2.full_name as created_by_name
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN departments d ON d.id=t.department_id
    LEFT JOIN companies c   ON c.id=t.company_id
    LEFT JOIN branches b    ON b.id=t.branch_id
    LEFT JOIN users u2      ON u2.id=t.created_by
    WHERE t.assigned_to=? AND t.is_active=1
      AND (
            t.due_date=? 
            OR 
            (t.due_date IS NULL AND ts.status_name IN('Pending','WIP','HBC'))
          )
      AND ts.status_name != 'Done'
    ORDER BY
      FIELD(t.priority,'urgent','high','medium','low'),
      t.due_date ASC
");
$tasks->execute([$user['id'], $today]);
$taskList = $tasks->fetchAll();

// Overdue (due_date < today, not complete)
$overdue = $db->prepare("
    SELECT t.*, ts.status_name AS status,
           d.dept_name, d.color as dept_color, c.company_name
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN departments d ON d.id=t.department_id
    LEFT JOIN companies c   ON c.id=t.company_id
    WHERE t.assigned_to=? AND t.is_active=1
      AND t.due_date < ?
      AND ts.status_name != 'Done'
    ORDER BY t.due_date ASC LIMIT 10
");
$overdue->execute([$user['id'], $today]);
$overdueList = $overdue->fetchAll();

include '../../includes/header.php';
?>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
<div class="app-wrapper">
    <?php include '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <div class="page-hero">
                <div class="page-hero-badge"><i class="fas fa-sun"></i> Today</div>
                <h4>Today's Tasks</h4>
                <p><?= date('l, F j, Y') ?> · <?= count($taskList) ?> task<?= count($taskList) !== 1 ? 's' : '' ?> due
                    today
                </p>
            </div>

            <?= flashHtml() ?>

            <!-- Overdue warning -->
            <?php if (!empty($overdueList)): ?>
                <div class="alert alert-danger rounded-3 mb-4 d-flex align-items-start gap-2">
                    <i class="fas fa-exclamation-triangle mt-1 flex-shrink-0"></i>
                    <div>
                        <strong>Overdue Tasks (<?= count($overdueList) ?>)</strong>
                        <div style="font-size:.83rem;">These tasks are past their due date and need immediate attention.
                        </div>
                        <div class="d-flex gap-2 flex-wrap mt-2">
                            <?php foreach (array_slice($overdueList, 0, 3) as $ot): ?>
                                <a href="view.php?id=<?= $ot['id'] ?>" class="badge bg-danger text-decoration-none">
                                    <?= htmlspecialchars($ot['task_number']) ?>
                                </a>
                            <?php endforeach; ?>
                            <?php if (count($overdueList) > 3): ?><span class="text-muted"
                                    style="font-size:.78rem;">+<?= count($overdueList) - 3 ?> more</span><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($taskList)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle" style="color:#10b981;"></i>
                    <h5>All caught up!</h5>
                    <p>No tasks due today. Check tomorrow's tasks or your pending tasks.</p>
                    <div class="d-flex gap-2 justify-content-center mt-2">
                        <a href="tomorrow.php" class="btn-gold btn btn-sm">Tomorrow's Tasks</a>
                        <a href="index.php" class="btn btn-outline-secondary btn-sm">All Tasks</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($taskList as $t):
                    $priorityColors = ['urgent' => '#ef4444', 'high' => '#f59e0b', 'medium' => '#3b82f6', 'low' => '#9ca3af'];
                    $pColor = $priorityColors[$t['priority']] ?? '#9ca3af';
                    $isOverdueRow = $t['due_date'] && $t['due_date'] < $today;
                    ?>
                    <div class="task-card <?= $isOverdueRow ? 'overdue' : '' ?>" style="--task-color:<?= $t['dept_color'] ?>;">
                        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
                            <div style="flex:1;">
                                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                    <span class="task-number-badge"><?= htmlspecialchars($t['task_number']) ?></span>
                                    <span class="dept-chip"
                                        style="background:<?= $t['dept_color'] ?>22;color:<?= $t['dept_color'] ?>;border:1px solid <?= $t['dept_color'] ?>44;">
                                        <i class="fas <?= $t['dept_icon'] ?> me-1"
                                            style="font-size:.65rem;"></i><?= htmlspecialchars($t['dept_name']) ?>
                                    </span>
                                    <span
                                        class="status-badge status-<?= strtolower(str_replace([' '], '-', $t['status'])) ?>"><?= htmlspecialchars($t['status']) ?></span>
                                    <span
                                        style="font-size:.72rem;font-weight:700;color:<?= $pColor ?>;padding:.1rem .4rem;background:<?= $pColor ?>15;border-radius:4px;text-transform:uppercase;">
                                        <?= ucfirst($t['priority']) ?>
                                    </span>
                                    <?php if ($isOverdueRow): ?>
                                        <span class="badge bg-danger" style="font-size:.7rem;">OVERDUE</span>
                                    <?php endif; ?>
                                </div>
                                <h5 style="font-size:.97rem;font-weight:600;color:#1f2937;margin:.25rem 0;">
                                    <?= htmlspecialchars($t['title']) ?>
                                </h5>
                                <div class="d-flex gap-3 flex-wrap" style="font-size:.78rem;color:#9ca3af;">
                                    <?php if ($t['company_name']): ?>
                                        <span><i class="fas fa-building me-1"></i><?= htmlspecialchars($t['company_name']) ?></span>
                                    <?php endif; ?>
                                    <span><i
                                            class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($t['branch_name'] ?? '') ?></span>
                                    <span><i class="fas fa-user-plus me-1"></i>By:
                                        <?= htmlspecialchars($t['created_by_name'] ?? '') ?></span>
                                    <?php if ($t['due_date']): ?>
                                        <span
                                            style="color:<?= $isOverdueRow ? '#ef4444' : 'inherit' ?>;font-weight:<?= $isOverdueRow ? '600' : '400' ?>;">
                                            <i class="fas fa-calendar-alt me-1"></i>Due:
                                            <?= date('M j', strtotime($t['due_date'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($t['description']): ?>
                                    <p style="font-size:.82rem;color:#6b7280;margin:.5rem 0 0;line-height:1.5;">
                                        <?= htmlspecialchars(mb_strimwidth($t['description'], 0, 120, '…')) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-2 flex-shrink-0">
                                <!-- Quick status update -->
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"
                                        style="font-size:.78rem;">
                                        <i class="fas fa-tag me-1"></i>Status
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end" style="min-width:160px; z-index:1055;">
                                        <?php
                                        $allStatuses = $db->query("SELECT id, status_name, color FROM task_status ORDER BY id")->fetchAll();
                                        foreach ($allStatuses as $s):
                                            if ($s['status_name'] === $t['status']) continue;
                                        ?>
                                            <li>
                                                <a class="dropdown-item" href="#" style="font-size:.82rem;"
                                                    onclick="quickStatus(<?= $t['id'] ?>,'<?= htmlspecialchars($s['status_name']) ?>',this);return false;">
                                                    <span
                                                        style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($s['color'] ?? '#9ca3af') ?>;display:inline-block;margin-right:.4rem;"></span>
                                                    <?= htmlspecialchars($s['status_name']) ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>

                                <!-- Transfer button -->
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                    data-bs-target="#transferModal" onclick="setTransferTask(<?= (int) $t['id'] ?>)">
                                    <i class="fas fa-exchange-alt me-1"></i>Transfer
                                </button>
                                <script>
                                    function setTransferTask(taskId) {
                                        document.getElementById('transfer_task_id').value = taskId;
                                    }
                                </script>
                                <!-- View button -->
                                <a href="view.php?id=<?= $t['id'] ?>" class="btn-gold btn btn-sm">
                                    <i class="fas fa-eye me-1"></i>View
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>

        <!-- Transfer modal -->
        <div class="modal fade" id="transferModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header" style="background:#0a0f1e;">
                        <h5 class="modal-title text-white">
                            <i class="fas fa-exchange-alt me-2 text-warning"></i>Transfer Task
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="transfer_task_id">

                        <div class="mb-3">
                            <label class="form-label-mis">Transfer To (Staff) <span
                                    style="color:#ef4444;">*</span></label>
                            <select id="transfer_to_user" class="form-select">
                                <option value="">-- Select Staff --</option>
                                <?php
                                // Only fetch staff in the same department and branch as the logged-in user
                                $stmt = $db->prepare("
                            SELECT id, full_name 
                            FROM users 
                            WHERE role_id = (SELECT id FROM roles WHERE role_name='staff') 
                              AND department_id = ? 
                              AND branch_id = ?
                        ");
                                $stmt->execute([$user['department_id'], $user['branch_id']]);
                                $staffList = $stmt->fetchAll();
                                foreach ($staffList as $s):
                                    echo '<option value="' . $s['id'] . '">' . htmlspecialchars($s['full_name']) . '</option>';
                                endforeach;
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn-gold btn btn-sm" onclick="submitTransfer()">
                            <i class="fas fa-paper-plane me-1"></i>Transfer
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php include '../../includes/footer.php'; ?>

        <script>
            window.quickStatus = function (taskId, newStatus, el) {
                if (!taskId) {
                    alert('Invalid task');
                    return;
                }

                if (!confirm(`Change status to "${newStatus}"?`)) return;

                const remarks = prompt('Add a remark (optional):') || '';

                updateTaskStatus(taskId, 'status_change', {
                    new_status: newStatus,
                    remarks: remarks
                }).then(r => {
                    if (r.ok) {
                        alert(r.msg);
                        location.reload();
                    } else {
                        alert(r.msg || 'Failed');
                    }
                });
            };

            window.submitTransfer = function () {
                const taskId = document.getElementById('transfer_task_id').value;
                const toUser = document.getElementById('transfer_to_user').value;

                if (!taskId) {
                    alert('Invalid task');
                    return;
                }

                if (!toUser) {
                    alert('Select staff');
                    return;
                }

                const remarks = prompt('Remarks (optional):') || '';

                updateTaskStatus(taskId, 'transfer_staff', {
                    to_user_id: toUser,
                    remarks: remarks,
                    time_spent: 0
                }).then(r => {
                    if (r.ok) {
                        alert(r.msg);
                        location.reload();
                    } else {
                        alert(r.msg || 'Transfer failed');
                    }
                });
            };

            async function updateTaskStatus(taskId, action, data = {}) {
                const formData = new FormData();
                formData.append('task_id', taskId);
                formData.append('action', action);

                for (const key in data) {
                    formData.append(key, data[key]);
                }

                try {
                    const res = await fetch('<?= APP_URL ?>/ajax/update_task_status.php', {
                        method: 'POST',
                        body: formData
                    });

                    const text = await res.text();
                    console.log("RAW RESPONSE:", text);

                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error("Invalid JSON:", text);
                        return { ok: false, msg: "Invalid server response" };
                    }

                } catch (e) {
                    console.error(e);
                    return { ok: false, msg: 'Request failed' };
                }
            }
            function markOne(id) {
                const fd = new FormData();
                fd.append('id', id);

                fetch('<?= APP_URL ?>/ajax/mark_notification_read.php', {
                    method: 'POST',
                    body: fd
                });
            }
        </script>