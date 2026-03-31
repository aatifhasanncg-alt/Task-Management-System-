<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';

requireExecutive();

$db = getDB();
$pageTitle = 'Branch-wise Report';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

$branches = $db->query("SELECT * FROM branches WHERE is_active=1 ORDER BY is_head_office DESC, branch_name")->fetchAll();
$statusRows = $db->query("
    SELECT id, status_name, color, bg_color, icon
    FROM task_status
    ORDER BY id ASC
")->fetchAll();

$branchStats = [];
foreach ($branches as $b) {
    $bId = $b['id'];

    $st = $db->prepare("
        SELECT ts.status_name, COUNT(t.id) as cnt
        FROM tasks t
        JOIN task_status ts ON ts.id = t.status_id
        WHERE t.branch_id = ? AND t.is_active = 1
          AND t.created_at BETWEEN ? AND ?
        GROUP BY ts.id, ts.status_name
    ");
    $st->execute([$bId, $from . ' 00:00:00', $to . ' 23:59:59']);
    $byStatus = array_column($st->fetchAll(), 'cnt', 'status_name');

    // ✅ Calculate total from the status counts
    $total = array_sum($byStatus);

    $deptBreak = $db->prepare("
        SELECT d.dept_name, d.color, COUNT(t.id) as cnt
        FROM tasks t LEFT JOIN departments d ON d.id = t.department_id
        WHERE t.branch_id = ? AND t.is_active = 1
          AND t.created_at BETWEEN ? AND ?
        GROUP BY t.department_id ORDER BY cnt DESC
    ");
    $deptBreak->execute([$bId, $from . ' 00:00:00', $to . ' 23:59:59']);

    $branchStats[] = array_merge($b, [
        'byStatus' => $byStatus,
        'total'    => $total,
        'depts'    => $deptBreak->fetchAll(),
    ]);
}

include '../../includes/header.php';
?>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <div class="page-hero">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-map-marker-alt"></i> Branch Report</div>
                        <h4>Branch-wise Task Report</h4>
                        <p><?= date('d M Y', strtotime($from)) ?> — <?= date('d M Y', strtotime($to)) ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="index.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=branch_wise&from=<?= $from ?>&to=<?= $to ?>"
                            class="btn btn-success btn-sm">
                            <i class="fas fa-file-excel me-1"></i>Export Excel
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=branch_wise&from=<?= $from ?>&to=<?= $to ?>"
                            class="btn btn-danger btn-sm">
                            <i class="fas fa-file-pdf me-1"></i>Export PDF
                        </a>
                    </div>
                </div>
            </div>

            <!-- Date Filter -->
            <div class="filter-bar mb-4 w-100">
                <form method="GET" class="row g-3 align-items-end w-100">
                    <div class="col-md-4">
                        <label class="form-label-mis">From</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="<?= $from ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-mis">To</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="<?= $to ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">&nbsp;</label>
                        <button type="submit" class="btn btn-gold btn-sm w-100">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                    </div>
                </form>
            </div>

            <!-- Branch Cards -->
            <?php foreach ($branchStats as $b): ?>
                <div class="card-mis mb-4">
                    <div class="card-mis-header">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fas <?= $b['is_head_office'] ? 'fa-building-columns' : 'fa-map-marker-alt' ?> text-warning"></i>
                            <h5><?= htmlspecialchars($b['branch_name']) ?></h5>
                            <?php if ($b['is_head_office']): ?>
                                <span style="font-size:.68rem;background:#fefce8;color:#ca8a04;border-radius:99px;
                                             padding:.15rem .55rem;font-weight:700;">Head Office</span>
                            <?php endif; ?>
                        </div>
                        <span class="fw-bold" style="font-size:1.1rem;color:#c9a84c;">
                            <?= $b['total'] ?> task<?= $b['total'] != 1 ? 's' : '' ?>
                        </span>
                    </div>

                    <?php if ($b['total'] === 0): ?>
                        <div class="card-mis-body text-center py-4" style="color:#9ca3af;font-size:.83rem;">
                            <i class="fas fa-inbox mb-2 d-block fa-lg"></i>No tasks in this date range.
                        </div>
                    <?php else: ?>
                        <div class="card-mis-body">
                            <div class="row g-3">

                                <!-- Status breakdown -->
                                <div class="col-md-5">
                                    <div class="section-divider">By Status</div>
                                    <?php foreach ($statusRows as $sr):
                                        $cnt    = $b['byStatus'][$sr['status_name']] ?? 0;
                                        $pct    = $b['total'] ? round(($cnt / $b['total']) * 100) : 0;
                                        $color  = $sr['color']    ?: '#9ca3af';
                                        $bg     = $sr['bg_color'] ?: '#f3f4f6';
                                        $rawIco = trim($sr['icon'] ?: 'fa-circle');
                                        $iClass = str_starts_with($rawIco, 'fa') ? $rawIco : 'fa-' . $rawIco;
                                    ?>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span style="width:115px;font-size:.71rem;font-weight:700;flex-shrink:0;
                                                         display:inline-flex;align-items:center;gap:.35rem;
                                                         background:<?= htmlspecialchars($bg) ?>;
                                                         color:<?= htmlspecialchars($color) ?>;
                                                         padding:.2rem .55rem;border-radius:99px;justify-content:center;">
                                                <i class="fas <?= htmlspecialchars($iClass) ?>" style="font-size:.58rem;"></i>
                                                <?= htmlspecialchars($sr['status_name']) ?>
                                            </span>
                                            <div style="flex:1;background:#f3f4f6;border-radius:50px;height:6px;overflow:hidden;">
                                                <div style="width:<?= $pct ?>%;background:<?= htmlspecialchars($color) ?>;height:6px;border-radius:50px;transition:width .3s;"></div>
                                            </div>
                                            <span style="font-size:.78rem;font-weight:600;width:36px;text-align:right;color:#1f2937;"><?= $cnt ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Dept breakdown -->
                                <div class="col-md-4">
                                    <div class="section-divider">By Department</div>
                                    <?php if (empty($b['depts'])): ?>
                                        <p style="font-size:.78rem;color:#9ca3af;">No department data.</p>
                                    <?php endif; ?>
                                    <?php foreach ($b['depts'] as $d):
                                        $pct = $b['total'] ? round(($d['cnt'] / $b['total']) * 100) : 0;
                                    ?>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <div style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($d['color'] ?? '#ccc') ?>;flex-shrink:0;"></div>
                                            <span style="font-size:.82rem;flex:1;"><?= htmlspecialchars($d['dept_name'] ?? '—') ?></span>
                                            <div style="flex:1;background:#f3f4f6;border-radius:50px;height:4px;overflow:hidden;">
                                                <div style="width:<?= $pct ?>%;background:<?= htmlspecialchars($d['color'] ?? '#ccc') ?>;height:4px;border-radius:50px;"></div>
                                            </div>
                                            <span style="font-size:.82rem;font-weight:600;width:28px;text-align:right;"><?= $d['cnt'] ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Contact info -->
                                <div class="col-md-3">
                                    <div class="section-divider">Contact</div>
                                    <div style="font-size:.82rem;color:#6b7280;">
                                        <div class="mb-1">
                                            <i class="fas fa-map-marker-alt me-2 text-warning"></i>
                                            <?= htmlspecialchars($b['address'] ?? '—') ?>
                                        </div>
                                        <div class="mb-1">
                                            <i class="fas fa-phone me-2 text-warning"></i>
                                            <?= htmlspecialchars($b['phone'] ?? '—') ?>
                                        </div>
                                        <div>
                                            <i class="fas fa-envelope me-2 text-warning"></i>
                                            <?= htmlspecialchars($b['email'] ?? '—') ?>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

        </div>
        <?php include '../../includes/footer.php'; ?>
    </div>
</div>