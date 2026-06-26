<?php
/**
 * manager/consulting/plans.php — Manager: Approve/Reject Work Plans
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
require_once '../../config/notify.php';
requireManager();

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];

$now        = new DateTime();
$month      = $_GET['month'] ?? $now->format('Y-m');
$monthDate  = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthLabel = $monthDate->format('F Y');
$staffId    = (int)($_GET['staff_id'] ?? 0);
$statusFilter = $_GET['status'] ?? 'submitted';
$weekFilter = (int)($_GET['week'] ?? 0);

// ── Handle approve / reject POST ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $planId  = (int)($_POST['plan_id'] ?? 0);
    $action  = $_POST['action'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if ($planId && in_array($action, ['approve', 'reject', 'bulk_approve'])) {

        if ($action === 'bulk_approve') {
            $ids = array_map('intval', $_POST['plan_ids'] ?? []);
            foreach ($ids as $pid) {
                $db->prepare("
                    UPDATE work_plans 
                    SET status='approved', approved_by=?, approved_at=NOW()
                    WHERE id=? AND status='submitted'
                ")->execute([$uid, $pid]);

                $pRow = $db->prepare("SELECT user_id, week_number, plan_month FROM work_plans WHERE id=?");
                $pRow->execute([$pid]);
                $pr = $pRow->fetch();
                if ($pr) {
                    $wn = (int)$pr['week_number'];
                    $ml = date('F Y', strtotime($pr['plan_month']));
                    notify(
                        (int)$pr['user_id'],
                        'Work Plan Approved',
                        'Your Week ' . $wn . ' work plan for ' . $ml . ' has been approved.',
                        'task',
                        APP_URL . '/staff/planning/plan_view.php?id=' . $pid,
                        true,
                        ['template' => 'work_plan_status', 'plan' => [
                            'week' => $wn, 'status' => 'approved',
                            'remarks' => '', 'reviewer' => $user['full_name']
                        ]]
                    );
                }
            }
            setFlash('success', count($ids) . ' plan(s) approved.');

        } elseif ($action === 'approve') {
            $db->prepare("
                UPDATE work_plans 
                SET status='approved', approved_by=?, approved_at=NOW(), remarks=?
                WHERE id=? AND status='submitted'
            ")->execute([$uid, $remarks ?: null, $planId]);

            $pRow = $db->prepare("
                SELECT wp.user_id, wp.week_number, wp.plan_month,
                       wp.week_start_date, wp.week_end_date,
                       COUNT(wpe.id) AS entry_count,
                       COALESCE(SUM(wpe.planned_hours),0) AS total_hours
                FROM work_plans wp
                LEFT JOIN work_plan_entries wpe ON wpe.plan_id = wp.id
                WHERE wp.id = ?
                GROUP BY wp.id
            ");
            $pRow->execute([$planId]);
            $pr = $pRow->fetch();

            if ($pr) {
                $wn = (int)$pr['week_number'];
                $ml = date('F Y', strtotime($pr['plan_month']));
                notify(
                    (int)$pr['user_id'],
                    'Work Plan Approved',
                    'Your Week ' . $wn . ' work plan for ' . $ml . ' has been approved by ' . $user['full_name'] . '.',
                    'task',
                    APP_URL . '/staff/planning/plan_view.php?id=' . $planId,
                    true,
                    ['template' => 'work_plan_status', 'plan' => [
                        'week'        => $wn,
                        'week_start'  => $pr['week_start_date'],
                        'week_end'    => $pr['week_end_date'],
                        'entry_count' => (int)$pr['entry_count'],
                        'total_hours' => (float)$pr['total_hours'],
                        'status'      => 'approved',
                        'remarks'     => $remarks,
                        'reviewer'    => $user['full_name'],
                    ]]
                );
            }
            setFlash('success', 'Plan approved successfully.');

        } elseif ($action === 'reject') {
            if (!$remarks) {
                setFlash('error', 'Rejection reason is required.');
                header('Location: plans.php?month=' . $month . '&staff_id=' . $staffId . '&status=' . $statusFilter);
                exit;
            }
            $db->prepare("
                UPDATE work_plans 
                SET status='rejected', remarks=?
                WHERE id=? AND status='submitted'
            ")->execute([$remarks, $planId]);

            $pRow = $db->prepare("
                SELECT wp.user_id, wp.week_number, wp.plan_month,
                       wp.week_start_date, wp.week_end_date,
                       COUNT(wpe.id) AS entry_count,
                       COALESCE(SUM(wpe.planned_hours),0) AS total_hours
                FROM work_plans wp
                LEFT JOIN work_plan_entries wpe ON wpe.plan_id = wp.id
                WHERE wp.id = ?
                GROUP BY wp.id
            ");
            $pRow->execute([$planId]);
            $pr = $pRow->fetch();

            if ($pr) {
                $wn = (int)$pr['week_number'];
                $ml = date('F Y', strtotime($pr['plan_month']));
                notify(
                    (int)$pr['user_id'],
                    'Work Plan Rejected',
                    'Your Week ' . $wn . ' work plan for ' . $ml . ' was rejected. Reason: ' . $remarks,
                    'task',
                    APP_URL . '/staff/planning/plan_view.php?id=' . $planId,
                    true,
                    ['template' => 'work_plan_status', 'plan' => [
                        'week'        => $wn,
                        'week_start'  => $pr['week_start_date'],
                        'week_end'    => $pr['week_end_date'],
                        'entry_count' => (int)$pr['entry_count'],
                        'total_hours' => (float)$pr['total_hours'],
                        'status'      => 'rejected',
                        'remarks'     => $remarks,
                        'reviewer'    => $user['full_name'],
                    ]]
                );
            }
            setFlash('warning', 'Plan rejected.');
        }

        logActivity('Plan #' . $planId . ' ' . $action . 'd', 'consulting');
    }

    header('Location: plans.php?month=' . $month . '&staff_id=' . $staffId . '&status=' . $statusFilter . '&week=' . $weekFilter);
    exit;
}

// ── Staff list for filter ─────────────────────────────────────
$staffList = $db->query("
    SELECT DISTINCT u.id, u.full_name, u.employee_id
    FROM users u
    LEFT JOIN departments d  ON d.id  = u.department_id
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    LEFT JOIN departments d2 ON d2.id = uda.department_id
    WHERE u.is_active = 1
      AND (
          d.dept_code = 'CON'  OR d.dept_name  LIKE '%consult%'
          OR d2.dept_code = 'CON' OR d2.dept_name LIKE '%consult%'
      )
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Build plan query ──────────────────────────────────────────
$where  = ["wp.plan_month = ?"];
$params = [$monthStart];

if ($staffId)     { $where[] = "wp.user_id = ?";     $params[] = $staffId; }
if ($statusFilter){ $where[] = "wp.status = ?";      $params[] = $statusFilter; }
if ($weekFilter)  { $where[] = "wp.week_number = ?"; $params[] = $weekFilter; }

$whereSQL = implode(' AND ', $where);

$plans = $db->prepare("
    SELECT wp.*,
           u.full_name, u.employee_id,
           ab.full_name AS approver_name,
           COUNT(wpe.id) AS entry_count,
           COALESCE(SUM(wpe.planned_hours),0) AS planned_hours
    FROM work_plans wp
    JOIN users u ON u.id = wp.user_id
    LEFT JOIN users ab ON ab.id = wp.approved_by
    LEFT JOIN work_plan_entries wpe ON wpe.plan_id = wp.id
    LEFT JOIN departments d  ON d.id  = u.department_id
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    LEFT JOIN departments d2 ON d2.id = uda.department_id
    WHERE {$whereSQL}
      AND (
          d.dept_code = 'CON'  OR d.dept_name  LIKE '%consult%'
          OR d2.dept_code = 'CON' OR d2.dept_name LIKE '%consult%'
      )
    GROUP BY wp.id
    ORDER BY wp.created_at DESC
");
$plans->execute($params);
$plans = $plans->fetchAll(PDO::FETCH_ASSOC);

// ── Status counts for tabs ────────────────────────────────────
$statusCounts = [];
foreach (['draft','submitted','approved','rejected'] as $s) {
    $cQ = $db->prepare("
        SELECT COUNT(*) FROM work_plans wp
        JOIN users u ON u.id = wp.user_id
        LEFT JOIN departments d  ON d.id  = u.department_id
        LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
        LEFT JOIN departments d2 ON d2.id = uda.department_id
        WHERE wp.plan_month = ?
          AND wp.status = ?
          AND (
              d.dept_code = 'CON' OR d.dept_name LIKE '%consult%'
              OR d2.dept_code = 'CON' OR d2.dept_name LIKE '%consult%'
          )
          " . ($staffId ? "AND wp.user_id = ?" : "") . "
    ");
    $cp = [$monthStart, $s];
    if ($staffId) $cp[] = $staffId;
    $cQ->execute($cp);
    $statusCounts[$s] = (int)$cQ->fetchColumn();
}

$pageTitle = 'Plan Lists';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="../../staff/planning/consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
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

    .status-tab {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: .75rem;
        font-weight: 600;
        cursor: pointer;
        border: 1.5px solid #e5e7eb;
        background: #fff;
        text-decoration: none;
        color: #374151;
    }

    .status-tab.active {
        border-color: #c9a84c;
        background: #c9a84c;
        color: #fff;
    }

    .status-tab .cnt {
        background: rgba(0, 0, 0, .12);
        border-radius: 10px;
        padding: 1px 6px;
        font-size: .68rem;
    }

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

    .dataTables_wrapper .dataTables_paginate {
        padding: 10px 16px;
    }

    /* ADD inside the existing <style> tag: */
    #staffSelect+.ts-wrapper {
        width: 100% !important;
        min-width: 160px;
    }
