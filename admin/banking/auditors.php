<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$pageTitle = 'Auditor Summary';
$userRole = $user['role'] ?? 'staff';

$errors = [];

// Handle Add Auditor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_auditor'])) {
    verifyCsrf();
    $name        = trim($_POST['auditor_name'] ?? '');
    $firm        = trim($_POST['firm_name'] ?? '');
    $pan         = trim($_POST['pan_number'] ?? '');
    $cop         = trim($_POST['cop_no'] ?? '');
    $f_reg       = trim($_POST['f_reg'] ?? '');
    $ican        = trim($_POST['ICAN_mem_no'] ?? '');
    $class       = trim($_POST['class'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if (!$name) $errors[] = 'Auditor name is required.';

    if (!$errors) {
        $stmt = $db->prepare("
            INSERT INTO auditors 
            (auditor_name, firm_name, pan_number, cop_no, f_reg, ICAN_mem_no, class, address, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $firm ?: null, $pan ?: null, $cop ?: null, $f_reg ?: null, $ican ?: null, $class ?: null, $address ?: null, $is_active]);
        setFlash('success', "Auditor \"$name\" added.");
        header('Location: auditor_report.php'); exit;
    }
}

// Filter by name
$filterName = trim($_GET['auditor_name'] ?? '');

// Fetch all auditors
$where = ['is_active IN (0,1)'];
$params = [];
if ($filterName) {
    $where[] = 'auditor_name LIKE ?';
    $params[] = "%{$filterName}%";
}
$ws = implode(' AND ', $where);

$stmt = $db->prepare("SELECT * FROM auditors WHERE {$ws} ORDER BY auditor_name");
$stmt->execute($params);
$auditors = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="app-wrapper">
<?php
if ($userRole === 'executive') include '../../includes/sidebar_executive.php';
elseif ($userRole === 'admin') include '../../includes/sidebar_admin.php';
else include '../../includes/sidebar_staff.php';
?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>

<div style="padding:1.5rem 0;">
<?= flashHtml() ?>

<!-- HEADER -->
<div class="page-hero d-flex justify-content-between align-items-center">
    <div>
        <div class="page-hero-badge"><i class="fas fa-user-tie"></i> Audit</div>
        <h4>Auditor Summary Report</h4>
        <p>Track auditor performance</p>
    </div>
    <div>
        <button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#auditorModal">
            <i class="fas fa-plus me-1"></i> Add Auditor
        </button>
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
        <div class="col-md-2 d-flex gap-1">
            <button class="btn btn-gold btn-sm w-100"><i class="fas fa-filter"></i> </button>
            <a href="auditor_report.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
        </div>
    </form>
</div>

<!-- TABLE -->
<div class="card-mis">
    <div class="card-mis-header">
        <h5><i class="fas fa-user-tie text-warning me-2"></i>Auditors</h5>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Auditor</th>
                    <th>Firm</th>
                    <th>PAN</th>
                    <th>COP</th>
                    <th>F_REG</th>
                    <th>ICAN</th>
                    <th>Class</th>
                    <th>Address</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($auditors)): ?>
                    <tr><td colspan="10" class="text-center py-4">No data found</td></tr>
                <?php else: ?>
                    <?php foreach ($auditors as $i => $a): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($a['auditor_name']) ?></td>
                        <td><?= htmlspecialchars($a['firm_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($a['pan_number'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($a['cop_no'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($a['f_reg'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($a['ICAN_mem_no'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($a['class'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($a['address'] ?? '—') ?></td>
                        <td>
                            <?php if ($a['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL FOR ADD AUDITOR -->
<div class="modal fade" id="auditorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#0a0f1e;">
                <h5 class="modal-title text-white"><i class="fas fa-user-tie me-2"></i>Add Auditor</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="auditorForm">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="add_auditor" value="1">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-mis">Auditor Name *</label>
                            <input type="text" name="auditor_name" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-mis">Firm Name</label>
                            <input type="text" name="firm_name" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-mis">PAN Number</label>
                            <input type="text" name="pan_number" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-mis">COP No</label>
                            <input type="text" name="cop_no" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-mis">F_REG</label>
                            <input type="text" name="f_reg" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-mis">ICAN Mem No</label>
                            <input type="text" name="ICAN_mem_no" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-mis">Class</label>
                            <input type="text" name="class" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-mis">Address</label>
                            <textarea name="address" rows="2" class="form-control form-control-sm"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" name="is_active" checked>
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-gold btn-sm" onclick="document.getElementById('auditorForm').submit();">
                    <i class="fas fa-save me-1"></i>Save
                </button>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>