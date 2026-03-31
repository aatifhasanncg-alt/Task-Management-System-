<?php
// includes/sidebar_admin.php
$__u = currentUser();
$__db = getDB();

if (!function_exists('isActive')) {
    function isActive(string $path): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return (strpos($uri, $path) !== false) ? 'active' : '';
    }
}

// Fetch admin's full profile for dept check
$__adminProfile = $__db->prepare("
    SELECT u.*, d.dept_code, d.dept_name, b.branch_name, b.is_head_office
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN branches b    ON b.id = u.branch_id
    WHERE u.id = ?
");
$__adminProfile->execute([$__u['id']]);
$__adminProfile = $__adminProfile->fetch();

$__deptCode = $__adminProfile['dept_code'] ?? '';

// Task count — scoped to admin's branch & dept, no admin_branch_access needed
$__taskCount = 0;
try {
    $tSt = $__db->prepare("
        SELECT COUNT(*) FROM tasks t
        JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active = 1
        AND t.branch_id = ?
        AND t.department_id = ?
        AND ts.status_name != 'Done'
    ");
    $tSt->execute([$__adminProfile['branch_id'], $__adminProfile['department_id']]);
    $__taskCount = (int) $tSt->fetchColumn();
} catch (Exception $e) {
    $__taskCount = 0;
}

// Unread notifications count
$__unread = 0;
try {
    $nSt = $__db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $nSt->execute([$__u['id']]);
    $__unread = (int) $nSt->fetchColumn();
} catch (Exception $e) {
}
?>
<style>
    .sidebar {
        display: flex !important;
        flex-direction: column !important;
        height: 100vh !important;
        overflow: hidden !important;
    }

    .sidebar-nav {
        flex: 1 !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        scrollbar-width: thin !important;
        scrollbar-color: #2a3550 transparent !important;
        padding-bottom: 1rem !important;
    }

    .sidebar-nav::-webkit-scrollbar {
        width: 3px;
    }

    .sidebar-nav::-webkit-scrollbar-thumb {
        background: #2a3550;
        border-radius: 99px;
    }

    .sidebar-brand,
    .sidebar-user,
    .sidebar-branch-info {
        flex-shrink: 0 !important;
    }

    .sidebar-branch-info {
        margin-top: auto !important;
        border-top: 1px solid #1e2a45 !important;
    }
</style>

<aside class="sidebar" id="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="brand-logo"><span>ASK</span></div>
        <div>
            <div class="brand-name">MISPro</div>
            <div class="brand-sub">ASK Global Advisory</div>
        </div>
    </div>

    <!-- User card -->
    <div class="sidebar-user">
        <div class="avatar-circle">
            <?= strtoupper(substr($__u['full_name'] ?? 'AU', 0, 2)) ?>
        </div>
        <div>
            <div class="sidebar-user-name">
                <?= htmlspecialchars(explode(' ', $__u['full_name'] ?? 'Admin')[0]) ?>
            </div>
            <div class="sidebar-user-role">
                <i class="fas fa-user-shield me-1"></i>Admin
                <?php if ($__deptCode): ?>
                    · <span style="color:#c9a84c;"><?= htmlspecialchars($__adminProfile['dept_name'] ?? '') ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">

        <!-- Main -->
        <div class="nav-section-label">Main</div>

        <a href="<?= APP_URL ?>/admin/dashboard/index.php" class="nav-item <?= isActive('/admin/dashboard') ?>">
            <i class="fas fa-th-large"></i><span>Dashboard</span>
        </a>
        <a href="<?= APP_URL ?>/admin/profile/index.php" class="nav-item <?= isActive('/admin/profile') ?>">
            <i class="fas fa-user"></i><span>My Profile</span>
        </a>

        <!-- Tasks -->
        <div class="nav-section-label">Tasks</div>

        <a href="<?= APP_URL ?>/admin/tasks/index.php" class="nav-item <?= isActive('/admin/tasks/index') ?>">
            <i class="fas fa-list-check"></i>
            <span>All Tasks</span>
            <?php if ($__taskCount > 0): ?>
                <span class="nav-badge" style="margin-left:auto;">
                    <?= $__taskCount > 99 ? '99+' : $__taskCount ?>
                </span>
            <?php endif; ?>
        </a>

        <a href="<?= APP_URL ?>/admin/tasks/assign.php" class="nav-item <?= isActive('/admin/tasks/assign') ?>">
            <i class="fas fa-plus-circle"></i><span>Assign Task</span>
        </a>

        <!-- Management -->
        <div class="nav-section-label">Management</div>

        <a href="<?= APP_URL ?>/admin/companies/index.php" class="nav-item <?= isActive('/admin/companies') ?>">
            <i class="fas fa-building"></i><span>Companies</span>
        </a>

        <a href="<?= APP_URL ?>/admin/staff/index.php" class="nav-item <?= isActive('/admin/staff') ?>">
            <i class="fas fa-users"></i><span>Staff</span>
        </a>

        <!-- Analytics -->
        <div class="nav-section-label">Analytics</div>

        <a href="<?= APP_URL ?>/admin/reports/index.php" class="nav-item <?= isActive('/admin/reports') ?>">
            <i class="fas fa-chart-bar"></i><span>Reports</span>
        </a>

        <!-- Bank Summary — ONLY shown if admin belongs to BANK department -->
        <?php if ($__deptCode === 'BANK'): ?>
            <a href="<?= APP_URL ?>/admin/banking/summary.php" class="nav-item <?= isActive('/banking/summary') ?>"
                style="<?= isActive('/banking/summary')  ?>">
                <i class="fas fa-landmark"></i>
                <span>Bank Summary</span>
            </a>
        <?php endif; ?>


    </nav>

    <!-- Branch info footer -->
    <div class="sidebar-branch-info" style="padding:1rem 1.25rem;">
        <div style="font-size:.7rem;color:#9ca3af;margin-bottom:.3rem;">Your Branch & Department</div>
        <div style="font-size:.82rem;font-weight:600;color:#c9a84c;">
            <i class="fas fa-map-marker-alt me-1"></i>
            <?= htmlspecialchars($__adminProfile['branch_name'] ?? $_SESSION['branch_name'] ?? 'Unassigned') ?>
        </div>
        <?php if (!empty($__adminProfile['dept_name'])): ?>
            <div style="font-size:.75rem;color:#8899aa;margin-top:.2rem;">
                <i class="fas fa-layer-group me-1"></i>
                <?= htmlspecialchars($__adminProfile['dept_name']) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($__adminProfile['is_head_office'])): ?>
            <div style="font-size:.7rem;color:#10b981;margin-top:.2rem;">
                <i class="fas fa-star me-1"></i>Head Office
            </div>
        <?php endif; ?>
    </div>

</aside>