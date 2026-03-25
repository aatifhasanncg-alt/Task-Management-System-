<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAdmin();

$db = getDB();
$user = currentUser();
$pageTitle = 'Reports';

// ── Admin profile ─────────────────────────────────────────────────────────────
$adminStmt = $db->prepare("
    SELECT u.*, d.dept_name, d.dept_code, b.branch_name
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN branches b    ON b.id = u.branch_id
    WHERE u.id = ?
");
$adminStmt->execute([$user['id']]);
$adminUser = $adminStmt->fetch();

$adminBranchId = (int) ($adminUser['branch_id'] ?? 0);
$adminDeptId = (int) ($adminUser['department_id'] ?? 0);

// ── Filters ───────────────────────────────────────────────────────────────────
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');
$employeeName = trim($_GET['employee_name'] ?? '');
$dateFrom = $fromDate . ' 00:00:00';
$dateTo = $toDate . ' 23:59:59';

// ── Dynamic statuses ──────────────────────────────────────────────────────────
$allStatuses = $db->query("SELECT id, status_name FROM task_status WHERE status_name != 'Corporate Team' ORDER BY id")->fetchAll();

// ── SCOPE — always show all related tasks (same as show_all=1 in task index) ──
// Own dept + transferred in/out + created by admin + assigned to admin
$scopeWhere = '(
    (t.branch_id = ? AND t.department_id = ?)
    OR EXISTS (
        SELECT 1 FROM task_workflow tw
        WHERE tw.task_id      = t.id
          AND tw.action       = \'transferred_dept\'
          AND tw.from_dept_id = ?
    )
    OR EXISTS (
        SELECT 1 FROM task_workflow tw
        WHERE tw.task_id    = t.id
          AND tw.action     = \'transferred_dept\'
          AND tw.to_dept_id = ?
    )
    OR t.created_by  = ?
    OR t.assigned_to = ?
)';
$scopeParams = [
    $adminBranchId,
    $adminDeptId,
    $adminDeptId,
    $adminDeptId,
    $user['id'],
    $user['id'],
];

$baseWhere = "t.is_active = 1 AND {$scopeWhere} AND t.created_at BETWEEN ? AND ?";
$baseParams = array_merge($scopeParams, [$dateFrom, $dateTo]);

