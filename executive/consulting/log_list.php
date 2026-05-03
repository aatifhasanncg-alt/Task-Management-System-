<?php
/**
 * consulting/executive/log_list.php — Executive: All Staff Visit Logs
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

$now     = new DateTime();
$month   = $_GET['month']    ?? $now->format('Y-m');
$staffId = (int) ($_GET['staff_id']  ?? 0);
$clientId= (int) ($_GET['client_id'] ?? 0);
$vstatus = $_GET['vstatus']  ?? '';
$weekNum = (int) ($_GET['week']      ?? 0);

$monthDate  = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthLabel = $monthDate->format('F Y');

// KPIs
$kpiParams = [$month];
$kpiWhere  = "wl.month_year=?";
if ($staffId)  { $kpiWhere .= " AND wl.user_id=?";      $kpiParams[] = $staffId;  }
if ($clientId) { $kpiWhere .= " AND wl.client_id=?";    $kpiParams[] = $clientId; }
if ($vstatus)  { $kpiWhere .= " AND wl.visit_status=?"; $kpiParams[] = $vstatus;  }

$kpiStmt = $db->prepare("SELECT COUNT(*), COALESCE(SUM(duration_hours),0),
    SUM(visit_status='visited'), SUM(visit_status='missed')
    FROM work_logs wl WHERE {$kpiWhere}");
$kpiStmt->execute($kpiParams);
[$totalLogs, $totalHours, $visitedCnt, $missedCnt] = $kpiStmt->fetch(PDO::FETCH_NUM);
$totalLogs  = (int)   $totalLogs;
$totalHours = (float) $totalHours;
$visitedCnt = (int)   $visitedCnt;
$missedCnt  = (int)   $missedCnt;

// Staff list — include employee_id (code) and pan_number for search
$staffList = $db->query("
    SELECT DISTINCT u.id, u.full_name, u.employee_id
    FROM users u
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
    ORDER BY u.full_name
")->fetchAll();

// Client list — include company_code and pan_number for search
$clientList = $db->query("
    SELECT id, company_name, company_code, pan_number
    FROM companies
    WHERE is_active = 1
    ORDER BY company_name
")->fetchAll();

// Build filtered query
$where  = ["wl.month_year=?"];
$params = [$month];

if ($staffId)  { $where[] = "wl.user_id=?";       $params[] = $staffId;  }
if ($clientId) { $where[] = "wl.client_id=?";     $params[] = $clientId; }
if ($vstatus)  { $where[] = "wl.visit_status=?";  $params[] = $vstatus;  }
if ($weekNum)  { $where[] = "wl.week_number=?";   $params[] = $weekNum;  }

$whereSQL = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT wl.*, u.full_name, u.employee_id,
           c.company_name, c.company_code
    FROM work_logs wl
    JOIN users     u ON u.id = wl.user_id
    JOIN companies c ON c.id = wl.client_id
    WHERE {$whereSQL}
    ORDER BY wl.log_date DESC, wl.time_in DESC
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageTitle = 'All Visit Logs';
include '../../includes/header.php';
?>

<!-- Existing stylesheets -->
<link rel="stylesheet" href="../../../staff/planning/consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<!-- Tom Select -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">

<style>
    .filter-bar {
        background: #f9fafb;
        border-radius: 10px;
        padding: 12px 14px;
        margin-bottom: 16px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }

    /* ── Tom Select: gold highlight to match theme ── */
    .ts-dropdown .option.active,
    .ts-dropdown .option:hover { background: #c9a84c !important; color: #fff !important; }
    .ts-wrapper.focus .ts-control { border-color: #c9a84c !important; box-shadow: none !important; }

    /* DataTables pagination */
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 32px;
        padding: 0 10px;
        margin: 0 2px;
        border-radius: 6px;
        border: 1.5px solid #e5e7eb !important;
        background: #fff !important;
        color: #374151 !important;
        font-size: .8rem;
        font-weight: 600;
        cursor: pointer;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current,
    .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
        background: #c9a84c !important;
        border-color: #c9a84c !important;
        color: #fff !important;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background: #f9fafb !important;
        border-color: #c9a84c !important;
        color: #c9a84c !important;
    }
    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
        border: 1.5px solid #e5e7eb;
        border-radius: 6px;
        padding: 5px 10px;
        font-size: .8rem;
        margin-left: 6px;
    }
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter {
        font-size: .8rem;
        color: #6b7280;
        padding: 10px 16px;
    }
    .dataTables_wrapper .dataTables_paginate { padding: 10px 16px; }
    /* ADD inside the existing <style> tag: */
    #staffSelect + .ts-wrapper,
    #clientSelect + .ts-wrapper {
        width: 100% !important;
        min-width: 200px;
    }

    .ts-wrapper .ts-control {
        border: 1.5px solid #e5e7eb;
        border-radius: 6px;
        font-size: .8rem;
        padding: 5px 10px;
    }
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
                    <div class="kpi-val"><?= number_format($totalHours, 1) ?>h</div>
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

                <!-- Month -->
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Month</label>
                    <input type="month" name="month" class="cn-input" style="width:145px;" value="<?= $month ?>">
                </div>

                <!-- Staff — searchable by name, employee code -->
                <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:200px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Staff</label>
                    <select name="staff_id" id="staffSelect" style="width:100%;">
                        <option value="">All Staff</option>
                        <?php foreach ($staffList as $sl): ?>
                            <option value="<?= $sl['id'] ?>"
                                <?= $staffId == $sl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sl['full_name']) ?>
                                <?= $sl['employee_id'] ? ' — ' . htmlspecialchars($sl['employee_id']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Client — searchable by name, company code, PAN -->
                <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:200px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Client</label>
                    <select name="client_id" id="clientSelect" style="width:100%;">
                        <option value="">All Clients</option>
                        <?php foreach ($clientList as $cl): ?>
                            <option value="<?= $cl['id'] ?>"
                                data-code="<?= htmlspecialchars($cl['company_code'] ?? '') ?>"
                                data-pan="<?= htmlspecialchars($cl['pan_number'] ?? '') ?>"
                                <?= $clientId == $cl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cl['company_name']) ?>
                                <?= $cl['company_code'] ? ' — ' . htmlspecialchars($cl['company_code']) : '' ?>
                                <?= $cl['pan_number']   ? ' | PAN: ' . htmlspecialchars($cl['pan_number']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Status -->
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Status</label>
                    <select name="vstatus" class="cn-input" style="width:130px;">
                        <option value="">All Status</option>
                        <option value="visited"     <?= $vstatus === 'visited'     ? 'selected' : '' ?>>✅ Visited</option>
                        <option value="missed"      <?= $vstatus === 'missed'      ? 'selected' : '' ?>>❌ Missed</option>
                        <option value="rescheduled" <?= $vstatus === 'rescheduled' ? 'selected' : '' ?>>🔄 Rescheduled</option>
                    </select>
                </div>

                <!-- Week -->
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Week</label>
                    <select name="week" class="cn-input" style="width:95px;">
                        <option value="">All</option>
                        <?php for ($w = 1; $w <= 5; $w++): ?>
                            <option value="<?= $w ?>" <?= $weekNum == $w ? 'selected' : '' ?>>Week <?= $w ?></option>
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
                    <a href="<?= APP_URL ?>/exports/export_excel.php?module=consulting_performance&view=who&month=<?= urlencode($month) ?>&staff_id=<?= $staffId ?>&client_id=<?= $clientId ?>&vstatus=<?= urlencode($vstatus) ?>&week=<?= $weekNum ?>"
                        class="cn-btn cn-btn-out cn-btn-sm">
                        <i class="fas fa-file-excel" style="color:#10b981;"></i> Excel
                    </a>
                    <a href="<?= APP_URL ?>/exports/export_pdf.php?module=consulting_performance&view=who&month=<?= urlencode($month) ?>&staff_id=<?= $staffId ?>&client_id=<?= $clientId ?>&vstatus=<?= urlencode($vstatus) ?>&week=<?= $weekNum ?>"
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
                                <tr>
                                    <td colspan="8" style="text-align:center;color:#9ca3af;padding:30px;font-size:.83rem;">
                                        No logs found for the selected filters.
                                    </td>
                                </tr>
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
                                            <strong style="color:<?= hoursColor((float) $l['duration_hours']) ?>">
                                                <?= number_format((float) $l['duration_hours'], 1) ?>h
                                            </strong>
                                        </td>
                                        <td class="text-center"><?= visitBadge($l['visit_status']) ?></td>
                                        <td style="font-size:.77rem;color:#6b7280;max-width:250px;word-wrap:break-word;white-space:normal;">
                                            <?= htmlspecialchars($l['work_description'] ?? '—') ?>
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

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ── Staff: search by name + employee code (no PAN) ── */
    new TomSelect('#staffSelect', {
        placeholder:      'Search by name or code…',
        maxOptions:       100,
        allowEmptyOption: true,
        searchField:      ['text'],
        render: {
            option: function(data, escape) {
                // text already contains "Name — EMP001"
                var parts = data.text.split(' — ');
                var name  = parts[0] ? escape(parts[0].trim()) : escape(data.text);
                var code  = parts[1] ? parts[1].trim() : '';
                return '<div style="padding:6px 10px;line-height:1.4;">' +
                           '<div style="font-weight:600;font-size:.82rem;">' + name + '</div>' +
                           (code ? '<div style="font-size:.7rem;color:#6b7280;">Code: ' + escape(code) + '</div>' : '') +
                       '</div>';
            },
            item: function(data, escape) {
                return '<div>' + escape(data.text) + '</div>';
            }
        }
    });

    /* ── Client: search by name + code + PAN (all baked into text) ── */
    new TomSelect('#clientSelect', {
        placeholder:      'Search by name, code or PAN…',
        maxOptions:       200,
        allowEmptyOption: true,
        searchField:      ['text'],
        render: {
            option: function(data, escape) {
                // text is "Company Name — CODE | PAN: 123456789"
                var raw   = data.text;
                var name  = raw.split(' — ')[0].trim();
                var rest  = raw.includes(' — ') ? raw.split(' — ').slice(1).join(' — ').trim() : '';
                var code  = '';
                var pan   = '';
                if (rest) {
                    var panSplit = rest.split(' | PAN: ');
                    code = panSplit[0].trim();
                    pan  = panSplit[1] ? panSplit[1].trim() : '';
                }
                return '<div style="padding:6px 10px;line-height:1.4;">' +
                           '<div style="font-weight:600;font-size:.82rem;">' + escape(name) + '</div>' +
                           '<div style="font-size:.7rem;color:#6b7280;">' +
                               (code ? '<span style="margin-right:8px;">Code: ' + escape(code) + '</span>' : '') +
                               (pan  ? '<span>PAN: ' + escape(pan) + '</span>' : '') +
                           '</div>' +
                       '</div>';
            },
            item: function(data, escape) {
                // Show just the company name in the selected pill
                var name = data.text.split(' — ')[0].trim();
                return '<div>' + escape(name) + '</div>';
            }
        }
    });

    /* ── DataTable ── */
    if ($('#logsTable tbody td').length > 1) {
        $('#logsTable').DataTable({
            order:      [[0, 'desc']],
            pageLength: 25,
            language:   { search: 'Search logs:' }
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>