<?php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';
requireManager();

$db = getDB();
$branchId = (int)($_GET['branch_id'] ?? 0);
if (!$branchId) { echo json_encode([]); exit; }

$stmt = $db->prepare("
    SELECT id, company_name,
           COALESCE(pan_number,'') AS pan_number,
           COALESCE(company_code,'') AS company_code
    FROM companies
    WHERE is_active=1 AND branch_id=?
    ORDER BY company_name
");
$stmt->execute([$branchId]);
header('Content-Type: application/json');
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));