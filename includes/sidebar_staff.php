<?php
// includes/sidebar_staff.php
$__u = currentUser();
if (!function_exists('isActiveStaff')) {
    function isActiveStaff(string $path): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return (strpos($uri, $path) !== false) ? 'active' : '';
    }
}
// Count tasks due today, pending, overdue
$db = getDB();
$today      = date('Y-m-d');
$pendingCnt = (function() use ($db,$__u,$today){
    $s=$db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status_id!=8 AND is_active=1"); $s->execute([$__u['id']]); return (int)$s->fetchColumn();
})();
$todayCnt   = (function() use ($db,$__u,$today){
    $s=$db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND due_date=? AND status_id!=8  AND is_active=1"); $s->execute([$__u['id'],$today]); return (int)$s->fetchColumn();
})();
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo"><span>ASK</span></div>
        <div><div class="brand-name">MISPro</div><div class="brand-sub">ASK Global Advisory</div></div>
    </div>
    <div class="sidebar-user">
        <div class="avatar-circle" style="background:linear-gradient(135deg,#10b981,#34d399);color:white;">
            <?= strtoupper(substr($__u['full_name'],0,2)) ?>
        </div>
        <div>
            <div class="sidebar-user-name"><?= htmlspecialchars(explode(' ',$__u['full_name'])[0]) ?></div>
            <div class="sidebar-user-role"><i class="fas fa-user me-1"></i>Staff</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="<?= APP_URL ?>/staff/dashboard/index.php" class="nav-item <?= isActiveStaff('/staff/dashboard') ?>">
            <i class="fas fa-th-large"></i><span>Dashboard</span>
        </a>

        <div class="nav-section-label">My Tasks</div>
        <a href="<?= APP_URL ?>/staff/tasks/today.php" class="nav-item <?= isActiveStaff('/staff/tasks/today') ?>">
            <i class="fas fa-sun"></i><span>Today</span>
            <?php if($todayCnt>0): ?><span class="nav-badge nav-badge-warning"><?= $todayCnt ?></span><?php endif; ?>
        </a>
        <a href="<?= APP_URL ?>/staff/tasks/tomorrow.php" class="nav-item <?= isActiveStaff('/staff/tasks/tomorrow') ?>">
            <i class="fas fa-forward"></i><span>Tomorrow</span>
        </a>
        <a href="<?= APP_URL ?>/staff/tasks/index.php" class="nav-item <?= isActiveStaff('/staff/tasks/index') ?><?= isActiveStaff('/staff/tasks/view') ?>">
            <i class="fas fa-list-check"></i><span>All My Tasks</span>
            <?php if($pendingCnt>0): ?><span class="nav-badge"><?= $pendingCnt ?></span><?php endif; ?>
        </a>
    </nav>
    <div class="sidebar-branch-info">
        <?php
        $bInfo = $db->prepare("SELECT b.branch_name, d.dept_name FROM users u LEFT JOIN branches b ON b.id=u.branch_id LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=?");
        $bInfo->execute([$__u['id']]);
        $bInfo = $bInfo->fetch();
        ?>
        <div style="font-size:.7rem;color:#9ca3af;margin-bottom:.2rem;">Your Assignment</div>
        <div style="font-size:.78rem;font-weight:600;color:#c9a84c;">
            <i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($bInfo['branch_name']??'—') ?>
        </div>
        <div style="font-size:.75rem;color:#9ca3af;margin-top:.15rem;">
            <i class="fas fa-layer-group me-1"></i><?= htmlspecialchars($bInfo['dept_name']??'—') ?>
        </div>
    </div>
</aside>