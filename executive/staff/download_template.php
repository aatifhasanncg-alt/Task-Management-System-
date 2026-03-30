<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="staff_import_template.csv"');
header('Cache-Control: no-cache');

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, [
    'full_name',
    'username',
    'email',
    'phone',
    'password',
    'role_name',
    'branch_name',
    'dept_name',
    'joining_date',
    'address',
    'emergency_contact',
]);

// Sample rows
fputcsv($out, [
    'Ram Sharma',
    'ramsharma',
    'ram@askglobal.com.np',
    '9841000001',
    'Welcome@123',
    'staff',
    'Hetauda Branch',
    'Tax',
    '2081-04-01',
    'Hetauda-5',
    '9841000002',
]);
fputcsv($out, [
    'Sita Thapa',
    'sitathapa',
    'sita@askglobal.com.np',
    '9841000003',
    'Welcome@123',
    'admin',
    'Simara Branch',
    'Retail',
    '2081-04-15',
    'Simara-3',
    '9841000004',
]);

fclose($out);