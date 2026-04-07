<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

// Only core admin department executives can add companies
if (!isCoreAdmin()) {
    setFlash('error', 'Access denied. Only Core Admin executives can add companies.');
    header('Location: index.php'); exit;
}

$db        = getDB();
$user      = currentUser();
$pageTitle = 'Add Company';
$errors    = [];

// Get next company code (same logic as trigger)
$nextCode = $db->query("
    SELECT CONCAT('CP-', LPAD(
        COALESCE(MAX(CAST(SUBSTRING_INDEX(company_code,'-',-1) AS UNSIGNED)),0) + 1
    ,3,'0')) AS next_code
    FROM companies
")->fetchColumn();

// Load lookups
$allBranches   = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
$allTypes      = $db->query("SELECT id, type_name FROM company_types ORDER BY type_name")->fetchAll();
$allIndustries = $db->query("SELECT id, industry_name FROM industries WHERE is_active=1 ORDER BY industry_name")->fetchAll();

// Handle single-company POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'single') {
    verifyCsrf();

    $companyName   = trim($_POST['company_name']   ?? '');
    $companyCode   = trim($_POST['company_code']   ?? '');
    $panNumber     = trim($_POST['pan_number']     ?? '');
    $regNumber     = trim($_POST['reg_number']     ?? '');
    $companyTypeId = (int)($_POST['company_type_id'] ?? 0) ?: null;
    $branchId      = (int)($_POST['branch_id']     ?? 0) ?: null;
    $returnType    = trim($_POST['return_type']    ?? '');
    $industryId    = (int)($_POST['industry_id']   ?? 0) ?: null;
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $contactPhone  = trim($_POST['contact_phone']  ?? '');
    $contactEmail  = trim($_POST['contact_email']  ?? '');
    $address       = trim($_POST['address']        ?? '');

    if (!$companyName)   $errors[] = 'Company name is required.';
    if (!$companyTypeId) $errors[] = 'Company type is required.';
    if (!$branchId)      $errors[] = 'Branch is required.';

    if (!$errors) {
        $dupCheck = $db->prepare("SELECT id FROM companies WHERE company_name = ? AND is_active = 1");
        $dupCheck->execute([$companyName]);
        if ($dupCheck->fetch()) $errors[] = 'A company with this name already exists.';
    }
    if (!$errors && $panNumber) {
        $panCheck = $db->prepare("SELECT id FROM companies WHERE pan_number = ? AND is_active = 1");
        $panCheck->execute([$panNumber]);
        if ($panCheck->fetch()) $errors[] = 'A company with this PAN number already exists.';
    }

    if (!$errors) {
        if (!$companyCode) {
            $companyCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $companyName), 0, 6))
                         . rand(100, 999);
        }
        $db->prepare("
            INSERT INTO companies
            (company_name, company_code, pan_number, reg_number,
             company_type_id, branch_id, return_type, industry_id,
             contact_person, contact_phone, contact_email,
             address, added_by, is_active, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())
        ")->execute([
            $companyName, $companyCode, $panNumber ?: null, $regNumber ?: null,
            $companyTypeId, $branchId, $returnType ?: null, $industryId,
            $contactPerson ?: null, $contactPhone ?: null, $contactEmail ?: null,
            $address ?: null, $user['id'],
        ]);
        logActivity("Company added: {$companyName}", 'companies');
        setFlash('success', "Company \"{$companyName}\" added successfully.");
        header("Location: index.php"); exit;
    }
}

// Pull bulk-import row errors from session (set by bulk_import.php)
$bulkErrors = $_SESSION['bulk_errors'] ?? [];
unset($_SESSION['bulk_errors']);

// Which tab to open: 'single' or 'bulk'
$activeTab = isset($_GET['bulk']) ? 'bulk' : 'single';

