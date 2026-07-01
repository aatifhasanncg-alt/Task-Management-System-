<?php
// auth/force_password_change.php
// Used for: (1) admin-sent reset links, (2) first-login forced password change
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';

$db = getDB();
$errors = [];
$userId = null;
$mode = 'token'; // 'token' | 'session'

// ── Resolve identity ──────────────────────────────────────────────────────────

$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');

if ($rawToken) {
    // Token-based flow (admin reset link)
    $tokenHash = hash('sha256', $rawToken);

    $tokenStmt = $db->prepare("
        SELECT prt.*, u.full_name, u.email, u.username
        FROM   password_reset_tokens prt
        JOIN   users u ON u.id = prt.user_id
        WHERE  prt.token = ?
          AND  prt.used  = 0
          AND  prt.expires_at > NOW()
    ");
    $tokenStmt->execute([$tokenHash]);
    $tokenRow = $tokenStmt->fetch();

    if (!$tokenRow) {
        $fatalError = 'This reset link is invalid or has expired. Please ask an administrator to send a new one.';
    } else {
        $userId = (int) $tokenRow['user_id'];
        $userInfo = $tokenRow;
        $mode = 'token';
    }

} elseif (!empty($_SESSION['force_pw_change_user'])) {
    // Session-based flow (first login)
    $userId = (int) $_SESSION['force_pw_change_user'];
    $mode = 'session';

    $userStmt = $db->prepare("SELECT id, full_name, email, username FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $userInfo = $userStmt->fetch();

    if (!$userInfo) {
        header('Location: login.php');
        exit;
    }
} else {
    header('Location: login.php');
    exit;
}

// ── Handle POST ───────────────────────────────────────────────────────────────

if (!isset($fatalError) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($newPass) < 8)
        $errors[] = 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $newPass))
        $errors[] = 'Include at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $newPass))
        $errors[] = 'Include at least one number.';
    if ($newPass !== $confirm)
        $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?")
            ->execute([$hash, $userId]);

        if ($mode === 'token') {
            // Mark token as used
            $db->prepare("UPDATE password_reset_tokens SET used = 1, used_at = NOW() WHERE token = ?")
                ->execute([$tokenHash]);

            logActivity('Password reset via secure link', 'auth', "user_id={$userId}");
        } else {
            logActivity('Forced password change on first login', 'auth', "user_id={$userId}");
            unset($_SESSION['force_pw_change_user'], $_SESSION['force_pw_change_role']);
        }

        // Log it
        $db->prepare("
            INSERT INTO password_change_logs (changed_by, changed_for, ip_address)
            VALUES (?,?,?)
        ")->execute([$userId, $userId, $_SERVER['REMOTE_ADDR'] ?? '']);

        setFlash('success', 'Your password has been updated. Please log in with your new password.');
        header('Location: login.php');
        exit;
    }
}

