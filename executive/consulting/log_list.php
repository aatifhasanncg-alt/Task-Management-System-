<?php
/**
 * consulting/executive/log_list.php — Executive: All Staff Visit Logs
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];


$branchId = (int)$user['branch_id'];
$deptId   = (int)$user['department_id'];

$now      = new DateTime();
$month    = $_GET['month']     ?? $now->format('Y-m');
$staffId  = (int)($_GET['staff_id']  ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);
$vstatus  = $_GET['vstatus']   ?? '';
$weekNum  = (int)($_GET['week'] ?? 0);

$monthDate  = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthLabel = $monthDate->format('F Y');

// KPIs (filtered by current filters or branch-wide for month)
$kpiWhere = "wl.branch_id={$branchId} AND wl.month_year='{$month}'";
if ($staffId)  $kpiWhere .= " AND wl.user_id={$staffId}";
if ($clientId) $kpiWhere .= " AND wl.client_id={$clientId}";
if ($vstatus)  $kpiWhere .= " AND wl.visit_status='" . $db->quote($vstatus) . "'";

$totalLogs   = (int)$db->query("SELECT COUNT(*) FROM work_logs wl WHERE {$kpiWhere}")->fetchColumn();
$totalHours  = (float)$db->query("SELECT COALESCE(SUM(duration_hours),0) FROM work_logs wl WHERE {$kpiWhere}")->fetchColumn();
$visitedCnt  = (int)$db->query("SELECT COUNT(*) FROM work_logs wl WHERE {$kpiWhere} AND wl.visit_status='visited'")->fetchColumn();
$missedCnt   = (int)$db->query("SELECT COUNT(*) FROM work_logs wl WHERE {$kpiWhere} AND wl.visit_status='missed'")->fetchColumn();

// Staff list
$staffList = $db->query("
    SELECT id, full_name, employee_id FROM users
    WHERE branch_id={$branchId} AND department_id={$deptId} AND is_active=1
    ORDER BY full_name
")->fetchAll();

// Client list
$clientList = $db->query("
    SELECT id, company_name, company_code FROM companies
    WHERE branch_id={$branchId} AND is_active=1
    ORDER BY company_name
")->fetchAll();

// Build filtered query
$where  = ["wl.branch_id=?", "wl.month_year=?"];
$params = [$branchId, $month];

if ($staffId)  { $where[] = "wl.user_id=?";       $params[] = $staffId;  }
if ($clientId) { $where[] = "wl.client_id=?";     $params[] = $clientId; }
if ($vstatus)  { $where[] = "wl.visit_status=?";  $params[] = $vstatus;  }
if ($weekNum)  { $where[] = "wl.week_number=?";   $params[] = $weekNum;  }

$whereSQL = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT wl.*, u.full_name, u.employee_id,
           c.company_name, c.company_code
    FROM work_logs wl
    JOIN users u    ON u.id  = wl.user_id
    JOIN companies c ON c.id = wl.client_id
    WHERE {$whereSQL}
    ORDER BY wl.log_date DESC, wl.time_in DESC
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageTitle = 'All Visit Logs';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="../../../staff/planning/consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<style>
.filter-bar { background:#f9fafb;border-radius:10px;padding:12px 14px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center; }
.dataTables_wrapper .dataTables_paginate .paginate_button { display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 10px;margin:0 2px;border-radius:6px;border:1.5px solid #e5e7eb !important;background:#fff !important;color:#374151 !important;font-size:.8rem;font-weight:600;cursor:pointer; }
.dataTables_wrapper .dataTables_paginate .paginate_button.current,.dataTables_wrapper .dataTables_paginate .paginate_button.current:hover { background:#c9a84c !important;border-color:#c9a84c !important;color:#fff !important; }
.dataTables_wrapper .dataTables_paginate .paginate_button:hover { background:#f9fafb !important;border-color:#c9a84c !important;color:#c9a84c !important; }
.dataTables_wrapper .dataTables_filter input,.dataTables_wrapper .dataTables_length select { border:1.5px solid #e5e7eb;border-radius:6px;padding:5px 10px;font-size:.8rem;margin-left:6px; }
.dataTables_wrapper .dataTables_info,.dataTables_wrapper .dataTables_length,.dataTables_wrapper .dataTables_filter { font-size:.8rem;color:#6b7280;padding:10px 16px; }
.dataTables_wrapper .dataTables_paginate { padding:10px 16px; }
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <?= flashHtml() ?>

            <!-- PAGE HERO -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-briefcase"></i> Executive · Consulting</div>
                        <h4>All Visit Logs</h4>
                        <p>Full log view across all staff · <?= $monthLabel ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="index.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- KPI ROW -->
            <div class="kpi-row mb-4">
                <div class="kpi-tile" style="--kpi-color:#3b82f6;">
                    <div class="kpi-icon"><i class="fas fa-list" style="color:#3b82f6;"></i></div>
                    <div class="kpi-val"><?= $totalLogs ?></div>
                    <div class="kpi-label">Total Logs</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#c9a84c;">
                    <div class="kpi-icon"><i class="fas fa-clock" style="color:#c9a84c;"></i></div>
                    <div class="kpi-val"><?= number_format($totalHours,1) ?>h</div>
                    <div class="kpi-label">Total Hours</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#10b981;">
                    <div class="kpi-icon"><i class="fas fa-check-circle" style="color:#10b981;"></i></div>
                    <div class="kpi-val"><?= $visitedCnt ?></div>
                    <div class="kpi-label">Visited</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#ef4444;">
                    <div class="kpi-icon"><i class="fas fa-times-circle" style="color:#ef4444;"></i></div>
                    <div class="kpi-val"><?= $missedCnt ?></div>
                    <div class="kpi-label">Missed</div>
                </div>
            </div>

            <!-- FILTER BAR -->
            <form method="GET" class="filter-bar">
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Month</label>
                    <input type="month" name="month" class="cn-input" style="width:145px;" value="<?= $month ?>">
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Staff</label>
                    <select name="staff_id" class="cn-input" style="min-width:155px;">
                        <option value="">All Staff</option>
                        <?php foreach ($staffList as $sl): ?>
                        <option value="<?= $sl['id'] ?>" <?= $staffId==$sl['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sl['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Client</label>
                    <select name="client_id" class="cn-input" style="min-width:155px;">
                        <option value="">All Clients</option>
                        <?php foreach ($clientList as $cl): ?>
                        <option value="<?= $cl['id'] ?>" <?= $clientId==$cl['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cl['company_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Status</label>
                    <select name="vstatus" class="cn-input" style="width:130px;">
                        <option value="">All Status</option>
                        <option value="visited"     <?= $vstatus==='visited'     ? 'selected' : '' ?>>✅ Visited</option>
                        <option value="missed"      <?= $vstatus==='missed'      ? 'selected' : '' ?>>❌ Missed</option>
                        <option value="rescheduled" <?= $vstatus==='rescheduled' ? 'selected' : '' ?>>🔄 Rescheduled</option>
                    </select>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Week</label>
                    <select name="week" class="cn-input" style="width:95px;">
                        <option value="">All</option>
                        <?php for ($w=1;$w<=5;$w++): ?>
                        <option value="<?= $w ?>" <?= $weekNum==$w ? 'selected' : '' ?>>Week <?= $w ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="cn-btn cn-btn-blue cn-btn-sm">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="log_list.php" class="cn-btn cn-btn-out cn-btn-sm">
                    <i class="fas fa-times"></i> Clear
                </a>
                <div style="margin-left:auto;display:flex;gap:6px;">
                    <a href="export_excel.php?type=logs&month=<?= $month ?>&staff_id=<?= $staffId ?>&client_id=<?= $clientId ?>&vstatus=<?= urlencode($vstatus) ?>&week=<?= $weekNum ?>"
                       class="cn-btn cn-btn-out cn-btn-sm">
                        <i class="fas fa-file-excel" style="color:#10b981;"></i> Excel
                    </a>
                    <a href="export_pdf.php?type=logs&month=<?= $month ?>&staff_id=<?= $staffId ?>&client_id=<?= $clientId ?>&vstatus=<?= urlencode($vstatus) ?>&week=<?= $weekNum ?>"
                       class="cn-btn cn-btn-out cn-btn-sm">
                        <i class="fas fa-file-pdf" style="color:#ef4444;"></i> PDF
                    </a>
                </div>
            </form>

            <!-- LOG TABLE -->
            <div class="cn-panel">
                <div style="padding:0;overflow-x:auto;">
                    <table class="cn-table w-100" id="logsTable">
                        <thead>
                            <tr>
                                <th style="width:100px;">Date</th>
                                <th style="width:130px;">Staff</th>
                                <th style="width:140px;">Client</th>
                                <th class="text-center" style="width:90px;">Time In</th>
                                <th class="text-center" style="width:90px;">Time Out</th>
                                <th class="text-center" style="width:70px;">Hours</th>
                                <th class="text-center" style="width:85px;">Status</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($logs)): ?>
                        <tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:30px;font-size:.83rem;">
                            No logs found for the selected filters.
                        </td></tr>
                        <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                        <tr>
                            <td>
                                <strong style="font-size:.82rem;"><?= date('d M Y', strtotime($l['log_date'])) ?></strong>
                                <div style="font-size:.68rem;color:#9ca3af;"><?= $l['day_of_week'] ?></div>
                            </td>
                            <td>
                                <div style="font-weight:600;font-size:.82rem;"><?= htmlspecialchars($l['full_name']) ?></div>
                                <div style="font-size:.68rem;color:#9ca3af;"><?= htmlspecialchars($l['employee_id'] ?? '') ?></div>
                            </td>
                            <td>
                                <div style="font-weight:600;font-size:.82rem;"><?= htmlspecialchars($l['company_name']) ?></div>
                                <div style="font-size:.68rem;color:#9ca3af;"><?= htmlspecialchars($l['company_code'] ?? '') ?></div>
                            </td>
                            <td class="text-center" style="font-size:.81rem;">
                                <?= $l['time_in']  ? date('h:i A', strtotime($l['time_in']))  : '—' ?>
                            </td>
                            <td class="text-center" style="font-size:.81rem;">
                                <?= $l['time_out'] ? date('h:i A', strtotime($l['time_out'])) : '—' ?>
                            </td>
                            <td class="text-center">
                                <strong style="color:<?= hoursColor((float)$l['duration_hours']) ?>">
                                    <?= number_format((float)$l['duration_hours'],1) ?>h
                                </strong>
                            </td>
                            <td class="text-center"><?= visitBadge($l['visit_status']) ?></td>
                            <td style="font-size:.77rem;color:#6b7280;max-width:200px;">
                                <?= htmlspecialchars(mb_strimwidth($l['work_description'] ?? '', 0, 60, '…')) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    if ($('#logsTable tbody td').length > 1) {
        $('#logsTable').DataTable({ order:[[0,'desc']], pageLength:25, language:{search:'Search logs:'} });
    }
});
</script>
<?php include '../../includes/footer.php'; ?>