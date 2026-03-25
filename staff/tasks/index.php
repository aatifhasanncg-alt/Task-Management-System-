<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();

if ($_SESSION['role'] !== 'staff') {
    header('Location: ' . APP_URL . '/' . $_SESSION['role'] . '/tasks/index.php');
    exit;
}

$db   = getDB();
$user = currentUser();
$pageTitle = 'My Tasks';

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus   = $_GET['status']   ?? '';
$filterPriority = $_GET['priority'] ?? '';
$filterDept     = (int)($_GET['dept_id'] ?? 0);
$search         = trim($_GET['search'] ?? '');
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 25;
$offset         = ($page - 1) * $perPage;

// ── SCOPE: all tasks this staff is involved with ──────────────────────────────
// (a) currently assigned to them
// (b) ever transferred to them via task_workflow
// We build a subquery of task IDs so all filters apply cleanly on top
$scopeSub = "
    SELECT DISTINCT t.id AS task_id
    FROM tasks t
    WHERE t.is_active = 1
      AND (
          -- currently assigned
          t.assigned_to = {$user['id']}
          OR
          -- ever transferred to this user
          EXISTS (
              SELECT 1 FROM task_workflow tw
              WHERE tw.task_id    = t.id
                AND tw.to_user_id = {$user['id']}
                AND tw.action IN ('transferred_staff','transferred_dept')
          )
      )
";

// ── Additional filter WHERE (applied on top of scope) ─────────────────────────
$filterWhere  = ['t.is_active = 1', "t.id IN ({$scopeSub})"];
$filterParams = [];

if ($filterStatus) {
    $filterWhere[]  = 'ts.status_name = ?';
    $filterParams[] = $filterStatus;
}
if ($filterPriority) {
    $filterWhere[]  = 't.priority = ?';
    $filterParams[] = $filterPriority;
}
if ($filterDept) {
    $filterWhere[]  = 't.department_id = ?';
    $filterParams[] = $filterDept;
}
if (isset($_GET['overdue'])) {
    $filterWhere[] = "t.due_date < CURDATE() AND ts.status_name != 'Done'";
}
if ($search) {
    $filterWhere[]  = '(t.title LIKE ? OR t.task_number LIKE ? OR c.company_name LIKE ?)';
    $filterParams   = array_merge($filterParams, ["%$search%", "%$search%", "%$search%"]);
}

$ws = implode(' AND ', $filterWhere);

