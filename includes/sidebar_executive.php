<?php
// includes/sidebar_executive.php
$__u = currentUser();

if (!function_exists('isActiveExec')) {
    function isActiveExec(string $path): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return (strpos($uri, $path) !== false) ? 'active' : '';
    }
}

?>
<style>
    /* Sidebar scroll fix */
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

    <!-- User -->
    <div class="sidebar-user">
        <div class="avatar-circle" style="background:linear-gradient(135deg,#c9a84c,#e8c96a);color:#0a0f1e;">
            <?= strtoupper(substr($__u['full_name'] ?? 'EX', 0, 2)) ?>
        </div>
        <div>
            <div class="sidebar-user-name">
                <?= htmlspecialchars(explode(' ', $__u['full_name'] ?? 'Executive')[0]) ?>
            </div>
            <div class="sidebar-user-role">
                <i class="fas fa-crown me-1" style="color:#c9a84c;"></i>Executive
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">

        <!-- ── Overview ── -->
        <div class="nav-section-label">Overview</div>

        <a href="<?= APP_URL ?>/executive/dashboard/index.php"
            class="nav-item <?= isActiveExec('/executive/dashboard') ?>">
            <i class="fas fa-th-large"></i><span>Dashboard</span>
        </a>


        <!-- ── Tasks ── -->
        <div class="nav-section-label">Tasks</div>

        <a href="<?= APP_URL ?>/executive/tasks/index.php" class="nav-item <?= isActiveExec('/tasks/index') ?>">
            <i class="fas fa-list-check"></i><span>All Tasks</span>
        </a>

        <a href="<?= APP_URL ?>/executive/tasks/assign.php" class="nav-item <?= isActiveExec('/tasks/assign') ?>">
            <i class="fas fa-plus-circle"></i><span>Assign Task</span>
        </a>

        <!-- ── Directory ── -->
        <div class="nav-section-label">Directory</div>

        <a href="<?= APP_URL ?>/executive/companies/index.php" class="nav-item <?= isActiveExec('/companies') ?>">
            <i class="fas fa-building"></i><span>Companies</span>
        </a>

        <a href="<?= APP_URL ?>/executive/staff/index.php" class="nav-item <?= isActiveExec('/executive/staff') ?>">
            <i class="fas fa-users"></i><span>All Staff</span>
        </a>

        <!-- ── Reports ── -->
        <div class="nav-section-label">Reports</div>

        <a href="<?= APP_URL ?>/executive/reports/index.php"
            class="nav-item <?= isActiveExec('/executive/reports/index') ?>">
            <i class="fas fa-chart-pie"></i><span>Analytics Overview</span>
        </a>

        <a href="<?= APP_URL ?>/executive/reports/department_wise.php"
            class="nav-item <?= isActiveExec('/executive/reports/department_wise') ?>">
            <i class="fas fa-layer-group"></i><span>Department-wise</span>
        </a>

        <a href="<?= APP_URL ?>/executive/reports/branch_wise.php"
            class="nav-item <?= isActiveExec('/executive/reports/branch_wise') ?>">
            <i class="fas fa-map-marker-alt"></i><span>Branch-wise</span>
        </a>

        <a href="<?= APP_URL ?>/executive/reports/staff_wise.php"
            class="nav-item <?= isActiveExec('/executive/reports/staff_wise') ?>">
            <i class="fas fa-chart-bar"></i><span>Staff Performance</span>
        </a>

        <a href="<?= APP_URL ?>/executive/reports/company_wise.php"
            class="nav-item <?= isActiveExec('/executive/reports/company_wise') ?>">
            <i class="fas fa-sitemap"></i><span>Company Workflow</span>
        </a>

        <a href="<?= APP_URL ?>/executive/reports/date_wise.php"
            class="nav-item <?= isActiveExec('/executive/reports/date_wise') ?>">
            <i class="fas fa-calendar-alt"></i><span>Date Trends</span>
        </a>
        <a href="<?= APP_URL ?>/executive/reports/summary.php" class="nav-item <?= isActiveExec('/executive/reports/summary') ?>">
            <i class="fas fa-landmark"></i><span>Bank Summary</span>
        </a>
        <a href="<?= APP_URL ?>/executive/reports/auditor_report.php"
            class="nav-item <?= isActiveExec('/executive/reports/auditor_report') ?>">
            <i class="fas fa-user-tie"></i>
            <span>Auditor Summary</span>
        </a>
    </nav>

    <!-- Access Level -->
    <div class="sidebar-branch-info">
        <div style="font-size:.7rem;color:#9ca3af;margin-bottom:.3rem;">Access Level</div>
        <div style="font-size:.82rem;font-weight:600;color:#c9a84c;">
            <i class="fas fa-globe me-1"></i>All Branches &amp; Departments
        </div>
    </div>

</aside>