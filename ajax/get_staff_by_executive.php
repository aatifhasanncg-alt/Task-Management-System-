<?php
// ajax/get_staff_by_executive.php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

$branchId = (int)($_GET['branch_id'] ?? 0);
$deptId   = (int)($_GET['dept_id']   ?? 0);
$user     = currentUser();

if (!$user || !(isExecutive() || isManager())) {
    echo json_encode([]);
    exit;
}

if (!$branchId || !$deptId) {
    echo json_encode([]);
    exit;
}

$db = getDB();

$deptNameStmt = $db->prepare("SELECT dept_name FROM departments WHERE id = ?");
$deptNameStmt->execute([$deptId]);
$requestedDeptName = $deptNameStmt->fetchColumn() ?: 'Unknown';

// Executive: ALWAYS filter by both branch and department (no UDA needed —
// executive is assigning fresh, not constrained by their own dept/UDA).
$st = $db->prepare("
    SELECT
        u.id,
        u.full_name,
        u.employee_id,
        b.branch_name,
        COALESCE(d.dept_name, ?) AS dept_name,
        d.dept_code              AS dept_code
    FROM users u
    LEFT JOIN branches    b ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    JOIN  roles           r ON r.id = u.role_id
    WHERE r.role_name IN ('staff','admin','manager')
      AND u.is_active   = 1
      AND u.branch_id   = ?
      AND u.department_id = ?
    ORDER BY u.full_name ASC
");
$st->execute([$requestedDeptName, $branchId, $deptId]);

$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$result = [];
foreach ($rows as $row) {
    $result[] = [
        'id'          => $row['id'],
        'full_name'   => $row['full_name'],
        'employee_id' => $row['employee_id'] ?? '',
        'dept_name'   => $row['dept_name'] ?? '',
        'dept_code'   => $row['dept_code'] ?? '',
        'branch_name' => $row['branch_name'] ?? '',
    ];
}

echo json_encode($result);