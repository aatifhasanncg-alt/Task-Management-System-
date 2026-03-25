<?php
require_once '../config/db.php';

$db = getDB();

$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$id]);

echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));