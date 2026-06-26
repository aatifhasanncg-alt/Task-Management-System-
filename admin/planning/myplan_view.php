<?php
/**
 * consulting/staff/plan_view.php — Staff: View Work Plan
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

$planId = (int) ($_GET['id'] ?? 0);
if (!$planId) {
    header('Location: plan_list.php');
    exit;
}

$planStmt = $db->prepare("
    SELECT wp.*, u.full_name AS planner_name,
           ab.full_name AS approver_name
    FROM work_plans wp
    LEFT JOIN users u  ON u.id  = wp.user_id
    LEFT JOIN users ab ON ab.id = wp.approved_by
    WHERE wp.id=? AND wp.user_id=?
");
$planStmt->execute([$planId, $uid]);
$plan = $planStmt->fetch();
if (!$plan) {
    header('Location: plan_list.php');
    exit;
}

$entries = $db->prepare("
    SELECT wpe.*, c.company_name, c.company_code,
           sv.full_name AS supervisor_name,
           sv.employee_id AS supervisor_emp_id
    FROM work_plan_entries wpe
    LEFT JOIN companies c  ON c.id  = wpe.client_id
    LEFT JOIN users     sv ON sv.id = wpe.supervisor_id
    WHERE wpe.plan_id=?
    ORDER BY wpe.plan_date ASC, wpe.planned_time_in ASC
");
$entries->execute([$planId]);
$entries = $entries->fetchAll();

// Group by date
$byDate = [];
foreach ($entries as $e) {
    $byDate[$e['plan_date']][] = $e;
}

$monthLabel = date('F Y', strtotime($plan['plan_month']));
$pageTitle = 'Work Plan — Week ' . $plan['week_number'];
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">

<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-briefcase"></i> Consulting</div>
                        <h4>Work Plan — Week <?= $plan['week_number'] ?></h4>
                        <p><?= $monthLabel ?> · <?= date('d M', strtotime($plan['week_start_date'])) ?> –
                            <?= date('d M', strtotime($plan['week_end_date'])) ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <?php if (in_array($plan['status'], ['draft', 'rejected'])): ?>
                            <a href="plan_edit.php?id=<?= $planId ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-edit me-1"></i> Edit
                            </a>
                        <?php endif; ?>
                        <?php if ($plan['status'] === 'draft'): ?>
                            <form method="POST" action="plan_submit.php" class="d-inline"
                                onsubmit="return confirm('Submit for approval?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="plan_id" value="<?= $planId ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-paper-plane me-1"></i> Submit
                                </button>
                            </form>
                        <?php endif; ?>
                        <a href="my_plans.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-history me-1"></i> My Plans
                        </a>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <?= flashHtml() ?>

            <!-- Status banner -->
            <?php if ($plan['status'] === 'rejected' && $plan['remarks']): ?>
                <div class="cn-alert cn-alert-danger" style="margin-bottom:16px;">
                    <div style="font-weight:700;font-size:.84rem;margin-bottom:4px;">
                        <i class="fas fa-times-circle me-1"></i>Rejected
                    </div>
                    <div style="font-size:.8rem;"><?= htmlspecialchars($plan['remarks']) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($plan['status'] === 'approved'): ?>
                <div class="cn-alert cn-alert-success" style="margin-bottom:16px;">
                    <div style="font-weight:700;font-size:.84rem;">
                        <i class="fas fa-check-circle me-1"></i>Approved
                        <?php if ($plan['approver_name']): ?> by
                            <?= htmlspecialchars($plan['approver_name']) ?>     <?php endif; ?>
                        <?php if ($plan['approved_at']): ?> ·
                            <?= date('d M Y H:i', strtotime($plan['approved_at'])) ?>     <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

                <!-- LEFT -->
                <div>
                    <?php if (empty($byDate)): ?>
                        <div class="cn-panel">
                            <div style="padding:40px;text-align:center;color:#9ca3af;">
                                <i class="fas fa-calendar-times"
                                    style="font-size:2rem;margin-bottom:10px;display:block;"></i>
                                <div style="font-size:.85rem;font-weight:600;">No entries in this plan</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($byDate as $date => $dayEntries): ?>
                            <div class="cn-panel mb-3">
                                <div class="cn-panel-hd" style="justify-content:space-between;">
                                    <span class="cn-panel-title">
                                        <i class="fas fa-calendar-day me-2" style="color:var(--gold)"></i>
                                        <?= date('l, d M Y', strtotime($date)) ?>
                                    </span>
                                    <span style="font-size:.72rem;color:#9ca3af;"><?= count($dayEntries) ?> visit(s)</span>
                                </div>
                                <div style="padding:0;">
                                    <table class="cn-table">
                                        <thead>
                                            <tr>
                                                <th>Client</th>
                                                <th>Time In</th>
                                                <th>Time Out</th>
                                                <th class="text-center">Hours</th>
                                                <th>Supervisor</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($dayEntries as $e): ?>
                                                <tr>
                                                    <td>
                                                        <div style="font-weight:600;font-size:.82rem;">
                                                            <?= htmlspecialchars($e['company_name'] ?? '—') ?></div>
                                                        <div style="font-size:.69rem;color:#9ca3af;">
                                                            <?= htmlspecialchars($e['company_code'] ?? '') ?></div>
                                                    </td>
                                                    <td style="font-size:.81rem;">
                                                        <?= $e['planned_time_in'] ? date('h:i A', strtotime($e['planned_time_in'])) : '—' ?>
                                                    </td>
                                                    <td style="font-size:.81rem;">
                                                        <?= $e['planned_time_out'] ? date('h:i A', strtotime($e['planned_time_out'])) : '—' ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <strong
                                                            style="color:#c9a84c;"><?= number_format((float) $e['planned_hours'], 1) ?>h</strong>
                                                    </td>
                                                    <td style="font-size:.77rem;">
                                                        <?php if (!empty($e['supervisor_name'])): ?>
                                                            <div style="font-weight:600;color:#374151;">
                                                                <?= htmlspecialchars($e['supervisor_name']) ?>
                                                            </div>
                                                            <?php if (!empty($e['supervisor_emp_id'])): ?>
                                                            <div style="font-size:.69rem;color:#9ca3af;">
                                                                <?= htmlspecialchars($e['supervisor_emp_id']) ?>
                                                            </div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span style="color:#d1d5db;">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="font-size:.77rem;color:#6b7280;">
                                                        <?= htmlspecialchars($e['notes'] ?? '—') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- RIGHT -->
                <div>
                    <div class="cn-panel mb-3">
                        <div class="cn-panel-hd">
                            <span class="cn-panel-title">
                                <i class="fas fa-chart-bar me-2" style="color:var(--gold)"></i>Plan Summary
                            </span>
                        </div>
                        <div style="padding:14px 16px;">
                            <div style="display:flex;flex-direction:column;gap:10px;">
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Status</span>
                                    <?= planBadge($plan['status']) ?>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Total Entries</span>
                                    <strong><?= count($entries) ?></strong>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Total Planned Hours</span>
                                    <strong
                                        style="color:#c9a84c;"><?= number_format(array_sum(array_column($entries, 'planned_hours')), 1) ?>h</strong>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Unique Clients</span>
                                    <strong><?= count(array_unique(array_column($entries, 'client_id'))) ?></strong>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                    <span style="color:#9ca3af;">Working Days</span>
                                    <strong><?= count($byDate) ?></strong>
                                </div>
                            </div>
                            <?php if ($plan['remarks']): ?>
                                <div style="margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;">
                                    <div style="font-size:.75rem;color:#6b7280;">
                                        <strong style="display:block;margin-bottom:4px;color:#374151;">Remarks</strong>
                                        <?= htmlspecialchars($plan['remarks']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cn-panel">
                        <div class="cn-panel-hd">
                            <span class="cn-panel-title">
                                <i class="fas fa-save me-2" style="color:var(--gold)"></i>Actions
                            </span>
                        </div>
                        <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px;">
                            <a href="log_create.php" class="cn-btn cn-btn-gold" style="justify-content:center;">
                                <i class="fas fa-clock me-2"></i>Log a Visit
                            </a>
                            <?php if (in_array($plan['status'], ['draft', 'rejected'])): ?>
                                <a href="plan_edit.php?id=<?= $planId ?>" class="cn-btn cn-btn-blue"
                                    style="justify-content:center;">
                                    <i class="fas fa-edit me-1"></i> Edit Plan
                                </a>
                            <?php endif; ?>
                            <?php if ($plan['status'] === 'draft'): ?>
                                <form method="POST" action="plan_submit.php"
                                    onsubmit="return confirm('Submit for approval?')">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="plan_id" value="<?= $planId ?>">
                                    <button type="submit" class="cn-btn cn-btn-out"
                                        style="justify-content:center;width:100%;">
                                        <i class="fas fa-paper-plane me-1"></i> Submit for Approval
                                    </button>
                                </form>
                            <?php endif; ?>
                            <a href="plan_list.php" class="cn-btn cn-btn-out" style="justify-content:center;">
                                <i class="fas fa-times me-1"></i> Back to Plans
                            </a>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>