<?php
// config/config.php — App-wide constants
require_once __DIR__ . '/notify.php';
date_default_timezone_set('Asia/Kathmandu');
define('APP_NAME',    'MISPro');
define('ORG_NAME',    'ASK Global Advisory Pvt. Ltd.');
define('ORG_TAGLINE', 'At ASK business problems end, solutions begin');
define('ORG_EMAIL',   'askglobaladvisory@gmail.com');
define('APP_URL',     'https://task.askglobaladvisory.com');
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
define('SMTP_PASS',      'fxnb ycqd niwu gtak');  // 16-char app password
define('SMTP_SECURE',    'tls');

