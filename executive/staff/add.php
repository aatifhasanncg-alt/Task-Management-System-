<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../vendor/GoogleAuthenticator.php';

requireExecutive();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied. Only Core Admin executives can add staff.');
    header('Location: index.php'); exit;
}

$db          = getDB();
$currentUser = currentUser(); // ← renamed to avoid conflict
$pageTitle   = 'Add Staff';
$errors      = [];

$ga = new PHPGangsta_GoogleAuthenticator();

// ── Generate secret ONCE and persist via hidden field ──
// On GET: generate fresh secret
// On POST with errors: reuse secret from hidden field
$secret = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $secret = trim($_POST['ga_secret_hidden'] ?? '');
    if (!$secret) $secret = $ga->createSecret();
} else {
    $secret = $ga->createSecret();
}

// Build QR URL using posted email (updates live via JS) or placeholder
$qrEmail  = trim($_POST['email'] ?? 'staff@askglobal.com.np');
$qrUrl    = $ga->getQRCodeGoogleUrl($qrEmail, $secret, 'ASK MIS');

// Lookups
$allBranches = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
$allDepts    = $db->query("SELECT id, dept_name, dept_code FROM departments WHERE is_active=1 ORDER BY dept_name")->fetchAll();
$allRoles    = $db->query("SELECT id, role_name FROM roles ORDER BY id")->fetchAll();
$allAdmins   = $db->query("
    SELECT u.id, u.full_name, b.branch_name FROM users u
    LEFT JOIN roles r    ON r.id = u.role_id
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE r.role_name = 'admin' AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $fullName    = trim($_POST['full_name']      ?? '');
    $username    = trim($_POST['username']       ?? '');
    $email       = trim($_POST['email']          ?? '');
    $phone       = trim($_POST['phone']          ?? '');
    $emergencyContact = trim($_POST['emergency_contact'] ?? '');
    $roleId      = (int)($_POST['role_id']       ?? 0);
    $branchId    = (int)($_POST['branch_id']     ?? 0);
    $deptId      = (int)($_POST['department_id'] ?? 0);
    $managedBy   = (int)($_POST['managed_by']    ?? 0) ?: null;
    $joiningDate = $_POST['joining_date']        ?? null;
    $address     = trim($_POST['address']        ?? '');
    $password    = $_POST['password']            ?? '';
    $confirmPass = $_POST['confirm_password']    ?? '';

    if (!$fullName)                      $errors[] = 'Full name is required.';
    if (!$username)                      $errors[] = 'Username is required.';
    if (!$email)                         $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';
    if (!$roleId)                        $errors[] = 'Role is required.';
    if (!$branchId)                      $errors[] = 'Branch is required.';
    if (!$deptId)                        $errors[] = 'Department is required.';
    if (!$password)                      $errors[] = 'Password is required.';
    if (strlen($password) < 6)           $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirmPass)      $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $uCheck = $db->prepare("SELECT id FROM users WHERE username = ?");
        $uCheck->execute([$username]);
        if ($uCheck->fetch()) $errors[] = 'Username already taken.';
    }
    if (!$errors) {
        $eCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
        $eCheck->execute([$email]);
        if ($eCheck->fetch()) $errors[] = 'Email already registered.';
    }

    if (!$errors) {
        $hashedPwd = password_hash($password, PASSWORD_DEFAULT);

        $db->prepare("
            INSERT INTO users
            (full_name, username, email, phone,
            password, role_id, branch_id, department_id,
            managed_by, joining_date, address,
            emergency_contact,
            ga_secret, ga_enabled, is_active, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,1,NOW())
        ")->execute([
            $fullName, $username, $email, $phone ?: null,
            $hashedPwd, $roleId, $branchId, $deptId,
            $managedBy, $joiningDate ?: null, $address ?: null,
            $emergencyContact ?: null,
            $secret,
        ]);

        $newId = $db->lastInsertId();

        // Send welcome email with credentials + secret
        try {
            require_once '../../config/mailer.php';
            $emailHtml = emailWrapper("
                <h2 style='color:#0a0f1e;'>Welcome to MISPro</h2>
                <p>Dear <strong>{$fullName}</strong>,</p>
                <p>Your account has been created. Here are your login credentials:</p>
                <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
                    <tr>
                        <td style='padding:8px;background:#f9fafb;font-weight:600;width:160px;'>Username</td>
                        <td style='padding:8px;border-bottom:1px solid #e5e7eb;'>{$username}</td>
                    </tr>
                    <tr>
                        <td style='padding:8px;background:#f9fafb;font-weight:600;'>Password</td>
                        <td style='padding:8px;border-bottom:1px solid #e5e7eb;'>{$password}</td>
                    </tr>
                    <tr>
                        <td style='padding:8px;background:#f9fafb;font-weight:600;'>2FA Secret</td>
                        <td style='padding:8px;font-family:monospace;font-size:1rem;font-weight:700;'>{$secret}</td>
                    </tr>
                </table>
                <p style='font-size:.85rem;color:#6b7280;'>
                    Please install <strong>Google Authenticator</strong> on your phone and add this account using the secret key above before your first login.
                </p>
                <a href='" . APP_URL . "/auth/login.php'
                   style='display:inline-block;background:#c9a84c;color:#0a0f1e;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:12px;'>
                    Login to MISPro
                </a>
            ");
            sendMail($email, $fullName, '[MISPro] Your Account Has Been Created', $emailHtml);
        } catch (Exception $e) {}

        logActivity("Staff added: {$fullName} ({$employeeId})", 'users');
        setFlash('success', "Staff member \"{$fullName}\" added. Credentials sent to {$email}.");
        header("Location: view.php?id={$newId}"); exit; // ← redirect to view so QR is visible
    }
}

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
    <h5 style="margin:0;">Add New Staff Member</h5>
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

