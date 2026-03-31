<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../vendor/GoogleAuthenticator.php';

requireExecutive();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied.');
    header('Location: index.php');
    exit;
}

$db = getDB();
$currentUser = currentUser(); // ← renamed — never overwrite this
$pageTitle = 'View Staff';

$staffId = (int) ($_GET['id'] ?? 0);
if (!$staffId) {
    setFlash('error', 'Invalid staff ID.');
    header('Location: index.php');
    exit;
}

// Fetch the STAFF being viewed — use $staffUser not $user
$staffStmt = $db->prepare("
    SELECT u.*,
           r.role_name,
           b.branch_name,
           d.dept_name
    FROM users u
    LEFT JOIN roles r       ON r.id = u.role_id
    LEFT JOIN branches b    ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.id = ?
");
$staffStmt->execute([$staffId]);
$staffUser = $staffStmt->fetch();

if (!$staffUser) {
    setFlash('error', 'Staff not found.');
    header('Location: index.php');
    exit;
}

// Only generate QR if ga_secret exists
$ga = new PHPGangsta_GoogleAuthenticator();
$qr = null;
if (!empty($staffUser['ga_secret'])) {
    $qr = $ga->getQRCodeGoogleUrl(
        $staffUser['email'],
        $staffUser['ga_secret'],
        'ASK MIS'
    );
}

// Task stats for this staff member
$taskStmt = $db->prepare("
    SELECT 
        ts.status_name,
        COUNT(t.id) as total
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE t.assigned_to = ? AND t.is_active = 1
    GROUP BY ts.status_name
");
$taskStmt->execute([$staffId]);
$statusData = $taskStmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['Done'=>5,...]

// Total tasks
$totalTasks = array_sum($statusData);

// Overdue count
$overdueStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE t.assigned_to = ?
      AND t.is_active = 1
      AND t.due_date < CURDATE()
      AND ts.status_name != 'Done'
");
$overdueStmt->execute([$staffId]);
$overdueTasks = $overdueStmt->fetchColumn();

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
                <div class="d-flex gap-2">
                    <a href="reset_password.php?id=<?= $staffId ?>" class="btn btn-warning btn-sm">
                        <i class="fas fa-key me-1"></i>Reset Password
                    </a>

                </div>
            </div>

            <div class="row g-4">

                <!-- LEFT -->
                <div class="col-lg-8">

                    <!-- Personal Info -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-user text-warning me-2"></i>Personal Information</h5>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3">
                                <?php
                                $personalFields = [
                                    'Full Name' => $staffUser['full_name'],
                                    'Employee ID' => $staffUser['employee_id'] ?? '—',
                                    'Email' => $staffUser['email'],
                                    'Phone' => $staffUser['phone'] ?? '—',
                                    'Joining Date' => $staffUser['joining_date']
                                        ? date('d M Y', strtotime($staffUser['joining_date'])) : '—',
                                    'Address' => $staffUser['address'] ?? '—',
                                ];
                                foreach ($personalFields as $label => $val):
                                    ?>
                                    <div class="col-md-4">
                                        <div
                                            style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                            <?= $label ?>
                                        </div>
                                        <div style="font-size:.88rem;margin-top:.2rem;color:#1f2937;">
                                            <?= htmlspecialchars($val) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Account Info -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-user-shield text-warning me-2"></i>Account Information</h5>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3">
                                <?php
                                $accountFields = [
                                    'Username' => $staffUser['username'],
                                    'Role' => ucfirst($staffUser['role_name'] ?? '—'),
                                    'Branch' => $staffUser['branch_name'] ?? '—',
                                    'Department' => $staffUser['dept_name'] ?? '—',
                                    'Last Login' => $staffUser['last_login']
                                        ? date('d M Y, H:i', strtotime($staffUser['last_login'])) : 'Never',
                                    'Created' => date('d M Y', strtotime($staffUser['created_at'])),
                                ];
                                foreach ($accountFields as $label => $val):
                                    ?>
                                    <div class="col-md-4">
                                        <div
                                            style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                            <?= $label ?>
                                        </div>
                                        <div style="font-size:.88rem;margin-top:.2rem;color:#1f2937;">
                                            <?= htmlspecialchars($val) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Status -->
                                <div class="col-md-4">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                        Status</div>
                                    <div style="margin-top:.2rem;">
                                        <?php if ($staffUser['is_active']): ?>
                                            <span
                                                style="background:#ecfdf5;color:#10b981;padding:.2rem .65rem;border-radius:99px;font-size:.78rem;font-weight:600;">
                                                <i class="fas fa-circle me-1" style="font-size:.5rem;"></i>Active
                                            </span>
                                        <?php else: ?>
                                            <span
                                                style="background:#fef2f2;color:#ef4444;padding:.2rem .65rem;border-radius:99px;font-size:.78rem;font-weight:600;">
                                                <i class="fas fa-circle me-1" style="font-size:.5rem;"></i>Inactive
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- 2FA Status -->
                                <div class="col-md-4">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                        2FA Status</div>
                                    <div style="margin-top:.2rem;">
                                        <?php if ($staffUser['ga_enabled']): ?>
                                            <span
                                                style="background:#ecfdf5;color:#10b981;padding:.2rem .65rem;border-radius:99px;font-size:.78rem;font-weight:600;">
                                                <i class="fas fa-shield-alt me-1"></i>Enabled
                                            </span>
                                        <?php else: ?>
                                            <span
                                                style="background:#fff7ed;color:#f59e0b;padding:.2rem .65rem;border-radius:99px;font-size:.78rem;font-weight:600;">
                                                <i class="fas fa-shield me-1"></i>Not Activated
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Task Stats -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-chart-bar text-warning me-2"></i>Task Statistics</h5>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3 text-center">

                                <!-- Total -->
                                <div class="col">
                                    <div style="background:#eff6ff;border-radius:10px;padding:.75rem .5rem;">
                                        <i class="fas fa-list-check" style="color:#3b82f6;font-size:1.1rem;"></i>
                                        <div style="font-size:1.4rem;font-weight:700;color:#3b82f6;">
                                            <?= $totalTasks ?>
                                        </div>
                                        <div style="font-size:.7rem;color:#6b7280;">Total</div>
                                    </div>
                                </div>

                                <!-- Dynamic Status -->
                                <?php foreach ($statusData as $status => $count):
                                    $percent = $totalTasks > 0 ? round(($count / $totalTasks) * 100) : 0;

                                    // Color mapping (optional fallback)
                                    $colorMap = [
                                        'Done' => ['#10b981', '#ecfdf5', 'fa-check-circle'],
                                        'WIP' => ['#f59e0b', '#fffbeb', 'fa-spinner'],
                                        'Pending' => ['#ef4444', '#fef2f2', 'fa-clock'],
                                    ];

                                    [$color, $bg, $icon] = $colorMap[$status] ?? ['#6b7280', '#f3f4f6', 'fa-circle'];
                                    ?>
                                    <div class="col">
                                        <div style="background:<?= $bg ?>;border-radius:10px;padding:.75rem .5rem;">
                                            <i class="fas <?= $icon ?>" style="color:<?= $color ?>;"></i>

                                            <div style="font-size:1.2rem;font-weight:700;color:<?= $color ?>;">
                                                <?= $count ?>
                                            </div>

                                            <div style="font-size:.7rem;color:#6b7280;">
                                                <?= htmlspecialchars($status) ?>
                                            </div>

                                            <!-- Percentage -->
                                            <div style="font-size:.65rem;color:#9ca3af;">
                                                <?= $percent ?>%
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Overdue -->
                                <div class="col">
                                    <div style="background:#fef2f2;border-radius:10px;padding:.75rem .5rem;">
                                        <i class="fas fa-exclamation-circle" style="color:#dc2626;"></i>
                                        <div style="font-size:1.2rem;font-weight:700;color:#dc2626;">
                                            <?= $overdueTasks ?>
                                        </div>
                                        <div style="font-size:.7rem;color:#6b7280;">Overdue</div>

                                        <?php
                                        $overduePercent = $totalTasks > 0 ? round(($overdueTasks / $totalTasks) * 100) : 0;
                                        ?>
                                        <div style="font-size:.65rem;color:#9ca3af;">
                                            <?= $overduePercent ?>%
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                </div>

                <!-- RIGHT -->
                <div class="col-lg-4">

                    <!-- Google 2FA -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-shield-alt text-warning me-2"></i>Google 2FA</h5>
                            <?php if ($staffUser['ga_enabled']): ?>
                                <span
                                    style="background:#ecfdf5;color:#10b981;padding:.2rem .55rem;border-radius:99px;font-size:.72rem;font-weight:600;">Active</span>
                            <?php else: ?>
                                <span
                                    style="background:#fff7ed;color:#f59e0b;padding:.2rem .55rem;border-radius:99px;font-size:.72rem;font-weight:600;">Pending</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-mis-body text-center">

                            <?php if (!empty($staffUser['ga_secret'])): ?>
                                <!-- QR Code -->
                                <div
                                    style="background:#fff;padding:12px;border-radius:10px;border:2px solid #e5e7eb;display:inline-block;margin-bottom:.75rem;">
                                    <img src="<?= htmlspecialchars($qr) ?>" width="170" height="170" alt="2FA QR Code"
                                        style="display:block;">
                                </div>
                                <p style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">
                                    Scan using <strong>Google Authenticator</strong>
                                </p>
                                <!-- Secret Key -->
                                <div
                                    style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:.65rem;text-align:left;">
                                    <div
                                        style="font-size:.7rem;color:#9ca3af;margin-bottom:.3rem;text-transform:uppercase;font-weight:600;">
                                        Secret Key</div>
                                    <div class="d-flex align-items-center gap-2">
                                        <code id="staffGaSecret"
                                            style="font-size:.88rem;font-weight:700;letter-spacing:.1em;flex:1;color:#1f2937;word-break:break-all;">
                                    <?= htmlspecialchars($staffUser['ga_secret']) ?>
                                </code>
                                        <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0"
                                            onclick="copyStaffSecret()">
                                            <i class="fas fa-copy" id="copyStaffIcon"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php if (!$staffUser['ga_enabled']): ?>
                                    <div
                                        style="margin-top:.75rem;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:.6rem .75rem;font-size:.78rem;color:#92400e;text-align:left;">
                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                        Staff has not activated 2FA yet. Share the QR code or secret key with them.
                                    </div>
                                <?php endif; ?>

                            <?php else: ?>
                                <div class="text-center py-3" style="color:#9ca3af;">
                                    <i class="fas fa-shield fa-2x mb-2 d-block"></i>
                                    <p style="font-size:.85rem;">No 2FA secret set.</p>
                                    <a href="regenerate_2fa.php?id=<?= $staffId ?>" class="btn btn-gold btn-sm">
                                        <i class="fas fa-plus me-1"></i>Generate 2FA Secret
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Admin Actions -->
                    <div class="card-mis">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-cog text-warning me-2"></i>Admin Actions</h5>
                        </div>
                        <div class="card-mis-body">
                            <a href="reset_password.php?id=<?= $staffId ?>" class="btn btn-warning w-100 mb-2">
                                <i class="fas fa-key me-1"></i>Reset Password
                            </a>
                            <a href="regenerate_2fa.php?id=<?= $staffId ?>"
                                class="btn btn-outline-secondary w-100 mb-2">
                                <i class="fas fa-sync me-1"></i>Regenerate 2FA Secret
                            </a>

                        </div>
                    </div>

                </div>
            </div>

        </div>
        <?php include '../../includes/footer.php'; ?>

        <script>
            function copyStaffSecret() {
                const text = document.getElementById('staffGaSecret').textContent.trim();
                navigator.clipboard.writeText(text).then(() => {
                    const icon = document.getElementById('copyStaffIcon');
                    icon.className = 'fas fa-check';
                    icon.style.color = '#10b981';
                    setTimeout(() => { icon.className = 'fas fa-copy'; icon.style.color = ''; }, 2000);
                });
            }
        </script>