<?php
// includes/sidebar_manager.php
$__u = currentUser();
$__userId = $__u['id'] ?? null;
$__db = getDB();

if (!function_exists('isActiveMgr')) {
    function isActiveMgr(string $path): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return (strpos($uri, $path) !== false) ? 'active' : '';
    }
}

// Fetch manager's full profile
$__mgrProfile = $__db->prepare("
    SELECT u.*, d.dept_code, d.dept_name, b.branch_name, b.is_head_office
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN branches b    ON b.id = u.branch_id
    WHERE u.id = ?
");
$__mgrProfile->execute([$__u['id']]);
$__mgrProfile = $__mgrProfile->fetch();

$__deptCode = $__mgrProfile['dept_code'] ?? '';
$__branchId = $__mgrProfile['branch_id'] ?? null;
$__deptId = $__mgrProfile['department_id'] ?? null;

// ── Key flags ────────────────────────────────────────────────
$__isBranchManager = ($__deptCode === 'CORE');
$__isConsultingDept = ($__deptCode === 'CON'
    || stripos($__mgrProfile['dept_name'] ?? '', 'consult') !== false);

$__udaStmt = $__db->prepare("
    SELECT d.dept_code FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ?
");
$__udaStmt->execute([$__userId]);
$__udaDeptCodes = array_column($__udaStmt->fetchAll(PDO::FETCH_ASSOC), 'dept_code');
$__hasUdaConsulting = in_array('CON', $__udaDeptCodes);
$__hasUdaBanking = in_array('BANK', $__udaDeptCodes);
$__hasBankingAccess = ($__deptCode === 'BANK') || $__hasUdaBanking;
$__hasITAccess = ($__deptCode === 'IT') || in_array('IT', $__udaDeptCodes);
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
                  AND ts.counts_as_done = 0
            ");
            $tSt->execute([$__branchId]);
        } else {
            $tSt = $__db->prepare("
                SELECT COUNT(*) FROM tasks t
                JOIN task_status ts ON ts.id = t.status_id
                WHERE t.is_active = 1
                  AND t.branch_id = ?
                  AND t.department_id = ?
                  AND ts.counts_as_done = 0
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
function conNavActiveMgr(string $file, string $dir = ''): string
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
            <div class="brand-name">TaskHub</div>
            <div class="brand-sub">ASK Global Advisory</div>
        </div>
    </div>

    <!-- User card -->
    <div class="sidebar-user">
        <div class="avatar-circle">
            <?= strtoupper(substr($__u['full_name'] ?? 'MG', 0, 2)) ?>
        </div>
        <div>
            <div class="sidebar-user-name">
                <?= htmlspecialchars(explode(' ', $__u['full_name'] ?? 'Manager')[0]) ?>
            </div>
            <div class="sidebar-user-role">
                <?php if ($__isConsultingDept): ?>
                    <i class="fas fa-briefcase me-1"></i>Manager
                    <span style="color:#c9a84c;"> ·
                        <?= htmlspecialchars($__mgrProfile['dept_name'] ?? 'Consulting') ?></span>
                <?php elseif ($__isBranchManager): ?>
                    <i class="fas fa-code-branch me-1"></i>Branch Manager
                    <span style="color:#c9a84c;"> · <?= htmlspecialchars($__mgrProfile['branch_name'] ?? '') ?></span>
                <?php else: ?>
                    <i class="fas fa-sitemap me-1"></i>Manager
                    <?php if ($__deptCode): ?>
                        · <span style="color:#c9a84c;"><?= htmlspecialchars($__mgrProfile['dept_name'] ?? '') ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">

        <?php if ($__isConsultingDept): ?>
            <!-- ═══════════════════════════════════════════════════
             CONSULTING DEPARTMENT MANAGER SIDEBAR
             No tasks — only planning, logs, performance
        ════════════════════════════════════════════════════ -->
            <div class="nav-section-label">
                <i class="fas fa-briefcase me-1"></i> Consulting
            </div>

            <a href="<?= APP_URL ?>manager/consulting/dashboard.php"
                class="nav-item<?= conNavActiveMgr('dashboard.php', 'consulting') ?>">
                <i class="fas fa-chart-pie"></i>
                <span>Dashboard</span>
            </a>

            <a href="<?= APP_URL ?>manager/consulting/plans.php"
                class="nav-item<?= conNavActiveMgr('plans.php', 'consulting') ?>">
                <i class="fas fa-calendar-alt"></i><span>All Plans</span>
            </a>

            <a href="<?= APP_URL ?>manager/consulting/today_tomorrow.php"
                class="nav-item<?= conNavActiveMgr('today_tomorrow.php', 'consulting') ?>">
                <i class="fas fa-calendar-day"></i>
                <span>This Week Plans</span>
                <?php if ($__planNotifCount > 0): ?>
                    <span class="nav-badge" style="margin-left:auto;background:#f59e0b;color:#000;">
                        <?= $__planNotifCount ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="<?= APP_URL ?>manager/consulting/plan_approvals.php"
                class="nav-item<?= conNavActiveMgr('plan_approvals.php', 'consulting') ?>">
                <i class="fas fa-check-circle"></i><span>Plan Approvals</span>
            </a>

            <a href="<?= APP_URL ?>manager/consulting/log_list.php"
                class="nav-item<?= isActiveMgr('/manager/consulting/log_list') ?>">
                <i class="fas fa-clock"></i><span>Work Logs</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/office_log_list.php"
                class="nav-item <?= conNavActiveMgr('office_log_list.php', 'consulting') ?><?= conNavActiveMgr('office_log_view.php', 'consulting') ?><?= conNavActiveMgr('office_log_edit.php', 'consulting') ?>">
                <i class="fas fa-clipboard-list"></i>
                <span>Office Logs</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/staff_report.php"
                class="nav-item<?= conNavActiveMgr('staff_report.php', 'consulting') ?>">
                <i class="fas fa-users"></i><span>Staff Performance</span>
            </a>

            <a href="<?= APP_URL ?>manager/consulting/client_report.php"
                class="nav-item<?= conNavActiveMgr('client_report.php', 'consulting') ?>">
                <i class="fas fa-building"></i><span>Client Report</span>
            </a>

            
            <a href="<?= APP_URL ?>manager/consulting/plan_approval.php"
                class="nav-item<?= conNavActiveMgr('plan_approval.php', 'consulting') ?>">
                <i class="fa-regular fa-check-circle"></i><span>Plan Approvals</span>
            </a>
            <!-- ── My Workspace: personal plan/log actions ── -->
            <div class="nav-section-label">
                <i class="fas fa-user-clock me-1"></i> My Workspace
            </div>
            <a href="<?= APP_URL ?>manager/consulting/my_plans.php"
                class="nav-item<?= conNavActiveMgr('my_plans.php', 'consulting') ?>">
                <i class="fa-regular fa-calendar"></i><span>My Plans</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/this_week.php"
                class="nav-item<?= conNavActiveMgr('this_week.php', 'consulting') ?>">
                <i class="fa-regular fa-calendar-week"></i><span>This Week Plans</span>
                <?php if ($__planNotifCount > 0): ?>
                    <span class="nav-badge" style="margin-left:auto;background:#f59e0b;color:#000;">
                        <?= $__planNotifCount ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/my_logs.php"
                class="nav-item<?= conNavActiveMgr('my_logs.php', 'consulting') ?>">
                <i class="fa-regular fa-clock"></i><span>My Logs</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/create_plan.php"
                class="nav-item<?= conNavActiveMgr('create_plan.php', 'consulting') ?>">
                <i class="fas fa-plus-circle"></i><span>Create Plan</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/log_create.php"
                class="nav-item<?= conNavActiveMgr('log_create.php', 'consulting') ?>">
                <i class="fas fa-pen"></i><span>Create Log</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/office_log_create.php"
                class="nav-item<?= conNavActiveMgr('office_log_create.php', 'consulting') ?>">
                <i class="fas fa-plus"></i>
                <span>Create Office Log</span>
            </a>

            <div class="nav-section-label">Management</div>
            <a href="<?= APP_URL ?>manager/companies/index.php" class="nav-item <?= isActiveMgr('/manager/companies') ?>">
                <i class="fas fa-building"></i><span>Companies</span>
            </a>
            <a href="<?= APP_URL ?>manager/staff/index.php" class="nav-item <?= isActiveMgr('/manager/staff') ?>">
                <i class="fas fa-users"></i><span>Staff</span>
            </a>


        <?php elseif ($__isBranchManager): ?>
            <!-- ═══════════════════════════════════════════════════
             BRANCH MANAGER SIDEBAR (CORE dept manager)
             — same shape as admin's branch manager view
        ════════════════════════════════════════════════════ -->

            <div class="nav-section-label">Main</div>

            <a href="<?= APP_URL ?>manager/dashboard/index.php" class="nav-item <?= isActiveMgr('/manager/dashboard') ?>">
                <i class="fas fa-th-large"></i><span>Dashboard</span>
            </a>
            <a href="<?= APP_URL ?>manager/profile/index.php" class="nav-item <?= isActiveMgr('/manager/profile') ?>">
                <i class="fas fa-user"></i><span>My Profile</span>
            </a>

            <div class="nav-section-label">Tasks</div>

            <a href="<?= APP_URL ?>manager/tasks/index.php"
                class="nav-item <?= isActiveMgr('/manager/tasks/index') ?><?= isActiveMgr('/manager/tasks/view') ?><?= isActiveMgr('/manager/tasks/edit') ?>">
                <i class="fas fa-list-check"></i>
                <span>All Tasks</span>
                <?php if ($__taskCount > 0): ?>
                    <span class="nav-badge" style="margin-left:auto;">
                        <?= $__taskCount > 99 ? '99+' : $__taskCount ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="<?= APP_URL ?>manager/tasks/assign.php" class="nav-item <?= isActiveMgr('/manager/tasks/assign') ?>">
                <i class="fas fa-plus-circle"></i><span>Assign Task</span>
            </a>

            <div class="nav-section-label">Management</div>

            <a href="<?= APP_URL ?>manager/companies/index.php" class="nav-item <?= isActiveMgr('/manager/companies') ?>">
                <i class="fas fa-building"></i><span>Companies</span>
            </a>
            <a href="<?= APP_URL ?>manager/staff/index.php" class="nav-item <?= isActiveMgr('/manager/staff') ?>">
                <i class="fas fa-users"></i><span>Staff</span>
            </a>

            <div class="nav-section-label">Reports</div>

            <a href="<?= APP_URL ?>manager/reports/department_wise.php"
                class="nav-item <?= isActiveMgr('/manager/reports/department_wise') ?>">
                <i class="fas fa-layer-group"></i><span>Department Wise</span>
            </a>
            <a href="<?= APP_URL ?>manager/reports/staff_wise.php"
                class="nav-item <?= isActiveMgr('/manager/reports/staff_wise') ?>">
                <i class="fas fa-user-check"></i><span>Staff Wise</span>
            </a>
            <a href="<?= APP_URL ?>manager/reports/company_workflow.php"
                class="nav-item <?= isActiveMgr('/manager/reports/company_workflow') ?>">
                <i class="fas fa-diagram-project"></i><span>Company Workflow</span>
            </a>
            <a href="<?= APP_URL ?>manager/reports/bank_summary.php"
                class="nav-item <?= isActiveMgr('/manager/reports/bank_summary') ?>">
                <i class="fas fa-landmark"></i><span>Bank Summary</span>
            </a>

            <!-- ═══════════════════════════════════════════
             CONSULTING SECTION — shown when BM or UDA has CONS
            ════════════════════════════════════════════════ -->
            <div class="nav-section-label">
                <i class="fas fa-briefcase me-1"></i> Consulting
            </div>
            <a href="<?= APP_URL ?>manager/consulting/branch/dashboard.php"
                class="nav-item<?= conNavActiveMgr('dashboard.php', 'branch') ?>">
                <i class="fas fa-chart-pie"></i>
                <span>Dashboard</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/branch/plans.php"
                class="nav-item <?= conNavActiveMgr('plans.php', 'branch') ?>">
                <i class="fas fa-calendar-alt"></i><span>All Plans</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/branch/create_plan.php"
                class="nav-item<?= conNavActiveMgr('create_plan.php', 'branch') ?>">
                <i class="fas fa-plus-circle"></i><span>Create Plan</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/branch/this_week.php"
                class="nav-item<?= conNavActiveMgr('this_week.php', 'branch') ?>">
                <i class="fas fa-calendar-week"></i><span>This Week Plans</span>
                <?php if ($__planNotifCount > 0): ?>
                    <span class="nav-badge" style="margin-left:auto;background:#f59e0b;color:#000;">
                        <?= $__planNotifCount ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/branch/log_list.php"
                class="nav-item <?= conNavActiveMgr('log_list.php', 'branch') ?>">
                <i class="fas fa-clock"></i><span>Work Logs</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/branch/log_create.php"
                class="nav-item<?= conNavActiveMgr('log_create.php', 'branch') ?>">
                <i class="fas fa-pen"></i><span>Create Log</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/branch/staff_report.php"
                class="nav-item<?= conNavActiveMgr('staff_report.php', 'branch') ?>">
                <i class="fas fa-users"></i><span>Staff Performance</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/branch/client_report.php"
                class="nav-item<?= conNavActiveMgr('client_report.php', 'branch') ?>">
                <i class="fas fa-building"></i><span>Client Report</span>
            </a>
            <a href="<?= APP_URL ?>it/issue_list.php" class="nav-item<?= conNavActiveMgr('issue_list.php', 'it') ?>">
                <i class="fa-regular fa-bug"></i><span>Technical Issues</span>
            </a>
            <!-- Office Work Section -->
            <div class="nav-section-label">
                <i class="fas fa-building me-1"></i> Office Work
            </div>

            <a href="<?= APP_URL ?>manager/consulting/branch/office_log_list.php"
                class="nav-item <?= conNavActiveMgr('office_log_list.php', 'branch') ?>">
                <i class="fas fa-clipboard-list"></i>
                <span>Office Logs</span>
            </a>
            <a href="<?= APP_URL ?>manager/consulting/branch/office_log_create.php"
                class="nav-item <?= conNavActiveMgr('office_log_create.php', 'branch') ?>">
                <i class="fas fa-plus"></i>
                <span>Create Office Log</span>
            </a>

        <?php else: ?>
            <!-- ═══════════════════════════════════════════════════
             NORMAL DEPT MANAGER SIDEBAR (not IT, not Consulting)
        ════════════════════════════════════════════════════ -->

            <div class="nav-section-label">Main</div>

            <a href="<?= APP_URL ?>manager/dashboard/index.php" class="nav-item <?= isActiveMgr('/manager/dashboard') ?>">
                <i class="fas fa-th-large"></i><span>Dashboard</span>
            </a>
            <a href="<?= APP_URL ?>manager/profile/index.php" class="nav-item <?= isActiveMgr('/manager/profile') ?>">
                <i class="fas fa-user"></i><span>My Profile</span>
            </a>
            <?php if ($__hasITAccess): ?>
                <a href="<?= APP_URL ?>it/issue_list.php" class="nav-item<?= conNavActiveMgr('issue_list.php', 'it') ?>">
                    <i class="fa-regular fa-bug"></i><span>Technical Issues</span>
                </a>
            <?php endif; ?>
            <div class="nav-section-label">Tasks</div>

            <a href="<?= APP_URL ?>manager/tasks/index.php"
                class="nav-item <?= isActiveMgr('/manager/tasks/index') ?><?= isActiveMgr('/manager/tasks/view') ?><?= isActiveMgr('/manager/tasks/edit') ?>">
                <i class="fas fa-list-check"></i>
                <span>All Tasks</span>
                <?php if ($__taskCount > 0): ?>
                    <span class="nav-badge" style="margin-left:auto;">
                        <?= $__taskCount > 99 ? '99+' : $__taskCount ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="<?= APP_URL ?>manager/tasks/assign.php" class="nav-item <?= isActiveMgr('/manager/tasks/assign') ?>">
                <i class="fas fa-plus-circle"></i><span>Assign Task</span>
            </a>
            <div class="nav-section-label">Management</div>
            <a href="<?= APP_URL ?>manager/companies/index.php" class="nav-item <?= isActiveMgr('/manager/companies') ?>">
                <i class="fas fa-building"></i><span>Companies</span>
            </a>
            <a href="<?= APP_URL ?>manager/staff/index.php" class="nav-item <?= isActiveMgr('/manager/staff') ?>">
                <i class="fas fa-users"></i><span>Staff</span>
            </a>
            <div class="nav-section-label">Analytics</div>

            <a href="<?= APP_URL ?>manager/reports/staff_wise.php"
                class="nav-item <?= isActiveMgr('/manager/reports/staff_wise') ?>">
                <i class="fas fa-user-friends"></i><span>Staff Wise</span>
            </a>
            <a href="<?= APP_URL ?>manager/reports/branch_wise.php"
                class="nav-item <?= isActiveMgr('/manager/reports/branch_wise') ?>">
                <i class="fas fa-building"></i><span>Branch Wise</span>
            </a>
            <?php if ($__hasBankingAccess): ?>
                <a href="<?= APP_URL ?>manager/banking/bank_summary.php"
                    class="nav-item <?= isActiveMgr('/manager/banking/bank_summary') ?>">
                    <i class="fas fa-landmark"></i><span>Bank Summary</span>
                </a>
            <?php endif; ?>


            <?php if ($__hasUdaConsulting): ?>
                <div class="nav-section-label">
                    <i class="fas fa-briefcase me-1"></i> Consulting
                </div>
                <a href="<?= APP_URL ?>manager/consulting/dashboard.php"
                    class="nav-item<?= conNavActiveMgr('dashboard.php', 'consulting') ?>">
                    <i class="fas fa-chart-pie"></i>
                    <span>Dashboard</span>
                </a>
                <a href="<?= APP_URL ?>manager/consulting/plans.php"
                    class="nav-item <?= conNavActiveMgr('plans.php', 'consulting') ?>">
                    <i class="fas fa-calendar-alt"></i><span>All Plans</span>
                </a>
                <a href="<?= APP_URL ?>manager/consulting/today_tomorrow.php"
                    class="nav-item <?= conNavActiveMgr('today_tomorrow.php', 'consulting') ?>">
                    <i class="fas fa-calendar-day"></i>
                    <span>This Week Plans</span>
                    <?php if ($__planNotifCount > 0): ?>
                        <span class="nav-badge" style="margin-left:auto;background:#f59e0b;color:#000;">
                            <?= $__planNotifCount ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="<?= APP_URL ?>manager/consulting/plan_approval.php"
                    class="nav-item<?= conNavActiveMgr('plan_approval.php', 'consulting') ?>">
                    <i class="fa-regular fa-check-circle"></i><span>Plan Approvals</span>
                </a>
                <a href="<?= APP_URL ?>manager/consulting/create_plan.php"
                    class="nav-item <?= conNavActiveMgr('create_plan.php', 'consulting') ?>">
                    <i class="fas fa-plus-circle"></i><span>Create Plan</span>
                </a>
                <a href="<?= APP_URL ?>manager/consulting/my_plans.php"
                    class="nav-item<?= conNavActiveMgr('my_plans.php', 'consulting') ?>">
                    <i class="fa-regular fa-calendar"></i><span>My Plans</span>
                </a>
                <a href="<?= APP_URL ?>manager/consulting/log_list.php"
                    class="nav-item <?= conNavActiveMgr('log_list.php', 'consulting') ?>">
                    <i class="fas fa-clock"></i><span>Work Logs</span>
                </a>
                <a href="<?= APP_URL ?>manager/consulting/log_create.php"
                    class="nav-item <?= conNavActiveMgr('log_create.php', 'consulting') ?>">
                    <i class="fas fa-pen"></i><span>Create Log</span>
                </a>
                <a href="<?= APP_URL ?>manager/consulting/staff_report.php"
                    class="nav-item <?= conNavActiveMgr('staff_report.php', 'consulting') ?>">
                    <i class="fas fa-users"></i><span>Staff Performance</span>
                </a>
                <a href="<?= APP_URL ?>manager/consulting/client_report.php"
                    class="nav-item <?= conNavActiveMgr('client_report.php', 'consulting') ?>">
                    <i class="fas fa-building"></i><span>Client Report</span>
                </a>

                <!-- Office Work Section -->
                <div class="nav-section-label">
                    <i class="fas fa-building me-1"></i> Office Work
                </div>

                <a href="<?= APP_URL ?>manager/consulting/office_log_list.php"
                    class="nav-item <?= conNavActiveMgr('office_log_list.php', 'consulting') ?>">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Office Logs</span>
                </a>
                <a href="<?= APP_URL ?>manager/consulting/office_log_create.php"
                    class="nav-item <?= conNavActiveMgr('office_log_create.php', 'consulting') ?>">
                    <i class="fas fa-plus"></i>
                    <span>Create Office Log</span>
                </a>
            <?php endif; ?>

        <?php endif; // end dept type switch ?>
        <!-- ── User-id=2 Tax section (always shown regardless of dept) ── -->
        <?php if ($__userId == 2): ?>
            <div class="nav-section-label">Tax</div>
            <a href="<?= APP_URL ?>manager/reports/tax_staff.php"
                class="nav-item <?= isActive('/manager/reports/tax_staff') ?>">
                <i class="fas fa-user-tie"></i><span>Tax Staff</span>
            </a>
            <a href="<?= APP_URL ?>manager/reports/tax_task.php" class="nav-item <?= isActive('/manager/reports/tax_task') ?>">
                <i class="fas fa-file-invoice-dollar"></i><span>Tax Task</span>
            </a>
        <?php endif; ?>

    </nav>

    <!-- Branch / dept footer -->
    <div class="sidebar-branch-info" style="padding:1rem 1.25rem;">
        <div style="font-size:.7rem;color:#9ca3af;margin-bottom:.3rem;">
            <?php if ($__isConsultingDept): ?>
                Your Department
            <?php elseif ($__isBranchManager): ?>
                Managing Branch
            <?php else: ?>
                Your Department
            <?php endif; ?>
        </div>
        <div style="font-size:.82rem;font-weight:600;color:#c9a84c;">
            <i class="fas fa-map-marker-alt me-1"></i>
            <?= htmlspecialchars($__mgrProfile['branch_name'] ?? 'Unassigned') ?>
        </div>
        <?php if ($__isConsultingDept): ?>
            <div style="font-size:.75rem;color:#8899aa;margin-top:.2rem;">
                <i class="fas fa-briefcase me-1"></i>
                <?= htmlspecialchars($__mgrProfile['dept_name'] ?? 'Consulting') ?>
            </div>
        <?php elseif ($__isBranchManager): ?>
            <div style="font-size:.75rem;color:#10b981;margin-top:.2rem;">
                <i class="fas fa-code-branch me-1"></i> Branch Manager Access
            </div>
        <?php elseif (!empty($__mgrProfile['dept_name'])): ?>
            <div style="font-size:.75rem;color:#8899aa;margin-top:.2rem;">
                <i class="fas fa-layer-group me-1"></i>
                <?= htmlspecialchars($__mgrProfile['dept_name']) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($__mgrProfile['is_head_office'])): ?>
            <div style="font-size:.7rem;color:#10b981;margin-top:.2rem;">
                <i class="fas fa-star me-1"></i>Head Office
            </div>
        <?php endif; ?>
    </div>

</aside>