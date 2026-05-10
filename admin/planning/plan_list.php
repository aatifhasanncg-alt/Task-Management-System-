<?php
/**
 * consulting/plan_list.php — Admin: All Work Plans
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
$now = new DateTime();
$month = $_GET['month'] ?? $now->format('Y-m');
$monthDate = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthLabel = $monthDate->format('F Y');

$currentRole = $_SESSION['role'] ?? ($user['role'] ?? '');
$isAdmin = in_array($currentRole, ['admin', 'executive', 'superadmin']);

// ── Scope: self + managed staff + other CON dept users ────────
$myStaffIds = [];
if ($isAdmin) {
    $mStmt = $db->prepare("
        SELECT DISTINCT u.id
        FROM users u
        LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
        WHERE u.is_active = 1
          AND (u.managed_by = ? OR uda.managed_by = ?)
    ");
    $mStmt->execute([$uid, $uid]);
    $myStaffIds = array_map('intval', array_column($mStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
}

if ($isAdmin) {
    $scopeRows = $db->query("
        SELECT DISTINCT u.id
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
    ")->fetchAll(PDO::FETCH_COLUMN);
    $scopeIds = array_unique(array_merge([$uid], $scopeRows));
} else {
    $scopeIds = [$uid];
}
$inList = implode(',', array_map('intval', $scopeIds)) ?: '0';
// ── STEP 1: fetch plans FIRST ─────────────────────────────────
$plans = $db->query("
    SELECT wp.*,
           u.full_name AS planner_name,
           u.employee_id,
           COUNT(DISTINCT wpe.id)             AS entry_count,
           COALESCE(SUM(wpe.planned_hours),0) AS total_planned_hours
    FROM work_plans wp
    LEFT JOIN users u ON u.id = wp.user_id
    LEFT JOIN work_plan_entries wpe ON wpe.plan_id = wp.id
    WHERE wp.plan_month = '{$monthStart}'
      AND wp.user_id IN ({$inList})
    GROUP BY wp.id
    ORDER BY wp.week_number ASC, u.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── STEP 2: status counts & KPIs (need $plans) ───────────────
// ── STEP 2: categorised KPIs ──────────────────────────────────
$statusCounts = [];
$myOwnPlans = [];
$myStaffPlans = [];
$otherPlans = [];

foreach ($plans as $p) {
    $statusCounts[$p['status']] = ($statusCounts[$p['status']] ?? 0) + 1;

    $pid = (int) $p['user_id'];
    if ($pid === $uid) {
        $myOwnPlans[] = $p;
    } elseif (in_array($pid, $myStaffIds)) {
        $myStaffPlans[] = $p;
    } else {
        $otherPlans[] = $p;
    }
}

$totalPlans = count($plans);
$totalEntries = array_sum(array_column($plans, 'entry_count'));
$totalPlannedHours = (float) array_sum(array_column($plans, 'total_planned_hours'));

// ── STEP 3: managedIds reuse myStaffIds + approvable count ────
$managedIds = $myStaffIds; // already fetched above

$approvableCount = 0;
foreach ($plans as $p) {
    if ($p['status'] === 'submitted' && in_array((int) $p['user_id'], $managedIds)) {
        $approvableCount++;
    }
}

// ── Per-category KPI helpers ───────────────────────────────────
$kpiOwn = [
    'plans' => count($myOwnPlans),
    'entries' => array_sum(array_column($myOwnPlans, 'entry_count')),
    'hours' => (float) array_sum(array_column($myOwnPlans, 'total_planned_hours')),
];
$kpiStaff = [
    'plans' => count($myStaffPlans),
    'entries' => array_sum(array_column($myStaffPlans, 'entry_count')),
    'hours' => (float) array_sum(array_column($myStaffPlans, 'total_planned_hours')),
];
$kpiOther = [
    'plans' => count($otherPlans),
    'entries' => array_sum(array_column($otherPlans, 'entry_count')),
    'hours' => (float) array_sum(array_column($otherPlans, 'total_planned_hours')),
];

$deptStmt = $db->prepare("SELECT dept_name FROM departments WHERE id = ?");
$deptStmt->execute([$deptId]);
$deptName = $deptStmt->fetchColumn() ?: 'Consulting';

$pageTitle = 'All Work Plans';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">


<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-list"></i> Consulting</div>
                        <h4>All Work Plans</h4>
                        <p>
                            Department view · <?= htmlspecialchars($deptName) ?> · <?= $monthLabel ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="month" class="form-control form-control-sm" style="width:150px;"
                            value="<?= $month ?>" onchange="location='?month='+this.value">
                        <a href="plan_approvals.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-check-circle me-1"></i> Approvals
                            <?php if ($approvableCount > 0): ?>
                                <span class="badge bg-danger ms-1"><?= $approvableCount ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="index.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=consulting_performance&view=monthly&month=<?= urlencode($month) ?>"
                            class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-file-pdf me-1" style="color:#ef4444;"></i>PDF
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=consulting_performance&view=monthly&month=<?= urlencode($month) ?>"
                            class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-file-excel me-1" style="color:#10b981;"></i>Excel
                        </a>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">

                <!-- ── Row 1: Overall totals + status ── -->
                <?php
                $planStatCards = [
                    ['fa-calendar-alt', '#3b82f6', '#eff6ff', 'Total Plans', $totalPlans],
                    ['fa-list', '#8b5cf6', '#eef2ff', 'Total Entries', $totalEntries],
                    ['fa-clock', '#c9a84c', '#fefce8', 'Planned Hours', number_format($totalPlannedHours, 1) . 'h'],
                    ['fa-file-alt', '#9ca3af', '#f3f4f6', 'Draft', $statusCounts['draft'] ?? 0],
                    ['fa-paper-plane', '#3b82f6', '#eff6ff', 'Submitted', $statusCounts['submitted'] ?? 0],
                    ['fa-check-circle', '#10b981', '#ecfdf5', 'Approved', $statusCounts['approved'] ?? 0],
                    ['fa-times-circle', '#ef4444', '#fef2f2', 'Rejected', $statusCounts['rejected'] ?? 0],
                    ['fa-bell', '#f59e0b', '#fffbeb', 'Awaiting Review', $approvableCount],
                ];
                foreach ($planStatCards as [$icon, $col, $bg, $label, $value]): ?>
                    <div class="col-6 col-md-3 col-xl-auto">
                        <div style="background:<?= $bg ?>;border-radius:12px;border:1px solid <?= $col ?>22;padding:1rem;">
                            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.65rem;">
                                <div style="width:36px;height:36px;border-radius:12px;background:<?= $col ?>22;
                                color:<?= $col ?>;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas <?= $icon ?>"></i>
                                </div>
                                <div style="font-size:.78rem;font-weight:600;color:#6b7280;"><?= $label ?></div>
                            </div>
                            <div style="font-size:1.35rem;font-weight:800;color:#1f2937;">
                                <?= htmlspecialchars((string) $value) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

            </div>

            <!-- ── Row 2: My Own / My Staff / Other breakdown ── -->
            <?php if ($isAdmin): ?>
                <div class="row g-3 mb-4">

                    <!-- My Own Plans -->
                    <div class="col-12 col-md-4">
                        <div style="background:#f0fdf4;border-radius:12px;border:1px solid #10b98122;padding:1rem;">
                            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem;">
                                <div style="width:34px;height:34px;border-radius:10px;background:#10b98122;
                            color:#10b981;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div style="font-size:.82rem;font-weight:700;color:#065f46;">My Own Plans</div>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center;">
                                <div>
                                    <div style="font-size:1.2rem;font-weight:800;color:#10b981;"><?= $kpiOwn['plans'] ?>
                                    </div>
                                    <div style="font-size:.68rem;color:#6b7280;">Plans</div>
                                </div>
                                <div>
                                    <div style="font-size:1.2rem;font-weight:800;color:#10b981;"><?= $kpiOwn['entries'] ?>
                                    </div>
                                    <div style="font-size:.68rem;color:#6b7280;">Entries</div>
                                </div>
                                <div>
                                    <div style="font-size:1.2rem;font-weight:800;color:#10b981;">
                                        <?= number_format($kpiOwn['hours'], 1) ?>h
                                    </div>
                                    <div style="font-size:.68rem;color:#6b7280;">Hours</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- My Staff Plans -->
                    <div class="col-12 col-md-4">
                        <div style="background:#eff6ff;border-radius:12px;border:1px solid #3b82f622;padding:1rem;">
                            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem;">
                                <div style="width:34px;height:34px;border-radius:10px;background:#3b82f622;
                            color:#3b82f6;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div style="font-size:.82rem;font-weight:700;color:#1e40af;">My Staff Plans</div>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center;">
                                <div>
                                    <div style="font-size:1.2rem;font-weight:800;color:#3b82f6;"><?= $kpiStaff['plans'] ?>
                                    </div>
                                    <div style="font-size:.68rem;color:#6b7280;">Plans</div>
                                </div>
                                <div>
                                    <div style="font-size:1.2rem;font-weight:800;color:#3b82f6;"><?= $kpiStaff['entries'] ?>
                                    </div>
                                    <div style="font-size:.68rem;color:#6b7280;">Entries</div>
                                </div>
                                <div>
                                    <div style="font-size:1.2rem;font-weight:800;color:#3b82f6;">
                                        <?= number_format($kpiStaff['hours'], 1) ?>h
                                    </div>
                                    <div style="font-size:.68rem;color:#6b7280;">Hours</div>
                                </div>
                            </div>
                            <?php if ($approvableCount > 0): ?>
                                <div style="margin-top:8px;padding:4px 8px;background:#fef3c7;border-radius:6px;
                        font-size:.72rem;color:#92400e;font-weight:600;">
                                    <i class="fas fa-bell me-1"></i><?= $approvableCount ?> awaiting your review
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Other Plans -->
                    <div class="col-12 col-md-4">
                        <div style="background:#faf5ff;border-radius:12px;border:1px solid #8b5cf622;padding:1rem;">
                            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem;">
                                <div style="width:34px;height:34px;border-radius:10px;background:#8b5cf622;
                            color:#8b5cf6;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-building"></i>
                                </div>
                                <div style="font-size:.82rem;font-weight:700;color:#5b21b6;">Consulting Dept Plans</div>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center;">
                                <div>
                                    <div style="font-size:1.2rem;font-weight:800;color:#8b5cf6;"><?= $kpiOther['plans'] ?>
                                    </div>
                                    <div style="font-size:.68rem;color:#6b7280;">Plans</div>
                                </div>
                                <div>
                                    <div style="font-size:1.2rem;font-weight:800;color:#8b5cf6;"><?= $kpiOther['entries'] ?>
                                    </div>
                                    <div style="font-size:.68rem;color:#6b7280;">Entries</div>
                                </div>
                                <div>
                                    <div style="font-size:1.2rem;font-weight:800;color:#8b5cf6;">
                                        <?= number_format($kpiOther['hours'], 1) ?>h
                                    </div>
                                    <div style="font-size:.68rem;color:#6b7280;">Hours</div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            <?php endif; ?>
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-table me-2 text-warning"></i>Plans — <?= $monthLabel ?></h5>
                    <span style="font-size:.78rem;color:#9ca3af;">
                        <?= count($plans) ?> plans found
                    </span>
                </div>

                <div class="card-mis-body p-0">
                    <?php if (empty($plans)): ?>
                        <div class="empty-state p-4">
                            <i class="fas fa-calendar-times"></i>
                            <h6>No plans for <?= $monthLabel ?></h6>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive cn-table-wrap">
                            <table class="cn-table" id="plansTable" style="width:100%;">
                                <thead>
                                    <tr>
                                        <th>Staff</th>
                                        <th>Week</th>
                                        <th>Period</th>
                                        <th class="text-center">Entries</th>
                                        <th class="text-center">Planned Hrs</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($plans as $p): ?>
                                        <tr>
                                            <td>
                                                <div class="text-truncate" style="max-width:160px;font-weight:600;">
                                                    <?= htmlspecialchars($p['planner_name'] ?? '—') ?>
                                                </div>
                                                <div style="font-size:.69rem;color:#9ca3af;">
                                                    <?= htmlspecialchars($p['employee_id'] ?? '') ?>
                                                </div>
                                            </td>
                                            <td><strong style="color:#c9a84c;">Week <?= $p['week_number'] ?></strong></td>
                                            <td class="text-nowrap" style="font-size:.77rem;color:#6b7280;">
                                                <?= date('d M', strtotime($p['week_start_date'])) ?> –
                                                <?= date('d M', strtotime($p['week_end_date'])) ?>
                                            </td>
                                            <td class="text-center"><?= $p['entry_count'] ?></td>
                                            <td class="text-center">
                                                <strong
                                                    style="color:#c9a84c;"><?= number_format((float) $p['total_planned_hours'], 1) ?>h</strong>
                                            </td>
                                            <td><?= planBadge($p['status']) ?></td>
                                            <td style="font-size:.75rem;color:#9ca3af;">
                                                <?= date('d M Y', strtotime($p['created_at'])) ?>
                                            </td>
                                            <!-- REPLACE the existing actions <td> block with this: -->
                                            <td>
                                                <div class="d-flex align-items-center gap-1 flex-wrap">

                                                    <!-- View: always show for own plans OR managed staff -->
                                                    <?php if ((int) $p['user_id'] === $uid || in_array((int) $p['user_id'], $managedIds)): ?>
                                                        <a href="plan_view.php?id=<?= $p['id'] ?>"
                                                            class="cn-btn cn-btn-out cn-btn-sm">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    <?php endif; ?>

                                                    <!-- Edit: always show for own plans OR managed staff -->
                                                    <?php if ((int) $p['user_id'] === $uid || in_array((int) $p['user_id'], $managedIds)): ?>
                                                        <a href="plan_edit.php?id=<?= $p['id'] ?>" class="cn-btn cn-btn-sm"
                                                            style="background:#fefce8;border:1px solid #fde68a;color:#92400e;">
                                                            <i class="fas fa-pencil-alt"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <!-- Other dept plans: view only, no edit -->
                                                        <a href="plan_edit.php?id=<?= $p['id'] ?>" class="cn-btn cn-btn-sm"
                                                            style="background:#fefce8;border:1px solid #fde68a;color:#92400e;">
                                                            <i class="fas fa-pencil-alt"></i>
                                                        </a>
                                                    <?php endif; ?>

                                                    <!-- Review: only for submitted plans of managed staff (not self) -->
                                                    <?php if ($p['status'] === 'submitted' && in_array((int) $p['user_id'], $managedIds)): ?>
                                                        <a href="plan_approvals.php?id=<?= $p['id'] ?>"
                                                            class="cn-btn cn-btn-gold cn-btn-sm">
                                                            <i class="fas fa-check"></i> Review
                                                        </a>
                                                    <?php elseif ($p['status'] === 'submitted' && (int) $p['user_id'] !== $uid): ?>
                                                        <span style="font-size:.7rem;color:#9ca3af;padding:3px 6px;background:#f3f4f6;
                                                            border-radius:6px;white-space:nowrap;">
                                                            <i class="fas fa-hourglass-half me-1"></i>Pending
                                                        </span>
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
    $(document).ready(function () {
        if ($('#plansTable tbody tr').length > 0)
            $('#plansTable').DataTable({ order: [[0, 'asc'], [1, 'asc']], pageLength: 25 });
    });
</script>
<?php include '../../includes/footer.php'; ?>