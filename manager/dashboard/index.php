<?php
// manager/dashboard/index.php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireManager();

$db = getDB();
$userSession = currentUser();
updateActiveAt($db, (int) $userSession['id']);

$stmt = $db->prepare("
    SELECT u.*, r.role_name, b.branch_name, d.dept_name, d.dept_code, d.color AS dept_color
    FROM users u
    LEFT JOIN roles       r ON r.id = u.role_id
    LEFT JOIN branches    b ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.id = ?
");
$stmt->execute([$userSession['id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Manager Dashboard';
$adminBranchId = (int) ($user['branch_id'] ?? 0);
$adminDeptId = (int) ($user['department_id'] ?? 0);
$adminUserId = (int) $userSession['id'];
$isBranchManager = (($user['dept_code'] ?? '') === 'CORE');

// UDA managers get branch-wide staff view like BM but scoped to their accessible depts
$dashUdaCheck = $db->prepare("SELECT COUNT(*) FROM user_department_assignments WHERE user_id = ?");
$dashUdaCheck->execute([$adminUserId]);
$dashHasUda = (int) $dashUdaCheck->fetchColumn() > 0;

// ── Fetch all statuses ────────────────────────────────────────────────────────
$allStatuses = $db->query("
    SELECT id, status_name, color, bg_color, icon
    FROM task_status
    WHERE status_name != 'Corporate Team'
    ORDER BY id
")->fetchAll();

// ── 1. Status counts ──────────────────────────────────────────────────────────
if ($isBranchManager) {
    // BM: all tasks in their branch (branch-wide scope is primary)
    $byStatusStmt = $db->prepare("
        SELECT ts.status_name, COUNT(DISTINCT t.id) AS cnt
        FROM task_status ts
        LEFT JOIN tasks t
            ON  t.status_id = ts.id
            AND t.is_active = 1
            AND t.branch_id = ?
        WHERE ts.status_name != 'Corporate Team'
        GROUP BY ts.id, ts.status_name
    ");
    $byStatusStmt->execute([$adminBranchId]);
} else {
    // Dept admin: tasks in their branch + dept (+ transfers + created/assigned)
// ── Primary dept status counts (for dept admins only) ─────────────────────
    $primaryDeptStatus = [];
    if (!$isBranchManager && $adminDeptId) {
        $primStQ = $db->prepare("
        SELECT ts.status_name, COUNT(DISTINCT t.id) AS cnt
        FROM task_status ts
        LEFT JOIN tasks t
            ON  t.status_id     = ts.id
            AND t.is_active     = 1
            AND t.branch_id     = ?
            AND t.department_id = ?
        WHERE ts.status_name != 'Corporate Team'
        GROUP BY ts.id, ts.status_name
    ");
        $primStQ->execute([$adminBranchId, $adminDeptId]);
        $primaryDeptStatus = array_column($primStQ->fetchAll(), 'cnt', 'status_name');
    }
    $byStatusStmt = $db->prepare("
    SELECT ts.status_name, COUNT(DISTINCT t.id) AS cnt
    FROM task_status ts
    LEFT JOIN tasks t
        ON  t.status_id = ts.id
        AND t.is_active = 1
        AND (
            (t.branch_id = ? AND t.department_id = ?)
            OR EXISTS (SELECT 1 FROM task_workflow tw WHERE tw.task_id=t.id AND tw.action='transferred_dept' AND tw.from_dept_id=?)
            OR EXISTS (SELECT 1 FROM task_workflow tw WHERE tw.task_id=t.id AND tw.action='transferred_dept' AND tw.to_dept_id=?)
            OR t.created_by  = ?
            OR t.assigned_to = ?
            OR EXISTS (
                SELECT 1 FROM user_department_assignments uda2
                WHERE uda2.user_id = t.assigned_to
                AND uda2.department_id = ?
            )
            OR EXISTS (
                SELECT 1 FROM user_department_assignments uda2
                WHERE uda2.user_id = t.created_by
                AND uda2.department_id = ?
            )
        )
    WHERE ts.status_name != 'Corporate Team'
    GROUP BY ts.id, ts.status_name
");
    $byStatusStmt->execute([$adminBranchId, $adminDeptId, $adminDeptId, $adminDeptId, $adminUserId, $adminUserId, $adminDeptId, $adminDeptId]);
}
$byStatus = array_column($byStatusStmt->fetchAll(), 'cnt', 'status_name');
$total = array_sum($byStatus);

// ── UDA additional departments for this admin ─────────────────────────────────
$udaAdminQ = $db->prepare("
    SELECT uda.department_id, d.dept_name, d.color, d.dept_code
    FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ? AND uda.department_id != ?
");
$udaAdminQ->execute([$adminUserId, $adminDeptId]);
$adminUdaDepts = $udaAdminQ->fetchAll(PDO::FETCH_ASSOC);

// Collect all accessible dept IDs (primary + UDA) for both BM and dept admin
$allAdminAccessDeptIds = array_unique(array_filter(array_merge(
    [$adminDeptId],
    array_column($adminUdaDepts, 'department_id')
)));

// Status counts per UDA dept
$udaDeptStatus = [];
foreach ($adminUdaDepts as $udaDept) {
    $udaDid = (int) $udaDept['department_id'];
    $udaStQ = $db->prepare("
        SELECT ts.status_name, COUNT(DISTINCT t.id) AS cnt
        FROM task_status ts
        LEFT JOIN tasks t
            ON  t.status_id   = ts.id
            AND t.is_active   = 1
            AND t.branch_id   = ?
            AND t.department_id = ?
        WHERE ts.status_name != 'Corporate Team'
        GROUP BY ts.id, ts.status_name
    ");
    $udaStQ->execute([$adminBranchId, $udaDid]);
    $udaDeptStatus[$udaDid] = [
        'dept_name' => $udaDept['dept_name'],
        'color' => $udaDept['color'],
        'counts' => array_column($udaStQ->fetchAll(), 'cnt', 'status_name'),
    ];
}

// ── 2. Staff count ────────────────────────────────────────────────────────────
if ($isBranchManager) {
    // Need dept join for the filter
    $scStmt = $db->prepare("
        SELECT COUNT(DISTINCT u.id) FROM users u
        JOIN roles r ON r.id = u.role_id
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE r.role_name = 'staff' AND u.is_active = 1
          AND u.branch_id = ?
          AND (d.dept_code IS NULL OR d.dept_code NOT IN ('CON','CORE'))
    ");
    $scStmt->execute([$adminBranchId]);
} else {
    $scStmt = $db->prepare("
        SELECT COUNT(DISTINCT u.id)
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE r.role_name = 'staff'
          AND u.is_active = 1
          AND u.branch_id = ?
          AND (
              u.department_id = ?
              OR EXISTS (
                  SELECT 1 FROM user_department_assignments uda
                  WHERE uda.user_id       = u.id
                    AND uda.department_id = ?
              )
          )
    ");
    $scStmt->execute([$adminBranchId, $adminDeptId, $adminDeptId]);

}
$staffCount = (int) $scStmt->fetchColumn();

// ── 3. Transfer activity (only relevant for dept admins) ──────────────────────
$transferIn = $transferOut = 0;
if (!$isBranchManager) {
    try {
        $tIn = $db->prepare("
            SELECT COUNT(DISTINCT tw.task_id)
            FROM task_workflow tw
            JOIN tasks t ON t.id = tw.task_id AND t.is_active=1 AND t.branch_id=?
            WHERE tw.action='transferred_dept' AND tw.to_dept_id=?
        ");
        $tIn->execute([$adminBranchId, $adminDeptId]);
        $transferIn = (int) $tIn->fetchColumn();

        $tOut = $db->prepare("
            SELECT COUNT(DISTINCT tw.task_id)
            FROM task_workflow tw
            JOIN tasks t ON t.id = tw.task_id AND t.is_active=1 AND t.branch_id=?
            WHERE tw.action='transferred_dept' AND tw.from_dept_id=?
        ");
        $tOut->execute([$adminBranchId, $adminDeptId]);
        $transferOut = (int) $tOut->fetchColumn();
    } catch (Exception $e) {
    }
}
// ── Active dept for Staff Work Distribution ───────────────────────────────────
$allUdaDeptIds = array_column($adminUdaDepts, 'department_id');

// Both BM and dept admin can filter dist by dept tab
// Both BM and dept admin can filter dist by dept tab
$distDeptFilter = (int) ($_GET['dist_dept'] ?? 0);
if (!$isBranchManager && $distDeptFilter === 0) {
    $distDeptFilter = $adminDeptId;
}

// For BM: also load their UDA depts so tabs show departments they are assigned to
$bmUdaDepts = [];
if ($isBranchManager) {
    $bmUdaQ = $db->prepare("
        SELECT uda.department_id, d.dept_name, d.color, d.dept_code
        FROM user_department_assignments uda
        JOIN departments d ON d.id = uda.department_id
        WHERE uda.user_id = ? AND d.dept_code NOT IN ('CON','CORE') AND d.is_active = 1
    ");
    $bmUdaQ->execute([$adminUserId]);
    $bmUdaDepts = $bmUdaQ->fetchAll(PDO::FETCH_ASSOC);
}

$validDistDeptIds = $isBranchManager
    ? array_merge([0], array_column($bmUdaDepts, 'department_id'))
    : array_merge([$adminDeptId], $allUdaDeptIds);

if ($distDeptFilter !== 0 && !in_array($distDeptFilter, $validDistDeptIds)) {
    $distDeptFilter = $isBranchManager ? 0 : $adminDeptId;
}
// ── 4. Staff performance distribution ────────────────────────────────────────
$statusCols = '';
foreach ($allStatuses as $st) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
    $quoted = $db->quote($st['status_name']);
    $statusCols .= "SUM(CASE WHEN ut.status_name = {$quoted} THEN 1 ELSE 0 END) AS `{$safe}`,\n        ";
}

// For BM: no dept filter. For dept admin: scope to selected dept + branch.
// We use placeholder literals directly since this goes inside a subquery.
if ($isBranchManager) {
    $unionDeptFilter = $distDeptFilter > 0
        ? "t.branch_id = {$adminBranchId} AND t.department_id = {$distDeptFilter}"
        : "t.branch_id = {$adminBranchId} AND t.department_id NOT IN (SELECT id FROM departments WHERE dept_code IN ('CON','CORE'))";
} else {
    $unionDeptFilter = "t.branch_id = {$adminBranchId} AND t.department_id = {$distDeptFilter}";
}
$unionSql = "
    SELECT t.id AS task_id, t.assigned_to AS user_id, ts.status_name, 0 AS via_transfer
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE t.is_active = 1
      AND {$unionDeptFilter}
    UNION
    SELECT t.id AS task_id, tw.to_user_id AS user_id, ts.status_name, 1 AS via_transfer
    FROM task_workflow tw
    JOIN  tasks t            ON t.id  = tw.task_id
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE t.is_active = 1
      AND {$unionDeptFilter}
      AND tw.action IN ('transferred_staff','transferred_dept')
      AND tw.to_user_id IS NOT NULL
";

if ($isBranchManager) {
    // BM: all staff in their branch, any dept — include staff linked via UDA too
    $deptDistStmt = $db->prepare("
        SELECT
            u.full_name,
            u.employee_id,
            u.id AS user_id,
            d.dept_name,
            COUNT(DISTINCT ut.task_id)  AS task_count,
            COALESCE(SUM(ut.via_transfer), 0)     AS transferred_in_count,
            COALESCE(SUM(1 - ut.via_transfer), 0) AS original_count,
            {$statusCols}
            CASE (ROW_NUMBER() OVER (ORDER BY COUNT(DISTINCT ut.task_id) DESC) % 8)
                WHEN 0 THEN '#f59e0b' WHEN 1 THEN '#3b82f6' WHEN 2 THEN '#10b981'
                WHEN 3 THEN '#8b5cf6' WHEN 4 THEN '#ef4444' WHEN 5 THEN '#ec4899'
                WHEN 6 THEN '#06b6d4' WHEN 7 THEN '#f97316'
            END AS color
        FROM users u
        LEFT JOIN roles r        ON r.id  = u.role_id
        LEFT JOIN departments d  ON d.id  = u.department_id
        LEFT JOIN ({$unionSql}) AS ut ON ut.user_id = u.id
        WHERE r.role_name = 'staff'
          AND u.is_active  = 1
          AND u.branch_id  = ?
          AND (
              d.dept_code IS NULL
              OR d.dept_code NOT IN ('CON','CORE')
              OR EXISTS (
                  SELECT 1 FROM user_department_assignments uda_chk
                  JOIN departments d2 ON d2.id = uda_chk.department_id
                  WHERE uda_chk.user_id = u.id
                    AND d2.dept_code NOT IN ('CON','CORE')
              )
          )
          AND (
              ? = 0
              OR u.department_id = ?
              OR EXISTS (
                  SELECT 1 FROM user_department_assignments uda2
                  WHERE uda2.user_id = u.id AND uda2.department_id = ?
              )
          )
        GROUP BY u.id, u.full_name, u.employee_id, d.dept_name
        ORDER BY task_count DESC
    ");
    $deptDistStmt->execute([$adminBranchId, $distDeptFilter, $distDeptFilter, $distDeptFilter]);
} else {
    // Dept admin: all staff in selected dept (primary OR UDA), show even with 0 tasks
    $deptDistStmt = $db->prepare("
        SELECT
            u.full_name,
            u.employee_id,
            u.id AS user_id,
            d.dept_name,
            COALESCE(COUNT(DISTINCT ut.task_id), 0)  AS task_count,
            COALESCE(SUM(ut.via_transfer), 0)        AS transferred_in_count,
            COALESCE(SUM(1 - ut.via_transfer), 0)    AS original_count,
            {$statusCols}
            CASE (ROW_NUMBER() OVER (ORDER BY COUNT(DISTINCT ut.task_id) DESC) % 8)
                WHEN 0 THEN '#f59e0b' WHEN 1 THEN '#3b82f6' WHEN 2 THEN '#10b981'
                WHEN 3 THEN '#8b5cf6' WHEN 4 THEN '#ef4444' WHEN 5 THEN '#ec4899'
                WHEN 6 THEN '#06b6d4' WHEN 7 THEN '#f97316'
            END AS color
        FROM users u
        LEFT JOIN roles r       ON r.id = u.role_id
        LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN ({$unionSql}) AS ut ON ut.user_id = u.id
        WHERE r.role_name = 'staff'
          AND u.is_active = 1
          AND u.branch_id = ?
          AND (
              u.department_id = ?
              OR EXISTS (
                  SELECT 1 FROM user_department_assignments uda2
                  WHERE uda2.user_id       = u.id
                    AND uda2.department_id = ?
              )
          )
        GROUP BY u.id, u.full_name, u.employee_id, d.dept_name
        ORDER BY task_count DESC
    ");
    $deptDistStmt->execute([$adminBranchId, $distDeptFilter, $distDeptFilter]);
}
$deptDist = $deptDistStmt->fetchAll();

// Label for the active dist dept
$distDeptLabel = '';
$distDeptColor = '#c9a84c';
if (!$isBranchManager) {
    foreach (array_merge(
        [['department_id' => $adminDeptId, 'dept_name' => $user['dept_name'], 'color' => $user['dept_color'] ?? '#c9a84c']],
        $adminUdaDepts
    ) as $dd) {
        if ((int) $dd['department_id'] === $distDeptFilter) {
            $distDeptLabel = $dd['dept_name'];
            $distDeptColor = $dd['color'] ?: '#c9a84c';
            break;
        }
    }
}

// ── 5. Recent tasks ───────────────────────────────────────────────────────────
if ($isBranchManager) {
    $recentStmt = $db->prepare("
        SELECT t.*, ts.status_name AS status,
               d.dept_name, d.color, c.company_name,
               u.full_name AS assigned_to_name
        FROM tasks t
        LEFT JOIN departments d  ON d.id  = t.department_id
        LEFT JOIN companies   c  ON c.id  = t.company_id
        LEFT JOIN users       u  ON u.id  = t.assigned_to
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active = 1 AND t.branch_id = ?
        ORDER BY t.created_at DESC
        LIMIT 8
    ");
    $recentStmt->execute([$adminBranchId]);
} else {
    // Collect all dept IDs this admin can see (primary + UDA)
    $allAdminDeptIds = array_unique(array_merge(
        [$adminDeptId],
        array_column($adminUdaDepts, 'department_id')
    ));
    $recentDeptFilter = (int) ($_GET['recent_dept'] ?? $adminDeptId);
    // Fallback: if selected dept not in allowed list, use primary
    if (!in_array($recentDeptFilter, $allAdminDeptIds)) {
        $recentDeptFilter = $adminDeptId;
    }

    if ($dashHasUda) {
        // UDA manager: show recent tasks across all their accessible depts in their branch
        $recentStmt = $db->prepare("
            SELECT t.*, ts.status_name AS status,
                   d.dept_name, d.color, c.company_name,
                   u.full_name AS assigned_to_name
            FROM tasks t
            LEFT JOIN departments d  ON d.id  = t.department_id
            LEFT JOIN companies   c  ON c.id  = t.company_id
            LEFT JOIN users       u  ON u.id  = t.assigned_to
            LEFT JOIN task_status ts ON ts.id = t.status_id
            WHERE t.is_active   = 1
              AND t.branch_id   = ?
              AND t.department_id = ?
            ORDER BY t.created_at DESC
            LIMIT 8
        ");
        $recentStmt->execute([$adminBranchId, $recentDeptFilter]);
    } else {
        $recentStmt = $db->prepare("
            SELECT t.*, ts.status_name AS status,
                   d.dept_name, d.color, c.company_name,
                   u.full_name AS assigned_to_name
            FROM tasks t
            LEFT JOIN departments d  ON d.id  = t.department_id
            LEFT JOIN companies   c  ON c.id  = t.company_id
            LEFT JOIN users       u  ON u.id  = t.assigned_to
            LEFT JOIN task_status ts ON ts.id = t.status_id
            WHERE t.is_active   = 1
              AND t.branch_id   = ?
              AND t.department_id = ?
            ORDER BY t.created_at DESC
            LIMIT 8
        ");
        $recentStmt->execute([$adminBranchId, $recentDeptFilter]);
    }
}
$recentTasks = $recentStmt->fetchAll();

// Build dept label map for recent tasks tabs (dept admin only)
$recentDeptTabs = [];
if (!empty($allAdminAccessDeptIds)) {
    $tabDeptIds = implode(',', array_map('intval', $allAdminAccessDeptIds));
    $recentDeptTabs = $db->query("
        SELECT id, dept_name, color FROM departments
        WHERE id IN ({$tabDeptIds}) AND is_active = 1
        AND dept_code NOT IN ('CON','CORE')
        ORDER BY FIELD(id, {$adminDeptId}) DESC, dept_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

include '../../includes/header.php';
?>
<style>
    .page-hero {
        position: relative;
        z-index: 10;
        border-radius: 16px;
        overflow: visible;
        /* hero itself no longer clips */
    }

    .page-hero::before {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: 16px;
        background: inherit;
        /* or move your gradient/bg-image here */
        z-index: -1;
    }
    .dropdown-menu.show {
        z-index: 1055 !important;
    }
</style>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_manager.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <!-- Hero -->
            <div class="page-hero">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="sidebar-user-role">
                            <?php if ($isBranchManager): ?>
                                <i class="fas fa-code-branch me-1"></i>Branch Manager
                            <?php else: ?>
                                <i
                                    class="fas fa-user-shield me-1"></i><?= htmlspecialchars($user['role_name'] ?? 'Manager') ?>
                            <?php endif; ?>
                        </div>
                        <h4>Hello, <?= htmlspecialchars($user['full_name'] ?? $userSession['username']) ?></h4>
                        <p style="margin:0;">
                            <?= htmlspecialchars($user['branch_name'] ?? '') ?>
                            <?php if ($isBranchManager): ?>
                                &mdash; <span style="color:#c9a84c;">Branch Manager · All Departments</span>
                            <?php elseif (!empty($user['dept_name'])): ?>
                                &mdash; <?= htmlspecialchars($user['dept_name']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex align-items-center gap-2"> 
                    <a href="<?= APP_URL ?>/manager/tasks/assign.php"
                        class="btn-gold btn d-flex align-items-center gap-2">
                        <i class="fas fa-plus"></i> Assign Task
                    </a>
                    <?php if (isCoreAdmin()): ?>
                        <div class="dropdown">
                            <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2"
                                type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-cog"></i>
                                <span>Settings</span>
                            </button>

                            <ul class="dropdown-menu dropdown-menu-end shadow p-2" style="min-width:220px;">

                                <!-- Header -->
                                <li class="px-3 py-2 text-muted small fw-semibold">
                                    <i class="fas fa-sliders-h me-1"></i> System Configuration
                                </li>

                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2"
                                        href="<?= APP_URL ?>/manager/settings/task_status.php">
                                        <i class="fas fa-tasks text-primary"></i>
                                        <span>Task Status</span>
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2"
                                        href="<?= APP_URL ?>/manager/settings/corporate_grades.php">
                                        <i class="fas fa-chart-line text-success"></i>
                                        <span>Corporate Grades</span>
                                    </a>
                                </li>

                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2"
                                        href="<?= APP_URL ?>/manager/settings/fiscal_year.php">
                                        <i class="fas fa-calendar-alt text-warning"></i>
                                        <span>Fiscal Year</span>
                                    </a>
                                </li>

                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2"
                                        href="<?= APP_URL ?>/manager/settings/industry.php">
                                        <i class="fas fa-building text-secondary"></i>
                                        <span>Industry</span>
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2"
                                        href="<?= APP_URL ?>/manager/settings/type.php">
                                        <i class="fas fa-tags text-info"></i>
                                        <span>Company Type</span>
                                    </a>
                                </li>

                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                </div>
            </div>

            <!-- Transfer activity banner (dept admin only) -->
            <?php if (!$isBranchManager && ($transferIn > 0 || $transferOut > 0)): ?>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;
                    padding:.65rem 1rem;margin-bottom:1rem;
                    display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
                    <span style="font-size:.78rem;color:#1d4ed8;font-weight:700;">
                        <i class="fas fa-exchange-alt me-1"></i>Transfer Activity
                    </span>
                    <span style="font-size:.78rem;color:#16a34a;">
                        <i class="fas fa-arrow-down me-1"></i><strong><?= $transferIn ?></strong> received
                    </span>
                    <span style="font-size:.78rem;color:#ef4444;">
                        <i class="fas fa-arrow-up me-1"></i><strong><?= $transferOut ?></strong> sent out
                    </span>
                </div>
            <?php endif; ?>

            <!-- Stat cards -->
            <div class="row g-3 mb-4">
                <?php foreach ($allStatuses as $st):
                    $k = $st['status_name'];
                    $cnt = $byStatus[$k] ?? 0;
                    $col = $st['color'] ?? '#6b7280';
                    $bg = $st['bg_color'] ?? '#f3f4f6';
                    $icon = $st['icon'] ?? 'fa-circle';
                    ?>
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-card-icon"
                                style="background:<?= htmlspecialchars($bg) ?>;color:<?= htmlspecialchars($col) ?>;">
                                <i class="fas <?= htmlspecialchars($icon) ?>"></i>
                            </div>
                            <div class="stat-card-value" style="color:<?= htmlspecialchars($col) ?>;">
                                <?= number_format($cnt) ?>
                            </div>
                            <div class="stat-card-label"><?= htmlspecialchars($k) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Total Tasks -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background:#eff6ff;color:#3b82f6;">
                            <i class="fas fa-list-check"></i>
                        </div>
                        <div class="stat-card-value" style="color:#3b82f6;"><?= number_format($total) ?></div>
                        <div class="stat-card-label">Total Tasks</div>
                    </div>
                </div>

                <!-- Staff count -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background:#fdf2f8;color:#ec4899;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-card-value" style="color:#ec4899;"><?= number_format($staffCount) ?></div>
                        <div class="stat-card-label"><?= $isBranchManager ? 'Branch Staff' : 'My Staff' ?></div>
                    </div>
                </div>
            </div>
            <!-- Primary Dept KPI (dept admins only) -->
            <?php if (!$isBranchManager && !empty($primaryDeptStatus)):
                $primaryTotal = array_sum($primaryDeptStatus);
                $deptColor = $user['dept_color'] ?? '#c9a84c'; // fallback gold
                ?>
                <div class="card-mis mb-3">
                    <div class="card-mis-header" style="border-left:3px solid <?= htmlspecialchars($deptColor) ?>;">
                        <h5 style="font-size:.88rem;">
                            <i class="fas fa-building me-2" style="color:<?= htmlspecialchars($deptColor) ?>;"></i>
                            <?= htmlspecialchars($user['dept_name'] ?? 'My Department') ?> — Task Status
                            <span style="font-size:.72rem;color:#9ca3af;font-weight:400;margin-left:.4rem;">(primary
                                dept)</span>
                        </h5>
                        <span style="font-size:.75rem;color:#9ca3af;"><?= $primaryTotal ?> total tasks</span>
                    </div>
                    <div class="card-mis-body">
                        <div class="row g-2">
                            <?php foreach ($allStatuses as $st):
                                $sn = $st['status_name'];
                                $cnt = $primaryDeptStatus[$sn] ?? 0;
                                $col = $st['color'] ?: '#9ca3af';
                                ?>
                                <div class="col-6 col-md-3 col-xl-2">
                                    <div
                                        style="background:#f9fafb;border-radius:10px;padding:.75rem;text-align:center;border:1px solid #f3f4f6;">
                                        <div style="font-size:1.3rem;font-weight:800;color:<?= $col ?>;"><?= $cnt ?></div>
                                        <div style="font-size:.68rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">
                                            <?= htmlspecialchars($sn) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="col-6 col-md-3 col-xl-2">
                                <div
                                    style="background:#f9fafb;border-radius:10px;padding:.75rem;text-align:center;border:1px solid <?= htmlspecialchars($deptColor) ?>44;">
                                    <div
                                        style="font-size:1.3rem;font-weight:800;color:<?= htmlspecialchars($deptColor) ?>;">
                                        <?= $primaryTotal ?>
                                    </div>
                                    <div style="font-size:.68rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">
                                        Total</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <!-- UDA Additional Dept KPIs -->
            <?php foreach ($udaDeptStatus as $udaDid => $udaData):
                $udaTotal = array_sum($udaData['counts']);
                if ($udaTotal === 0)
                    continue;
                // skip consulting dept in display — it uses plans/logs not tasks
                $udaDeptQ2 = $db->prepare("SELECT dept_code FROM departments WHERE id = ?");
                $udaDeptQ2->execute([$udaDid]);
                $udaDeptCode2 = $udaDeptQ2->fetchColumn();
                if (in_array($udaDeptCode2, ['CON']))
                    continue;
                ?>
                <div class="card-mis mb-3">
                    <div class="card-mis-header"
                        style="border-left:3px solid <?= htmlspecialchars($udaData['color'] ?: '#c9a84c') ?>;">
                        <h5 style="font-size:.88rem;">
                            <i class="fas fa-layer-group me-2"
                                style="color:<?= htmlspecialchars($udaData['color'] ?: '#c9a84c') ?>;"></i>
                            <?= htmlspecialchars($udaData['dept_name']) ?> — Task Status
                            <span style="font-size:.72rem;color:#9ca3af;font-weight:400;margin-left:.4rem;">(additional
                                dept)</span>
                        </h5>
                        <span style="font-size:.75rem;color:#9ca3af;"><?= $udaTotal ?> total tasks</span>
                    </div>
                    <div class="card-mis-body">
                        <div class="row g-2">
                            <?php foreach ($allStatuses as $st):
                                $sn = $st['status_name'];
                                $cnt = $udaData['counts'][$sn] ?? 0;
                                $col = $st['color'] ?: '#9ca3af';
                                $bg = $st['bg_color'] ?: '#f3f4f6';
                                $ico = $st['icon'] ?: 'fa-circle';
                                ?>
                                <div class="col-6 col-md-3 col-xl-2">
                                    <div
                                        style="background:#f9fafb;border-radius:10px;padding:.75rem;text-align:center;border:1px solid #f3f4f6;">
                                        <div style="font-size:1.3rem;font-weight:800;color:<?= $col ?>;"><?= $cnt ?></div>
                                        <div style="font-size:.68rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">
                                            <?= htmlspecialchars($sn) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="col-6 col-md-3 col-xl-2">
                                <div
                                    style="background:#f9fafb;border-radius:10px;padding:.75rem;text-align:center;border:1px solid <?= htmlspecialchars($udaData['color'] ?: '#c9a84c') ?>44;">
                                    <div
                                        style="font-size:1.3rem;font-weight:800;color:<?= htmlspecialchars($udaData['color'] ?: '#c9a84c') ?>;">
                                        <?= $udaTotal ?>
                                    </div>
                                    <div style="font-size:.68rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">
                                        Total</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <!-- Staff Work Distribution -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-chart-bar text-warning me-2"></i>Staff Work Distribution</h5>
                    <span style="font-size:.75rem;color:#9ca3af;">
                        <?= htmlspecialchars($user['branch_name'] ?? '') ?>
                        <?php if ($isBranchManager): ?>
                            · <span style="color:#c9a84c;">All Departments</span>
                        <?php else: ?>
                            · <span
                                style="color:<?= htmlspecialchars($distDeptColor) ?>;"><?= htmlspecialchars($distDeptLabel ?: ($user['dept_name'] ?? '')) ?></span>
                        <?php endif; ?>
                        <span
                            style="margin-left:.4rem;background:#eff6ff;color:#3b82f6;padding:.1rem .45rem;border-radius:99px;font-size:.68rem;">
                            incl. transferred tasks
                        </span>
                    </span>
                </div>

                <?php
                // Build tabs: for BM show their UDA depts + "All"; for dept admin show their accessible depts
                $distTabs = [];
                if ($isBranchManager && count($bmUdaDepts) > 0) {
                    $distTabs = $bmUdaDepts;
                } elseif (!$isBranchManager && count($recentDeptTabs) > 1) {
                    $distTabs = $recentDeptTabs;
                }
                ?>
                <?php if (!empty($distTabs)): ?>
                    <div style="display:flex;gap:0;border-bottom:2px solid #f3f4f6;overflow-x:auto;">
                        <?php if ($isBranchManager): ?>
                            <!-- "All Departments" tab for BM -->
                            <?php $allActive = ($distDeptFilter === 0); ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['dist_dept' => 0])) ?>#dist-section" style="padding:.6rem 1.2rem;font-size:.8rem;font-weight:600;text-decoration:none;
                white-space:nowrap;border-bottom:2px solid <?= $allActive ? '#c9a84c' : 'transparent' ?>;
                margin-bottom:-2px;color:<?= $allActive ? '#c9a84c' : '#9ca3af' ?>;
                background:<?= $allActive ? '#c9a84c0d' : 'transparent' ?>;transition:.15s;">
                                <i class="fas fa-layer-group me-1" style="font-size:.7rem;"></i>All Depts
                            </a>
                        <?php endif; ?>
                        <?php foreach ($distTabs as $dtab):
                            $isActive = ($distDeptFilter == $dtab['id']);
                            $tabColor = $dtab['color'] ?: '#c9a84c';
                            ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['dist_dept' => $dtab['id']])) ?>#dist-section"
                                style="padding:.6rem 1.2rem;font-size:.8rem;font-weight:600;text-decoration:none;
                white-space:nowrap;border-bottom:2px solid <?= $isActive ? $tabColor : 'transparent' ?>;
                margin-bottom:-2px;
                color:<?= $isActive ? $tabColor : '#9ca3af' ?>;
                background:<?= $isActive ? $tabColor . '0d' : 'transparent' ?>;
                transition:.15s;">
                                <i class="fas fa-users me-1" style="font-size:.7rem;"></i>
                                <?= htmlspecialchars($dtab['dept_name']) ?>
                                <?php if (!$isBranchManager && $dtab['id'] == $adminDeptId): ?>
                                    <span style="font-size:.6rem;color:<?= $tabColor ?>;margin-left:.2rem;">★</span>
                                <?php endif; ?>
                                <?php if ($isActive): ?>
                                    <span style="background:<?= $tabColor ?>22;color:<?= $tabColor ?>;
                        font-size:.62rem;font-weight:700;padding:.05rem .35rem;
                        border-radius:99px;margin-left:.3rem;">
                                        <?= count($deptDist) ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="card-mis-body" id="dist-section">
                    <?php if (!empty($deptDist)): ?>
                        <div style="height:300px;position:relative;margin-bottom:1.5rem;">
                            <canvas id="staffBarChart"></canvas>
                        </div>
                        <div class="row g-2 mt-2">
                            <?php
                            // Prefetch UDA extra depts for all dist staff (avoids N+1)
                            $distStaffIds = array_column($deptDist, 'user_id');
                            $distUdaMap = [];
                            if (!empty($distStaffIds)) {
                                $distInPh = implode(',', array_fill(0, count($distStaffIds), '?'));
                                $distUdaPre = $db->prepare("
                                    SELECT uda.user_id, d.dept_name, d.color
                                    FROM user_department_assignments uda
                                    JOIN departments d ON d.id = uda.department_id
                                    JOIN users u ON u.id = uda.user_id
                                    WHERE uda.user_id IN ({$distInPh})
                                    AND uda.department_id != u.department_id
                                    AND d.dept_code NOT IN ('CON','CORE')
                                    AND d.is_active = 1
                                    ORDER BY uda.user_id, d.dept_name
                                ");
                                $distUdaPre->execute($distStaffIds);
                                foreach ($distUdaPre->fetchAll(PDO::FETCH_ASSOC) as $row) {
                                    $distUdaMap[$row['user_id']][] = $row;
                                }
                            }
                            ?>
                            <?php foreach ($deptDist as $s):
                                $doneKey = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower('Done'));
                                $doneCnt = (int) ($s[$doneKey] ?? 0);
                                $openCnt = max(0, (int) $s['task_count'] - $doneCnt);
                                $xferIn = (int) ($s['transferred_in_count'] ?? 0);
                                $donePct = $s['task_count'] > 0 ? round(($doneCnt / $s['task_count']) * 100) : 0;
                                ?>
                                <div class="col-md-4 col-6">
                                    <div
                                        style="background:#f9fafb;border-radius:10px;padding:.75rem 1rem;border-left:3px solid <?= htmlspecialchars($s['color']) ?>;">
                                        <div
                                            style="font-size:.82rem;font-weight:600;color:#1f2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                            <?= htmlspecialchars($s['full_name']) ?>
                                        </div>
                                        <div style="font-size:.7rem;color:#9ca3af;margin-bottom:.5rem;">
                                            <?= htmlspecialchars($s['employee_id'] ?? '') ?>
                                            <?php if ($isBranchManager && !empty($s['dept_name'])): ?>
                                                <span
                                                    style="background:#f3f4f6;padding:.05rem .35rem;border-radius:3px;margin-left:.3rem;font-size:.65rem;color:#6b7280;">
                                                    <?= htmlspecialchars($s['dept_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php foreach (($distUdaMap[$s['user_id'] ?? 0] ?? []) as $ux): ?>
                                                <span
                                                    style="font-size:.63rem;
                                                             background:<?= htmlspecialchars($ux['color'] ?? '#ccc') ?>22;
                                                             color:<?= htmlspecialchars($ux['color'] ?? '#666') ?>;
                                                             padding:.05rem .35rem;border-radius:3px;
                                                             margin-left:.2rem;
                                                             border:1px dashed <?= htmlspecialchars($ux['color'] ?? '#ccc') ?>66;">
                                                    +<?= htmlspecialchars($ux['dept_name']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if ($xferIn > 0): ?>
                                                <span
                                                    style="background:#eff6ff;color:#3b82f6;padding:.05rem .35rem;border-radius:3px;margin-left:.3rem;font-size:.65rem;">
                                                    +<?= $xferIn ?> transferred in
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex gap-2 align-items-center">
                                            <div style="text-align:center;">
                                                <div style="font-size:1rem;font-weight:700;color:#f59e0b;"><?= $openCnt ?></div>
                                                <div style="font-size:.65rem;color:#9ca3af;">Open</div>
                                            </div>
                                            <div style="width:1px;background:#e5e7eb;align-self:stretch;"></div>
                                            <div style="text-align:center;">
                                                <div style="font-size:1rem;font-weight:700;color:#10b981;"><?= $doneCnt ?></div>
                                                <div style="font-size:.65rem;color:#9ca3af;">Done</div>
                                            </div>
                                            <div style="width:1px;background:#e5e7eb;align-self:stretch;"></div>
                                            <div style="text-align:center;">
                                                <div style="font-size:1rem;font-weight:700;color:#3b82f6;">
                                                    <?= $s['task_count'] ?>
                                                </div>
                                                <div style="font-size:.65rem;color:#9ca3af;">Total</div>
                                            </div>
                                            <div style="flex:1;margin-left:.5rem;">
                                                <div style="background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
                                                    <div
                                                        style="width:<?= $donePct ?>%;background:#10b981;height:100%;border-radius:99px;">
                                                    </div>
                                                </div>
                                                <div style="font-size:.65rem;color:#9ca3af;text-align:right;"><?= $donePct ?>%
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5" style="color:#9ca3af;">
                            <i class="fas fa-users fa-2x mb-2 d-block"></i>
                            <p style="font-size:.85rem;margin:0;">No staff tasks found for your branch.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <script>
                (function () {
                    const ctx = document.getElementById('staffBarChart');
                    if (!ctx) return;
                    const data = <?= json_encode(array_values($deptDist)) ?>;
                    if (!data.length) return;
                    const statuses = <?= json_encode(array_map(function ($st) {
                        return [
                            'key' => preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name'])),
                            'label' => $st['status_name'],
                            'color' => $st['color'] ?? '#9ca3af',
                        ];
                    }, $allStatuses)) ?>;
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.map(d => d.full_name.split(' ')[0]),
                            datasets: statuses
                                .filter(s => data.some(d => (d[s.key] ?? 0) > 0))
                                .map(s => ({
                                    label: s.label,
                                    data: data.map(d => parseInt(d[s.key] ?? 0)),
                                    backgroundColor: s.color,
                                    borderRadius: 6, borderSkipped: false,
                                }))
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { display: true, position: 'top', labels: { font: { size: 11 }, usePointStyle: true, padding: 14 } },
                                tooltip: { callbacks: { afterBody: items => [`Total: ${data[items[0].dataIndex].task_count} tasks`] } }
                            },
                            scales: {
                                x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
                                y: { stacked: true, beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { stepSize: 1, font: { size: 11 } } }
                            }
                        }
                    });
                })();
            </script>

            <!-- Recent Tasks -->
            <!-- Recent Tasks -->
            <div class="card-mis mt-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-clock text-warning me-2"></i>Recent Tasks</h5>
                    <a href="<?= APP_URL ?>/manager/tasks/index.php" class="btn btn-sm btn-outline-secondary">View
                        All</a>
                </div>

                <?php if (!$isBranchManager && count($recentDeptTabs) > 1): ?>
                    <!-- Dept tabs for recent tasks -->
                    <div style="display:flex;gap:0;border-bottom:2px solid #f3f4f6;overflow-x:auto;">
                        <?php foreach ($recentDeptTabs as $rtab):
                            $isActive = ($recentDeptFilter == $rtab['id']);
                            $tabColor = $rtab['color'] ?: '#c9a84c';
                            ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['recent_dept' => $rtab['id']])) ?>#recent-tasks"
                                style="padding:.6rem 1.2rem;font-size:.8rem;font-weight:600;text-decoration:none;
                              white-space:nowrap;border-bottom:2px solid <?= $isActive ? $tabColor : 'transparent' ?>;
                              margin-bottom:-2px;
                              color:<?= $isActive ? $tabColor : '#9ca3af' ?>;
                              background:<?= $isActive ? $tabColor . '0d' : 'transparent' ?>;
                              transition:.15s;">
                                <i class="fas fa-layer-group me-1" style="font-size:.7rem;"></i>
                                <?= htmlspecialchars($rtab['dept_name']) ?>
                                <?php if ($rtab['id'] == $adminDeptId): ?>
                                    <span style="font-size:.6rem;color:<?= $tabColor ?>;margin-left:.2rem;">★</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="table-responsive" id="recent-tasks">
                    <table class="table-mis w-100">
                        <thead>
                            <tr>
                                <th>Task #</th>
                                <th>Title</th>
                                <th>Department</th>
                                <th>Company</th>
                                <th>Assigned To</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTasks)): ?>
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        <i class="fas fa-list-check"></i> No tasks yet
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($recentTasks as $t):
                                $sClass = 'status-' . strtolower(str_replace(' ', '-', $t['status'] ?? ''));
                                ?>
                                <tr>
                                    <td><span class="task-number"><?= htmlspecialchars($t['task_number']) ?></span></td>
                                    <td
                                        style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.87rem;font-weight:500;">
                                        <?= htmlspecialchars($t['title']) ?>
                                    </td>
                                    <td>
                                        <span
                                            style="font-size:.73rem;background:<?= htmlspecialchars($t['color'] ?? '#ccc') ?>22;color:<?= htmlspecialchars($t['color'] ?? '#666') ?>;padding:.2rem .5rem;border-radius:99px;">
                                            <?= htmlspecialchars($t['dept_name'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($t['company_name'] ?? '—') ?></td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($t['assigned_to_name'] ?? '—') ?>
                                    </td>
                                    <td><span
                                            class="status-badge <?= $sClass ?>"><?= htmlspecialchars($t['status'] ?? '—') ?></span>
                                    </td>
                                    <td style="font-size:.78rem;color:#9ca3af;">
                                        <?= date('M j', strtotime($t['created_at'])) ?>
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