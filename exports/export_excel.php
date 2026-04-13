<?php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';
requireAnyRole();

$db     = getDB();
$user   = currentUser();
$module = $_GET['module'] ?? 'tasks';
$from   = $_GET['from']   ?? date('Y-m-01');
$to     = $_GET['to']     ?? date('Y-m-d');

// ── Simple Excel (XML Spreadsheet format — no library needed) ──
function excelHeader(string $title): void {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $title . '_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
           xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
           xmlns:x="urn:schemas-microsoft-com:office:excel">
    <Styles>
        <Style ss:ID="header">
            <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="9"/>
            <Interior ss:Color="#0A0F1E" ss:Pattern="Solid"/>
            <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
            <Borders>
                <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#C9A84C"/>
            </Borders>
        </Style>
        <Style ss:ID="gold_header">
            <Font ss:Bold="1" ss:Color="#0A0F1E" ss:Size="9"/>
            <Interior ss:Color="#C9A84C" ss:Pattern="Solid"/>
            <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
        </Style>
        <Style ss:ID="odd">
            <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
            <Font ss:Size="8"/>
            <Borders>
                <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
            </Borders>
        </Style>
        <Style ss:ID="even">
            <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
            <Font ss:Size="8"/>
            <Borders>
                <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
            </Borders>
        </Style>
        <Style ss:ID="done">
            <Interior ss:Color="#ECFDF5" ss:Pattern="Solid"/>
            <Font ss:Color="#10B981" ss:Bold="1" ss:Size="8"/>
        </Style>
        <Style ss:ID="pending">
            <Interior ss:Color="#FEF2F2" ss:Pattern="Solid"/>
            <Font ss:Color="#EF4444" ss:Bold="1" ss:Size="8"/>
        </Style>
        <Style ss:ID="wip">
            <Interior ss:Color="#FFFBEB" ss:Pattern="Solid"/>
            <Font ss:Color="#F59E0B" ss:Bold="1" ss:Size="8"/>
        </Style>
        <Style ss:ID="title_row">
            <Font ss:Bold="1" ss:Size="12" ss:Color="#0A0F1E"/>
            <Interior ss:Color="#FEF9EC" ss:Pattern="Solid"/>
        </Style>
        <Style ss:ID="true_cell">
            <Interior ss:Color="#ECFDF5" ss:Pattern="Solid"/>
            <Font ss:Color="#10B981" ss:Bold="1" ss:Size="8"/>
            <Alignment ss:Horizontal="Center"/>
        </Style>
        <Style ss:ID="false_cell">
            <Interior ss:Color="#FEF2F2" ss:Pattern="Solid"/>
            <Font ss:Color="#EF4444" ss:Bold="1" ss:Size="8"/>
            <Alignment ss:Horizontal="Center"/>
        </Style>
        <Style ss:ID="number">
            <NumberFormat ss:Format="#,##0.00"/>
            <Font ss:Size="8"/>
        </Style>
        <Style ss:ID="percent">
            <NumberFormat ss:Format="0.00&quot;%&quot;"/>
            <Font ss:Size="8"/>
            <Alignment ss:Horizontal="Center"/>
        </Style>
    </Styles>' . "\n";
}

function cell(string $value, string $styleId = 'odd', string $type = 'String'): string {
    $value = htmlspecialchars($value, ENT_XML1, 'UTF-8');
    return "<Cell ss:StyleID=\"{$styleId}\"><Data ss:Type=\"{$type}\">{$value}</Data></Cell>\n";
}

function headerCell(string $value, string $styleId = 'header'): string {
    return cell($value, $styleId, 'String');
}

function boolCell(bool $value): string {
    $style = $value ? 'true_cell' : 'false_cell';
    $text  = $value ? 'TRUE' : 'FALSE';
    return cell($text, $style);
}

function numberCell(float $value, string $styleId = 'number'): string {
    return "<Cell ss:StyleID=\"{$styleId}\"><Data ss:Type=\"Number\">{$value}</Data></Cell>\n";
}

function startSheet(string $name): string {
    return "<Worksheet ss:Name=\"" . htmlspecialchars($name, ENT_XML1) . "\"><Table>\n";
}

function endSheet(): string {
    return "</Table></Worksheet>\n";
}

function startRow(): string { return "<Row>\n"; }
function endRow(): string   { return "</Row>\n"; }

function statusStyle(string $status): string {
    return match($status) {
        'Done'       => 'done',
        'WIP'        => 'wip',
        'Pending'    => 'pending',
        default      => 'odd',
    };
}

// ══════════════════════════════════════════════════════════════
// MODULE: tasks
// ══════════════════════════════════════════════════════════════
if ($module === 'tasks') {

    $where  = ['t.is_active = 1', 't.created_at BETWEEN ? AND ?'];
    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];

    if (!empty($_GET['dept_id']))   { $where[] = 't.department_id = ?'; $params[] = (int)$_GET['dept_id']; }
    if (!empty($_GET['branch_id'])) { $where[] = 't.branch_id = ?';     $params[] = (int)$_GET['branch_id']; }
    if (!empty($_GET['status']))    { $where[] = 'ts.status_name = ?';  $params[] = $_GET['status']; }
    $ws = implode(' AND ', $where);

    $taskSt = $db->prepare("
        SELECT t.task_number, t.title,
               ts.status_name AS status,
               t.priority,
               d.dept_name, b.branch_name,
               c.company_name,
               ua.full_name AS assigned_to,
               t.due_date, t.fiscal_year, t.remarks,
               t.created_at
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        LEFT JOIN departments d  ON d.id  = t.department_id
        LEFT JOIN branches b     ON b.id  = t.branch_id
        LEFT JOIN companies c    ON c.id  = t.company_id
        LEFT JOIN users ua       ON ua.id = t.assigned_to
        WHERE {$ws}
        ORDER BY t.created_at DESC
    ");
    $taskSt->execute($params);
    $data = $taskSt->fetchAll();

    excelHeader('Tasks');
    echo startSheet('Tasks');

    // Title row
    echo startRow();
    echo "<Cell ss:MergeAcross=\"10\" ss:StyleID=\"title_row\">
            <Data ss:Type=\"String\">MISPro — Task List Report | Period: {$from} to {$to}</Data>
          </Cell>\n";
    echo endRow();

    // Header
    echo startRow();
    foreach (['Task #','Title','Status','Priority','Department','Branch','Company','Assigned To','Due Date','FY','Remarks','Created'] as $h) {
        echo headerCell($h);
    }
    echo endRow();

    $i = 0;
    foreach ($data as $r) {
        $style = statusStyle($r['status']);
        echo startRow();
        echo cell($r['task_number'],                                   $style);
        echo cell($r['title'],                                         $style);
        echo cell($r['status'],                                        $style);
        echo cell(ucfirst($r['priority']),                             $style);
        echo cell($r['dept_name'] ?? '—',                             $style);
        echo cell($r['branch_name'] ?? '—',                           $style);
        echo cell($r['company_name'] ?? '—',                          $style);
        echo cell($r['assigned_to'] ?? '—',                           $style);
        echo cell($r['due_date'] ? date('d M Y', strtotime($r['due_date'])) : '—', $style);
        echo cell($r['fiscal_year'] ?? '—',                           $style);
        echo cell($r['remarks'] ?? '',                                 $style);
        echo cell(date('d M Y', strtotime($r['created_at'])),         $style);
        echo endRow();
        $i++;
    }

    // Total row
    echo startRow();
    echo "<Cell ss:MergeAcross=\"0\" ss:StyleID=\"gold_header\"><Data ss:Type=\"String\">TOTAL: {$i} tasks</Data></Cell>\n";
    echo endRow();

    echo endSheet();
    echo '</Workbook>';
    exit;
}

