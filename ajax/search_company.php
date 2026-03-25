<?php
require_once '../config/db.php';

$db = getDB();

$q = trim($_GET['q'] ?? '');

if (!$q) {
    echo json_encode([]);
    exit;
}

$stmt = $db->prepare("
    SELECT id, company_name, pan_no, company_code
    FROM companies
    WHERE is_active = 1
      AND (
            company_name LIKE ?
         OR pan_no LIKE ?
         OR company_code LIKE ?
      )
    LIMIT 10
");

$search = "%{$q}%";
$stmt->execute([$search, $search, $search]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));