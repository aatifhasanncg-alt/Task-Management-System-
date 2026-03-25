<?php
// auth/logout.php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';

if (!empty($_SESSION['user_id'])) {
    logActivity('Logout', 'auth');
}
session_destroy();
header('Location: ' . APP_URL . '/auth/login.php');
exit;