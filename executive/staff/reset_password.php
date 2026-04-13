<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied.');
    header('Location: index.php'); exit;
}

$db          = getDB();
$currentUser = currentUser();
$staffId     = (int)($_GET['id'] ?? 0);
if (!$staffId) { header('Location: index.php'); exit; }

// Fetch staff
$staffStmt = $db->prepare("
    SELECT u.*, r.role_name FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE u.id = ? AND u.is_active = 1
");
$staffStmt->execute([$staffId]);
$staffUser = $staffStmt->fetch();
if (!$staffUser) {
    setFlash('error', 'Staff not found.');
    header('Location: index.php'); exit;
}

$pageTitle = 'Reset Password';
$errors    = [];
$success   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $newPassword = $_POST['new_password']     ?? '';
    $confirmPwd  = $_POST['confirm_password'] ?? '';
    $sendEmail   = isset($_POST['send_email']);

    if (!$newPassword)               $errors[] = 'New password is required.';
    if (strlen($newPassword) < 6)    $errors[] = 'Password must be at least 6 characters.';
    if ($newPassword !== $confirmPwd) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

        $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$hashed, $staffId]);

        // Log password change
        $db->prepare("
            INSERT INTO password_change_logs (changed_by, changed_for, ip_address)
            VALUES (?,?,?)
        ")->execute([
            $currentUser['id'],
            $staffId,
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        // Send email if checked
        if ($sendEmail) {
            try {
                require_once '../../config/mailer.php';
                $html = emailWrapper("
                    <h2 style='color:#0a0f1e;'>Password Reset</h2>
                    <p>Dear <strong>{$staffUser['full_name']}</strong>,</p>
                    <p>Your MISPro password has been reset by an administrator.</p>
                    <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
                        <tr>
                            <td style='padding:8px;background:#f9fafb;font-weight:600;width:140px;'>Username</td>
                            <td style='padding:8px;border-bottom:1px solid #e5e7eb;'>{$staffUser['username']}</td>
                        </tr>
                        <tr>
                            <td style='padding:8px;background:#f9fafb;font-weight:600;'>New Password</td>
                            <td style='padding:8px;font-family:monospace;font-size:1rem;font-weight:700;'>{$newPassword}</td>
                        </tr>
                    </table>
                    <p style='color:#ef4444;font-size:.85rem;'>Please log in and change your password immediately.</p>
                    <a href='" . APP_URL . "/auth/login.php'
                       style='display:inline-block;background:#c9a84c;color:#0a0f1e;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:12px;'>
                        Login Now
                    </a>
                ");
                sendMail($staffUser['email'], $staffUser['full_name'],
                         '[MISPro] Your Password Has Been Reset', $html);
            } catch (Exception $e) {}
        }

        logActivity("Password reset for user #{$staffId}", 'users');
        setFlash('success', "Password reset successfully for {$staffUser['full_name']}.");
        header("Location: view.php?id={$staffId}"); exit;
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
    <a href="view.php?id=<?= $staffId ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Back
    </a>
    <h5 style="margin:0;">Reset Password</h5>
</div>

<div class="row g-4 justify-content-center">
    <div class="col-lg-6">

        <!-- Staff Info -->
        <div class="card-mis mb-4" style="border-left:3px solid #f59e0b;">
            <div class="card-mis-body">
                <div class="d-flex align-items-center gap-3">
                    <div class="avatar-circle" style="width:48px;height:48px;font-size:.95rem;flex-shrink:0;">
                        <?= strtoupper(substr($staffUser['full_name'], 0, 2)) ?>
                    </div>
                    <div>
                        <div style="font-weight:600;"><?= htmlspecialchars($staffUser['full_name']) ?></div>
                        <div style="font-size:.78rem;color:#9ca3af;">
                            <?= htmlspecialchars($staffUser['username']) ?>
                            · <?= htmlspecialchars($staffUser['email']) ?>
                        </div>
                    </div>
                </div>
            </div>
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

        <div class="card-mis">
            <div class="card-mis-header">
                <h5><i class="fas fa-key text-warning me-2"></i>Set New Password</h5>
            </div>
            <div class="card-mis-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <div class="row g-3">

                        <div class="col-12">
                            <label class="form-label-mis">New Password <span class="required-star">*</span></label>
                            <div class="input-group">
                                <input type="password" name="new_password" id="newPwd"
                                       class="form-control" required minlength="6"
                                       placeholder="Minimum 6 characters">
                                <button type="button" class="btn btn-outline-secondary"
                                        onclick="togglePwd('newPwd', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label-mis">Confirm Password <span class="required-star">*</span></label>
                            <div class="input-group">
                                <input type="password" name="confirm_password" id="confirmPwd"
                                       class="form-control" required
                                       placeholder="Re-enter password">
                                <button type="button" class="btn btn-outline-secondary"
                                        onclick="togglePwd('confirmPwd', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div id="pwdMatchMsg" style="font-size:.75rem;margin-top:.3rem;"></div>
                        </div>

                        <!-- Password strength -->
                        <div class="col-12">
                            <div style="font-size:.75rem;color:#9ca3af;margin-bottom:.3rem;">Password Strength</div>
                            <div id="strengthBar" style="height:4px;border-radius:99px;background:#f3f4f6;overflow:hidden;">
                                <div id="strengthFill" style="height:100%;width:0;border-radius:99px;transition:width .3s,background .3s;"></div>
                            </div>
                            <div id="strengthText" style="font-size:.72rem;margin-top:.2rem;"></div>
                        </div>

                        <!-- Quick generate -->
                        <div class="col-12">
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    onclick="generatePassword()">
                                <i class="fas fa-dice me-1"></i>Generate Strong Password
                            </button>
                        </div>

                        <!-- Send email -->
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="send_email" id="sendEmail" checked>
                                <label class="form-check-label" for="sendEmail" style="font-size:.85rem;">
                                    Send new password to <strong><?= htmlspecialchars($staffUser['email']) ?></strong>
                                </label>
                            </div>
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="fas fa-key me-1"></i>Reset Password
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

</div>
<?php include '../../includes/footer.php'; ?>

<script>
function togglePwd(id, btn) {
    const f = document.getElementById(id);
    const i = btn.querySelector('i');
    if (f.type === 'password') { f.type = 'text'; i.classList.replace('fa-eye','fa-eye-slash'); }
    else { f.type = 'password'; i.classList.replace('fa-eye-slash','fa-eye'); }
}

function generatePassword() {
    const chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#$!';
    let pwd = '';
    for (let i = 0; i < 12; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length));
    document.getElementById('newPwd').type    = 'text';
    document.getElementById('confirmPwd').type = 'text';
    document.getElementById('newPwd').value    = pwd;
    document.getElementById('confirmPwd').value = pwd;
    checkStrength(pwd);
    checkMatch();
}

function checkStrength(pwd) {
    let score = 0;
    if (pwd.length >= 8)  score++;
    if (pwd.length >= 12) score++;
    if (/[A-Z]/.test(pwd)) score++;
    if (/[0-9]/.test(pwd)) score++;
    if (/[^A-Za-z0-9]/.test(pwd)) score++;
    const levels = [
        {w:'20%',  bg:'#ef4444', txt:'Very Weak'},
        {w:'40%',  bg:'#f97316', txt:'Weak'},
        {w:'60%',  bg:'#f59e0b', txt:'Fair'},
        {w:'80%',  bg:'#84cc16', txt:'Strong'},
        {w:'100%', bg:'#10b981', txt:'Very Strong'},
    ];
    const l = levels[Math.min(score, 4)];
    document.getElementById('strengthFill').style.width      = l.w;
    document.getElementById('strengthFill').style.background = l.bg;
    document.getElementById('strengthText').textContent      = l.txt;
    document.getElementById('strengthText').style.color      = l.bg;
}

function checkMatch() {
    const p1  = document.getElementById('newPwd').value;
    const p2  = document.getElementById('confirmPwd').value;
    const msg = document.getElementById('pwdMatchMsg');
    if (!p2) { msg.textContent = ''; return; }
    if (p1 === p2) {
        msg.textContent = '✓ Passwords match';
        msg.style.color = '#10b981';
    } else {
        msg.textContent = '✗ Passwords do not match';
        msg.style.color = '#ef4444';
    }
}

document.getElementById('newPwd').addEventListener('input', function() {
    checkStrength(this.value); checkMatch();
});
document.getElementById('confirmPwd').addEventListener('input', checkMatch);
</script>