// ══════════════════════════════════════════════════════════════
// MODULE: bank_summary (matches screenshot exactly)
// ══════════════════════════════════════════════════════════════
elseif ($module === 'bank_summary') {

    $branchId   = (int)($_GET['branch_id'] ?? 0);
    $fiscalYear = $_GET['fiscal_year'] ?? '';

    $where  = ['1=1'];
    $params = [];
    if ($branchId)   { $where[] = 'bs.branch_id = ?';     $params[] = $branchId; }
    if ($fiscalYear) { $where[] = 'bs.fiscal_year = ?';   $params[] = $fiscalYear; }
    $ws = implode(' AND ', $where);

    $summaryStmt = $db->prepare("
        SELECT br.bank_name,
               bs.fiscal_year,
               b.branch_name,
               bs.total_files,
               bs.completed,
               bs.hbc,
               bs.pending,
               bs.support,
               bs.cancelled,
               bs.is_checked,
               bs.pct_of_total_files
        FROM bank_summary bs
        LEFT JOIN bank_references br ON br.id = bs.bank_reference_id
        LEFT JOIN branches b         ON b.id  = bs.branch_id
        WHERE {$ws}
        ORDER BY bs.pct_of_total_files DESC
    ");
    $summaryStmt->execute($params);
    $rows = $summaryStmt->fetchAll();

    // Totals
    $totals = [
        'total_files' => array_sum(array_column($rows, 'total_files')),
        'completed'   => array_sum(array_column($rows, 'completed')),
        'hbc'         => array_sum(array_column($rows, 'hbc')),
        'pending'     => array_sum(array_column($rows, 'pending')),
        'support'     => array_sum(array_column($rows, 'support')),
        'cancelled'   => array_sum(array_column($rows, 'cancelled')),
    ];

    excelHeader('Bank_Summary');
    echo startSheet('Bank Summary');

    // Title row matching screenshot style
    echo startRow();
    echo "<Cell ss:MergeAcross=\"9\" ss:StyleID=\"title_row\">
            <Data ss:Type=\"String\">ASK Global Advisory — Bank Summary Report | {$fiscalYear}</Data>
          </Cell>\n";
    echo endRow();

    // Summary totals row (like screenshot row 2)
    echo startRow();
    echo "<Cell ss:MergeAcross=\"2\" ss:StyleID=\"header\"><Data ss:Type=\"String\"></Data></Cell>\n";
    echo numberCell($totals['total_files'], 'number');
    echo numberCell($totals['completed'],   'done');
    echo numberCell($totals['hbc'],         'wip');
    echo numberCell($totals['pending'],     'pending');
    echo numberCell($totals['support'],     'odd');
    echo numberCell($totals['cancelled'],   'false_cell');
    echo endRow();

    // Percentage summary row
    echo startRow();
    echo "<Cell ss:MergeAcross=\"2\" ss:StyleID=\"gold_header\"><Data ss:Type=\"String\">Fix it on A to Z sorting</Data></Cell>\n";
    $pct = $totals['total_files'] > 0 ? round(($totals['completed'] / $totals['total_files']) * 100, 2) : 0;
    echo "<Cell ss:StyleID=\"percent\"><Data ss:Type=\"Number\">{$pct}</Data></Cell>\n";
    echo numberCell($totals['completed'],   'done');
    echo numberCell($totals['hbc'],         'wip');
    echo numberCell($totals['pending'],     'pending');
    echo numberCell($totals['support'],     'odd');
    echo numberCell($totals['cancelled'],   'false_cell');
    echo endRow();

    // Empty row
    echo startRow(); echo endRow();

    // Header row — matching screenshot exactly
    echo startRow();
    echo headerCell('S.No');
    echo headerCell('Bank Name');
    echo headerCell('% of Total Files');
    echo headerCell('Total File');
    echo headerCell('Completed');
    echo headerCell('HBC');
    echo headerCell('Pending');
    echo headerCell('Support');
    echo headerCell('Cancelled');
    echo headerCell('Check');
    echo endRow();

    $sno = 1;
    foreach ($rows as $r) {
        echo startRow();
        echo cell((string)$sno++,          'odd', 'Number');
        echo cell($r['bank_name'],          'odd');
        echo "<Cell ss:StyleID=\"percent\"><Data ss:Type=\"Number\">{$r['pct_of_total_files']}</Data></Cell>\n";
        echo numberCell($r['total_files'],  'odd');
        echo numberCell($r['completed'],    'done');
        echo numberCell($r['hbc'],          'wip');
        echo numberCell($r['pending'],      'pending');
        echo numberCell($r['support'],      'odd');
        echo numberCell($r['cancelled'],    'false_cell');
        echo boolCell((bool)$r['is_checked']);
        echo endRow();
    }

    // Totals footer row
    echo startRow();
    echo "<Cell ss:MergeAcross=\"2\" ss:StyleID=\"gold_header\"><Data ss:Type=\"String\">TOTAL</Data></Cell>\n";
    echo numberCell($totals['total_files'], 'number');
    echo numberCell($totals['completed'],   'done');
    echo numberCell($totals['hbc'],         'wip');
    echo numberCell($totals['pending'],     'pending');
    echo numberCell($totals['support'],     'odd');
    echo numberCell($totals['cancelled'],   'false_cell');
    echo cell('', 'odd');
    echo endRow();

    echo endSheet();
    echo '</Workbook>';
    exit;
}

// ══════════════════════════════════════════════════════════════
// MODULE: tax_report
// ══════════════════════════════════════════════════════════════
elseif ($module === 'tax_report') {

    $where  = ['t.is_active = 1'];
    $params = [];
    if (!empty($_GET['from']))      { $where[] = 't.created_at >= ?';  $params[] = $_GET['from'] . ' 00:00:00'; }
    if (!empty($_GET['to']))        { $where[] = 't.created_at <= ?';  $params[] = $_GET['to']   . ' 23:59:59'; }
    if (!empty($_GET['branch_id'])) { $where[] = 't.branch_id = ?';    $params[] = (int)$_GET['branch_id']; }
    $ws = implode(' AND ', $where);

    $taxSt = $db->prepare("
        SELECT t.task_number, c.company_name, c.pan_number AS company_pan,
               tt.firm_name,
               tot.office_name AS assigned_office,
               tst.submission_name AS submission_type,
               tt.fiscal_year,
               tt.submission_number,
               tt.submission_url,
               tt.udin_no,
               tt.udin_url,
               tt.business_type,
               tt.pan_number,
               ts.status_name AS status,
               tc.status_name AS tax_clearance_status,
               a.full_name AS assigned_to,
               fr.full_name AS file_received_by,
               ub.full_name AS updated_by,
               vb.full_name AS verify_by,
               tt.bills_issued,
               tt.fee_received,
               tt.tds_payment,
               tt.assigned_date,
               tt.completed_date,
               tt.follow_up_date,
               tt.remarks
        FROM task_tax tt
        JOIN tasks t            ON t.id   = tt.task_id
        JOIN companies c        ON c.id   = tt.company_id
        LEFT JOIN tax_office_types tot ON tot.id = tt.assigned_office_id
        LEFT JOIN tax_submission_types tst ON tst.id = tt.submission_type_id
        LEFT JOIN task_status ts ON ts.id = tt.status_id
        LEFT JOIN task_status tc ON tc.id = tt.tax_clearance_status_id
        LEFT JOIN users a    ON a.id   = tt.assigned_to
        LEFT JOIN users fr   ON fr.id  = tt.file_received_by
        LEFT JOIN users ub   ON ub.id  = tt.updated_by
        LEFT JOIN users vb   ON vb.id  = tt.verify_by
        WHERE {$ws}
        ORDER BY t.created_at DESC
    ");
    $taxSt->execute($params);
    $data = $taxSt->fetchAll();

    excelHeader('Tax_Report');
    echo startSheet('Tax Tasks');

    echo startRow();
    echo "<Cell ss:MergeAcross=\"24\" ss:StyleID=\"title_row\">
            <Data ss:Type=\"String\">MISPro — Tax Department Report | {$from} to {$to}</Data>
          </Cell>\n";
    echo endRow();

    echo startRow();
    foreach ([
        'Task #','Company','Company PAN','Firm Name','Office',
        'Submission Type','FY','Submission No','Submission URL',
        'UDIN No','UDIN URL','Business Type','PAN No','Status',
        'Tax Clearance','Assigned To','File Received By','Updated By',
        'Verify By','Bills Issued','Fee Received','TDS Payment',
        'Assigned Date','Completed Date','Remarks'
    ] as $h) {
        echo headerCell($h);
    }
    echo endRow();

    foreach ($data as $r) {
        $style = statusStyle($r['status'] ?? '');
        echo startRow();
        echo cell($r['task_number'],             $style);
        echo cell($r['company_name'],            $style);
        echo cell($r['company_pan'] ?? '—',      $style);
        echo cell($r['firm_name'] ?? '—',        $style);
        echo cell($r['assigned_office'] ?? '—',  $style);
        echo cell($r['submission_type'] ?? '—',  $style);
        echo cell($r['fiscal_year'] ?? '—',      $style);
        echo cell($r['submission_number'] ?? '—',$style);
        // Submission URL as hyperlink-style
        echo "<Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">{$r['submission_url']}</Data></Cell>\n";
        echo cell($r['udin_no'] ?? '—',          $style);
        echo "<Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">{$r['udin_url']}</Data></Cell>\n";
        echo cell($r['business_type'] ?? '—',    $style);
        echo cell($r['pan_number'] ?? '—',       $style);
        echo cell($r['status'] ?? '—',           $style);
        echo cell($r['tax_clearance_status'] ?? '—', $style);
        echo cell($r['assigned_to'] ?? '—',      $style);
        echo cell($r['file_received_by'] ?? '—', $style);
        echo cell($r['updated_by'] ?? '—',       $style);
        echo cell($r['verify_by'] ?? '—',        $style);
        echo numberCell((float)($r['bills_issued'] ?? 0));
        echo numberCell((float)($r['fee_received'] ?? 0));
        echo numberCell((float)($r['tds_payment'] ?? 0));
        echo cell($r['assigned_date'] ? date('d M Y', strtotime($r['assigned_date'])) : '—', $style);
        echo cell($r['completed_date'] ? date('d M Y', strtotime($r['completed_date'])) : '—', $style);
        echo cell($r['remarks'] ?? '',           $style);
        echo endRow();
    }

    echo endSheet();
    echo '</Workbook>';
    exit;
}

// ══════════════════════════════════════════════════════════════
// MODULE: banking_report
// ══════════════════════════════════════════════════════════════
elseif ($module === 'banking_report') {

    $filterBranch = (int)($_GET['branch_id'] ?? 0);
    $filterFY     = $_GET['fiscal_year'] ?? '';
    $filterStatus = $_GET['status']      ?? '';

    $where  = ['t.is_active = 1'];
    $params = [];
    if ($filterBranch) { $where[] = 't.branch_id = ?';     $params[] = $filterBranch; }
    if ($filterFY)     { $where[] = 't.fiscal_year = ?';   $params[] = $filterFY; }
    if ($filterStatus) { $where[] = 'ws.status_name = ?';  $params[] = $filterStatus; }
    $ws = implode(' AND ', $where);

    $bankSt = $db->prepare("
        SELECT
            t.task_number,
            t.fiscal_year,

            -- Company info from companies table
            c.company_name,
            c.contact_person,
            c.contact_phone,
            c.pan_number AS company_pan,

            -- Bank info
            br.bank_name,

            -- Referred by
            ru.full_name AS referred_by_name,

            -- Client category
            bcc.category_name AS client_category,

            -- Delegated
            da.full_name AS delegated_to,

            -- Dates
            tb.assigned_date,
            tb.ecd,
            tb.completion_date,

            -- Status
            ws.status_name AS work_status,

            -- Checklist (numbers as per screenshot)
            tb.sales_check,
            tb.audit_check,
            tb.provisional_financial_statement,
            tb.projected,
            tb.consulting,
            tb.nta,
            tb.salary_certificate,
            tb.ca_certification,
            tb.etds,
            tb.bill_issued,

            tb.remarks
        FROM task_banking tb
        JOIN tasks t                  ON t.id   = tb.task_id
        JOIN companies c              ON c.id   = tb.company_id
        LEFT JOIN bank_references br  ON br.id  = tb.bank_reference_id
        LEFT JOIN users ru            ON ru.id  = tb.referred_by
        LEFT JOIN users da            ON da.id  = tb.delegated_to_auditor
        LEFT JOIN bank_client_categories bcc ON bcc.id = tb.client_category_id
        LEFT JOIN task_status ws      ON ws.id  = tb.work_status_id
        WHERE {$ws}
        ORDER BY c.company_name ASC
    ");
    $bankSt->execute($params);
    $data = $bankSt->fetchAll();

    // Row color per status — matches screenshot
    // Green=Done, Yellow=WIP, Cyan=HBC, Red=Pending, Light blue=Support
    $statusColors = [
        'Done'    => '#92D050',  // green  like screenshot
        'WIP'     => '#FFFF00',  // yellow
        'HBC'     => '#00B0F0',  // cyan/blue
        'Pending' => '#FF0000',  // red
        'Support' => '#BDD7EE',  // light blue
        'Not Started' => '#FFFFFF',
    ];

    // Build dynamic styles per status
    $statusStyleMap = [];
    $styleXml = '';
    foreach ($statusColors as $status => $color) {
        $safeId = 'row_' . preg_replace('/[^a-z0-9]/i', '_', $status);
        $statusStyleMap[$status] = $safeId;
        $styleXml .= "<Style ss:ID=\"{$safeId}\">
            <Interior ss:Color=\"{$color}\" ss:Pattern=\"Solid\"/>
            <Font ss:Size=\"8\"/>
            <Borders>
                <Border ss:Position=\"Bottom\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#D9D9D9\"/>
                <Border ss:Position=\"Right\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#D9D9D9\"/>
            </Borders>
        </Style>\n";
        // Number style for same color
        $styleXml .= "<Style ss:ID=\"{$safeId}_num\">
            <Interior ss:Color=\"{$color}\" ss:Pattern=\"Solid\"/>
            <Font ss:Size=\"8\"/>
            <NumberFormat ss:Format=\"#,##0\"/>
            <Alignment ss:Horizontal=\"Center\"/>
            <Borders>
                <Border ss:Position=\"Bottom\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#D9D9D9\"/>
                <Border ss:Position=\"Right\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#D9D9D9\"/>
            </Borders>
        </Style>\n";
        // Yes style
        $styleXml .= "<Style ss:ID=\"{$safeId}_yes\">
            <Interior ss:Color=\"{$color}\" ss:Pattern=\"Solid\"/>
            <Font ss:Size=\"8\" ss:Bold=\"1\"/>
            <Alignment ss:Horizontal=\"Center\"/>
            <Borders>
                <Border ss:Position=\"Bottom\" ss:LineStyle=\"Continuous\" ss:Weight=\"1\" ss:Color=\"#D9D9D9\"/>
            </Borders>
        </Style>\n";
    }

    // Output headers
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Banking_Report_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
           xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
           xmlns:x="urn:schemas-microsoft-com:office:excel">
    <Styles>
        <Style ss:ID="header">
            <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="9"/>
            <Interior ss:Color="#0A0F1E" ss:Pattern="Solid"/>
            <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
            <Borders>
                <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#C9A84C"/>
                <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#2A3550"/>
            </Borders>
        </Style>
        <Style ss:ID="title_row">
            <Font ss:Bold="1" ss:Size="12" ss:Color="#0A0F1E"/>
            <Interior ss:Color="#FEF9EC" ss:Pattern="Solid"/>
        </Style>
        <Style ss:ID="odd">
            <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
            <Font ss:Size="8"/>
            <Borders>
                <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
                <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
            </Borders>
        </Style>
        ' . $styleXml . '
    </Styles>' . "\n";

    echo '<Worksheet ss:Name="Banking Tasks"><Table>' . "\n";

    // Column widths matching screenshot
    $colWidths = [80, 60, 80, 60, 60, 60, 60, 60, 60, 40, 50, 50,
                  40, 40, 40, 40, 40, 40, 40, 40, 40, 40, 80];
    foreach ($colWidths as $w) {
        echo "<Column ss:Width=\"{$w}\"/>\n";
    }

    // Title
    echo "<Row ss:Height=\"20\">\n";
    echo "<Cell ss:MergeAcross=\"22\" ss:StyleID=\"title_row\">
            <Data ss:Type=\"String\">ASK Global Advisory — Banking Department Report" .
            ($filterFY ? " | FY: {$filterFY}" : '') . "</Data>
          </Cell>\n";
    echo "</Row>\n";

    // Header row — exactly matching screenshot columns
    echo "<Row ss:Height=\"40\">\n";
    $headers = [
        'Task #',
        'Company Name',        // from companies table
        'Contact Person',      // from companies table
        'Contact Phone',       // from companies table
        'PAN No',              // from companies table
        'Bank Name',
        'Referred By',
        'Client Category',
        'Delegated To Auditor',
        'Work Status',
        'Assigned Date',
        'ECD',
        'Sales Check',
        'Audit',
        'Provisional / Financial Statement',
        'Projected',
        'Consulting',
        'NTA',
        'Salary Certificate',
        'CA Certification',
        'ETDS',
        'Bill Issued',
        'Remarks',
    ];
    foreach ($headers as $h) {
        echo "<Cell ss:StyleID=\"header\"><Data ss:Type=\"String\">" .
             htmlspecialchars($h, ENT_XML1) . "</Data></Cell>\n";
    }
    echo "</Row>\n";

    // Data rows — colored by status matching screenshot
    foreach ($data as $r) {
        $status    = $r['work_status'] ?? 'Not Started';
        $styleBase = $statusStyleMap[$status] ?? 'odd';
        $styleNum  = $styleBase . '_num';
        $styleYes  = $styleBase . '_yes';

        echo "<Row>\n";

        // Text cells
        foreach ([
            $r['task_number'],
            $r['company_name'],
            $r['contact_person'] ?? '—',
            $r['contact_phone']  ?? '—',
            $r['company_pan']    ?? '—',
            $r['bank_name']      ?? '—',
            $r['referred_by_name'] ?? '—',
            $r['client_category'] ?? '—',
            $r['delegated_to']   ?? '—',
            $r['work_status']    ?? '—',
            $r['assigned_date']  ? date('d M Y', strtotime($r['assigned_date'])) : '—',
            $r['ecd']            ? date('d M Y', strtotime($r['ecd']))           : '—',
        ] as $val) {
            $val = htmlspecialchars($val ?? '', ENT_XML1, 'UTF-8');
            echo "<Cell ss:StyleID=\"{$styleBase}\"><Data ss:Type=\"String\">{$val}</Data></Cell>\n";
        }

        // Numeric checklist columns — show number or blank (matching screenshot)
        foreach ([
            'sales_check',
            'audit_check',
            'provisional_financial_statement',
            'projected',
            'consulting',
            'nta',
            'salary_certificate',
            'ca_certification',
            'etds',
        ] as $field) {
            $val = $r[$field];
            if ($val !== null && $val !== '' && $val != 0) {
                echo "<Cell ss:StyleID=\"{$styleNum}\"><Data ss:Type=\"Number\">{$val}</Data></Cell>\n";
            } else {
                echo "<Cell ss:StyleID=\"{$styleBase}\"><Data ss:Type=\"String\"></Data></Cell>\n";
            }
        }

        // Bill Issued — "Yes" or blank (matching screenshot)
        $billVal = $r['bill_issued'] ? 'Yes' : '';
        echo "<Cell ss:StyleID=\"{$styleYes}\"><Data ss:Type=\"String\">{$billVal}</Data></Cell>\n";

        // Remarks
        $rem = htmlspecialchars($r['remarks'] ?? '', ENT_XML1, 'UTF-8');
        echo "<Cell ss:StyleID=\"{$styleBase}\"><Data ss:Type=\"String\">{$rem}</Data></Cell>\n";

        echo "</Row>\n";
    }

    echo "</Table></Worksheet>\n";
    echo "</Workbook>\n";
    exit;
}
elseif ($module === 'date_wise') {

    $from  = $_GET['from'] ?? date('Y-m-01');
    $to    = $_GET['to']   ?? date('Y-m-d');
    $group = $_GET['group'] ?? 'day';

    $fmt = match($group) {
        'month' => "DATE_FORMAT(created_at,'%b %Y')",
        'week'  => "CONCAT('Week ', WEEK(created_at), ' ', YEAR(created_at))",
        default => "DATE(created_at)",
    };

    $st = $db->prepare("
        SELECT {$fmt} as period,
               COUNT(*) as created,
               SUM(status='Completed') as completed,
               SUM(status='WIP') as wip,
               SUM(status='Pending') as pending
        FROM tasks
        WHERE is_active=1
          AND created_at BETWEEN ? AND ?
        GROUP BY period
        ORDER BY MIN(created_at)
    ");
    $st->execute([$from.' 00:00:00', $to.' 23:59:59']);

    excelHeader('Date_Wise_Report');
    echo startSheet('Date Report');

    echo startRow();
    foreach (['Period','Created','Completed','WIP','Pending','Completion %'] as $h) {
        echo headerCell($h);
    }
    echo endRow();

    foreach ($st as $r) {
        $pct = $r['created'] ? round(($r['completed']/$r['created'])*100) : 0;

        echo startRow();
        echo cell($r['period']);
        echo numberCell($r['created']);
        echo numberCell($r['completed'],'done');
        echo numberCell($r['wip'],'wip');
        echo numberCell($r['pending'],'pending');
        echo numberCell($pct);
        echo endRow();
    }

    echo endSheet();
    echo '</Workbook>';
    exit;
}
// ══════════════════════════════════════════════════════════════
// MODULE: finance_report
// ══════════════════════════════════════════════════════════════
elseif ($module === 'finance_report') {

    $finSt = $db->prepare("
        SELECT t.task_number, c.company_name,
               fst.service_name AS service_type,
               tf.fiscal_year,
               tf.invoice_number, tf.invoice_date,
               tf.total_amount, tf.paid_amount, tf.due_amount,
               tf.payment_date, tf.payment_method,
               ps.status_name AS payment_status,
               tc.status_name AS tax_clearance_status,
               tf.tax_clearance_date, tf.tax_clearance_number,
               tf.service_tax_amount, tf.service_tax_paid,
               tf.vat_amount, tf.vat_paid,
               tf.tds_deducted, tf.tds_certificate_no,
               tf.is_completed,
               pb.full_name AS processed_by,
               vb.full_name AS verified_by,
               tf.remarks
        FROM task_finance tf
        JOIN tasks t               ON t.id  = tf.task_id
        JOIN companies c           ON c.id  = tf.company_id
        LEFT JOIN finance_service_types fst ON fst.id = tf.service_type_id
        LEFT JOIN task_status ps   ON ps.id = tf.payment_status_id
        LEFT JOIN task_status tc   ON tc.id = tf.tax_clearance_status_id
        LEFT JOIN users pb         ON pb.id = tf.processed_by
        LEFT JOIN users vb         ON vb.id = tf.verified_by
        WHERE t.is_active = 1
        ORDER BY t.created_at DESC
    ");
    $finSt->execute([]);
    $data = $finSt->fetchAll();

    excelHeader('Finance_Report');
    echo startSheet('Finance Tasks');

    echo startRow();
    echo "<Cell ss:MergeAcross=\"24\" ss:StyleID=\"title_row\">
            <Data ss:Type=\"String\">MISPro — Finance Department Report</Data>
          </Cell>\n";
    echo endRow();

    echo startRow();
    foreach ([
        'Task #','Company','Service Type','FY',
        'Invoice No','Invoice Date','Total Amount','Paid Amount','Due Amount',
        'Payment Date','Payment Method','Payment Status',
        'Tax Clearance Status','TC Date','TC Number',
        'Service Tax','ST Paid','VAT Amount','VAT Paid',
        'TDS Deducted','TDS Cert No',
        'Completed','Processed By','Verified By','Remarks'
    ] as $h) {
        echo headerCell($h);
    }
    echo endRow();

    foreach ($data as $r) {
        $style = (bool)$r['is_completed'] ? 'done' : statusStyle($r['payment_status'] ?? '');
        echo startRow();
        echo cell($r['task_number'],                    $style);
        echo cell($r['company_name'],                   $style);
        echo cell($r['service_type'] ?? '—',            $style);
        echo cell($r['fiscal_year'] ?? '—',             $style);
        echo cell($r['invoice_number'] ?? '—',          $style);
        echo cell($r['invoice_date'] ? date('d M Y', strtotime($r['invoice_date'])) : '—', $style);
        echo numberCell((float)($r['total_amount'] ?? 0));
        echo numberCell((float)($r['paid_amount'] ?? 0));
        echo numberCell((float)($r['due_amount'] ?? 0));
        echo cell($r['payment_date'] ? date('d M Y', strtotime($r['payment_date'])) : '—', $style);
        echo cell($r['payment_method'] ?? '—',          $style);
        echo cell($r['payment_status'] ?? '—',          $style);
        echo cell($r['tax_clearance_status'] ?? '—',    $style);
        echo cell($r['tax_clearance_date'] ? date('d M Y', strtotime($r['tax_clearance_date'])) : '—', $style);
        echo cell($r['tax_clearance_number'] ?? '—',    $style);
        echo numberCell((float)($r['service_tax_amount'] ?? 0));
        echo boolCell((bool)$r['service_tax_paid']);
        echo numberCell((float)($r['vat_amount'] ?? 0));
        echo boolCell((bool)$r['vat_paid']);
        echo numberCell((float)($r['tds_deducted'] ?? 0));
        echo cell($r['tds_certificate_no'] ?? '—',      $style);
        echo boolCell((bool)$r['is_completed']);
        echo cell($r['processed_by'] ?? '—',            $style);
        echo cell($r['verified_by'] ?? '—',             $style);
        echo cell($r['remarks'] ?? '',                  $style);
        echo endRow();
    }

    echo endSheet();
    echo '</Workbook>';
    exit;
}

// ══════════════════════════════════════════════════════════════
// MODULE: staff_wise
// ══════════════════════════════════════════════════════════════
elseif ($module === 'staff_wise') {

    $staffSt = $db->prepare("
        SELECT u.full_name, u.employee_id,
               b.branch_name, d.dept_name,
               COUNT(t.id) as total,
               SUM(CASE WHEN ts.status_name='Done'    THEN 1 ELSE 0 END) as done,
               SUM(CASE WHEN ts.status_name='WIP'     THEN 1 ELSE 0 END) as wip,
               SUM(CASE WHEN ts.status_name='Pending' THEN 1 ELSE 0 END) as pending,
               SUM(CASE WHEN ts.status_name='HBC'     THEN 1 ELSE 0 END) as hbc
        FROM users u
        LEFT JOIN roles r        ON r.id  = u.role_id
        LEFT JOIN branches b     ON b.id  = u.branch_id
        LEFT JOIN departments d  ON d.id  = u.department_id
        LEFT JOIN tasks t        ON t.assigned_to = u.id AND t.is_active = 1
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE r.role_name = 'staff' AND u.is_active = 1
        GROUP BY u.id, u.full_name, u.employee_id, b.branch_name, d.dept_name
        ORDER BY total DESC
    ");
    $staffSt->execute([]);
    $data = $staffSt->fetchAll();

    excelHeader('Staff_Performance');
    echo startSheet('Staff Performance');

    echo startRow();
    echo "<Cell ss:MergeAcross=\"8\" ss:StyleID=\"title_row\">
            <Data ss:Type=\"String\">MISPro — Staff Performance Report</Data>
          </Cell>\n";
    echo endRow();

    echo startRow();
    foreach (['Staff Member','Employee ID','Branch','Department','Total','Done','WIP','Pending','HBC','% Done'] as $h) {
        echo headerCell($h);
    }
    echo endRow();

    foreach ($data as $r) {
        $pct   = $r['total'] > 0 ? round(($r['done'] / $r['total']) * 100, 2) : 0;
        $style = $pct >= 75 ? 'done' : ($pct >= 40 ? 'wip' : 'odd');
        echo startRow();
        echo cell($r['full_name'],          $style);
        echo cell($r['employee_id'] ?? '—', $style);
        echo cell($r['branch_name'] ?? '—', $style);
        echo cell($r['dept_name'] ?? '—',   $style);
        echo numberCell($r['total']);
        echo numberCell($r['done']);
        echo numberCell($r['wip']);
        echo numberCell($r['pending']);
        echo numberCell($r['hbc']);
        echo "<Cell ss:StyleID=\"percent\"><Data ss:Type=\"Number\">{$pct}</Data></Cell>\n";
        echo endRow();
    }

    echo endSheet();
    echo '</Workbook>';
    exit;
}
elseif ($module === 'staff_wise') {

    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');
    $dept = (int)($_GET['dept_id'] ?? 0);
    $branch = (int)($_GET['branch_id'] ?? 0);

    $where = ['t.created_at BETWEEN ? AND ?', 't.is_active=1'];
    $params = [$from.' 00:00:00',$to.' 23:59:59'];

    if ($dept)   { $where[]='u.department_id=?'; $params[]=$dept; }
    if ($branch) { $where[]='u.branch_id=?';     $params[]=$branch; }

    $ws = implode(' AND ',$where);

    $st = $db->prepare("
        SELECT u.full_name, b.branch_name, d.dept_name,
               COUNT(t.id) as total,
               SUM(ts.status_name='Done') as done,
               SUM(ts.status_name='WIP') as wip,
               SUM(ts.status_name='Pending') as pending
        FROM users u
        LEFT JOIN tasks t ON t.assigned_to=u.id AND {$ws}
        LEFT JOIN task_status ts ON ts.id=t.status_id
        LEFT JOIN branches b ON b.id=u.branch_id
        LEFT JOIN departments d ON d.id=u.department_id
        GROUP BY u.id
    ");
    $st->execute($params);

    excelHeader('Staff_Report');
    echo startSheet('Staff');

    echo startRow();
    foreach (['Staff','Branch','Department','Total','Done','WIP','Pending'] as $h) {
        echo headerCell($h);
    }
    echo endRow();

    foreach ($st as $r) {
        echo startRow();
        echo cell($r['full_name']);
        echo cell($r['branch_name']);
        echo cell($r['dept_name']);
        echo numberCell($r['total']);
        echo numberCell($r['done'],'done');
        echo numberCell($r['wip'],'wip');
        echo numberCell($r['pending'],'pending');
        echo endRow();
    }

    echo endSheet();
    echo '</Workbook>';
    exit;
}
// ══════════════════════════════════════════════════════════════
// MODULE: branch_wise
// ══════════════════════════════════════════════════════════════
elseif ($module === 'branch_wise') {

    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');

    $branches = $db->query("
        SELECT * FROM branches 
        WHERE is_active=1 
        ORDER BY is_head_office DESC, branch_name
    ")->fetchAll();

    excelHeader('Branch_Wise_Report');
    echo startSheet('Branch Report');

    // Title
    echo startRow();
    echo "<Cell ss:MergeAcross=\"5\" ss:StyleID=\"title_row\">
            <Data ss:Type=\"String\">Branch-wise Task Report | {$from} to {$to}</Data>
          </Cell>\n";
    echo endRow();

    // Header
    echo startRow();
    foreach (['Branch Name','Total Tasks','Done','WIP','Pending','HBC'] as $h) {
        echo headerCell($h);
    }
    echo endRow();

    foreach ($branches as $b) {

        $st = $db->prepare("
            SELECT ts.status_name, COUNT(*) as cnt
            FROM tasks t
            LEFT JOIN task_status ts ON ts.id = t.status_id
            WHERE t.branch_id=? AND t.is_active=1
              AND t.created_at BETWEEN ? AND ?
            GROUP BY ts.status_name
        ");
        $st->execute([
            $b['id'],
            $from.' 00:00:00',
            $to.' 23:59:59'
        ]);

        $statusData = array_column($st->fetchAll(),'cnt','status_name');

        $total   = array_sum($statusData);
        $done    = $statusData['Done']    ?? 0;
        $wip     = $statusData['WIP']     ?? 0;
        $pending = $statusData['Pending']?? 0;
        $hbc     = $statusData['HBC']    ?? 0;

        echo startRow();
        echo cell($b['branch_name']);
        echo numberCell($total);
        echo numberCell($done, 'done');
        echo numberCell($wip, 'wip');
        echo numberCell($pending, 'pending');
        echo numberCell($hbc);
        echo endRow();
    }

    echo endSheet();
    echo '</Workbook>';
    exit;
}
// ══════════════════════════════════════════════════════════════
// MODULE: department_wise
// ══════════════════════════════════════════════════════════════
elseif ($module === 'department_wise') {

    $deptSt = $db->query("
        SELECT d.dept_name,
               COUNT(t.id) as total,
               SUM(CASE WHEN ts.status_name='Done'    THEN 1 ELSE 0 END) as done,
               SUM(CASE WHEN ts.status_name='WIP'     THEN 1 ELSE 0 END) as wip,
               SUM(CASE WHEN ts.status_name='Pending' THEN 1 ELSE 0 END) as pending,
               SUM(CASE WHEN ts.status_name='HBC'     THEN 1 ELSE 0 END) as hbc,
               SUM(CASE WHEN ts.status_name='Next Year' THEN 1 ELSE 0 END) as next_year
        FROM departments d
        LEFT JOIN tasks t        ON t.department_id = d.id AND t.is_active = 1
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE d.is_active = 1
        GROUP BY d.id, d.dept_name
        ORDER BY total DESC
    ");
    $data = $deptSt->fetchAll();

    excelHeader('Department_Report');
    echo startSheet('Department Report');

    echo startRow();
    foreach (['Department','Total','Done','WIP','Pending','HBC','Next Year','% Done'] as $h) {
        echo headerCell($h);
    }
    echo endRow();

    foreach ($data as $r) {
        $pct = $r['total'] > 0 ? round(($r['done'] / $r['total']) * 100, 2) : 0;
        echo startRow();
        echo cell($r['dept_name'],      'odd');
        echo numberCell($r['total']);
        echo numberCell($r['done']);
        echo numberCell($r['wip']);
        echo numberCell($r['pending']);
        echo numberCell($r['hbc']);
        echo numberCell($r['next_year']);
        echo "<Cell ss:StyleID=\"percent\"><Data ss:Type=\"Number\">{$pct}</Data></Cell>\n";
        echo endRow();
    }

    echo endSheet();
    echo '</Workbook>';
    exit;
}
elseif ($module === 'department_wise') {

    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');

    $st = $db->prepare("
        SELECT d.dept_name,
               COUNT(t.id) as total,
               SUM(ts.status_name='Done') as done,
               SUM(ts.status_name='WIP') as wip,
               SUM(ts.status_name='Pending') as pending,
               SUM(ts.status_name='HBC') as hbc
        FROM departments d
        LEFT JOIN tasks t ON t.department_id=d.id AND t.is_active=1
            AND t.created_at BETWEEN ? AND ?
        LEFT JOIN task_status ts ON ts.id=t.status_id
        WHERE d.is_active=1
        GROUP BY d.id
    ");
    $st->execute([$from.' 00:00:00',$to.' 23:59:59']);

    excelHeader('Department_Report');
    echo startSheet('Departments');

    echo startRow();
    foreach (['Department','Total','Done','WIP','Pending','HBC'] as $h) {
        echo headerCell($h);
    }
    echo endRow();

    foreach ($st as $r) {
        echo startRow();
        echo cell($r['dept_name']);
        echo numberCell($r['total']);
        echo numberCell($r['done'],'done');
        echo numberCell($r['wip'],'wip');
        echo numberCell($r['pending'],'pending');
        echo numberCell($r['hbc']);
        echo endRow();
    }

    echo endSheet();
    echo '</Workbook>';
    exit;
}
// ══════════════════════════════════════════════════════════════
// MODULE: company_wise
// ══════════════════════════════════════════════════════════════
elseif ($module === 'company_wise') {

    $search  = trim($_GET['search'] ?? '');
    $branch  = (int)($_GET['branch_id'] ?? 0);

    $where  = ['c.is_active = 1'];
    $params = [];

    if ($search) {
        $where[] = '(c.company_name LIKE ? OR c.pan_number LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($branch) {
        $where[] = 'c.branch_id = ?';
        $params[] = $branch;
    }

    $ws = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT c.company_name, c.pan_number,
               b.branch_name,
               COUNT(t.id) AS total_tasks,
               SUM(CASE WHEN ts.status_name='Done' THEN 1 ELSE 0 END) AS done,
               SUM(CASE WHEN ts.status_name='WIP' THEN 1 ELSE 0 END) AS wip,
               SUM(CASE WHEN ts.status_name='Pending' THEN 1 ELSE 0 END) AS pending,
               SUM(CASE WHEN ts.status_name='HBC' THEN 1 ELSE 0 END) AS hbc
        FROM companies c
        LEFT JOIN branches b     ON b.id = c.branch_id
        LEFT JOIN tasks t        ON t.company_id = c.id AND t.is_active=1
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE {$ws}
        GROUP BY c.id
        ORDER BY total_tasks DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    excelHeader('Company_Wise_Report');
    echo startSheet('Company Report');

    // Title
    echo startRow();
    echo "<Cell ss:MergeAcross=\"6\" ss:StyleID=\"title_row\">
            <Data ss:Type=\"String\">Company-wise Task Report</Data>
          </Cell>\n";
    echo endRow();

    // Header
    echo startRow();
    foreach ([
        'Company Name','PAN','Branch',
        'Total Tasks','Done','WIP','Pending','HBC'
    ] as $h) {
        echo headerCell($h);
    }
    echo endRow();

    foreach ($rows as $r) {
        echo startRow();
        echo cell($r['company_name']);
        echo cell($r['pan_number'] ?? '—');
        echo cell($r['branch_name'] ?? '—');
        echo numberCell($r['total_tasks']);
        echo numberCell($r['done'], 'done');
        echo numberCell($r['wip'], 'wip');
        echo numberCell($r['pending'], 'pending');
        echo numberCell($r['hbc']);
        echo endRow();
    }

    echo endSheet();
    echo '</Workbook>';
    exit;
}
// ══════════════════════════════════════════════════════════════
// MODULE: report (admin/reports/index.php — staff performance)
// ══════════════════════════════════════════════════════════════
elseif ($module === 'report') {

    $fromDate     = $_GET['from']          ?? date('Y-m-01');
    $toDate       = $_GET['to']            ?? date('Y-m-d');
    $employeeName = trim($_GET['employee_name'] ?? '');
    $branchId     = (int)($_GET['branch_id']    ?? 0);
    $deptId       = (int)($_GET['dept_id']      ?? 0);

    $where  = ['t.is_active = 1', 't.created_at BETWEEN ? AND ?'];
    $params = [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'];

    if ($branchId)     { $where[] = 't.branch_id = ?';     $params[] = $branchId; }
    if ($deptId)       { $where[] = 't.department_id = ?'; $params[] = $deptId; }
    if ($employeeName) { $where[] = 'u.full_name LIKE ?';  $params[] = "%{$employeeName}%"; }

    $ws = implode(' AND ', $where);

    // Staff performance query — same as reports/index.php
    $staffSt = $db->prepare("
        SELECT
            u.full_name,
            u.employee_id,
            b.branch_name,
            d.dept_name,
            COUNT(t.id)                                                      AS total,
            SUM(CASE WHEN ts.status_name = 'Done'            THEN 1 ELSE 0 END) AS done,
            SUM(CASE WHEN ts.status_name = 'WIP'             THEN 1 ELSE 0 END) AS wip,
            SUM(CASE WHEN ts.status_name = 'Pending'         THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN ts.status_name = 'HBC'             THEN 1 ELSE 0 END) AS hbc,
            SUM(CASE WHEN ts.status_name = 'Not Started'     THEN 1 ELSE 0 END) AS not_started,
            SUM(CASE WHEN ts.status_name = 'Next Year'       THEN 1 ELSE 0 END) AS next_year,
            SUM(CASE WHEN ts.status_name = 'Corporate Team'  THEN 1 ELSE 0 END) AS corporate_team,
            SUM(CASE WHEN ts.status_name = 'NON Performance' THEN 1 ELSE 0 END) AS non_performance,
            SUM(CASE WHEN t.due_date < CURDATE()
                AND ts.status_name != 'Done'                 THEN 1 ELSE 0 END) AS overdue
        FROM tasks t
        LEFT JOIN users u        ON u.id  = t.assigned_to
        LEFT JOIN branches b     ON b.id  = u.branch_id
        LEFT JOIN departments d  ON d.id  = u.department_id
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE {$ws}
        GROUP BY t.assigned_to, u.full_name, u.employee_id, b.branch_name, d.dept_name
        ORDER BY total DESC
    ");
    $staffSt->execute($params);
    $staffData = $staffSt->fetchAll();

    // Status summary totals
    $statusSt = $db->prepare("
        SELECT ts.status_name, COUNT(t.id) AS cnt
        FROM task_status ts
        LEFT JOIN tasks t ON t.status_id = ts.id
            AND t.is_active = 1
            AND t.created_at BETWEEN ? AND ?
            " . ($branchId ? "AND t.branch_id = {$branchId}" : '') . "
            " . ($deptId   ? "AND t.department_id = {$deptId}" : '') . "
        GROUP BY ts.id, ts.status_name
        ORDER BY ts.id
    ");
    $statusSt->execute([$fromDate . ' 00:00:00', $toDate . ' 23:59:59']);
    $statusTotals = array_column($statusSt->fetchAll(), 'cnt', 'status_name');
    $grandTotal   = array_sum($statusTotals);

    // ── Output ──
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Staff_Performance_Report_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
           xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
           xmlns:x="urn:schemas-microsoft-com:office:excel">
    <Styles>
        <Style ss:ID="header">
            <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="9"/>
            <Interior ss:Color="#0A0F1E" ss:Pattern="Solid"/>
            <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
            <Borders>
                <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#C9A84C"/>
                <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#2A3550"/>
            </Borders>
        </Style>
        <Style ss:ID="title">
            <Font ss:Bold="1" ss:Size="13" ss:Color="#0A0F1E"/>
            <Interior ss:Color="#FEF9EC" ss:Pattern="Solid"/>
        </Style>
        <Style ss:ID="subtitle">
            <Font ss:Italic="1" ss:Size="9" ss:Color="#6B7280"/>
            <Interior ss:Color="#FAFAFA" ss:Pattern="Solid"/>
        </Style>
        <Style ss:ID="section_header">
            <Font ss:Bold="1" ss:Size="9" ss:Color="#C9A84C"/>
            <Interior ss:Color="#0A0F1E" ss:Pattern="Solid"/>
        </Style>
        <Style ss:ID="odd">
            <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
            <Font ss:Size="8"/>
            <Borders>
                <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
                <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
            </Borders>
        </Style>
        <Style ss:ID="even">
            <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
            <Font ss:Size="8"/>
            <Borders>
                <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
                <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
            </Borders>
        </Style>
        <Style ss:ID="done_cell">
            <Interior ss:Color="#ECFDF5" ss:Pattern="Solid"/>
            <Font ss:Bold="1" ss:Color="#10B981" ss:Size="8"/>
            <Alignment ss:Horizontal="Center"/>
        </Style>
        <Style ss:ID="wip_cell">
            <Interior ss:Color="#FFFBEB" ss:Pattern="Solid"/>
            <Font ss:Bold="1" ss:Color="#F59E0B" ss:Size="8"/>
            <Alignment ss:Horizontal="Center"/>
        </Style>
        <Style ss:ID="pending_cell">
            <Interior ss:Color="#FEF2F2" ss:Pattern="Solid"/>
            <Font ss:Bold="1" ss:Color="#EF4444" ss:Size="8"/>
            <Alignment ss:Horizontal="Center"/>
        </Style>
        <Style ss:ID="hbc_cell">
            <Interior ss:Color="#EFF6FF" ss:Pattern="Solid"/>
            <Font ss:Bold="1" ss:Color="#3B82F6" ss:Size="8"/>
            <Alignment ss:Horizontal="Center"/>
        </Style>
        <Style ss:ID="overdue_cell">
            <Interior ss:Color="#FFF1F2" ss:Pattern="Solid"/>
            <Font ss:Bold="1" ss:Color="#E11D48" ss:Size="8"/>
            <Alignment ss:Horizontal="Center"/>
        </Style>
        <Style ss:ID="total_cell">
            <Font ss:Bold="1" ss:Size="8"/>
            <Alignment ss:Horizontal="Center"/>
            <Interior ss:Color="#F9FAFB" ss:Pattern="Solid"/>
        </Style>
        <Style ss:ID="pct_cell">
            <Interior ss:Color="#F0FDF4" ss:Pattern="Solid"/>
            <Font ss:Color="#16A34A" ss:Bold="1" ss:Size="8"/>
            <Alignment ss:Horizontal="Center"/>
            <NumberFormat ss:Format="0&quot;%&quot;"/>
        </Style>
        <Style ss:ID="footer_row">
            <Interior ss:Color="#0A0F1E" ss:Pattern="Solid"/>
            <Font ss:Bold="1" ss:Color="#C9A84C" ss:Size="9"/>
            <Alignment ss:Horizontal="Center"/>
        </Style>
        <Style ss:ID="status_label">
            <Font ss:Bold="1" ss:Size="8" ss:Color="#374151"/>
            <Interior ss:Color="#F3F4F6" ss:Pattern="Solid"/>
        </Style>
        <Style ss:ID="num">
            <Alignment ss:Horizontal="Center"/>
            <Font ss:Size="8"/>
        </Style>
    </Styles>' . "\n";

    // ═══════════════════════════════════
    // SHEET 1: Staff Performance
    // ═══════════════════════════════════
    echo '<Worksheet ss:Name="Staff Performance"><Table>' . "\n";

    // Column widths
    foreach ([30, 40, 100, 80, 60, 40, 30, 30, 30, 30, 30, 30, 30, 30, 35, 35] as $w) {
        echo "<Column ss:Width=\"{$w}\"/>\n";
    }

    // Title
    echo "<Row ss:Height=\"22\">\n";
    echo "<Cell ss:MergeAcross=\"15\" ss:StyleID=\"title\">
            <Data ss:Type=\"String\">ASK Global Advisory — Staff Performance Report</Data>
          </Cell>\n";
    echo "</Row>\n";

    // Subtitle
    echo "<Row ss:Height=\"16\">\n";
    echo "<Cell ss:MergeAcross=\"15\" ss:StyleID=\"subtitle\">
            <Data ss:Type=\"String\">Period: {$fromDate} to {$toDate}" .
            ($employeeName ? "  |  Filter: {$employeeName}" : '') .
            "  |  Generated: " . date('d M Y H:i') . "</Data>
          </Cell>\n";
    echo "</Row>\n";

    // Empty row
    echo "<Row ss:Height=\"8\"></Row>\n";

    // Table header
    echo "<Row ss:Height=\"36\">\n";
    $headers = [
        '#', 'Employee ID', 'Full Name', 'Branch', 'Department',
        'Total', 'WIP', 'Pending', 'HBC',
        'Not Started', 'Next Year', 'Corp Team', 'NON Perf',
        'Done', 'Overdue', '% Done'
    ];
    foreach ($headers as $h) {
        echo "<Cell ss:StyleID=\"header\"><Data ss:Type=\"String\">" .
             htmlspecialchars($h, ENT_XML1) . "</Data></Cell>\n";
    }
    echo "</Row>\n";

    // Data rows
    $rowNum = 1;
    foreach ($staffData as $i => $s) {
        $pct   = $s['total'] > 0 ? round(($s['done'] / $s['total']) * 100) : 0;
        $style = ($i % 2 === 0) ? 'odd' : 'even';

        echo "<Row>\n";
        echo "<Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"Number\">{$rowNum}</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($s['employee_id'] ?? '—', ENT_XML1) . "</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($s['full_name'] ?? 'Unassigned', ENT_XML1) . "</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($s['branch_name'] ?? '—', ENT_XML1) . "</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"String\">" . htmlspecialchars($s['dept_name'] ?? '—', ENT_XML1) . "</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"total_cell\"><Data ss:Type=\"Number\">{$s['total']}</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"wip_cell\"><Data ss:Type=\"Number\">{$s['wip']}</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"pending_cell\"><Data ss:Type=\"Number\">{$s['pending']}</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"hbc_cell\"><Data ss:Type=\"Number\">{$s['hbc']}</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"num\"><Data ss:Type=\"Number\">{$s['not_started']}</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"num\"><Data ss:Type=\"Number\">{$s['next_year']}</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"num\"><Data ss:Type=\"Number\">{$s['corporate_team']}</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"num\"><Data ss:Type=\"Number\">{$s['non_performance']}</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"done_cell\"><Data ss:Type=\"Number\">{$s['done']}</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"overdue_cell\"><Data ss:Type=\"Number\">{$s['overdue']}</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"pct_cell\"><Data ss:Type=\"Number\">{$pct}</Data></Cell>\n";
        echo "</Row>\n";
        $rowNum++;
    }

    // Totals footer row
    $totDone    = array_sum(array_column($staffData, 'done'));
    $totTotal   = array_sum(array_column($staffData, 'total'));
    $totWip     = array_sum(array_column($staffData, 'wip'));
    $totPending = array_sum(array_column($staffData, 'pending'));
    $totHbc     = array_sum(array_column($staffData, 'hbc'));
    $totOverdue = array_sum(array_column($staffData, 'overdue'));
    $totPct     = $totTotal > 0 ? round(($totDone / $totTotal) * 100) : 0;

    echo "<Row ss:Height=\"20\">\n";
    echo "<Cell ss:MergeAcross=\"4\" ss:StyleID=\"footer_row\"><Data ss:Type=\"String\">TOTALS — " . count($staffData) . " staff members</Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"Number\">{$totTotal}</Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"Number\">{$totWip}</Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"Number\">{$totPending}</Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"Number\">{$totHbc}</Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"String\"></Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"String\"></Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"String\"></Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"String\"></Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"Number\">{$totDone}</Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"Number\">{$totOverdue}</Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"Number\">{$totPct}</Data></Cell>\n";
    echo "</Row>\n";

    echo "</Table></Worksheet>\n";

    // ═══════════════════════════════════
    // SHEET 2: Status Summary
    // ═══════════════════════════════════
    echo '<Worksheet ss:Name="Status Summary"><Table>' . "\n";

    foreach ([200, 100, 80] as $w) {
        echo "<Column ss:Width=\"{$w}\"/>\n";
    }

    echo "<Row ss:Height=\"22\">\n";
    echo "<Cell ss:MergeAcross=\"2\" ss:StyleID=\"title\">
            <Data ss:Type=\"String\">Status Summary — {$fromDate} to {$toDate}</Data>
          </Cell>\n";
    echo "</Row>\n";

    echo "<Row ss:Height=\"20\">\n";
    foreach (['Status', 'Count', '% of Total'] as $h) {
        echo "<Cell ss:StyleID=\"header\"><Data ss:Type=\"String\">{$h}</Data></Cell>\n";
    }
    echo "</Row>\n";

    $statusColors = [
        'Not Started'    => 'odd',
        'WIP'            => 'wip_cell',
        'Pending'        => 'pending_cell',
        'HBC'            => 'hbc_cell',
        'Next Year'      => 'odd',
        'Corporate Team' => 'odd',
        'NON Performance'=> 'odd',
        'Done'           => 'done_cell',
    ];

    foreach ($statusTotals as $statusName => $cnt) {
        $pct   = $grandTotal > 0 ? round(($cnt / $grandTotal) * 100) : 0;
        $style = $statusColors[$statusName] ?? 'odd';
        echo "<Row>\n";
        echo "<Cell ss:StyleID=\"status_label\"><Data ss:Type=\"String\">" . htmlspecialchars($statusName, ENT_XML1) . "</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"Number\">{$cnt}</Data></Cell>\n";
        echo "<Cell ss:StyleID=\"pct_cell\"><Data ss:Type=\"Number\">{$pct}</Data></Cell>\n";
        echo "</Row>\n";
    }

    // Grand total
    echo "<Row ss:Height=\"18\">\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"String\">TOTAL</Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"Number\">{$grandTotal}</Data></Cell>\n";
    echo "<Cell ss:StyleID=\"footer_row\"><Data ss:Type=\"Number\">100</Data></Cell>\n";
    echo "</Row>\n";

    echo "</Table></Worksheet>\n";
    echo "</Workbook>\n";
    exit;
}
// ── Fallback ──────────────────────────────────────────────────
else {
    header('Content-Type: text/plain');
    echo 'Unknown module: ' . htmlspecialchars($module);
    exit;
}