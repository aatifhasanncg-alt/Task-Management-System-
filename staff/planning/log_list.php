<?php
/**
 * consulting/staff/log_list.php — Staff: My Visit Logs
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

$__deptMeta = $db->prepare("
    SELECT d.dept_code, d.dept_name 
    FROM departments d 
    WHERE d.id = ?
");
$__deptMeta->execute([$user['department_id']]);
$__deptMeta = $__deptMeta->fetch(PDO::FETCH_ASSOC);
$__primaryDeptCode = $__deptMeta['dept_code'] ?? '';
$__primaryDeptName = $__deptMeta['dept_name'] ?? '';

$__isConsultingPrimary = ($__primaryDeptCode === 'CON' 
    || stripos($__primaryDeptName, 'consult') !== false);

// Check UDA for consulting dept
$__udaConsStmt = $db->prepare("
    SELECT d.id, d.dept_code FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ? AND (d.dept_code = 'CON' 
        OR d.dept_name LIKE '%consult%')
    LIMIT 1
");
$__udaConsStmt->execute([$uid]);
$__udaConsDept = $__udaConsStmt->fetch(PDO::FETCH_ASSOC);

// Use consulting dept ID — either from primary or UDA
if ($__isConsultingPrimary) {
    $deptId = (int) $user['department_id'];
} elseif ($__udaConsDept) {
    $deptId = (int) $__udaConsDept['id'];
} else {
    $deptId = (int) $user['department_id']; // fallback
}

$now = new DateTime();
$month = $_GET['month'] ?? $now->format('Y-m');
$monthDate = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthLabel = $monthDate->format('F Y');

// KPIs
$totalLogs = (int) $db->prepare("SELECT COUNT(*) FROM work_logs WHERE user_id=? AND month_year=? AND department_id=?")->execute([$uid, $month, $deptId]) ? $db->query("SELECT COUNT(*) FROM work_logs WHERE user_id={$uid} AND month_year='{$month}' AND department_id={$deptId}")->fetchColumn() : 0;
$totalHours = (float) $db->query("SELECT COALESCE(SUM(duration_hours),0) FROM work_logs WHERE user_id={$uid} AND month_year='{$month}' AND department_id={$deptId}")->fetchColumn();
$visitedCnt = (int) $db->query("SELECT COUNT(*) FROM work_logs WHERE user_id={$uid} AND month_year='{$month}' AND department_id={$deptId} AND visit_status='visited'")->fetchColumn();
$missedCnt = (int) $db->query("SELECT COUNT(*) FROM work_logs WHERE user_id={$uid} AND month_year='{$month}' AND department_id={$deptId} AND visit_status='missed'")->fetchColumn();

$logs = $db->prepare("
    SELECT wl.*, c.company_name, c.company_code
    FROM work_logs wl
    LEFT JOIN companies c ON c.id=wl.client_id
    WHERE wl.user_id=? AND wl.month_year=? AND wl.department_id=?
    ORDER BY wl.log_date DESC, wl.time_in DESC
");
$logs->execute([$uid, $month, $deptId]);
$logs = $logs->fetchAll();

$pageTitle = 'My Visit Logs';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<style>
    /* DataTables pagination fix */
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

    .dataTables_wrapper .dataTables_paginate .paginate_button.disabled,
    .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
        background: #f9fafb !important;
        border-color: #e5e7eb !important;
        color: #d1d5db !important;
        cursor: not-allowed;
    }

    /* DataTables search + length fix */
    .dataTables_wrapper .dataTables_filter input {
        border: 1.5px solid #e5e7eb;
        border-radius: 6px;
        padding: 5px 10px;
        font-size: .8rem;
        outline: none;
        margin-left: 6px;
    }

    .dataTables_wrapper .dataTables_filter input:focus {
        border-color: #c9a84c;
    }

    .dataTables_wrapper .dataTables_length select {
        border: 1.5px solid #e5e7eb;
        border-radius: 6px;
        padding: 4px 8px;
        font-size: .8rem;
        margin: 0 4px;
    }

    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter {
        font-size: .8rem;
        color: #6b7280;
        padding: 10px 16px;
    }

    .dataTables_wrapper .dataTables_paginate {
        padding: 10px 16px;
    }

    /* Table overflow fix */
    .cn-table {
        table-layout: auto;
        word-break: break-word;
    }

    .cn-panel {
        overflow: hidden;
    }
