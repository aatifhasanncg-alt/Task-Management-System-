<?php
// auth/verify_2fa.php

require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';

if (empty($_SESSION['2fa_pending_user'])) {
    header('Location: login.php');
    exit;
}

$errors = [];
$userId = (int) $_SESSION['2fa_pending_user'];
$db = getDB();
$user = $db->query("
    SELECT u.*, r.role_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE u.id = {$userId}
")->fetch();
if (!$user) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $code = preg_replace('/\D/', '', $_POST['totp_code'] ?? '');

    require_once '../vendor/GoogleAuthenticator.php';
    $ga = new PHPGangsta_GoogleAuthenticator();

    if ($ga->verifyCode($user['ga_secret'], $code, 1)) {
        // Log OTP
        $db->prepare("INSERT INTO otp_logs (user_id, otp_code, type, ip_address) VALUES (?,?,?,?)")
            ->execute([$userId, $code, 'login', $_SERVER['REMOTE_ADDR']]);

        // Set session
        $bSt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
        $bSt->execute([$user['branch_id']]);
        $bName = $bSt->fetchColumn();

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role_name'] ?? 'staff';
        $_SESSION['branch_id'] = $user['branch_id'];
        $_SESSION['branch_name'] = $bName ?: '';
        $_SESSION['dept_id'] = $user['department_id'];
        $_SESSION['email'] = $user['email'];
        unset($_SESSION['2fa_pending_user'], $_SESSION['2fa_pending_role']);

        if ($user['role'] === 'admin') {
            $dSt = $db->prepare("SELECT department_id FROM admin_department_access WHERE admin_id=?");
            $dSt->execute([$user['id']]);
            $_SESSION['allowed_depts'] = array_column($dSt->fetchAll(), 'department_id');
            $bSt2 = $db->prepare("SELECT branch_id FROM admin_branch_access WHERE admin_id=?");
            $bSt2->execute([$user['id']]);
            $_SESSION['allowed_branches'] = array_column($bSt2->fetchAll(), 'branch_id');
        }

        $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
        logActivity('2FA Login', 'auth');
        $_SESSION['role'] = $user['role_name'];
        $role = strtolower($user['role_name']);
        header('Location: ../' . $role . '/dashboard/');
        exit;
    } else {
        $errors[] = 'Invalid authentication code. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication | MISPro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Outfit:wght@400;500;600&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --navy: #0a0f1e;
            --gold: #c9a84c;
            --gold-l: #e8c96a;
        }

        body {
            min-height: 100vh;
            background: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Outfit', sans-serif;
        }

        .card-2fa {
            background: white;
            border-radius: 20px;
            padding: 2.5rem;
            width: min(400px, 92vw);
            box-shadow: 0 32px 80px rgba(0, 0, 0, .5);
        }

        .icon-2fa {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--navy), #1a2540);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }

        h3 {
            font-family: 'Cormorant Garamond', serif;
            color: var(--navy);
            text-align: center;
        }

        .otp-input {
            text-align: center;
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: .4em;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: .6rem 1rem;
            width: 100%;
            font-family: 'Outfit', sans-serif;
        }

        .otp-input:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(201, 168, 76, .12);
        }

        .btn-verify {
            width: 100%;
            padding: .75rem;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--gold), var(--gold-l));
            color: var(--navy);
            font-weight: 700;
            font-size: .95rem;
            cursor: pointer;
            margin-top: 1rem;
        }

        .btn-verify:hover {
            opacity: .9;
        }
    </style>
</head>

<body>
    <div class="card-2fa">
        <div class="icon-2fa"><i class="fas fa-shield-alt fa-xl" style="color:var(--gold);"></i></div>
        <h3 class="mb-1">Two-Factor Auth</h3>
        <p class="text-center text-muted mb-3" style="font-size:.88rem;">
            Open Google Authenticator and enter the 6-digit code for<br>
            <strong><?= htmlspecialchars($user['full_name']) ?></strong>
        </p>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger py-2 small"><?= htmlspecialchars($errors[0]) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="text" name="totp_code" class="otp-input" placeholder="000000" maxlength="6" inputmode="numeric"
                pattern="[0-9]{6}" autocomplete="one-time-code" autofocus required>
            <button type="submit" class="btn-verify">
                <i class="fas fa-check me-2"></i>Verify
            </button>
        </form>
        <div class="text-center mt-3">
            <a href="login.php" style="font-size:.8rem;color:#9ca3af;">
                <i class="fas fa-arrow-left me-1"></i>Back to Login
            </a>
        </div>
    </div>
    <script>
        // Auto-submit when 6 digits entered
        document.querySelector('.otp-input').addEventListener('input', function () {
            if (this.value.length === 6) this.closest('form').submit();
        });
    </script>
</body>

</html>