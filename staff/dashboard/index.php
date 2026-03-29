<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];
$pageTitle = 'My Dashboard';

// ── Scope: tasks assigned to OR transferred to this staff ─────────────────────
$scopeSub = "
    SELECT DISTINCT t.id FROM tasks t
    WHERE t.is_active = 1
      AND (
          t.assigned_to = {$uid}
          OR EXISTS (
              SELECT 1 FROM task_workflow tw
              WHERE tw.task_id    = t.id
                AND tw.to_user_id = {$uid}
                AND tw.action IN ('transferred_staff','transferred_dept')
          )
      )
";

// ── Status counts ─────────────────────────────────────────────────────────────
$byStatusStmt = $db->query("
    SELECT ts.status_name, COUNT(DISTINCT t.id) AS cnt
    FROM tasks t
    JOIN task_status ts ON ts.id = t.status_id
    WHERE t.id IN ({$scopeSub})
      AND ts.status_name != 'Corporate Team'
    GROUP BY ts.status_name
");
$byStatus = array_column($byStatusStmt->fetchAll(), 'cnt', 'status_name');
$total    = array_sum($byStatus);

// ── Overdue count ─────────────────────────────────────────────────────────────
$overdueStmt = $db->query("
    SELECT COUNT(DISTINCT t.id)
    FROM tasks t
    JOIN task_status ts ON ts.id = t.status_id
    WHERE t.id IN ({$scopeSub})
      AND t.due_date < CURDATE()
      AND ts.status_name != 'Done'
");
$overdue = (int)$overdueStmt->fetchColumn();

// ── Transferred count (informational) ────────────────────────────────────────
$xferStmt = $db->query("
    SELECT COUNT(DISTINCT t.id)
    FROM tasks t
    WHERE t.is_active = 1
      AND t.assigned_to != {$uid}
      AND EXISTS (
          SELECT 1 FROM task_workflow tw
          WHERE tw.task_id    = t.id
            AND tw.to_user_id = {$uid}
            AND tw.action IN ('transferred_staff','transferred_dept')
      )
");
$transferredCount = (int)$xferStmt->fetchColumn();

// ── All dynamic statuses for stat cards ──────────────────────────────────────
$allStatuses = $db->query("SELECT id, status_name, color, bg_color, icon FROM task_status ORDER BY id")->fetchAll();

// ── Recent tasks — scoped ─────────────────────────────────────────────────────
$myTasksStmt = $db->query("
    SELECT t.*,
           ts.status_name  AS status,
           d.dept_name, d.color,
           c.company_name,
           cb.full_name    AS assigned_by_name,
           CASE WHEN t.assigned_to = {$uid} THEN 0 ELSE 1 END AS is_transferred
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN departments d  ON d.id  = t.department_id
    LEFT JOIN companies   c  ON c.id  = t.company_id
    LEFT JOIN users       cb ON cb.id = t.created_by
    WHERE t.id IN ({$scopeSub})
      AND ts.status_name != 'Corporate Team'
    ORDER BY
        CASE ts.status_name
            WHEN 'Pending'     THEN 1
            WHEN 'WIP'         THEN 2
            WHEN 'HBC'         THEN 3
            WHEN 'Not Started' THEN 4
            WHEN 'Next Year'   THEN 5
            WHEN 'Done'        THEN 6
            ELSE 7
        END,
        t.due_date ASC
    LIMIT 10
");
$myTasks = $myTasksStmt->fetchAll();

// Icon map for stat cards
$statusIcons = [
    'Not Started' => ['fa-circle',          '#9ca3af', '#f3f4f6'],
    'WIP'         => ['fa-spinner',          '#f59e0b', '#fffbeb'],
    'Pending'     => ['fa-clock',            '#ef4444', '#fef2f2'],
    'HBC'         => ['fa-hourglass',        '#8b5cf6', '#f5f3ff'],
    'Next Year'   => ['fa-calendar',         '#06b6d4', '#ecfeff'],
    'NON Performance'=>['fa-ban',            '#6b7280', '#f3f4f6'],
    'Done'        => ['fa-check-circle',     '#10b981', '#ecfdf5'],
];

include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php include '../../includes/sidebar_staff.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<!-- Hero -->
<div class="page-hero">
    <div class="page-hero-badge"><i class="fas fa-user"></i> Staff</div>
    <h4>My Dashboard — <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?></h4>
    <p>
        <?= htmlspecialchars($_SESSION['branch_name'] ?? '') ?> · <?= date('l, d F Y') ?>
        <?php if ($transferredCount > 0): ?>
        <span style="font-size:.75rem;color:#3b82f6;margin-left:.75rem;
                     background:#eff6ff;padding:.15rem .55rem;border-radius:99px;">
            <i class="fas fa-exchange-alt me-1"></i><?= $transferredCount ?> transferred to you
        </span>
        <?php endif; ?>
    </p>
</div>

<!-- Stat cards — all dynamic statuses + overdue -->
<div class="row g-3 mb-4">
    <?php foreach ($allStatuses as $st):
        $k    = $st['status_name'];
        $cnt  = $byStatus[$k] ?? 0;
        $col  = $st['color'] ?? '#6b7280';
        $bg   = $st['bg_color'] ?? '#f3f4f6';
        $icon = $st['icon'] ?? 'fa-circle';
    ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:<?= $bg ?>;color:<?= $col ?>;">
                <i class="fas <?= $icon ?>"></i>
            </div>
            <div class="stat-card-value" style="color:<?= $col ?>;"><?= number_format($cnt) ?></div>
            <div class="stat-card-label"><?= htmlspecialchars($k) ?></div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Total -->
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card">
            <div class="stat-card-icon" style="background:#eff6ff;color:#3b82f6;">
                <i class="fas fa-list-check"></i>
            </div>
            <div class="stat-card-value" style="color:#3b82f6;"><?= number_format($total) ?></div>
            <div class="stat-card-label">Total</div>
        </div>
    </div>

    <!-- Overdue -->
    <?php if ($overdue > 0): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card" style="border-left:3px solid #dc2626;">
            <div class="stat-card-icon" style="background:#fef2f2;color:#dc2626;">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="stat-card-value" style="color:#dc2626;"><?= number_format($overdue) ?></div>
            <div class="stat-card-label">Overdue</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Recent tasks table -->
<div class="card-mis">
    <div class="card-mis-header">
        <h5><i class="fas fa-list-check text-warning me-2"></i>My Tasks</h5>
        <a href="<?= APP_URL ?>/staff/tasks/index.php" class="btn btn-sm btn-outline-secondary">
            View All (<?= $total ?>)
        </a>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>Task #</th>
                    <th>Title</th>
                    <th>Company</th>
                    <th>Department</th>
                    <th>Assigned By</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($myTasks)): ?>
            <tr>
                <td colspan="8" class="empty-state">
                    <i class="fas fa-list-check"></i>No tasks yet
                </td>
            </tr>
            <?php endif; ?>

            <?php foreach ($myTasks as $t):
                $sClass    = 'status-' . strtolower(str_replace(' ', '-', $t['status']));
                $isOverdue = $t['due_date'] && strtotime($t['due_date']) < time() && $t['status'] !== 'Done';
                $isXfer    = (bool)$t['is_transferred'];
            ?>
            <tr <?= $isOverdue ? 'style="background:#fef2f2;"' : '' ?>>
                <td>
                    <span class="task-number"><?= htmlspecialchars($t['task_number']) ?></span>
                    <?php if ($isOverdue): ?>
                    <span style="font-size:.62rem;background:#fef2f2;color:#ef4444;
                                 padding:.1rem .35rem;border-radius:3px;margin-left:.25rem;font-weight:700;">
                        OVERDUE
                    </span>
                    <?php endif; ?>
                    <?php if ($isXfer): ?>
                    <span style="font-size:.62rem;background:#eff6ff;color:#3b82f6;
                                 padding:.1rem .35rem;border-radius:3px;margin-left:.25rem;font-weight:600;">
                        <i class="fas fa-exchange-alt me-1"></i>Transferred
                    </span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.87rem;font-weight:500;max-width:160px;
                            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <?= htmlspecialchars($t['title']) ?>
                </td>
                <td style="font-size:.82rem;">
                    <?= htmlspecialchars($t['company_name'] ?? '—') ?>
                </td>
                <td>
                    <span style="font-size:.73rem;
                                 background:<?= htmlspecialchars($t['color']??'#ccc') ?>22;
                                 color:<?= htmlspecialchars($t['color']??'#666') ?>;
                                 padding:.2rem .5rem;border-radius:99px;">
                        <?= htmlspecialchars($t['dept_name'] ?? '—') ?>
                    </span>
                </td>
                <td style="font-size:.82rem;">
                    <?= htmlspecialchars($t['assigned_by_name'] ?? '—') ?>
                </td>
                <td style="font-size:.82rem;<?= $isOverdue ? 'color:#ef4444;font-weight:600;' : '' ?>">
                    <?= $t['due_date'] ? date('M j, Y', strtotime($t['due_date'])) : '—' ?>
                </td>
                <td>
                    <span class="status-badge <?= $sClass ?>">
                        <?= htmlspecialchars($t['status']) ?>
                    </span>
                </td>
                <td>
                    <a href="<?= APP_URL ?>/staff/tasks/view.php?id=<?= $t['id'] ?>"
                       class="btn btn-sm btn-outline-secondary" style="font-size:.75rem;">
                        <i class="fas fa-eye me-1"></i>View
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- padding -->
</div><!-- main-content -->
</div><!-- app-wrapper -->
<?php include '../../includes/footer.php'; ?>