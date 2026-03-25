<?php
// auth/setup_2fa.php — First-time Google Authenticator setup
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';
requireAnyRole();

$db   = getDB();
$user = currentUser();
$uid  = $user['id'];

// Load user record
$uSt = $db->prepare("SELECT * FROM users WHERE id=?");
$uSt->execute([$uid]);
$dbUser = $uSt->fetch();

// If already enabled, show management page instead
$alreadyEnabled = (bool)($dbUser['ga_enabled'] ?? false);

require_once '../vendor/GoogleAuthenticator.php';
$ga = new PHPGangsta_GoogleAuthenticator();

$errors  = [];
$success = false;
$secret  = $_SESSION['2fa_setup_secret'] ?? null;

// Generate a new secret if not in session
if (!$secret) {
    $secret = $ga->createSecret();
    $_SESSION['2fa_setup_secret'] = $secret;
}

$issuer   = urlencode(ORG_NAME);
$account  = urlencode($dbUser['email']);
$otpAuthUrl = "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}";

// QR code via Google Charts API (no library needed)
$qrUrl = 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . urlencode($otpAuthUrl);

// ── HANDLE: Verify & Enable ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_and_enable'])) {
    verifyCsrf();
    $code = preg_replace('/\D/', '', $_POST['totp_code'] ?? '');

    if (!$code || strlen($code) !== 6) {
        $errors[] = 'Please enter a valid 6-digit code.';
    } elseif (!$ga->verifyCode($secret, $code, 1)) {
        $errors[] = 'Code is incorrect. Make sure your phone time is synced and try again.';
    } else {
        // Save secret and enable
        $db->prepare("UPDATE users SET ga_secret=?, ga_enabled=1 WHERE id=?")
           ->execute([$secret, $uid]);
        unset($_SESSION['2fa_setup_secret']);
        logActivity('2FA enabled', 'auth');
        $success = true;
        $alreadyEnabled = true;
    }
}

// ── HANDLE: Disable 2FA ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_2fa'])) {
    verifyCsrf();
    $password = $_POST['confirm_password'] ?? '';
    if (!password_verify($password, $dbUser['password'])) {
        $errors[] = 'Incorrect password. 2FA was not disabled.';
    } else {
        $db->prepare("UPDATE users SET ga_secret=NULL, ga_enabled=0 WHERE id=?")
           ->execute([$uid]);
        logActivity('2FA disabled', 'auth');
        setFlash('success', '2FA has been disabled. You can re-enable it at any time.');
        header('Location: setup_2fa.php'); exit;
    }
}

