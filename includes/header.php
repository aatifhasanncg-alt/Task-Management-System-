<?php
// includes/header.php
if (!defined('APP_URL')) {
    require_once __DIR__ . '/../config/config.php';
}
$pageTitle = $pageTitle ?? 'MISPro';
$__user = isset($_SESSION['user_id']) ? (function () {
    try {
        return currentUser(); } catch (Exception $e) {
        return null; } })() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1.0" />
    <meta name="csrf" content="<?= csrfToken() ?>" />
    <title><?= htmlspecialchars($pageTitle) ?> — MISPro</title>
    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Outfit:wght@300;400;500;600;700&display=swap"
        rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet" />
    <link href="<?= APP_URL ?>../assets/css/style.css" rel="stylesheet" />
    <link href="<?= APP_URL ?>../assets/css/datatables.custom.css" rel="stylesheet" />
    <link href="<?= APP_URL ?>../assets/css/dashboard.css" rel="stylesheet" />
    <script>
        window.APP_URL = '<?= APP_URL ?>';
        window.APP_USER_ID = <?= $__user['id'] ?? 0 ?>;
        window.APP_ROLE = '<?= htmlspecialchars($__user['role'] ?? 'admin') ?>';
    </script>
 
</head>

<body>
    <?php if ($flash = getFlash()): ?>
        <div class="flash-banner flash-<?= $flash['type'] ?> auto-dismiss">
            <i class="fas fa-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <?= htmlspecialchars($flash['msg'] ?? '') ?>
            <button onclick="this.parentElement.remove()"
                style="background:none;border:none;cursor:pointer;float:right;">&times;</button>
        </div>
    <?php endif; ?>