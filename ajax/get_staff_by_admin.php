<?php
// ajax/get_staff_by_admin.php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';

header('Content-Type: application/json');

$branchId = (int) ($_GET['branch_id'] ?? 0);
$deptId = (int) ($_GET['dept_id'] ?? 0);
$user = currentUser();

if (!$user) {
    echo json_encode([]);
    exit;
}

$db = getDB();

// ── Detect current user's dept code ──────────────────────────────────────────
$myDeptStmt = $db->prepare("SELECT dept_code FROM departments WHERE id = ?");
$myDeptStmt->execute([$user['department_id'] ?? 0]);
$myDeptCode = $myDeptStmt->fetchColumn() ?: '';

$isCoreAdmin = ($myDeptCode === 'CORE');

// ── Build query ───────────────────────────────────────────────────────────────
// Roles to include: staff + admin (role_id 3 and 2 typically, but join on role_name is safer)
// Also include users with NULL department (unassigned/general users)
$params = [];
$conditions = ["u.is_active = 1"];

// Role filter: only staff and admin, not superadmin/executive
$conditions[] = "r.role_name IN ('staff', 'admin')";

if (isExecutive()) {
    // Executive sees everyone — only filter by branch/dept if explicitly passed
    if ($branchId) {
        $conditions[] = 'u.branch_id = ?';
        $params[] = $branchId;
    }
    if ($deptId > 0) {
        $conditions[] = '(u.department_id = ? OR EXISTS (
        SELECT 1 FROM user_department_assignments uda
        WHERE uda.user_id = u.id AND uda.department_id = ?
    ))';
        $params[] = $deptId;
        $params[] = $deptId;
    }
} elseif ($isCoreAdmin) {
    $conditions[] = 'u.branch_id = ?';
    $params[] = (int) $user['branch_id'];

    if ($deptId) {
        $conditions[] = '(u.department_id = ? OR u.department_id IS NULL OR EXISTS (
            SELECT 1 FROM user_department_assignments uda
            WHERE uda.user_id = u.id AND uda.department_id = ?
        ))';
        $params[] = $deptId;
        $params[] = $deptId;
    }
} else {
    $conditions[] = 'u.branch_id = ?';
    $params[] = (int) $user['branch_id'];

    $targetDept = $deptId > 0 ? $deptId : (int) $user['department_id'];
    $conditions[] = '(u.department_id = ? OR u.department_id IS NULL OR EXISTS (
        SELECT 1 FROM user_department_assignments uda
        WHERE uda.user_id = u.id AND uda.department_id = ?
    ))';
    $params[] = $targetDept;
    $params[] = $targetDept;
}

$whereClause = implode(' AND ', $conditions);

$st = $db->prepare("
    SELECT
        u.id,
        u.full_name,
        u.employee_id,
        u.branch_id,
        u.department_id,
        b.branch_name,
        d.dept_name,
        r.role_name
    FROM users u
    LEFT JOIN branches    b ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN roles       r ON r.id = u.role_id
    WHERE {$whereClause}
    ORDER BY d.dept_name ASC, u.full_name ASC
");
$st->execute($params);
$data = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Always include self (the logged-in admin) as first option ─────────────────
// Fetch current user's full info
$selfStmt = $db->prepare("
    SELECT u.id, u.full_name, u.employee_id, u.branch_id, u.department_id,
           b.branch_name, d.dept_name, r.role_name
    FROM users u
    LEFT JOIN branches    b ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN roles       r ON r.id = u.role_id
    WHERE u.id = ?
");
$selfStmt->execute([$user['id']]);
$selfData = $selfStmt->fetch(PDO::FETCH_ASSOC);

$result = [];

// Add self first (marked so JS can style it)
if ($selfData) {
    $result[] = [
        'id' => $selfData['id'],
        'full_name' => $selfData['full_name'],
        'employee_id' => $selfData['employee_id'] ?? '',
        'dept_name' => $selfData['dept_name'] ?? '',
        'branch_name' => $selfData['branch_name'] ?? '',
        'role_name' => $selfData['role_name'] ?? '',
        'is_self' => true,
    ];
}

// Add rest of staff (exclude self to avoid duplicate)
foreach ($data as $u) {
    if ((int) $u['id'] === (int) $user['id'])
        continue; // skip self, already added
    $result[] = [
        'id' => $u['id'],
        'full_name' => $u['full_name'],
        'employee_id' => $u['employee_id'] ?? '',
        'dept_name' => $u['dept_name'] ?? '',
        'branch_name' => $u['branch_name'] ?? '',
        'role_name' => $u['role_name'] ?? '',
        'is_self' => false,
    ];
}

echo json_encode($result);