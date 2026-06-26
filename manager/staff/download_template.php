<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();

// ── Fetch live branches and departments from DB ────────────────────────────────
$branches = $db->query("SELECT branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")
                ->fetchAll(PDO::FETCH_COLUMN);
$depts    = $db->query("SELECT dept_name FROM departments WHERE is_active=1 ORDER BY dept_name")
                ->fetchAll(PDO::FETCH_COLUMN);

// Build "(must be one of: A / B / C)"-style hints from real, current data.
// Falls back to a generic note if the tables are empty so the template never breaks.
$branchHint = $branches
    ? '(must match exactly — one of: ' . implode(' / ', $branches) . ')'
    : '(must match an active branch name exactly)';

$deptHint = $depts
    ? '(primary dept — one of: ' . implode(' / ', $depts) . ')'
    : '(must match an active department name exactly)';

$extraDeptHint = $depts
    ? '(optional, comma-separated — from: ' . implode(' / ', $depts) . ')'
    : '(optional, comma-separated department names)';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="staff_import_template.csv"');
header('Cache-Control: no-cache');

$out = fopen('php://output', 'w');

// ── Header row ───────────────────────────────────────────────────────────────
fputcsv($out, [
    'full_name',            // A — required
    'username',             // B — required
    'email',                // C — required
    'phone',                // D
    'password',             // E — defaults to Welcome@123 if blank
    'role_name',            // F — staff / admin / executive
    'branch_name',          // G — must match exactly
    'dept_name',            // H — primary department
    'joining_date',         // I — YYYY-MM-DD
    'address',              // J
    'emergency_contact',    // K
    'extra_departments',    // L — comma-separated dept names e.g. "Tax,Retail"
    'extra_dept_managers',  // M — comma-separated manager emails matching extra_departments order
]);

// ── Hint row — placeholder guidance only, never real importable data ─────────
fputcsv($out, [
    '(e.g. Ram Sharma)',
    '(e.g. ramsharma)',
    '(e.g. name@askglobal.com.np)',
    '(e.g. 98XXXXXXXX)',
    '(blank = Welcome@123)',
    'staff / admin / executive',
    $branchHint,
    $deptHint,
    '(format: YYYY-MM-DD)',
    '(e.g. ward / city)',
    '(e.g. 98XXXXXXXX)',
    $extraDeptHint,
    '(optional, comma-separated manager emails matching extra_departments order)',
]);

// ── Sample row — illustrative placeholders, NOT real DB rows ─────────────────
fputcsv($out, [
    'Sample Name',
    'sampleuser',
    'sample.user@askglobal.com.np',
    '9800000000',
    'Welcome@123',
    'staff',
    '<' . 'YOUR_BRANCH_NAME' . '>',
    '<' . 'YOUR_DEPT_NAME' . '>',
    date('Y-m-d'),
    'Sample Address',
    '9800000001',
    '',   // extra_departments — leave blank unless assigning multiple depts
    '',   // extra_dept_managers
]);

// ── Second sample row — demonstrates the extra-department (UDA) columns ──────
fputcsv($out, [
    'Sample Name 2',
    'sampleuser2',
    'sample.user2@askglobal.com.np',
    '9800000002',
    'Welcome@123',
    'admin',
    '<' . 'YOUR_BRANCH_NAME' . '>',
    '<' . 'YOUR_DEPT_NAME' . '>',
    date('Y-m-d'),
    'Sample Address 2',
    '9800000003',
    '<' . 'EXTRA_DEPT_NAME' . '>',          // e.g. a second department this user also has access to
    '<' . 'EXTRA_DEPT_MANAGER_EMAIL' . '>', // manager email for that extra department
]);

fclose($out);