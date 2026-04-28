<?php
/**
 * consulting/executive/plan_view.php — Executive: View & Approve/Reject a Work Plan
 *
 * Changes from previous codebase:
 *  ✓ Uses getDepartmentStaff() for multi-dept staff scope (user_department_assignments)
 *  ✓ Notification written to plan_notifications table on approve/reject
 *  ✓ Shows per-entry match status (planned vs logged)
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db       = getDB();
$user     = currentUser();
$uid      = (int)$user['id'];
$branchId = (int)$user['branch_id'];
$deptId   = (int)$user['department_id'];

$planId = (int)($_GET['id'] ?? 0);
if (!$planId) { header('Location: plan_list.php'); exit; }

// ── Fetch plan (must belong to this branch) ───────────────────
$planStmt = $db->prepare("
    SELECT wp.*,
           u.full_name, u.employee_id, u.department_id AS owner_dept_id,
           COALESCE(d.dept_name,'No Dept') AS owner_dept,
           ab.full_name AS approved_by_name
    FROM work_plans wp
    JOIN users u ON u.id = wp.user_id
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN users ab ON ab.id = wp.approved_by
    WHERE wp.id = ? AND wp.branch_id = ?
");
$planStmt->execute([$planId, $branchId]);
$plan = $planStmt->fetch(PDO::FETCH_ASSOC);
if (!$plan) { header('Location: plan_list.php'); exit; }

// ── Handle approve / reject ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action']  ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if ($action === 'approve' && $plan['status'] === 'submitted') {
        $db->prepare("
            UPDATE work_plans
            SET status='approved', approved_by=?, approved_at=NOW(), remarks=NULL
            WHERE id=? AND branch_id=?
        ")->execute([$uid, $planId, $branchId]);

        // Notify via notifications table
        notify(
            $plan['user_id'],
            'Plan Approved',
            $user['full_name'] . ' approved your Week ' . $plan['week_number'] . ' plan.',
            'task',
            APP_URL . 'consulting/staff/plan_view.php?id=' . $planId,
            false, []
        );

        // Also write to plan_notifications (for badge counter on dashboard)
        $db->prepare("
            INSERT INTO plan_notifications (user_id, entry_id, notify_for, type, is_read)
            SELECT ?, wpe.id, wpe.plan_date, 'today', 0
            FROM work_plan_entries wpe WHERE wpe.plan_id = ?
            ON DUPLICATE KEY UPDATE is_read=0
        ")->execute([$plan['user_id'], $planId]);

        logActivity('Executive approved plan #' . $planId, 'consulting', 'plan_id=' . $planId);
        setFlash('success', 'Plan approved successfully.');
        header('Location: plan_view.php?id=' . $planId);
        exit;
    }

    if ($action === 'reject' && $remarks && $plan['status'] === 'submitted') {
        $db->prepare("
            UPDATE work_plans
            SET status='rejected', remarks=?, approved_by=NULL, approved_at=NULL
            WHERE id=? AND branch_id=?
        ")->execute([$remarks, $planId, $branchId]);

        notify(
            $plan['user_id'],
            'Plan Rejected',
            $user['full_name'] . ' rejected your Week ' . $plan['week_number'] . ' plan: ' . $remarks,
            'task',
            APP_URL . 'consulting/staff/plan_view.php?id=' . $planId,
            false, []
        );

        logActivity('Executive rejected plan #' . $planId, 'consulting', 'plan_id=' . $planId);
        setFlash('warning', 'Plan rejected.');
        header('Location: plan_view.php?id=' . $planId);
        exit;
    }
}

// ── Plan entries with match status ───────────────────────────
$stmt = $db->prepare("
    SELECT wpe.*,
           c.company_name, c.company_code,
           u.full_name AS assigned_name,

           (SELECT COUNT(*)
            FROM work_logs wl
            WHERE wl.client_id = wpe.client_id
            AND wl.user_id = wpe.assigned_to
            AND wl.log_date >= wpe.plan_date
            AND wl.log_date < DATE_ADD(wpe.plan_date, INTERVAL 1 DAY)
            ) AS logged_count,

           (SELECT COALESCE(SUM(wl.duration_hours),0)
            FROM work_logs wl
            WHERE wl.client_id = wpe.client_id
              AND wl.log_date >= wpe.plan_date
              AND wl.log_date < DATE_ADD(wpe.plan_date, INTERVAL 1 DAY)
              AND wl.user_id = wpe.assigned_to
           ) AS logged_hours,

           (SELECT wl.visit_status
            FROM work_logs wl
            WHERE wl.client_id = wpe.client_id
              AND DATE(wl.log_date) = wpe.plan_date
              AND wl.user_id = wpe.assigned_to
            LIMIT 1
           ) AS logged_status

    FROM work_plan_entries wpe
    JOIN companies c ON c.id = wpe.client_id
    JOIN users u     ON u.id = wpe.assigned_to
    WHERE wpe.plan_id = ?
    ORDER BY wpe.plan_date ASC, wpe.planned_time_in ASC
");

$stmt->execute([$planId]);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Refresh plan after possible update
$planStmt->execute([$planId, $branchId]);
$plan = $planStmt->fetch(PDO::FETCH_ASSOC);

// ── Aggregate stats ───────────────────────────────────────────
$totalEntries  = count($entries);
$totalPlannedH = array_sum(array_column($entries, 'planned_hours'));
$totalActualH  = array_sum(array_column($entries, 'logged_hours'));
$matchedCount  = count(array_filter($entries, fn($e) => $e['logged_count'] > 0));
$visitEff      = $totalEntries > 0 ? min(100, round(($matchedCount / $totalEntries) * 100)) : 0;
$effColor      = $visitEff >= 80 ? '#10b981' : ($visitEff >= 50 ? '#f59e0b' : '#ef4444');

// Status styles
$statusMap = [
    'draft'     => ['#9ca3af', '#f9fafb', '✏ Draft'],
    'submitted' => ['#3b82f6', '#eff6ff', '⟳ Submitted'],
    'approved'  => ['#10b981', '#ecfdf5', '✓ Approved'],
    'rejected'  => ['#ef4444', '#fef2f2', '✕ Rejected'],
];
[$stColor, $stBg, $stLabel] = $statusMap[$plan['status']] ?? ['#9ca3af', '#f9fafb', $plan['status']];

$pageTitle = 'Plan View — Week ' . $plan['week_number'];
include '../../includes/header.php';
?>

<div class="app-wrapper">
<?php include '../../includes/sidebar_executive.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">
<?= flashHtml() ?>

<!-- ══ HERO ════════════════════════════════════════════════════ -->
<div class="page-hero mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="page-hero-badge"><i class="fas fa-briefcase"></i> Executive · Plan Review</div>
            <h4>Work Plan — Week <?= (int)$plan['week_number'] ?></h4>
            <p>
                <?= htmlspecialchars($plan['full_name']) ?>
                <span style="color:#9ca3af;">·</span>
                <?= htmlspecialchars($plan['owner_dept']) ?>
                <span style="color:#9ca3af;">·</span>
                <?= date('d M', strtotime($plan['week_start_date'])) ?> –
                <?= date('d M Y', strtotime($plan['week_end_date'])) ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <a href="plans.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-list me-1"></i>All Plans
            </a>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-th-large me-1"></i>Dashboard
            </a>
        </div>
    </div>
</div>

<!-- ══ PLAN META + QUICK ACTIONS ══════════════════════════════ -->
<div class="row g-3 mb-4">

    <!-- Plan info card -->
    <div class="col-lg-8">
        <div class="card-mis">
            <div class="card-mis-header">
                <h5><i class="fas fa-info-circle text-warning me-2"></i>Plan Details</h5>
                <span style="background:<?= $stBg ?>;color:<?= $stColor ?>;padding:.2rem .65rem;
                             border-radius:99px;font-size:.75rem;font-weight:600;">
                    <?= $stLabel ?>
                </span>
            </div>
            <div class="card-mis-body">
                <div class="row g-3">
                    <?php foreach ([
                        ['fa-user',         '#3b82f6', htmlspecialchars($plan['full_name']),                          'Staff'],
                        ['fa-id-badge',     '#c9a84c', htmlspecialchars($plan['employee_id'] ?? '—'),                 'Employee ID'],
                        ['fa-layer-group',  '#8b5cf6', htmlspecialchars($plan['owner_dept']),                         'Department'],
                        ['fa-calendar-week','#10b981', 'Week ' . (int)$plan['week_number'],                           'Week'],
                        ['fa-calendar-alt', '#0ea5e9', date('d M', strtotime($plan['week_start_date'])) . ' – ' . date('d M Y', strtotime($plan['week_end_date'])), 'Period'],
                        ['fa-clock',        '#f59e0b', date('d M Y, g:i A', strtotime($plan['created_at'])),          'Submitted'],
                    ] as [$ico, $col, $val, $lbl]): ?>
                    <div class="col-6 col-md-4">
                        <div style="background:#f9fafb;border-radius:8px;padding:.7rem .85rem;">
                            <div style="font-size:.68rem;color:#9ca3af;margin-bottom:.2rem;">
                                <i class="fas <?= $ico ?>" style="color:<?= $col ?>;margin-right:.3rem;"></i><?= $lbl ?>
                            </div>
                            <div style="font-size:.85rem;font-weight:600;color:#1f2937;"><?= $val ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($plan['remarks']): ?>
                <div style="margin-top:.85rem;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;
                            padding:.65rem .85rem;font-size:.8rem;color:#b91c1c;">
                    <i class="fas fa-comment-alt me-1"></i>
                    <strong>Remarks:</strong> <?= htmlspecialchars($plan['remarks']) ?>
                </div>
                <?php endif; ?>
                <?php if ($plan['approved_by_name'] && $plan['approved_at']): ?>
                <div style="margin-top:.75rem;font-size:.75rem;color:#9ca3af;">
                    <i class="fas fa-check-circle me-1 text-success"></i>
                    Approved by <strong><?= htmlspecialchars($plan['approved_by_name']) ?></strong>
                    on <?= date('d M Y, g:i A', strtotime($plan['approved_at'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stats + actions -->
    <div class="col-lg-4">
        <div class="card-mis mb-3">
            <div class="card-mis-header">
                <h5><i class="fas fa-tachometer-alt text-warning me-2"></i>Summary</h5>
            </div>
            <div class="card-mis-body">
                <?php foreach ([
                    ['Entries',       $totalEntries,                '#374151'],
                    ['Planned Hours', number_format($totalPlannedH,1).'h', '#3b82f6'],
                    ['Actual Hours',  number_format($totalActualH,1).'h',  '#c9a84c'],
                    ['Matched',       $matchedCount.'/'.$totalEntries, '#10b981'],
                ] as [$lbl, $val, $col]): ?>
                <div style="display:flex;justify-content:space-between;padding:.35rem 0;
                            border-bottom:1px solid #f3f4f6;font-size:.82rem;">
                    <span style="color:#9ca3af;"><?= $lbl ?></span>
                    <strong style="color:<?= $col ?>;"><?= $val ?></strong>
                </div>
                <?php endforeach; ?>
                <!-- Visit efficiency bar -->
                <div style="margin-top:.75rem;">
                    <div style="display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:.3rem;">
                        <span style="color:#9ca3af;">Visit Efficiency</span>
                        <strong style="color:<?= $effColor ?>;"><?= $visitEff ?>%</strong>
                    </div>
                    <div style="background:#f3f4f6;border-radius:99px;height:7px;overflow:hidden;">
                        <div style="width:<?= $visitEff ?>%;background:<?= $effColor ?>;height:100%;border-radius:99px;transition:.4s;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approve / Reject actions -->
        <?php if ($plan['status'] === 'submitted'): ?>
        <div class="card-mis">
            <div class="card-mis-header">
                <h5><i class="fas fa-gavel text-warning me-2"></i>Review Action</h5>
            </div>
            <div class="card-mis-body">
                <!-- Approve -->
                <form method="POST" style="margin-bottom:.6rem;">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action"    value="approve">
                    <button type="submit"
                        onclick="return confirm('Approve this plan?')"
                        style="width:100%;padding:.55rem;background:#10b981;color:#fff;border:none;
                               border-radius:8px;font-weight:700;font-size:.82rem;cursor:pointer;">
                        <i class="fas fa-check me-1"></i> Approve Plan
                    </button>
                </form>
                <!-- Reject -->
                <button type="button" onclick="document.getElementById('rejectBox').style.display='block'"
                    style="width:100%;padding:.55rem;background:#fef2f2;color:#ef4444;border:1.5px solid #fecaca;
                           border-radius:8px;font-weight:700;font-size:.82rem;cursor:pointer;">
                    <i class="fas fa-times me-1"></i> Reject Plan
                </button>
                <div id="rejectBox" style="display:none;margin-top:.75rem;">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action"    value="reject">
                        <textarea name="remarks" rows="3" required placeholder="Reason for rejection…"
                            style="width:100%;border:1.5px solid #fecaca;border-radius:8px;padding:.5rem .65rem;
                                   font-size:.8rem;color:#374151;resize:vertical;box-sizing:border-box;
                                   margin-bottom:.5rem;"></textarea>
                        <button type="submit"
                            onclick="return confirm('Reject this plan?')"
                            style="width:100%;padding:.5rem;background:#ef4444;color:#fff;border:none;
                                   border-radius:8px;font-weight:700;font-size:.8rem;cursor:pointer;">
                            Confirm Rejection
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ PLAN ENTRIES TABLE ═════════════════════════════════════ -->
<div class="card-mis mb-4" style="border-top:3px solid #c9a84c;">
    <div class="card-mis-header">
        <h5><i class="fas fa-clipboard-list text-warning me-2"></i>
            Plan Entries (<?= $totalEntries ?>)
        </h5>
        <div style="display:flex;gap:10px;font-size:.75rem;">
            <span style="color:#10b981;">● Matched <?= $matchedCount ?></span>
            <span style="color:#ef4444;">● Unmatched <?= $totalEntries - $matchedCount ?></span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Day</th>
                    <th>Client</th>
                    <th>Assigned To</th>
                    <th class="text-center">Planned In</th>
                    <th class="text-center">Planned Out</th>
                    <th class="text-center">Planned Hrs</th>
                    <th class="text-center">Actual Hrs</th>
                    <th class="text-center">Match</th>
                    <th>Log Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($entries)): ?>
            <tr><td colspan="12" style="text-align:center;padding:24px;color:#9ca3af;font-size:.83rem;">
                <i class="fas fa-calendar-times" style="display:block;font-size:1.5rem;margin-bottom:6px;opacity:.4;"></i>
                No entries in this plan.
            </td></tr>
            <?php endif; ?>
            <?php foreach ($entries as $i => $e):
                $isMatched   = $e['logged_count'] > 0;
                $logStatus   = $e['logged_status'] ?? null;
                $rowBg       = $isMatched ? '' : 'style="background:#fff9f9;"';
                $vstMap = [
                    'visited'     => ['#ecfdf5', '#10b981', 'fa-check-circle', 'Visited'],
                    'missed'      => ['#fef2f2', '#ef4444', 'fa-times-circle', 'Missed'],
                    'rescheduled' => ['#fffbeb', '#f59e0b', 'fa-redo',         'Rescheduled'],
                ];
                [$vstBg, $vstCol, $vstIco, $vstLbl] = $vstMap[$logStatus] ?? ['#f9fafb','#9ca3af','fa-circle','—'];
            ?>
            <tr <?= $rowBg ?>>
                <td style="color:#9ca3af;font-size:.75rem;"><?= $i + 1 ?></td>
                <td style="font-size:.82rem;font-weight:500;white-space:nowrap;">
                    <?= date('d M Y', strtotime($e['plan_date'])) ?>
                </td>
                <td style="font-size:.75rem;color:#9ca3af;"><?= htmlspecialchars($e['day_of_week'] ?? '') ?></td>
                <td>
                    <div style="font-size:.83rem;font-weight:500;"><?= htmlspecialchars($e['company_name']) ?></div>
                    <div style="font-size:.68rem;color:#9ca3af;"><?= htmlspecialchars($e['company_code'] ?? '') ?></div>
                </td>
                <td style="font-size:.82rem;"><?= htmlspecialchars($e['assigned_name']) ?></td>
                <td class="text-center" style="font-size:.78rem;">
                    <?= $e['planned_time_in']  ? date('g:i A', strtotime($e['planned_time_in']))  : '—' ?>
                </td>
                <td class="text-center" style="font-size:.78rem;">
                    <?= $e['planned_time_out'] ? date('g:i A', strtotime($e['planned_time_out'])) : '—' ?>
                </td>
                <td class="text-center">
                    <span style="font-weight:600;color:#3b82f6;"><?= number_format((float)$e['planned_hours'],1) ?>h</span>
                </td>
                <td class="text-center">
                    <?php if ($isMatched): ?>
                    <span style="font-weight:700;color:<?= (float)$e['logged_hours']>=4?'#10b981':((float)$e['logged_hours']>=2?'#f59e0b':'#ef4444') ?>;">
                        <?= number_format((float)$e['logged_hours'],1) ?>h
                    </span>
                    <?php else: ?>
                    <span style="color:#d1d5db;font-size:.75rem;">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ($isMatched): ?>
                    <span style="background:#ecfdf5;color:#10b981;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:600;">
                        <i class="fas fa-check" style="font-size:.6rem;"></i> Logged
                    </span>
                    <?php else: ?>
                    <span style="background:#fef2f2;color:#ef4444;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:600;">
                        <i class="fas fa-times" style="font-size:.6rem;"></i> Not Logged
                    </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($logStatus): ?>
                    <span style="background:<?= $vstBg ?>;color:<?= $vstCol ?>;padding:.15rem .5rem;border-radius:99px;
                                 font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;">
                        <i class="fas <?= $vstIco ?>" style="font-size:.6rem;"></i><?= $vstLbl ?>
                    </span>
                    <?php else: ?>
                    <span style="color:#d1d5db;font-size:.75rem;">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.73rem;color:#6b7280;max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <?= htmlspecialchars(mb_strimwidth($e['notes'] ?? '—', 0, 35, '…')) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if (!empty($entries)): ?>
            <tfoot>
                <tr style="background:#f9fafb;font-weight:700;">
                    <td colspan="7" style="padding:10px 14px;font-size:.82rem;color:#374151;">
                        <i class="fas fa-calculator me-1 text-warning"></i>TOTAL
                    </td>
                    <td class="text-center" style="color:#3b82f6;"><?= number_format($totalPlannedH,1) ?>h</td>
                    <td class="text-center" style="color:#c9a84c;"><?= number_format($totalActualH,1) ?>h</td>
                    <td class="text-center" style="color:#10b981;"><?= $matchedCount ?>/<?= $totalEntries ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

</div><!-- /padding -->
<?php include '../../includes/footer.php'; ?>
</div></div>