$pageTitle = 'Two-Factor Authentication Setup';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> | MISPro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root { --navy:#0a0f1e; --navy-l:#1a2540; --gold:#c9a84c; --gold-l:#e8c96a; }
        body { min-height:100vh; background:#f0f4f8; font-family:'Outfit',sans-serif; display:flex; align-items:center; justify-content:center; padding:2rem 1rem; }
        .setup-card { background:white; border-radius:20px; width:min(520px,100%); box-shadow:0 4px 24px rgba(0,0,0,.1); overflow:hidden; }
        .setup-header { background:linear-gradient(135deg,var(--navy),var(--navy-l)); padding:2rem; color:white; text-align:center; }
        .setup-header h3 { font-family:'Cormorant Garamond',serif; font-size:1.7rem; margin-bottom:.25rem; }
        .setup-header p  { color:#8899aa; font-size:.88rem; margin:0; }
        .shield-icon { width:56px; height:56px; border-radius:14px; background:rgba(201,168,76,.2); border:1px solid rgba(201,168,76,.3); display:flex; align-items:center; justify-content:center; margin:0 auto 1rem; }
        .setup-body { padding:2rem; }
        .step-block { display:flex; gap:1rem; margin-bottom:1.5rem; }
        .step-num { width:28px; height:28px; border-radius:50%; background:linear-gradient(135deg,var(--gold),var(--gold-l)); color:var(--navy); font-weight:700; font-size:.82rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .step-content h6 { font-size:.9rem; font-weight:600; margin-bottom:.2rem; }
        .step-content p  { font-size:.82rem; color:#6b7280; margin:0; }
        .qr-box { text-align:center; padding:1.25rem; background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; margin:1rem 0; }
        .qr-box img { border-radius:8px; }
        .secret-key { font-family:monospace; font-size:1.1rem; font-weight:700; letter-spacing:.2em; color:var(--navy); background:#f0f4f8; padding:.5rem 1rem; border-radius:8px; display:inline-block; margin-top:.5rem; cursor:pointer; border:1px solid #e5e7eb; }
        .otp-input { text-align:center; font-size:1.8rem; font-weight:700; letter-spacing:.4em; border:2px solid #e5e7eb; border-radius:10px; padding:.5rem; width:100%; font-family:'Outfit',sans-serif; }
        .otp-input:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(201,168,76,.12); }
        .btn-enable { width:100%; padding:.7rem; border:none; border-radius:10px; background:linear-gradient(135deg,var(--gold),var(--gold-l)); color:var(--navy); font-weight:700; font-size:.95rem; cursor:pointer; transition:all .2s; }
        .btn-enable:hover { opacity:.9; }
        .success-banner { background:#ecfdf5; border:1px solid #a7f3d0; border-radius:12px; padding:1.25rem; text-align:center; }
        .enabled-badge { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; border-radius:8px; padding:.5rem 1.25rem; font-size:.88rem; font-weight:600; display:inline-flex; align-items:center; gap:.5rem; }
        .app-links { display:flex; gap:.75rem; justify-content:center; flex-wrap:wrap; margin:.75rem 0; }
        .app-link { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:.5rem 1rem; font-size:.8rem; color:#374151; text-decoration:none; display:flex; align-items:center; gap:.4rem; }
        .app-link:hover { background:#f3f4f6; color:#1f2937; }
    </style>
</head>
<body>

<div class="setup-card">
    <!-- Header -->
    <div class="setup-header">
        <div class="shield-icon">
            <i class="fas fa-shield-alt fa-xl" style="color:var(--gold);"></i>
        </div>
        <h3>Two-Factor Authentication</h3>
        <p>Secure your MISPro account with Google Authenticator</p>
    </div>

    <div class="setup-body">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger py-2 mb-3">
            <?php foreach ($errors as $e): ?>
            <div style="font-size:.85rem;"><i class="fas fa-exclamation-circle me-1"></i><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="success-banner mb-3">
            <i class="fas fa-check-circle fa-2x text-success d-block mb-2"></i>
            <strong style="color:#065f46;">2FA Enabled Successfully!</strong>
            <p style="font-size:.83rem;color:#059669;margin:.5rem 0 0;">
                Your account is now protected. You'll be asked for your code on every login.
            </p>
        </div>
        <?php endif; ?>

        <?php if ($alreadyEnabled && !$success): ?>
        <!-- Already enabled state -->
        <div class="text-center mb-4">
            <div class="enabled-badge"><i class="fas fa-shield-check"></i> 2FA is Active</div>
            <p class="mt-2 mb-0" style="font-size:.83rem;color:#6b7280;">
                Google Authenticator is protecting your account.
            </p>
        </div>

        <!-- Disable section -->
        <div class="p-3 rounded-3" style="background:#fef2f2;border:1px solid #fecaca;">
            <h6 style="font-size:.88rem;font-weight:600;color:#dc2626;">
                <i class="fas fa-exclamation-triangle me-1"></i>Disable 2FA
            </h6>
            <p style="font-size:.8rem;color:#b91c1c;margin-bottom:.75rem;">
                This will remove 2FA protection from your account. Not recommended.
            </p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="input-group">
                    <input type="password" name="confirm_password" class="form-control form-control-sm"
                           placeholder="Confirm your password" required>
                    <button type="submit" name="disable_2fa"
                            class="btn btn-danger btn-sm"
                            onclick="return confirm('Are you sure you want to disable 2FA?')">
                        Disable
                    </button>
                </div>
            </form>
        </div>

        <?php else: ?>
        <!-- Setup flow -->

        <!-- Step 1: Install app -->
        <div class="step-block">
            <div class="step-num">1</div>
            <div class="step-content">
                <h6>Install Google Authenticator</h6>
                <p>Download the app on your phone if you don't have it already.</p>
                <div class="app-links">
                    <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2"
                       class="app-link" target="_blank">
                        <i class="fab fa-google-play text-success"></i>Android
                    </a>
                    <a href="https://apps.apple.com/app/google-authenticator/id388497605"
                       class="app-link" target="_blank">
                        <i class="fab fa-apple"></i>iOS
                    </a>
                </div>
            </div>
        </div>

        <!-- Step 2: Scan QR -->
        <div class="step-block">
            <div class="step-num">2</div>
            <div class="step-content">
                <h6>Scan QR Code</h6>
                <p>Open the app, tap <strong>+</strong>, then <strong>Scan a QR code</strong>.</p>
            </div>
        </div>

        <div class="qr-box">
            <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Code" width="180" height="180">
            <div style="margin-top:.75rem;font-size:.78rem;color:#9ca3af;">
                Can't scan? Enter this key manually:
            </div>
            <div class="secret-key" onclick="copySecret(this)" title="Click to copy">
                <?= wordwrap($secret, 4, ' ', true) ?>
            </div>
            <div id="copyMsg" style="font-size:.73rem;color:#10b981;margin-top:.3rem;display:none;">
                ✓ Copied to clipboard
            </div>
        </div>

        <!-- Step 3: Verify -->
        <div class="step-block mt-3">
            <div class="step-num">3</div>
            <div class="step-content">
                <h6>Enter Verification Code</h6>
                <p>Type the 6-digit code shown in Google Authenticator.</p>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="mb-3">
                <input type="text" name="totp_code" class="otp-input"
                       placeholder="000000" maxlength="6"
                       inputmode="numeric" pattern="[0-9]{6}"
                       autocomplete="one-time-code" autofocus required>
            </div>
            <button type="submit" name="verify_and_enable" class="btn-enable">
                <i class="fas fa-shield-alt me-2"></i>Verify & Enable 2FA
            </button>
        </form>

        <?php endif; ?>

        <div class="text-center mt-3">
            <a href="<?= APP_URL ?>/<?= $user['role'] ?>/dashboard/"
               style="font-size:.8rem;color:#9ca3af;">
                <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
            </a>
        </div>
    </div>
</div>

<script>
function copySecret(el) {
    const text = el.textContent.replace(/\s/g,'');
    navigator.clipboard.writeText(text).then(() => {
        const msg = document.getElementById('copyMsg');
        msg.style.display = 'block';
        setTimeout(() => msg.style.display = 'none', 2000);
    });
}
// Auto-submit on 6 digits
document.querySelector('.otp-input')?.addEventListener('input', function() {
    if (this.value.replace(/\D/g,'').length === 6) {
        this.value = this.value.replace(/\D/g,'');
        this.closest('form').submit();
    }
});
</script>
</body>
</html>