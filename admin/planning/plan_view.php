<?php
/**
 * consulting/admin/plan_view.php — Admin: View Any Work Plan
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAdmin();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];
$planId = (int) ($_GET['id'] ?? 0);
if (!$planId) {
    header('Location: plan_list.php');
    exit;
}

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

// ── Load plan ─────────────────────────────────────────────────
$planStmt = $db->prepare("
    SELECT wp.*, u.full_name AS planner_name, u.employee_id,
           ab.full_name AS approver_name,
           d.dept_name, b.branch_name
    FROM work_plans wp
    LEFT JOIN users u  ON u.id  = wp.user_id
    LEFT JOIN users ab ON ab.id = wp.approved_by
    LEFT JOIN departments d ON d.id = wp.department_id
    LEFT JOIN branches b ON b.id = wp.branch_id
    WHERE wp.id = ?
");
$planStmt->execute([$planId]);
$plan = $planStmt->fetch();
if (!$plan) {
    header('Location: plan_list.php');
    exit;
}
// ── Access check: only show if login user is managed_by of plan owner ──
// ── Access check: supervisor_id = login user OR managed_by = login user ──
// ── Is login user a manager of the plan owner? ────────────────
$canManageStmt = $db->prepare("
    SELECT 1 FROM users
    WHERE id = ? AND managed_by = ?
    UNION
    SELECT 1 FROM user_department_assignments
    WHERE user_id = ? AND managed_by = ?
    LIMIT 1
");
$canManageStmt->execute([$plan['user_id'], $uid, $plan['user_id'], $uid]);
$canManage = (bool) $canManageStmt->fetch();

// ── Is login user supervisor on any entry of THIS plan? ───────
$isSupervisorStmt = $db->prepare("
    SELECT 1 FROM work_plan_entries
    WHERE plan_id = ? AND supervisor_id = ?
    LIMIT 1
");
$isSupervisorStmt->execute([$planId, $uid]);
$isSupervisor = (bool) $isSupervisorStmt->fetch();

$isOwner = ($plan['user_id'] === $uid);

// ── Hard gate ─────────────────────────────────────────────────
// ── Hard gate: ONLY manager (managed_by) can view ─────────────
if (!$canManage && !$isOwner && !$isSupervisor) {
    setFlash('error', 'You do not have permission to view this plan.');
    header('Location: plan_list.php');
    exit;
}
$entries = $db->prepare("
    SELECT wpe.*, c.company_name, c.company_code,
           u.full_name  AS assigned_name,
           sv.full_name AS supervisor_name
    FROM work_plan_entries wpe
    LEFT JOIN companies c  ON c.id  = wpe.client_id
    LEFT JOIN users u      ON u.id  = wpe.assigned_to
    LEFT JOIN users sv     ON sv.id = wpe.supervisor_id
    WHERE wpe.plan_id = ?
    ORDER BY wpe.plan_date ASC, wpe.planned_time_in ASC
");
$entries->execute([$planId]);
$entries = $entries->fetchAll();
// Supervisor name for the summary sidebar (first entry that has one)
$supervisorName = '—';
foreach ($entries as $e) {
    if (!empty($e['supervisor_name'])) {
        $supervisorName = $e['supervisor_name'];
        break;
    }
}
// Check if there are actual logs linked to this plan
$logCount = 0;

$byDate = [];
foreach ($entries as $e) {
    $byDate[$e['plan_date']][] = $e;
}

$monthLabel = date('F Y', strtotime($plan['plan_month']));
$pageTitle = 'Plan View — ' . $plan['planner_name'];
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">

<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge">
                            <i class="fas fa-calendar-week"></i> Consulting
                        </div>
                        <h4>Work Plan View</h4>
                        <p>
                            <?= htmlspecialchars($plan['planner_name']) ?> · Week <?= $plan['week_number'] ?> ·
                            <?= $monthLabel ?>
                        </p>
                    </div>

                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="plan_list.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back
                        </a>
                    </div>
                </div>
            </div>
            <div class="row g-3 mb-4">
                <?php
                $kpis = [
                    ['fa-user', '#3b82f6', '#eff6ff', 'Staff', $plan['planner_name']],
                    ['fa-calendar', '#8b5cf6', '#eef2ff', 'Week', 'W' . $plan['week_number']],
                    ['fa-list', '#10b981', '#ecfdf5', 'Entries', count($entries)],
                    ['fa-clock', '#c9a84c', '#fefce8', 'Planned Hours', number_format(array_sum(array_column($entries, 'planned_hours')), 1) . 'h'],
                    ['fa-check-circle', '#10b981', '#ecfdf5', 'Logs', $logCount],
                ];
                foreach ($kpis as [$icon, $col, $bg, $label, $value]):
                    ?>
                    <div class="col-6 col-md-4 col-xl-2">
                        <div style="background:<?= $bg ?>;border-radius:12px;border:1px solid <?= $col ?>22;padding:1rem;">
                            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.65rem;">
                                <div
                                    style="width:36px;height:36px;border-radius:12px;background:<?= $col ?>22;color:<?= $col ?>;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas <?= $icon ?>"></i>
                                </div>
                                <div style="font-size:.78rem;font-weight:600;color:#6b7280;"><?= $label ?></div>
                            </div>
                            <div style="font-size:1.2rem;font-weight:800;color:#1f2937;">
                                <?= htmlspecialchars((string) $value) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?= flashHtml() ?>

            <?php if ($plan['status'] === 'rejected' && $plan['remarks']): ?>
                <div class="cn-alert cn-alert-danger mb-3">
                    <i class="fas fa-times-circle me-2"></i><strong>Rejected:</strong>
                    <?= htmlspecialchars($plan['remarks']) ?>
                </div>
            <?php elseif ($plan['status'] === 'approved'): ?>
                <div class="cn-alert cn-alert-success mb-3">
                    <i class="fas fa-check-circle me-2"></i><strong>Approved</strong>
                    <?php if ($plan['approver_name']): ?> by <?= htmlspecialchars($plan['approver_name']) ?><?php endif; ?>
                    <?php if ($plan['approved_at']): ?> on
                        <?= date('d M Y H:i', strtotime($plan['approved_at'])) ?>     <?php endif; ?>
                </div>

            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-8">

                    <?php foreach ($byDate as $date => $dayEntries): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-table me-2 text-warning"></i>Plan Entries</h5>
                                <span style="font-size:.78rem;color:#9ca3af;">
                                    <?= count($dayEntries) ?> entries
                                </span>
                            </div>

                            <div class="card-mis-body p-0">

                                <?php if (empty($dayEntries)): ?>
                                    <div class="empty-state p-4">
                                        <i class="fas fa-calendar-times"></i>
                                        <h6>No entries for this date</h6>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive cn-table-wrap">
                                        <table class="cn-table">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Client</th>
                                                    <th>Staff</th>
                                                    <th>Supervisor</th>
                                                    <th>Time</th>
                                                    <th class="text-center">Hours</th>
                                                    <th>Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>

                                                <?php foreach ($dayEntries as $e): ?>
                                                    <tr>
                                                        <td style="font-size:.78rem;color:#6b7280;">
                                                            <?= date('d M', strtotime($e['plan_date'])) ?>
                                                        </td>

                                                        <td>
                                                            <div style="font-weight:600;font-size:.82rem;">
                                                                <?= htmlspecialchars($e['company_name'] ?? '—') ?>
                                                            </div>
                                                            <div style="font-size:.68rem;color:#9ca3af;">
                                                                <?= htmlspecialchars($e['company_code'] ?? '') ?>
                                                            </div>
                                                        </td>

                                                        <td><?= htmlspecialchars($e['assigned_name'] ?? '—') ?></td>

                                                        <td style="font-size:.82rem;">
                                                            <?php if (!empty($e['supervisor_name'])): ?>
                                                                <span
                                                                    style="font-weight:600;"><?= htmlspecialchars($e['supervisor_name']) ?></span>
                                                            <?php else: ?>
                                                                <span style="color:#d1d5db;">—</span>
                                                            <?php endif; ?>
                                                        </td>

                                                        <td style="font-size:.78rem;">
                                                            <?= $e['planned_time_in'] ? date('h:i A', strtotime($e['planned_time_in'])) : '—' ?>
                                                            -
                                                            <?= $e['planned_time_out'] ? date('h:i A', strtotime($e['planned_time_out'])) : '—' ?>
                                                        </td>

                                                        <td class="text-center">
                                                            <strong style="color:#c9a84c;">
                                                                <?= number_format((float) $e['planned_hours'], 1) ?>h
                                                            </strong>
                                                        </td>

                                                        <td style="font-size:.75rem;color:#6b7280;max-width:200px;">
                                                            <?php if (!empty($e['notes'])): ?>
                                                                <span title="<?= htmlspecialchars($e['notes']) ?>"
                                                                    style="display:block;white-space:pre-wrap;word-break:break-word;">
                                                                    <?= nl2br(htmlspecialchars($e['notes'])) ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span style="color:#d1d5db;">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>

                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($byDate)): ?>
                        <div class="card-mis">
                            <div class="card-mis-body">
                                <div class="empty-state"><i class="fas fa-calendar-times"></i>
                                    <h6>No entries in this plan</h6>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
                <div class="col-lg-4">
                    <div class="card-mis mb-3">
                        <div class="card-mis-header">
                            <h5>Plan Summary</h5>
                        </div>
                        <div class="card-mis-body">
                            <div style="display:flex;flex-direction:column;gap:10px;">
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Staff</span>
                                    <strong><?= htmlspecialchars($plan['planner_name']) ?></strong>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Employee ID</span>
                                    <strong><?= htmlspecialchars($plan['employee_id'] ?? '—') ?></strong>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Department</span>
                                    <strong><?= htmlspecialchars($plan['dept_name'] ?? '—') ?></strong>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Status</span>
                                    <?= planBadge($plan['status']) ?>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Total Entries</span>
                                    <strong><?= count($entries) ?></strong>
                                </div>
                                <!-- Add after the "Status" row in the summary table: -->
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Your Access</span>
                                    <?php if ($canManage): ?>
                                        <span
                                            style="background:#ecfdf5;color:#065f46;font-size:.72rem;padding:2px 8px;border-radius:6px;font-weight:700;">
                                            <i class="fas fa-user-shield me-1"></i>Manager
                                        </span>
                                    <?php elseif ($isSupervisor): ?>
                                        <span
                                            style="background:#eff6ff;color:#1e40af;font-size:.72rem;padding:2px 8px;border-radius:6px;font-weight:700;">
                                            <i class="fas fa-eye me-1"></i>Supervisor
                                        </span>
                                    <?php else: ?>
                                        <span
                                            style="background:#f3f4f6;color:#6b7280;font-size:.72rem;padding:2px 8px;border-radius:6px;font-weight:700;">
                                            Owner
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Planned Hours</span>
                                    <strong
                                        style="color:#c9a84c;"><?= number_format(array_sum(array_column($entries, 'planned_hours')), 1) ?>h</strong>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Actual Logs</span>
                                    <strong
                                        style="color:<?= $logCount > 0 ? '#10b981' : '#9ca3af' ?>;"><?= $logCount ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($plan['status'] === 'submitted' && $canManage): ?>
                        <div class="card-mis p-3" style="border-left:3px solid #f59e0b;">
                            <p style="font-weight:700;font-size:.82rem;margin-bottom:10px;color:#b45309;">
                                <i class="fas fa-hourglass-half me-1"></i>Quick Review
                            </p>
                            <form method="POST" action="plan_approvals.php"
                                style="display:flex;flex-direction:column;gap:8px;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="plan_id" value="<?= $planId ?>">
                                <textarea name="remarks" class="form-control" rows="2"
                                    placeholder="Remarks (optional for approve, required for reject)"></textarea>
                                <div style="display:flex;gap:6px;">
                                    <button type="submit" name="action" value="approve"
                                        class="cn-btn cn-btn-success cn-btn-sm flex-fill"
                                        onclick="return confirm('Approve?')">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button type="submit" name="action" value="reject"
                                        class="cn-btn cn-btn-danger cn-btn-sm flex-fill"
                                        onclick="return confirm('Reject?')">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>