<?php
// ajax/get_companies.php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';
requireAnyRole();
header('Content-Type: application/json');

$branchId = (int)($_GET['branch_id'] ?? 0);
$deptId   = (int)($_GET['dept_id']   ?? 0);
$search   = trim($_GET['search'] ?? '');

$where  = ['is_active=1'];
$params = [];
if ($branchId) { $where[] = 'branch_id=?'; $params[] = $branchId; }
if ($deptId)   { $where[] = 'department_id=?'; $params[] = $deptId; }
if ($search)   { $where[] = 'company_name LIKE ?'; $params[] = "%$search%"; }

$st = getDB()->prepare("SELECT id,company_name,pan_number,company_type FROM companies WHERE " . implode(' AND ',$where) . " ORDER BY company_name LIMIT 50");
$st->execute($params);
echo json_encode($st->fetchAll());