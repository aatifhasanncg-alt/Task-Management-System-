<?php
/**
 * download_template.php
 * Dynamically generates the company bulk-import Excel template
 * with live data from the DB — so Valid Values never go stale.
 *
 * Requires PhpSpreadsheet: composer require phpoffice/phpspreadsheet
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    die('PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet');
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;

// ── Fetch live lookup data from DB ────────────────────────────────────────────
$db = getDB();

$types      = $db->query("SELECT type_name     FROM company_types             ORDER BY type_name"    )->fetchAll(PDO::FETCH_COLUMN);
$branches   = $db->query("SELECT branch_name   FROM branches   WHERE is_active=1 ORDER BY branch_name"  )->fetchAll(PDO::FETCH_COLUMN);
$industries = $db->query("SELECT industry_name FROM industries WHERE is_active=1 ORDER BY industry_name")->fetchAll(PDO::FETCH_COLUMN);
$returnTypes = ['N/A', 'D1', 'D2', 'D3', 'D4'];

// ── Build workbook ────────────────────────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setTitle('Company Bulk Import Template')
    ->setDescription('Generated ' . date('Y-m-d H:i:s') . ' — valid values pulled live from database.');

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 1 — Valid Values  (hidden reference sheet, built first so we can name ranges)
// ══════════════════════════════════════════════════════════════════════════════
$valSheet = $spreadsheet->getActiveSheet();
$valSheet->setTitle('Valid Values');

// Helper: write a column of values + return last row used
$writeCol = function(string $header, array $values, int $col) use ($valSheet): int {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);

    // Header cell
    $valSheet->setCellValue("{$colLetter}1", $header);
    $valSheet->getStyle("{$colLetter}1")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9, 'name' => 'Arial'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4B5563']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    foreach ($values as $i => $val) {
        $row = $i + 2;
        $valSheet->setCellValue("{$colLetter}{$row}", $val);
        $valSheet->getStyle("{$colLetter}{$row}")->applyFromArray([
            'font'      => ['size' => 9, 'name' => 'Arial'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
    }

    $valSheet->getColumnDimension($colLetter)->setWidth(22);
    return count($values) + 1; // last used row
};

$lastTypeRow     = $writeCol('Company Types *',  $types,       1);
$lastBranchRow   = $writeCol('Branches *',        $branches,    2);
$lastIndRow      = $writeCol('Industries',        $industries,  3);
$lastReturnRow   = $writeCol('Return Types',      $returnTypes, 4);

// Named ranges for dropdown formulas (absolute references)
$spreadsheet->addNamedRange(new NamedRange('TypeList',     $valSheet, '$A$2:$A$' . $lastTypeRow));
$spreadsheet->addNamedRange(new NamedRange('BranchList',   $valSheet, '$B$2:$B$' . $lastBranchRow));
$spreadsheet->addNamedRange(new NamedRange('IndustryList', $valSheet, '$C$2:$C$' . $lastIndRow));
$spreadsheet->addNamedRange(new NamedRange('ReturnList',   $valSheet, '$D$2:$D$' . $lastReturnRow));

// Add a "last updated" note
$note = 'Valid values generated: ' . date('Y-m-d H:i:s') . '. Re-download template after adding branches, types, or industries.';
$valSheet->setCellValue('F1', $note);
$valSheet->getStyle('F1')->applyFromArray([
    'font'      => ['italic' => true, 'color' => ['rgb' => '9CA3AF'], 'size' => 8, 'name' => 'Arial'],
]);
$valSheet->getColumnDimension('F')->setWidth(70);

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 2 — Companies  (the data entry sheet)
// ══════════════════════════════════════════════════════════════════════════════
$dataSheet = $spreadsheet->createSheet();
$dataSheet->setTitle('Companies');
$spreadsheet->setActiveSheetIndex(1); // open on Companies tab

// Column definitions: [header, width, hint, required]
$columns = [
    1  => ['Company Name',     30, 'Full legal name',                       true],
    2  => ['Company Code',     16, 'Leave blank to auto-insert; fill to UPDATE', false],
    3  => ['PAN Number',       14, '9 digits only, e.g. 123456789',         false],
    4  => ['Reg Number',       18, 'Registration / VAT number',             false],
    5  => ['Company Type',     20, 'Must match Valid Values sheet',         true],
    6  => ['Branch Name',      20, 'Must match Valid Values sheet',         true],
    7  => ['Return Type',      14, 'N/A, D1–D4',                           false],
    8  => ['Industry',         20, 'Must match Valid Values sheet',         false],
    9  => ['Contact Person',   22, 'Primary contact name',                  false],
    10 => ['Contact Phone',    18, 'Phone number',                          false],
    11 => ['Contact Email',    26, 'Valid email address',                   false],
    12 => ['Address',          30, 'Street / full address',                 false],
];

// ── Header row styling ────────────────────────────────────────────────────────
$headerStyle = [
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10, 'name' => 'Arial'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C9A84C']], // gold
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'A07830']]],
];

$requiredMark = [
    'font' => ['color' => ['rgb' => 'FF0000'], 'bold' => true],
];

foreach ($columns as $col => [$header, $width, $hint, $required]) {
    $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
    $cell   = "{$letter}1";

    $dataSheet->setCellValue($cell, $header . ($required ? ' *' : ''));
    $dataSheet->getStyle($cell)->applyFromArray($headerStyle);
    $dataSheet->getColumnDimension($letter)->setWidth($width);

    // Tooltip comment on header
    $dataSheet->getComment($cell)->getText()->createTextRun($hint);
    $dataSheet->getComment($cell)->setWidth('180pt');
    $dataSheet->getComment($cell)->setHeight('40pt');
}

$dataSheet->getRowDimension(1)->setRowHeight(28);
$dataSheet->freezePane('A2');

// ── Sample rows (2 rows: one insert, one update placeholder) ─────────────────
$samples = [
    2 => [
        'Acme Trading Pvt Ltd', '',              '987654321', 'REG-001',
        $types[0]   ?? '',  $branches[0] ?? '',  'D1',
        $industries[0] ?? '', 'Ram Sharma',     '9841000000',
        'ram@acme.com.np',    'Kathmandu, Nepal',
    ],
    3 => [
        'Update Me Ltd',        'CP-002',         '',          '',
        $types[1]   ?? '',  $branches[1] ?? '',  'D2',
        $industries[1] ?? '', '',                '',
        '',                   '',
    ],
];

$sampleStyle = [
    'font'      => ['color' => ['rgb' => '6B7280'], 'italic' => true, 'size' => 9, 'name' => 'Arial'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
];

foreach ($samples as $row => $values) {
    foreach ($values as $i => $val) {
        $col    = $i + 1;
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $dataSheet->setCellValue("{$letter}{$row}", $val);
    }
    $dataSheet->getStyle('A' . $row . ':L' . $row)->applyFromArray($sampleStyle);
}

// ── Data rows: styling + dropdowns for rows 2–501 ────────────────────────────
$dataRowStyle = [
    'font'      => ['size' => 9, 'name' => 'Arial'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
];
$dataSheet->getStyle('A2:L501')->applyFromArray($dataRowStyle);

// Helper: apply dropdown validation
$addDropdown = function(string $colLetter, string $namedRange) use ($dataSheet): void {
    $validation = $dataSheet->getCell("{$colLetter}2")->getDataValidation();
    $validation->setType(DataValidation::TYPE_LIST);
    $validation->setErrorStyle(DataValidation::STYLE_STOP);
    $validation->setAllowBlank(true);
    $validation->setShowDropDown(false); // true = hide arrow (counter-intuitive); false = show arrow
    $validation->setShowErrorMessage(true);
    $validation->setErrorTitle('Invalid value');
    $validation->setError("Please select a value from the list (see 'Valid Values' sheet).");
    $validation->setFormula1($namedRange);

    // Clone to all data rows
    for ($row = 3; $row <= 501; $row++) {
        $dataSheet->getCell("{$colLetter}{$row}")
            ->setDataValidation(clone $validation);
    }
};

$addDropdown('E', 'TypeList');
$addDropdown('F', 'BranchList');
$addDropdown('G', 'ReturnList');
$addDropdown('H', 'IndustryList');

// ── Legend row below headers ──────────────────────────────────────────────────
// (skip — hints in comments are sufficient)

// ── Freeze + auto-filter ──────────────────────────────────────────────────────
$dataSheet->setAutoFilter('A1:L1');

// ── Instructions box (merged cell above headers) ─────────────────────────────
// Shift everything down by adding an instructions row at top is messy;
// instead add a note in a separate "Instructions" sheet.

// ══════════════════════════════════════════════════════════════════════════════
// SHEET 3 — Instructions
// ══════════════════════════════════════════════════════════════════════════════
$instrSheet = $spreadsheet->createSheet();
$instrSheet->setTitle('Instructions');

$instrSheet->getColumnDimension('A')->setWidth(5);
$instrSheet->getColumnDimension('B')->setWidth(90);

$instrSheet->mergeCells('B1:B1');
$instrSheet->setCellValue('B1', 'Company Bulk Import — How To Use');
$instrSheet->getStyle('B1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'C9A84C'], 'name' => 'Arial'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
]);
$instrSheet->getRowDimension(1)->setRowHeight(28);

$instructions = [
    '',
    '── INSERTING NEW COMPANIES ──────────────────────────────────',
    '  • Leave "Company Code" blank — it will be auto-generated (CP-001, CP-002 …)',
    '  • "Company Name" must not already exist in the system.',
    '  • PAN Number (if provided) must be exactly 9 digits and unique.',
    '',
    '── UPDATING EXISTING COMPANIES ──────────────────────────────',
    '  • Enter the exact Company Code (e.g. CP-007) in the "Company Code" column.',
    '  • All non-blank fields in the row will overwrite existing data.',
    '  • The company must exist and be active in the system.',
    '',
    '── YOU CAN MIX INSERT & UPDATE ROWS IN ONE FILE ─────────────',
    '',
    '── DROPDOWN COLUMNS ─────────────────────────────────────────',
    '  • Company Type, Branch Name, Industry, Return Type have dropdown lists.',
    '  • Values are pulled LIVE from the database at the time you downloaded this file.',
    '  • If a branch or type was added after you downloaded, re-download the template.',
    '',
    '── COLUMN RULES ─────────────────────────────────────────────',
    '  • Company Name    — Required. Max 200 characters.',
    '  • Company Type    — Required. Must match dropdown exactly.',
    '  • Branch Name     — Required. Must match dropdown exactly.',
    '  • PAN Number      — Optional. Exactly 9 digits if provided.',
    '  • Contact Email   — Optional. Must be a valid email format.',
    '  • Return Type     — Optional. One of: N/A, D1, D2, D3, D4.',
    '',
    '── FILE LIMITS ──────────────────────────────────────────────',
    '  • Maximum 500 data rows per upload.',
    '  • Max file size: 5 MB.',
    '  • Accepted formats: .xlsx, .xls',
    '',
    '  Generated: ' . date('Y-m-d H:i:s'),
];

foreach ($instructions as $i => $line) {
    $row = $i + 2;
    $instrSheet->setCellValue("B{$row}", $line);

    $isBanner = str_starts_with(trim($line), '──');
    $style = ['font' => ['name' => 'Arial', 'size' => 9]];
    if ($isBanner) {
        $style['font']['bold']  = true;
        $style['font']['color'] = ['rgb' => '374151'];
        $style['fill']          = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']];
    } else {
        $style['font']['color'] = ['rgb' => '4B5563'];
    }
    $instrSheet->getStyle("B{$row}")->applyFromArray($style);
    $instrSheet->getRowDimension($row)->setRowHeight(16);
}

// ── Final sheet order & active sheet ─────────────────────────────────────────
$spreadsheet->setActiveSheetIndex(1); // open on Companies

// ── Stream to browser ─────────────────────────────────────────────────────────
$filename = 'company_bulk_template_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;