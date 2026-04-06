<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied.');
    header('Location: industries.php'); exit;
}

verifyCsrf();

$db   = getDB();
$name = trim($_POST['industry_name'] ?? '');
$active = (int)($_POST['is_active'] ?? 1);

if (!$name) {
    setFlash('error', 'Industry name is required.');
    header('Location: industries.php'); exit;
}

$dup = $db->prepare("SELECT id FROM industries WHERE industry_name = ?");
$dup->execute([$name]);
if ($dup->fetch()) {
    setFlash('error', "Industry \"{$name}\" already exists.");
    header('Location: industries.php'); exit;
}

$db->prepare("INSERT INTO industries (industry_name, is_active) VALUES (?, ?)")
   ->execute([$name, $active]);

logActivity("Added industry: {$name}", 'industries');
setFlash('success', "Industry \"{$name}\" added.");
header('Location: industries.php');
exit;