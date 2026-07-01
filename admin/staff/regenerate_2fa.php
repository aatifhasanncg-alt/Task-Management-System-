<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../vendor/GoogleAuthenticator.php';

requireAdmin();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied.');
    header('Location: index.php'); exit;
}

$db          = getDB();
$currentUser = currentUser();
$staffId     = (int)($_GET['id'] ?? $_POST['staff_id'] ?? 0);
if (!$staffId) { header('Location: index.php'); exit; }

// Fetch staff
$staffStmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
$staffStmt->execute([$staffId]);
$staffUser = $staffStmt->fetch();
if (!$staffUser) {
    setFlash('error', 'Staff not found.');
    header('Location: index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $ga        = new PHPGangsta_GoogleAuthenticator();
    $newSecret = $ga->createSecret();

    // ── Save new secret, reset ga_enabled to 0 (staff must re-verify) ──────────
    $db->prepare("UPDATE users SET ga_secret = ?, ga_enabled = 0, updated_at = NOW() WHERE id = ?")
       ->execute([$newSecret, $staffId]);



    // SECURITY: never log the secret itself in the activity/audit trail —
    // logging it would let anyone with audit-log read access bypass 2FA.
    logActivity("2FA secret regenerated for user #{$staffId} by admin #{$currentUser['id']}", 'users');
    setFlash('success', "2FA secret regenerated for {$staffUser['full_name']}. Please inform them directly to re-configure Google Authenticator.");

    header("Location: view.php?id={$staffId}"); exit;
}

// GET — show confirmation page
$pageTitle = 'Regenerate 2FA';
include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php include '../../includes/sidebar_admin.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<?= flashHtml() ?>

<div class="row g-4 justify-content-center">
    <div class="col-lg-5">

        <div class="card-mis" style="border-left:3px solid #ef4444;">
            <div class="card-mis-header">
                <h5><i class="fas fa-sync" style="color:#ef4444;"></i><span class="ms-2">Regenerate 2FA Secret</span></h5>
            </div>
            <div class="card-mis-body">

                <!-- Staff info -->
                <div class="d-flex align-items-center gap-3 mb-4 p-3"
                     style="background:#f9fafb;border-radius:10px;">
                    <div class="avatar-circle" style="width:44px;height:44px;flex-shrink:0;">
                        <?= strtoupper(substr($staffUser['full_name'], 0, 2)) ?>
                    </div>
                    <div>
                        <div style="font-weight:600;"><?= htmlspecialchars($staffUser['full_name']) ?></div>
                        <div style="font-size:.78rem;color:#9ca3af;"><?= htmlspecialchars($staffUser['email']) ?></div>
                    </div>
                </div>

                <div class="alert alert-warning rounded-3 mb-3" style="font-size:.85rem;">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Warning:</strong> This will invalidate the current 2FA setup.
                    The staff member will need to re-configure Google Authenticator with the new secret.
                </div>

               <form method="POST" id="regenForm"
                     onsubmit="return handleRegenSubmit(event, '<?= htmlspecialchars(addslashes($staffUser['full_name'])) ?>');">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="staff_id"   value="<?= $staffId ?>">

                    <div class="d-flex gap-2">
                        <a href="view.php?id=<?= $staffId ?>"
                           class="btn btn-outline-secondary flex-grow-1">
                            Cancel
                        </a>
                        <button type="submit" id="regenSubmitBtn" class="btn flex-grow-1"
                                style="background:#ef4444;color:#fff;border:none;">
                            <span id="regenBtnIcon"><i class="fas fa-sync me-1"></i>Regenerate & Email</span>
                            <span id="regenBtnLoading" style="display:none;align-items:center;justify-content:center;gap:.4rem;">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                Regenerating...
                            </span>
                        </button>
                    </div>
                </form>

            </div>
        </div>

    </div>
</div>

</div>
<script>
function handleRegenSubmit(e, fullName) {
    const confirmed = confirm(`Regenerate 2FA secret for ${fullName}? Their current authenticator setup will stop working.`);
    if (!confirmed) {
        e.preventDefault();
        return false;
    }
    const btn = document.getElementById('regenSubmitBtn');
    btn.disabled = true;
    btn.style.opacity = '0.7';
    document.getElementById('regenBtnIcon').style.display = 'none';
    document.getElementById('regenBtnLoading').style.display = 'inline-flex';
    return true;
}
</script>
<?php include '../../includes/footer.php'; ?>