$isFirstLogin = ($mode === 'session');
$heading = $isFirstLogin ? 'Set Your Password' : 'Reset Your Password';
$subtext = $isFirstLogin
    ? 'Welcome! Before you continue, please set a personal password for your account.'
    : 'Create a new secure password for your account.';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($heading) ?> — TAMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            background: #0a0f1e;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding: 1.5rem;
        }

        /* ── Subtle grid pattern ── */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(201, 168, 76, .04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(201, 168, 76, .04) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }

        .card-wrap {
            width: 100%;
            max-width: 460px;
            background: #111827;
            border: 1px solid rgba(201, 168, 76, .18);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 32px 80px rgba(0, 0, 0, .55), 0 0 0 1px rgba(255, 255, 255, .04);
            position: relative;
        }

        /* Gold accent top bar */
        .card-wrap::before {
            content: '';
            display: block;
            height: 3px;
            background: linear-gradient(90deg, #c9a84c, #f0c96e, #c9a84c);
        }

        .card-header-area {
            padding: 2rem 2rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, .06);
        }

        .lock-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, rgba(201, 168, 76, .15), rgba(201, 168, 76, .05));
            border: 1px solid rgba(201, 168, 76, .3);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: #c9a84c;
            margin-bottom: 1rem;
            transition: all .4s ease;
        }

        .lock-icon.unlocked {
            background: linear-gradient(135deg, rgba(16, 185, 129, .15), rgba(16, 185, 129, .05));
            border-color: rgba(16, 185, 129, .4);
            color: #10b981;
        }

        .badge-mode {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            margin-bottom: .75rem;
        }

        .badge-first {
            background: rgba(99, 102, 241, .15);
            border: 1px solid rgba(99, 102, 241, .3);
            color: #a5b4fc;
        }

        .badge-reset {
            background: rgba(201, 168, 76, .12);
            border: 1px solid rgba(201, 168, 76, .25);
            color: #c9a84c;
        }

        h1 {
            font-size: 1.45rem;
            font-weight: 700;
            color: #f9fafb;
            margin-bottom: .4rem;
        }

        .subtext {
            font-size: .83rem;
            color: #6b7280;
            line-height: 1.5;
        }

        .user-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: .9rem;
            padding: 6px 12px;
            background: rgba(255, 255, 255, .04);
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 8px;
        }

        .user-chip .avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: linear-gradient(135deg, #c9a84c, #f0c96e);
            color: #0a0f1e;
            font-size: .68rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .user-chip span {
            font-size: .8rem;
            color: #d1d5db;
        }

        .card-body-area {
            padding: 1.75rem 2rem 2rem;
        }

        /* Error list */
        .err-box {
            background: rgba(239, 68, 68, .08);
            border: 1px solid rgba(239, 68, 68, .25);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 1.25rem;
        }

        .err-box ul {
            padding-left: 1rem;
            margin: 6px 0 0;
        }

        .err-box li {
            font-size: .8rem;
            color: #fca5a5;
            margin-bottom: 3px;
        }

        .err-box strong {
            font-size: .8rem;
            color: #ef4444;
        }

        /* Fatal error */
        .fatal-box {
            padding: 2rem;
            text-align: center;
        }

        .fatal-icon {
            font-size: 2.5rem;
            color: #ef4444;
            margin-bottom: 1rem;
        }

        .fatal-box h2 {
            font-size: 1.1rem;
            color: #f9fafb;
            margin-bottom: .5rem;
        }

        .fatal-box p {
            font-size: .82rem;
            color: #9ca3af;
            line-height: 1.6;
        }

        /* Form */
        .field-group {
            margin-bottom: 1.1rem;
        }

        .field-label {
            display: block;
            font-size: .78rem;
            font-weight: 600;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: .05em;
            margin-bottom: .45rem;
        }

        .field-label .req {
            color: #c9a84c;
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrap input {
            width: 100%;
            padding: .7rem 2.6rem .7rem .9rem;
            background: rgba(255, 255, 255, .05);
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 10px;
            color: #f9fafb;
            font-size: .9rem;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }

        .input-wrap input:focus {
            border-color: rgba(201, 168, 76, .5);
            box-shadow: 0 0 0 3px rgba(201, 168, 76, .1);
        }

        .input-wrap input.valid {
            border-color: rgba(16, 185, 129, .5);
        }

        .input-wrap input.invalid {
            border-color: rgba(239, 68, 68, .5);
        }

        .toggle-eye {
            position: absolute;
            right: .7rem;
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            padding: 0;
            font-size: .85rem;
            transition: color .2s;
        }

        .toggle-eye:hover {
            color: #c9a84c;
        }

        /* Strength bar */
        .strength-wrap {
            margin-top: .5rem;
        }

        .strength-track {
            height: 4px;
            background: rgba(255, 255, 255, .08);
            border-radius: 99px;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0;
            border-radius: 99px;
            transition: width .35s ease, background .35s ease;
        }

        .strength-label {
            font-size: .7rem;
            margin-top: .25rem;
            transition: color .35s;
        }

        /* Requirements checklist */
        .req-list {
            margin: .9rem 0 .25rem;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .req-item {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: .75rem;
            color: #6b7280;
            transition: color .2s;
        }

        .req-item i {
            width: 14px;
            font-size: .68rem;
        }

        .req-item.met {
            color: #10b981;
        }

        .req-item.unmet {
            color: #6b7280;
        }

        /* Match message */
        .match-msg {
            font-size: .75rem;
            margin-top: .3rem;
            min-height: 1em;
        }

        /* Generate btn */
        .btn-gen {
            background: none;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 8px;
            color: #9ca3af;
            font-size: .75rem;
            padding: 5px 11px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all .2s;
            margin-bottom: 1.1rem;
        }

        .btn-gen:hover {
            border-color: #c9a84c;
            color: #c9a84c;
        }

        /* Submit */
        .btn-submit {
            width: 100%;
            padding: .8rem;
            background: linear-gradient(135deg, #c9a84c, #f0c96e);
            border: none;
            border-radius: 10px;
            color: #0a0f1e;
            font-size: .95rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity .2s, transform .1s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit:hover {
            opacity: .9;
        }

        .btn-submit:active {
            transform: scale(.98);
        }

        .btn-submit:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .back-link {
            text-align: center;
            margin-top: 1.1rem;
            font-size: .78rem;
            color: #6b7280;
        }

        .back-link a {
            color: #c9a84c;
            text-decoration: none;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {

            .card-header-area,
            .card-body-area {
                padding-left: 1.25rem;
                padding-right: 1.25rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                transition: none !important;
            }
        }
    </style>
</head>

<body>

    <div class="card-wrap">

        <?php if (isset($fatalError)): ?>
            <!-- ── Fatal / expired token ── -->
            <div class="fatal-box">
                <div class="fatal-icon"><i class="fas fa-link-slash"></i></div>
                <h2>Link Expired or Invalid</h2>
                <p><?= htmlspecialchars($fatalError) ?></p>
            </div>

        <?php else: ?>
            <!-- ── Header ── -->
            <div class="card-header-area">
                <div class="lock-icon" id="lockIcon">
                    <i class="fas fa-lock" id="lockIco"></i>
                </div>

                <div class="badge-mode <?= $isFirstLogin ? 'badge-first' : 'badge-reset' ?>">
                    <i class="fas <?= $isFirstLogin ? 'fa-star' : 'fa-rotate-right' ?>"></i>
                    <?= $isFirstLogin ? 'First Login' : 'Password Reset' ?>
                </div>

                <h1><?= htmlspecialchars($heading) ?></h1>
                <p class="subtext"><?= htmlspecialchars($subtext) ?></p>

                <div class="user-chip">
                    <div class="avatar"><?= strtoupper(substr($userInfo['full_name'], 0, 2)) ?></div>
                    <span><?= htmlspecialchars($userInfo['full_name']) ?> &middot;
                        <?= htmlspecialchars($userInfo['username']) ?></span>
                </div>
            </div>

            <!-- ── Body ── -->
            <div class="card-body-area">

                <?php if (!empty($errors)): ?>
                    <div class="err-box">
                        <strong><i class="fas fa-circle-exclamation me-1"></i>Please fix the following:</strong>
                        <ul>
                            <?php foreach ($errors as $e): ?>
                                <li><?= htmlspecialchars($e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" id="pwForm" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <?php if ($mode === 'token'): ?>
                        <input type="hidden" name="token" value="<?= htmlspecialchars($rawToken) ?>">
                    <?php endif; ?>

                    <!-- New Password -->
                    <div class="field-group">
                        <label class="field-label" for="newPwd">
                            New Password <span class="req">*</span>
                        </label>
                        <div class="input-wrap">
                            <input type="password" id="newPwd" name="new_password" required minlength="8"
                                placeholder="Create a strong password" autocomplete="new-password">
                            <button type="button" class="toggle-eye" onclick="togglePwd('newPwd',this)" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>

                        <!-- Strength -->
                        <div class="strength-wrap">
                            <div class="strength-track">
                                <div class="strength-fill" id="strengthFill"></div>
                            </div>
                            <div class="strength-label" id="strengthText" style="color:#6b7280;"></div>
                        </div>

                        <!-- Requirements -->
                        <div class="req-list">
                            <div class="req-item unmet" id="req-len"><i class="fas fa-circle-check"></i> At least 8
                                characters</div>
                            <div class="req-item unmet" id="req-upper"><i class="fas fa-circle-check"></i> One uppercase
                                letter</div>
                            <div class="req-item unmet" id="req-num"><i class="fas fa-circle-check"></i> One number</div>
                            <div class="req-item unmet" id="req-special"><i class="fas fa-circle-check"></i> One special
                                character (bonus)</div>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="field-group">
                        <label class="field-label" for="confirmPwd">
                            Confirm Password <span class="req">*</span>
                        </label>
                        <div class="input-wrap">
                            <input type="password" id="confirmPwd" name="confirm_password" required
                                placeholder="Re-enter password" autocomplete="new-password">
                            <button type="button" class="toggle-eye" onclick="togglePwd('confirmPwd',this)" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="match-msg" id="matchMsg"></div>
                    </div>

                    <!-- Generate -->
                    <button type="button" class="btn-gen" onclick="generatePassword()">
                        <i class="fas fa-dice"></i> Generate Strong Password
                    </button>

                    <button type="submit" class="btn-submit" id="submitBtn">
                        <i class="fas fa-lock"></i>
                        <span><?= $isFirstLogin ? 'Set Password &amp; Continue' : 'Set New Password' ?></span>
                    </button>
                </form>

                <div class="back-link">
                    <a href="login.php"><i class="fas fa-arrow-left" style="font-size:.7rem;"></i> Back to login</a>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script>
        const newPwdEl = document.getElementById('newPwd');
        const confirmEl = document.getElementById('confirmPwd');
        const lockIcon = document.getElementById('lockIcon');
        const lockIco = document.getElementById('lockIco');
        const strengthFill = document.getElementById('strengthFill');
        const strengthText = document.getElementById('strengthText');
        const matchMsg = document.getElementById('matchMsg');
        const submitBtn = document.getElementById('submitBtn');

        function togglePwd(id, btn) {
            const f = document.getElementById(id);
            const i = btn.querySelector('i');
            const show = f.type === 'password';
            f.type = show ? 'text' : 'password';
            i.classList.toggle('fa-eye', !show);
            i.classList.toggle('fa-eye-slash', show);
        }

        function scorePassword(pwd) {
            let score = 0;
            if (pwd.length >= 8) score++;
            if (pwd.length >= 12) score++;
            if (/[A-Z]/.test(pwd)) score++;
            if (/[0-9]/.test(pwd)) score++;
            if (/[^A-Za-z0-9]/.test(pwd)) score++;
            return score;
        }

        const levels = [
            { w: '15%', bg: '#ef4444', txt: 'Very Weak' },
            { w: '30%', bg: '#f97316', txt: 'Weak' },
            { w: '52%', bg: '#f59e0b', txt: 'Fair' },
            { w: '75%', bg: '#84cc16', txt: 'Strong' },
            { w: '100%', bg: '#10b981', txt: 'Very Strong' },
        ];

        function updateReq(id, met) {
            const el = document.getElementById(id);
            el.classList.toggle('met', met);
            el.classList.toggle('unmet', !met);
        }

        function checkStrength(pwd) {
            if (!pwd) {
                strengthFill.style.width = '0'; strengthText.textContent = ''; return 0;
            }
            const score = scorePassword(pwd);
            const l = levels[Math.min(score, 4)];
            strengthFill.style.width = l.w;
            strengthFill.style.background = l.bg;
            strengthText.style.color = l.bg;
            strengthText.textContent = l.txt;

            updateReq('req-len', pwd.length >= 8);
            updateReq('req-upper', /[A-Z]/.test(pwd));
            updateReq('req-num', /[0-9]/.test(pwd));
            updateReq('req-special', /[^A-Za-z0-9]/.test(pwd));

            return score;
        }

        function checkMatch() {
            const p1 = newPwdEl.value, p2 = confirmEl.value;
            if (!p2) { matchMsg.textContent = ''; confirmEl.classList.remove('valid', 'invalid'); return false; }
            const ok = p1 === p2;
            matchMsg.textContent = ok ? '✓ Passwords match' : '✗ Passwords do not match';
            matchMsg.style.color = ok ? '#10b981' : '#ef4444';
            confirmEl.classList.toggle('valid', ok);
            confirmEl.classList.toggle('invalid', !ok);

            // Animate lock icon
            if (ok && scorePassword(p1) >= 2) {
                lockIcon.classList.add('unlocked');
                lockIco.classList.replace('fa-lock', 'fa-lock-open');
            } else {
                lockIcon.classList.remove('unlocked');
                lockIco.classList.replace('fa-lock-open', 'fa-lock');
            }

            return ok;
        }

        newPwdEl.addEventListener('input', function () {
            checkStrength(this.value);
            if (confirmEl.value) checkMatch();
        });
        confirmEl.addEventListener('input', checkMatch);

        document.getElementById('pwForm').addEventListener('submit', function (e) {
            const ok = checkMatch() && scorePassword(newPwdEl.value) >= 1
                && newPwdEl.value.length >= 8;
            if (!ok) e.preventDefault();
        });

        function generatePassword() {
            const chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#$!';
            let pwd = '';
            for (let i = 0; i < 14; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length));

            // Guarantee one uppercase, one digit, one special
            const upper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
            const digits = '23456789';
            const special = '@#$!';
            pwd = pwd.slice(0, 11)
                + upper.charAt(Math.floor(Math.random() * upper.length))
                + digits.charAt(Math.floor(Math.random() * digits.length))
                + special.charAt(Math.floor(Math.random() * special.length));

            newPwdEl.type = confirmEl.type = 'text';
            newPwdEl.value = confirmEl.value = pwd;
            checkStrength(pwd);
            checkMatch();
        }
    </script>
</body>

</html>