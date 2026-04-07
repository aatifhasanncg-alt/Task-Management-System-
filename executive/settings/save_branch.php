<?php
require_once '../../config/db.php';

$db = getDB();

$stmt = $db->prepare("
    INSERT INTO branches 
    (branch_name, city, address, phone, email, is_head_office, is_active)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$stmt->execute([
    $_POST['branch_name'],
    $_POST['city'] ?? null,
    $_POST['address'] ?? null,
    $_POST['phone'] ?? null,
    $_POST['email'] ?? null,
    $_POST['is_head_office'] ?? 0,
    $_POST['is_active'] ?? 1
]);

header("Location: branch.php");
exit;