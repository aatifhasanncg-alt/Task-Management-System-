<?php
/**
 * consulting/admin/plan_approvals.php — Admin: Approve / Reject Plans
 * Layout: [Pending LEFT] [All Plans MIDDLE] [Recently Processed RIGHT]
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAdmin();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];


$deptId = (int) $user['department_id'];

$branchId = (int) $user['branch_id'];
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
// Scope: all staff + self in same dept+branch
$scopeStmt = $db->prepare("
    SELECT DISTINCT u.id
    FROM users u
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    WHERE u.is_active = 1
      AND u.branch_id = ?
      AND (
          u.id = ?
          OR u.department_id = ?
          OR uda.department_id = ?
      )
");
$scopeStmt->execute([$branchId, $uid, $deptId, $deptId]);
$scopeIds = array_unique(array_column($scopeStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
if (!in_array($uid, $scopeIds)) $scopeIds[] = $uid;
$inList = implode(',', array_map('intval', $scopeIds)) ?: (string)$uid;

// ── Month filter ───────────────────────────────────────────────
$now = new DateTime();
$month = $_GET['month'] ?? $now->format('Y-m');
$monthDate = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthLabel = $monthDate->format('F Y');

// ── Handle approve/reject POST ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $planId = (int) ($_POST['plan_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    $statusMap = [
        'approve' => 'approved',
        'reject' => 'rejected',
        'draft' => 'draft',
        'submitted' => 'submitted',
    ];

    if ($planId && isset($statusMap[$action])) {
        $newStatus = $statusMap[$action];

        // Verify plan belongs to this dept
        $chk = $db->prepare("SELECT id, user_id, week_number FROM work_plans WHERE id=? AND department_id=?");
        $chk->execute([$planId, $deptId]);
        $planRow = $chk->fetch();

        if ($planRow) {
            if (in_array($newStatus, ['approved', 'rejected'])) {
                $db->prepare("
                    UPDATE work_plans
                    SET status=?, approved_by=?, approved_at=NOW(), remarks=?
                    WHERE id=?
                ")->execute([$newStatus, $uid, $remarks ?: null, $planId]);
            } else {
                $db->prepare("
                    UPDATE work_plans SET status=?, remarks=? WHERE id=?
                ")->execute([$newStatus, $remarks ?: null, $planId]);
            }

            // Notify the staff member
            try {
                $staffUserId = (int) $planRow['user_id'];
                $wkNum = $planRow['week_number'];
                if ($staffUserId !== $uid) {
                    $msg = $newStatus === 'approved'
                        ? 'Your work plan (Week ' . $wkNum . ') has been approved ✅'
                        : 'Your work plan (Week ' . $wkNum . ') was ' . $newStatus . ($remarks ? '. Note: ' . $remarks : '');
                    notify(
                        $staffUserId,
                        'Work Plan ' . ucfirst($newStatus),
                        $msg,
                        'status',
                        APP_URL . '/consulting/staff/plan_view.php?id=' . $planId,
                        true,
                        []
                    );
                }
            } catch (Exception $ex) {
            }

            logActivity('Plan #' . $planId . ' ' . $newStatus, 'consulting');
            setFlash('success', 'Plan marked as ' . $newStatus . '.');
        } else {
            setFlash('error', 'Plan not found or access denied.');
        }
    }
    header('Location: plan_approvals.php?month=' . $month);
    exit;
}

// ── KPI summary ───────────────────────────────────────────────
$kpiRow = $db->query("
    SELECT
        COUNT(*)                                        AS total,
        SUM(status='draft')                             AS draft,
        SUM(status='submitted')                         AS submitted,
        SUM(status='approved')                          AS approved,
        SUM(status='rejected')                          AS rejected
    FROM work_plans
    WHERE department_id={$deptId} AND user_id IN ({$inList})
      AND plan_month='{$monthStart}'
")->fetch();

// ── Pending / submitted plans (left column) ───────────────────
$pending = $db->query("
    SELECT wp.*, u.full_name AS planner_name, u.employee_id,
           COUNT(wpe.id) AS entry_count,
           COALESCE(SUM(wpe.planned_hours),0) AS total_hours
    FROM work_plans wp
    LEFT JOIN users u              ON u.id  = wp.user_id
    LEFT JOIN work_plan_entries wpe ON wpe.plan_id = wp.id
    WHERE wp.department_id = {$deptId}
      AND wp.user_id IN ({$inList})
      AND wp.status IN ('draft','submitted')
      AND wp.plan_month = '{$monthStart}'
    GROUP BY wp.id
    ORDER BY FIELD(wp.status,'submitted','draft'), wp.created_at DESC
")->fetchAll();

// ── All plans this month (middle column) ──────────────────────
$allPlans = $db->query("
    SELECT wp.*, u.full_name AS planner_name,
           COUNT(wpe.id) AS entry_count,
           COALESCE(SUM(wpe.planned_hours),0) AS total_hours
    FROM work_plans wp
    LEFT JOIN users u               ON u.id  = wp.user_id
    LEFT JOIN work_plan_entries wpe ON wpe.plan_id = wp.id
    WHERE wp.department_id = {$deptId}
      AND wp.user_id IN ({$inList})
      AND wp.plan_month = '{$monthStart}'
    GROUP BY wp.id
    ORDER BY wp.week_number ASC, wp.created_at DESC
")->fetchAll();

// ── Recently processed (right column) ────────────────────────
$recent = $db->query("
    SELECT wp.*, u.full_name AS planner_name,
           ab.full_name AS approver_name
    FROM work_plans wp
    LEFT JOIN users u  ON u.id  = wp.user_id
    LEFT JOIN users ab ON ab.id = wp.approved_by
    WHERE wp.status IN ('approved','rejected')
      AND wp.department_id = {$deptId}
      AND wp.user_id IN ({$inList})
    ORDER BY wp.approved_at DESC
    LIMIT 15
")->fetchAll();

$pageTitle = 'Plan Approvals';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<style>
    /* Three-column approval layout */
    .ap-layout {
        display: grid;
        grid-template-columns: 320px 1fr 280px;
        gap: 16px;
        align-items: start;
    }

    @media(max-width:1100px) {
        .ap-layout {
            grid-template-columns: 1fr 1fr;
        }
    }

    @media(max-width:760px) {
        .ap-layout {
            grid-template-columns: 1fr;
        }
    }

    .ap-pending-card {
        background: var(--card);
        border: 1px solid var(--cn4);
        border-left: 4px solid var(--amber);
        border-radius: var(--rad);
        padding: 14px 16px;
        margin-bottom: 12px;
        transition: box-shadow .15s;
    }

    .ap-pending-card:hover {
        box-shadow: 0 4px 16px rgba(0, 0, 0, .18);
    }

    .ap-pending-card.submitted {
        border-left-color: var(--blue);
    }

    .ap-action-row {
        display: flex;
        gap: 7px;
        align-items: flex-end;
        flex-wrap: wrap;
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px solid var(--cn4);
    }
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="container-fluid py-3">

            <?= flashHtml() ?>

            <!-- Top bar -->
            <div class="page-hero mb-4">
                <div>
                    <div class="page-hero-badge">
                        <i class="fas fa-check-circle"></i> Consulting
                    </div>
                    <h4>Plan Approvals</h4>
                    <p><?= $monthLabel ?> · Review and approve work plans</p>
                </div>

                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <input type="month" class="form-control form-control-sm" style="width:160px;"
                        value="<?= htmlspecialchars($month) ?>" onchange="location='?month='+this.value">

                    <a href="plan_list.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-list me-1"></i>All Plans
                    </a>
                </div>
            </div>

            <!-- KPI row -->
            <div class="row g-3 mb-4">
                <?php
                $kpis = [
                    ['Pending', count($pending), 'warning', 'fa-clock'],
                    ['All Plans', count($allPlans), 'primary', 'fa-list'],
                    ['Approved', 0, 'success', 'fa-check'],
                    ['Rejected', 0, 'danger', 'fa-times'],
                ];
                foreach ($kpis as [$label, $val, $color, $icon]): ?>
                    <div class="col-6 col-md-3">
                        <div class="card-mis text-center p-3">
                            <div style="font-size:1.4rem;font-weight:700;">
                                <?= $val ?>
                            </div>
                            <div style="color:var(--muted);font-size:.85rem;">
                                <i class="fas <?= $icon ?> me-1 text-<?= $color ?>"></i>
                                <?= $label ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Three-column layout -->
           <div class="row g-4">


                <!-- ── LEFT: Pending approval ── -->
                <div class="card-mis mb-0">
                    <div class="card-mis-header d-flex justify-content-between align-items-center">
                        <h5>
                            <i class="fas fa-clock text-warning me-2"></i>
                            Pending Review
                        </h5>
                        <span class="badge bg-warning text-dark">
                            <?= count($pending) ?>
                        </span>
                    </div>

                    <?php if (empty($pending)): ?>
                        <div class="cn-panel" style="border-radius:0 0 var(--rad) var(--rad);">
                            <div class="cn-empty"><i class="fas fa-check-double"></i>All caught up!</div>
                        </div>
                    <?php else: ?>
                        <div style="background:var(--card);border:1px solid var(--cn4);border-top:none;
                                border-radius:0 0 var(--rad) var(--rad);padding:10px;">
                            <?php foreach ($pending as $p):
                                $isSubmitted = $p['status'] === 'submitted';
                                ?>
                                <div class="card-mis mb-3 p-3 <?= $isSubmitted ? 'submitted' : '' ?>">
                                    <!-- Plan info -->
                                    <div
                                        style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:6px;">
                                        <div>
                                            <div style="font-weight:700;font-size:.88rem;color:var(--txt);">
                                                <?= htmlspecialchars($p['planner_name'] ?? '—') ?>
                                            </div>
                                            <div style="font-size:.72rem;color:var(--muted);margin-top:1px;">
                                                <?= htmlspecialchars($p['employee_id'] ?? '') ?>
                                            </div>
                                        </div>
                                        <?= planBadge($p['status']) ?>
                                    </div>

                                    <div style="font-size:.78rem;color:var(--gold);font-weight:600;margin-bottom:3px;">
                                        Week <?= $p['week_number'] ?>
                                        <span style="color:var(--muted);font-weight:400;">
                                            · <?= date('d M', strtotime($p['week_start_date'])) ?>
                                            – <?= date('d M', strtotime($p['week_end_date'])) ?>
                                        </span>
                                    </div>
                                    <div style="font-size:.74rem;color:var(--muted);margin-bottom:4px;">
                                        <?= $p['entry_count'] ?> entries ·
                                        <?= number_format((float) $p['total_hours'], 1) ?>h planned
                                    </div>

                                    <?php if ($p['remarks']): ?>
                                        <div style="font-size:.74rem;color:var(--muted);background:var(--cn3);
                                        border-radius:5px;padding:5px 8px;margin-bottom:6px;">
                                            <i class="fas fa-comment me-1"></i><?= htmlspecialchars($p['remarks']) ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- View link -->
                                    <a href="plan_view.php?id=<?= $p['id'] ?>" class="cn-btn cn-btn-blue cn-btn-sm"
                                        style="width:100%;justify-content:center;margin-bottom:8px;">
                                        <i class="fas fa-eye"></i> View Full Plan
                                    </a>

                                    <!-- Quick approve -->
                                    <form method="POST" onsubmit="return confirm('Approve this plan?')">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <div class="ap-action-row">
                                            <input type="text" name="remarks" class="cn-input"
                                                style="flex:1;min-width:0;font-size:.78rem;padding:5px 9px;"
                                                placeholder="Approval note (optional)">
                                            <button type="submit" class="btn btn-gold btn-sm">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </div>
                                    </form>

                                    <!-- Quick reject -->
                                    <form method="POST" onsubmit="return confirm('Reject this plan?')" style="margin-top:6px;">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <div class="ap-action-row" style="border-top:none;padding-top:0;">
                                            <input type="text" name="remarks" class="cn-input" required
                                                style="flex:1;min-width:0;font-size:.78rem;padding:5px 9px;"
                                                placeholder="Rejection reason (required)">
                                            <button type="submit" class="cn-btn cn-btn-danger cn-btn-sm">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </div>
                                    </form>

                                    <!-- Change status dropdown -->
                                    <form method="POST" style="margin-top:6px;">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                        <div style="display:flex;gap:6px;align-items:center;">
                                            <select name="action" class="cn-input"
                                                style="flex:1;font-size:.76rem;padding:5px 8px;">
                                                <option value="submitted">Mark Submitted</option>
                                                <option value="draft">Mark Draft</option>
                                            </select>
                                            <button type="submit" class="cn-btn cn-btn-out cn-btn-sm">Set</button>
                                        </div>
                                    </form>

                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ── MIDDLE: All plans list ── -->
                <div class="cn-panel">
                    <div class="cn-panel-hd">
                        <span class="cn-panel-title">
                            <i class="fas fa-list me-2" style="color:var(--gold)"></i>
                            All Plans — <?= $monthLabel ?>
                        </span>
                        <a href="plan_list.php?month=<?= $month ?>" class="cn-btn cn-btn-out cn-btn-sm">
                            Full List
                        </a>
                    </div>
                    <?php if (empty($allPlans)): ?>
                        <div class="cn-empty"><i class="fas fa-calendar-times"></i>No plans this month</div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="cn-table">
                                <thead>
                                    <tr>
                                        <th>Staff</th>
                                        <th class="text-center">Wk</th>
                                        <th>Dates</th>
                                        <th class="text-center">Entries</th>
                                        <th class="text-center">Hours</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allPlans as $idx => $p):
                                        $odd = $idx % 2 === 0;
                                        ?>
                                        <tr style="<?= $odd ? '' : 'background:rgba(30,42,64,.2)' ?>">
                                            <td>
                                                <div style="font-weight:600;font-size:.83rem;">
                                                    <?= htmlspecialchars(mb_strimwidth($p['planner_name'] ?? '—', 0, 18, '…')) ?>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <strong style="color:var(--gold);">Wk <?= $p['week_number'] ?></strong>
                                            </td>
                                            <td style="font-size:.74rem;color:var(--muted);">
                                                <?= date('d M', strtotime($p['week_start_date'])) ?>
                                                – <?= date('d M', strtotime($p['week_end_date'])) ?>
                                            </td>
                                            <td class="text-center"><?= $p['entry_count'] ?></td>
                                            <td class="text-center">
                                                <span style="color:var(--gold);font-weight:600;">
                                                    <?= number_format((float) $p['total_hours'], 1) ?>h
                                                </span>
                                            </td>
                                            <td><?= planBadge($p['status']) ?></td>
                                            <td>
                                                <a href="plan_view.php?id=<?= $p['id'] ?>" class="cn-btn cn-btn-out cn-btn-sm">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ── RIGHT: Recently processed ── -->
                <div class="card-mis h-100">
                    <div class="card-mis-header">
                        <h5>
                            <i class="fas fa-history text-warning me-2"></i>
                            Processed
                        </h5>
                    </div>
                    <?php if (empty($recent)): ?>
                        <div class="cn-empty"><i class="fas fa-history"></i>No processed plans yet</div>
                    <?php else: ?>
                        <div style="padding:0 4px;">
                            <?php foreach ($recent as $r): ?>
                                <div class="border-bottom p-2">
                                    <div
                                        style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;">
                                        <span style="font-size:.8rem;font-weight:600;color:var(--txt);">
                                            <?= htmlspecialchars(explode(' ', $r['planner_name'])[0] ?? '—') ?>
                                        </span>
                                        <?= planBadge($r['status']) ?>
                                    </div>
                                    <div style="font-size:.73rem;color:var(--gold);">Week <?= $r['week_number'] ?></div>
                                    <div style="font-size:.7rem;color:var(--muted);margin-top:2px;">
                                        <?= $r['approved_at'] ? date('d M Y', strtotime($r['approved_at'])) : '—' ?>
                                        <?php if ($r['approver_name']): ?>
                                            · by <?= htmlspecialchars(explode(' ', $r['approver_name'])[0]) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($r['remarks']): ?>
                                        <div style="font-size:.7rem;color:var(--muted);margin-top:3px;font-style:italic;">
                                            "<?= htmlspecialchars(mb_strimwidth($r['remarks'], 0, 55, '…')) ?>"
                                        </div>
                                    <?php endif; ?>
                                    <a href="plan_view.php?id=<?= $r['id'] ?>"
                                        style="font-size:.71rem;color:var(--blue);text-decoration:none;">
                                        <i class="fas fa-eye me-1"></i>View plan
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div><!-- /ap-layout -->

        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>