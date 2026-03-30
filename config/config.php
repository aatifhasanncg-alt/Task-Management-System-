<<<<<<< HEAD
<?php
// config/config.php — App-wide constants
require_once __DIR__ . '/notify.php';

define('APP_NAME',    'MISPro');
define('ORG_NAME',    'ASK Global Advisory Pvt. Ltd.');
define('ORG_TAGLINE', 'At ASK business problems end, solutions begin');
define('ORG_EMAIL',   'askglobaladvisory@gmail.com');
define('APP_URL',     'http://localhost/mis');
define('APP_VERSION', '1.0.0');


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
]);

// ── Email / SMTP ──────────────────────────────────────────────
define('MAIL_FROM',      'askglobaladvisory@gmail.com');
define('MAIL_FROM_NAME', 'ASK Global Advisory MIS');
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USER',      'askglobaladvisory@gmail.com');
define('SMTP_PASS',      'uwrv gcad iung ajmo');  // 16-char app password
define('SMTP_SECURE',    'tls');

// config/config.php — App-wide constants
require_once __DIR__ . '/notify.php';

define('APP_NAME',    'MISPro');
define('ORG_NAME',    'ASK Global Advisory Pvt. Ltd.');
define('ORG_TAGLINE', 'At ASK business problems end, solutions begin');
define('ORG_EMAIL',   'askglobaladvisory@gmail.com');
define('APP_URL',     'http://localhost/mis');
define('APP_VERSION', '1.0.0');


// Auto-include fiscal year helper — available everywhere after config.php loads
// No need to require it individually in any page.
require_once __DIR__ . '/helper.php';

// ── Upload settings ───────────────────────────────────────────────────────────
define('UPLOAD_PATH',         __DIR__ . '/../uploads/');
define('UPLOAD_URL',          APP_URL . '/uploads/');
define('MAX_FILE_SIZE',       5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS',  ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png']);

// ── Task statuses — must match task_status.status_name in DB exactly ──────────
define('TASK_STATUSES', [
    'Not Started'     => ['label' => 'Not Started',     'color' => '#9ca3af', 'bg' => '#f3f4f6'],
    'HBC'             => ['label' => 'HBC',             'color' => '#3b82f6', 'bg' => '#eff6ff'],
    'WIP'             => ['label' => 'WIP',             'color' => '#f59e0b', 'bg' => '#fffbeb'],
    'Pending'         => ['label' => 'Pending',         'color' => '#ef4444', 'bg' => '#fef2f2'],
    'Next Year'       => ['label' => 'Next Year',       'color' => '#8b5cf6', 'bg' => '#f5f3ff'],
    'Corporate Team'  => ['label' => 'Corporate Team',  'color' => '#06b6d4', 'bg' => '#ecfeff'],
    'NON Performance' => ['label' => 'NON Performance', 'color' => '#ec4899', 'bg' => '#fdf2f8'],
    'Done'            => ['label' => 'Done',            'color' => '#10b981', 'bg' => '#ecfdf5'],
]);

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
]);

// ── Email / SMTP ──────────────────────────────────────────────
define('MAIL_FROM',      'askglobaladvisory@gmail.com');
define('MAIL_FROM_NAME', 'ASK Global Advisory MIS');
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USER',      'askglobaladvisory@gmail.com');
define('SMTP_PASS',      'uwrv gcad iung ajmo');  // 16-char app password
define('SMTP_SECURE',    'tls');

