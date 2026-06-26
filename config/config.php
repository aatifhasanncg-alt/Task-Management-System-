<?php
// config/config.php — App-wide constants
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/../secrets.php';
date_default_timezone_set('Asia/Kathmandu');
define('APP_NAME',    'TaskHub');
define('ORG_NAME',    'ASK Global Advisory Pvt. Ltd.');
define('ORG_TAGLINE', 'At ASK business problems end, solutions begin');
define('ORG_EMAIL',   'askglobaladvisorydemo@gmail.com');
define('APP_URL',     'http://localhost/mis/');
define('APP_VERSION', '1.0.0');

// ── Login security ──────────────────────────────────────────
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);
// Auto-include fiscal year helper — available everywhere after config.php loads
// No need to require it individually in any page.
require_once __DIR__ . '/helper.php';

// ── Upload settings ───────────────────────────────────────────────────────────
define('UPLOAD_PATH',         __DIR__ . '/../uploads/');
define('UPLOAD_URL',          APP_URL . '/uploads/');
define('MAX_FILE_SIZE',       5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS',  ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png']);


// ── Task priorities ───────────────────────────────────────────────────────────
define('TASK_PRIORITIES', [
    'low'    => ['label' => 'Low',    'color' => '#6b7280'],
    'medium' => ['label' => 'Medium', 'color' => '#f59e0b'],
    'high'   => ['label' => 'High',   'color' => '#ef4444'],
    'urgent' => ['label' => 'Urgent', 'color' => '#dc2626'],
]);

// ── Department → detail table mapping ────────────────────────────────────────
define('DEPT_DETAIL_TABLES', [
    'RETAIL' => 'task_retail',
    'TAX'    => 'task_tax',
    'BANK'   => 'task_banking',
    'FIN'    => 'task_finance',
    'CORP'   => 'task_corporate',
    'IT'     => 'task_it',
]);

// ── Email / SMTP ──────────────────────────────────────────────
define('MAIL_FROM',      'askglobaladvisorydemo@gmail.com');
define('MAIL_FROM_NAME', 'ASK Global Advisory TaskHub');

// Cross-department assignment permissions
// 'from_dept_code' => ['allowed_to_assign_dept_codes']
// config.php
// user_id => ['dept_codes they can assign to']
define('CROSS_DEPT_ASSIGN', [
    2 => ['TAX'],   // Samikshya Aryal — RETAIL admin
]);

function getDepartmentStaff(PDO $db, int $branchId, int $deptId): array
{
    $stmt = $db->prepare("
        SELECT DISTINCT 
            u.id,
            u.full_name,
            u.employee_id,

            GROUP_CONCAT(DISTINCT d.dept_name SEPARATOR ', ') AS dept_label

        FROM users u

        LEFT JOIN user_department_assignments uda 
            ON uda.user_id = u.id

        LEFT JOIN departments d 
            ON d.id = uda.department_id

        WHERE u.is_active = 1
          AND u.branch_id = ?
          AND (
                u.department_id = ?
                OR uda.department_id = ?
          )

        GROUP BY u.id, u.full_name, u.employee_id

        ORDER BY u.full_name
    ");

    $stmt->execute([$branchId, $deptId, $deptId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}