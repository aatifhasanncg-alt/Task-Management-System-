<?php
// ajax/snooze_pw_reminder.php — snooze password reminder for today
require_once __DIR__ . '/../config/session.php';
requireLogin();

// Set today's cache key to false so the modal won't show again this session
$_SESSION['pw_check_' . date('Y-m-d')] = false;

header('Content-Type: application/json');
echo json_encode(['ok' => true]);