<?php
// manager/reports/staff_wise.php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireManager();

$db = getDB();
$user = currentUser();
$pageTitle = 'Staff Performance Report';

// ── Admin / BM detection ──────────────────────────────────────────────────────
$adminMeta = $db->prepare("
    SELECT d.dept_code, u.branch_id, u.department_id
    FROM users u LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.id = ?
");
$adminMeta->execute([$user['id']]);
$adminMeta = $adminMeta->fetch();
$isBranchManager = (($adminMeta['dept_code'] ?? '') === 'CORE');
$adminBranchId = (int) ($adminMeta['branch_id'] ?? 0);
$adminDeptId = (int) ($adminMeta['department_id'] ?? 0);

$udaDepts = $db->prepare("SELECT department_id FROM user_department_assignments WHERE user_id = ?");
$udaDepts->execute([$user['id']]);
$udaDeptIds = array_column($udaDepts->fetchAll(PDO::FETCH_ASSOC), 'department_id');
if (!in_array($adminDeptId, $udaDeptIds) && $adminDeptId) {
    $udaDeptIds[] = $adminDeptId;
}

$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');
$filterDept = (int) ($_GET['dept_id'] ?? 0);
$filterBranch = isExecutive() ? (int) ($_GET['branch_id'] ?? 0) : 0;

$selfId = (int) $user['id'];

// ── User scope (which staff rows appear) ──────────────────────────────────────
$userWhere = [];
$userParams = [];

$userWhere[] = "(d.dept_code IS NULL OR d.dept_code NOT IN ('CON'))";

if (isExecutive()) {
    if ($filterBranch) {
        $userWhere[] = 'u.branch_id = ?';
        $userParams[] = $filterBranch;
    }
    if ($filterDept) {
        $userWhere[] = "(u.department_id = ? OR EXISTS (
        SELECT 1 FROM user_department_assignments uda_f
        WHERE uda_f.user_id = u.id AND uda_f.department_id = ?
        AND uda_f.department_id NOT IN (SELECT id FROM departments WHERE dept_code='CON')
    ))";
        $userParams[] = $filterDept;
        $userParams[] = $filterDept;
    }
} elseif ($isBranchManager) {
    // BM: locked to their own branch, skip CORE dept users
    $userWhere[] = 'u.branch_id = ?';
    $userParams[] = $adminBranchId;
    $userWhere[] = "(d.dept_code IS NULL OR d.dept_code != 'CORE')";
    if ($filterDept) {
        $userWhere[] = "(u.department_id = ? OR EXISTS (
            SELECT 1 FROM user_department_assignments uda_bm
            WHERE uda_bm.user_id = u.id AND uda_bm.department_id = ?
        ))";
        $userParams[] = $filterDept;
        $userParams[] = $filterDept;
    }
} else {
    // Regular (non-CORE) admin: locked to OWN department only — UDA depts
    // are ignored entirely on this report. CORE is the only role with
    // broader scope (branch-wide, see isBranchManager branch above).
    $userWhere[] = 'u.department_id = ?';
    $userParams[] = $adminDeptId;
}
$uwStr = implode(' AND ', $userWhere);

