<?php
/**
 * consulting/log_list.php — Admin: All Visit Logs
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAdmin();

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];

$deptId   = (int)$user['department_id'];

$branchId = (int)$user['branch_id'];
// ── UDA consulting dept detection ─────────────────────────────
$__deptMetaQ = $db->prepare("SELECT dept_code, dept_name FROM departments WHERE id = ?");
$__deptMetaQ->execute([$user['department_id']]);
$__deptMeta = $__deptMetaQ->fetch(PDO::FETCH_ASSOC);
$__primaryCode = $__deptMeta['dept_code'] ?? '';
$__isConsPrimary = ($__primaryCode === 'CON' || stripos($__deptMeta['dept_name'] ?? '', 'consult') !== false);
$__isCoreAdmin = ($__primaryCode === 'CORE');

$__udaQ = $db->prepare("
    SELECT d.id, d.dept_code FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ? AND (d.dept_code = 'CON' OR d.dept_name LIKE '%consult%')
    LIMIT 1
");
$__udaQ->execute([$uid]);
$__udaCons = $__udaQ->fetch(PDO::FETCH_ASSOC);

if ($__isConsPrimary) {
    $deptId = (int) $user['department_id'];
} elseif ($__isCoreAdmin && $__udaCons) {
    $deptId = (int) $__udaCons['id'];
} elseif ($__udaCons) {
    $deptId = (int) $__udaCons['id'];
}
// $branchId stays unchanged — always use user's actual branch
$now        = new DateTime();
$month      = $_GET['month'] ?? $now->format('Y-m');
$monthDate  = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthLabel = $monthDate->format('F Y');

$currentRole = $_SESSION['role'] ?? ($user['role'] ?? '');
$isAdmin = in_array($currentRole, ['admin', 'executive', 'superadmin']);

if ($isAdmin) {
    $scopeRows = $db->query("
        SELECT DISTINCT u.id FROM users u
        WHERE u.is_active = 1
          AND (
              u.id = {$uid}
              OR u.id IN (
                  SELECT u2.id FROM users u2
                  JOIN departments d ON d.id = u2.department_id AND d.dept_code = 'CON'
                  WHERE u2.is_active = 1
                  UNION
                  SELECT uda.user_id FROM user_department_assignments uda
                  JOIN departments d ON d.id = uda.department_id AND d.dept_code = 'CON'
              )
          )
    ")->fetchAll(PDO::FETCH_COLUMN);
    $scopeIds = array_unique(array_merge([$uid], $scopeRows));
} else {
    $scopeIds = [$uid];
}
$inList = implode(',', array_map('intval', $scopeIds)) ?: '0';

// Filters
$staffFilter  = (int)($_GET['staff_id']  ?? 0);
$statusFilter = $_GET['visit_status']    ?? '';


$params = [];

$where = "wl.month_year=? AND wl.department_id=? AND wl.user_id IN ({$inList})";
$params[] = $month;
$params[] = $deptId;

if ($staffFilter) {
    $where .= " AND wl.user_id=?";
    $params[] = $staffFilter;
}

if ($statusFilter) {
    $where .= " AND wl.visit_status=?";
    $params[] = $statusFilter;
}
$stmt = $db->prepare("
(
    SELECT 
        wl.id,
        wl.user_id,
        wl.log_date,
        wl.time_in,
        wl.time_out,
        wl.duration_hours,
        wl.visit_status AS status,
        wl.work_description AS description,
        c.company_name,
        c.company_code,
        u.full_name AS staff_name,
        u.employee_id,
        'VISIT' AS log_type,
        wl.day_of_week
    FROM work_logs wl
    LEFT JOIN companies c ON c.id = wl.client_id
    LEFT JOIN users u ON u.id = wl.user_id
    WHERE wl.month_year = ?
)

UNION ALL

(
    SELECT 
        owl.id,
        owl.user_id,
        owl.log_date,
        owl.time_in,
        owl.time_out,
        ROUND(TIME_TO_SEC(TIMEDIFF(owl.time_out, owl.time_in)) / 3600, 2) AS duration_hours,
        owl.status,
        owl.description,
        c.company_name,
        c.company_code,
        u.full_name AS staff_name,
        u.employee_id,
        'OFFICE' AS log_type,
        NULL AS day_of_week
    FROM office_work_logs owl
    LEFT JOIN companies c ON c.id = owl.client_id
    LEFT JOIN users u ON u.id = owl.user_id
    WHERE DATE_FORMAT(owl.log_date, '%Y-%m') = ?
)

ORDER BY log_date DESC, time_in DESC
");

$stmt->execute([$month, $month]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Staff list for filter
$st1 = $db->prepare("
    SELECT DISTINCT u.id, u.full_name, u.employee_id
    FROM users u
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    WHERE u.is_active = 1
      AND u.branch_id = ?
      AND (
          u.department_id = ?
          OR uda.department_id = ?
      )
    ORDER BY u.full_name
");
$st1->execute([$branchId, $deptId, $deptId]);
$deptStaff = $st1->fetchAll(PDO::FETCH_ASSOC);
// KPIs
$stmtKpi = $db->prepare("
    SELECT
        COUNT(*) AS total_logs,
        COALESCE(SUM(duration_hours),0) AS total_hours,
        COUNT(DISTINCT client_id) AS unique_clients,
        SUM(visit_status='visited') AS visited,
        SUM(visit_status='missed') AS missed,
        SUM(visit_status='rescheduled') AS rescheduled
    FROM work_logs wl
    WHERE {$where}
");

$stmtKpi->execute($params);
$kpi = $stmtKpi->fetch();
$pageTitle = 'All Visit Logs';
include '../../includes/header.php';

function vstBadge($s): string {
    $s = $s ?? 'unknown';

    $map = [
        'visited'     => ['#ecfdf5','#10b981','fa-check-circle','Visited'],
        'missed'      => ['#fef2f2','#ef4444','fa-times-circle','Missed'],
        'rescheduled' => ['#fffbeb','#f59e0b','fa-redo','Rescheduled'],
        'wip'         => ['#eff6ff','#3b82f6','fa-spinner','WIP'],
        'completed'   => ['#f0fdf4','#10b981','fa-check','Completed'],
        'unknown'     => ['#f3f4f6','#9ca3af','fa-circle','—'],
    ];

    [$bg,$col,$ico,$lbl] = $map[$s] ?? $map['unknown'];

    return "<span style='background:$bg;color:$col;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;'>
        <i class='fas $ico'></i>$lbl
    </span>";
}
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <!-- ── Hero ──────────────────────────────────────────────────── -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-history"></i> Logs</div>
                        <h4>All Visit Logs</h4>
                        <p>
                            <?= htmlspecialchars($user['full_name']) ?> ·
                            Department view
                            · <?= $monthLabel ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="index.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=consulting_performance&view=who&month=<?= urlencode($month) ?>&staff_id=<?= $staffFilter ?>&visit_status=<?= urlencode($statusFilter) ?>"
                            class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-file-pdf me-1" style="color:#ef4444;"></i>PDF
                            </a>
                            <a href="<?= APP_URL ?>/exports/export_excel.php?module=consulting_performance&view=who&month=<?= urlencode($month) ?>&staff_id=<?= $staffFilter ?>&visit_status=<?= urlencode($statusFilter) ?>"
                            class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-file-excel me-1" style="color:#10b981;"></i>Excel
                            </a>
                    </div>
                </div>
            </div>

            <!-- ── Filter Bar ────────────────────────────────────────────── -->
            <div class="card-mis mb-4">
                <div class="card-mis-body p-0" style="padding:1rem!important;">
                    <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">
                        <div>
                            <label style="display:block;font-size:.75rem;font-weight:700;color:#9ca3af;margin-bottom:.35rem;">Month</label>
                            <input type="month" class="form-control form-control-sm" style="width:155px;" value="<?= $month ?>"
                                   onchange="applyFilter()">
                        </div>
                        <div>
                            <label style="display:block;font-size:.75rem;font-weight:700;color:#9ca3af;margin-bottom:.35rem;">Staff</label>
                            <select id="fStaff" class="form-control form-control-sm" style="width:180px;" onchange="applyFilter()">
                                <option value="">All Staff</option>
                                <?php foreach ($deptStaff as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $staffFilter == $s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['full_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-size:.75rem;font-weight:700;color:#9ca3af;margin-bottom:.35rem;">Status</label>
                            <select id="fStatus" class="form-control form-control-sm" style="width:150px;" onchange="applyFilter()">
                                <option value="">All Status</option>
                                <option value="visited"     <?= $statusFilter==='visited'     ? 'selected' : '' ?>>✅ Visited</option>
                                <option value="missed"      <?= $statusFilter==='missed'      ? 'selected' : '' ?>>❌ Missed</option>
                                <option value="rescheduled" <?= $statusFilter==='rescheduled' ? 'selected' : '' ?>>🔄 Rescheduled</option>
                            </select>
                        </div>
                        <button class="btn btn-outline-secondary btn-sm" onclick="clearFilter()">
                            <i class="fas fa-times me-1"></i> Clear
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── KPI Cards ─────────────────────────────────────────── -->
            <div class="row g-3 mb-4">
                <?php
                $kpiCards = [
                    ['fa-list',           '#3b82f6','#eff6ff', 'Total Logs',       (int)($kpi['total_logs'] ?? 0)],
                    ['fa-clock',          '#c9a84c','#fefce8', 'Total Hours',      number_format((float)($kpi['total_hours'] ?? 0), 1) . 'h'],
                    ['fa-building',       '#8b5cf6','#f5f3ff', 'Clients',          (int)($kpi['unique_clients'] ?? 0)],
                    ['fa-check-circle',   '#10b981','#ecfdf5', 'Visited',          (int)($kpi['visited'] ?? 0)],
                    ['fa-times-circle',   '#ef4444','#fef2f2', 'Missed',           (int)($kpi['missed'] ?? 0)],
                    ['fa-redo',           '#f59e0b','#fffbeb', 'Rescheduled',      (int)($kpi['rescheduled'] ?? 0)],
                ];
                foreach ($kpiCards as [$icon, $col, $bg, $lbl, $val]):
                ?>
                <div class="col-6 col-md-2">
                    <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;
                                padding:1rem 1.1rem;display:flex;align-items:center;gap:.8rem;
                                box-shadow:0 1px 3px rgba(0,0,0,.04);">
                        <div style="width:40px;height:40px;border-radius:10px;background:<?= $bg ?>;
                                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas <?= $icon ?>" style="color:<?= $col ?>;font-size:.95rem;"></i>
                        </div>
                        <div>
                            <div style="font-size:1.35rem;font-weight:800;color:#1f2937;line-height:1.1;"><?= $val ?></div>
                            <div style="font-size:.7rem;color:#9ca3af;margin-top:.1rem;"><?= $lbl ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ── Table ────────────────────────────────────────────────── -->
            <div class="card-mis">
                <div class="card-mis-header">
                    <h5><i class="fas fa-table me-2 text-warning"></i>Logs — <?= $monthLabel ?></h5>
                    <span style="font-size:.78rem;color:#9ca3af;"><?= count($logs) ?> records</span>
                </div>
                <div class="card-mis-body p-0">
                <?php if (empty($logs)): ?>
                <div class="empty-state p-4">
                    <i class="fas fa-history"></i>
                    <h6>No logs found</h6>
                    <p>Try adjusting your filters.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table-mis" id="logsTable" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Staff</th>
                            <th>Client</th>
                            <th class="text-center">Time In</th>
                            <th class="text-center">Time Out</th>
                            <th class="text-center">Hours</th>
                            <th>Status</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $l): ?>
                    <tr>
                        <td>
                            <div style="font-weight:600;white-space:nowrap;font-size:.83rem;"><?= date('d M Y', strtotime($l['log_date'])) ?></div>
                            <div style="font-size:.68rem;color:#9ca3af;"><?= $l['day_of_week'] ?? '—' ?? '—' ?></div>
                        </td>
                        <td>
    <?php if ($l['log_type'] === 'OFFICE'): ?>
        <span style="background:#fef3c7;color:#92400e;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;">
            Office
        </span>
    <?php else: ?>
        <span style="background:#dbeafe;color:#1d4ed8;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;">
            Visit
        </span>
    <?php endif; ?>
</td>
                        <td>
                            <div style="font-weight:500;font-size:.82rem;"><?= htmlspecialchars($l['staff_name'] ?? '—') ?></div>
                            <div style="font-size:.68rem;color:#9ca3af;"><?= htmlspecialchars($l['employee_id'] ?? '') ?></div>
                        </td>
                        <td>
                            <div style="font-weight:500;font-size:.82rem;"><?= htmlspecialchars(mb_strimwidth($l['company_name'] ?? '—', 0, 22, '…')) ?></div>
                            <div style="font-size:.68rem;color:#9ca3af;"><?= htmlspecialchars($l['company_code'] ?? '') ?></div>
                        </td>
                        <td class="text-center" style="font-size:.78rem;color:#6b7280;"><?= $l['time_in'] ? date('g:i A', strtotime($l['time_in'])) : '—' ?></td>
                        <td class="text-center" style="font-size:.78rem;color:#6b7280;"><?= $l['time_out'] ? date('g:i A', strtotime($l['time_out'])) : '—' ?></td>
                        <td class="text-center">
                            <strong style="color:<?= (float)$l['duration_hours'] >= 4 ? '#10b981' : ((float)$l['duration_hours'] >= 2 ? '#f59e0b' : '#ef4444') ?>;font-weight:700;"><?= number_format((float)$l['duration_hours'],1) ?>h</strong>
                        </td>
                        <td><?= vstBadge($l['status'] ?? 'unknown') ?></td>
                        <td style="font-size:.75rem;color:#6b7280;max-width:200px;">
                            <?= htmlspecialchars(mb_strimwidth($l['work_description'] ?? '', 0, 60, '…')) ?>
                        </td>
                        <td>
    <div style="display:flex;gap:6px;align-items:center;justify-content:center;">

        <!-- VIEW -->
        <?php if (($l['log_type'] ?? '') === 'OFFICE'): ?>
            <a href="office_log_view.php?id=<?= $l['id'] ?>"
               class="cn-btn cn-btn-sm"
               title="View"
               style="background:#ecfeff;border:1px solid #a5f3fc;color:#0e7490;">
                <i class="fas fa-eye"></i>
            </a>
        <?php else: ?>
            <a href="log_view.php?id=<?= $l['id'] ?>"
               class="cn-btn cn-btn-sm"
               title="View"
               style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;">
                <i class="fas fa-eye"></i>
            </a>
        <?php endif; ?>


        <!-- EDIT (only owner) -->
        <?php if (($l['user_id'] ?? 0) == $uid): ?>

            <?php if (($l['log_type'] ?? '') === 'OFFICE'): ?>
                <a href="office_log_edit.php?id=<?= $l['id'] ?>"
                   class="cn-btn cn-btn-sm"
                   title="Edit"
                   style="background:#fefce8;border:1px solid #fde68a;color:#92400e;">
                    <i class="fas fa-pencil-alt"></i>
                </a>
            <?php else: ?>
                <a href="log_edit.php?id=<?= $l['id'] ?>"
                   class="cn-btn cn-btn-sm"
                   title="Edit"
                   style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;">
                    <i class="fas fa-pencil-alt"></i>
                </a>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    if ($('#logsTable tbody tr').length > 0)
        $('#logsTable').DataTable({ order: [[0,'desc']], pageLength: 25 });
});

function applyFilter() {
    const m = document.querySelector('input[type=month]').value;
    const s = document.getElementById('fStaff').value;
    const st= document.getElementById('fStatus').value;
    let url = '?month=' + m;
    if (s)  url += '&staff_id=' + s;
    if (st) url += '&visit_status=' + st;
    location.href = url;
}

function clearFilter() {
    location.href = '?month=<?= $month ?>';
}
</script>
<?php include '../../includes/footer.php'; ?>