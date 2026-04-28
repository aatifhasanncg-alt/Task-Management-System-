<?php
// includes/sidebar_admin.php
$__u = currentUser();
$__userId = $__u['id'] ?? null;
$__db = getDB();

if (!function_exists('isActive')) {
    function isActive(string $path): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return (strpos($uri, $path) !== false) ? 'active' : '';
    }
}

// Fetch admin's full profile
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
$__branchId = $__adminProfile['branch_id'] ?? null;
$__deptId = $__adminProfile['department_id'] ?? null;

// ── Key flags ────────────────────────────────────────────────
$__isBranchManager = ($__deptCode === 'CORE');
$__isConsultingDept = ($__deptCode === 'CON'
    || stripos($__adminProfile['dept_name'] ?? '', 'consult') !== false);

$__udaStmt = $__db->prepare("
    SELECT d.dept_code FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ?
");
$__udaStmt->execute([$__userId]);
$__udaDeptCodes = array_column($__udaStmt->fetchAll(PDO::FETCH_ASSOC), 'dept_code');
$__hasUdaConsulting = in_array('CON', $__udaDeptCodes);

// ── Task count (only if NOT consulting) ───────────────────────
$__taskCount = 0;
if (!$__isConsultingDept) {
    try {
        if ($__isBranchManager) {
            $tSt = $__db->prepare("
                SELECT COUNT(*) FROM tasks t
                JOIN task_status ts ON ts.id = t.status_id
                WHERE t.is_active = 1
                  AND t.branch_id = ?
                  AND ts.status_name != 'Done'
            ");
            $tSt->execute([$__branchId]);
        } else {
            $tSt = $__db->prepare("
                SELECT COUNT(*) FROM tasks t
                JOIN task_status ts ON ts.id = t.status_id
                WHERE t.is_active = 1
                  AND t.branch_id = ?
                  AND t.department_id = ?
                  AND ts.status_name != 'Done'
            ");
            $tSt->execute([$__branchId, $__deptId]);
        }
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

// ── Consulting: today/tomorrow plan count for badge ───────────
$__planNotifCount = 0;
if ($__isConsultingDept || $__isBranchManager || $__hasUdaConsulting) {
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
function conNavActive(string $file, string $dir = ''): string
{
    global $__currentFile, $__currentDir;
    $fileMatch = ($__currentFile === $file);
    $dirMatch = $dir ? ($__currentDir === $dir) : true;
    return ($fileMatch && $dirMatch) ? ' active' : '';
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
                <?php if ($__isConsultingDept): ?>
                    <i class="fas fa-briefcase me-1"></i>Admin
                    <span style="color:#c9a84c;"> ·
                        <?= htmlspecialchars($__adminProfile['dept_name'] ?? 'Consulting') ?></span>
                <?php elseif ($__isBranchManager): ?>
                    <i class="fas fa-code-branch me-1"></i>Branch Manager
                    <span style="color:#c9a84c;"> · <?= htmlspecialchars($__adminProfile['branch_name'] ?? '') ?></span>
                <?php else: ?>
                    <i class="fas fa-user-shield me-1"></i>Admin
                    <?php if ($__deptCode): ?>
                        · <span style="color:#c9a84c;"><?= htmlspecialchars($__adminProfile['dept_name'] ?? '') ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">

        <?php if ($__isConsultingDept): ?>
            <!-- ═══════════════════════════════════════════════════
             CONSULTING DEPARTMENT ADMIN SIDEBAR
             No tasks — only planning, logs, performance
        ════════════════════════════════════════════════════ -->
            <div class="nav-section-label">
                <i class="fas fa-briefcase me-1"></i> Consulting
            </div>

            <a href="<?= APP_URL ?>admin/planning/index.php" class="nav-item<?= conNavActive('index.php', 'planning') ?>">
                <i class="fas fa-chart-pie"></i>
                <span>Dashboard</span>
            </a>

            <a href="<?= APP_URL ?>admin/planning/plan_list.php"
                class="nav-item<?= conNavActive('plan_list.php', 'planning') ?>">
                <i class="fas fa-calendar-alt"></i><span>Work Plans</span>
            </a>
            <a href="<?= APP_URL ?>admin/planning/today_tomorrow.php"
                class="nav-item<?= conNavActive('today_tomorrow.php', 'planning') ?>">
                <i class="fas fa-calendar-day"></i>
                <span>Today & Tomorrow</span><?php if ($__planNotifCount > 0): ?>
                        <span class="nav-badge" style="margin-left:auto;background:#f59e0b;color:#000;">
                            <?= $__planNotifCount ?>
                        </span>
                    <?php endif; ?>
            </a>
            <a href="<?= APP_URL ?>admin/planning/plan_create.php"
                class="nav-item<?= conNavActive('plan_create.php', 'planning') ?>">
                <i class="fas fa-plus-circle"></i><span>Create Plan</span>
            </a>

            <a href="<?= APP_URL ?>admin/planning/plan_approvals.php"
                class="nav-item<?= conNavActive('plan_approvals.php', 'planning') ?>">
                <i class="fas fa-check-circle"></i><span>Plan Approvals</span>
            </a>

            <a href="<?= APP_URL ?>admin/planning/log_list.php"
                class="nav-item<?= conNavActive('log_list.php', 'planning') ?>">
                <i class="fas fa-clock"></i><span>Work Logs</span>
            </a>

            <a href="<?= APP_URL ?>admin/planning/log_create.php"
                class="nav-item<?= conNavActive('log_create.php', 'planning') ?>">
                <i class="fas fa-pen"></i><span>Create Log</span>
            </a>

            <a href="<?= APP_URL ?>admin/planning/dashboard.php"
                class="nav-item<?= conNavActive('dashboard.php', 'planning') ?>">
                <i class="fas fa-chart-bar"></i><span>Performance Report</span>
            </a>

            <a href="<?= APP_URL ?>admin/planning/staff_performance.php"
                class="nav-item<?= conNavActive('staff_performance.php', 'planning') ?>">
                <i class="fas fa-users"></i><span>Staff Performance</span>
            </a>

            <a href="<?= APP_URL ?>admin/planning/client_report.php"
                class="nav-item<?= conNavActive('client_report.php', 'planning') ?>">
                <i class="fas fa-building"></i><span>Client Report</span>
            </a>

        <?php elseif ($__isBranchManager): ?>
            <!-- ═══════════════════════════════════════════════════
             BRANCH MANAGER SIDEBAR (CORE dept admin)
        ════════════════════════════════════════════════════ -->

            <div class="nav-section-label">Main</div>

            <a href="<?= APP_URL ?>/admin/dashboard/index.php" class="nav-item <?= isActive('/admin/dashboard') ?>">
                <i class="fas fa-th-large"></i><span>Dashboard</span>
            </a>
            <a href="<?= APP_URL ?>/admin/profile/index.php" class="nav-item <?= isActive('/admin/profile') ?>">
                <i class="fas fa-user"></i><span>My Profile</span>
            </a>

            <div class="nav-section-label">Tasks</div>

            <a href="<?= APP_URL ?>/admin/tasks/index.php"
                class="nav-item <?= isActive('/admin/tasks/index') ?><?= isActive('/admin/tasks/view') ?><?= isActive('/admin/tasks/edit') ?>">
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

            <div class="nav-section-label">Management</div>

            <a href="<?= APP_URL ?>/admin/companies/index.php" class="nav-item <?= isActive('/admin/companies') ?>">
                <i class="fas fa-building"></i><span>Companies</span>
            </a>
            <a href="<?= APP_URL ?>/admin/staff/index.php" class="nav-item <?= isActive('/admin/staff') ?>">
                <i class="fas fa-users"></i><span>Staff</span>
            </a>

            <div class="nav-section-label">Reports</div>

            <a href="<?= APP_URL ?>/admin/reports/department_wise.php"
                class="nav-item <?= isActive('/admin/reports/department_wise') ?>">
                <i class="fas fa-layer-group"></i><span>Department Wise</span>
            </a>
            <a href="<?= APP_URL ?>/admin/reports/staff_wise.php"
                class="nav-item <?= isActive('/admin/reports/staff_wise') ?>">
                <i class="fas fa-user-check"></i><span>Staff Wise</span>
            </a>
            <a href="<?= APP_URL ?>/admin/reports/company_workflow.php"
                class="nav-item <?= isActive('/admin/reports/company_workflow') ?>">
                <i class="fas fa-diagram-project"></i><span>Company Workflow</span>
            </a>
            <a href="<?= APP_URL ?>/admin/reports/bank_summary.php"
                class="nav-item <?= isActive('/admin/reports/bank_summary') ?>">
                <i class="fas fa-landmark"></i><span>Bank Summary</span>
            </a>
            <!-- ═══════════════════════════════════════════
             CONSULTING SECTION — shown when BM or UDA has CONS
            ════════════════════════════════════════════════ -->
            <div class="nav-section-label">
                <i class="fas fa-briefcase me-1"></i> Consulting
            </div>
            <a href="<?= APP_URL ?>admin/planning/plan_list.php"
                class="nav-item<?= conNavActive('plan_list.php', 'planning') ?>">
                <i class="fas fa-calendar-alt"></i><span>Work Plans</span>
            </a>
            <a href="<?= APP_URL ?>admin/planning/plan_create.php"
                class="nav-item<?= conNavActive('plan_create.php', 'planning') ?>">
                <i class="fas fa-plus-circle"></i><span>Create Plan</span>
            </a>
            <a href="<?= APP_URL ?>admin/planning/plan_approvals.php"
                class="nav-item<?= conNavActive('plan_approvals.php', 'planning') ?>">
                <i class="fas fa-check-circle"></i><span>Plan Approvals</span>
            </a>
            <a href="<?= APP_URL ?>admin/planning/log_list.php"
                class="nav-item<?= conNavActive('log_list.php', 'planning') ?>">
                <i class="fas fa-clock"></i><span>Work Logs</span>
            </a>
            <a href="<?= APP_URL ?>admin/planning/log_create.php"
                class="nav-item<?= conNavActive('log_create.php', 'planning') ?>">
                <i class="fas fa-pen"></i><span>Create Log</span>
            </a>
            <a href="<?= APP_URL ?>admin/planning/staff_performance.php"
                class="nav-item<?= conNavActive('staff_performance.php', 'planning') ?>">
                <i class="fas fa-users"></i><span>Staff Performance</span>
            </a>
            <a href="<?= APP_URL ?>admin/planning/client_report.php"
                class="nav-item<?= conNavActive('client_report.php', 'planning') ?>">
                <i class="fas fa-building"></i><span>Client Report</span>
            </a>
        <?php else: ?>
            <!-- NORMAL DEPT ADMIN SIDEBAR -->

            <div class="nav-section-label">Main</div>

            <a href="<?= APP_URL ?>/admin/dashboard/index.php" class="nav-item <?= isActive('/admin/dashboard') ?>">
                <i class="fas fa-th-large"></i><span>Dashboard</span>
            </a>
            <a href="<?= APP_URL ?>/admin/profile/index.php" class="nav-item <?= isActive('/admin/profile') ?>">
                <i class="fas fa-user"></i><span>My Profile</span>
            </a>

            <div class="nav-section-label">Tasks</div>

            <a href="<?= APP_URL ?>/admin/tasks/index.php"
                class="nav-item <?= isActive('/admin/tasks/index') ?><?= isActive('/admin/tasks/view') ?><?= isActive('/admin/tasks/edit') ?>">
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

            <div class="nav-section-label">Management</div>

            <a href="<?= APP_URL ?>/admin/companies/index.php" class="nav-item <?= isActive('/admin/companies') ?>">
                <i class="fas fa-building"></i><span>Companies</span>
            </a>
            <a href="<?= APP_URL ?>/admin/staff/index.php" class="nav-item <?= isActive('/admin/staff') ?>">
                <i class="fas fa-users"></i><span>Staff</span>
            </a>

            <div class="nav-section-label">Analytics</div>

            <a href="<?= APP_URL ?>/admin/reports/index.php" class="nav-item <?= isActive('/admin/reports/index') ?>">
                <i class="fas fa-chart-bar"></i><span>Reports</span>
            </a>

            <?php if ($__deptCode === 'BANK'): ?>
                <a href="<?= APP_URL ?>/admin/banking/summary.php" class="nav-item <?= isActive('/banking/summary') ?>">
                    <i class="fas fa-landmark"></i><span>Bank Summary</span>
                </a>
            <?php endif; ?>

            <?php if ($__hasUdaConsulting): ?>
                <div class="nav-section-label">
                    <i class="fas fa-briefcase me-1"></i> Consulting
                </div>
                <a href="<?= APP_URL ?>admin/planning/plan_list.php"
                    class="nav-item<?= conNavActive('plan_list.php', 'planning') ?>">
                    <i class="fas fa-calendar-alt"></i><span>Work Plans</span>
                </a>
                <a href="<?= APP_URL ?>admin/planning/today_tomorrow.php"
                    class="nav-item<?= conNavActive('today_tomorrow.php', 'planning') ?>">
                    <i class="fas fa-calendar-day"></i>
                    <span>Today & Tomorrow</span>
                    <?php if ($__planNotifCount > 0): ?>
                        <span class="nav-badge" style="margin-left:auto;background:#f59e0b;color:#000;">
                            <?= $__planNotifCount ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="<?= APP_URL ?>admin/planning/plan_create.php"
                    class="nav-item<?= conNavActive('plan_create.php', 'planning') ?>">
                    <i class="fas fa-plus-circle"></i><span>Create Plan</span>
                </a>
                <a href="<?= APP_URL ?>admin/planning/plan_approvals.php"
                    class="nav-item<?= conNavActive('plan_approvals.php', 'planning') ?>">
                    <i class="fas fa-check-circle"></i><span>Plan Approvals</span>
                </a>
                <a href="<?= APP_URL ?>admin/planning/log_list.php"
                    class="nav-item<?= conNavActive('log_list.php', 'planning') ?>">
                    <i class="fas fa-clock"></i><span>Work Logs</span>
                </a>
                <a href="<?= APP_URL ?>admin/planning/log_create.php"
                    class="nav-item<?= conNavActive('log_create.php', 'planning') ?>">
                    <i class="fas fa-pen"></i><span>Create Log</span>
                </a>
                <a href="<?= APP_URL ?>admin/planning/staff_performance.php"
                    class="nav-item<?= conNavActive('staff_performance.php', 'planning') ?>">
                    <i class="fas fa-users"></i><span>Staff Performance</span>
                </a>
                <a href="<?= APP_URL ?>admin/planning/client_report.php"
                    class="nav-item<?= conNavActive('client_report.php', 'planning') ?>">
                    <i class="fas fa-building"></i><span>Client Report</span>
                </a>
            <?php endif; ?>

        <?php endif; // end dept type switch ?>

        <!-- ── User-id=2 Tax section (always shown regardless of dept) ── -->
        <?php if ($__userId == 2): ?>
            <div class="nav-section-label">Tax</div>
            <a href="<?= APP_URL ?>/admin/reports/tax_staff.php"
                class="nav-item <?= isActive('/admin/reports/tax_staff') ?>">
                <i class="fas fa-user-tie"></i><span>Tax Staff</span>
            </a>
            <a href="<?= APP_URL ?>/admin/reports/tax_task.php" class="nav-item <?= isActive('/admin/reports/tax_task') ?>">
                <i class="fas fa-file-invoice-dollar"></i><span>Tax Task</span>
            </a>
        <?php endif; ?>
         

    </nav>

    <!-- Branch / dept footer -->
    <div class="sidebar-branch-info" style="padding:1rem 1.25rem;">
        <div style="font-size:.7rem;color:#9ca3af;margin-bottom:.3rem;">
            <?php if ($__isConsultingDept): ?>
                Your Department &amp; Branch
            <?php elseif ($__isBranchManager): ?>
                Managing Branch
            <?php else: ?>
                Your Branch &amp; Department
            <?php endif; ?>
        </div>
        <div style="font-size:.82rem;font-weight:600;color:#c9a84c;">
            <i class="fas fa-map-marker-alt me-1"></i>
            <?= htmlspecialchars($__adminProfile['branch_name'] ?? 'Unassigned') ?>
        </div>
        <?php if ($__isConsultingDept): ?>
            <div style="font-size:.75rem;color:#8899aa;margin-top:.2rem;">
                <i class="fas fa-briefcase me-1"></i>
                <?= htmlspecialchars($__adminProfile['dept_name'] ?? 'Consulting') ?>
            </div>
     
        <?php elseif ($__isBranchManager): ?>
            <div style="font-size:.75rem;color:#10b981;margin-top:.2rem;">
                <i class="fas fa-code-branch me-1"></i> Branch Manager Access
            </div>
        <?php elseif (!empty($__adminProfile['dept_name'])): ?>
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