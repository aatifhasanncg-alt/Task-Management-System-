<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAdmin();

$db = getDB();
$user = currentUser();

$adminStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$adminStmt->execute([$user['id']]);
$adminUser = $adminStmt->fetch();

$pageTitle = 'All Tasks';

$adminBranchId = (int) ($adminUser['branch_id'] ?? 0);
$adminDeptId = (int) ($adminUser['department_id'] ?? 0);

// Filters
$filterStatus = $_GET['status'] ?? '';
$filterStaff = (int) ($_GET['staff_id'] ?? 0);
$filterDept = (int) ($_GET['dept_id'] ?? 0);
$filterFY = trim($_GET['fy'] ?? '');
$search = trim($_GET['search'] ?? '');
$showAll = isset($_GET['show_all']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// ─────────────────────────────────────────────────────────────────────────────
// SCOPE BUILD
//
// MY DEPT (default):
//   All tasks where branch_id + department_id match admin's own
//   — this covers original tasks AND tasks transferred INTO this dept
//   (because transfers update t.branch_id + t.department_id to the new dept)
//
// SHOW ALL:
//   Own dept tasks  (same as above)
//   + tasks TRANSFERRED OUT from this dept (recorded in task_workflow)
//   + tasks admin created that may now live elsewhere
//   + tasks directly assigned to the admin themselves
// ─────────────────────────────────────────────────────────────────────────────
$where = ['t.is_active = 1'];
$params = [];

if (!isExecutive()) {
    if ($showAll) {
        $where[] = '(
            -- Own dept + branch (includes transferred-in tasks)
            (t.branch_id = ? AND t.department_id = ?)
            OR
            -- Transferred OUT from this dept (workflow recorded from_dept_id = adminDept)
            EXISTS (
                SELECT 1 FROM task_workflow tw
                WHERE tw.task_id     = t.id
                  AND tw.action      = \'transferred_dept\'
                  AND tw.from_dept_id = ?
            )
            OR
            -- Transferred TO this dept from somewhere else (workflow to_dept_id = adminDept)
            EXISTS (
                SELECT 1 FROM task_workflow tw
                WHERE tw.task_id   = t.id
                  AND tw.action    = \'transferred_dept\'
                  AND tw.to_dept_id = ?
            )
            OR
            -- Created by admin (their own tasks wherever they live now)
            t.created_by = ?
            OR
            -- Directly assigned to admin
            t.assigned_to = ?
        )';
        $params[] = $adminBranchId;
        $params[] = $adminDeptId;
        $params[] = $adminDeptId;   // from_dept_id = adminDept (transferred out)
        $params[] = $adminDeptId;   // to_dept_id   = adminDept (transferred in)
        $params[] = $user['id'];    // created_by
        $params[] = $user['id'];    // assigned_to
    } else {
        // MY DEPT: tasks currently in admin's branch + dept
        // (covers both originally-created-here AND transferred-in)
        $where[] = '(
            t.branch_id     = ?
            AND t.department_id = ?
        )';
        $params[] = $adminBranchId;
        $params[] = $adminDeptId;
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
if ($filterDept) {
    $where[] = 't.department_id = ?';
    $params[] = $filterDept;
}
if ($filterFY) {
    $where[] = 't.fiscal_year = ?';
    $params[] = $filterFY;
}
if ($search) {
    $where[] = '(t.title LIKE ? OR t.task_number LIKE ? OR c.company_name LIKE ? OR at.full_name LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}

$whereStr = implode(' AND ', $where);

// ── Total count ───────────────────────────────────────────────────────────────
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

// ── Task list — also fetch transfer info from workflow ────────────────────────
$taskSt = $db->prepare("
    SELECT t.*,
           d.dept_name, d.dept_code, d.color  AS dept_color,
           b.branch_name,
           c.company_name,
           ts.status_name                      AS status,
           cb.full_name                        AS created_by_name,
           at.full_name                        AS assigned_to_name,
           -- Where was this task transferred FROM (first transfer's from_dept)
           wf_from.from_dept_id                AS transfer_from_dept_id,
           from_d.dept_name                    AS transfer_from_dept_name,
           from_d.color                        AS transfer_from_dept_color,
           -- Where was this task transferred TO (last transfer's to_dept)
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
    -- First transfer (where it came FROM originally)
    LEFT JOIN (
        SELECT task_id, from_dept_id
        FROM task_workflow
        WHERE action = 'transferred_dept'
          AND id = (
              SELECT MIN(id) FROM task_workflow tw2
              WHERE tw2.task_id = task_workflow.task_id
                AND tw2.action  = 'transferred_dept'
          )
    ) wf_from ON wf_from.task_id = t.id
    LEFT JOIN departments from_d ON from_d.id = wf_from.from_dept_id
    -- Latest transfer (where it went TO most recently)
    LEFT JOIN (
        SELECT task_id, to_dept_id
        FROM task_workflow
        WHERE action = 'transferred_dept'
          AND id = (
              SELECT MAX(id) FROM task_workflow tw3
              WHERE tw3.task_id = task_workflow.task_id
                AND tw3.action  = 'transferred_dept'
          )
    ) wf_to ON wf_to.task_id = t.id
    LEFT JOIN departments to_d ON to_d.id = wf_to.to_dept_id
    WHERE {$whereStr}
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
        CASE t.priority
            WHEN 'urgent' THEN 1
            WHEN 'high'   THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low'    THEN 4
        END,
        t.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$taskSt->execute($params);
$tasks = $taskSt->fetchAll();

// ── Staff dropdown — own dept/branch ─────────────────────────────────────────
$staffQuery = $db->prepare("
    SELECT u.id, u.full_name FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.role_name    = 'staff'
      AND u.is_active    = 1
      AND u.branch_id     = ?
      AND u.department_id = ?
    ORDER BY u.full_name
");
$staffQuery->execute([$adminBranchId, $adminDeptId]);
$allStaff = $staffQuery->fetchAll();

// All departments (for show_all dept filter)
$allDepts = $db->query("SELECT id, dept_name FROM departments WHERE is_active=1 ORDER BY dept_name")->fetchAll();

// ── Status tab counts ─────────────────────────────────────────────────────────
$tabCounts = [];
$allStatuses = $db->query("SELECT id, status_name, color, bg_color FROM task_status ORDER BY id")->fetchAll();

$tabCounts = [];
foreach ($allStatuses as $st) {
    $k = $st['status_name'];
    $tabParams = $params;
    $tabWhere = $whereStr;
    if ($filterStatus) {
        $tabWhere = str_replace('ts.status_name = ?', '1=1', $tabWhere);
        $idx = array_search($filterStatus, $tabParams);
        if ($idx !== false)
            unset($tabParams[$idx]);
        $tabParams = array_values($tabParams);
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
   $tabCounts[$k] = (int) $cntSt->fetchColumn();
}
$fiscalYears = $db->query("
    SELECT fy_code 
    FROM fiscal_years WHERE is_active=1
    ORDER BY fy_code DESC
")->fetchAll(PDO::FETCH_COLUMN);
include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <div class="page-hero">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-list-check"></i> Task List</div>
                        <h4>All Tasks</h4>
                        <p><?= number_format($total) ?> task<?= $total !== 1 ? 's' : '' ?> found</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="assign.php" class="btn btn-gold">
                            <i class="fas fa-plus me-1"></i>Assign Task
                        </a>
                    </div>
                </div>
            </div>

            <!-- My Dept / Show All toggle -->
            <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
                <div class="d-flex gap-2">
                    <!-- My Dept: tasks currently sitting in admin's branch+dept -->
                    <a href="?<?= http_build_query(array_diff_key($_GET, ['show_all' => '', 'dept_id' => '', 'page' => ''])) ?>"
                        class="btn btn-sm <?= !$showAll ? 'btn-navy' : 'btn-outline-secondary' ?>">
                        <i class="fas fa-building me-1"></i>My Dept Tasks
                    </a>
                    <!-- Show All: own + transferred in + transferred out + created by admin -->
                    <a href="?<?= http_build_query(array_merge($_GET, ['show_all' => '1', 'page' => 1])) ?>"
                        class="btn btn-sm <?= $showAll ? 'btn-navy' : 'btn-outline-secondary' ?>">
                        <i class="fas fa-globe me-1"></i>Show All Related
                    </a>
                </div>
                <?php if ($showAll): ?>
                    <span
                        style="font-size:.73rem;color:#f59e0b;background:#fffbeb;padding:.2rem .7rem;border-radius:99px;border:1px solid #f59e0b33;">
                        <i class="fas fa-info-circle me-1"></i>
                        Own dept &amp; all transferred tasks (in/out)
                    </span>
                <?php else: ?>
                    <span
                        style="font-size:.73rem;color:#3b82f6;background:#eff6ff;padding:.2rem .7rem;border-radius:99px;border:1px solid #3b82f633;">
                        <i class="fas fa-building me-1"></i>
                        Tasks currently in your department
                    </span>
                <?php endif; ?>
            </div>

            <!-- Status Tabs -->
            <div class="d-flex gap-2 flex-wrap mb-3">
                <a href="?<?= http_build_query(array_merge(array_diff_key($_GET, ['status' => '', 'page' => '']), $showAll ? ['show_all' => '1'] : [])) ?>"
                    class="btn btn-sm <?= !$filterStatus ? 'btn-navy' : 'btn-outline-secondary' ?>">
                    All (<?= $total ?>)
                </a>
               <?php foreach ($allStatuses as $st):
                    $k = $st['status_name'];
                    $col = $st['color'] ?? '#9ca3af';
                ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['status' => $k, 'page' => 1])) ?>" class="btn btn-sm"
                        style="border:1px solid <?= $col ?>;
                               color:<?= $filterStatus === $k ? '#fff' : $col ?>;
                               background:<?= $filterStatus === $k ? $col : 'transparent' ?>;">
                        <?= htmlspecialchars($k) ?> (<?= $tabCounts[$k] ?? 0 ?>)
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar mb-4 w-100">
                <form method="GET" class="row g-2 align-items-end w-100">
                    <?php if ($showAll): ?>
                        <input type="hidden" name="show_all" value="1">
                    <?php endif; ?>
                    <?php if ($filterStatus): ?>
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
                    <?php endif; ?>

                    <div class="col-md-3">
                        <label class="form-label-mis">Search</label>
                        <input type="text" name="search" class="form-control form-control-sm"
                            placeholder="Task #, title, company, staff..." value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label-mis">Staff</label>
                        <select name="staff_id" class="form-select form-select-sm">
                            <option value="">All Staff</option>
                            <?php foreach ($allStaff as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $filterStaff == $s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($showAll): ?>
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
                        <button type="submit" class="btn btn-gold btn-sm w-100">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>
            <!-- Where to edit hint -->
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;
            padding:.7rem 1rem;margin-bottom:1.25rem;
            display:flex;align-items:flex-start;gap:.75rem;">
                <i class="fas fa-lightbulb" style="color:#f59e0b;margin-top:.15rem;flex-shrink:0;"></i>
                <div style="font-size:.8rem;color:#92400e;">
                    <strong>Where to edit task details:</strong><br>
                    <span style="display:inline-flex;align-items:center;gap:.3rem;margin-top:.3rem;">
                        <span
                            style="background:#0a0f1e;color:#c9a84c;padding:.15rem .5rem;border-radius:5px;font-size:.72rem;font-weight:600;">
                            <i class="fas fa-eye me-1"></i>View Page
                        </span>
                        — fill dept-specific fields (Tax, Banking, Finance, etc.) and update work status
                    </span><br>
                    <span style="display:inline-flex;align-items:center;gap:.3rem;margin-top:.3rem;">
                        <span
                            style="background:#f59e0b;color:#fff;padding:.15rem .5rem;border-radius:5px;font-size:.72rem;font-weight:600;">
                            <i class="fas fa-pen me-1"></i>Edit Page
                        </span>
                        — change title, priority, due date, assigned staff, company, remarks
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
                                    <td colspan="9" class="empty-state">
                                        <i class="fas fa-list-check"></i> No tasks found
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($tasks as $t):
                                $sClass = 'status-' . strtolower(str_replace(' ', '-', $t['status'] ?? ''));
                                $overdue = $t['due_date']
                                    && strtotime($t['due_date']) < time()
                                    && $t['status'] !== 'Done';

                                // Was this task ever transferred?
                                $hasTransfer = !empty($t['transfer_from_dept_id']) || !empty($t['transfer_to_dept_id']);

                                // Transferred IN to admin's dept (from somewhere else)
                                $transferredIn = !empty($t['transfer_to_dept_id'])
                                    && (int) $t['transfer_to_dept_id'] === $adminDeptId
                                    && (int) $t['transfer_from_dept_id'] !== $adminDeptId;

                                // Transferred OUT from admin's dept (now lives elsewhere)
                                $transferredOut = !empty($t['transfer_from_dept_id'])
                                    && (int) $t['transfer_from_dept_id'] === $adminDeptId
                                    && (int) $t['department_id'] !== $adminDeptId;
                                ?>
                                <tr <?= $overdue ? 'style="background:#fef2f2;"' : '' ?>>
                                    <td style="min-width:100px;">
                                        <span class="task-number" style="font-size:.75rem;">
                                            <?= htmlspecialchars($t['task_number']) ?>
                                        </span>
                                        <?php if ($overdue): ?>
                                            <div style="font-size:.62rem;color:#ef4444;font-weight:700;margin-top:.1rem;">
                                                ⚠ OVERDUE
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($transferredIn): ?>
                                            <!-- Task came INTO this dept from another dept -->
                                            <div style="font-size:.62rem;font-weight:600;color:#8b5cf6;margin-top:.15rem;">
                                                <i class="fas fa-arrow-down me-1"></i>IN
                                            </div>
                                            <div
                                                style="font-size:.6rem;color:#9ca3af;display:flex;align-items:center;gap:.2rem;">
                                                From:
                                                <span
                                                    style="color:<?= htmlspecialchars($t['transfer_from_dept_color'] ?? '#8b5cf6') ?>;font-weight:600;background:<?= htmlspecialchars($t['transfer_from_dept_color'] ?? '#8b5cf6') ?>18;padding:.05rem .3rem;border-radius:3px;">
                                                    <?= htmlspecialchars($t['transfer_from_dept_name'] ?? '—') ?>
                                                </span>
                                            </div>
                                        <?php elseif ($transferredOut): ?>
                                            <!-- Task went OUT from this dept to another dept -->
                                            <div style="font-size:.62rem;font-weight:600;color:#f59e0b;margin-top:.15rem;">
                                                <i class="fas fa-arrow-up me-1"></i>OUT
                                            </div>
                                            <div
                                                style="font-size:.6rem;color:#9ca3af;display:flex;align-items:center;gap:.2rem;">
                                                To:
                                                <span
                                                    style="color:<?= htmlspecialchars($t['transfer_to_dept_color'] ?? '#f59e0b') ?>;font-weight:600;background:<?= htmlspecialchars($t['transfer_to_dept_color'] ?? '#f59e0b') ?>18;padding:.05rem .3rem;border-radius:3px;">
                                                    <?= htmlspecialchars($t['transfer_to_dept_name'] ?? '—') ?>
                                                </span>
                                            </div>
                                        <?php elseif ($hasTransfer): ?>
                                            <!-- Has transfers but neither in nor out of this specific dept -->
                                            <div style="font-size:.62rem;color:#6b7280;margin-top:.15rem;">
                                                <i class="fas fa-exchange-alt me-1"></i>Transferred
                                            </div>
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
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span
                                            style="font-size:.73rem;background:<?= htmlspecialchars($t['dept_color'] ?? '#ccc') ?>22;color:<?= htmlspecialchars($t['dept_color'] ?? '#666') ?>;padding:.2rem .5rem;border-radius:99px;font-weight:500;">
                                            <?= htmlspecialchars($t['dept_name'] ?? '—') ?>
                                        </span>
                                        <?php if (!empty($t['branch_name'])): ?>
                                            <div style="font-size:.68rem;color:#9ca3af;margin-top:.1rem;">
                                                <?= htmlspecialchars($t['branch_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td style="font-size:.85rem;"><?= htmlspecialchars($t['assigned_to_name'] ?? '—') ?>
                                    </td>

                                    <td>
                                        <span class="status-badge <?= $sClass ?>">
                                            <?= htmlspecialchars($t['status'] ?? '—') ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php
                                        $pColors = ['urgent' => '#ef4444', 'high' => '#f59e0b', 'medium' => '#3b82f6', 'low' => '#9ca3af'];
                                        $pColor = $pColors[$t['priority']] ?? '#9ca3af';
                                        ?>
                                        <span style="font-size:.78rem;font-weight:600;color:<?= $pColor ?>;">
                                            <?= ucfirst($t['priority']) ?>
                                        </span>
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
                                                title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-warning"
                                                title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center px-4 py-3 border-top">
                        <small class="text-muted">
                            Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?>
                        </small>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link"
                                            href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹</a>
                                    </li>
                                <?php endif; ?>
                                <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                                    <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                                        <a class="page-link"
                                            href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($page < $pages): ?>
                                    <li class="page-item">
                                        <a class="page-link"
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