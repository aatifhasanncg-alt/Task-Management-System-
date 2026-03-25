<?php
// ajax/get_staff_by_admin.php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';


header('Content-Type: application/json');

$branchId = (int)($_GET['branch_id'] ?? 0);
$deptId   = (int)($_GET['dept_id'] ?? 0);
$user     = currentUser();

$where  = ["u.role_id='3'", "u.is_active=1"];
$params = [];

if (!isExecutive()) {
    $where[]  = 'u.managed_by = ?';
    $params[] = $user['id'];
}

if ($branchId) {
    $where[]  = 'u.branch_id = ?';
    $params[] = $branchId;
}

if ($deptId) {
    $where[]  = 'u.department_id = ?';
    $params[] = $deptId;
}

$db = getDB();

$st = $db->prepare("
    SELECT u.id, u.full_name, u.employee_id
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY u.full_name
");

$st->execute($params);

// ✅ FETCH DATA (this was missing)
$data = $st->fetchAll(PDO::FETCH_ASSOC);

// ✅ return JSON
echo json_encode(array_map(function($u) {
    return [
        'id' => $u['id'],
        'full_name' => $u['full_name'],
        'employee_id' => $u['employee_id'] ?? ''
    ];
}, $data));