// ── 1. Status summary ─────────────────────────────────────────────────────────
$statusStmt = $db->prepare("
    SELECT ts.status_name AS status, COUNT(DISTINCT t.id) AS cnt
    FROM task_status ts
    LEFT JOIN tasks t ON t.status_id = ts.id
        AND t.is_active = 1
        AND {$scopeWhere}
        AND t.created_at BETWEEN ? AND ?
    LEFT JOIN companies c  ON c.id  = t.company_id
    LEFT JOIN users     at ON at.id = t.assigned_to
    GROUP BY ts.id, ts.status_name
    ORDER BY ts.id
");
$statusStmt->execute(array_merge($scopeParams, [$dateFrom, $dateTo]));
$statusReport = array_column($statusStmt->fetchAll(), 'cnt', 'status');
$totalDeptTasks = array_sum($statusReport);

// ── 2. Transfer activity ──────────────────────────────────────────────────────
$transferIn = $transferOut = 0;
try {
    $tIn = $db->prepare("
        SELECT COUNT(DISTINCT tw.task_id)
        FROM task_workflow tw
        JOIN tasks t ON t.id = tw.task_id AND t.is_active = 1 AND t.branch_id = ?
        WHERE tw.action    = 'transferred_dept'
          AND tw.to_dept_id = ?
          AND tw.created_at BETWEEN ? AND ?
    ");
    $tIn->execute([$adminBranchId, $adminDeptId, $dateFrom, $dateTo]);
    $transferIn = (int) $tIn->fetchColumn();

    $tOut = $db->prepare("
        SELECT COUNT(DISTINCT tw.task_id)
        FROM task_workflow tw
        JOIN tasks t ON t.id = tw.task_id AND t.is_active = 1 AND t.branch_id = ?
        WHERE tw.action      = 'transferred_dept'
          AND tw.from_dept_id = ?
          AND tw.created_at BETWEEN ? AND ?
    ");
    $tOut->execute([$adminBranchId, $adminDeptId, $dateFrom, $dateTo]);
    $transferOut = (int) $tOut->fetchColumn();
} catch (Exception $e) {
}

// ── 3. Staff performance ──────────────────────────────────────────────────────
// Staff shown = users in this dept+branch (role: staff)
// Their task count = ALL tasks they are involved in under the same scope as above
// (assigned to them OR transferred to them) — same UNION strategy as before

$statusCols = '';
foreach ($allStatuses as $st) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
    $quoted = $db->quote($st['status_name']);
    $statusCols .= "SUM(CASE WHEN ut.status_name = {$quoted} THEN 1 ELSE 0 END) AS `{$safe}`,\n        ";
}

$nameWhere = $employeeName ? 'AND u.full_name LIKE ?' : '';
$nameParams = $employeeName ? ["%{$employeeName}%"] : [];

// Build UNION params:
// Branch (a): assigned_to tasks — scope params + dates
// Branch (b): transferred-to tasks — scope params + dates
// Outer WHERE: branch+dept for the user
$staffParams = array_merge(
    $scopeParams,
    [$dateFrom, $dateTo],          // UNION branch (a)
    $scopeParams,
    [$dateFrom, $dateTo],          // UNION branch (b)
    [$adminBranchId, $adminDeptId],              // outer: staff in this branch+dept only
    $nameParams
);

$staffStmt = $db->prepare("
    SELECT
        u.id          AS user_id,
        u.full_name,
        u.employee_id,
        b.branch_name,
        d.dept_name,
        COUNT(DISTINCT ut.task_id)  AS total,
        {$statusCols}
        SUM(ut.via_transfer)        AS transferred_in_count,
        SUM(1 - ut.via_transfer)    AS original_count
    FROM users u
    LEFT JOIN branches b    ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN roles r       ON r.id = u.role_id
    LEFT JOIN (

        -- Branch (a): tasks currently assigned to this user, same scope as index
        SELECT t.id AS task_id, t.assigned_to AS user_id, ts.status_name, 0 AS via_transfer
        FROM tasks t
        LEFT JOIN companies   c  ON c.id  = t.company_id
        LEFT JOIN users       at ON at.id = t.assigned_to
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active = 1
          AND {$scopeWhere}
          AND t.created_at BETWEEN ? AND ?

        UNION

        -- Branch (b): tasks transferred TO this user, same scope
        SELECT t.id AS task_id, tw.to_user_id AS user_id, ts.status_name, 1 AS via_transfer
        FROM task_workflow tw
        JOIN tasks t ON t.id = tw.task_id
        LEFT JOIN companies   c  ON c.id  = t.company_id
        LEFT JOIN users       at ON at.id = t.assigned_to
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active = 1
          AND {$scopeWhere}
          AND t.created_at BETWEEN ? AND ?
          AND tw.action IN ('transferred_staff','transferred_dept')
          AND tw.to_user_id IS NOT NULL

    ) AS ut ON ut.user_id = u.id
    WHERE u.is_active      = 1
      AND r.role_name     = 'staff'
      AND u.branch_id     = ?
      AND u.department_id = ?
      {$nameWhere}
    GROUP BY u.id, u.full_name, u.employee_id, b.branch_name, d.dept_name
    ORDER BY total DESC, u.full_name ASC
");
$staffStmt->execute($staffParams);
$staffReport = $staffStmt->fetchAll();

// Chart statuses with data
$chartStatuses = [];
foreach ($allStatuses as $st) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
    foreach ($staffReport as $row) {
        if (($row[$safe] ?? 0) > 0) {
            $chartStatuses[] = $st;
            break;
        }
    }
}

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <!-- Hero -->
            <div class="page-hero">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-chart-bar"></i> Reports</div>
                        <h4>Task Reports</h4>
                        <p style="margin:0;">
                            <?= date('d M Y', strtotime($fromDate)) ?> — <?= date('d M Y', strtotime($toDate)) ?>
                            <span style="font-size:.8rem;color:#9ca3af;margin-left:.5rem;">
                                · <?= htmlspecialchars($adminUser['dept_name'] ?? '') ?>
                                · <?= htmlspecialchars($adminUser['branch_name'] ?? '') ?>
                            </span>
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?<?= http_build_query($_GET) ?>"
                            class="btn btn-sm"
                            style="background:#dc2626;color:white;border-radius:8px;padding:.4rem .9rem;">
                            <i class="fas fa-file-pdf me-1"></i>Export PDF
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=report&<?= http_build_query(array_merge($_GET, [
                              'branch_id' => $adminBranchId,
                              'dept_id' => $adminDeptId,
                          ])) ?>" class="btn btn-sm" style="background:#16a34a;color:white;border-radius:8px;padding:.4rem .9rem;">
                            <i class="fas fa-file-excel me-1"></i>Export Excel
                        </a>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-bar mb-4 w-100">
                <form method="GET" class="row g-2 align-items-end w-100">
                    <div class="col-md-2">
                        <label class="form-label-mis">From Date</label>
                        <input type="date" name="from" class="form-control form-control-sm"
                            value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">To Date</label>
                        <input type="date" name="to" class="form-control form-control-sm"
                            value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-mis">Employee Name</label>
                        <input type="text" name="employee_name" class="form-control form-control-sm"
                            value="<?= htmlspecialchars($employeeName) ?>" placeholder="Search staff name...">
                    </div>
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

            <!-- Transfer banner -->
            <?php if ($transferIn > 0 || $transferOut > 0): ?>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;
            padding:.75rem 1.1rem;margin-bottom:1.25rem;
            display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
                    <span style="font-size:.8rem;color:#1d4ed8;font-weight:700;">
                        <i class="fas fa-exchange-alt me-1"></i>Transfer Activity
                    </span>
                    <span style="font-size:.8rem;color:#16a34a;">
                        <i class="fas fa-arrow-down me-1"></i>
                        <strong><?= $transferIn ?></strong> task<?= $transferIn !== 1 ? 's' : '' ?> received
                    </span>
                    <span style="font-size:.8rem;color:#ef4444;">
                        <i class="fas fa-arrow-up me-1"></i>
                        <strong><?= $transferOut ?></strong> task<?= $transferOut !== 1 ? 's' : '' ?> sent out
                    </span>
                    <span style="font-size:.72rem;color:#9ca3af;margin-left:auto;">
                        Staff totals include both assigned &amp; transferred tasks
                    </span>
                </div>
            <?php endif; ?>

            <!-- Status summary cards -->
            <div class="row g-3 mb-4">
                <?php foreach ($allStatuses as $st):
                    $k = $st['status_name'];
                    $cnt = $statusReport[$k] ?? 0;
                    $pct = $totalDeptTasks ? round(($cnt / $totalDeptTasks) * 100) : 0;
                    $col = defined('TASK_STATUSES') && isset(TASK_STATUSES[$k]) ? TASK_STATUSES[$k]['color'] : '#6b7280';
                    $bg = defined('TASK_STATUSES') && isset(TASK_STATUSES[$k]) ? TASK_STATUSES[$k]['bg'] : '#f3f4f6';
                    ?>
                    <div class="col-6 col-md-3 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-card-value" style="color:<?= $col ?>;"><?= $cnt ?></div>
                            <div class="stat-card-label"><?= htmlspecialchars($k) ?></div>
                            <div class="stat-card-change" style="color:#9ca3af;"><?= $pct ?>% of total</div>
                            <div style="background:#f3f4f6;border-radius:50px;height:4px;margin-top:.5rem;overflow:hidden;">
                                <div style="width:<?= $pct ?>%;background:<?= $col ?>;height:4px;border-radius:50px;"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="col-6 col-md-3 col-xl-2">
                    <div class="stat-card">
                        <div class="stat-card-value" style="color:#1f2937;"><?= $totalDeptTasks ?></div>
                        <div class="stat-card-label">Total (Dept)</div>
                        <div class="stat-card-change" style="color:#9ca3af;font-size:.68rem;">all related tasks</div>
                    </div>
                </div>
            </div>

            <!-- Staff Performance Table -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <div>
                        <h5 style="margin:0;"><i class="fas fa-users text-warning me-2"></i>Staff Performance</h5>
                        <small style="color:#9ca3af;">
                            <?= htmlspecialchars($adminUser['dept_name'] ?? '') ?> ·
                            <?= htmlspecialchars($adminUser['branch_name'] ?? '') ?> ·
                            <?= count($staffReport) ?> staff
                        </small>
                    </div>
                    <span
                        style="font-size:.72rem;color:#6b7280;background:#f3f4f6;padding:.25rem .65rem;border-radius:99px;">
                        <i class="fas fa-info-circle me-1"></i>Total includes transferred tasks
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table-mis w-100">
                        <thead>
                            <tr>
                                <th>Staff</th>
                                <th class="text-center">Total</th>
                                <?php foreach ($allStatuses as $st): ?>
                                    <th class="text-center" style="font-size:.72rem;white-space:nowrap;">
                                        <?= htmlspecialchars($st['status_name']) ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="text-center" style="font-size:.72rem;" title="Originally assigned">Original
                                </th>
                                <th class="text-center" style="font-size:.72rem;" title="Arrived via transfer">Xfer In
                                </th>
                                <th style="min-width:110px;">Done %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staffReport)): ?>
                                <tr>
                                    <td colspan="<?= 5 + count($allStatuses) ?>" class="empty-state">
                                        <i class="fas fa-users me-2"></i>
                                        No staff found in <?= htmlspecialchars($adminUser['dept_name'] ?? '') ?> ·
                                        <?= htmlspecialchars($adminUser['branch_name'] ?? '') ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php
                            $grandTotal = $grandXfer = $grandOrig = 0;
                            $grandDonePct = 0;
                            $grandStatusTotals = [];
                            ?>

                            <?php foreach ($staffReport as $s):
                                $doneCol = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower('Done'));
                                $doneCnt = (int) ($s[$doneCol] ?? 0);
                                $donePct = $s['total'] > 0 ? round(($doneCnt / $s['total']) * 100) : 0;
                                $xferIn = (int) ($s['transferred_in_count'] ?? 0);
                                $origCnt = (int) ($s['original_count'] ?? 0);
                                $grandTotal += (int) $s['total'];
                                $grandXfer += $xferIn;
                                $grandOrig += $origCnt;
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-circle avatar-sm flex-shrink-0">
                                                <?= strtoupper(substr($s['full_name'] ?? '?', 0, 2)) ?>
                                            </div>
                                            <div>
                                                <div style="font-size:.87rem;font-weight:500;">
                                                    <?= htmlspecialchars($s['full_name'] ?? '—') ?>
                                                </div>
                                                <div style="font-size:.72rem;color:#9ca3af;">
                                                    <?= htmlspecialchars($s['employee_id'] ?? '') ?>
                                                    <?php if ($s['branch_name']): ?> ·
                                                        <?= htmlspecialchars($s['branch_name']) ?>    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span
                                            style="font-size:.95rem;font-weight:700;color:#1f2937;"><?= $s['total'] ?></span>
                                    </td>
                                    <?php foreach ($allStatuses as $st):
                                        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                                        $cnt = (int) ($s[$safe] ?? 0);
                                        $badge = 'status-' . strtolower(str_replace(' ', '-', $st['status_name']));
                                        $grandStatusTotals[$safe] = ($grandStatusTotals[$safe] ?? 0) + $cnt;
                                        ?>
                                        <td class="text-center">
                                            <?php if ($cnt > 0): ?>
                                                <span class="status-badge <?= $badge ?>"><?= $cnt ?></span>
                                            <?php else: ?>
                                                <span style="color:#e5e7eb;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-center">
                                        <span style="background:#f0fdf4;color:#16a34a;padding:.15rem .5rem;
                                 border-radius:99px;font-size:.72rem;font-weight:600;">
                                            <?= $origCnt ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($xferIn > 0): ?>
                                            <span style="background:#eff6ff;color:#3b82f6;padding:.15rem .5rem;
                                 border-radius:99px;font-size:.72rem;font-weight:600;"
                                                title="<?= $xferIn ?> task(s) arrived via transfer">
                                                +<?= $xferIn ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#e5e7eb;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div
                                                style="flex:1;background:#f3f4f6;border-radius:99px;height:6px;overflow:hidden;">
                                                <div
                                                    style="width:<?= $donePct ?>%;background:#10b981;height:100%;border-radius:99px;">
                                                </div>
                                            </div>
                                            <span style="font-size:.72rem;color:#6b7280;flex-shrink:0;min-width:28px;">
                                                <?= $donePct ?>%
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Grand totals row -->
                            <?php if (!empty($staffReport)):
                                $grandDoneTotal = $grandStatusTotals[preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower('Done'))] ?? 0;
                                $grandDonePct = $grandTotal ? round(($grandDoneTotal / $grandTotal) * 100) : 0;
                                ?>
                                <tr style="background:#f9fafb;border-top:2px solid #e5e7eb;font-weight:700;">
                                    <td style="font-size:.82rem;color:#374151;">
                                        <i class="fas fa-sigma me-1 text-warning"></i>Grand Total
                                    </td>
                                    <td class="text-center" style="font-size:.95rem;color:#1f2937;"><?= $grandTotal ?></td>
                                    <?php foreach ($allStatuses as $st):
                                        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                                        $colTotal = $grandStatusTotals[$safe] ?? 0;
                                        ?>
                                        <td class="text-center" style="font-size:.82rem;color:#374151;"><?= $colTotal ?: '—' ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-center" style="font-size:.82rem;color:#16a34a;"><?= $grandOrig ?></td>
                                    <td class="text-center" style="font-size:.82rem;color:#3b82f6;">
                                        <?= $grandXfer ? '+' . $grandXfer : '—' ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div
                                                style="flex:1;background:#f3f4f6;border-radius:99px;height:6px;overflow:hidden;">
                                                <div
                                                    style="width:<?= $grandDonePct ?>%;background:#10b981;height:100%;border-radius:99px;">
                                                </div>
                                            </div>
                                            <span style="font-size:.72rem;color:#6b7280;flex-shrink:0;">
                                                <?= $grandDonePct ?>%
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Bar Chart -->
            <?php if (!empty($staffReport) && !empty($chartStatuses)): ?>
                <div class="card-mis mb-4">
                    <div class="card-mis-header">
                        <h5><i class="fas fa-chart-bar text-warning me-2"></i>Staff Task Breakdown</h5>
                    </div>
                    <div class="card-mis-body">
                        <div style="height:320px;position:relative;">
                            <canvas id="staffReportChart"></canvas>
                        </div>
                    </div>
                </div>

                <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const ctx = document.getElementById('staffReportChart');
                        if (!ctx) return;

                        const staff = <?= json_encode(array_map(function ($s) use ($allStatuses) {
                            $row = ['name' => explode(' ', $s['full_name'])[0] ?? 'N/A', 'total' => (int) $s['total']];
                            foreach ($allStatuses as $st) {
                                $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                                $row[$safe] = (int) ($s[$safe] ?? 0);
                            }
                            return $row;
                        }, $staffReport)) ?>;

                        const statuses = <?= json_encode(array_map(function ($st) {
                            $k = $st['status_name'];
                            return [
                                'key' => preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($k)),
                                'label' => $k,
                                'color' => (defined('TASK_STATUSES') && isset(TASK_STATUSES[$k]))
                                    ? TASK_STATUSES[$k]['color'] : '#9ca3af',
                            ];
                        }, $chartStatuses)) ?>;

                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: staff.map(d => d.name),
                                datasets: statuses.map(st => ({
                                    label: st.label,
                                    data: staff.map(d => d[st.key] || 0),
                                    backgroundColor: st.color,
                                    borderRadius: 4,
                                }))
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                interaction: { mode: 'index', intersect: false },
                                plugins: {
                                    legend: {
                                        display: true, position: 'top',
                                        labels: { font: { size: 11 }, usePointStyle: true }
                                    }
                                },
                                scales: {
                                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
                                    y: {
                                        stacked: true, beginAtZero: true, grid: { color: '#f3f4f6' },
                                        ticks: { stepSize: 1, font: { size: 11 } }
                                    }
                                }
                            }
                        });
                    });
                </script>
            <?php endif; ?>

        </div><!-- padding -->
    </div><!-- main-content -->
</div><!-- app-wrapper -->
<?php include '../../includes/footer.php'; ?>