<?php
require_once '../../config/db.php';

$db = getDB();

$id = $_POST['id'] ?? null;
$fy_code = $_POST['fy_code'];
$fy_label = $_POST['fy_label'];
$start = $_POST['start_date'];
$end = $_POST['end_date'];
$is_active = $_POST['is_active'];
$is_current = $_POST['is_current'];

if ($is_current == 1) {
    $db->query("UPDATE fiscal_years SET is_current = 0");
}

if ($id) {
    $stmt = $db->prepare("UPDATE fiscal_years 
        SET fy_code=?, fy_label=?, start_date=?, end_date=?, is_active=?, is_current=? 
        WHERE id=?");
    $stmt->execute([$fy_code, $fy_label, $start, $end, $is_active, $is_current, $id]);
} else {
    $stmt = $db->prepare("INSERT INTO fiscal_years 
        (fy_code, fy_label, start_date, end_date, is_active, is_current) 
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$fy_code, $fy_label, $start, $end, $is_active, $is_current]);
}

header("Location: " . $_SERVER['HTTP_REFERER']);
exit;