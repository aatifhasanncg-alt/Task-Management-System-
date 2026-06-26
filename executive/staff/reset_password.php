<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';

$db = getDB();
$currentUser = currentUser();
$staffId = (int) ($_GET['id'] ?? 0);
if (!$staffId) {
    header('Location: index.php');
    exit;
}

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
    header('Location: index.php');
    exit;
}

$pageTitle = 'Reset Password';
$errors = [];
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Generate a secure random token
    $token = bin2hex(random_bytes(32));          // 64-char hex string
    $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    // Invalidate any existing tokens for this user
    $db->prepare("
        UPDATE password_reset_tokens
        SET used = 1
        WHERE user_id = ? AND used = 0
    ")->execute([$staffId]);

    // Store new token
    $db->prepare("
        INSERT INTO password_reset_tokens (user_id, token, expires_at, created_by, ip_address)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
                $staffId,
                hash('sha256', $token),   // store the hash, send the raw token
                $expiresAt,
                $currentUser['id'],
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
    // Flag user must change password before accessing system
    $db->prepare("UPDATE users SET must_change_password = 1 WHERE id = ?")
        ->execute([$staffId]);

    // Build reset link
    $resetLink = APP_URL . '/auth/force_password_change.php?token=' . urlencode($token);

    // Log the action
    $db->prepare("
        INSERT INTO password_change_logs (changed_by, changed_for, ip_address)
        VALUES (?,?,?)
    ")->execute([
                $currentUser['id'],
                $staffId,
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

    // Send email
    try {
        require_once '../../config/mailer.php';
        $html = emailWrapper("
            <h2 style='color:#0a0f1e;'>Password Reset Request</h2>
            <p>Dear <strong>{$staffUser['full_name']}</strong>,</p>
            <p>An administrator has requested a password reset for your TaskHub account.
               Click the button below to set a new password.</p>
            <div style='background:#f9fafb;border-radius:10px;padding:16px;margin:20px 0;text-align:center;'>
                <p style='margin:0 0 4px;font-size:.8rem;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;'>Username</p>
                <p style='margin:0;font-weight:700;font-size:1rem;color:#0a0f1e;'>{$staffUser['username']}</p>
            </div>
            <div style='text-align:center;margin:24px 0;'>
                <a href='{$resetLink}'
                   style='display:inline-block;background:#c9a84c;color:#0a0f1e;padding:13px 32px;
                          border-radius:8px;text-decoration:none;font-weight:700;font-size:1rem;'>
                    Set New Password
                </a>
            </div>
            <p style='color:#6b7280;font-size:.82rem;'>
                This link will expire in <strong>15 minutes</strong>. If you did not request this,
                contact your administrator immediately.
            </p>
            <p style='color:#6b7280;font-size:.78rem;word-break:break-all;'>
                Or copy this link: {$resetLink}
            </p>
        ");
        sendMail(
            $staffUser['email'],
            $staffUser['full_name'],
            '[TaskHub] Reset Your Password',
            $html
        );
        $sent = true;
    } catch (Exception $e) {
        $errors[] = 'Email could not be sent: ' . $e->getMessage();
    }

    if ($sent) {
        logActivity("Password reset link sent for user #{$staffId}", 'users');
        setFlash('success', "Password reset link sent to {$staffUser['email']}. Link expires in 15 minutes.");
        header("Location: view.php?id={$staffId}");
        exit;
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
                                <div class="avatar-circle"
                                    style="width:48px;height:48px;font-size:.95rem;flex-shrink:0;">
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
                            <h5><i class="fas fa-envelope text-warning me-2"></i>Send Password Reset Link</h5>
                        </div>
                        <div class="card-mis-body">

                            <!-- Info banner -->
                            <div style="background:#1e3a5f;border:1px solid #2d5a8e;border-radius:10px;
                            padding:14px 16px;margin-bottom:1.25rem;display:flex;gap:12px;align-items:flex-start;">
                                <i class="fas fa-shield-alt" style="color:#60a5fa;margin-top:2px;flex-shrink:0;"></i>
                                <div style="font-size:.82rem;color:#93c5fd;line-height:1.5;">
                                    A <strong style="color:#bfdbfe;">secure one-time link</strong> will be emailed to
                                    <strong
                                        style="color:#bfdbfe;"><?= htmlspecialchars($staffUser['email']) ?></strong>.
                                    The link expires in <strong style="color:#bfdbfe;">15 minutes</strong> and can only be
                                    used once.
                                    The user sets their own password — no plaintext passwords are sent.
                                </div>
                            </div>

                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                                <div
                                    style="background:#f9fafb;border-radius:10px;padding:14px 16px;margin-bottom:1.25rem;">
                                    <table style="width:100%;font-size:.85rem;border-collapse:collapse;">
                                        <tr>
                                            <td style="padding:5px 0;color:#6b7280;width:110px;">Recipient</td>
                                            <td style="padding:5px 0;font-weight:600;">
                                                <?= htmlspecialchars($staffUser['full_name']) ?></td>
                                        </tr>
                                        <tr>
                                            <td style="padding:5px 0;color:#6b7280;">Email</td>
                                            <td style="padding:5px 0;"><?= htmlspecialchars($staffUser['email']) ?></td>
                                        </tr>
                                        <tr>
                                            <td style="padding:5px 0;color:#6b7280;">Expires</td>
                                            <td style="padding:5px 0;">15 minutes from now</td>
                                        </tr>
                                    </table>
                                </div>

                                <button type="submit" class="btn btn-warning w-100" style="font-weight:600;">
                                    <i class="fas fa-paper-plane me-2"></i>Send Reset Link
                                </button>
                            </form>
                        </div>
                    </div>

                </div>
            </div>

        </div>
        <?php include '../../includes/footer.php'; ?>