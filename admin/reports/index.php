<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAdmin();

$db = getDB();
$user = currentUser();
$pageTitle = 'Reports';

// ── Admin profile ─────────────────────────────────────────────
$adminStmt = $db->prepare("
    SELECT u.*, d.dept_name, d.dept_code, d.color AS dept_color, b.branch_name
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN branches b    ON b.id = u.branch_id
    WHERE u.id = ?
");
$adminStmt->execute([$user['id']]);
$adminUser = $adminStmt->fetch();
$adminBranchId = (int) ($adminUser['branch_id'] ?? 0);
$adminDeptId = (int) ($adminUser['department_id'] ?? 0);
$deptColor = $adminUser['dept_color'] ?: '#c9a84c';

// ── Filters ───────────────────────────────────────────────────
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');
$employeeName = trim($_GET['employee_name'] ?? '');
$dateFrom = $fromDate . ' 00:00:00';
$dateTo = $toDate . ' 23:59:59';

// ── Statuses from DB ──────────────────────────────────────────
$allStatuses = $db->query("
    SELECT id, status_name, color, bg_color, icon
    FROM task_status
    WHERE status_name != 'Corporate Team'
    ORDER BY id ASC
")->fetchAll();

// Build status meta map
$statusMeta = [];
foreach ($allStatuses as $st) {
    $statusMeta[$st['status_name']] = $st;
}

// ── Task scope (same branch+dept or transferred, or created/assigned by admin) ──
$scopeWhere = '(
    (t.branch_id = ? AND t.department_id = ?)
    OR EXISTS (SELECT 1 FROM task_workflow tw WHERE tw.task_id=t.id AND tw.action=\'transferred_dept\' AND tw.from_dept_id=?)
    OR EXISTS (SELECT 1 FROM task_workflow tw WHERE tw.task_id=t.id AND tw.action=\'transferred_dept\' AND tw.to_dept_id=?)
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

// ── 1. Status summary ─────────────────────────────────────────
$statusStmt = $db->prepare("
    SELECT ts.status_name AS status, COUNT(DISTINCT t.id) AS cnt
    FROM task_status ts
    LEFT JOIN tasks t ON t.status_id = ts.id AND t.is_active=1
        AND {$scopeWhere}
        AND t.created_at BETWEEN ? AND ?
    WHERE ts.status_name != 'Corporate Team'
    GROUP BY ts.id, ts.status_name ORDER BY ts.id
");
$statusStmt->execute(array_merge($scopeParams, [$dateFrom, $dateTo]));
$statusReport = array_column($statusStmt->fetchAll(), 'cnt', 'status');
$totalDeptTasks = array_sum($statusReport);

// Find done count for completion rate
$doneCount = 0;
foreach ($statusMeta as $sn => $sm) {
    if (strtolower($sn) === 'done') {
        $doneCount = $statusReport[$sn] ?? 0;
        break;
    }
}
$completionRate = $totalDeptTasks ? round(($doneCount / $totalDeptTasks) * 100) : 0;

// ── 2. Transfer activity ──────────────────────────────────────
$transferIn = $transferOut = 0;
try {
    $tIn = $db->prepare("SELECT COUNT(DISTINCT tw.task_id) FROM task_workflow tw
        JOIN tasks t ON t.id=tw.task_id AND t.is_active=1 AND t.branch_id=?
        WHERE tw.action='transferred_dept' AND tw.to_dept_id=? AND tw.created_at BETWEEN ? AND ?");
    $tIn->execute([$adminBranchId, $adminDeptId, $dateFrom, $dateTo]);
    $transferIn = (int) $tIn->fetchColumn();

    $tOut = $db->prepare("SELECT COUNT(DISTINCT tw.task_id) FROM task_workflow tw
        JOIN tasks t ON t.id=tw.task_id AND t.is_active=1 AND t.branch_id=?
        WHERE tw.action='transferred_dept' AND tw.from_dept_id=? AND tw.created_at BETWEEN ? AND ?");
    $tOut->execute([$adminBranchId, $adminDeptId, $dateFrom, $dateTo]);
    $transferOut = (int) $tOut->fetchColumn();
} catch (Exception $e) {
}

// ── 3. Staff performance ──────────────────────────────────────
$statusCols = '';
foreach ($allStatuses as $st) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
    $quoted = $db->quote($st['status_name']);
    $statusCols .= "SUM(CASE WHEN ut.status_name = {$quoted} THEN 1 ELSE 0 END) AS `{$safe}`,\n        ";
}

$nameWhere = $employeeName ? 'AND u.full_name LIKE ?' : '';
$nameParams = $employeeName ? ["%{$employeeName}%"] : [];

$staffParams = array_merge(
    $scopeParams,
    [$dateFrom, $dateTo],
    $scopeParams,
    [$dateFrom, $dateTo],
    [$adminBranchId, $adminDeptId],
    $nameParams
);

$staffStmt = $db->prepare("
    SELECT u.id AS user_id, u.full_name, u.employee_id,
           b.branch_name, d.dept_name,
           COUNT(DISTINCT ut.task_id) AS total,
           {$statusCols}
           SUM(ut.via_transfer)     AS transferred_in_count,
           SUM(1 - ut.via_transfer) AS original_count
    FROM users u
    LEFT JOIN branches b    ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN roles r       ON r.id = u.role_id
    LEFT JOIN (
        SELECT t.id AS task_id, t.assigned_to AS user_id, ts.status_name, 0 AS via_transfer
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active=1 AND {$scopeWhere} AND t.created_at BETWEEN ? AND ?
        UNION
        SELECT t.id AS task_id, tw.to_user_id AS user_id, ts.status_name, 1 AS via_transfer
        FROM task_workflow tw
        JOIN tasks t ON t.id = tw.task_id
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active=1 AND {$scopeWhere} AND t.created_at BETWEEN ? AND ?
          AND tw.action IN ('transferred_staff','transferred_dept')
          AND tw.to_user_id IS NOT NULL
    ) AS ut ON ut.user_id = u.id
    WHERE u.is_active=1 AND r.role_name='staff'
      AND u.branch_id=? AND u.department_id=?
      {$nameWhere}
    GROUP BY u.id, u.full_name, u.employee_id, b.branch_name, d.dept_name
    ORDER BY total DESC, u.full_name ASC
");
$staffStmt->execute($staffParams);
$staffReport = $staffStmt->fetchAll();

// Find done key
$doneKey = 'done';
foreach ($allStatuses as $st) {
    if (strtolower($st['status_name']) === 'done') {
        $doneKey = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
        break;
    }
}

// Donut SVG helper
function donut(int $pct, string $color, int $size = 56): string
{
    $r = ($size / 2) - 5;
    $circ = round(2 * M_PI * $r, 2);
    $dash = round($circ * $pct / 100, 2);
    $cx = $size / 2;
    return <<<SVG
<svg width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}">
  <circle cx="{$cx}" cy="{$cx}" r="{$r}" fill="none" stroke="#f3f4f6" stroke-width="4.5"/>
  <circle cx="{$cx}" cy="{$cx}" r="{$r}" fill="none" stroke="{$color}" stroke-width="4.5"
          stroke-dasharray="{$dash} {$circ}" stroke-linecap="round"
          transform="rotate(-90 {$cx} {$cx})"/>
  <text x="{$cx}" y="{$cx}" dominant-baseline="central" text-anchor="middle"
        font-size="9" font-weight="700" fill="{$color}">{$pct}%</text>
</svg>
SVG;
}

include '../../includes/header.php';
?>

<style>
    /* ── Report page custom styles ─────────────────────────────── */
    .rpt-hero {
        background: linear-gradient(135deg, #0a0f1e 0%, #111827 60%, #1a2235 100%);
        border-radius: 16px;
        padding: 1.75rem 2rem;
        margin-bottom: 1.5rem;
        position: relative;
        overflow: hidden;
    }

    .rpt-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(ellipse at 80% 50%,
                <?= $deptColor ?>
                18 0%, transparent 60%);
        pointer-events: none;
    }

    .rpt-hero-accent {
        position: absolute;
        right: -20px;
        top: -30px;
        width: 180px;
        height: 180px;
        border-radius: 50%;
        background:
            <?= $deptColor ?>
            0d;
        border: 1px solid
            <?= $deptColor ?>
            22;
    }

    /* Stat cards */
    .rpt-stat {
        background: #fff;
        border-radius: 14px;
        border: 1px solid #f3f4f6;
        padding: 1.1rem 1rem 1rem;
        position: relative;
        overflow: hidden;
        transition: transform .15s, box-shadow .15s;
    }

    .rpt-stat:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, .08);
    }

    .rpt-stat::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        border-radius: 14px 14px 0 0;
        background: var(--sc);
    }

    .rpt-stat-val {
        font-size: 2rem;
        font-weight: 800;
        line-height: 1;
        color: var(--sc);
    }

    .rpt-stat-label {
        font-size: .72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #9ca3af;
        margin-top: .25rem;
    }

    .rpt-stat-bar {
        background: #f3f4f6;
        border-radius: 99px;
        height: 4px;
        margin-top: .75rem;
        overflow: hidden;
    }

    .rpt-stat-fill {
        height: 100%;
        border-radius: 99px;
        background: var(--sc);
    }

    /* Staff performance cards */
    .staff-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #f0f0f0;
        padding: 1rem 1.1rem;
        transition: box-shadow .15s;
    }

    .staff-card:hover {
        box-shadow: 0 4px 16px rgba(0, 0, 0, .07);
    }

    .staff-pill {
        display: inline-block;
        font-size: .68rem;
        font-weight: 700;
        padding: .15rem .5rem;
        border-radius: 99px;
        white-space: nowrap;
    }

    /* Status mini-grid */
    .status-grid {
        display: grid;
        gap: .35rem;
        margin-top: .6rem;
    }

    /* Chart card */
    .chart-card {
        background: #fff;
        border-radius: 14px;
        border: 1px solid #f3f4f6;
        overflow: hidden;
    }

    .chart-card-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f3f4f6;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    /* Transfer badge */
    .xfer-badge {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        font-size: .72rem;
        font-weight: 700;
        padding: .3rem .75rem;
        border-radius: 99px;
    }

    /* Filter bar */
    .rpt-filter {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
    }

    /* Completion ring card */
    .completion-ring-card {
        background: linear-gradient(135deg,
                <?= $deptColor ?>
                10,
                <?= $deptColor ?>
                05);
        border: 1px solid
            <?= $deptColor ?>
            33;
        border-radius: 14px;
        padding: 1.25rem;
        display: flex;
        align-items: center;
        gap: 1.25rem;
        height: 100%;
    }

    @media (max-width: 576px) {
        .rpt-stat-val {
            font-size: 1.5rem;
        }
    }
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <!-- ── HERO ── -->
            <div class="rpt-hero mb-4">
                <div class="rpt-hero-accent"></div>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3"
                    style="position:relative;">
                    <div>
                        <div style="display:inline-flex;align-items:center;gap:.5rem;background:<?= $deptColor ?>22;
                                    border:1px solid <?= $deptColor ?>44;border-radius:99px;
                                    padding:.25rem .8rem;font-size:.72rem;font-weight:700;
                                    color:<?= $deptColor ?>;margin-bottom:.6rem;">
                            <i class="fas fa-chart-bar" style="font-size:.65rem;"></i>
                            Task Reports
                        </div>
                        <h4 style="color:#fff;font-size:1.4rem;font-weight:800;margin:0 0 .2rem;">
                            <?= htmlspecialchars($adminUser['dept_name'] ?? 'Reports') ?>
                            <span style="font-size:.9rem;font-weight:400;color:#6b7280;margin-left:.5rem;">·</span>
                            <span style="font-size:.9rem;font-weight:500;color:#9ca3af;margin-left:.35rem;">
                                <?= htmlspecialchars($adminUser['branch_name'] ?? '') ?>
                            </span>
                        </h4>
                        <p style="color:#6b7280;font-size:.82rem;margin:0;">
                            <?= date('d M Y', strtotime($fromDate)) ?> — <?= date('d M Y', strtotime($toDate)) ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=report&<?= http_build_query(array_merge($_GET, ['branch_id' => $adminBranchId, 'dept_id' => $adminDeptId])) ?>"
                            style="background:#dc2626;color:#fff;border:none;border-radius:8px;
                                  padding:.45rem 1rem;font-size:.8rem;font-weight:600;
                                  display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=report&<?= http_build_query(array_merge($_GET, ['branch_id' => $adminBranchId, 'dept_id' => $adminDeptId])) ?>"
                            style="background:#16a34a;color:#fff;border:none;border-radius:8px;
                                  padding:.45rem 1rem;font-size:.8rem;font-weight:600;
                                  display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </a>
                    </div>
                </div>
            </div>

            <!-- ── FILTERS ── -->
            <div class="rpt-filter">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label-mis">From Date</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="<?= $fromDate ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">To Date</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="<?= $toDate ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-mis">Search Staff</label>
                        <input type="text" name="employee_name" class="form-control form-control-sm"
                            value="<?= htmlspecialchars($employeeName) ?>" placeholder="Name…">
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

            <!-- ── TRANSFER ACTIVITY BANNER ── -->
            <?php if ($transferIn > 0 || $transferOut > 0): ?>
                <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1.25rem;">
                    <span
                        style="font-size:.72rem;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">
                        <i class="fas fa-exchange-alt me-1"></i>Transfer Activity
                    </span>
                    <?php if ($transferIn > 0): ?>
                        <span class="xfer-badge" style="background:#ecfdf5;color:#16a34a;border:1px solid #a7f3d0;">
                            <i class="fas fa-arrow-down" style="font-size:.6rem;"></i>
                            <?= $transferIn ?> received
                        </span>
                    <?php endif; ?>
                    <?php if ($transferOut > 0): ?>
                        <span class="xfer-badge" style="background:#fef2f2;color:#ef4444;border:1px solid #fecaca;">
                            <i class="fas fa-arrow-up" style="font-size:.6rem;"></i>
                            <?= $transferOut ?> sent out
                        </span>
                    <?php endif; ?>
                    <span style="font-size:.7rem;color:#9ca3af;">Staff totals include transferred tasks</span>
                </div>
            <?php endif; ?>

            <!-- ── STATUS SUMMARY + COMPLETION RING ── -->
            <div class="row g-3 mb-4">

                <!-- Status stat cards -->
                <?php foreach ($allStatuses as $st):
                    $sn = $st['status_name'];
                    $cnt = $statusReport[$sn] ?? 0;
                    $pct = $totalDeptTasks ? round(($cnt / $totalDeptTasks) * 100) : 0;
                    $sc = $st['color'] ?: '#9ca3af';
                    $sbg = $st['bg_color'] ?: '#f3f4f6';
                    $rawI = trim($st['icon'] ?: 'fa-circle');
                    $ico = str_starts_with($rawI, 'fa') ? $rawI : 'fa-' . $rawI;
                    $link = APP_URL . '/admin/tasks/index.php?status=' . urlencode($sn);
                    ?>
                    <div class="col-6 col-md-3 col-xl-2">
                        <a href="<?= $link ?>" style="text-decoration:none;display:block;">
                            <div class="rpt-stat" style="--sc:<?= $sc ?>;">
                                <!-- Icon bubble -->
                                <div style="position:absolute;top:.85rem;right:.85rem;
                                            width:30px;height:30px;border-radius:8px;
                                            background:<?= $sbg ?>;
                                            display:flex;align-items:center;justify-content:center;">
                                    <i class="fas <?= htmlspecialchars($ico) ?>"
                                        style="font-size:.75rem;color:<?= $sc ?>;"></i>
                                </div>
                                <div class="rpt-stat-val"><?= $cnt ?></div>
                                <div class="rpt-stat-label"><?= htmlspecialchars($sn) ?></div>
                                <div style="font-size:.68rem;color:#9ca3af;margin-top:.2rem;"><?= $pct ?>% of total</div>
                                <div class="rpt-stat-bar">
                                    <div class="rpt-stat-fill" style="width:<?= $pct ?>%;"></div>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>

                <!-- Total + Completion ring -->
                <div class="col-6 col-md-3 col-xl-2">
                    <div class="rpt-stat" style="--sc:<?= $deptColor ?>;">
                        <div style="position:absolute;top:.85rem;right:.85rem;
                                    width:30px;height:30px;border-radius:8px;
                                    background:<?= $deptColor ?>18;
                                    display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-layer-group" style="font-size:.75rem;color:<?= $deptColor ?>;"></i>
                        </div>
                        <div class="rpt-stat-val"><?= $totalDeptTasks ?></div>
                        <div class="rpt-stat-label">Total Tasks</div>
                        <div style="font-size:.68rem;color:#9ca3af;margin-top:.2rem;">All dept tasks</div>
                        <div class="rpt-stat-bar">
                            <div class="rpt-stat-fill" style="width:100%;"></div>
                        </div>
                    </div>
                </div>

                <!-- Completion ring full-col on larger -->
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="completion-ring-card">
                        <?php
                        $rc = 48;
                        $rcirc = round(2 * M_PI * $rc, 2);
                        $rdsh = round($rcirc * $completionRate / 100, 2);
                        ?>
                        <div style="position:relative;flex-shrink:0;">
                            <svg width="110" height="110" viewBox="0 0 110 110">
                                <circle cx="55" cy="55" r="<?= $rc ?>" fill="none" stroke="#e5e7eb" stroke-width="8" />
                                <circle cx="55" cy="55" r="<?= $rc ?>" fill="none" stroke="<?= $deptColor ?>"
                                    stroke-width="8" stroke-dasharray="<?= $rdsh ?> <?= $rcirc ?>"
                                    stroke-linecap="round" transform="rotate(-90 55 55)" />
                            </svg>
                            <div style="position:absolute;inset:0;display:flex;flex-direction:column;
                                        align-items:center;justify-content:center;">
                                <div style="font-size:1.4rem;font-weight:800;color:<?= $deptColor ?>;line-height:1;">
                                    <?= $completionRate ?>%
                                </div>
                                <div style="font-size:.58rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">
                                    done</div>
                            </div>
                        </div>
                        <div>
                            <div style="font-size:.95rem;font-weight:700;color:#1f2937;">Completion Rate</div>
                            <div style="font-size:.75rem;color:#9ca3af;margin-top:.2rem;">
                                <?= $doneCount ?> of <?= $totalDeptTasks ?> tasks completed
                            </div>
                            <div style="margin-top:.75rem;display:flex;flex-direction:column;gap:.3rem;">
                                <?php if ($transferIn > 0): ?>
                                    <div style="font-size:.72rem;color:#16a34a;font-weight:600;">
                                        <i class="fas fa-arrow-circle-down me-1"></i><?= $transferIn ?> received via
                                        transfer
                                    </div>
                                <?php endif; ?>
                                <?php if ($transferOut > 0): ?>
                                    <div style="font-size:.72rem;color:#ef4444;font-weight:600;">
                                        <i class="fas fa-arrow-circle-up me-1"></i><?= $transferOut ?> sent to another dept
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ── STAFF PERFORMANCE ── -->
            <div class="chart-card mb-4">
                <div class="chart-card-header">
                    <div>
                        <h5 style="margin:0;font-size:.95rem;font-weight:700;">
                            <i class="fas fa-users me-2" style="color:<?= $deptColor ?>;"></i>Staff Performance
                        </h5>
                        <small style="color:#9ca3af;">
                            <?= htmlspecialchars($adminUser['dept_name'] ?? '') ?> ·
                            <?= htmlspecialchars($adminUser['branch_name'] ?? '') ?> ·
                            <?= count($staffReport) ?> staff
                        </small>
                    </div>
                    <span style="font-size:.7rem;color:#6b7280;background:#f9fafb;
                                 padding:.25rem .65rem;border-radius:99px;border:1px solid #e5e7eb;">
                        <i class="fas fa-info-circle me-1 text-warning"></i>Includes transferred tasks
                    </span>
                </div>

                <!-- Staff cards grid -->
                <?php if (empty($staffReport)): ?>
                    <div style="padding:3rem;text-align:center;color:#9ca3af;">
                        <i class="fas fa-users fa-2x mb-2 d-block"></i>
                        No staff found in <?= htmlspecialchars($adminUser['dept_name'] ?? '') ?>
                    </div>
                <?php else: ?>

                    <!-- Table for md+ -->
                    <div class="table-responsive d-none d-md-block">
                        <table class="table-mis w-100" style="font-size:.78rem;">
                            <thead>
                                <tr>
                                    <th style="min-width:160px;">Staff Member</th>
                                    <th class="text-center" style="width:52px;">Total</th>
                                    <?php foreach ($allStatuses as $st):
                                        $sc = $st['color'] ?: '#9ca3af'; ?>
                                        <th class="text-center" style="width:52px;">
                                            <span style="color:<?= $sc ?>;font-size:.65rem;font-weight:700;">
                                                <?= htmlspecialchars($st['status_name']) ?>
                                            </span>
                                        </th>
                                    <?php endforeach; ?>
                                    <th class="text-center" style="width:52px;font-size:.65rem;"
                                        title="Originally assigned">Orig</th>
                                    <th class="text-center" style="width:52px;font-size:.65rem;"
                                        title="Arrived via transfer">Xfer</th>
                                    <th style="min-width:110px;">Done %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $grandTotal = $grandXfer = $grandOrig = 0;
                                $grandStatusTotals = [];
                                ?>
                                <?php foreach ($staffReport as $idx => $s):
                                    $doneCnt = (int) ($s[$doneKey] ?? 0);
                                    $donePct = $s['total'] > 0 ? round(($doneCnt / $s['total']) * 100) : 0;
                                    $xferIn = (int) ($s['transferred_in_count'] ?? 0);
                                    $origCnt = (int) ($s['original_count'] ?? 0);
                                    $grandTotal += (int) $s['total'];
                                    $grandXfer += $xferIn;
                                    $grandOrig += $origCnt;
                                    $odd = $idx % 2 === 0;
                                    ?>
                                    <tr style="<?= $odd ? '' : 'background:#fafafa;' ?>">
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="avatar-circle avatar-sm flex-shrink-0"
                                                    style="width:30px;height:30px;font-size:.65rem;">
                                                    <?= strtoupper(substr($s['full_name'] ?? '?', 0, 2)) ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight:600;font-size:.85rem;color:#1f2937;">
                                                        <?= htmlspecialchars($s['full_name'] ?? '—') ?>
                                                    </div>
                                                    <div style="font-size:.68rem;color:#9ca3af;">
                                                        <?= htmlspecialchars($s['employee_id'] ?? '') ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span
                                                style="font-size:1rem;font-weight:800;color:#1f2937;"><?= $s['total'] ?></span>
                                        </td>
                                        <?php foreach ($allStatuses as $st):
                                            $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                                            $cnt = (int) ($s[$safe] ?? 0);
                                            $sc = $st['color'] ?: '#9ca3af';
                                            $sbg = $st['bg_color'] ?: '#f3f4f6';
                                            $grandStatusTotals[$safe] = ($grandStatusTotals[$safe] ?? 0) + $cnt;
                                            ?>
                                            <td class="text-center">
                                                <?php if ($cnt > 0): ?>
                                                    <span style="background:<?= $sbg ?>;color:<?= $sc ?>;
                                                             font-size:.68rem;font-weight:700;
                                                             padding:.15rem .45rem;border-radius:99px;
                                                             display:inline-block;min-width:22px;text-align:center;">
                                                        <?= $cnt ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color:#e5e7eb;font-size:.7rem;">—</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="text-center">
                                            <span style="background:#f0fdf4;color:#16a34a;font-size:.68rem;
                                                     font-weight:700;padding:.15rem .45rem;border-radius:99px;">
                                                <?= $origCnt ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($xferIn > 0): ?>
                                                <span style="background:#eff6ff;color:#3b82f6;font-size:.68rem;
                                                         font-weight:700;padding:.15rem .45rem;border-radius:99px;">
                                                    +<?= $xferIn ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color:#e5e7eb;font-size:.7rem;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <?= donut($donePct, $deptColor, 42) ?>
                                                <div style="flex:1;min-width:0;">
                                                    <div
                                                        style="background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
                                                        <div
                                                            style="width:<?= $donePct ?>%;background:<?= $deptColor ?>;height:5px;border-radius:99px;">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <!-- Grand total row -->
                                <?php
                                $grandDoneVal = $grandStatusTotals[$doneKey] ?? 0;
                                $grandDonePct = $grandTotal ? round(($grandDoneVal / $grandTotal) * 100) : 0;
                                ?>
                                <tr style="background:linear-gradient(90deg,<?= $deptColor ?>10,transparent);
                                       border-top:2px solid <?= $deptColor ?>33;font-weight:700;">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div style="width:30px;height:30px;border-radius:99px;
                                                    background:<?= $deptColor ?>22;
                                                    display:flex;align-items:center;justify-content:center;">
                                                <i class="fas fa-sigma"
                                                    style="font-size:.7rem;color:<?= $deptColor ?>;"></i>
                                            </div>
                                            <span style="font-size:.85rem;color:#1f2937;">Grand Total</span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span
                                            style="font-size:1rem;font-weight:800;color:<?= $deptColor ?>;"><?= $grandTotal ?></span>
                                    </td>
                                    <?php foreach ($allStatuses as $st):
                                        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                                        $colTotal = $grandStatusTotals[$safe] ?? 0;
                                        $sc = $st['color'] ?: '#9ca3af';
                                        ?>
                                        <td class="text-center" style="font-size:.82rem;font-weight:700;color:<?= $sc ?>;">
                                            <?= $colTotal ?: '—' ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-center" style="color:#16a34a;"><?= $grandOrig ?></td>
                                    <td class="text-center" style="color:#3b82f6;">
                                        <?= $grandXfer ? '+' . $grandXfer : '—' ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?= donut($grandDonePct, $deptColor, 42) ?>
                                            <div style="flex:1;min-width:0;">
                                                <div
                                                    style="background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
                                                    <div
                                                        style="width:<?= $grandDonePct ?>%;background:<?= $deptColor ?>;height:5px;border-radius:99px;">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile: card layout -->
                    <div class="d-md-none p-3">
                        <?php foreach ($staffReport as $s):
                            $doneCnt = (int) ($s[$doneKey] ?? 0);
                            $donePct = $s['total'] > 0 ? round(($doneCnt / $s['total']) * 100) : 0;
                            ?>
                            <div class="staff-card mb-3">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-circle avatar-sm" style="width:34px;height:34px;font-size:.7rem;">
                                            <?= strtoupper(substr($s['full_name'] ?? '?', 0, 2)) ?>
                                        </div>
                                        <div>
                                            <div style="font-weight:600;font-size:.88rem;">
                                                <?= htmlspecialchars($s['full_name']) ?></div>
                                            <div style="font-size:.7rem;color:#9ca3af;">
                                                <?= htmlspecialchars($s['employee_id'] ?? '') ?></div>
                                        </div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div style="font-size:1.25rem;font-weight:800;color:<?= $deptColor ?>;">
                                            <?= $s['total'] ?></div>
                                        <div style="font-size:.65rem;color:#9ca3af;">tasks</div>
                                    </div>
                                </div>
                                <!-- Status pills row -->
                                <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-bottom:.6rem;">
                                    <?php foreach ($allStatuses as $st):
                                        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                                        $cnt = (int) ($s[$safe] ?? 0);
                                        if (!$cnt)
                                            continue;
                                        $sc = $st['color'] ?: '#9ca3af';
                                        $sbg = $st['bg_color'] ?: '#f3f4f6';
                                        ?>
                                        <span style="background:<?= $sbg ?>;color:<?= $sc ?>;font-size:.65rem;
                                                 font-weight:700;padding:.12rem .42rem;border-radius:99px;">
                                            <?= htmlspecialchars($st['status_name']) ?>: <?= $cnt ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <!-- Done bar -->
                                <div style="background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
                                    <div
                                        style="width:<?= $donePct ?>%;background:<?= $deptColor ?>;height:5px;border-radius:99px;">
                                    </div>
                                </div>
                                <div style="font-size:.68rem;color:#9ca3af;margin-top:.25rem;text-align:right;"><?= $donePct ?>%
                                    done</div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            </div>

            <!-- ── BAR CHART ── -->
            <?php if (!empty($staffReport)): ?>
                <div class="chart-card mb-4">
                    <div class="chart-card-header">
                        <h5 style="margin:0;font-size:.95rem;font-weight:700;">
                            <i class="fas fa-chart-bar me-2" style="color:<?= $deptColor ?>;"></i>Staff Task Breakdown
                        </h5>
                        <span style="font-size:.7rem;color:#9ca3af;">Stacked by status</span>
                    </div>
                    <div style="padding:1.25rem;">
                        <div style="height:300px;position:relative;">
                            <canvas id="staffChart"></canvas>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php if (!empty($staffReport)): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const ctx = document.getElementById('staffChart');
            if (!ctx) return;

            const staff = <?= json_encode(array_map(function ($s) use ($allStatuses) {
                $row = ['name' => explode(' ', $s['full_name'])[0] ?? 'N/A', 'total' => (int) $s['total']];
                foreach ($allStatuses as $st) {
                    $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                    $row[$safe] = (int) ($s[$safe] ?? 0);
                }
                return $row;
            }, $staffReport)) ?>;

            const statuses = <?= json_encode(array_map(fn($st) => [
                'key' => preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name'])),
                'label' => $st['status_name'],
                'color' => $st['color'] ?: '#9ca3af',
                'bg' => $st['bg_color'] ?: '#f3f4f6',
            ], $allStatuses)) ?>;

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: staff.map(d => d.name),
                    datasets: statuses.map(st => ({
                        label: st.label,
                        data: staff.map(d => d[st.key] || 0),
                        backgroundColor: st.color,
                        borderRadius: 4,
                        borderSkipped: false,
                    }))
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { usePointStyle: true, font: { size: 11 }, padding: 16 }
                        },
                        tooltip: {
                            callbacks: {
                                footer: items => {
                                    const total = staff[items[0]?.dataIndex]?.total ?? 0;
                                    return total ? `Total: ${total}` : '';
                                }
                            }
                        }
                    },
                    scales: {
                        x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
                        y: { stacked: true, beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { stepSize: 1, font: { size: 11 } } }
                    }
                }
            });
        });
    </script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>