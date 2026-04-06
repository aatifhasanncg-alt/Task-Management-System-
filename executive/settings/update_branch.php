<?php
require_once '../../config/db.php';
requireExecutive();
if (!isCoreAdmin()) {
    setFlash('error', 'Access denied.');
    header('Location: industries.php'); exit;
}
$db = getDB();

$stmt = $db->prepare("
    UPDATE branches SET
        branch_name=?,
        city=?,
        address=?,
        phone=?,
        email=?,
        is_head_office=?,
        is_active=?
    WHERE id=?
");

$stmt->execute([
    $_POST['branch_name'],
    $_POST['city'] ?? null,
    $_POST['address'] ?? null,
    $_POST['phone'] ?? null,
    $_POST['email'] ?? null,
    $_POST['is_head_office'] ?? 0,
    $_POST['is_active'] ?? 1,
    $_POST['id']
]);

header("Location: branch.php");
exit;