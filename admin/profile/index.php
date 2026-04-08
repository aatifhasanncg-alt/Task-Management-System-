<?php
// admin/profile/index.php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/active_sessions_widget.php';  // ✅ ADD THIS
requireAdmin();

$db = getDB();
$user = currentUser();
$pageTitle = 'My Profile';

handleSessionRevoke();
// Full profile
$profileStmt = $db->prepare("
    SELECT u.*, r.role_name, d.dept_name, b.branch_name
    FROM users u
    LEFT JOIN roles r       ON r.id = u.role_id
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN branches b    ON b.id = u.branch_id
    WHERE u.id = ?
");
$profileStmt->execute([$user['id']]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
if (!$profile) {
    echo "User profile not found!";
    exit;
}

$errors = [];

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    verifyCsrf();
    $currentPw = $_POST['current_password'] ?? '';
    $newPw = $_POST['new_password'] ?? '';
    $confirmPw = $_POST['confirm_password'] ?? '';

    if (!password_verify($currentPw, $profile['password']))
        $errors[] = 'Current password is incorrect.';
    if (strlen($newPw) < 8)
        $errors[] = 'New password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $newPw))
        $errors[] = 'Password must contain at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $newPw))
        $errors[] = 'Password must contain at least one number.';
    if ($newPw !== $confirmPw)
        $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $db->prepare("UPDATE users SET password=?, updated_at=NOW() WHERE id=?")
            ->execute([password_hash($newPw, PASSWORD_DEFAULT), $user['id']]);
        $db->prepare("INSERT INTO password_change_logs (changed_by, changed_for, ip_address) VALUES (?,?,?)")
            ->execute([$user['id'], $user['id'], $_SERVER['REMOTE_ADDR']]);
        logActivity('Changed own password', 'profile');
        setFlash('success', 'Password changed successfully.');
        header('Location: index.php');
        exit;
    }
}

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <div class="page-hero">
                <div class="page-hero-badge"><i class="fas fa-user-edit"></i> Profile</div>
                <h4>My Profile</h4>
                <p>Manage your account settings and view your task summary.</p>
            </div>

            <?= flashHtml() ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger rounded-3 mb-3">
                    <ul class="mb-0">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="row g-4">

                <!-- ── Left Column ── -->
                <div class="col-lg-4">

                    <!-- Avatar card -->
                    <div class="card-mis text-center mb-4">
                        <div class="card-mis-body py-4">
                            <div class="avatar-circle"
                                style="width:80px;height:80px;font-size:1.8rem;margin:0 auto 1rem;background:linear-gradient(135deg,#0a0f1e,#1e2a45);color:#c9a84c;border:2px solid #c9a84c;">
                                <?= strtoupper(substr($profile['full_name'], 0, 2)) ?>
                            </div>
                            <h5 style="font-size:1.1rem;font-weight:700;"><?= htmlspecialchars($profile['full_name']) ?>
                            </h5>
                            <p style="color:#9ca3af;font-size:.83rem;margin:.25rem 0;">
                                <?= htmlspecialchars($profile['email']) ?></p>
                            <div class="d-flex justify-content-center gap-2 mt-2 flex-wrap">
                                <span
                                    class="branch-badge"><?= htmlspecialchars($profile['branch_name'] ?? '—') ?></span>
                                <span class="dept-chip"><?= htmlspecialchars($profile['dept_name'] ?? '—') ?></span>
                            </div>
                            <?php if ($profile['employee_id']): ?>
                                <p style="font-size:.75rem;color:#9ca3af;margin-top:.75rem;">
                                    <i class="fas fa-id-badge me-1"></i><?= htmlspecialchars($profile['employee_id']) ?>
                                </p>
                            <?php endif; ?>
                            <div class="mt-3">
                                <span
                                    style="background:#fef3c7;color:#92400e;font-size:.72rem;padding:.25rem .75rem;border-radius:99px;font-weight:600;text-transform:uppercase;">
                                    <i
                                        class="fas fa-user-shield me-1"></i><?= htmlspecialchars($profile['role_name'] ?? 'Admin') ?>
                                </span>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ── Right Column ── -->
                <div class="col-lg-8">

                    <!-- Account Information -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-user text-warning me-2"></i>Account Information</h5>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3">
                                <?php foreach ([
                                    ['Full Name', $profile['full_name'], 'fa-user'],
                                    ['Username', $profile['username'], 'fa-at'],
                                    ['Email', $profile['email'], 'fa-envelope'],
                                    ['Phone', $profile['phone'] ?? '—', 'fa-phone'],
                                    ['Employee ID', $profile['employee_id'] ?? '—', 'fa-id-badge'],
                                    ['Role', $profile['role_name'] ?? '—', 'fa-user-shield'],
                                    ['Department', $profile['dept_name'] ?? '—', 'fa-layer-group'],
                                    ['Branch', $profile['branch_name'] ?? '—', 'fa-map-marker-alt'],
                                    ['Status', $profile['is_active'] ? 'Active' : 'Inactive', 'fa-circle-check'],
                                    ['Joining Date', $profile['joining_date'] ? date('M j, Y', strtotime($profile['joining_date'])) : '—', 'fa-calendar'],
                                    ['Address', $profile['address'] ?? '—', 'fa-home'],
                                    ['Emergency Contact', $profile['emergency_contact'] ?? '—', 'fa-phone-alt'],
                                ] as [$lbl, $val, $ic]): ?>
                                    <div class="col-md-6">
                                        <div
                                            style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;">
                                            <i class="fas <?= $ic ?> me-1 text-warning"></i><?= $lbl ?>
                                        </div>
                                        <div style="font-size:.9rem;color:#1f2937;font-weight:500;margin-top:.15rem;">
                                            <?= htmlspecialchars($val ?? '—') ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Change Password -->
                    <div class="card-mis">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-key text-warning me-2"></i>Change Password</h5>
                        </div>
                        <div class="card-mis-body">
                            <form method="POST" novalidate>
                                <input type="hidden" name="action" value="change_password">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label-mis">Current Password</label>
                                        <div style="position:relative;">
                                            <input type="password" id="cp" name="current_password" class="form-control"
                                                required>
                                            <button type="button" onclick="togglePass('cp',this)"
                                                style="position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af;">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-mis">New Password</label>
                                        <div style="position:relative;">
                                            <input type="password" id="np" name="new_password" class="form-control"
                                                oninput="checkPwStrength(this.value)" required>
                                            <button type="button" onclick="togglePass('np',this)"
                                                style="position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af;">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-mis">Confirm Password</label>
                                        <div style="position:relative;">
                                            <input type="password" id="cp2" name="confirm_password" class="form-control"
                                                required>
                                            <button type="button" onclick="togglePass('cp2',this)"
                                                style="position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af;">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex gap-3 flex-wrap" style="font-size:.78rem;">
                                            <span id="r-len"><i class="fas fa-circle" style="font-size:.5rem;"></i> 8+
                                                characters</span>
                                            <span id="r-upper"><i class="fas fa-circle" style="font-size:.5rem;"></i>
                                                Uppercase</span>
                                            <span id="r-lower"><i class="fas fa-circle" style="font-size:.5rem;"></i>
                                                Lowercase</span>
                                            <span id="r-num"><i class="fas fa-circle" style="font-size:.5rem;"></i>
                                                Number</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <button type="submit" class="btn-gold btn">
                                        <i class="fas fa-save me-2"></i>Update Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="card-mis mt-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-shield-alt text-warning me-2"></i>Active Sessions</h5>
                        </div>
                        <div class="card-mis-body p-0">
                            <?php renderSessionsWidget(); ?>
                        </div>
                    </div>
                </div><!-- col-lg-8 -->
            </div><!-- row -->
        </div>
    </div>
</div>

<script>
    function togglePass(id, btn) {
        const inp = document.getElementById(id);
        const icon = btn.querySelector('i');
        if (inp.type === 'password') {
            inp.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            inp.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    function checkPwStrength(val) {
        const rules = {
            'r-len': val.length >= 8,
            'r-upper': /[A-Z]/.test(val),
            'r-lower': /[a-z]/.test(val),
            'r-num': /[0-9]/.test(val),
        };
        for (const [id, pass] of Object.entries(rules)) {
            const el = document.getElementById(id);
            if (el) el.style.color = pass ? '#10b981' : '#9ca3af';
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>