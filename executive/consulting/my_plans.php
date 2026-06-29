<?php
/**
 * consulting/my_plans/plan_list.php — Manager: My Work Plans
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireExecutive();

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
$monthDate = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthLabel = $monthDate->format('F Y');

$plans = $db->prepare("
    SELECT wp.*, COUNT(wpe.id) AS entry_count,
           COALESCE(SUM(wpe.planned_hours),0) AS total_planned_hours
    FROM work_plans wp
    LEFT JOIN work_plan_entries wpe ON wpe.plan_id=wp.id
    WHERE wp.user_id=? AND wp.plan_month=?
    GROUP BY wp.id ORDER BY wp.week_number ASC
");
$plans->execute([$uid, $monthStart]);
$plans = $plans->fetchAll();

$pageTitle = 'My Work Plans';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="<?= APP_URL ?>/staff/planning/consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
<style>
    /* KPI responsive */
    .kpi-row {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 12px;
    }

    @media (max-width: 1024px) {
        .kpi-row {
            grid-template-columns: repeat(3, 1fr) !important;
        }
    }

    @media (max-width: 768px) {
        .kpi-row {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 10px !important;
        }

        .page-hero h4 {
            font-size: 1.05rem;
        }

        .page-hero p {
            font-size: .75rem;
        }

        .cn-wrap {
            padding: 0 10px !important;
        }

        .plan-hero-actions {
            flex-direction: column !important;
            align-items: stretch !important;
        }

        .plan-hero-actions .form-control,
        .plan-hero-actions .btn {
            width: 100% !important;
        }
    }

    @media (max-width: 480px) {
        .kpi-row {
            grid-template-columns: repeat(2, 1fr) !important;
        }

        .kpi-val {
            font-size: 1.2rem !important;
        }

        .kpi-tile {
            padding: 10px 8px !important;
        }

        .kpi-label {
            font-size: .65rem !important;
        }

        .kpi-icon {
            font-size: .9rem !important;
        }
    }

    /* Plan cards grid */
    .plans-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 16px;
    }

    @media (max-width: 480px) {
        .plans-grid {
            grid-template-columns: 1fr !important;
        }
    }

    /* Card footer buttons responsive */
    .plan-card-footer {
        padding: 10px 16px 14px;
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        border-top: 1px solid #f1f5f9;
    }

    .plan-card-footer .cn-btn,
    .plan-card-footer form {
        flex: 1;
        min-width: 70px;
    }

    @media (max-width: 360px) {
        .plan-card-footer {
            flex-direction: column;
        }

        .plan-card-footer .cn-btn,
        .plan-card-footer form {
            flex: unset;
            width: 100%;
        }
    }

    /* Status badge */
    .plan-status-badge {
        padding: 3px 10px;
        border-radius: 20px;
        font-size: .72rem;
        font-weight: 600;
        text-transform: capitalize;
        white-space: nowrap;
    }

    /* Stats mini grid inside card */
    .plan-stats-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-bottom: 12px;
    }

    /* Scrollable main content */
    .main-content {
        overflow-y: auto;
        height: 100vh;
    }

    /* Smooth scroll */
    .main-content {
        scroll-behavior: smooth;
    }

    /* Scrollbar styling */
    .main-content::-webkit-scrollbar {
        width: 5px;
    }

    .main-content::-webkit-scrollbar-track {
        background: #f1f5f9;
    }

    .main-content::-webkit-scrollbar-thumb {
        background: #c9a84c;
        border-radius: 99px;
    }

    .main-content::-webkit-scrollbar-thumb:hover {
        background: #a8883a;
    }
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <!-- PAGE HERO -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-briefcase"></i> Consulting</div>
                        <h4>My Work Plans</h4>
                        <p><?= htmlspecialchars($user['full_name']) ?> · <?= $monthLabel ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center plan-hero-actions">
                        <input type="month" class="form-control form-control-sm" style="width:140px;min-width:120px;"
                            value="<?= $month ?>" onchange="location='?month='+this.value">
                        <a href="plan_create.php?month=<?= $month ?>" class="btn btn-sm btn-gold">
                            <i class="fas fa-plus me-1"></i> New Plan
                        </a>
                        <a href="index.php?month=<?= $month ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <?= flashHtml() ?>

            <!-- KPI ROW -->
            <?php if (!empty($plans)):
                $totalEntries = array_sum(array_column($plans, 'entry_count'));
                $totalHrs = array_sum(array_column($plans, 'total_planned_hours'));
                $approvedCnt = count(array_filter($plans, fn($p) => $p['status'] === 'approved'));
                $draftCnt = count(array_filter($plans, fn($p) => $p['status'] === 'draft'));
                ?>
                <div class="kpi-row mb-4">
                    <div class="kpi-tile" style="--kpi-color:#3b82f6;">
                        <div class="kpi-icon"><i class="fas fa-calendar-week" style="color:#3b82f6;"></i></div>
                        <div class="kpi-val"><?= count($plans) ?></div>
                        <div class="kpi-label">Plans This Month</div>
                    </div>
                    <div class="kpi-tile" style="--kpi-color:#8b5cf6;">
                        <div class="kpi-icon"><i class="fas fa-list" style="color:#8b5cf6;"></i></div>
                        <div class="kpi-val"><?= $totalEntries ?></div>
                        <div class="kpi-label">Total Entries</div>
                    </div>
                    <div class="kpi-tile" style="--kpi-color:#c9a84c;">
                        <div class="kpi-icon"><i class="fas fa-clock" style="color:#c9a84c;"></i></div>
                        <div class="kpi-val"><?= number_format((float) $totalHrs, 1) ?>h</div>
                        <div class="kpi-label">Planned Hours</div>
                    </div>
                    <div class="kpi-tile" style="--kpi-color:#10b981;">
                        <div class="kpi-icon"><i class="fas fa-check-circle" style="color:#10b981;"></i></div>
                        <div class="kpi-val"><?= $approvedCnt ?></div>
                        <div class="kpi-label">Approved</div>
                    </div>
                    <div class="kpi-tile" style="--kpi-color:#f59e0b;">
                        <div class="kpi-icon"><i class="fas fa-file" style="color:#f59e0b;"></i></div>
                        <div class="kpi-val"><?= $draftCnt ?></div>
                        <div class="kpi-label">Drafts</div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- EMPTY STATE -->
            <?php if (empty($plans)): ?>
                <div class="cn-panel">
                    <div style="padding:40px 20px;text-align:center;color:#9ca3af;">
                        <i class="fas fa-calendar-times" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
                        <div style="font-size:.85rem;font-weight:600;margin-bottom:4px;">
                            No plans for <?= $monthLabel ?>
                        </div>
                        <div style="font-size:.78rem;margin-bottom:14px;">
                            Create your first work plan for this month.
                        </div>
                        <a href="plan_create.php?month=<?= $month ?>" class="cn-btn cn-btn-gold"
                            style="display:inline-flex;">
                            <i class="fas fa-plus me-2"></i>Create Plan
                        </a>
                    </div>
                </div>

                <!-- PLANS GRID -->
            <?php else: ?>
                <div class="plans-grid">
                    <?php foreach ($plans as $p):
                        $sc = ['draft' => '#9ca3af', 'submitted' => '#3b82f6', 'approved' => '#10b981', 'rejected' => '#ef4444'];
                        $sc2 = ['draft' => '#f3f4f6', 'submitted' => '#eff6ff', 'approved' => '#f0fdf4', 'rejected' => '#fef2f2'];
                        $st = $p['status'] ?? 'draft';
                        ?>
                        <div style="background:#fff;border-radius:12px;border:1.5px solid #f1f5f9;
                            border-top:3px solid #c9a84c;display:flex;flex-direction:column;
                            box-shadow:0 1px 4px rgba(0,0,0,.06);">

                            <!-- Card Header -->
                            <div style="padding:14px 16px 10px;display:flex;justify-content:space-between;
                                align-items:flex-start;border-bottom:1px solid #f1f5f9;">
                                <div>
                                    <div style="font-weight:700;color:#c9a84c;font-size:.95rem;">
                                        Week <?= $p['week_number'] ?>
                                    </div>
                                    <div style="font-size:.72rem;color:#9ca3af;margin-top:2px;">
                                        <?= date('d M', strtotime($p['week_start_date'])) ?> –
                                        <?= date('d M', strtotime($p['week_end_date'])) ?>
                                    </div>
                                </div>
                                <span class="plan-status-badge" style="background:<?= $sc2[$st] ?>;color:<?= $sc[$st] ?>;">
                                    <?= $st ?>
                                </span>
                            </div>

                            <!-- Card Body -->
                            <div style="padding:14px 16px;flex:1;">
                                <div class="plan-stats-grid">
                                    <div style="background:#f9fafb;border-radius:8px;padding:10px;text-align:center;">
                                        <div style="font-size:1.4rem;font-weight:800;color:#0a0f1e;line-height:1;">
                                            <?= $p['entry_count'] ?>
                                        </div>
                                        <div style="font-size:.68rem;color:#9ca3af;margin-top:3px;">Entries</div>
                                    </div>
                                    <div style="background:#f9fafb;border-radius:8px;padding:10px;text-align:center;">
                                        <div style="font-size:1.4rem;font-weight:800;color:#c9a84c;line-height:1;">
                                            <?= number_format((float) $p['total_planned_hours'], 1) ?>h
                                        </div>
                                        <div style="font-size:.68rem;color:#9ca3af;margin-top:3px;">Planned Hrs</div>
                                    </div>
                                </div>

                                <?php if ($p['remarks']): ?>
                                    <div style="font-size:.77rem;color:#6b7280;background:#f9fafb;border-radius:6px;
                                    padding:7px 10px;margin-bottom:10px;border-left:3px solid #c9a84c;">
                                        <?= htmlspecialchars(mb_strimwidth($p['remarks'], 0, 80, '…')) ?>
                                    </div>
                                <?php endif; ?>

                                <div style="font-size:.7rem;color:#9ca3af;">
                                    Created <?= date('d M Y', strtotime($p['created_at'])) ?>
                                </div>
                            </div>

                            <!-- Card Footer -->
                            <div class="plan-card-footer">
                                <a href="myplan_view.php?id=<?= $p['id'] ?>" class="cn-btn cn-btn-gold cn-btn-sm"
                                    style="justify-content:center;">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="myplan_edit.php?id=<?= $p['id'] ?>" class="cn-btn cn-btn-out cn-btn-sm"
                                    style="justify-content:center;">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <?php if ($p['status'] === 'draft'): ?>
                                    <form method="POST" action="plan_submit.php" class="planSubmitForm"
                                        onsubmit="return handlePlanSubmit(this)">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="cn-btn cn-btn-blue cn-btn-sm w-100"
                                            style="justify-content:center;">
                                            <span class="btnIcon"><i class="fas fa-paper-plane"></i> Submit</span>
                                            <span class="btnLoading"
                                                style="display:none;align-items:center;justify-content:center;gap:.4rem;">
                                                <span class="spinner-border spinner-border-sm"
                                                    style="width:.8rem;height:.8rem;"></span> Saving...
                                            </span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>
<script>
function handlePlanSubmit(form) {
    if (!confirm('Submit this plan for approval?')) return false;
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.style.opacity = '0.7';
    btn.querySelector('.btnIcon').style.display = 'none';
    btn.querySelector('.btnLoading').style.display = 'inline-flex';
    return true;
}
</script>
<?php include '../../includes/footer.php'; ?>