</style>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <!-- ── PAGE HERO (MATCH DASHBOARD) ── -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge">
                            <i class="fas fa-history"></i> Logs
                        </div>
                        <h4>My Visit Logs</h4>
                        <p>
                            <?= htmlspecialchars($user['full_name']) ?> ·
                            <?= $monthLabel ?>
                        </p>
                    </div>

                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="month" class="form-control form-control-sm" style="width:155px;"
                            value="<?= $month ?>" onchange="location='?month='+this.value">

                        <a href="log_create.php?month=<?= $month ?>" class="btn-gold btn btn-sm">
                            <i class="fas fa-plus me-1"></i> Log Visit
                        </a>

                        <a href="../index.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- ── KPI ROW (MATCH DASHBOARD STYLE) ── -->
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

            <!-- ── LOG TABLE ── -->
            <div class="cn-panel">
                <div class="cn-panel-hd">
                    <span class="cn-panel-title">
                        <i class="fas fa-table me-2" style="color:var(--gold)"></i>
                        All Logs — <?= $monthLabel ?>
                    </span>
                </div>

                <?php if (empty($logs)): ?>
                    <div class="card-mis-body">
                        <div style="padding:40px;text-align:center;color:#9ca3af;">
                            <i class="fas fa-history" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
                            <div style="font-size:.85rem;font-weight:600;margin-bottom:4px;">No logs for <?= $monthLabel ?>
                            </div>
                            <div style="font-size:.78rem;margin-bottom:12px;">Start logging your client visits.</div>
                            <a href="log_create.php?month=<?= $month ?>" class="cn-btn cn-btn-gold"
                                style="display:inline-flex;">
                                <i class="fas fa-plus me-2"></i>Log Visit
                            </a>
                        </div>
                    </div>
                <?php else: ?>

                    <div style="padding:0;overflow-x:auto;">
                        <table class="cn-table w-100" id="logsTable">
                            <thead>
                                <tr>
                                    <th style="width:110px;">Date</th>
                                    <th>Client</th>
                                    <th class="text-center" style="width:95px;">Time In</th>
                                    <th class="text-center" style="width:95px;">Time Out</th>
                                    <th class="text-center" style="width:70px;">Hours</th>
                                    <th class="text-center" style="width:90px;">Status</th>
                                    <th style="width:160px;">Description</th>
                                    <th class="text-center" style="width:70px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $l): ?>
                                    <tr>
                                        <td>
                                            <strong
                                                style="font-size:.82rem;"><?= date('d M Y', strtotime($l['log_date'])) ?></strong>
                                            <div style="font-size:.7rem;color:#9ca3af;"><?= $l['day_of_week'] ?></div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;font-size:.82rem;">
                                                <?= htmlspecialchars($l['company_name'] ?? '—') ?>
                                            </div>
                                            <div style="font-size:.7rem;color:#9ca3af;">
                                                <?= htmlspecialchars($l['company_code'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td class="text-center" style="font-size:.81rem;">
                                            <?= $l['time_in'] ? date('h:i A', strtotime($l['time_in'])) : '—' ?>
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
                                        <td style="font-size:.77rem;color:#6b7280;max-width:200px;">
                                            <?= htmlspecialchars(mb_strimwidth($l['work_description'] ?? '', 0, 60, '…')) ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="log_edit.php?id=<?= $l['id'] ?>" class="cn-btn cn-btn-out cn-btn-sm">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>
            </div>

        </div>

    </div><!-- /main-content -->
</div><!-- /app-wrapper -->

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
    $(document).ready(function () {
        if ($('#logsTable tbody tr').length > 0) {
            $('#logsTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: { search: 'Search logs:' }
            });
        }
    });
</script>
<?php include '../../includes/footer.php'; ?>