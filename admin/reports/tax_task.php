<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();

$db        = getDB();
$user      = currentUser();
$pageTitle = 'Tax Tasks (HTD)';
$userRole  = $user['role_name'] ?? 'admin';

// ── Access control ─────────────────────────────────────────────────────────────
// Only user id = 2 may access this page
if ((int)$user['id'] !== 2) {
    http_response_code(403);
    die('<div style="padding:2rem;font-family:sans-serif;color:#dc2626;">
            <strong>403 — Access Denied.</strong> You do not have permission to view this page.
         </div>');
}

// ── Resolve Tax dept + Hetauda branch IDs dynamically ─────────────────────────
$taxDeptRow = $db->query("
    SELECT id FROM departments WHERE dept_code = 'TAX' OR LOWER(dept_name) LIKE '%tax%' LIMIT 1
")->fetch();

$htdBranchRow = $db->query("
    SELECT id FROM branches WHERE LOWER(branch_name) LIKE '%hetauda%' LIMIT 1
")->fetch();

$taxDeptId   = (int)($taxDeptRow['id']    ?? 0);
$htdBranchId = (int)($htdBranchRow['id'] ?? 0);

// ── Filters ────────────────────────────────────────────────────────────────────
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate   = $_GET['to']   ?? date('Y-m-d');
$dateFrom = $fromDate . ' 00:00:00';
$dateTo   = $toDate   . ' 23:59:59';
$search   = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

// ── Statuses ───────────────────────────────────────────────────────────────────
$allStatuses = $db->query("
    SELECT id, status_name, color, bg_color, icon
    FROM task_status
    WHERE status_name != 'Corporate Team'
    ORDER BY id ASC
")->fetchAll();

// ── Task query — Tax dept, Hetauda branch, view only ──────────────────────────
$whereExtra = '';
$params     = [$htdBranchId, $taxDeptId, $dateFrom, $dateTo];

if ($search !== '') {
    $whereExtra .= ' AND (t.title LIKE ? OR t.task_number LIKE ? OR c.company_name LIKE ?)';
    $params[]    = "%{$search}%";
    $params[]    = "%{$search}%";
    $params[]    = "%{$search}%";
}
if ($statusFilter !== '') {
    $whereExtra .= ' AND ts.status_name = ?';
    $params[]    = $statusFilter;
}

$taskStmt = $db->prepare("
    SELECT
        t.id, t.task_number, t.title, t.due_date, t.priority, t.created_at,
        ts.status_name  AS status,
        ts.color        AS status_color,
        ts.bg_color     AS status_bg,
        ts.icon         AS status_icon,
        c.company_name,
        ua.full_name    AS assigned_name,
        ua.employee_id  AS assigned_emp_id,
        uc.full_name    AS created_name
    FROM tasks t
    LEFT JOIN task_status  ts ON ts.id = t.status_id
    LEFT JOIN companies    c  ON c.id  = t.company_id
    LEFT JOIN users        ua ON ua.id = t.assigned_to
    LEFT JOIN users        uc ON uc.id = t.created_by
    WHERE t.is_active   = 1
      AND t.branch_id     = ?
      AND t.department_id = ?
      AND t.created_at BETWEEN ? AND ?
      {$whereExtra}
    ORDER BY
        CASE WHEN t.due_date < CURDATE() AND ts.status_name != 'Done' THEN 0 ELSE 1 END,
        t.due_date ASC, t.created_at DESC
    LIMIT 500
");
$taskStmt->execute($params);
$tasks = $taskStmt->fetchAll();

// ── Status summary counts ──────────────────────────────────────────────────────
$summaryStmt = $db->prepare("
    SELECT ts.status_name, COUNT(DISTINCT t.id) AS cnt
    FROM task_status ts
    LEFT JOIN tasks t ON t.status_id = ts.id
        AND t.is_active     = 1
        AND t.branch_id     = ?
        AND t.department_id = ?
        AND t.created_at BETWEEN ? AND ?
    WHERE ts.status_name != 'Corporate Team'
    GROUP BY ts.id, ts.status_name
    ORDER BY ts.id
");
$summaryStmt->execute([$htdBranchId, $taxDeptId, $dateFrom, $dateTo]);
$summary    = array_column($summaryStmt->fetchAll(), 'cnt', 'status_name');
$totalTasks = array_sum($summary);
$doneCount  = 0;
foreach ($summary as $sn => $cnt) {
    if (strtolower($sn) === 'done') { $doneCount = $cnt; break; }
}
$completionRate = $totalTasks ? round(($doneCount / $totalTasks) * 100) : 0;

// dept color (fallback gold)
$deptColor = '#c9a84c';
$dcRow = $db->prepare("SELECT color FROM departments WHERE id = ?");
$dcRow->execute([$taxDeptId]);
$dcFetch = $dcRow->fetch();
if ($dcFetch && $dcFetch['color']) $deptColor = $dcFetch['color'];

include '../../includes/header.php';
?>

<style>
/* ── reuse report card styles ── */
.rpt-stat {
    background:#fff; border-radius:14px; border:1px solid #f3f4f6;
    padding:1.1rem 1rem 1rem; position:relative; overflow:hidden;
    transition:transform .15s,box-shadow .15s;
}
.rpt-stat:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.08);}
.rpt-stat::after{
    content:'';position:absolute;top:0;left:0;right:0;height:3px;
    border-radius:14px 14px 0 0;background:var(--sc);
}
.rpt-stat-val{font-size:2rem;font-weight:800;line-height:1;color:var(--sc);}
.rpt-stat-label{font-size:.72rem;font-weight:700;text-transform:uppercase;
                letter-spacing:.06em;color:#9ca3af;margin-top:.25rem;}
.rpt-stat-bar{background:#f3f4f6;border-radius:99px;height:4px;margin-top:.75rem;overflow:hidden;}
.rpt-stat-fill{height:100%;border-radius:99px;background:var(--sc);}
.chart-card{background:#fff;border-radius:14px;border:1px solid #f3f4f6;overflow:hidden;}
.chart-card-header{
    padding:1rem 1.25rem;border-bottom:1px solid #f3f4f6;
    display:flex;align-items:center;justify-content:space-between;
}
.rpt-filter{background:#fff;border:1px solid #e5e7eb;border-radius:12px;
            padding:1rem 1.25rem;margin-bottom:1.5rem;}
.completion-ring-card{
    background:linear-gradient(135deg,<?= $deptColor ?>10,<?= $deptColor ?>05);
    border:1px solid <?= $deptColor ?>33;border-radius:14px;padding:1.25rem;
    display:flex;align-items:center;gap:1.25rem;height:100%;
}
/* hero */
.rpt-hero{
    background:linear-gradient(135deg,#0a0f1e 0%,#111827 60%,#1a2235 100%);
    border-radius:16px;padding:1.75rem 2rem;margin-bottom:1.5rem;
    position:relative;overflow:hidden;
}
.rpt-hero::before{
    content:'';position:absolute;inset:0;
    background:radial-gradient(ellipse at 80% 50%,<?= $deptColor ?>18 0%,transparent 60%);
    pointer-events:none;
}
/* view-only badge */
.view-only-badge{
    display:inline-flex;align-items:center;gap:.35rem;
    background:#fef9ec;color:#b45309;border:1px solid #fcd34d;
    border-radius:99px;font-size:.72rem;font-weight:700;
    padding:.3rem .8rem;
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
                <div style="position:absolute;right:-20px;top:-30px;width:180px;height:180px;
                            border-radius:50%;background:<?= $deptColor ?>0d;
                            border:1px solid <?= $deptColor ?>22;"></div>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3"
                     style="position:relative;">
                    <div>
                        <div style="display:inline-flex;align-items:center;gap:.5rem;
                                    background:<?= $deptColor ?>22;border:1px solid <?= $deptColor ?>44;
                                    border-radius:99px;padding:.25rem .8rem;font-size:.72rem;
                                    font-weight:700;color:<?= $deptColor ?>;margin-bottom:.6rem;">
                            <i class="fas fa-file-invoice me-1" style="font-size:.65rem;"></i> Tax Tasks — Hetauda
                        </div>
                        <h4 style="color:#fff;font-size:1.4rem;font-weight:800;margin:0 0 .2rem;">
                            Tax Department
                            <span style="font-size:.9rem;font-weight:400;color:#6b7280;margin-left:.5rem;">·</span>
                            <span style="font-size:.9rem;font-weight:500;color:#9ca3af;margin-left:.35rem;">
                                Hetauda Branch
                            </span>
                        </h4>
                        <p style="color:#6b7280;font-size:.82rem;margin:0;">
                            <?= date('d M Y', strtotime($fromDate)) ?> — <?= date('d M Y', strtotime($toDate)) ?>
                        </p>
                    </div>
                    <div class="view-only-badge">
                        <i class="fas fa-eye" style="font-size:.65rem;"></i> View Only
                    </div>
                </div>
            </div>

            <!-- ── FILTERS ── -->
            <div class="rpt-filter">
                <form method="GET" class="row g-2 align-items-end w-100">
                    <div class="col-md-2">
                        <label class="form-label-mis">From Date</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="<?= $fromDate ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">To Date</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="<?= $toDate ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-mis">Search</label>
                        <input type="text" name="search" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($search) ?>" placeholder="Task #, title, company…">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">Status</label>
                        <select name="status" class="form-control form-control-sm">
                            <option value="">All Statuses</option>
                            <?php foreach ($allStatuses as $st): ?>
                            <option value="<?= htmlspecialchars($st['status_name']) ?>"
                                <?= $statusFilter === $st['status_name'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($st['status_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto d-flex gap-1">
                        <button type="submit" class="btn btn-gold btn-sm">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                        <a href="tax_tasks_htd.php" class="btn btn-outline-secondary btn-sm" title="Reset">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- ── STATUS SUMMARY CARDS ── -->
            <div class="row g-3 mb-4">
                <?php foreach ($allStatuses as $st):
                    $sn  = $st['status_name'];
                    $cnt = $summary[$sn] ?? 0;
                    $pct = $totalTasks ? round(($cnt / $totalTasks) * 100) : 0;
                    $sc  = $st['color']    ?: '#9ca3af';
                    $sbg = $st['bg_color'] ?: '#f3f4f6';
                    $rawI = trim($st['icon'] ?: 'fa-circle');
                    $ico  = str_starts_with($rawI, 'fa') ? $rawI : 'fa-' . $rawI;
                ?>
                <div class="col-6 col-md-3 col-xl-2">
                    <div class="rpt-stat" style="--sc:<?= $sc ?>;">
                        <div style="position:absolute;top:.85rem;right:.85rem;width:30px;height:30px;
                                    border-radius:8px;background:<?= $sbg ?>;
                                    display:flex;align-items:center;justify-content:center;">
                            <i class="fas <?= htmlspecialchars($ico) ?>" style="font-size:.75rem;color:<?= $sc ?>;"></i>
                        </div>
                        <div class="rpt-stat-val"><?= $cnt ?></div>
                        <div class="rpt-stat-label"><?= htmlspecialchars($sn) ?></div>
                        <div style="font-size:.68rem;color:#9ca3af;margin-top:.2rem;"><?= $pct ?>% of total</div>
                        <div class="rpt-stat-bar"><div class="rpt-stat-fill" style="width:<?= $pct ?>%;"></div></div>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Total card -->
                <div class="col-6 col-md-3 col-xl-2">
                    <div class="rpt-stat" style="--sc:<?= $deptColor ?>;">
                        <div style="position:absolute;top:.85rem;right:.85rem;width:30px;height:30px;
                                    border-radius:8px;background:<?= $deptColor ?>18;
                                    display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-layer-group" style="font-size:.75rem;color:<?= $deptColor ?>;"></i>
                        </div>
                        <div class="rpt-stat-val"><?= $totalTasks ?></div>
                        <div class="rpt-stat-label">Total Tasks</div>
                        <div style="font-size:.68rem;color:#9ca3af;margin-top:.2rem;">Tax · Hetauda</div>
                        <div class="rpt-stat-bar"><div class="rpt-stat-fill" style="width:100%;"></div></div>
                    </div>
                </div>

                <!-- Completion ring -->
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="completion-ring-card">
                        <?php
                        $rc    = 48; $rcirc = round(2 * M_PI * $rc, 2);
                        $rdsh  = round($rcirc * $completionRate / 100, 2);
                        ?>
                        <div style="position:relative;flex-shrink:0;">
                            <svg width="110" height="110" viewBox="0 0 110 110">
                                <circle cx="55" cy="55" r="<?= $rc ?>" fill="none" stroke="#e5e7eb" stroke-width="8"/>
                                <circle cx="55" cy="55" r="<?= $rc ?>" fill="none" stroke="<?= $deptColor ?>"
                                        stroke-width="8" stroke-dasharray="<?= $rdsh ?> <?= $rcirc ?>"
                                        stroke-linecap="round" transform="rotate(-90 55 55)"/>
                            </svg>
                            <div style="position:absolute;inset:0;display:flex;flex-direction:column;
                                        align-items:center;justify-content:center;">
                                <div style="font-size:1.4rem;font-weight:800;color:<?= $deptColor ?>;line-height:1;">
                                    <?= $completionRate ?>%
                                </div>
                                <div style="font-size:.58rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">done</div>
                            </div>
                        </div>
                        <div>
                            <div style="font-size:.95rem;font-weight:700;color:#1f2937;">Completion Rate</div>
                            <div style="font-size:.75rem;color:#9ca3af;margin-top:.2rem;">
                                <?= $doneCount ?> of <?= $totalTasks ?> tasks completed
                            </div>
                            <div class="view-only-badge mt-2">
                                <i class="fas fa-lock" style="font-size:.6rem;"></i> Read-only access
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── TASK TABLE ── -->
            <div class="chart-card mb-4">
                <div class="chart-card-header">
                    <div>
                        <h5 style="margin:0;font-size:.95rem;font-weight:700;">
                            <i class="fas fa-list-check me-2" style="color:<?= $deptColor ?>;"></i>
                            Tax Tasks — Hetauda Branch
                            <span style="font-size:.72rem;color:#9ca3af;font-weight:400;margin-left:.4rem;">
                                (<?= count($tasks) ?>)
                            </span>
                        </h5>
                        <small style="color:#9ca3af;">
                            <?= date('d M Y', strtotime($fromDate)) ?> — <?= date('d M Y', strtotime($toDate)) ?>
                        </small>
                    </div>
                    <span class="view-only-badge">
                        <i class="fas fa-eye" style="font-size:.6rem;"></i> View Only · No Edit
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table-mis w-100" style="font-size:.78rem;">
                        <thead>
                            <tr>
                                <th style="width:90px;">Task #</th>
                                <th>Title</th>
                                <th style="min-width:120px;">Company</th>
                                <th style="min-width:110px;">Assigned To</th>
                                <th style="width:90px;">Status</th>
                                <th style="width:70px;">Priority</th>
                                <th style="width:90px;">Due Date</th>
                                <th style="width:90px;">Created</th>
                                <th style="width:50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tasks)): ?>
                            <tr>
                                <td colspan="9" class="empty-state">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block" style="color:#d1d5db;"></i>
                                    No tasks found for Tax dept · Hetauda in this period.
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($tasks as $idx => $t):
                                $overdue = $t['due_date']
                                    && strtotime($t['due_date']) < time()
                                    && strtolower($t['status']) !== 'done';
                                $sc  = $t['status_color'] ?: '#9ca3af';
                                $sbg = $t['status_bg']    ?: '#f3f4f6';
                                $odd = $idx % 2 === 0;
                            ?>
                            <tr style="<?= $overdue ? 'background:#fef2f2;' : ($odd ? '' : 'background:#fafafa;') ?>">
                                <td>
                                    <span class="task-number"><?= htmlspecialchars($t['task_number']) ?></span>
                                    <?php if ($overdue): ?>
                                        <div style="font-size:.6rem;color:#ef4444;font-weight:700;margin-top:.15rem;">
                                            <i class="fas fa-exclamation-triangle me-1"></i>OVERDUE
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width:180px;white-space:nowrap;overflow:hidden;
                                           text-overflow:ellipsis;font-weight:500;color:#1f2937;">
                                    <?= htmlspecialchars($t['title']) ?>
                                </td>
                                <td style="font-size:.75rem;color:#6b7280;">
                                    <?= htmlspecialchars($t['company_name'] ?? '—') ?>
                                </td>
                                <td>
                                    <?php if ($t['assigned_name']): ?>
                                    <div style="display:flex;align-items:center;gap:.4rem;">
                                        <div style="width:24px;height:24px;border-radius:50%;flex-shrink:0;
                                                    background:<?= $deptColor ?>22;color:<?= $deptColor ?>;
                                                    font-size:.6rem;font-weight:700;display:flex;
                                                    align-items:center;justify-content:center;">
                                            <?= strtoupper(substr($t['assigned_name'], 0, 2)) ?>
                                        </div>
                                        <div>
                                            <div style="font-size:.75rem;font-weight:600;color:#374151;">
                                                <?= htmlspecialchars($t['assigned_name']) ?>
                                            </div>
                                            <div style="font-size:.65rem;color:#9ca3af;">
                                                <?= htmlspecialchars($t['assigned_emp_id'] ?? '') ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                        <span style="color:#d1d5db;font-size:.72rem;">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="background:<?= $sbg ?>;color:<?= $sc ?>;
                                                 padding:.2rem .55rem;border-radius:99px;
                                                 font-size:.7rem;font-weight:600;white-space:nowrap;">
                                        <?= htmlspecialchars($t['status'] ?? '—') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge priority-<?= $t['priority'] ?>">
                                        <?= ucfirst($t['priority']) ?>
                                    </span>
                                </td>
                                <td style="font-size:.75rem;<?= $overdue ? 'color:#ef4444;font-weight:600;' : 'color:#9ca3af;' ?>">
                                    <?= $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—' ?>
                                </td>
                                <td style="font-size:.72rem;color:#9ca3af;">
                                    <?= date('d M Y', strtotime($t['created_at'])) ?>
                                </td>
                                <td>
                                    <!-- VIEW ONLY — no edit/delete buttons -->
                                    <a href="<?= APP_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>"
                                       class="btn btn-sm btn-outline-secondary"
                                       title="View Task">
                                        <i class="fas fa-eye" style="font-size:.7rem;"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($tasks)): ?>
                <div style="padding:.75rem 1.25rem;border-top:1px solid #f3f4f6;
                            font-size:.72rem;color:#9ca3af;display:flex;
                            align-items:center;justify-content:space-between;">
                    <span>Showing <?= count($tasks) ?> task<?= count($tasks) !== 1 ? 's' : '' ?></span>
                    <span class="view-only-badge">
                        <i class="fas fa-shield-alt" style="font-size:.6rem;"></i>
                        Read-only · Edits must be made from the Tax department
                    </span>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /padding -->
    </div><!-- /main-content -->
</div><!-- /app-wrapper -->

<?php include '../../includes/footer.php'; ?>