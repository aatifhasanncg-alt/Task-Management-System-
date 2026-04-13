<?php
/**
 * bulk_import.php
 * Handles Excel upload for bulk company insert / update.
 * Called via POST from add.php bulk-upload form.
 * Requires PhpSpreadsheet: composer require phpoffice/phpspreadsheet
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

if (!isCoreAdmin()) {
    setFlash('error', 'Access denied.');
    header('Location: index.php'); exit;
}

verifyCsrf();

$db   = getDB();
$user = currentUser();

// ── Helpers ───────────────────────────────────────────────────────────────────
function cellVal($sheet, int $row, int $col): string {
    $val = $sheet->getCellByColumnAndRow($col, $row)->getValue();
    return trim((string)($val ?? ''));
}

// ── Load lookup maps (name → id) ─────────────────────────────────────────────
$typeMap     = [];
$branchMap   = [];
$industryMap = [];

foreach ($db->query("SELECT id, type_name FROM company_types")->fetchAll() as $r)
    $typeMap[strtolower($r['type_name'])] = $r['id'];

foreach ($db->query("SELECT id, branch_name FROM branches WHERE is_active=1")->fetchAll() as $r)
    $branchMap[strtolower($r['branch_name'])] = $r['id'];

foreach ($db->query("SELECT id, industry_name FROM industries WHERE is_active=1")->fetchAll() as $r)
    $industryMap[strtolower($r['industry_name'])] = $r['id'];

// ── Validate upload ───────────────────────────────────────────────────────────
if (empty($_FILES['bulk_file']['tmp_name'])) {
    setFlash('error', 'No file uploaded.');
    header('Location: add.php'); exit;
}

$file    = $_FILES['bulk_file'];
$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['xlsx', 'xls', 'csv'];

if (!in_array($ext, $allowed)) {
    setFlash('error', 'Invalid file type. Please upload .xlsx, .xls, or .csv');
    header('Location: add.php'); exit;
}

if ($file['size'] > 5 * 1024 * 1024) {
    setFlash('error', 'File too large. Max 5 MB.');
    header('Location: add.php'); exit;
}

// ── Load PhpSpreadsheet ───────────────────────────────────────────────────────
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoload)) {
    setFlash('error', 'PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet');
    header('Location: add.php'); exit;
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\IOFactory;

try {
    $spreadsheet = IOFactory::load($file['tmp_name']);
} catch (Exception $e) {
    setFlash('error', 'Could not read file: ' . htmlspecialchars($e->getMessage()));
    header('Location: add.php'); exit;
}

$sheet    = $spreadsheet->getSheetByName('Companies') ?? $spreadsheet->getActiveSheet();
$highRow  = $sheet->getHighestDataRow();
$maxRows  = 500;

// Column map (1-based): matches template headers
// 1=company_name, 2=company_code, 3=pan_number, 4=reg_number,
// 5=company_type, 6=branch_name, 7=return_type, 8=industry_name,
// 9=contact_person, 10=contact_phone, 11=contact_email, 12=address
define('COL_NAME',    1);
define('COL_CODE',    2);
define('COL_PAN',     3);
define('COL_REG',     4);
define('COL_TYPE',    5);
define('COL_BRANCH',  6);
define('COL_RETURN',  7);
define('COL_IND',     8);
define('COL_CPERSON', 9);
define('COL_CPHONE',  10);
define('COL_CEMAIL',  11);
define('COL_ADDR',    12);

// ── Process rows ──────────────────────────────────────────────────────────────
$inserted  = 0;
$updated   = 0;
$skipped   = 0;
$rowErrors = [];
$processed = 0;

// Get next code counter base
$maxCodeNum = (int)$db->query("
    SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(company_code,'-',-1) AS UNSIGNED)),0)
    FROM companies
")->fetchColumn();

for ($row = 2; $row <= $highRow; $row++) {
    $name = cellVal($sheet, $row, COL_NAME);
    if ($name === '' || strtolower($name) === 'company name *') continue; // skip blank/header

    if (++$processed > $maxRows) {
        $rowErrors[] = "Row {$row}: Stopped — exceeded {$maxRows} row limit.";
        break;
    }

    $code       = cellVal($sheet, $row, COL_CODE);
    $pan        = cellVal($sheet, $row, COL_PAN);
    $reg        = cellVal($sheet, $row, COL_REG);
    $typeName   = cellVal($sheet, $row, COL_TYPE);
    $branchName = cellVal($sheet, $row, COL_BRANCH);
    $returnType = cellVal($sheet, $row, COL_RETURN);
    $indName    = cellVal($sheet, $row, COL_IND);
    $cPerson    = cellVal($sheet, $row, COL_CPERSON);
    $cPhone     = cellVal($sheet, $row, COL_CPHONE);
    $cEmail     = cellVal($sheet, $row, COL_CEMAIL);
    $addr       = cellVal($sheet, $row, COL_ADDR);

    $errs = [];

    // Validate name length
    if (strlen($name) > 200) $errs[] = 'Company name too long (max 200).';

    // Validate PAN
    if ($pan !== '' && !preg_match('/^\d{10}$/', $pan))
        $errs[] = 'PAN must be exactly 10 digits.';

    // Validate email
    if ($cEmail !== '' && !filter_var($cEmail, FILTER_VALIDATE_EMAIL))
        $errs[] = 'Invalid email format.';

    // Resolve FK lookups
    $typeId     = $typeName   !== '' ? ($typeMap[strtolower($typeName)]     ?? null) : null;
    $branchId   = $branchName !== '' ? ($branchMap[strtolower($branchName)] ?? null) : null;
    $industryId = $indName    !== '' ? ($industryMap[strtolower($indName)]  ?? null) : null;

    if ($typeName   !== '' && $typeId   === null) $errs[] = "Unknown company type: \"{$typeName}\".";
    if ($branchName !== '' && $branchId === null) $errs[] = "Unknown branch: \"{$branchName}\".";
    if ($indName    !== '' && $industryId === null) $errs[] = "Unknown industry: \"{$indName}\".";

    if ($errs) {
        $rowErrors[] = "Row {$row} (\"{$name}\"): " . implode(' ', $errs);
        $skipped++;
        continue;
    }

    // ── UPDATE path: company_code provided ───────────────────────────────────
    if ($code !== '') {
        $existing = $db->prepare("SELECT id FROM companies WHERE company_code = ? AND is_active = 1");
        $existing->execute([$code]);
        $existingId = $existing->fetchColumn();

        if (!$existingId) {
            $rowErrors[] = "Row {$row}: Company code \"{$code}\" not found — skipped.";
            $skipped++;
            continue;
        }

        // Check PAN uniqueness (exclude self)
        if ($pan !== '') {
            $panChk = $db->prepare("SELECT id FROM companies WHERE pan_number=? AND is_active=1 AND id != ?");
            $panChk->execute([$pan, $existingId]);
            if ($panChk->fetch()) {
                $rowErrors[] = "Row {$row}: PAN \"{$pan}\" already used by another company — skipped.";
                $skipped++;
                continue;
            }
        }

        $db->prepare("
            UPDATE companies SET
                company_name    = ?,
                pan_number      = ?,
                reg_number      = ?,
                company_type_id = ?,
                branch_id       = ?,
                return_type     = ?,
                industry_id     = ?,
                contact_person  = ?,
                contact_phone   = ?,
                contact_email   = ?,
                address         = ?,
                updated_at      = NOW()
            WHERE id = ?
        ")->execute([
            $name,
            $pan   ?: null,
            $reg   ?: null,
            $typeId,
            $branchId,
            $returnType ?: null,
            $industryId,
            $cPerson ?: null,
            $cPhone  ?: null,
            $cEmail  ?: null,
            $addr    ?: null,
            $existingId,
        ]);
        $updated++;

    } else {
        // ── INSERT path ───────────────────────────────────────────────────────
        // Duplicate name check
        $nameChk = $db->prepare("SELECT id FROM companies WHERE company_name=? AND is_active=1");
        $nameChk->execute([$name]);
        if ($nameChk->fetch()) {
            $rowErrors[] = "Row {$row}: Company \"{$name}\" already exists — skipped.";
            $skipped++;
            continue;
        }

        // Duplicate PAN check
        if ($pan !== '') {
            $panChk = $db->prepare("SELECT id FROM companies WHERE pan_number=? AND is_active=1");
            $panChk->execute([$pan]);
            if ($panChk->fetch()) {
                $rowErrors[] = "Row {$row}: PAN \"{$pan}\" already used — skipped.";
                $skipped++;
                continue;
            }
        }

        // Auto-generate code
        $maxCodeNum++;
        $autoCode = 'CP-' . str_pad($maxCodeNum, 3, '0', STR_PAD_LEFT);

        $db->prepare("
            INSERT INTO companies
            (company_name, company_code, pan_number, reg_number,
             company_type_id, branch_id, return_type, industry_id,
             contact_person, contact_phone, contact_email,
             address, added_by, is_active, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())
        ")->execute([
            $name, $autoCode,
            $pan  ?: null,
            $reg  ?: null,
            $typeId,
            $branchId,
            $returnType ?: null,
            $industryId,
            $cPerson ?: null,
            $cPhone  ?: null,
            $cEmail  ?: null,
            $addr    ?: null,
            $user['id'],
        ]);
        $inserted++;
    }
}

// ── Log & flash ───────────────────────────────────────────────────────────────
$summary = "Bulk import done — {$inserted} inserted, {$updated} updated, {$skipped} skipped.";
logActivity($summary, 'companies');

if ($rowErrors) {
    $_SESSION['bulk_errors'] = $rowErrors;
}

setFlash('success', $summary);
header('Location: add.php?bulk=1');
exit;