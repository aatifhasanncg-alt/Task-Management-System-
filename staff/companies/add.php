<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$pageTitle = 'Add Company';

$depts      = $db->query("SELECT id,dept_name FROM departments WHERE is_active=1 ORDER BY dept_name")->fetchAll();
$branches   = $db->query("SELECT id,branch_name,city FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
$industries = $db->query("SELECT id,industry_name FROM industries WHERE is_active=1 ORDER BY industry_name")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name       = trim($_POST['company_name'] ?? '');
    $pan        = trim($_POST['pan_number'] ?? '');
    $reg        = trim($_POST['registration_number'] ?? '');
    $type       = (int)($_POST['company_type_id'] ?? 0);
    $industryId = (int)($_POST['industry_id'] ?? 0);
    $address    = trim($_POST['address'] ?? '');
    $contact    = trim($_POST['contact_person'] ?? '');
    $email      = strtolower(trim($_POST['contact_email'] ?? ''));
    $phone      = trim($_POST['contact_phone'] ?? '');
    $branchId   = (int)($_POST['branch_id'] ?? 0);
    $returnType = $_POST['return_type'] ?? 'N/A';

    // Validation
    if (!$name)
        $errors[] = 'Company name is required.';
    if (strlen($name) > 200)
        $errors[] = 'Company name too long (max 200).';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Invalid contact email.';
    if ($phone && !preg_match('/^[\d\s\+\-\(\)]{7,20}$/', $phone))
        $errors[] = 'Invalid phone format.';

    if (!$errors) {
        $dup = $db->prepare("SELECT id FROM companies WHERE company_name=? AND is_active=1");
        $dup->execute([$name]);
        if ($dup->fetch())
            $errors[] = "Company \"{$name}\" already exists.";
    }
    if ($pan && !preg_match('/^\d{9}$/', $pan)) {
        $errors[] = "PAN number must be exactly 09 digits.";
    }
    if (!$errors && $pan) {
        $dup2 = $db->prepare("SELECT id FROM companies WHERE pan_number=? AND is_active=1");
        $dup2->execute([$pan]);
        if ($dup2->fetch())
            $errors[] = "PAN number already registered.";
    }

    if (!$errors) {
        $ins = $db->prepare("
            INSERT INTO companies(
                company_name,
                pan_number,
                reg_number,
                company_type_id,
                industry_id,
                return_type,
                address,
                contact_person,
                contact_email,
                contact_phone,
                branch_id,
                added_by
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $ins->execute([
            $name,
            $pan ?: null,
            $reg ?: null,
            $type ?: null,
            $industryId ?: null,
            $returnType,
            $address,
            $contact,
            $email ?: null,
            $phone ?: null,
            $branchId ?: null,
            $user['id']
        ]);
        logActivity("Added company: {$name}", 'companies');
        setFlash('success', "Company \"{$name}\" added successfully.");
        header('Location: index.php');
        exit;
    }
}
$types = $db->query("
    SELECT id,type_name 
    FROM company_types 
    ORDER BY type_name
")->fetchAll();
include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger rounded-3 mb-3">
                    <strong>Please fix:</strong>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-9">
                    <form method="POST" novalidate id="addCompanyForm">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-info-circle text-warning me-2"></i>Basic Information</h5>
                            </div>
                            <div class="card-mis-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label-mis">Company Name <span class="required-star" style="color:#ef4444;">*</span></label>
                                        <input type="text" name="company_name" id="company_name" class="form-control" maxlength="200"
                                            value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>" required>
                                        <div class="invalid-feedback-mis" id="err_company_name" style="color:#ef4444;font-size:.72rem;display:none;"></div>
                                        <small id="company_name_count" style="font-size:.7rem;color:#9ca3af;float:right;"></small>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label-mis">Company Code</label>
                                        <input type="text"
                                            class="form-control"
                                            value="Auto Generated"
                                            readonly
                                            style="background:#f3f4f6;font-weight:600;">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label-mis">Company Type</label>
                                        <select name="company_type_id" class="form-select">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($types as $t): ?>
                                                <option value="<?= $t['id'] ?>"
                                                    <?= ($_POST['company_type_id'] ?? '') == $t['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($t['type_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label-mis">Return Type</label>
                                        <select name="return_type" class="form-select">
                                            <option value="N/A">N/A</option>
                                            <option value="D1">D1</option>
                                            <option value="D2">D2</option>
                                            <option value="D3">D3</option>
                                            <option value="D4">D4</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">PAN Number</label>
                                        <input type="text"
                                            name="pan_number"
                                            id="pan_number"
                                            class="form-control"
                                            maxlength="9"
                                            pattern="\d{9}"
                                            placeholder="09 digit PAN"
                                            value="<?= htmlspecialchars($_POST['pan_number'] ?? '') ?>">
                                        <div class="invalid-feedback-mis" id="err_pan_number" style="color:#ef4444;font-size:.72rem;display:none;"></div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Registration Number</label>
                                        <input type="text" name="registration_number" class="form-control"
                                            value="<?= htmlspecialchars($_POST['registration_number'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Industry</label>
                                        <select name="industry_id" class="form-select">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($industries as $ind): ?>
                                                <option value="<?= $ind['id'] ?>"
                                                    <?= ($_POST['industry_id'] ?? '') == $ind['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($ind['industry_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Branch</label>
                                        <select name="branch_id" class="form-select">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($branches as $b): ?>
                                                <option value="<?= $b['id'] ?>"
                                                    <?= ($_POST['branch_id'] ?? '') == $b['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($b['branch_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label-mis">Address</label>
                                        <textarea name="address" class="form-control"
                                            rows="2"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-phone text-warning me-2"></i>Contact Details</h5>
                            </div>
                            <div class="card-mis-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Contact Person</label>
                                        <input type="text" name="contact_person" class="form-control"
                                            value="<?= htmlspecialchars($_POST['contact_person'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Contact Email</label>
                                        <input type="email" name="contact_email" id="contact_email" class="form-control"
                                            value="<?= htmlspecialchars($_POST['contact_email'] ?? '') ?>">
                                        <div class="invalid-feedback-mis" id="err_contact_email" style="color:#ef4444;font-size:.72rem;display:none;"></div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Contact Phone</label>
                                        <input type="tel" name="contact_phone" id="contact_phone" class="form-control"
                                            value="<?= htmlspecialchars($_POST['contact_phone'] ?? '') ?>">
                                        <div class="invalid-feedback-mis" id="err_contact_phone" style="color:#ef4444;font-size:.72rem;display:none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-3">
                            <button type="submit" id="addCompanySubmitBtn" class="btn-gold btn">
                                <span id="addCompanyBtnIcon"><i class="fas fa-save me-2"></i>Save Company</span>
                                <span id="addCompanyBtnLoading" style="display:none;align-items:center;">
                                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                    Saving...
                                </span>
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
                <div class="col-lg-3">
                    <div class="card-mis p-3" style="border-left:3px solid var(--gold);">
                        <p style="font-size:.8rem;font-weight:600;margin-bottom:.5rem;"><i
                                class="fas fa-info-circle text-warning me-1"></i>Tips</p>
                        <ul style="font-size:.78rem;color:#6b7280;padding-left:1rem;margin:0;">
                            <li>PAN must be unique across companies.</li>
                            <li>PAN must be 09 digit.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Floating Scroll-to-Bottom Arrow ── -->
        <button id="scrollDownArrow" type="button" title="Jump to Save button"
            style="
                position: fixed;
                bottom: 28px;
                right: 28px;
                width: 48px;
                height: 48px;
                border-radius: 50%;
                background: #c9a84c;
                color: #0a0f1e;
                border: none;
                box-shadow: 0 6px 20px rgba(0,0,0,.18);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.1rem;
                cursor: pointer;
                z-index: 999;
                transition: opacity .2s, transform .2s;
            ">
            <i class="fas fa-arrow-down"></i>
        </button>

        <script>
        function bindCounter(inputId, counterId, max) {
            const el = document.getElementById(inputId);
            const counter = document.getElementById(counterId);
            if (!el || !counter) return;
            const update = () => {
                const len = el.value.length;
                counter.textContent = `${len}/${max}`;
                counter.style.color = len >= max ? '#ef4444' : (len >= max * 0.9 ? '#f59e0b' : '#9ca3af');
            };
            el.addEventListener('input', update);
            update();
        }
        bindCounter('company_name', 'company_name_count', 200);

        function showFieldError(id, msg) {
            const el = document.getElementById(id);
            const err = document.getElementById('err_' + id);
            if (el) el.classList.add('is-invalid');
            if (err) { err.textContent = msg; err.style.display = 'block'; }
        }
        function clearFieldError(id) {
            const el = document.getElementById(id);
            const err = document.getElementById('err_' + id);
            if (el) el.classList.remove('is-invalid');
            if (err) err.style.display = 'none';
        }

        document.getElementById('addCompanyForm').addEventListener('submit', function (e) {
            let valid = true;

            const name = document.getElementById('company_name');
            if (!name.value.trim()) {
                valid = false;
                showFieldError('company_name', 'Company name is required.');
            } else if (name.value.length > 200) {
                valid = false;
                showFieldError('company_name', 'Company name too long (max 200).');
            } else {
                clearFieldError('company_name');
            }

            const pan = document.getElementById('pan_number');
            if (pan.value.trim() && !/^\d{9}$/.test(pan.value.trim())) {
                valid = false;
                showFieldError('pan_number', 'PAN number must be exactly 9 digits.');
            } else {
                clearFieldError('pan_number');
            }

            const email = document.getElementById('contact_email');
            if (email.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
                valid = false;
                showFieldError('contact_email', 'Invalid contact email.');
            } else {
                clearFieldError('contact_email');
            }

            const phone = document.getElementById('contact_phone');
            if (phone.value.trim() && !/^[\d\s\+\-\(\)]{7,20}$/.test(phone.value.trim())) {
                valid = false;
                showFieldError('contact_phone', 'Invalid phone format.');
            } else {
                clearFieldError('contact_phone');
            }

            if (!valid) {
                e.preventDefault();
                const firstInvalid = document.querySelector('.is-invalid');
                if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }

            const btn = document.getElementById('addCompanySubmitBtn');
            btn.disabled = true;
            btn.style.opacity = '0.75';
            btn.style.cursor = 'not-allowed';
            document.getElementById('addCompanyBtnIcon').style.display = 'none';
            document.getElementById('addCompanyBtnLoading').style.display = 'inline-flex';
            document.getElementById('addCompanyBtnLoading').style.alignItems = 'center';
        });
        // ── Scroll-to-bottom arrow ──────────────────────────────────────────────────
        (function () {
            const arrow = document.getElementById('scrollDownArrow');
            if (!arrow) return;

            function updateArrowVisibility() {
                const scrollPos = window.scrollY + window.innerHeight;
                const pageHeight = document.documentElement.scrollHeight;
                const nearBottom = pageHeight - scrollPos < 150;
                arrow.style.opacity = nearBottom ? '0' : '1';
                arrow.style.pointerEvents = nearBottom ? 'none' : 'auto';
            }

            arrow.addEventListener('click', function () {
                const target = document.getElementById('addCompanySubmitBtn');
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' });
                }
            });

            window.addEventListener('scroll', updateArrowVisibility);
            window.addEventListener('resize', updateArrowVisibility);
            updateArrowVisibility();
        })();

        </script>
        <?php include '../../includes/footer.php'; ?>