include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php include '../../includes/sidebar_executive.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<?= flashHtml() ?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <a href="index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Back
    </a>
    <h5 style="margin:0;">Add Company</h5>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger rounded-3 mb-4">
    <strong>Please fix:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ── Tab switcher ─────────────────────────────────────────────────────────── -->
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;border-bottom:2px solid #f3f4f6;padding-bottom:0;">
    <button id="tab-single-btn" onclick="switchTab('single')"
        style="background:none;border:none;padding:.6rem 1.2rem;font-size:.88rem;font-weight:600;
               cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;
               <?= $activeTab === 'single' ? 'color:#c9a84c;border-bottom-color:#c9a84c;' : 'color:#9ca3af;' ?>">
        <i class="fas fa-building me-1"></i>Single Company
    </button>
    <button id="tab-bulk-btn" onclick="switchTab('bulk')"
        style="background:none;border:none;padding:.6rem 1.2rem;font-size:.88rem;font-weight:600;
               cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;
               <?= $activeTab === 'bulk' ? 'color:#c9a84c;border-bottom-color:#c9a84c;' : 'color:#9ca3af;' ?>">
        <i class="fas fa-file-excel me-1"></i>Bulk Import / Update via Excel
    </button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1 — Single Company                                                    -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-single" style="display:<?= $activeTab === 'single' ? 'block' : 'none' ?>;">