</style>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_manager.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <?= flashHtml() ?>

            <!-- PAGE HERO -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-check-circle"></i> Manager · Consulting</div>
                        <h4>Plan List</h4>
                        <p>Review and approve staff work plans · <?= $monthLabel ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="month" class="form-control form-control-sm" style="width:150px;"
                               value="<?= $month ?>" onchange="location='?month='+this.value+'&staff_id=<?= $staffId ?>&status=<?= $statusFilter ?>'">
                        <a href="plans.php?month=<?= $month ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-list me-1"></i> All Plans
                        </a>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- STATUS TABS -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
                <?php
                $allCount = array_sum($statusCounts);
                $tabs = ['' => 'All', 'submitted' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'draft' => 'Draft'];
                foreach ($tabs as $val => $label):
                    $cnt    = $val === '' ? $allCount : ($statusCounts[$val] ?? 0);
                    $active = $statusFilter === $val ? 'active' : '';
                    $url    = '?month=' . $month . '&staff_id=' . $staffId . '&status=' . $val . '&week=' . $weekFilter;
                ?>
                    <a href="<?= $url ?>" class="status-tab <?= $active ?>">
                        <?= $label ?> <span class="cnt"><?= $cnt ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- FILTER BAR -->
            <form method="GET" style="background:#f9fafb;border-radius:10px;padding:12px 14px;
                  margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Month</label>
                    <input type="month" name="month" class="cn-input" style="width:145px;" value="<?= $month ?>">
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Staff</label>
                    <select name="staff_id" class="cn-input" style="min-width:160px;" id="staffSelect">
                        <option value="">All Staff</option>
                        <?php foreach ($staffList as $sl): ?>
                            <option value="<?= $sl['id'] ?>" <?= $staffId == $sl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sl['full_name']) ?>
                                <?= $sl['employee_id'] ? ' — ' . htmlspecialchars($sl['employee_id']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:.75rem;color:#6b7280;white-space:nowrap;">Week</label>
                    <select name="week" class="cn-input" style="width:100px;">
                        <option value="">All</option>
                        <?php for ($w = 1; $w <= 6; $w++): ?>
                            <option value="<?= $w ?>" <?= $weekFilter == $w ? 'selected' : '' ?>>Week <?= $w ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="cn-btn cn-btn-blue cn-btn-sm">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="plans.php" class="cn-btn cn-btn-out cn-btn-sm">
                    <i class="fas fa-times"></i> Clear
                </a>
            </form>

            <!-- BULK APPROVE -->
            <form method="POST" id="bulkForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="bulk_approve">
                <input type="hidden" name="plan_id" value="0">

                <?php if ($statusFilter === 'submitted' && !empty($plans)): ?>
                <div style="margin-bottom:10px;display:flex;align-items:center;gap:10px;">
                    <label style="font-size:.78rem;color:#6b7280;display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" id="selectAll"> Select All
                    </label>
                    <button type="submit" class="cn-btn cn-btn-blue cn-btn-sm"
                            onclick="return confirm('Approve all selected plans?')">
                        <i class="fas fa-check-double"></i> Bulk Approve Selected
                    </button>
                </div>
                <?php endif; ?>

                <!-- PLANS TABLE -->
                <div class="cn-panel">
                    <div style="padding:0;overflow-x:auto;">
                        <table class="cn-table w-100" id="plansTable">
                            <thead>
                                <tr>
                                    <th style="width:36px;"></th>
                                    <th>Staff</th>
                                    <th class="text-center">Week</th>
                                    <th>Date Range</th>
                                    <th class="text-center">Entries</th>
                                    <th class="text-center">Hours</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Created</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($plans)): ?>
                                <tr>
                                    <td colspan="9" style="text-align:center;color:#9ca3af;padding:30px;font-size:.83rem;">
                                        No plans found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($plans as $p):
                                    $sc  = ['draft'=>'#9ca3af','submitted'=>'#3b82f6','approved'=>'#10b981','rejected'=>'#ef4444'];
                                    $sc2 = ['draft'=>'#f3f4f6','submitted'=>'#eff6ff','approved'=>'#f0fdf4','rejected'=>'#fef2f2'];
                                    $st  = $p['status'] ?? 'draft';
                                ?>
                                <tr>
                                    <td class="text-center">
                                        <?php if ($st === 'submitted'): ?>
                                            <input type="checkbox" name="plan_ids[]" value="<?= $p['id'] ?>" class="plan-chk">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight:600;font-size:.82rem;"><?= htmlspecialchars($p['full_name']) ?></div>
                                        <div style="font-size:.68rem;color:#9ca3af;"><?= htmlspecialchars($p['employee_id'] ?? '') ?></div>
                                    </td>
                                    <td class="text-center"><strong style="color:#c9a84c;">W<?= $p['week_number'] ?></strong></td>
                                    <td style="font-size:.78rem;color:#6b7280;">
                                        <?= date('d M', strtotime($p['week_start_date'])) ?> –
                                        <?= date('d M', strtotime($p['week_end_date'])) ?>
                                    </td>
                                    <td class="text-center"><strong><?= $p['entry_count'] ?></strong></td>
                                    <td class="text-center">
                                        <strong style="color:#c9a84c;"><?= number_format($p['planned_hours'],1) ?>h</strong>
                                    </td>
                                    <td class="text-center">
                                        <span style="background:<?= $sc2[$st] ?>;color:<?= $sc[$st] ?>;
                                            padding:2px 10px;border-radius:20px;font-size:.72rem;
                                            font-weight:600;text-transform:capitalize;">
                                            <?= $st ?>
                                        </span>
                                    </td>
                                    <td class="text-center" style="font-size:.75rem;color:#9ca3af;">
                                        <?= $p['created_at'] ? date('d M Y', strtotime($p['created_at'])) : '—' ?>
                                    </td>
                                    <td class="text-center">
                                        <div style="display:flex;gap:4px;justify-content:center;">
                                            <a href="plan_view.php?id=<?= $p['id'] ?>"
                                               class="cn-btn cn-btn-blue cn-btn-sm" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($st === 'submitted'): ?>
                                                <button type="button" class="cn-btn cn-btn-out cn-btn-sm"
                                                        style="color:#10b981;border-color:#10b981;"
                                                        onclick="quickApprove(<?= $p['id'] ?>)" title="Approve">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" class="cn-btn cn-btn-out cn-btn-sm"
                                                        style="color:#ef4444;border-color:#ef4444;"
                                                        onclick="openReject(<?= $p['id'] ?>)" title="Reject">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($st === 'approved' || $st === 'rejected'): ?>
                                                <span style="font-size:.7rem;color:#9ca3af;">
                                                    <?= $p['approver_name'] ? 'by ' . htmlspecialchars(explode(' ', $p['approver_name'])[0]) : '' ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- REJECT MODAL -->
