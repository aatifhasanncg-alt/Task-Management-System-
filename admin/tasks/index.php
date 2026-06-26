<?php
// admin/tasks/index.php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAdmin();

$db = getDB();
$user = currentUser();

$adminStmt = $db->prepare("
    SELECT u.*, d.dept_code 
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.id = ?
");
$adminStmt->execute([$user['id']]);
$adminUser = $adminStmt->fetch();

$pageTitle = 'All Tasks';
$adminBranchId = (int) ($adminUser['branch_id'] ?? 0);
$adminDeptId = (int) ($adminUser['department_id'] ?? 0);
$isBranchManager = (($adminUser['dept_code'] ?? '') === 'CORE');
// ── UDA dept tabs (non-BM only) ──────────────────────────────────────────────
$activeTaskDeptTab = 0;
$taskDeptTabs = [];
if (!$isBranchManager) {
    $udaTabStmt = $db->prepare("
        SELECT d.id, d.dept_name, d.color
        FROM user_department_assignments uda
        JOIN departments d ON d.id = uda.department_id
        WHERE uda.user_id = ?
        ORDER BY d.dept_name
    ");
    $udaTabStmt->execute([$user['id']]);
    $udaTabDepts = $udaTabStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build full tab list: primary dept first, then UDA depts
    $primaryDeptStmt = $db->prepare("SELECT id, dept_name, color FROM departments WHERE id = ?");
    $primaryDeptStmt->execute([$adminDeptId]);
    $primaryDeptRow = $primaryDeptStmt->fetch(PDO::FETCH_ASSOC);

    if ($primaryDeptRow) {
        $taskDeptTabs[] = array_merge($primaryDeptRow, ['is_primary' => true]);
    }
    foreach ($udaTabDepts as $udt) {
        if ($udt['id'] != $adminDeptId) {
            $taskDeptTabs[] = array_merge($udt, ['is_primary' => false]);
        }
    }

    $activeTaskDeptTab = (int) ($_GET['task_dept'] ?? 0);
    $validTabIds = array_column($taskDeptTabs, 'id');
    if ($activeTaskDeptTab !== 0 && !in_array($activeTaskDeptTab, $validTabIds)) {
        $activeTaskDeptTab = 0;
    }
}
// Filters
$filterStatus = $_GET['status'] ?? '';
$filterStaff = (int) ($_GET['staff_id'] ?? 0);
$filterCompany = (int) ($_GET['company_id'] ?? 0);
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$filterDept = (int) ($_GET['dept_id'] ?? 0);
$filterFY = trim($_GET['fy'] ?? '');
$filterBankRef = (int) ($_GET['bank_ref'] ?? 0);
$search = trim($_GET['search'] ?? '');
$showAll = isset($_GET['show_all']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$filterOverdue = isset($_GET['overdue']) && $_GET['overdue'] == 1;
// ── Scope BUILD ───────────────────────────────────────────────────────────────
$where = ['t.is_active = 1'];
$params = [];

if (!isExecutive()) {
    if ($isBranchManager) {
        $where[] = 't.branch_id = ?';
        $params[] = $adminBranchId;
    } else {
        // If a dept tab is selected, scope to that dept only
        // Otherwise scope to all accessible depts
        $udaStmt = $db->prepare("
            SELECT department_id FROM user_department_assignments WHERE user_id = ?
        ");
        $udaStmt->execute([$user['id']]);
        $udaDeptIds = array_column($udaStmt->fetchAll(), 'department_id');

        $allAccessDeptIds = array_unique(array_filter(
            array_merge([$adminDeptId], $udaDeptIds)
        ));

        // Tab scoping: if a specific tab is active, filter to that dept only
        $scopedDeptIds = ($activeTaskDeptTab && $activeTaskDeptTab !== 0)
            ? [$activeTaskDeptTab]
            : $allAccessDeptIds;

        $deptPlaceholders = implode(',', array_fill(0, count($scopedDeptIds), '?'));

        if ($showAll) {
            $where[] = "(
                (t.branch_id = ? AND t.department_id IN ({$deptPlaceholders}))
                OR EXISTS (SELECT 1 FROM task_workflow tw WHERE tw.task_id=t.id AND tw.action='transferred_dept' AND tw.from_dept_id IN ({$deptPlaceholders}))
                OR EXISTS (SELECT 1 FROM task_workflow tw WHERE tw.task_id=t.id AND tw.action='transferred_dept' AND tw.to_dept_id   IN ({$deptPlaceholders}))
                OR t.created_by  = ?
                OR t.assigned_to = ?
            )";
            $params = array_merge(
                $params,
                [$adminBranchId],
                $scopedDeptIds,
                $scopedDeptIds,
                $scopedDeptIds,
                [$user['id'], $user['id']]
            );
        } else {
            $where[] = "(t.branch_id = ? AND t.department_id IN ({$deptPlaceholders}))";
            $params = array_merge($params, [$adminBranchId], $scopedDeptIds);
        }
    }
}

// Optional filters
if ($filterStatus) {
    $where[] = 'ts.status_name = ?';
    $params[] = $filterStatus;
}
if ($filterStaff) {
    $where[] = 't.assigned_to = ?';
    $params[] = $filterStaff;
}
if ($filterDateFrom) {
    $where[] = 't.created_at >= ?';
    $params[] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo) {
    $where[] = 't.created_at <= ?';
    $params[] = $filterDateTo . ' 23:59:59';
}
if ($filterCompany) {
    $where[] = 't.company_id = ?';
    $params[] = $filterCompany;
}
if ($filterDept) {
    $where[] = 't.department_id = ?';
    $params[] = $filterDept;
}
if ($filterFY) {
    $where[] = 't.fiscal_year = ?';
    $params[] = $filterFY;
}
if ($filterBankRef) {
    $where[] = 'EXISTS (SELECT 1 FROM task_banking tb WHERE tb.task_id = t.id AND tb.bank_reference_id = ?)';
    $params[] = $filterBankRef;
}
if ($filterOverdue) {
    $where[] = 't.due_date < CURDATE()';
    $where[] = 'ts.counts_as_done = 0';
}
if ($search) {
    $where[] = '(t.title LIKE ? OR t.task_number LIKE ? OR c.company_name LIKE ? OR at.full_name LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}

$whereStr = implode(' AND ', $where);

// ── Count ─────────────────────────────────────────────────────────────────────
$countSt = $db->prepare("
    SELECT COUNT(DISTINCT t.id)
    FROM tasks t
    LEFT JOIN companies   c  ON c.id  = t.company_id
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN users       at ON at.id = t.assigned_to
    WHERE {$whereStr}
");
$countSt->execute($params);
$total = (int) $countSt->fetchColumn();
$pages = (int) ceil($total / $perPage);

// ── Task list ─────────────────────────────────────────────────────────────────
$taskSt = $db->prepare("
    SELECT t.*,
           d.dept_name, d.dept_code, d.color  AS dept_color,
           b.branch_name,
           c.company_name,
           ts.status_name                      AS status,
           ts.counts_as_done,
           cb.full_name                        AS created_by_name,
           at.full_name                        AS assigned_to_name,
           wf_from.from_dept_id                AS transfer_from_dept_id,
           from_d.dept_name                    AS transfer_from_dept_name,
           from_d.color                        AS transfer_from_dept_color,
           wf_to.to_dept_id                    AS transfer_to_dept_id,
           to_d.dept_name                      AS transfer_to_dept_name,
           to_d.color                          AS transfer_to_dept_color
    FROM tasks t
    LEFT JOIN departments d  ON d.id  = t.department_id
    LEFT JOIN branches    b  ON b.id  = t.branch_id
    LEFT JOIN companies   c  ON c.id  = t.company_id
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN users       cb ON cb.id = t.created_by
    LEFT JOIN users       at ON at.id = t.assigned_to
    LEFT JOIN (
        SELECT task_id, from_dept_id FROM task_workflow
        WHERE action = 'transferred_dept'
          AND id = (SELECT MIN(id) FROM task_workflow tw2 WHERE tw2.task_id=task_workflow.task_id AND tw2.action='transferred_dept')
    ) wf_from ON wf_from.task_id = t.id
    LEFT JOIN departments from_d ON from_d.id = wf_from.from_dept_id
    LEFT JOIN (
        SELECT task_id, to_dept_id FROM task_workflow
        WHERE action = 'transferred_dept'
          AND id = (SELECT MAX(id) FROM task_workflow tw3 WHERE tw3.task_id=task_workflow.task_id AND tw3.action='transferred_dept')
    ) wf_to ON wf_to.task_id = t.id
    LEFT JOIN departments to_d ON to_d.id = wf_to.to_dept_id
    WHERE {$whereStr}
    ORDER BY
        CASE ts.status_name WHEN 'Pending' THEN 1 WHEN 'WIP' THEN 2 WHEN 'HBC' THEN 3
            WHEN 'Not Started' THEN 4 WHEN 'Next Year' THEN 5 WHEN 'Done' THEN 6 ELSE 7 END,
        CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END,
        t.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$taskSt->execute($params);
$tasks = $taskSt->fetchAll();

// ── Staff dropdown for filter ─────────────────────────────────────────────────
if ($isBranchManager) {
    $staffQuery = $db->prepare("
        SELECT u.id, u.full_name FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE r.role_name = 'staff' AND u.is_active = 1 AND u.branch_id = ?
        ORDER BY u.full_name
    ");
    $staffQuery->execute([$adminBranchId]);
} else {
    $staffQuery = $db->prepare("
        SELECT DISTINCT u.id, u.full_name FROM users u
        JOIN roles r ON r.id = u.role_id
        LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
        WHERE r.role_name = 'staff' AND u.is_active = 1
          AND u.branch_id = ?
          AND (u.department_id = ? OR uda.department_id = ?)
        ORDER BY u.full_name
    ");
    $staffQuery->execute([$adminBranchId, $adminDeptId, $adminDeptId]);
}
$allStaff = $staffQuery->fetchAll();

$allDepts = $db->query("SELECT id, dept_name FROM departments WHERE is_active=1 AND dept_code NOT IN ('CON','CORE') ORDER BY dept_name")->fetchAll();

// ── Status tab counts ─────────────────────────────────────────────────────────
$allStatuses = $db->query("
    SELECT id, status_name,
           COALESCE(color,'#9ca3af') AS color, COALESCE(bg_color,'#f3f4f6') AS bg_color,
           COALESCE(icon,'fa-circle-dot') AS icon
    FROM task_status ORDER BY id
")->fetchAll();

$tabCounts = [];
foreach ($allStatuses as $st) {
    $k = $st['status_name'];
    $tabParams = $params;
    $tabWhere = $whereStr;
    if ($filterStatus) {
        $tabWhere = str_replace('ts.status_name = ?', '1=1', $tabWhere);
        $idx = array_search($filterStatus, $tabParams);
        if ($idx !== false) {
            unset($tabParams[$idx]);
            $tabParams = array_values($tabParams);
        }
    }
    $cntSt = $db->prepare("
        SELECT COUNT(DISTINCT t.id)
        FROM tasks t
        LEFT JOIN companies   c  ON c.id  = t.company_id
        LEFT JOIN task_status ts ON ts.id = t.status_id
        LEFT JOIN users       at ON at.id = t.assigned_to
        WHERE {$tabWhere} AND ts.status_name = ?
    ");
    $cntSt->execute(array_merge($tabParams, [$k]));
    $tabCounts[$k] = (int) $cntSt->fetchColumn();
}

$fiscalYears = $db->query("SELECT fy_code FROM fiscal_years WHERE is_active=1 ORDER BY fy_code DESC")->fetchAll(PDO::FETCH_COLUMN);

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>
            <!-- UDA Dept Tabs (non-BM with multiple depts) -->
            <?php if (!$isBranchManager && count($taskDeptTabs) > 1): ?>
                <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;">
                    <a href="?<?= http_build_query(array_merge(array_diff_key($_GET, ['task_dept' => '']), [])) ?>" style="padding:.35rem .9rem;border-radius:99px;font-size:.78rem;font-weight:600;
                          text-decoration:none;
                          background:<?= !$activeTaskDeptTab || !in_array($activeTaskDeptTab, array_column($taskDeptTabs, 'id')) ? '#0a0f1e' : '#f3f4f6' ?>;
                          color:<?= !$activeTaskDeptTab || !in_array($activeTaskDeptTab, array_column($taskDeptTabs, 'id')) ? '#c9a84c' : '#6b7280' ?>;
                          border:1px solid <?= !$activeTaskDeptTab ? '#c9a84c44' : '#e5e7eb' ?>;">
                        All My Depts
                    </a>
                    <?php foreach ($taskDeptTabs as $ttab):
                        $isActive = ($activeTaskDeptTab == $ttab['id']);
                        $tabCol = $ttab['color'] ?: '#3b82f6';
                        ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['task_dept' => $ttab['id'], 'page' => 1])) ?>" style="padding:.35rem .9rem;border-radius:99px;font-size:.78rem;font-weight:600;
                          text-decoration:none;
                          background:<?= $isActive ? $tabCol : '#f3f4f6' ?>;
                          color:<?= $isActive ? '#fff' : '#6b7280' ?>;
                          border:1px solid <?= $isActive ? $tabCol : '#e5e7eb' ?>;">
                            <?= htmlspecialchars($ttab['dept_name']) ?>
                            <?php if ($ttab['is_primary']): ?>
                                <span style="font-size:.6rem;opacity:.8;margin-left:.2rem;">★</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="page-hero">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-list-check"></i> Task List</div>
                        <h4>All Tasks</h4>
                        <p><?= number_format($total) ?> task<?= $total !== 1 ? 's' : '' ?> found
                            <?php if ($isBranchManager): ?>
                                <span style="font-size:.72rem;color:#c9a84c;"> · All departments in your branch</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <a href="assign.php" class="btn btn-gold"><i class="fas fa-plus me-1"></i>Assign Task</a>
                </div>
            </div>


            <!-- Status Tabs -->
            <div class="d-flex gap-2 flex-wrap mb-3">
                <a href="?<?= http_build_query(array_merge(array_diff_key($_GET, ['status' => '', 'page' => '']), $showAll ? ['show_all' => '1'] : [])) ?>"
                    class="btn btn-sm <?= !$filterStatus ? 'btn-navy' : 'btn-outline-secondary' ?>">
                    <i class="fas fa-list"></i> All (<?= $total ?>)
                </a>
                <?php foreach ($allStatuses as $st):
                    $k = $st['status_name'];
                    $col = $st['color'] ?? '#9ca3af';
                    $bg = $st['bg_color'] ?? '#f3f4f6';
                    $icon = $st['icon'] ?? 'fa-circle-dot';
                    $active = $filterStatus === $k;
                    ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['status' => $k, 'page' => 1])) ?>" class="btn btn-sm"
                        style="border-color:<?= $col ?>;background:<?= $active ? $col : $bg ?>;color:<?= $active ? '#fff' : $col ?>;">
                        <i class="fas <?= htmlspecialchars($icon) ?>" style="color:<?= $active ? '#fff' : $col ?>;"></i>
                        <?= htmlspecialchars($k) ?>
                        <span
                            style="background:<?= $active ? 'rgba(255,255,255,.25)' : $col ?>33;color:<?= $active ? '#fff' : $col ?>;padding:.05rem .4rem;border-radius:99px;font-weight:700;">
                            <?= $tabCounts[$k] ?? 0 ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar mb-4 w-100">
                <form method="GET" class="row g-2 align-items-end w-100">
                    <?php if ($showAll && !$isBranchManager): ?><input type="hidden" name="show_all"
                            value="1"><?php endif; ?>
                    <?php if ($filterStatus): ?><input type="hidden" name="status"
                            value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>
                    <?php if ($filterCompany): ?><input type="hidden" name="company_id"
                            value="<?= $filterCompany ?>"><?php endif; ?>
                    <div class="col-md-3">
                        <label class="form-label-mis">Search</label>
                        <input type="text" name="search" class="form-control form-control-sm"
                            placeholder="Task #, title, company, staff..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">Date From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm"
                            value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">Date To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm"
                            value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                    </div>

                    <!-- Department filter: always show for BM, show in show_all for others -->
                    <?php if ($isBranchManager || $showAll): ?>
                        <div class="col-md-2">
                            <label class="form-label-mis">Department</label>
                            <select name="dept_id" class="form-select form-select-sm">
                                <option value="">All Depts</option>
                                <?php foreach ($allDepts as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $filterDept == $d['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($d['dept_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="col-md-2">
                        <label class="form-label-mis">Fiscal Year</label>
                        <select name="fy" class="form-select form-select-sm">
                            <option value="">All FY</option>
                            <?php foreach ($fiscalYears as $fy): ?>
                                <option value="<?= $fy ?>" <?= $filterFY === $fy ? 'selected' : '' ?>><?= $fy ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-gold btn-sm w-100"><i
                                class="fas fa-filter me-1"></i>Filter</button>
                        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div>

            <!-- Edit hint -->
            <div
                style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.7rem 1rem;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.75rem;">
                <i class="fas fa-lightbulb" style="color:#f59e0b;margin-top:.15rem;flex-shrink:0;"></i>
                <div style="font-size:.8rem;color:#92400e;">
                    <strong>Where to edit task details:</strong><br>
                    <span style="display:inline-flex;align-items:center;gap:.3rem;margin-top:.3rem;">
                        <span
                            style="background:#0a0f1e;color:#c9a84c;padding:.15rem .5rem;border-radius:5px;font-size:.72rem;font-weight:600;"><i
                                class="fas fa-eye me-1"></i>View Page</span>
                        — fill dept-specific fields and update work status
                    </span><br>
                    <span style="display:inline-flex;align-items:center;gap:.3rem;margin-top:.3rem;">
                        <span
                            style="background:#f59e0b;color:#fff;padding:.15rem .5rem;border-radius:5px;font-size:.72rem;font-weight:600;"><i
                                class="fas fa-pen me-1"></i>Edit Page</span>
                        — change title, priority, due date, assigned staff
                    </span>
                </div>
            </div>

            <!-- Task Table -->
            <div class="card-mis">
                <div class="card-mis-header">
                    <h5><i class="fas fa-list-check text-warning me-2"></i>Tasks</h5>
                    <div class="d-flex gap-2">
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=tasks&<?= http_build_query(array_merge($_GET, ['branch_id' => $adminBranchId, 'dept_id' => $adminDeptId])) ?>"
                            class="btn btn-sm"
                            style="background:#16a34a;color:white;border-radius:8px;padding:.35rem .8rem;">
                            <i class="fas fa-file-excel me-1"></i>Excel
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=tasks&<?= http_build_query($_GET) ?>"
                            class="btn btn-sm"
                            style="background:#dc2626;color:white;border-radius:8px;padding:.35rem .8rem;">
                            <i class="fas fa-file-pdf me-1"></i>PDF
                        </a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table-mis w-100">
                        <thead>
                            <tr>
                                <th>Task #</th>
                                <th>Title / Company</th>
                                <th>Department</th>
                                <th>Assigned To</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Due Date</th>
                                <th>FY</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tasks)): ?>
                                <tr>
                                    <td colspan="9" class="empty-state"><i class="fas fa-list-check"></i> No tasks found
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($tasks as $t):
                                $sClass = 'status-' . strtolower(str_replace(' ', '-', $t['status'] ?? ''));
                                $overdue = $t['due_date'] && strtotime($t['due_date']) < time() && !$t['counts_as_done'];
                                $hasTransfer = !empty($t['transfer_from_dept_id']) || !empty($t['transfer_to_dept_id']);
                                $transferredIn = !empty($t['transfer_to_dept_id']) && (int) $t['transfer_to_dept_id'] === $adminDeptId && (int) $t['transfer_from_dept_id'] !== $adminDeptId;
                                $transferredOut = !empty($t['transfer_from_dept_id']) && (int) $t['transfer_from_dept_id'] === $adminDeptId && (int) $t['department_id'] !== $adminDeptId;
                                ?>
                                <tr <?= $overdue ? 'style="background:#fef2f2;"' : '' ?>>
                                    <td style="min-width:100px;">
                                        <span class="task-number"
                                            style="font-size:.75rem;"><?= htmlspecialchars($t['task_number']) ?></span>
                                        <?php if ($overdue): ?>
                                            <div style="font-size:.62rem;color:#ef4444;font-weight:700;margin-top:.1rem;">⚠
                                                OVERDUE</div><?php endif; ?>
                                        <?php if ($transferredIn): ?>
                                            <div style="font-size:.62rem;font-weight:600;color:#8b5cf6;margin-top:.15rem;"><i
                                                    class="fas fa-arrow-down me-1"></i>IN</div>
                                            <div style="font-size:.6rem;color:#9ca3af;">From: <span
                                                    style="color:<?= htmlspecialchars($t['transfer_from_dept_color'] ?? '#8b5cf6') ?>;font-weight:600;"><?= htmlspecialchars($t['transfer_from_dept_name'] ?? '—') ?></span>
                                            </div>
                                        <?php elseif ($transferredOut): ?>
                                            <div style="font-size:.62rem;font-weight:600;color:#f59e0b;margin-top:.15rem;"><i
                                                    class="fas fa-arrow-up me-1"></i>OUT</div>
                                            <div style="font-size:.6rem;color:#9ca3af;">To: <span
                                                    style="color:<?= htmlspecialchars($t['transfer_to_dept_color'] ?? '#f59e0b') ?>;font-weight:600;"><?= htmlspecialchars($t['transfer_to_dept_name'] ?? '—') ?></span>
                                            </div>
                                        <?php elseif ($hasTransfer): ?>
                                            <div style="font-size:.62rem;color:#6b7280;margin-top:.15rem;"><i
                                                    class="fas fa-exchange-alt me-1"></i>Transferred</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div
                                            style="font-weight:500;font-size:.87rem;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                            <?= htmlspecialchars($t['title']) ?>
                                        </div>
                                        <?php if ($t['company_name']): ?>
                                            <div style="font-size:.73rem;color:#9ca3af;">
                                                <?= htmlspecialchars($t['company_name']) ?>
                                            </div><?php endif; ?>
                                    </td>
                                    <td>
                                        <span
                                            style="font-size:.73rem;background:<?= htmlspecialchars($t['dept_color'] ?? '#ccc') ?>22;color:<?= htmlspecialchars($t['dept_color'] ?? '#666') ?>;padding:.2rem .5rem;border-radius:99px;font-weight:500;">
                                            <?= htmlspecialchars($t['dept_name'] ?? '—') ?>
                                        </span>
                                        <?php if (!empty($t['branch_name'])): ?>
                                            <div style="font-size:.68rem;color:#9ca3af;margin-top:.1rem;">
                                                <?= htmlspecialchars($t['branch_name']) ?>
                                            </div><?php endif; ?>
                                    </td>
                                    <td style="font-size:.85rem;"><?= htmlspecialchars($t['assigned_to_name'] ?? '—') ?>
                                    </td>
                                    <td><span
                                            class="status-badge <?= $sClass ?>"><?= htmlspecialchars($t['status'] ?? '—') ?></span>
                                    </td>
                                    <td>
                                        <?php $pColors = ['urgent' => '#ef4444', 'high' => '#f59e0b', 'medium' => '#3b82f6', 'low' => '#9ca3af'];
                                        $pColor = $pColors[$t['priority']] ?? '#9ca3af'; ?>
                                        <span
                                            style="font-size:.78rem;font-weight:600;color:<?= $pColor ?>;"><?= ucfirst($t['priority']) ?></span>
                                    </td>
                                    <td
                                        style="font-size:.8rem;<?= $overdue ? 'color:#ef4444;font-weight:600;' : 'color:#6b7280;' ?>">
                                        <?= $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—' ?>
                                    </td>
                                    <td style="font-size:.78rem;color:#9ca3af;">
                                        <?= htmlspecialchars($t['fiscal_year'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <a href="view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary"
                                                title="View"><i class="fas fa-eye"></i></a>
                                            <a href="edit.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-warning"
                                                title="Edit"><i class="fas fa-pen"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center px-4 py-3 border-top">
                        <small class="text-muted">Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of
                            <?= $total ?></small>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item"><a class="page-link"
                                            href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹</a>
                                    </li>
                                <?php endif; ?>
                                <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                                    <li class="page-item <?= $p == $page ? 'active' : '' ?>"><a class="page-link"
                                            href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($page < $pages): ?>
                                    <li class="page-item"><a class="page-link"
                                            href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">›</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <?php include '../../includes/footer.php'; ?>