<?php
/**
 * download_template.php
 * Serves the company bulk-import Excel template.
 * Place company_bulk_template.xlsx in the same directory as this file,
 * or adjust $templatePath below.
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$templatePath = __DIR__ . '/company_bulk_template.xlsx';

if (!file_exists($templatePath)) {
    http_response_code(404);
    die('Template file not found. Please contact your administrator.');
}

$filename = 'company_bulk_template.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($templatePath));
header('Cache-Control: max-age=0');

readfile($templatePath);
exit;