<div id="rejectModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);
     z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:24px;width:400px;
                max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <div style="font-weight:700;font-size:.95rem;margin-bottom:4px;">
            <i class="fas fa-times-circle text-danger me-2"></i>Reject Plan
        </div>
        <div style="font-size:.78rem;color:#6b7280;margin-bottom:14px;">Reason is required.</div>
        <form method="POST" id="rejectForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="plan_id" id="rejectPlanId" value="">
            <textarea name="remarks" class="cn-input" rows="3" placeholder="Rejection reason..."
                      required style="width:100%;margin-bottom:12px;box-sizing:border-box;"></textarea>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="cn-btn cn-btn-out cn-btn-sm" onclick="closeReject()">Cancel</button>
                <button type="submit" class="cn-btn cn-btn-sm"
                        style="background:#ef4444;color:#fff;border-color:#ef4444;">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
        </form>
    </div>
</div>

<!-- QUICK APPROVE FORM -->
<form method="POST" id="approveForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="plan_id" id="approvePlanId" value="">
    <input type="hidden" name="remarks" value="">
</form>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
$(document).ready(function () {
    if ($('#plansTable tbody tr td').length > 1) {
        $('#plansTable').DataTable({
            order: [[7, 'desc']], pageLength: 25,
            language: { search: 'Search plans:' }
        });
    }
});

new TomSelect('#staffSelect', {
    placeholder: 'Search staff...', maxOptions: 100, allowEmptyOption: true
});

document.getElementById('selectAll')?.addEventListener('change', function () {
    document.querySelectorAll('.plan-chk').forEach(c => c.checked = this.checked);
});

function quickApprove(id) {
    if (!confirm('Approve this plan?')) return;
    document.getElementById('approvePlanId').value = id;
    document.getElementById('approveForm').submit();
}

function openReject(id) {
    document.getElementById('rejectPlanId').value = id;
    document.getElementById('rejectModal').style.display = 'flex';
}

function closeReject() {
    document.getElementById('rejectModal').style.display = 'none';
}
</script>
<?php include '../../includes/footer.php'; ?>