<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$pageTitle = 'Department-wise Report';

$statusRows = $db->query("
    SELECT id, status_name, color, bg_color, icon
    FROM task_status
    ORDER BY id ASC
")->fetchAll();

$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate   = $_GET['to']   ?? date('Y-m-d');

// Dynamic CASE columns
$statusCases = '';
foreach ($statusRows as $sr) {
    $sn = addslashes($sr['status_name']);
    $statusCases .= "SUM(CASE WHEN ts.status_name = '{$sn}' THEN 1 ELSE 0 END) AS `s_{$sr['id']}`,\n";
}

$deptStmt = $db->prepare("
    SELECT d.id as dept_id, d.dept_name, d.color, d.icon,
           COUNT(t.id) as total,
           {$statusCases}
           SUM(CASE WHEN t.due_date < CURDATE()
               AND ts.status_name != 'Done' THEN 1 ELSE 0 END) as overdue
    FROM departments d
    LEFT JOIN tasks t        ON t.department_id = d.id
        AND t.is_active = 1
        AND t.created_at BETWEEN ? AND ?
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE d.is_active = 1 AND d.dept_name != 'Core Admin'
    GROUP BY d.id, d.dept_name, d.color, d.icon
    ORDER BY total DESC
");
$deptStmt->execute([$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
$deptReport = $deptStmt->fetchAll();

// Find Done id for % calc
$doneId = null;
foreach ($statusRows as $sr) {
    if (strtolower($sr['status_name']) === 'done') { $doneId = $sr['id']; break; }
}

$chartMeta = array_values(array_map(fn($sr) => [
    'label' => $sr['status_name'],
    'color' => $sr['color'] ?: '#9ca3af',
    'key'   => 's_' . $sr['id'],
], $statusRows));

include '../../includes/header.php';
?>
<style>
/* Clickable status tile */
a.status-tile {
    display: block;
    text-decoration: none;
    border-radius: 10px;
    padding: .6rem .4rem;
    transition: transform .15s, box-shadow .15s;
    border: 1px solid transparent;
}
a.status-tile:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(0,0,0,.1);
}

/* Dept card layout */
.dept-card-body { padding: 1.25rem 1.4rem; }
.dept-card-stat-row {
    display: grid;
    gap: .5rem;
    margin-top: .9rem;
}
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <div class="page-hero">
                <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-layer-group"></i> Reports</div>
                        <h4>Department-wise Performance</h4>
                        <p><?= date('d M Y', strtotime($fromDate)) ?> — <?= date('d M Y', strtotime($toDate)) ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="index.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=department_wise&from=<?= $fromDate ?>&to=<?= $toDate ?>"
                            class="btn btn-success btn-sm">
                            <i class="fas fa-file-excel me-1"></i>Export Excel
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=department_wise&from=<?= $fromDate ?>&to=<?= $toDate ?>"
                            class="btn btn-danger btn-sm">
                            <i class="fas fa-file-pdf me-1"></i>Export PDF
                        </a>
                    </div>
                </div>
            </div>

            <?= flashHtml() ?>

            <!-- Filter -->
            <div class="filter-bar mb-4 w-100">
                <form method="GET" class="row g-3 align-items-end w-100">
                    <div class="col-md-2">
                        <label class="form-label-mis">From</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="<?= $fromDate ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">To</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="<?= $toDate ?>">
                    </div>
                    <div class="col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-gold btn-sm w-100">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="department_wise.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- ── Department Cards ── -->
            <?php foreach ($deptReport as $d):
                $doneVal = $doneId ? (int)($d['s_' . $doneId] ?? 0) : 0;
                $pct     = $d['total'] > 0 ? round(($doneVal / $d['total']) * 100) : 0;
                $r = 30; $circ = round(2 * M_PI * $r); $dash = round($circ * $pct / 100);
                // How many status cols → determine grid columns
                $tileCols = count($statusRows) + 1; // +1 for overdue
            ?>
                <div class="card-mis mb-4" style="border-left: 4px solid <?= htmlspecialchars($d['color']) ?>;">
                    <div class="dept-card-body">

                        <!-- Dept header -->
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:48px;height:48px;border-radius:12px;
                                            background:<?= htmlspecialchars($d['color']) ?>22;
                                            color:<?= htmlspecialchars($d['color']) ?>;
                                            display:flex;align-items:center;justify-content:center;
                                            font-size:1.1rem;flex-shrink:0;">
                                    <i class="fas <?= htmlspecialchars($d['icon'] ?? 'fa-briefcase') ?>"></i>
                                </div>
                                <div>
                                    <div style="font-weight:700;font-size:1.05rem;color:#1f2937;">
                                        <?= htmlspecialchars($d['dept_name']) ?>
                                    </div>
                                    <div style="font-size:.75rem;color:#9ca3af;margin-top:.15rem;">
                                        <span style="font-weight:600;color:#1f2937;"><?= $d['total'] ?></span> total tasks
                                        <?php if ($d['overdue'] > 0): ?>
                                            &nbsp;·&nbsp;
                                            <span style="color:#ef4444;font-weight:600;">
                                                <i class="fas fa-clock" style="font-size:.65rem;"></i>
                                                <?= $d['overdue'] ?> overdue
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Donut + % done -->
                            <div class="d-flex align-items-center gap-3">
                                <div style="text-align:right;">
                                    <div style="font-size:1.6rem;font-weight:800;color:<?= htmlspecialchars($d['color']) ?>;line-height:1;">
                                        <?= $pct ?>%
                                    </div>
                                    <div style="font-size:.7rem;color:#9ca3af;">completed</div>
                                </div>
                                <div style="position:relative;">
                                    <svg width="68" height="68" viewBox="0 0 68 68">
                                        <circle cx="34" cy="34" r="<?= $r ?>" fill="none" stroke="#f3f4f6" stroke-width="6"/>
                                        <circle cx="34" cy="34" r="<?= $r ?>" fill="none"
                                                stroke="<?= htmlspecialchars($d['color']) ?>" stroke-width="6"
                                                stroke-dasharray="<?= $dash ?> <?= $circ ?>"
                                                stroke-linecap="round"
                                                transform="rotate(-90 34 34)"/>
                                    </svg>
                                    <div style="position:absolute;top:50%;left:50%;
                                                transform:translate(-50%,-50%);
                                                font-size:.62rem;font-weight:700;
                                                color:<?= htmlspecialchars($d['color']) ?>;">
                                        <?= $doneVal ?>/<?= $d['total'] ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Completion bar -->
                        <?php if ($d['total'] > 0): ?>
                            <div style="background:#f3f4f6;border-radius:99px;height:6px;overflow:hidden;margin-bottom:1rem;">
                                <div style="width:<?= $pct ?>%;background:<?= htmlspecialchars($d['color']) ?>;
                                            height:100%;border-radius:99px;transition:width .4s;"></div>
                            </div>
                        <?php endif; ?>

                        <!-- Status tiles — clickable, link to tasks filtered by dept + status -->
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:.6rem;">

                            <?php foreach ($statusRows as $sr):
                                $colKey  = 's_' . $sr['id'];
                                $val     = (int)($d[$colKey] ?? 0);
                                $color   = $sr['color']    ?: '#9ca3af';
                                $bg      = $sr['bg_color'] ?: '#f3f4f6';
                                $rawIco  = trim($sr['icon'] ?: 'fa-circle');
                                $iClass  = str_starts_with($rawIco, 'fa') ? $rawIco : 'fa-' . $rawIco;
                                $tilePct = $d['total'] > 0 ? round(($val / $d['total']) * 100) : 0;
                                // Link → executive tasks list filtered by dept + status
                                $tileLink = APP_URL . '/executive/tasks/index.php?'
                                    . http_build_query([
                                        'dept'   => $d['dept_id'],
                                        'status' => $sr['status_name'],
                                    ]);
                            ?>
                                <a href="<?= $tileLink ?>" class="status-tile"
                                   style="background:<?= htmlspecialchars($bg) ?>;
                                          border-color:<?= htmlspecialchars($color) ?>33;"
                                   title="View <?= htmlspecialchars($sr['status_name']) ?> tasks in <?= htmlspecialchars($d['dept_name']) ?>">
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem;">
                                        <i class="fas <?= htmlspecialchars($iClass) ?>"
                                           style="font-size:.75rem;color:<?= htmlspecialchars($color) ?>;"></i>
                                        <span style="font-size:.6rem;color:#9ca3af;font-weight:600;"><?= $tilePct ?>%</span>
                                    </div>
                                    <div style="font-size:1.35rem;font-weight:800;color:<?= htmlspecialchars($color) ?>;line-height:1.1;">
                                        <?= $val ?>
                                    </div>
                                    <div style="font-size:.65rem;color:#6b7280;margin-top:.2rem;font-weight:600;
                                                white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= htmlspecialchars($sr['status_name']) ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>

                            <!-- Overdue tile -->
                            <?php
                                $overdueLink = APP_URL . '/executive/tasks/index.php?'
                                    . http_build_query(['dept' => $d['dept_id'], 'overdue' => 1]);
                                $overduePct  = $d['total'] > 0 ? round(($d['overdue'] / $d['total']) * 100) : 0;
                            ?>
                            <a href="<?= $overdueLink ?>" class="status-tile"
                               style="background:#fef2f2;border-color:#fecaca;"
                               title="View overdue tasks in <?= htmlspecialchars($d['dept_name']) ?>">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem;">
                                    <i class="fas fa-triangle-exclamation" style="font-size:.75rem;color:#dc2626;"></i>
                                    <span style="font-size:.6rem;color:#9ca3af;font-weight:600;"><?= $overduePct ?>%</span>
                                </div>
                                <div style="font-size:1.35rem;font-weight:800;color:#dc2626;line-height:1.1;">
                                    <?= $d['overdue'] ?>
                                </div>
                                <div style="font-size:.65rem;color:#6b7280;margin-top:.2rem;font-weight:600;">
                                    Overdue
                                </div>
                            </a>

                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($deptReport)): ?>
                <div class="card-mis text-center py-5" style="color:#9ca3af;">
                    <i class="fas fa-layer-group fa-2x mb-2 d-block"></i>
                    No department data for this period.
                </div>
            <?php endif; ?>

            <!-- ── Bar Chart — stacked, no line ── -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-chart-bar text-warning me-2"></i>Department Comparison</h5>
                    <small class="text-muted">Stacked by status</small>
                </div>
                <div class="card-mis-body">
                    <div style="height:320px;position:relative;">
                        <canvas id="deptChart"></canvas>
                    </div>
                </div>
            </div>

        </div>
        <?php include '../../includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const data = <?= json_encode(array_values($deptReport)) ?>;
    const meta = <?= json_encode($chartMeta) ?>;

    // Pure stacked bar — no line, no secondary axis
    const datasets = meta.map(m => ({
        label:           m.label,
        data:            data.map(d => parseInt(d[m.key] ?? 0)),
        backgroundColor: m.color,
        borderRadius:    3,
        borderSkipped:   false,
    }));

    new Chart(document.getElementById('deptChart'), {
        type: 'bar',
        data: {
            labels:   data.map(d => d.dept_name),
            datasets: datasets,
        },
        options: {
            responsive:          true,
            maintainAspectRatio: false,
            interaction:         { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { usePointStyle: true, font: { size: 11 }, padding: 16 }
                },
                tooltip: {
                    callbacks: {
                        footer: function(items) {
                            const idx   = items[0]?.dataIndex;
                            const total = parseInt(data[idx]?.total ?? 0);
                            const done  = parseInt(data[idx]?.['<?= $doneId ? "s_{$doneId}" : "" ?>'] ?? 0);
                            const pct   = total > 0 ? Math.round((done / total) * 100) : 0;
                            return total ? `Total: ${total}  |  ${pct}% done` : '';
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid:    { display: false },
                    ticks:   { font: { size: 11 } }
                },
                y: {
                    stacked:      true,
                    beginAtZero:  true,
                    grid:         { color: '#f3f4f6' },
                    ticks:        { font: { size: 11 }, stepSize: 1 },
                    title:        { display: true, text: 'Tasks', font: { size: 11 }, color: '#9ca3af' }
                }
            }
        }
    });
});
</script>