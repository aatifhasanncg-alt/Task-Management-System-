<?php
// includes/sidebar_staff.php
$__u = currentUser();
$__userId = $__u['id'] ?? null;
$__db = getDB();

if (!function_exists('isActiveStaff')) {
    function isActiveStaff(string $path): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return (strpos($uri, $path) !== false) ? 'active' : '';
    }
}
$today = date('Y-m-d');
$pendingCnt = (function () use ($__db, $__u, $today) {
    $s = $__db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status_id!=8 AND is_active=1");
    $s->execute([$__u['id']]);
    return (int) $s->fetchColumn();
})();
$todayCnt = (function () use ($__db, $__u, $today) {
    $s = $__db->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND due_date=? AND status_id!=8  AND is_active=1");
    $s->execute([$__u['id'], $today]);
    return (int) $s->fetchColumn();
})();

// Fetch staff's full profile
$__staffProfile = $__db->prepare("
    SELECT u.*, d.dept_code, d.dept_name, b.branch_name, b.is_head_office
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN branches b    ON b.id = u.branch_id
    WHERE u.id = ?
");
$__staffProfile->execute([$__u['id']]);
$__staffProfile = $__staffProfile->fetch();

$__deptCode = $__staffProfile['dept_code'] ?? '';
$__branchId = $__staffProfile['branch_id'] ?? null;
$__deptId = $__staffProfile['department_id'] ?? null;

// ── Is this staff in the consulting department? ───────────────
$__isConsultingDept = ($__deptCode === 'CON'
    || stripos($__staffProfile['dept_name'] ?? '', 'consult') !== false);

$__udaStmt = $__db->prepare("
    SELECT d.dept_code FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ?
");
$__udaStmt->execute([$__userId]);
$__udaDeptCodes = array_column($__udaStmt->fetchAll(PDO::FETCH_ASSOC), 'dept_code');
$__hasUdaConsulting = in_array('CON', $__udaDeptCodes);

// ── Task count (only for non-consulting staff) ────────────────
$__taskCount = 0;
if (!$__isConsultingDept) {
    try {
        $tSt = $__db->prepare("
            SELECT COUNT(*) FROM tasks t
            JOIN task_status ts ON ts.id = t.status_id
            WHERE t.is_active = 1
              AND t.assigned_to = ?
              AND ts.status_name != 'Done'
        ");
        $tSt->execute([$__u['id']]);
        $__taskCount = (int) $tSt->fetchColumn();
    } catch (Exception $e) {
        $__taskCount = 0;
    }
}

// ── Unread notifications ──────────────────────────────────────
$__unread = 0;
try {
    $nSt = $__db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $nSt->execute([$__u['id']]);
    $__unread = (int) $nSt->fetchColumn();
} catch (Exception $e) {
}

// ── Consulting: today/tomorrow plan badge count ───────────────
$__planNotifCount = 0;
if ($__isConsultingDept || $__hasUdaConsulting) {
    try {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $pnSt = $__db->prepare("
            SELECT COUNT(*) FROM work_plan_entries wpe
            JOIN work_plans wp ON wp.id = wpe.plan_id
            WHERE wpe.assigned_to = ?
              AND wpe.plan_date IN (?, ?)
        ");
        $pnSt->execute([$__u['id'], $today, $tomorrow]);
        $__planNotifCount = (int) $pnSt->fetchColumn();
    } catch (Exception $e) {
    }
}

// ── Active helper for consulting pages ───────────────────────
$__currentFile = basename($_SERVER['PHP_SELF']);
$__currentDir = basename(dirname($_SERVER['PHP_SELF']));
if (!function_exists('conNavActive')) {
    function conNavActive(string $file, string $dir = ''): string
    {
        global $__currentFile, $__currentDir;
        $fileMatch = ($__currentFile === $file);
        $dirMatch = $dir ? ($__currentDir === $dir) : true;
        return ($fileMatch && $dirMatch) ? ' active' : '';
    }
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
            <?= strtoupper(substr($__u['full_name'] ?? 'ST', 0, 2)) ?>
        </div>
        <div>
            <div class="sidebar-user-name">
                <?= htmlspecialchars(explode(' ', $__u['full_name'] ?? 'Staff')[0]) ?>
            </div>
            <div class="sidebar-user-role">
                <?php if ($__isConsultingDept): ?>
                    <i class="fas fa-briefcase me-1"></i>Staff
                    <span style="color:#c9a84c;"> ·
                        <?= htmlspecialchars($__staffProfile['dept_name'] ?? 'Consulting') ?></span>
                <?php else: ?>
                    <i class="fas fa-user me-1"></i>Staff
                    <?php if ($__deptCode): ?>
                        · <span style="color:#c9a84c;"><?= htmlspecialchars($__staffProfile['dept_name'] ?? '') ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">

        <?php if ($__isConsultingDept && !$__hasUdaConsulting): ?>
            <!-- Pure consulting staff — only consulting nav -->
            <div class="nav-section-label">
                <i class="fas fa-briefcase me-1"></i> Consulting
            </div>
            <a href="<?= APP_URL ?>staff/planning/index.php" class="nav-item<?= conNavActive('index.php', 'planning') ?>">
                <i class="fas fa-chart-pie"></i><span>Dashboard</span>
            </a>
            <a href="<?= APP_URL ?>staff/planning/plan_list.php"
                class="nav-item<?= conNavActive('plan_list.php', 'planning') ?> <?= conNavActive('plan_view.php', 'planning') ?><?= conNavActive('plan_edit.php', 'planning') ?>">
                <i class="fas fa-calendar-alt"></i><span>My Work Plans</span>
            </a>
            <a href="<?= APP_URL ?>staff/planning/today_tomorrow.php"
                class="nav-item<?= conNavActive('today_tomorrow.php', 'planning') ?>">
                <i class="fas fa-calendar-day"></i>
                <span>Today & Tomorrow</span>
                <?php if ($__planNotifCount > 0): ?>
                    <span class="nav-badge" style="margin-left:auto;background:#f59e0b;color:#000;">
                        <?= $__planNotifCount ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="<?= APP_URL ?>staff/planning/plan_create.php"
                class="nav-item<?= conNavActive('plan_create.php', 'planning') ?>">
                <i class="fas fa-plus-circle"></i><span>Create Plan</span>
            </a>
            <?php if ($__currentFile === 'plan_view.php'): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        document.querySelector('a[href*="plan_list.php"]').classList.add('active');
                    });
                </script>
            <?php endif; ?>
            <a href="<?= APP_URL ?>staff/planning/log_list.php"
                class="nav-item<?= conNavActive('log_list.php', 'planning') ?> <?= conNavActive('log_view.php', 'planning') ?><?= conNavActive('log_edit.php', 'planning') ?>">
                <i class="fas fa-clock"></i><span>My Work Logs</span>
            </a>
            <a href="<?= APP_URL ?>staff/planning/log_create.php"
                class="nav-item<?= conNavActive('log_create.php', 'planning') ?>">
                <i class="fas fa-edit"></i><span>Create Log</span>
            </a>
            <?php if ($__currentFile === 'log_edit.php'): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        document.querySelector('a[href*="log_list.php"]').classList.add('active');
                    });
                </script>
            <?php endif; ?>
            <a href="<?= APP_URL ?>staff/planning/office_log_list.php"
                class="nav-item<?= conNavActive('office_log_list.php', 'planning') ?><?= conNavActive('office_log_view.php', 'planning') ?><?= conNavActive('office_log_edit.php', 'planning') ?>">
                <i class="fas fa-building"></i><span>Office Logs</span>
            </a>
            <a href="<?= APP_URL ?>staff/planning/office_log_create.php"
                class="nav-item<?= conNavActive('office_log_create.php', 'planning') ?> ">
                <i class="fas fa-plus-square"></i><span>Create Office Log</span>
            </a>
            <a href="<?= APP_URL ?>staff/planning/my_performance.php"
                class="nav-item<?= conNavActive('my_performance.php', 'planning') ?>">
                <i class="fas fa-chart-bar"></i><span>My Performance</span>
            </a>

        <?php else: ?>
            <!-- Normal tasks nav — shown for:
                 1. Non-consulting staff
                 2. Non-consulting staff WITH uda consulting
                 3. Consulting staff WHO ALSO has uda (show both)
            -->
            <?php if (!$__isConsultingDept): ?>
                <div class="nav-section-label">Main</div>
                <a href="<?= APP_URL ?>/staff/dashboard/index.php" class="nav-item <?= isActiveStaff('/staff/dashboard') ?>">
                    <i class="fas fa-th-large"></i><span>Dashboard</span>
                </a>

                <div class="nav-section-label">My Tasks</div>
                <a href="<?= APP_URL ?>/staff/tasks/today.php" class="nav-item <?= isActiveStaff('/staff/tasks/today') ?>">
                    <i class="fas fa-sun"></i><span>Today</span>
                    <?php if ($todayCnt > 0): ?>
                        <span class="nav-badge nav-badge-warning"><?= $todayCnt ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= APP_URL ?>/staff/tasks/tomorrow.php"
                    class="nav-item <?= isActiveStaff('/staff/tasks/tomorrow') ?>">
                    <i class="fas fa-forward"></i><span>Tomorrow</span>
                </a>
                <a href="<?= APP_URL ?>/staff/tasks/index.php"
                    class="nav-item <?= isActiveStaff('/staff/tasks/index') ?><?= isActiveStaff('/staff/tasks/view') ?>">
                    <i class="fas fa-list-check"></i><span>All My Tasks</span>
                    <?php if ($pendingCnt > 0): ?>
                        <span class="nav-badge"><?= $pendingCnt ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <!-- Consulting section — shown when staff has UDA CONS (regardless of primary dept) -->
            <?php if ($__hasUdaConsulting): ?>
                <div class="nav-section-label">
                    <i class="fas fa-briefcase me-1"></i> Consulting
                </div>
                <a href="<?= APP_URL ?>staff/planning/index.php" class="nav-item<?= conNavActive('index.php', 'planning') ?>">
                    <i class="fas fa-chart-pie"></i><span>Dashboard</span>
                </a>
                <a href="<?= APP_URL ?>staff/planning/plan_list.php"
                    class="nav-item<?= conNavActive('plan_list.php', 'planning') ?> <?= conNavActive('plan_view.php', 'planning') ?><?= conNavActive('plan_edit.php', 'planning') ?>">
                    <i class="fas fa-calendar-alt"></i><span>My Work Plans</span>
                </a>
                <a href="<?= APP_URL ?>staff/planning/today_tomorrow.php"
                    class="nav-item<?= conNavActive('today_tomorrow.php', 'planning') ?>">
                    <i class="fas fa-calendar-day"></i>
                    <span>Today & Tomorrow</span>
                    <?php if ($__planNotifCount > 0): ?>
                        <span class="nav-badge" style="margin-left:auto;background:#f59e0b;color:#000;">
                            <?= $__planNotifCount ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="<?= APP_URL ?>staff/planning/plan_create.php"
                    class="nav-item<?= conNavActive('plan_create.php', 'planning') ?>">
                    <i class="fas fa-plus-circle"></i><span>Create Plan</span>
                </a>
                <a href="<?= APP_URL ?>staff/planning/log_list.php"
                    class="nav-item<?= conNavActive('log_list.php', 'planning') ?> <?= conNavActive('log_view.php', 'planning') ?><?= conNavActive('log_edit.php', 'planning') ?>">
                    <i class="fas fa-clock"></i><span>My Work Logs</span>
                </a>
                <a href="<?= APP_URL ?>staff/planning/log_create.php"
                    class="nav-item<?= conNavActive('log_create.php', 'planning') ?>">
                    <i class="fas fa-edit"></i><span>Create Log</span>
                </a>
                <a href="<?= APP_URL ?>staff/planning/office_log_list.php"
                    class="nav-item<?= conNavActive('office_log_list.php', 'planning') ?><?= conNavActive('office_log_view.php', 'planning') ?><?= conNavActive('office_log_edit.php', 'planning') ?>">
                    <i class="fas fa-building"></i><span>Office Logs</span>
                </a>
                <a href="<?= APP_URL ?>staff/planning/office_log_create.php"
                    class="nav-item<?= conNavActive('office_log_create.php', 'planning') ?>">
                    <i class="fas fa-plus-square"></i><span>Create Office Log</span>
                </a>
            <?php endif; ?>

        <?php endif; ?>

    </nav>
    <!-- Branch / dept footer -->
    <div class="sidebar-branch-info" style="padding:1rem 1.25rem;">
        <div style="font-size:.7rem;color:#9ca3af;margin-bottom:.3rem;">
            Your Branch &amp; Department
        </div>
        <div style="font-size:.82rem;font-weight:600;color:#c9a84c;">
            <i class="fas fa-map-marker-alt me-1"></i>
            <?= htmlspecialchars($__staffProfile['branch_name'] ?? 'Unassigned') ?>
        </div>
        <?php if (!empty($__staffProfile['dept_name'])): ?>
            <div style="font-size:.75rem;color:#8899aa;margin-top:.2rem;">
                <i class="fas <?= $__isConsultingDept ? 'fa-briefcase' : 'fa-layer-group' ?> me-1"></i>
                <?= htmlspecialchars($__staffProfile['dept_name']) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($__staffProfile['is_head_office'])): ?>
            <div style="font-size:.7rem;color:#10b981;margin-top:.2rem;">
                <i class="fas fa-star me-1"></i>Head Office
            </div>
        <?php endif; ?>
    </div>

</aside>