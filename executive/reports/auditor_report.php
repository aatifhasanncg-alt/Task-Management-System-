<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$pageTitle = 'Auditor Summary';
$userRole = $user['role'] ?? 'staff';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_auditor'])) {
    verifyCsrf();

    // 🔒 ONLY Core Admin allowed
    if (!isCoreAdmin()) {
        setFlash('error', 'You are not allowed to edit auditors.');
        header('Location: auditor_report.php');
        exit;
    }

    $auditorId = (int) ($_POST['auditor_id'] ?? 0);
    $auditorName = trim($_POST['auditor_name'] ?? '');
    $firmName = trim($_POST['firm_name'] ?? '');
    $panNumber = trim($_POST['pan_number'] ?? '');
    $copNo = trim($_POST['cop_no'] ?? '');
    $fReg = trim($_POST['f_reg'] ?? '');
    $icanMemNo = trim($_POST['ICAN_mem_no'] ?? '');
    $class = trim($_POST['class'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $countable = (int) ($_POST['countable_count'] ?? 0);
    $uncountable = (int) ($_POST['uncountable_count'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if (!$auditorName) {
        setFlash('error', 'Auditor name is required.');
        header('Location: auditor_report.php');
        exit;
    }

    if ($auditorId > 0) {
        $stmt = $db->prepare("
            UPDATE auditors SET
                auditor_name=?, firm_name=?, pan_number=?, cop_no=?,
                f_reg=?, ICAN_mem_no=?, class=?, address=?, is_active=?,
                countable_count=?, uncountable_count=?
            WHERE id=?
        ");

        $stmt->execute([
            $auditorName,
            $firmName,
            $panNumber,
            $copNo,
            $fReg,
            $icanMemNo,
            $class,
            $address,
            $isActive,
            $countable,
            $uncountable,
            $auditorId
        ]);
        setFlash('success', 'Auditor updated successfully.');
    } else {
        $stmt = $db->prepare("
            INSERT INTO auditors
                (auditor_name, firm_name, pan_number, cop_no, f_reg, ICAN_mem_no, class, address, is_active, countable_count, uncountable_count)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");

        $stmt->execute([
            $auditorName,
            $firmName,
            $panNumber,
            $copNo,
            $fReg,
            $icanMemNo,
            $class,
            $address,
            $isActive,
            $countable,
            $uncountable
        ]);
        $stmt->execute([
            $auditorName,
            $firmName,
            $panNumber,
            $copNo,
            $fReg,
            $icanMemNo,
            $class,
            $address,
            $isActive
        ]);
        setFlash('success', 'Auditor added successfully.');
    }

    header('Location: auditor_report.php');
    exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterName = trim($_GET['auditor_name'] ?? '');
$filterClass = trim($_GET['class'] ?? '');

// ── Fetch auditor rows ────────────────────────────────────────────────────────
$where = ['a.is_active IN (0,1)'];
$params = [];

if ($filterName) {
    $where[] = 'a.auditor_name LIKE ?';
    $params[] = "%{$filterName}%";
}
if ($filterClass) {
    $where[] = 'a.class LIKE ?';
    $params[] = "%{$filterClass}%";
}

$ws = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT
        a.id, a.auditor_name, a.firm_name, a.pan_number, a.cop_no,
        a.f_reg, a.ICAN_mem_no, a.class, a.address, a.is_active, a.countable_count, a.uncountable_count,
        COUNT(tb.id) AS total_files,
        SUM(CASE WHEN ts.status_name = 'Done'    THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN ts.status_name = 'Pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN ts.status_name = 'HBC'     THEN 1 ELSE 0 END) AS hbc
    FROM auditors a
    LEFT JOIN task_banking tb ON tb.auditor_id = a.id
    LEFT JOIN tasks        t  ON t.id = tb.task_id
    LEFT JOIN task_status  ts ON ts.id = t.status_id
    WHERE {$ws}
    GROUP BY a.id
    ORDER BY total_files DESC
");
$stmt->execute($params);
$auditors = $stmt->fetchAll();

// ── Grand total & per-row % ───────────────────────────────────────────────────
// Formula: auditor_files ÷ grand_total × 100
// e.g. grand=10, X=2→20%, Y=3→30%, Z=5→50%  (all rows sum to 100%)
$grandTotal = array_sum(array_column($auditors, 'total_files'));

foreach ($auditors as &$row) {
    $row['pct'] = ($grandTotal > 0)
        ? round(($row['total_files'] / $grandTotal) * 100, 2)
        : 0.00;
}
unset($row);

// Totals row values
$totals = [
    'total_files' => $grandTotal,
    'completed' => array_sum(array_column($auditors, 'completed')),
    'pending' => array_sum(array_column($auditors, 'pending')),
    'hbc' => array_sum(array_column($auditors, 'hbc')),
];

include '../../includes/header.php';
?>

<div class="app-wrapper">
    <?php
    if ($userRole === 'executive')
        include '../../includes/sidebar_executive.php';
    elseif ($userRole === 'admin')
        include '../../includes/sidebar_admin.php';
    else
        include '../../includes/sidebar_staff.php';
    ?>

    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <!-- HEADER -->
            <div class="page-hero">
                <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-user-tie"></i> Audit</div>
                        <h4>Auditor Summary Report</h4>
                        <p>Track auditor performance and file completion</p>
                    </div>
                    <div>
                        <button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#auditorModal">
                            <i class="fas fa-plus me-1"></i> Add Auditor
                        </button>
                    </div>
                </div>
            </div>

            <!-- FILTER -->
            <div class="filter-bar mb-4">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label-mis">Auditor Name</label>
                        <input type="text" name="auditor_name" class="form-control form-control-sm"
                            value="<?= htmlspecialchars($filterName) ?>" placeholder="Search by name...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-mis">Class</label>
                        <input type="text" name="class" class="form-control form-control-sm"
                            value="<?= htmlspecialchars($filterClass) ?>" placeholder="Filter by class...">
                    </div>
                    <div class="col-md-2 d-flex gap-1">
                        <button class="btn btn-gold btn-sm w-100"><i class="fas fa-filter"></i> Filter</button>
                        <a href="auditor_report.php" class="btn btn-outline-secondary btn-sm"><i
                                class="fas fa-times"></i></a>
                    </div>
                </form>
            </div>

            <!-- TABLE -->
            <div class="card-mis">
                <div class="card-mis-header">
                    <h5><i class="fas fa-user-tie text-warning me-2"></i>Auditor Performance</h5>
                    <small class="text-muted">
                        <?= count($auditors) ?> auditor<?= count($auditors) !== 1 ? 's' : '' ?>
                        &nbsp;|&nbsp; Grand total: <strong><?= $grandTotal ?></strong> files
                    </small>
                </div>
                <div class="table-responsive">
                    <table class="table-mis w-100">
                        <thead>
                            <tr style="background:#0a0f1e;">
                                <th style="color:#9ca3af;">#</th>
                                <th style="color:#c9a84c;">Auditor</th>
                                <th style="color:#c9a84c;">Firm</th>
                                <th style="color:#c9a84c;">PAN</th>
                                <th style="color:#c9a84c;">COP</th>
                                <th style="color:#c9a84c;">Firm Reg.</th>
                                <th style="color:#c9a84c;">Mem No.</th>
                                <th style="color:#c9a84c;">Class</th>
                                <th style="color:#c9a84c;">Address</th>
                                <th style="color:#c9a84c;">Status</th>
                                <th style="color:#10b981;text-align:center;">Countable</th>
                                <th style="color:#f59e0b;text-align:center;">Uncountable</th>
                                <th style="color:#c9a84c;text-align:center;">Total</th>
                                <th style="color:#10b981;text-align:center;">Done</th>
                                <th style="color:#ef4444;text-align:center;">Pending</th>
                                <th style="color:#f59e0b;text-align:center;">HBC</th>
                                <th style="color:#c9a84c;text-align:center;" title="Auditor files ÷ Grand total × 100">%
                                    of Total</th>
                                <th style="color:#9ca3af;text-align:center;">Edit</th>
                            </tr>
                        </thead>
                        <tbody>

                            <!-- Grand totals row — 100% when files exist, 0% when none -->
                            <tr style="background:#1e2a45;">
                                <td colspan="10" style="color:#c9a84c;font-weight:700;font-size:.82rem;">ALL AUDITORS
                                </td>
                                <td style="text-align:center;font-weight:600;color:#10b981;">
                                    <?= array_sum(array_column($auditors, 'countable_count')) ?>
                                </td>

                                <td style="text-align:center;font-weight:600;color:#f59e0b">
                                    <?= array_sum(array_column($auditors, 'uncountable_count')) ?>
                                </td>
                                </td>
                                <td style="color:#fff;font-weight:700;text-align:center;"><?= $totals['total_files'] ?>
                                </td>
                                <td style="color:#10b981;font-weight:700;text-align:center;"><?= $totals['completed'] ?>
                                </td>
                                <td style="color:#ef4444;font-weight:700;text-align:center;"><?= $totals['pending'] ?>
                                </td>
                                <td style="color:#f59e0b;font-weight:700;text-align:center;"><?= $totals['hbc'] ?></td>
                                <td style="color:#c9a84c;font-weight:700;text-align:center;">
                                    <?= $grandTotal > 0 ? '100.00%' : '0.00%' ?>
                                </td>
                                <td></td>
                            </tr>

                            <?php if (empty($auditors)): ?>
                                <tr>
                                    <td colspan="16" class="empty-state"><i class="fas fa-user-tie"></i> No data found</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($auditors as $i => $a): ?>
                                <tr>
                                    <td style="color:#9ca3af;font-size:.78rem;"><?= $i + 1 ?></td>
                                    <td style="font-size:.87rem;font-weight:500;">
                                        <?= htmlspecialchars($a['auditor_name']) ?>
                                    </td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($a['firm_name']) ?></td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($a['pan_number']) ?></td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($a['cop_no']) ?></td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($a['f_reg']) ?></td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($a['ICAN_mem_no']) ?></td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($a['class']) ?></td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($a['address']) ?></td>
                                    <td>
                                        <?php if ($a['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;font-weight:600;"><?= $a['countable_count'] ?? 0 ?></td>

                                    <td style="text-align:center;font-weight:600;"><?= $a['uncountable_count'] ?? 0 ?></td>
                                    <td style="text-align:center;font-weight:600;"><?= $a['total_files'] ?></td>
                                    <td style="text-align:center;">
                                        <span
                                            style="background:#ecfdf5;color:#10b981;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600;"><?= $a['completed'] ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <span
                                            style="background:#fef2f2;color:#ef4444;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600;"><?= $a['pending'] ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <span
                                            style="background:#fffbeb;color:#f59e0b;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600;"><?= $a['hbc'] ?></span>
                                    </td>

                                    <!-- % of grand total with progress bar -->
                                    <td style="min-width:130px;">
                                        <div style="display:flex;align-items:center;gap:.4rem;">
                                            <div
                                                style="flex:1;background:#1e2a45;border-radius:99px;height:5px;overflow:hidden;">
                                                <div
                                                    style="width:<?= min(100, $a['pct']) ?>%;background:#c9a84c;height:100%;border-radius:99px;transition:width .4s ease;">
                                                </div>
                                            </div>
                                            <span
                                                style="font-size:.75rem;font-weight:600;color:#c9a84c;flex-shrink:0;min-width:38px;text-align:right;">
                                                <?= number_format($a['pct'], 2) ?>%
                                            </span>
                                        </div>
                                    </td>

                                    <td style="text-align:center;">
                                        <?php if (isCoreAdmin()): ?>
                                            <button class="btn btn-sm btn-outline-secondary"
                                                onclick='openAuditorModal(<?= json_encode($a) ?>)'>
                                                <i class="fas fa-pen"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:.75rem;">No Access</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- MODAL FOR ADD/EDIT AUDITOR -->
            <div class="modal fade" id="auditorModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header" style="background:#0a0f1e;">
                            <h5 class="modal-title text-white"><i class="fas fa-user-tie me-2"></i>Auditor</h5>
                            <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form method="POST" id="auditorForm">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="auditor_id" id="auditor_id">
                                <input type="hidden" name="save_auditor" value="1">

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label-mis">Auditor Name *</label>
                                        <input type="text" name="auditor_name" id="auditor_name"
                                            class="form-control form-control-sm" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-mis">Firm Name</label>
                                        <input type="text" name="firm_name" id="firm_name"
                                            class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-mis">PAN Number</label>
                                        <input type="text" name="pan_number" id="pan_number"
                                            class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-mis">COP No</label>
                                        <input type="text" name="cop_no" id="cop_no"
                                            class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-mis">Firm Reg.</label>
                                        <input type="text" name="f_reg" id="f_reg" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-mis">ICAN Membership No</label>
                                        <input type="text" name="ICAN_mem_no" id="ICAN_mem_no"
                                            class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-mis">Class</label>
                                        <input type="text" name="class" id="class" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-mis">Address</label>
                                        <textarea name="address" id="address" class="form-control form-control-sm"
                                            <?= isCoreAdmin() ? '' : 'readonly' ?>></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-mis">Countable Count</label>
                                        <input type="number" name="countable_count" id="countable_count"
                                            class="form-control form-control-sm" min="0">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label-mis">Uncountable Count</label>
                                        <input type="number" name="uncountable_count" id="uncountable_count"
                                            class="form-control form-control-sm" min="0">
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check form-switch">
                                            <input type="checkbox" name="is_active" id="is_active" <?= isCoreAdmin() ? '' : 'disabled' ?>>
                                            <label class="form-check-label" for="is_active">Active</label>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <?php if (isCoreAdmin()): ?>
                                <button class="btn btn-gold btn-sm"
                                    onclick="document.getElementById('auditorForm').submit();">
                                    <i class="fas fa-save me-1"></i>Save
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                function openAuditorModal(auditor) {
                    document.getElementById('auditor_id').value = auditor.id || '';
                    document.getElementById('auditor_name').value = auditor.auditor_name || '';
                    document.getElementById('firm_name').value = auditor.firm_name || '';
                    document.getElementById('pan_number').value = auditor.pan_number || '';
                    document.getElementById('cop_no').value = auditor.cop_no || '';
                    document.getElementById('f_reg').value = auditor.f_reg || '';
                    document.getElementById('ICAN_mem_no').value = auditor.ICAN_mem_no || '';
                    document.getElementById('class').value = auditor.class || '';
                    document.getElementById('address').value = auditor.address || '';
                    document.getElementById('countable_count').value = auditor.countable_count || 0;
                    document.getElementById('uncountable_count').value = auditor.uncountable_count || 0;
                    document.getElementById('is_active').checked = auditor.is_active == 1;

                    new bootstrap.Modal(document.getElementById('auditorModal')).show();
                }
            </script>

        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>