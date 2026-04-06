<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied.');
    header('Location: industry.php'); exit;
}

$db     = getDB();
$id     = (int)($_POST['id'] ?? 0);
$name   = trim($_POST['industry_name'] ?? '');
$active = (int)($_POST['is_active'] ?? 1);

$dup = $db->prepare("SELECT id FROM industries WHERE industry_name = ? AND id != ?");
$dup->execute([$name, $id]);
if ($dup->fetch()) {
    setFlash('error', "Industry \"{$name}\" already exists.");
    header('Location: industries.php'); exit;
}

$db->prepare("UPDATE industries SET industry_name = ?, is_active = ? WHERE id = ?")
   ->execute([$name, $active, $id]);

logActivity("Updated industry ID {$id} → {$name}", 'industries');
setFlash('success', "Industry \"{$name}\" updated.");
header('Location: industries.php');
exit;