// ── Statuses ──────────────────────────────────────────────────────────────────
$allStatuses = $db->query("
    SELECT status_name,
           COALESCE(color,    '#9ca3af')     AS color,
           COALESCE(bg_color, '#f3f4f6')     AS bg_color,
           COALESCE(icon,     'fa-circle-dot') AS icon
    FROM task_status ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

$sumCols = '';
foreach ($allStatuses as $st) {
    $safe = preg_replace('/[^a-z0-9_]/', '_', strtolower($st['status_name']));
    $escaped = addslashes($st['status_name']);
    $sumCols .= "SUM(CASE WHEN ts.status_name = '{$escaped}' THEN 1 ELSE 0 END) AS `status_{$safe}`,\n        ";
}

// ── Main query ────────────────────────────────────────────────────────────────
// KEY FIX: when filterDept is set, add t.department_id = ? directly in the JOIN
// so task counts are scoped to that department only, not all of the user's depts
$taskDeptJoin = $filterDept ? "AND t.department_id = ?" : "";

$staffStmt = $db->prepare("
    SELECT u.id, u.full_name, u.employee_id,
           u.department_id,
           b.branch_name, d.dept_name, d.color AS dept_color, r.role_name,
           COUNT(DISTINCT t.id)                                        AS total,
           SUM(CASE WHEN ts.status_name = 'Done' THEN 1 ELSE 0 END)  AS done,
           SUM(CASE WHEN t.due_date < CURDATE()
               AND ts.status_name != 'Done'     THEN 1 ELSE 0 END)   AS overdue,
           {$sumCols}
           MAX(t.created_at) AS last_task_date
    FROM users u
    LEFT JOIN roles       r  ON r.id  = u.role_id
    LEFT JOIN branches    b  ON b.id  = u.branch_id
    LEFT JOIN departments d  ON d.id  = u.department_id
    LEFT JOIN tasks t
        ON  t.assigned_to = u.id
        AND t.is_active   = 1
        AND t.created_at BETWEEN ? AND ?
        {$taskDeptJoin}
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE ({$uwStr} OR u.id = ?)
      AND r.role_name IN ('staff', 'admin')
      AND u.is_active = 1
    GROUP BY u.id, u.full_name, u.employee_id,
             b.branch_name, d.dept_name, d.color, r.role_name
    ORDER BY done DESC, total DESC
");

// Param order:
// 1. date range (JOIN)
// 2. filterDept for t.department_id (JOIN) — only if set
// 3. userParams (WHERE scope)
// 4. selfId (OR u.id = ?)
$joinParams = [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'];
if ($filterDept)
    $joinParams[] = $filterDept;

$staffStmt->execute(array_merge($joinParams, $userParams, [$selfId]));
$staffReport = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

// ── UDA extra depts (avoids N+1) ──────────────────────────────────────────────
$allStaffIds = array_column($staffReport, 'id');
$udaMap = [];
if (!empty($allStaffIds)) {
    $inPh = implode(',', array_fill(0, count($allStaffIds), '?'));
    $udaPre = $db->prepare("
        SELECT uda.user_id, d.dept_name, d.color
        FROM user_department_assignments uda
        JOIN departments d ON d.id  = uda.department_id
        JOIN users       u ON u.id  = uda.user_id
        WHERE uda.user_id IN ({$inPh})
          AND uda.department_id != u.department_id
          AND d.dept_code NOT IN ('CON', 'CORE')
          AND d.is_active = 1
        ORDER BY uda.user_id, d.dept_name
    ");
    $udaPre->execute($allStaffIds);
    foreach ($udaPre->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $udaMap[$row['user_id']][] = $row;
    }
}

// ── Summary stats ─────────────────────────────────────────────────────────────
$totalStaff = count($staffReport);
$totalTasks = array_sum(array_column($staffReport, 'total'));
$totalDone = array_sum(array_column($staffReport, 'done'));
$totalOverdue = array_sum(array_column($staffReport, 'overdue'));
$overallPct = $totalTasks > 0 ? round(($totalDone / $totalTasks) * 100) : 0;

$topPerformer = null;
$topPct = -1;
foreach ($staffReport as $s) {
    $pct = $s['total'] > 0 ? ($s['done'] / $s['total']) : 0;
    if ($pct > $topPct) {
        $topPerformer = $s;
        $topPct = $pct;
    }
}

$allDepts = $db->query("
    SELECT id, dept_name FROM departments
    WHERE is_active = 1 AND dept_code NOT IN ('CON','CORE')
    ORDER BY dept_name
")->fetchAll();
$allBranches = $db->query("
    SELECT id, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_name
")->fetchAll();

include '../../includes/header.php';
?>
<style>
    .role-badge {
        display: inline-block;
        padding: 4px 10px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 20px;
        text-transform: capitalize;
    }

    .role-admin {
        background: #dbeafe;
        color: #1d4ed8;
        border: 1px solid #93c5fd;
    }

    .role-executive {
        background: #e0e7ff;
        color: #3730a3;
    }

    .role-staff {
        background: #dcfce7;
        color: #166534;
    }

    .role-default {
        background: #f3f4f6;
        color: #374151;
    }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_manager.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <!-- Hero -->
            <div class="page-hero">
                <div class="d-flex justify-content-between flex-wrap gap-3 align-items-center">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-users"></i> Reports</div>
                        <h4>Staff Performance</h4>
                        <p>
                            <?= date('d M Y', strtotime($fromDate)) ?> — <?= date('d M Y', strtotime($toDate)) ?>
                            <?php if ($isBranchManager): ?>
                                <span style="font-size:.73rem;color:#c9a84c;margin-left:.5rem;">· Your branch only</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=staff_wise&from=<?= $fromDate ?>&to=<?= $toDate ?>&dept_id=<?= $filterDept ?>&branch_id=<?= $filterBranch ?>"
                            class="btn btn-success btn-sm"><i class="fas fa-file-excel me-1"></i>Excel</a>
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=staff_wise&from=<?= $fromDate ?>&to=<?= $toDate ?>&dept_id=<?= $filterDept ?>&branch_id=<?= $filterBranch ?>"
                            class="btn btn-danger btn-sm"><i class="fas fa-file-pdf me-1"></i>PDF</a>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="row g-3 mb-4">
                <?php foreach ([
                    ['fa-users', '#3b82f6', '#eff6ff', 'Active Staff', $totalStaff],
                    ['fa-list-check', '#8b5cf6', '#f5f3ff', 'Total Tasks', $totalTasks],
                    ['fa-circle-check', '#10b981', '#ecfdf5', 'Completed', $totalDone],
                    ['fa-triangle-exclamation', '#ef4444', '#fef2f2', 'Overdue', $totalOverdue],
                ] as [$icon, $color, $bg, $label, $value]): ?>
                    <div class="col-6 col-md-3">
                        <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;
                    padding:1.1rem 1.2rem;display:flex;align-items:center;gap:.9rem;">
                            <div style="width:42px;height:42px;border-radius:10px;background:<?= $bg ?>;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas <?= $icon ?>" style="color:<?= $color ?>;font-size:1rem;"></i>
                            </div>
                            <div>
                                <div style="font-size:1.45rem;font-weight:800;color:#1f2937;line-height:1.1;">
                                    <?= number_format($value) ?>
                                </div>
                                <div style="font-size:.73rem;color:#9ca3af;margin-top:.1rem;"><?= $label ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Overall meter -->
            <div class="card-mis mb-4" style="border-left:4px solid #10b981;">
                <div class="card-mis-body" style="padding:.9rem 1.2rem;">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div style="font-size:.82rem;font-weight:600;color:#374151;">
                            <i class="fas fa-gauge-high me-2" style="color:#10b981;"></i>Overall Completion Rate
                            <?php if ($topPerformer): ?>
                                <span style="font-size:.72rem;color:#9ca3af;font-weight:400;margin-left:.75rem;">
                                    Top: <strong style="color:#10b981;">
                                        <?= htmlspecialchars(explode(' ', $topPerformer['full_name'])[0]) ?>
                                    </strong>
                                    (<?= $topPerformer['total'] > 0 ? round(($topPerformer['done'] / $topPerformer['total']) * 100) : 0 ?>%)
                                </span>
                            <?php endif; ?>
                        </div>
                        <span style="font-size:1.1rem;font-weight:800;color:#10b981;"><?= $overallPct ?>%</span>
                    </div>
                    <div style="background:#f3f4f6;border-radius:99px;height:7px;margin-top:.6rem;">
                        <div style="width:<?= $overallPct ?>%;background:linear-gradient(90deg,#10b981,#34d399);
                        height:100%;border-radius:99px;transition:width .6s ease;"></div>
                    </div>
                    <div style="font-size:.7rem;color:#9ca3af;margin-top:.3rem;">
                        <?= $totalDone ?> of <?= $totalTasks ?> tasks completed across <?= $totalStaff ?> staff
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-bar mb-4 w-100">
                <form method="GET" class="row g-2 align-items-end w-100">
                    <div class="col-md-2">
                        <label class="form-label-mis">From</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="<?= $fromDate ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">To</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="<?= $toDate ?>">
                    </div>
                    <?php if (isExecutive() || $isBranchManager): ?>
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
                    <?php if (isExecutive()): ?>
                        <div class="col-md-2">
                            <label class="form-label-mis">Branch</label>
                            <select name="branch_id" class="form-select form-select-sm">
                                <option value="">All Branches</option>
                                <?php foreach ($allBranches as $b): ?>
                                    <option value="<?= $b['id'] ?>" <?= $filterBranch == $b['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['branch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-gold btn-sm w-100">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                        <a href="staff_wise.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- Charts -->
            <?php if (!empty($staffReport)): ?>
                <div class="row g-3 mb-4">
                    <div class="col-lg-8">
                        <div class="card-mis h-100">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-chart-bar text-warning me-2"></i>Task Breakdown by Staff</h5>
                            </div>
                            <div class="card-mis-body">
                                <div style="height:280px;position:relative;">
                                    <canvas id="staffBarChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card-mis h-100">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-chart-pie text-warning me-2"></i>Status Distribution</h5>
                            </div>
                            <div class="card-mis-body d-flex flex-column align-items-center justify-content-center">
                                <div style="height:200px;position:relative;width:100%;">
                                    <canvas id="statusDonut"></canvas>
                                </div>
                                <div style="margin-top:.75rem;width:100%;">
                                    <?php foreach ($allStatuses as $st):
                                        $safe = preg_replace('/[^a-z0-9_]/', '_', strtolower($st['status_name']));
                                        $count = array_sum(array_column($staffReport, 'status_' . $safe));
                                        if (!$count)
                                            continue;
                                        ?>
                                        <div style="display:flex;align-items:center;justify-content:space-between;
                                padding:.2rem 0;font-size:.75rem;">
                                            <div style="display:flex;align-items:center;gap:.4rem;">
                                                <div style="width:10px;height:10px;border-radius:50%;
                                        background:<?= htmlspecialchars($st['color']) ?>;flex-shrink:0;"></div>
                                                <i class="fas <?= htmlspecialchars($st['icon']) ?>"
                                                    style="font-size:.65rem;color:<?= htmlspecialchars($st['color']) ?>;"></i>
                                                <span style="color:#374151;"><?= htmlspecialchars($st['status_name']) ?></span>
                                            </div>
                                            <span style="font-weight:600;color:#1f2937;"><?= $count ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Staff Table -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-users text-warning me-2"></i>Staff Performance Details</h5>
                    <small class="text-muted"><?= $totalStaff ?> staff members
                        <?php if ($isBranchManager): ?>
                            · <?= htmlspecialchars($user['branch_name'] ?? '') ?>
                        <?php endif; ?>
                    </small>
                </div>
                <div class="table-responsive">
                    <table class="table-mis w-100">
                        <thead>
                            <tr>
                                <th style="width:36px;">#</th>
                                <th>Staff</th>
                                <th>Role</th>
                                <th>Dept / Branch</th>
                                <th class="text-center">Total</th>
                                <?php foreach ($allStatuses as $st): ?>
                                    <th class="text-center" title="<?= htmlspecialchars($st['status_name']) ?>"
                                        style="min-width:54px;">
                                        <i class="fas <?= htmlspecialchars($st['icon']) ?>"
                                            style="color:<?= htmlspecialchars($st['color']) ?>;font-size:.75rem;"></i>
                                    </th>
                                <?php endforeach; ?>
                                <th style="min-width:160px;">Completion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staffReport)): ?>
                                <tr>
                                    <td colspan="<?= 5 + count($allStatuses) ?>" class="empty-state">
                                        <i class="fas fa-users"></i> No staff data found
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($staffReport as $i => $s):
                                $pct = $s['total'] > 0 ? round(($s['done'] / $s['total']) * 100) : 0;
                                $barColor = $pct >= 80 ? '#10b981' : ($pct >= 50 ? '#f59e0b' : '#ef4444');
                                $initials = strtoupper(
                                    substr($s['full_name'], 0, 1) .
                                    (strpos($s['full_name'], ' ')
                                        ? substr($s['full_name'], strpos($s['full_name'], ' ') + 1, 1)
                                        : '')
                                );
                                $role = strtolower($s['role_name'] ?? 'unknown');
                                $roleClass = match (true) {
                                    $role === 'admin' => 'role-admin',
                                    $role === 'executive' => 'role-executive',
                                    $role === 'staff' => 'role-staff',
                                    str_contains($role, 'branch') => 'role-branch_manager',
                                    default => 'role-default',
                                };
                                ?>
                                <tr style="<?= $s['id'] == $selfId ? 'background:#eff6ff;' : '' ?>">
                                    <td style="color:#9ca3af;font-size:.75rem;"><?= $i + 1 ?></td>

                                    <!-- Staff -->
                                    <td>
                                        <div
                                            style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;">
                                            <div style="display:flex;align-items:center;gap:.6rem;">
                                                <div style="width:34px;height:34px;border-radius:50%;
                                            background:<?= htmlspecialchars($s['dept_color'] ?? '#c9a84c') ?>22;
                                            color:<?= htmlspecialchars($s['dept_color'] ?? '#c9a84c') ?>;
                                            display:flex;align-items:center;justify-content:center;
                                            font-size:.72rem;font-weight:700;flex-shrink:0;">
                                                    <?= $initials ?>
                                                </div>
                                                <div>
                                                    <div style="font-size:.87rem;font-weight:500;color:#1f2937;
                                                display:flex;align-items:center;gap:6px;">
                                                        <?= htmlspecialchars($s['full_name']) ?>
                                                        <?php if ($s['id'] == $selfId): ?>
                                                            <span style="font-size:.65rem;background:#3b82f6;color:#fff;
                                                     padding:2px 6px;border-radius:10px;">YOU</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($s['employee_id']): ?>
                                                        <div style="font-size:.68rem;color:#9ca3af;">
                                                            <?= htmlspecialchars($s['employee_id']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <a href="<?= APP_URL ?>/admin/staff/view.php?id=<?= $s['id'] ?>"
                                                class="btn btn-sm btn-outline-secondary"
                                                style="padding:.2rem .45rem;flex-shrink:0;">
                                                <i class="fas fa-eye" style="font-size:.7rem;"></i>
                                            </a>
                                        </div>
                                    </td>

                                    <!-- Role -->
                                    <td>
                                        <span class="role-badge <?= $roleClass ?>">
                                            <?= htmlspecialchars($s['role_name'] ?? 'Unknown') ?>
                                        </span>
                                    </td>

                                    <!-- Dept / Branch -->
                                    <td>
                                        <span style="font-size:.73rem;
                                     background:<?= htmlspecialchars($s['dept_color'] ?? '#ccc') ?>22;
                                     color:<?= htmlspecialchars($s['dept_color'] ?? '#666') ?>;
                                     padding:.2rem .55rem;border-radius:99px;
                                     display:inline-block;margin-bottom:.2rem;">
                                            <?= htmlspecialchars($s['dept_name'] ?? '—') ?>
                                        </span>
                                        <?php foreach (($udaMap[$s['id']] ?? []) as $ux): ?>
                                            <span style="font-size:.65rem;
                                     background:<?= htmlspecialchars($ux['color'] ?? '#ccc') ?>22;
                                     color:<?= htmlspecialchars($ux['color'] ?? '#666') ?>;
                                     padding:.15rem .4rem;border-radius:99px;
                                     display:inline-block;margin-top:.1rem;
                                     border:1px dashed <?= htmlspecialchars($ux['color'] ?? '#ccc') ?>66;">
                                                +<?= htmlspecialchars($ux['dept_name']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                        <div style="font-size:.68rem;color:#9ca3af;margin-top:.15rem;">
                                            <?= htmlspecialchars(strtok($s['branch_name'] ?? '—', ' ')) ?>
                                        </div>
                                    </td>

                                    <!-- Total -->
                                    <td class="text-center">
                                        <span
                                            style="font-size:.9rem;font-weight:700;color:#1f2937;"><?= $s['total'] ?></span>
                                    </td>

                                    <!-- Dynamic status columns -->
                                    <?php foreach ($allStatuses as $st):
                                        $safe = preg_replace('/[^a-z0-9_]/', '_', strtolower($st['status_name']));
                                        $count = (int) ($s['status_' . $safe] ?? 0);
                                        ?>
                                        <td class="text-center">
                                            <?php if ($count > 0): ?>
                                                <span style="background:<?= htmlspecialchars($st['bg_color']) ?>;
                                     color:<?= htmlspecialchars($st['color']) ?>;
                                     padding:.15rem .45rem;border-radius:99px;
                                     font-size:.72rem;font-weight:600;display:inline-block;">
                                                    <?= $count ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color:#e5e7eb;font-size:.75rem;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>

                                    <!-- Completion -->
                                    <td style="min-width:160px;">
                                        <?php if ($s['total'] > 0): ?>
                                            <div style="display:flex;align-items:center;gap:.5rem;">
                                                <div
                                                    style="flex:1;background:#f3f4f6;border-radius:99px;height:6px;overflow:hidden;">
                                                    <div style="width:<?= $pct ?>%;background:<?= $barColor ?>;
                                            height:100%;border-radius:99px;transition:width .4s;"></div>
                                                </div>
                                                <span style="font-size:.72rem;font-weight:700;color:<?= $barColor ?>;
                                         white-space:nowrap;min-width:38px;text-align:right;">
                                                    <?= $pct ?>%
                                                </span>
                                            </div>
                                            <div style="font-size:.65rem;color:#9ca3af;margin-top:.15rem;">
                                                <?= $s['done'] ?>/<?= $s['total'] ?> done
                                                <?php if ($s['overdue'] > 0): ?>
                                                    &nbsp;<span style="color:#ef4444;font-weight:600;">⚠ <?= $s['overdue'] ?>
                                                        overdue</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size:.75rem;color:#9ca3af;">No tasks</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
        <?php include '../../includes/footer.php'; ?>
    </div>
</div>

<?php if (!empty($staffReport)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            const staff = <?= json_encode(array_map(function ($s) use ($allStatuses) {
                $row = [
                    'name' => explode(' ', $s['full_name'])[0],
                    'total' => (int) $s['total'],
                    'done' => (int) $s['done'],
                    'overdue' => (int) $s['overdue'],
                    'statuses' => [],
                ];
                foreach ($allStatuses as $st) {
                    $safe = preg_replace('/[^a-z0-9_]/', '_', strtolower($st['status_name']));
                    $row['statuses'][$st['status_name']] = (int) ($s['status_' . $safe] ?? 0);
                }
                return $row;
            }, $staffReport)) ?>;

            const statusMeta = <?= json_encode(array_values($allStatuses)) ?>;
            const top = [...staff].sort((a, b) => b.total - a.total).slice(0, 15);

            // Bar chart
            new Chart(document.getElementById('staffBarChart'), {
                type: 'bar',
                data: {
                    labels: top.map(d => d.name),
                    datasets: statusMeta.map(st => ({
                        label: st.status_name,
                        backgroundColor: st.color,
                        borderRadius: 3,
                        data: top.map(d => d.statuses[st.status_name] ?? 0),
                    }))
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true, pointStyle: 'circle',
                                font: { size: 11 }, padding: 14,
                                filter: item => top.reduce((s, d) => s + (d.statuses[item.text] ?? 0), 0) > 0
                            }
                        },
                        tooltip: {
                            callbacks: {
                                footer: items => 'Total: ' + items.reduce((s, i) => s + i.raw, 0)
                            }
                        }
                    },
                    scales: {
                        x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
                        y: { stacked: true, beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { stepSize: 1, font: { size: 11 } } }
                    }
                }
            });

            // Donut chart
            const donutTotals = statusMeta.map(st => ({
                label: st.status_name,
                color: st.color,
                value: staff.reduce((s, d) => s + (d.statuses[st.status_name] ?? 0), 0),
            })).filter(d => d.value > 0);

            new Chart(document.getElementById('statusDonut'), {
                type: 'doughnut',
                data: {
                    labels: donutTotals.map(d => d.label),
                    datasets: [{ data: donutTotals.map(d => d.value), backgroundColor: donutTotals.map(d => d.color), borderWidth: 2, borderColor: '#fff', hoverOffset: 6 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '68%',
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw} tasks` } }
                    }
                },
                plugins: [{
                    id: 'centreText',
                    afterDraw(chart) {
                        const { ctx, chartArea: { top, bottom, left, right } } = chart;
                        const cx = (left + right) / 2, cy = (top + bottom) / 2;
                        ctx.save();
                        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                        ctx.fillStyle = '#1f2937'; ctx.font = 'bold 22px sans-serif';
                        ctx.fillText(<?= $totalTasks ?>, cx, cy - 8);
                        ctx.fillStyle = '#9ca3af'; ctx.font = '11px sans-serif';
                        ctx.fillText('total tasks', cx, cy + 12);
                        ctx.restore();
                    }
                }]
            });
        });
    </script>
<?php endif; ?>