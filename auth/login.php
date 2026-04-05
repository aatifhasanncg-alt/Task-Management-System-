<?php
// auth/login.php — ASK Global Advisory MISPro
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';

// Already logged in → redirect
if (!empty($_SESSION['user_id'])) {
    header('Location: ../' . $_SESSION['role'] . '/dashboard/index.php');
    exit;
}

$errors = [];
$selectedRole = $_GET['role'] ?? ($_POST['role'] ?? 'staff');
$selectedBranch = $_GET['branch_id'] ?? ($_POST['branch_id'] ?? '');

// Sanitise role value
if (!in_array($selectedRole, ['executive', 'admin', 'staff'])) {
    $selectedRole = 'staff';
}

$db = getDB();

// Load active branches
$branches = $db->query("
    SELECT id, branch_name, city
    FROM   branches
    WHERE  is_active = 1
    ORDER  BY is_head_office DESC, branch_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'staff';
    $branchId = (int) ($_POST['branch_id'] ?? 0);

    if (!in_array($role, ['executive', 'admin', 'staff']))
        $role = 'staff';

    // Validation
    if (!$username)
        $errors[] = 'Username or email is required.';
    if (!$password)
        $errors[] = 'Password is required.';
    if ($role !== 'executive' && !$branchId)
        $errors[] = 'Please select your branch.';

    if (!$errors) {
        /*
         * Schema note:
         *   users.role_id  →  FK to roles(id)
         *   roles.role_name = 'executive' | 'admin' | 'staff'
         * So we JOIN roles and filter by role_name, NOT a direct column.
         */
        $st = $db->prepare("
            SELECT u.*, r.role_name AS role, b.branch_name
            FROM   users    u
            JOIN   roles    r ON r.id  = u.role_id
            LEFT JOIN branches b ON b.id = u.branch_id
            WHERE  (u.username = ? OR u.email = ?)
              AND  r.role_name  = ?
              AND  u.is_active  = 1
            LIMIT  1
        ");
        $st->execute([$username, $username, $role]);
        $user = $st->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = 'Invalid credentials. Please check your username, password, and role.';

        } elseif ($role !== 'executive' && (int) $user['branch_id'] !== $branchId) {
            $errors[] = 'You are not assigned to the selected branch.';

        } else {
            // ── 2FA pending ──────────────────────────────────────────────
            if ($user['ga_enabled'] && $user['ga_secret']) {
                // Set user session BEFORE redirecting to 2FA
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role'],
                    'role_id' => $user['role_id'] ?? 0
                ];

                $_SESSION['2fa_pending_user'] = $user['id'];
                $_SESSION['2fa_pending_role'] = $role;
                header('Location: verify_2fa.php');
                exit;
            }

            // ── Login success ─────────────────────────────────────────────
            // Login success (no 2FA)
            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'role_id' => $user['role_id'] ?? 0
            ];

            // Other session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['branch_id'] = $user['branch_id'];
            $_SESSION['branch_name'] = $user['branch_name'] ?? '';
            $_SESSION['dept_id'] = $user['department_id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['employee_id'] = $user['employee_id'] ?? '';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            // Admin access
            if ($role === 'admin') {
                $dSt = $db->prepare("SELECT department_id FROM admin_department_access WHERE admin_id = ?");
                $dSt->execute([$user['id']]);
                $_SESSION['allowed_depts'] = array_column($dSt->fetchAll(PDO::FETCH_ASSOC), 'department_id');

                $bSt = $db->prepare("SELECT branch_id FROM admin_branch_access WHERE admin_id = ?");
                $bSt->execute([$user['id']]);
                $_SESSION['allowed_branches'] = array_column($bSt->fetchAll(PDO::FETCH_ASSOC), 'branch_id');
            }

            // Log & redirect
            logActivity('Login', 'auth', "role={$role}, branch_id={$user['branch_id']}");
            header('Location: ../' . $role . '/dashboard/index.php');
            exit;
        }
    }

    // Preserve selections on error
    $selectedRole = $role;
    $selectedBranch = $branchId;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — MISPro | ASK Global Advisory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Outfit:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --navy: #0a0f1e;
            --navy-l: #1a2540;
            --gold: #c9a84c;
            --gold-l: #e8c96a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Outfit', sans-serif;
            background: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 60% at 20% 50%, rgba(201, 168, 76, .07) 0%, transparent 60%),
                radial-gradient(ellipse 60% 80% at 80% 20%, rgba(26, 37, 64, .8) 0%, transparent 70%);
            pointer-events: none;
        }

        /* ── Layout ── */
        .login-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 32px 80px rgba(0, 0, 0, .5);
            position: relative;
            z-index: 1;
            width: min(820px, 96vw);
        }

        /* ── Left panel ── */
        .login-left {
            background: linear-gradient(145deg, var(--navy) 0%, var(--navy-l) 100%);
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .login-left::after {
            content: '';
            position: absolute;
            bottom: -60px;
            right: -60px;
            width: 250px;
            height: 250px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(201, 168, 76, .1), transparent 70%);
        }

        .brand-block {
            position: relative;
            z-index: 1;
        }

        .brand-logo-lg {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--gold), var(--gold-l));
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .brand-logo-lg span {
            font-family: 'Cormorant Garamond', serif;
            font-weight: 700;
            font-size: 1.3rem;
            color: var(--navy);
        }

        .brand-block h2 {
            color: white;
            font-family: 'Cormorant Garamond', serif;
            font-size: 2rem;
            margin-bottom: .3rem;
        }

        .brand-block p {
            color: #8899aa;
            font-size: .85rem;
            line-height: 1.6;
        }

        .role-cards {
            display: flex;
            flex-direction: column;
            gap: .6rem;
            margin-top: 2rem;
        }

        .role-card {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .7rem 1rem;
            border-radius: 10px;
            background: rgba(255, 255, 255, .04);
            border: 1px solid rgba(255, 255, 255, .06);
        }

        .role-card-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(201, 168, 76, .15);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gold);
            font-size: .85rem;
            flex-shrink: 0;
        }

        .role-card-title {
            color: #d1d5db;
            font-size: .83rem;
            font-weight: 500;
        }

        .role-card-desc {
            color: #6b7280;
            font-size: .73rem;
        }

        .branch-chips {
            display: flex;
            gap: .4rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }

        .branch-chip {
            background: rgba(201, 168, 76, .1);
            color: var(--gold);
            border: 1px solid rgba(201, 168, 76, .2);
            border-radius: 50px;
            padding: .2rem .7rem;
            font-size: .73rem;
        }

        .left-footer {
            color: #4b5563;
            font-size: .72rem;
            position: relative;
            z-index: 1;
        }

        /* ── Right panel ── */
        .login-right {
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-right h3 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.8rem;
            color: var(--navy);
            margin-bottom: .25rem;
        }

        .login-right .subtitle {
            color: #9ca3af;
            font-size: .88rem;
            margin-bottom: 1.5rem;
        }

        /* ── Role tabs ── */
        .role-tabs {
            display: flex;
            gap: .4rem;
            margin-bottom: 1.5rem;
            background: #f9fafb;
            border-radius: 10px;
            padding: .3rem;
        }

        .role-tab {
            flex: 1;
            padding: .5rem .75rem;
            border-radius: 8px;
            border: none;
            background: transparent;
            font-size: .82rem;
            font-weight: 500;
            color: #9ca3af;
            cursor: pointer;
            transition: all .2s;
            text-align: center;
            font-family: 'Outfit', sans-serif;
        }

        .role-tab.active {
            background: white;
            color: var(--navy);
            box-shadow: 0 1px 4px rgba(0, 0, 0, .1);
            font-weight: 600;
        }

        .role-tab:hover:not(.active) {
            color: #374151;
        }

        /* ── Form ── */
        .form-group {
            margin-bottom: 1.1rem;
        }

        .form-label {
            font-size: .8rem;
            font-weight: 600;
            color: #374151;
            display: block;
            margin-bottom: .35rem;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: .85rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: .85rem;
            pointer-events: none;
        }

        .form-input {
            width: 100%;
            padding: .6rem .9rem .6rem 2.4rem;
            border: 1.5px solid #e5e7eb;
            border-radius: 9px;
            font-size: .9rem;
            font-family: 'Outfit', sans-serif;
            transition: all .2s;
            background: #fafafa;
            color: #1f2937;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--gold);
            background: white;
            box-shadow: 0 0 0 3px rgba(201, 168, 76, .1);
        }

        .form-select {
            appearance: none;
        }

        .pass-eye {
            position: absolute;
            right: .85rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            font-size: .85rem;
            padding: 0;
        }

        /* ── Button ── */
        .btn-login {
            width: 100%;
            padding: .75rem;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--gold), var(--gold-l));
            color: var(--navy);
            font-weight: 700;
            font-size: .95rem;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all .2s;
            margin-top: .5rem;
        }

        .btn-login:hover {
            opacity: .92;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(201, 168, 76, .3);
        }

        /* ── Errors ── */
        .error-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 9px;
            padding: .75rem 1rem;
            margin-bottom: 1rem;
        }

        .error-box li {
            color: #dc2626;
            font-size: .82rem;
            margin: 0;
        }

        /* ── Mobile ── */
        @media (max-width:680px) {
            .login-grid {
                grid-template-columns: 1fr;
            }

            .login-left {
                display: none;
            }

            .login-right {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>

<body>
    <?php if (isset($_GET['msg']) && $_GET['msg'] == "password_changed"): ?>
        <div class="alert alert-success">
            Password changed successfully. Please login again.
        </div>
    <?php endif; ?>
    <div class="login-grid">

        <!-- ══ LEFT ══════════════════════════════════════════════════════════ -->
        <div class="login-left">
            <div class="brand-block">

                <div class="brand-logo-lg"><span>ASK</span></div>
                <h2>ASK Global<br>Advisory</h2>
                <p>Management Information System for<br>Nepal's leading consulting firm.</p>

                <div class="role-cards">
                    <div class="role-card">
                        <div class="role-card-icon"><i class="fas fa-crown"></i></div>
                        <div>
                            <div class="role-card-title">Executive</div>
                            <div class="role-card-desc">Full visibility across all branches</div>
                        </div>
                    </div>
                    <div class="role-card">
                        <div class="role-card-icon"><i class="fas fa-user-shield"></i></div>
                        <div>
                            <div class="role-card-title">Admin</div>
                            <div class="role-card-desc">Branch &amp; department management</div>
                        </div>
                    </div>
                    <div class="role-card">
                        <div class="role-card-icon"><i class="fas fa-user"></i></div>
                        <div>
                            <div class="role-card-title">Staff</div>
                            <div class="role-card-desc">Assigned tasks &amp; submissions</div>
                        </div>
                    </div>
                </div>

                <div class="branch-chips">
                    <?php foreach ($branches as $b): ?>
                        <span class="branch-chip">
                            <i class="fas fa-map-marker-alt me-1"></i>
                            <?= htmlspecialchars($b['branch_name']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="left-footer">
                © <?= date('Y') ?> ASK Global Advisory Pvt. Ltd.<br>
                "At ASK business problems end, solutions begin"
            </div>
        </div>

        <!-- ══ RIGHT ══════════════════════════════════════════════════════════ -->
        <div class="login-right">
            <h3>Welcome Back</h3>
            <p class="subtitle">Sign in to continue to MISPro</p>

            <!-- Role tabs -->
            <div class="role-tabs" role="tablist" aria-label="Select your role">
                <button type="button" class="role-tab <?= $selectedRole === 'executive' ? 'active' : '' ?>"
                    onclick="setRole('executive',this)">
                    <i class="fas fa-crown me-1"></i>Executive
                </button>
                <button type="button" class="role-tab <?= $selectedRole === 'admin' ? 'active' : '' ?>"
                    onclick="setRole('admin',this)">
                    <i class="fas fa-user-shield me-1"></i>Admin
                </button>
                <button type="button" class="role-tab <?= $selectedRole === 'staff' ? 'active' : '' ?>"
                    onclick="setRole('staff',this)">
                    <i class="fas fa-user me-1"></i>Staff
                </button>
            </div>

            <!-- Errors -->
            <?php if (!empty($errors)): ?>
                <div class="error-box">
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($errors as $e): ?>
                            <li><i class="fas fa-exclamation-circle me-1"></i><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Login form -->
            <form method="POST" novalidate autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="role" id="roleInput" value="<?= htmlspecialchars($selectedRole) ?>">

                <!-- Username / Email -->
                <div class="form-group">
                    <label class="form-label" for="usernameInput">Username or Email</label>
                    <div class="input-wrap">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" id="usernameInput" name="username" class="form-input"
                            placeholder="Enter your username or email"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username">
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label class="form-label" for="passInput">Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="passInput" name="password" class="form-input"
                            placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" class="pass-eye" onclick="togglePass('passInput',this)"
                            aria-label="Toggle password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Branch — hidden for executive -->
                <div class="form-group" id="branchGroup"
                    style="<?= $selectedRole === 'executive' ? 'display:none;' : '' ?>">
                    <label class="form-label" for="branchSelect">Branch</label>
                    <div class="input-wrap">
                        <i class="fas fa-map-marker-alt input-icon"></i>
                        <select id="branchSelect" name="branch_id" class="form-input form-select"
                            <?= $selectedRole === 'executive' ? 'disabled' : '' ?>>
                            <option value="">— Select Your Branch —</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= ((string) $selectedBranch === (string) $b['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                    &mdash; <?= htmlspecialchars($b['city']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>Sign In
                </button>
            </form>

            <div class="text-center mt-3" style="color:#9ca3af;font-size:.78rem;">
                <i class="fas fa-shield-alt me-1" style="color:#f59e0b;"></i>
                Secured with Google Authenticator 2FA
            </div>
        </div>

    </div><!-- /.login-grid -->

    <script>
        /* Switch role tab + toggle branch dropdown */
        function setRole(role, btn) {
            document.getElementById('roleInput').value = role;

            document.querySelectorAll('.role-tab').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');

            const bg = document.getElementById('branchGroup');
            const sel = document.getElementById('branchSelect');

            if (role === 'executive') {
                bg.style.display = 'none';
                sel.disabled = true;
                sel.value = '';      // clear so no branch_id submitted accidentally
            } else {
                bg.style.display = 'block';
                sel.disabled = false;
            }
        }

        /* Toggle password visibility */
        function togglePass(id, btn) {
            const inp = document.getElementById(id);
            inp.type = inp.type === 'password' ? 'text' : 'password';
            btn.querySelector('i').className =
                inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
        }
    </script>
</body>

</html>