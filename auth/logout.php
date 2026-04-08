<?php
// auth/logout.php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/auth_token.php';  // ✅ ADD THIS
require_once '../config/session.php';

if (!empty($_SESSION['user_id'])) {
    logActivity('Logout', 'auth');
}

clearRememberToken();   // ✅ ADD THIS — deletes DB token + clears cookie

session_unset();        // ✅ USE BOTH unset() AND destroy()
session_destroy();

header('Location: ' . APP_URL . '/auth/login.php');
exit;