<div class="row g-4">
    <div class="col-lg-8">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <!-- Add inside the form, right after csrf_token hidden input -->
<input type="hidden" name="ga_secret_hidden" value="<?= htmlspecialchars($secret) ?>">
            <!-- Personal Info -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-user text-warning me-2"></i>Personal Information</h5>
                </div>
                <div class="card-mis-body">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label-mis">Full Name <span class="required-star">*</span></label>
                            <input type="text" name="full_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">Email <span class="required-star">*</span></label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">Phone</label>
                            <input type="text" name="phone" class="form-control"
                                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-mis">Emergency Contact</label>
                            <input type="text" name="emergency_contact" class="form-control"
                                value="<?= htmlspecialchars($_POST['emergency_contact'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-mis">Joining Date</label>
                            <input type="date" name="joining_date" class="form-control"
                                   value="<?= htmlspecialchars($_POST['joining_date'] ?? date('Y-m-d')) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">Address</label>
                            <input type="text" name="address" class="form-control"
                                   value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                        </div>

                    </div>
                </div>
            </div>

            <!-- Account & Role -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-user-shield text-warning me-2"></i>Account & Role</h5>
                </div>
                <div class="card-mis-body">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label-mis">Username <span class="required-star">*</span></label>
                            <input type="text" name="username" class="form-control"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                   required autocomplete="off">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">Role <span class="required-star">*</span></label>
                            <select name="role_id" class="form-select" required>
                                <option value="">-- Select Role --</option>
                                <?php foreach ($allRoles as $r): ?>
                                    <option value="<?= $r['id'] ?>"
                                        <?= ($_POST['role_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>
                                        <?= ucfirst(htmlspecialchars($r['role_name'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                       <div class="col-md-6">
                        <label class="form-label-mis">Password <span class="required-star">*</span></label>
                        <div class="input-group">
                            <input type="password" name="password" id="password" class="form-control"
                                required autocomplete="new-password" minlength="6">
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label-mis">Confirm Password <span class="required-star">*</span></label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control"
                                required autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('confirm_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                        <!-- Replace the existing ga_secret field with this: -->
                    <div class="col-12">
                        <div class="card-mis" style="border-left:3px solid #10b981;margin-top:.5rem;">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-shield-alt" style="color:#10b981;"></i><span class="ms-2">Google 2FA Setup</span></h5>
                            </div>
                            <div class="card-mis-body">
                                <div class="row g-3 align-items-center">
                                    <div class="col-md-4 text-center">
                                        <div style="background:#fff;padding:10px;border-radius:10px;border:2px solid #e5e7eb;display:inline-block;">
                                            <img id="qrPreview"
                                                src="<?= htmlspecialchars($qrUrl) ?>"
                                                style="width:150px;height:150px;display:block;">
                                        </div>
                                        <div style="font-size:.7rem;color:#9ca3af;margin-top:.4rem;">
                                            QR updates when email changes
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label-mis">2FA Secret Key</label>
                                        <div class="input-group mb-2">
                                            <input type="text" id="gaSecretDisplay"
                                                class="form-control"
                                                value="<?= htmlspecialchars($secret) ?>"
                                                readonly
                                                style="font-family:monospace;font-size:.88rem;letter-spacing:.08em;background:#f9fafb;">
                                            <button type="button" class="btn btn-outline-secondary" onclick="copyGaSecret()">
                                                <i class="fas fa-copy" id="copyGaIcon"></i>
                                            </button>
                                        </div>
                                        <div style="font-size:.75rem;color:#6b7280;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:.6rem .75rem;">
                                            <i class="fas fa-info-circle me-1" style="color:#10b981;"></i>
                                            This secret will be emailed to the staff member.
                                            They must add it to Google Authenticator before their first login.
                                            2FA is <strong>disabled</strong> until they complete setup.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                </div>
            </div>

            <!-- Assignment -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-sitemap text-warning me-2"></i>Branch & Department</h5>
                </div>
                <div class="card-mis-body">
                    <div class="row g-3">

                        <div class="col-md-6">
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

                        <div class="col-md-6">
                            <label class="form-label-mis">Department <span class="required-star">*</span></label>
                            <select name="department_id" class="form-select" required>
                                <option value="">-- Select Department --</option>
                                <?php foreach ($allDepts as $d): ?>
                                    <option value="<?= $d['id'] ?>"
                                        <?= ($_POST['department_id'] ?? '') == $d['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($d['dept_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-mis">Managed By</label>
                            <select name="managed_by" class="form-select">
                                <option value="">-- Select Manager --</option>
                                <?php foreach ($allAdmins as $a): ?>
                                    <option value="<?= $a['id'] ?>"
                                        <?= ($_POST['managed_by'] ?? '') == $a['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($a['full_name']) ?>
                                        (<?= htmlspecialchars($a['branch_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-gold">
                <i class="fas fa-save me-1"></i>Add Staff Member
            </button>
        </form>
    </div>

    <!-- Right info -->
    <div class="col-lg-4">
        <div class="card-mis p-3" style="border-left:3px solid #c9a84c;">
            <div style="font-size:.82rem;color:#6b7280;line-height:1.7;">
                <div class="mb-2">
                    <i class="fas fa-info-circle text-warning me-2"></i>
                    <strong>Only Core Admin executives can add staff.</strong>
                </div>
                <div class="mb-2">Employee ID is auto-generated if left blank.</div>
                <div class="mb-2">A welcome email with login credentials will be sent automatically.</div>
                <div>Password must be at least 6 characters.</div>
            </div>
        </div>
    </div>
</div>

</div>
<script>
function togglePassword(fieldId, btn){

    let field = document.getElementById(fieldId);
    let icon = btn.querySelector("i");

    if(field.type === "password"){
        field.type = "text";
        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");
    }else{
        field.type = "password";
        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");
    }

}
function copyGaSecret() {
    const text = document.getElementById('gaSecretDisplay').value;
    navigator.clipboard.writeText(text).then(() => {
        const icon = document.getElementById('copyGaIcon');
        icon.className = 'fas fa-check';
        icon.style.color = '#10b981';
        setTimeout(() => { icon.className = 'fas fa-copy'; icon.style.color = ''; }, 2000);
    });
}

function togglePassword(fieldId, btn) {
    const field = document.getElementById(fieldId);
    const icon  = btn.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// Update QR code when email field changes
document.querySelector('[name="email"]')?.addEventListener('blur', function() {
    const email  = this.value.trim();
    const secret = document.getElementById('gaSecretDisplay').value;
    if (!email) return;
    const otpUrl = encodeURIComponent(
        'otpauth://totp/ASK%20MIS:' + encodeURIComponent(email) +
        '?secret=' + secret + '&issuer=ASK%20MIS'
    );
    document.querySelector('[name="email"]')?.addEventListener('blur', function() {
    const email  = this.value.trim();
    const secret = document.getElementById('gaSecretDisplay').value;
    if (!email) return;

    const otpUrl = 'otpauth://totp/ASK MIS:' + encodeURIComponent(email) +
                   '?secret=' + secret + '&issuer=ASK MIS';

    document.getElementById('qrPreview').src =
        'https://chart.googleapis.com/chart?chs=160x160&chld=M|0&cht=qr&chl=' + encodeURIComponent(otpUrl);
});
});
</script>
<?php include '../../includes/footer.php'; ?>