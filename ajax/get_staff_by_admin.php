<?php
// ajax/get_staff_by_admin.php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

$branchId = (int)($_GET['branch_id'] ?? 0);
$deptId   = (int)($_GET['dept_id']   ?? 0);
$user     = currentUser();

if (!$user || !in_array($user['role_name'] ?? '', ['admin', 'executive'])) {
    echo json_encode([]);
    exit;
}

$db = getDB();

$myDeptStmt = $db->prepare("SELECT dept_code FROM departments WHERE id = ?");
$myDeptStmt->execute([$user['department_id'] ?? 0]);
$myDeptCode = $myDeptStmt->fetchColumn() ?: '';
$isCoreAdmin = ($myDeptCode === 'CORE');

$effectiveBranch = $branchId ?: (int)$user['branch_id'];
$effectiveDept   = $deptId   ?: (int)$user['department_id'];


// Primary: staff whose department_id matches the requested dept
// Secondary: staff whose UDA has the dept but primary dept is different
// CORE admin also allows NULL department_id users
$nullDeptClause = $isCoreAdmin ? "OR u.department_id IS NULL" : '';

// Also fetch the dept_name of the requested dept for labeling
$deptNameStmt = $db->prepare("SELECT dept_name FROM departments WHERE id = ?");
$deptNameStmt->execute([$effectiveDept]);
$requestedDeptName = $deptNameStmt->fetchColumn() ?: 'Unknown';

$st = $db->prepare("
    SELECT
        u.id,
        u.full_name,
        u.employee_id,
        b.branch_name,
        COALESCE(d.dept_name, ?)  AS dept_name,
        d.dept_code               AS primary_dept_code,
        NULL                      AS secondary_dept_name,
        'primary'                 AS match_type,
        (u.id = ?)                AS is_self
    FROM users u
    LEFT JOIN branches    b ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    JOIN  roles           r ON r.id = u.role_id
    WHERE r.role_name IN ('staff','admin')
      AND u.is_active = 1
      AND (u.department_id = ? {$nullDeptClause})

    UNION

    SELECT
        u.id,
        u.full_name,
        u.employee_id,
        b.branch_name,
        uda_d.dept_name      AS dept_name,
        d.dept_code          AS primary_dept_code,
        d.dept_name          AS secondary_dept_name,
        'secondary'          AS match_type,
        (u.id = ?)           AS is_self
    FROM users u
    LEFT JOIN branches    b     ON b.id = u.branch_id
    LEFT JOIN departments d     ON d.id = u.department_id
    JOIN  user_department_assignments uda  ON uda.user_id = u.id
    JOIN  departments uda_d ON uda_d.id = uda.department_id
    JOIN  roles       r     ON r.id = u.role_id
    WHERE r.role_name IN ('staff','admin')
      AND u.is_active       = 1
      AND uda.department_id = ?
      AND u.department_id  != ?

    ORDER BY dept_name ASC, full_name ASC
");
$st->execute([
    $requestedDeptName,         // COALESCE fallback label — primary leg
    (int)$user['id'],           // is_self — primary leg
    $effectiveDept,             // primary dept match
    (int)$user['id'],           // is_self — secondary leg
    $effectiveDept,             // uda dept match
    $effectiveDept,             // exclude those already in primary (dept_id != ?)
]);

$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Always prepend self ───────────────────────────────────────────────────────
$selfStmt = $db->prepare("
    SELECT u.id, u.full_name, u.employee_id,
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

if ($selfData) {
    $result[] = [
        'id'                  => $selfData['id'],
        'full_name'           => $selfData['full_name'],
        'employee_id'         => $selfData['employee_id'] ?? '',
        'dept_name'           => $selfData['dept_name'] ?? '',
        'dept_code'           => $myDeptCode,                 // ← add this
        'branch_name'         => $selfData['branch_name'] ?? '',
        'role_name'           => $selfData['role_name'] ?? '',
        'secondary_dept_name' => null,
        'match_type'          => 'self',
        'is_self'             => true,
    ];
}
foreach ($rows as $row) {
    if ((int)$row['id'] === (int)$user['id']) continue;

    $result[] = [
        'id'                  => $row['id'],
        'full_name'           => $row['full_name'],
        'employee_id'         => $row['employee_id'] ?? '',
        'dept_name'           => $row['dept_name'] ?? '',
        'dept_code'           => $row['primary_dept_code'] ?? '',   // ← add this
        'branch_name'         => $row['branch_name'] ?? '',
        'secondary_dept_name' => $row['secondary_dept_name'] ?: null,
        'match_type'          => $row['match_type'],
        'is_self'             => false,
    ];
}

echo json_encode($result);