<div class="row g-4">
    <div class="col-lg-8">
        <form method="POST">
            <input type="hidden" name="csrf_token"   value="<?= csrfToken() ?>">
            <input type="hidden" name="form_action"  value="single">

            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-building text-warning me-2"></i>Company Information</h5>
                </div>
                <div class="card-mis-body">
                    <div class="row g-3">

                        <div class="col-md-8">
                            <label class="form-label-mis">Company Name <span class="required-star">*</span></label>
                            <input type="text" name="company_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>"
                                   placeholder="Full legal name" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label-mis">Company Code
                                <span style="font-size:.7rem;color:#9ca3af;">(auto if blank)</span>
                            </label>
                            <input type="text" class="form-control"
                                   value="<?= htmlspecialchars($nextCode) ?>"
                                   readonly style="background:#f9fafb;font-weight:600;">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label-mis">Company Type <span class="required-star">*</span></label>
                            <select name="company_type_id" class="form-select" required>
                                <option value="">-- Select Type --</option>
                                <?php foreach ($allTypes as $t): ?>
                                    <option value="<?= $t['id'] ?>"
                                        <?= ($_POST['company_type_id'] ?? '') == $t['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['type_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label-mis">Branch <span class="required-star">*</span></label>
                            <select name="branch_id" class="form-select" required>
                                <option value="">-- Select Branch --</option>
                                <?php foreach ($allBranches as $b): ?>
                                    <option value="<?= $b['id'] ?>"
                                        <?= ($_POST['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['branch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label-mis">Return Type</label>
                            <select name="return_type" class="form-select">
                                <option value="">-- Select --</option>
                                <?php foreach (['D1','D2','D3','D4'] as $rt): ?>
                                    <option value="<?= $rt ?>"
                                        <?= ($_POST['return_type'] ?? '') === $rt ? 'selected' : '' ?>>
                                        <?= $rt ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label-mis">PAN Number</label>
                            <input type="text" name="pan_number" class="form-control"
                                   value="<?= htmlspecialchars($_POST['pan_number'] ?? '') ?>"
                                   placeholder="09-digit PAN">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label-mis">Registration Number</label>
                            <input type="text" name="reg_number" class="form-control"
                                   value="<?= htmlspecialchars($_POST['reg_number'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label-mis">Industry</label>
                            <select name="industry_id" class="form-select">
                                <option value="">-- Select --</option>
                                <?php foreach ($allIndustries as $ind): ?>
                                    <option value="<?= $ind['id'] ?>"
                                        <?= ($_POST['industry_id'] ?? '') == $ind['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($ind['industry_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>
                </div>
            </div>

            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-address-card text-warning me-2"></i>Contact Details</h5>
                </div>
                <div class="card-mis-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-mis">Contact Person</label>
                            <input type="text" name="contact_person" class="form-control"
                                   value="<?= htmlspecialchars($_POST['contact_person'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-mis">Contact Phone</label>
                            <input type="text" name="contact_phone" class="form-control"
                                   value="<?= htmlspecialchars($_POST['contact_phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-mis">Contact Email</label>
                            <input type="email" name="contact_email" class="form-control"
                                   value="<?= htmlspecialchars($_POST['contact_email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-mis">Address</label>
                            <input type="text" name="address" class="form-control"
                                   value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-gold">
                <i class="fas fa-save me-1"></i>Save Company
            </button>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card-mis p-3" style="border-left:3px solid #c9a84c;">
            <div style="font-size:.82rem;color:#6b7280;">
                <div class="mb-2"><i class="fas fa-info-circle text-warning me-2"></i>
                    <strong>Note:</strong> Only Core Admin executives can add companies.
                </div>
                <div class="mb-2">Company code is auto-generated if left blank.</div>
                <div>PAN number must be unique across all companies.</div>
            </div>
        </div>
    </div>
</div>
</div><!-- /tab-single -->

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 2 — Bulk Import / Update                                              -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-bulk" style="display:<?= $activeTab === 'bulk' ? 'block' : 'none' ?>;">
<div class="row g-4">
    <div class="col-lg-8">

        <!-- Row errors from last import -->
        <?php if (!empty($bulkErrors)): ?>
        <div class="alert alert-warning rounded-3 mb-4" style="font-size:.83rem;">
            <strong><i class="fas fa-triangle-exclamation me-1"></i>
                Some rows were skipped (<?= count($bulkErrors) ?>):
            </strong>
            <ul class="mb-0 mt-2" style="max-height:200px;overflow-y:auto;">
                <?php foreach ($bulkErrors as $be): ?>
                    <li><?= htmlspecialchars($be) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Upload card -->
        <div class="card-mis mb-4">
            <div class="card-mis-header">
                <h5><i class="fas fa-file-excel text-warning me-2"></i>Upload Excel File</h5>
            </div>
            <div class="card-mis-body">
                <form method="POST" action="bulk_import.php" enctype="multipart/form-data" id="bulk-form">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                    <!-- Drop zone -->
                    <div id="drop-zone"
                        onclick="document.getElementById('bulk_file').click()"
                        style="border:2px dashed #d1d5db;border-radius:12px;padding:2.5rem 1.5rem;
                               text-align:center;cursor:pointer;transition:.2s;background:#fafafa;"
                        ondragover="event.preventDefault();this.style.borderColor='#c9a84c';this.style.background='#fffbeb';"
                        ondragleave="this.style.borderColor='#d1d5db';this.style.background='#fafafa';"
                        ondrop="handleDrop(event)">
                        <div id="drop-icon" style="margin-bottom:.75rem;">
                            <i class="fas fa-cloud-upload-alt" style="font-size:2.2rem;color:#c9a84c;"></i>
                        </div>
                        <div id="drop-text" style="font-size:.9rem;font-weight:600;color:#1f2937;">
                            Click or drag & drop your Excel file here
                        </div>
                        <div style="font-size:.78rem;color:#9ca3af;margin-top:.35rem;">
                            Supports .xlsx, .xls · Max 5 MB · Up to 500 rows
                        </div>
                        <input type="file" id="bulk_file" name="bulk_file"
                               accept=".xlsx,.xls"
                               style="display:none;"
                               onchange="onFileSelect(this)">
                    </div>

                    <!-- Selected file preview -->
                    <div id="file-preview" style="display:none;margin-top:1rem;
                        background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;
                        padding:.75rem 1rem;display:none;align-items:center;gap:.75rem;">
                        <i class="fas fa-file-excel" style="color:#16a34a;font-size:1.4rem;"></i>
                        <div style="flex:1;min-width:0;">
                            <div id="file-name" style="font-size:.87rem;font-weight:600;color:#1f2937;
                                 white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
                            <div id="file-size" style="font-size:.75rem;color:#6b7280;"></div>
                        </div>
                        <button type="button" onclick="clearFile()"
                            style="background:none;border:none;color:#9ca3af;font-size:1rem;cursor:pointer;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" id="upload-btn" class="btn btn-gold" disabled>
                            <i class="fas fa-upload me-1"></i>Upload & Process
                        </button>
                        <span id="upload-spinner" style="display:none;align-items:center;gap:.5rem;color:#6b7280;font-size:.85rem;">
                            <i class="fas fa-spinner fa-spin"></i> Processing…
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <!-- How it works -->
        <div class="card-mis mb-4">
            <div class="card-mis-header">
                <h5><i class="fas fa-circle-question text-warning me-2"></i>How It Works</h5>
            </div>
            <div class="card-mis-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div style="background:#eff6ff;border-radius:10px;padding:1rem;">
                            <div style="font-size:.82rem;font-weight:700;color:#2563eb;margin-bottom:.5rem;">
                                <i class="fas fa-plus-circle me-1"></i>BULK INSERT
                            </div>
                            <ul style="font-size:.8rem;color:#1e40af;margin:0;padding-left:1.1rem;">
                                <li>Leave <strong>Company Code</strong> column blank</li>
                                <li>Company name must not already exist</li>
                                <li>Code is auto-generated (CP-001, CP-002…)</li>
                                <li>PAN must be unique if provided</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div style="background:#fefce8;border-radius:10px;padding:1rem;">
                            <div style="font-size:.82rem;font-weight:700;color:#b45309;margin-bottom:.5rem;">
                                <i class="fas fa-pen me-1"></i>BULK UPDATE
                            </div>
                            <ul style="font-size:.8rem;color:#92400e;margin:0;padding-left:1.1rem;">
                                <li>Provide the exact <strong>Company Code</strong></li>
                                <li>All non-blank fields will be overwritten</li>
                                <li>Company must exist and be active</li>
                                <li>Mix insert &amp; update rows in one file</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-12">
                        <div style="font-size:.78rem;color:#6b7280;background:#f9fafb;
                            border-radius:8px;padding:.75rem 1rem;line-height:1.7;">
                            <strong>Column rules:</strong>
                            Company Type, Branch Name, and Industry must match the exact names
                            listed in the <em>Valid Values</em> sheet of the template.
                            Return Type must be one of: D1, D2, D3, D4.
                            PAN must be exactly 09 digits.
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- col-lg-8 -->

    <!-- Right sidebar -->
    <div class="col-lg-4">

        <!-- Download template -->
        <div class="card-mis mb-3" style="border-left:3px solid #c9a84c;">
            <div class="card-mis-body p-3">
                <div style="font-size:.85rem;font-weight:700;color:#1f2937;margin-bottom:.5rem;">
                    <i class="fas fa-download text-warning me-1"></i>Step 1 — Download Template
                </div>
                <p style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">
                    Use our pre-formatted Excel template. It includes sample rows,
                    column hints, and a <em>Valid Values</em> reference sheet.
                </p>
                <a href="<?= APP_URL ?>/executive/companies/download_template.php"
                   class="btn btn-gold btn-sm w-100">
                    <i class="fas fa-file-excel me-1"></i>Download Template (.xlsx)
                </a>
            </div>
        </div>

        <!-- Valid values quick ref -->
        <div class="card-mis mb-3 p-3">
            <div style="font-size:.82rem;font-weight:700;color:#1f2937;margin-bottom:.75rem;">
                <i class="fas fa-list-check text-warning me-1"></i>Valid Values Quick Ref
            </div>

            <div style="font-size:.75rem;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:.3rem;">
                Company Types
            </div>
            <div style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">
                <?php
                foreach ($allTypes as $t)
                    echo '<span style="background:#f3f4f6;border-radius:4px;padding:.1rem .4rem;margin:.1rem .1rem 0 0;display:inline-block;">'
                       . htmlspecialchars($t['type_name']) . '</span>';
                ?>
            </div>

            <div style="font-size:.75rem;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:.3rem;">
                Industries
            </div>
            <div style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">
                <?php
                foreach ($allIndustries as $ind)
                    echo '<span style="background:#f3f4f6;border-radius:4px;padding:.1rem .4rem;margin:.1rem .1rem 0 0;display:inline-block;">'
                       . htmlspecialchars($ind['industry_name']) . '</span>';
                ?>
            </div>

            <div style="font-size:.75rem;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:.3rem;">
                Return Types
            </div>
            <div style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">
                <?php foreach (['D1','D2','D3','D4'] as $rt): ?>
                    <span style="background:#f3f4f6;border-radius:4px;padding:.1rem .4rem;margin:.1rem .1rem 0 0;display:inline-block;">
                        <?= $rt ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <div style="font-size:.75rem;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:.3rem;">
                Branches
            </div>
            <div style="font-size:.78rem;color:#6b7280;">
                <?php
                foreach ($allBranches as $b)
                    echo '<span style="background:#f3f4f6;border-radius:4px;padding:.1rem .4rem;margin:.1rem .1rem 0 0;display:inline-block;">'
                       . htmlspecialchars($b['branch_name']) . '</span>';
                ?>
            </div>
        </div>

    </div><!-- col-lg-4 -->
</div>
</div><!-- /tab-bulk -->

</div><!-- padding wrapper -->
<?php include '../../includes/footer.php'; ?>
</div></div><!-- main-content / app-wrapper -->

<script>
// ── Tab switching ──────────────────────────────────────────────────────────
function switchTab(tab) {
    document.getElementById('tab-single').style.display = tab === 'single' ? 'block' : 'none';
    document.getElementById('tab-bulk').style.display   = tab === 'bulk'   ? 'block' : 'none';
    const gold = '#c9a84c', gray = '#9ca3af';
    const active  = tab === 'single' ? 'tab-single-btn' : 'tab-bulk-btn';
    const inactive = tab === 'single' ? 'tab-bulk-btn'  : 'tab-single-btn';
    document.getElementById(active).style.color            = gold;
    document.getElementById(active).style.borderBottomColor = gold;
    document.getElementById(inactive).style.color            = gray;
    document.getElementById(inactive).style.borderBottomColor = 'transparent';
}

// ── File selection helpers ─────────────────────────────────────────────────
function formatBytes(bytes) {
    if (bytes < 1024)       return bytes + ' B';
    if (bytes < 1024*1024)  return (bytes/1024).toFixed(1) + ' KB';
    return (bytes/1024/1024).toFixed(2) + ' MB';
}

function showFile(file) {
    document.getElementById('file-name').textContent = file.name;
    document.getElementById('file-size').textContent = formatBytes(file.size);
    document.getElementById('file-preview').style.display = 'flex';
    document.getElementById('drop-text').textContent = 'File selected — ready to upload';
    document.getElementById('drop-icon').innerHTML = '<i class="fas fa-check-circle" style="font-size:2.2rem;color:#16a34a;"></i>';
    document.getElementById('upload-btn').disabled = false;
}

function onFileSelect(input) {
    if (input.files[0]) showFile(input.files[0]);
}

function handleDrop(e) {
    e.preventDefault();
    const dz = document.getElementById('drop-zone');
    dz.style.borderColor = '#d1d5db';
    dz.style.background  = '#fafafa';
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const allowed = ['xlsx','xls'];
    const ext = file.name.split('.').pop().toLowerCase();
    if (!allowed.includes(ext)) {
        alert('Please upload an .xlsx or .xls file.');
        return;
    }
    const dt = new DataTransfer();
    dt.items.add(file);
    document.getElementById('bulk_file').files = dt.files;
    showFile(file);
}

function clearFile() {
    document.getElementById('bulk_file').value = '';
    document.getElementById('file-preview').style.display = 'none';
    document.getElementById('drop-text').textContent = 'Click or drag & drop your Excel file here';
    document.getElementById('drop-icon').innerHTML = '<i class="fas fa-cloud-upload-alt" style="font-size:2.2rem;color:#c9a84c;"></i>';
    document.getElementById('upload-btn').disabled = true;
}

// ── Show spinner on submit ─────────────────────────────────────────────────
document.getElementById('bulk-form').addEventListener('submit', function() {
    document.getElementById('upload-btn').disabled = true;
    document.getElementById('upload-spinner').style.display = 'flex';
});
</script>