// ── Total count ───────────────────────────────────────────────────────────────
$cntSt = $db->prepare("
    SELECT COUNT(DISTINCT t.id)
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN companies   c  ON c.id  = t.company_id
    WHERE {$ws}
");
$cntSt->execute($filterParams);
$total = (int)$cntSt->fetchColumn();
$pages = (int)ceil($total / $perPage);

// ── Task list ─────────────────────────────────────────────────────────────────
$taskStmt = $db->prepare("
    SELECT t.*,
           ts.status_name          AS status,
           d.dept_name, d.color    AS dept_color,
           c.company_name,
           b.branch_name,
           -- Was this task transferred to this user?
           CASE WHEN t.assigned_to = {$user['id']} THEN 0 ELSE 1 END AS is_transferred
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN departments  d ON d.id  = t.department_id
    LEFT JOIN companies    c ON c.id  = t.company_id
    LEFT JOIN branches     b ON b.id  = t.branch_id
    WHERE {$ws}
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
        t.due_date ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$taskStmt->execute($filterParams);
$taskList = $taskStmt->fetchAll();

// ── Status counts (over full scope, no status filter) ─────────────────────────
$allStatuses = $db->query("
    SELECT id, status_name FROM task_status
    WHERE status_name != 'Corporate Team'
    ORDER BY id
")->fetchAll();

$statCounts = [];
foreach ($allStatuses as $st) {
    $sc = $db->prepare("
        SELECT COUNT(DISTINCT t.id)
        FROM tasks t
        JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active = 1
          AND ts.status_name = ?
          AND t.id IN ({$scopeSub})
    ");
    $sc->execute([$st['status_name']]);
    $statCounts[$st['status_name']] = (int)$sc->fetchColumn();
}

// ── Priority counts ───────────────────────────────────────────────────────────
$priorityCounts = [];
foreach (['urgent','high','medium','low'] as $p) {
    $pc = $db->prepare("
        SELECT COUNT(DISTINCT t.id) FROM tasks t
        WHERE t.is_active = 1 AND t.priority = ?
          AND t.id IN ({$scopeSub})
    ");
    $pc->execute([$p]);
    $priorityCounts[$p] = (int)$pc->fetchColumn();
}

// ── Overdue count ─────────────────────────────────────────────────────────────
$ovSt = $db->prepare("
    SELECT COUNT(DISTINCT t.id)
    FROM tasks t
    JOIN task_status ts ON ts.id = t.status_id
    WHERE t.is_active = 1
      AND t.due_date < CURDATE()
      AND ts.status_name != 'Done'
      AND t.id IN ({$scopeSub})
");
$ovSt->execute([]);
$overdueCount = (int)$ovSt->fetchColumn();

// ── Transferred count (informational) ────────────────────────────────────────
$xferSt = $db->prepare("
    SELECT COUNT(DISTINCT t.id)
    FROM tasks t
    WHERE t.is_active = 1
      AND t.assigned_to != {$user['id']}
      AND EXISTS (
          SELECT 1 FROM task_workflow tw
          WHERE tw.task_id    = t.id
            AND tw.to_user_id = {$user['id']}
            AND tw.action IN ('transferred_staff','transferred_dept')
      )
");
$xferSt->execute([]);
$transferredCount = (int)$xferSt->fetchColumn();

// ── Departments for filter dropdown ──────────────────────────────────────────
$depts = $db->prepare("
    SELECT DISTINCT d.id, d.dept_name
    FROM departments d
    JOIN tasks t ON t.department_id = d.id
    WHERE t.is_active = 1
      AND t.id IN ({$scopeSub})
    ORDER BY d.dept_name
");
$depts->execute([]);
$depts = $depts->fetchAll();

$pColors = [
    'urgent' => '#ef4444',
    'high'   => '#f59e0b',
    'medium' => '#3b82f6',
    'low'    => '#9ca3af',
];

include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php include '../../includes/sidebar_staff.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<div class="page-hero">
    <div class="page-hero-badge"><i class="fas fa-list-check"></i> My Tasks</div>
    <h4>All My Tasks</h4>
    <p>
        <?= number_format($total) ?> task<?= $total !== 1 ? 's' : '' ?>
        <?php if ($transferredCount > 0): ?>
        <span style="font-size:.78rem;color:#3b82f6;margin-left:.5rem;
                     background:#eff6ff;padding:.15rem .55rem;border-radius:99px;">
            <i class="fas fa-exchange-alt me-1"></i><?= $transferredCount ?> transferred to you
        </span>
        <?php endif; ?>
    </p>
</div>

<?= flashHtml() ?>

<!-- Status pills -->
<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <a href="index.php"
       class="btn btn-sm <?= !$filterStatus && !isset($_GET['overdue']) ? 'btn-navy' : 'btn-outline-secondary' ?>"
       style="border-radius:50px;">
        All (<?= array_sum($statCounts) ?>)
    </a>

    <?php foreach ($allStatuses as $st):
        $k   = $st['status_name'];
        $cnt = $statCounts[$k] ?? 0;
        $col = defined('TASK_STATUSES') && isset(TASK_STATUSES[$k]) ? TASK_STATUSES[$k]['color'] : '#6b7280';
    ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['status' => $k, 'page' => 1])) ?>"
       class="btn btn-sm"
       style="border-radius:50px;
              border:1px solid <?= $col ?>;
              color:<?= $filterStatus === $k ? '#fff' : $col ?>;
              background:<?= $filterStatus === $k ? $col : 'transparent' ?>;">
        <?= htmlspecialchars($k) ?>
        <?php if ($cnt): ?>
        <span style="margin-left:.3rem;background:rgba(255,255,255,.25);border-radius:50px;padding:0 .4rem;">
            <?= $cnt ?>
        </span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <?php if ($overdueCount > 0): ?>
    <a href="?overdue=1"
       class="btn btn-sm"
       style="border-radius:50px;border:1px solid #ef4444;
              background:<?= isset($_GET['overdue']) ? '#ef4444' : 'transparent' ?>;
              color:<?= isset($_GET['overdue']) ? '#fff' : '#ef4444' ?>;">
        ⚠ Overdue (<?= $overdueCount ?>)
    </a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="filter-bar mb-3 w-100">
    <form method="GET" class="row g-2 align-items-end w-100">
        <?php if ($filterStatus): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
        <?php endif; ?>

        <div class="col-md-4">
            <label class="form-label-mis">Search</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="Task #, title, company..."
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label-mis">Priority</label>
            <select name="priority" class="form-select form-select-sm">
                <option value="">All Priorities</option>
                <?php foreach (['urgent','high','medium','low'] as $p): ?>
                <option value="<?= $p ?>" <?= $filterPriority === $p ? 'selected' : '' ?>>
                    <?= ucfirst($p) ?> (<?= $priorityCounts[$p] ?? 0 ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (!empty($depts)): ?>
        <div class="col-md-2">
            <label class="form-label-mis">Department</label>
            <select name="dept_id" class="form-select form-select-sm">
                <option value="">All Depts</option>
                <?php foreach ($depts as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $filterDept == $d['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['dept_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-auto d-flex gap-1">
            <button type="submit" class="btn btn-gold btn-sm">
                <i class="fas fa-filter me-1"></i>Filter
            </button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm" title="Reset">
                <i class="fas fa-times"></i>
            </a>
        </div>
    </form>
</div>

<!-- Active filter chips -->
<?php if ($filterStatus || $filterPriority || $filterDept || $search || isset($_GET['overdue'])): ?>
<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <span style="font-size:.75rem;color:#9ca3af;">Active filters:</span>
    <?php if ($filterStatus): ?>
    <span style="background:#f3f4f6;border-radius:99px;padding:.2rem .6rem;font-size:.75rem;">
        Status: <?= htmlspecialchars($filterStatus) ?>
        <a href="?<?= http_build_query(array_diff_key($_GET, ['status'=>''])) ?>" style="color:#ef4444;margin-left:.3rem;">×</a>
    </span>
    <?php endif; ?>
    <?php if ($filterPriority): ?>
    <span style="background:#f3f4f6;border-radius:99px;padding:.2rem .6rem;font-size:.75rem;">
        Priority: <?= ucfirst($filterPriority) ?>
        <a href="?<?= http_build_query(array_diff_key($_GET, ['priority'=>''])) ?>" style="color:#ef4444;margin-left:.3rem;">×</a>
    </span>
    <?php endif; ?>
    <?php if ($filterDept): ?>
    <?php $dN = array_values(array_filter($depts, fn($d) => $d['id'] == $filterDept))[0] ?? null; ?>
    <span style="background:#f3f4f6;border-radius:99px;padding:.2rem .6rem;font-size:.75rem;">
        Dept: <?= htmlspecialchars($dN['dept_name'] ?? '') ?>
        <a href="?<?= http_build_query(array_diff_key($_GET, ['dept_id'=>''])) ?>" style="color:#ef4444;margin-left:.3rem;">×</a>
    </span>
    <?php endif; ?>
    <?php if ($search): ?>
    <span style="background:#f3f4f6;border-radius:99px;padding:.2rem .6rem;font-size:.75rem;">
        "<?= htmlspecialchars($search) ?>"
        <a href="?<?= http_build_query(array_diff_key($_GET, ['search'=>''])) ?>" style="color:#ef4444;margin-left:.3rem;">×</a>
    </span>
    <?php endif; ?>
    <?php if (isset($_GET['overdue'])): ?>
    <span style="background:#fef2f2;color:#ef4444;border-radius:99px;padding:.2rem .6rem;font-size:.75rem;">
        ⚠ Overdue only
        <a href="?<?= http_build_query(array_diff_key($_GET, ['overdue'=>''])) ?>" style="color:#ef4444;margin-left:.3rem;">×</a>
    </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Task table -->
<div class="card-mis">
    <div class="card-mis-header">
        <h5><i class="fas fa-list-check text-warning me-2"></i>Tasks</h5>
        <small class="text-muted"><?= $total ?> results</small>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Due Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($taskList)): ?>
            <tr>
                <td colspan="6" class="empty-state">
                    <i class="fas fa-check-circle" style="color:#10b981;"></i> No tasks found
                </td>
            </tr>
            <?php endif; ?>

            <?php foreach ($taskList as $t):
                $today     = date('Y-m-d');
                $isOverdue = $t['due_date'] && $t['due_date'] < $today && $t['status'] !== 'Done';
                $sClass    = 'status-' . strtolower(str_replace(' ', '-', $t['status']));
                $pColor    = $pColors[$t['priority']] ?? '#9ca3af';
                $isXfer    = (bool)$t['is_transferred'];
            ?>
            <tr <?= $isOverdue ? 'style="background:#fef2f2;"' : '' ?>>
                <td>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <span class="task-number" style="font-size:.72rem;">
                                <?= htmlspecialchars($t['task_number']) ?>
                            </span>
                            <?php if ($isOverdue): ?>
                            <span style="background:#fef2f2;color:#ef4444;font-size:.62rem;
                                         padding:.1rem .4rem;border-radius:4px;font-weight:700;">
                                OVERDUE
                            </span>
                            <?php endif; ?>
                            <?php if ($isXfer): ?>
                            <span style="background:#eff6ff;color:#3b82f6;font-size:.62rem;
                                         padding:.1rem .4rem;border-radius:4px;font-weight:600;">
                                <i class="fas fa-exchange-alt me-1"></i>Transferred
                            </span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:.87rem;font-weight:500;max-width:220px;
                                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?= htmlspecialchars($t['title']) ?>
                        </div>
                        <?php if ($t['company_name']): ?>
                        <div style="font-size:.72rem;color:#9ca3af;">
                            <i class="fas fa-building me-1"></i><?= htmlspecialchars($t['company_name']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <span style="font-size:.75rem;
                                 background:<?= htmlspecialchars($t['dept_color']??'#ccc') ?>22;
                                 color:<?= htmlspecialchars($t['dept_color']??'#666') ?>;
                                 border:1px solid <?= htmlspecialchars($t['dept_color']??'#ccc') ?>44;
                                 padding:.2rem .55rem;border-radius:99px;">
                        <?= htmlspecialchars($t['dept_name'] ?? '—') ?>
                    </span>
                </td>
                <td>
                    <span class="status-badge <?= $sClass ?>">
                        <?= htmlspecialchars($t['status']) ?>
                    </span>
                </td>
                <td>
                    <span style="font-size:.78rem;font-weight:600;color:<?= $pColor ?>;">
                        <?= ucfirst($t['priority']) ?>
                    </span>
                </td>
                <td style="font-size:.82rem;<?= $isOverdue ? 'color:#ef4444;font-weight:600;' : 'color:#6b7280;' ?>">
                    <?= $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—' ?>
                </td>
                <td>
                    <a href="view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-gold">
                        <i class="fas fa-eye me-1"></i>View
                    </a>
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
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>">‹</a>
                </li>
                <?php endif; ?>
                <?php for ($p = max(1,$page-2); $p <= min($pages,$page+2); $p++): ?>
                <li class="page-item <?= $p==$page?'active':'' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($page < $pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>">›</a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

</div><!-- padding -->
</div><!-- main-content -->
</div><!-- app-wrapper -->
<?php include '../../includes/footer.php'; ?>