<?php
// staff/tasks/tomorrow.php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();
if ($_SESSION['role'] !== 'staff') {
    header('Location: ' . APP_URL . '/' . $_SESSION['role'] . '/dashboard/index.php'); exit;
}
$db   = getDB();
$user = currentUser();
$pageTitle = "Tomorrow's Tasks";
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$tasks = $db->prepare("
    SELECT t.*, d.dept_name, d.color as dept_color, d.icon as dept_icon,
           c.company_name, b.branch_name
    FROM tasks t
    LEFT JOIN departments d ON d.id=t.department_id
    LEFT JOIN companies c   ON c.id=t.company_id
    LEFT JOIN branches b    ON b.id=t.branch_id
    WHERE t.assigned_to=? AND t.is_active=1
      AND t.due_date=? AND t.status_id != 8
    ORDER BY FIELD(t.priority,'urgent','high','medium','low')
");
$tasks->execute([$user['id'], $tomorrow]);
$taskList = $tasks->fetchAll();

include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php include '../../includes/sidebar_staff.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<div class="page-hero">
    <div class="page-hero-badge"><i class="fas fa-forward"></i> Tomorrow</div>
    <h4>Tomorrow's Tasks</h4>
    <p><?= date('l, F j, Y', strtotime('+1 day')) ?> · <?= count($taskList) ?> task<?= count($taskList)!==1?'s':'' ?> due</p>
</div>

<?php if(empty($taskList)): ?>
<div class="empty-state">
    <i class="fas fa-calendar-check" style="color:#10b981;"></i>
    <h5>Nothing due tomorrow!</h5>
    <p>Your schedule is clear for tomorrow. Enjoy!</p>
    <a href="index.php" class="btn-gold btn btn-sm mt-2">View All Tasks</a>
</div>
<?php else: ?>
<div style="display:grid;gap:1rem;">
<?php foreach($taskList as $t): ?>
<div class="task-card" style="--task-color:<?= $t['dept_color'] ?>;">
    <div class="d-flex align-items-start justify-content-between gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                <span class="task-number-badge"><?= htmlspecialchars($t['task_number']) ?></span>
                <span class="dept-chip" style="background:<?= $t['dept_color'] ?>22;color:<?= $t['dept_color'] ?>;border:1px solid <?= $t['dept_color'] ?>44;">
                    <i class="fas <?= $t['dept_icon'] ?> me-1" style="font-size:.65rem;"></i><?= htmlspecialchars($t['dept_name']) ?>
                </span>
                <span class="status-badge status-<?= strtolower(str_replace(' ','-',$t['status'])) ?>"><?= $t['status'] ?></span>
            </div>
            <h5 style="font-size:.95rem;font-weight:600;margin:.25rem 0;"><?= htmlspecialchars($t['title']) ?></h5>
            <div style="font-size:.78rem;color:#9ca3af;">
                <?php if($t['company_name']): ?><span class="me-3"><i class="fas fa-building me-1"></i><?= htmlspecialchars($t['company_name']) ?></span><?php endif; ?>
                <span><i class="fas fa-flag me-1" style="color:<?= ['urgent'=>'#ef4444','high'=>'#f59e0b','medium'=>'#3b82f6','low'=>'#9ca3af'][$t['priority']]??'#9ca3af' ?>;"></i><?= ucfirst($t['priority']) ?></span>
            </div>
        </div>
        <a href="view.php?id=<?= $t['id'] ?>" class="btn-gold btn btn-sm flex-shrink-0">
            <i class="fas fa-eye me-1"></i>View
        </a>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php include '../../includes/footer.php'; ?>