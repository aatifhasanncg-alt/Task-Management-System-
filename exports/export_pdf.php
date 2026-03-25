<?php
require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';
requireAnyRole();
// Replace the TCPDF require with:
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../vendor/tcpdf/tcpdf.php')) {
    require_once __DIR__ . '/../vendor/tcpdf/tcpdf.php';
} elseif (file_exists(__DIR__ . '/../vendor/SimplePDF.php')) {
    require_once __DIR__ . '/../vendor/SimplePDF.php';
} else {
    die('No PDF library found. Please install TCPDF.');
}

$db     = getDB();
$user   = currentUser();
$module = $_GET['module'] ?? 'tasks';
$from   = $_GET['from']   ?? date('Y-m-01');
$to     = $_GET['to']     ?? date('Y-m-d');

// ── Custom TCPDF class ────────────────────────────────────────
class MISPdf extends TCPDF {
    private string $reportTitle;
    public function __construct(string $title) {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8');
        $this->reportTitle = $title;
        $this->SetCreator('MISPro — ASK Global Advisory');
        $this->SetAuthor('MISPro');
        $this->SetTitle($title);
        $this->setPrintHeader(true);
        $this->setPrintFooter(true);
        $this->SetMargins(12, 28, 12);
        $this->SetHeaderMargin(6);
        $this->SetFooterMargin(12);
        $this->SetAutoPageBreak(true, 20);
    }
    public function Header(): void {
        $this->SetFillColor(10, 15, 30);
        $this->Rect(0, 0, $this->getPageWidth(), 22, 'F');
        $this->SetFillColor(201, 168, 76);
        $this->Rect(0, 22, $this->getPageWidth(), 1.5, 'F');
        $this->SetFillColor(201, 168, 76);
        $this->RoundedRect(8, 3, 16, 16, 3, '1111', 'F');
        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(10, 15, 30);
        $this->SetXY(8, 9);
        $this->Cell(16, 5, 'ASK', 0, 0, 'C');
        $this->SetFont('helvetica', 'B', 12);
        $this->SetTextColor(201, 168, 76);
        $this->SetXY(28, 4);
        $this->Cell(160, 7, $this->reportTitle, 0, 0, 'L');
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(136, 153, 170);
        $this->SetXY(28, 12);
        $this->Cell(160, 5, 'ASK Global Advisory Pvt. Ltd. — "At ASK business problems end, solutions begin"', 0, 0, 'L');
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(201, 168, 76);
        $this->SetXY(-70, 8);
        $this->Cell(60, 5, 'Generated: ' . date('M j, Y H:i'), 0, 0, 'R');
    }
    public function Footer(): void {
        $this->SetY(-15);
        $this->SetFillColor(248, 249, 250);
        $this->Rect(0, $this->getY() - 2, $this->getPageWidth(), 20, 'F');
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(156, 163, 175);
        $this->Cell(0, 6, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages() . '  ·  MISPro — ASK Global Advisory Pvt. Ltd.  ·  CONFIDENTIAL', 0, 0, 'C');
    }
}

// ── Helpers ───────────────────────────────────────────────────
function rgbFromHex(string $hex): array {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function tableHeader(MISPdf $pdf, array $cols, array $widths): void {
    $pdf->SetFillColor(10, 15, 30);
    $pdf->SetTextColor(201, 168, 76);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetLineWidth(0.1);
    $pdf->SetDrawColor(30, 42, 64);
    foreach ($cols as $i => $c) {
        $pdf->Cell($widths[$i], 7, $c, 1, 0, 'C', true);
    }
    $pdf->Ln();
}

function tableRow(MISPdf $pdf, array $cells, array $widths, bool $odd, array $aligns = []): void {
    $pdf->SetFillColor($odd ? 255 : 248, $odd ? 255 : 250, $odd ? 255 : 251);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->SetFont('helvetica', '', 7);
    foreach ($cells as $i => $cell) {
        $align = $aligns[$i] ?? 'L';
        $pdf->Cell($widths[$i], 6, $cell, 1, 0, $align, !$odd);
    }
    $pdf->Ln();
}

$STATUS_COLORS = [
    'Not Started'   => '#9ca3af',
    'WIP'           => '#f59e0b',
    'Pending'       => '#ef4444',
    'HBC'           => '#3b82f6',
    'Next Year'     => '#8b5cf6',
    'Done'          => '#10b981',
    'Corporate Team'=> '#06b6d4',
    'NON Performance'=> '#ec4899',
];

// ══════════════════════════════════════════════════════════════
// MODULE: tasks
// ══════════════════════════════════════════════════════════════
if ($module === 'tasks') {

    $where  = ['t.is_active = 1', 't.created_at BETWEEN ? AND ?'];
    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];

    // Optional filters
    if (!empty($_GET['dept_id']))    { $where[] = 't.department_id = ?'; $params[] = (int)$_GET['dept_id']; }
    if (!empty($_GET['branch_id']))  { $where[] = 't.branch_id = ?';     $params[] = (int)$_GET['branch_id']; }
    if (!empty($_GET['staff_id']))   { $where[] = 't.assigned_to = ?';   $params[] = (int)$_GET['staff_id']; }
    if (!empty($_GET['status']))     { $where[] = 'ts.status_name = ?';  $params[] = $_GET['status']; }

    $ws = implode(' AND ', $where);

    $taskSt = $db->prepare("
        SELECT t.task_number, t.title,
               ts.status_name AS status,
               t.priority,
               d.dept_name, b.branch_name,
               c.company_name,
               ua.full_name AS assigned_to,
               t.due_date, t.fiscal_year, t.remarks
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        LEFT JOIN departments d  ON d.id  = t.department_id
        LEFT JOIN branches b     ON b.id  = t.branch_id
        LEFT JOIN companies c    ON c.id  = t.company_id
        LEFT JOIN users ua       ON ua.id = t.assigned_to
        WHERE {$ws}
        ORDER BY t.created_at DESC
        LIMIT 2000
    ");
    $taskSt->execute($params);
    $data = $taskSt->fetchAll();

    $pdf = new MISPdf('Task List Report');
    $pdf->AddPage();

    // Period summary
    $pdf->SetFillColor(249, 250, 251);
    $pdf->Rect(12, $pdf->GetY(), 270, 10, 'F');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->SetX(14);
    $pdf->Cell(270, 10,
        "Period: " . date('M j, Y', strtotime($from)) . " – " . date('M j, Y', strtotime($to)) .
        "  ·  " . count($data) . " tasks",
        0, 1, 'L');
    $pdf->Ln(2);

    $cols   = ['Task #', 'Title', 'Status', 'Priority', 'Dept', 'Branch', 'Company', 'Assigned To', 'Due Date', 'FY'];
    $widths = [24, 55, 20, 16, 22, 28, 32, 30, 22, 16];
    $aligns = ['L', 'L', 'C', 'C', 'L', 'L', 'L', 'L', 'C', 'C'];

    tableHeader($pdf, $cols, $widths);

    $odd = true;
    foreach ($data as $r) {
        if ($pdf->GetY() > 178) {
            $pdf->AddPage();
            tableHeader($pdf, $cols, $widths);
        }
        [$sr, $sg, $sb] = rgbFromHex($STATUS_COLORS[$r['status']] ?? '#9ca3af');

        $pdf->SetFillColor($odd ? 255 : 248, $odd ? 255 : 250, $odd ? 255 : 251);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetDrawColor(30, 42, 64);

        $pdf->SetTextColor(31, 41, 55);
        $pdf->Cell($widths[0], 6, $r['task_number'],                           1, 0, 'L', !$odd);
        $pdf->Cell($widths[1], 6, mb_strimwidth($r['title'] ?? '', 0, 45, '…'), 1, 0, 'L', !$odd);
        $pdf->SetTextColor($sr, $sg, $sb);
        $pdf->Cell($widths[2], 6, $r['status'],                                1, 0, 'C', !$odd);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->Cell($widths[3], 6, ucfirst($r['priority'] ?? ''),               1, 0, 'C', !$odd);
        $pdf->Cell($widths[4], 6, mb_strimwidth($r['dept_name'] ?? '', 0, 15, '…'),    1, 0, 'L', !$odd);
        $pdf->Cell($widths[5], 6, mb_strimwidth($r['branch_name'] ?? '', 0, 18, '…'), 1, 0, 'L', !$odd);
        $pdf->Cell($widths[6], 6, mb_strimwidth($r['company_name'] ?? '—', 0, 20, '…'), 1, 0, 'L', !$odd);
        $pdf->Cell($widths[7], 6, mb_strimwidth($r['assigned_to'] ?? '—', 0, 18, '…'), 1, 0, 'L', !$odd);
        $pdf->Cell($widths[8], 6, $r['due_date'] ? date('M j, Y', strtotime($r['due_date'])) : '—', 1, 0, 'C', !$odd);
        $pdf->Cell($widths[9], 6, $r['fiscal_year'] ?? '—',                    1, 1, 'C', !$odd);
        $odd = !$odd;
    }

    $filename = 'Tasks_' . date('Ymd_His') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// MODULE: executive_report
// ══════════════════════════════════════════════════════════════
elseif ($module === 'executive_report') {

    $pdf = new MISPdf('Executive Analytics Report');
    $pdf->AddPage();

    // KPI tiles — fix: join task_status, join roles
    $kpis = [
        ['Total Tasks',   $db->query("SELECT COUNT(*) FROM tasks WHERE is_active=1")->fetchColumn(), '#3b82f6'],
        ['Done',          $db->query("SELECT COUNT(*) FROM tasks t JOIN task_status ts ON ts.id=t.status_id WHERE ts.status_name='Done' AND t.is_active=1")->fetchColumn(), '#10b981'],
        ['WIP',           $db->query("SELECT COUNT(*) FROM tasks t JOIN task_status ts ON ts.id=t.status_id WHERE ts.status_name='WIP' AND t.is_active=1")->fetchColumn(), '#f59e0b'],
        ['Pending',       $db->query("SELECT COUNT(*) FROM tasks t JOIN task_status ts ON ts.id=t.status_id WHERE ts.status_name='Pending' AND t.is_active=1")->fetchColumn(), '#ef4444'],
        ['Companies',     $db->query("SELECT COUNT(*) FROM companies WHERE is_active=1")->fetchColumn(), '#c9a84c'],
        ['Staff',         $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_name='staff' AND u.is_active=1")->fetchColumn(), '#8b5cf6'],
    ];

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(10, 15, 30);
    $pdf->Cell(0, 8, 'Key Performance Indicators', 0, 1, 'L');

    $x = 12; $y = $pdf->GetY();
    foreach ($kpis as $i => [$lbl, $val, $col]) {
        [$r, $g, $b] = rgbFromHex($col);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($x, $y, 43, 19, 2, '1111', 'DF');
        $pdf->SetFillColor($r, $g, $b);
        $pdf->Rect($x, $y, 3, 19, 'F');
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont('helvetica', 'B', 15);
        $pdf->SetXY($x + 5, $y + 2);
        $pdf->Cell(36, 8, $val, 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->SetXY($x + 5, $y + 11);
        $pdf->Cell(36, 6, $lbl, 0, 0, 'L');
        $x += 45;
    }
    $pdf->SetY($y + 25);

    // Tasks by Department — fix: dept_name, status join
    $deptData = $db->query("
        SELECT d.dept_name, d.color,
               COUNT(t.id) as total,
               SUM(CASE WHEN ts.status_name='Done'    THEN 1 ELSE 0 END) as done,
               SUM(CASE WHEN ts.status_name='WIP'     THEN 1 ELSE 0 END) as wip,
               SUM(CASE WHEN ts.status_name='Pending' THEN 1 ELSE 0 END) as pending,
               SUM(CASE WHEN ts.status_name='HBC'     THEN 1 ELSE 0 END) as hbc
        FROM departments d
        LEFT JOIN tasks t        ON t.department_id = d.id AND t.is_active = 1
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE d.is_active = 1
        GROUP BY d.id, d.dept_name, d.color
        ORDER BY total DESC
    ")->fetchAll();

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(10, 15, 30);
    $pdf->Cell(0, 8, 'Tasks by Department', 0, 1, 'L');

    $dCols   = ['Department', 'Total', 'WIP', 'Pending', 'HBC', 'Done', '% Done'];
    $dWidths = [70, 25, 25, 28, 25, 25, 25];
    $dAligns = ['L', 'C', 'C', 'C', 'C', 'C', 'C'];
    tableHeader($pdf, $dCols, $dWidths);

    $odd = true;
    foreach ($deptData as $d) {
        $pct = $d['total'] ? round(($d['done'] / $d['total']) * 100) : 0;
        tableRow($pdf, [
            $d['dept_name'], $d['total'], $d['wip'],
            $d['pending'], $d['hbc'], $d['done'], $pct . '%'
        ], $dWidths, $odd, $dAligns);
        $odd = !$odd;
    }

    $pdf->Ln(5);

    // Tasks by Branch
    $branchData = $db->query("
        SELECT b.branch_name,
               COUNT(t.id) as total,
               SUM(CASE WHEN ts.status_name='Done'    THEN 1 ELSE 0 END) as done,
               SUM(CASE WHEN ts.status_name='WIP'     THEN 1 ELSE 0 END) as wip,
               SUM(CASE WHEN ts.status_name='Pending' THEN 1 ELSE 0 END) as pending
        FROM branches b
        LEFT JOIN tasks t        ON t.branch_id = b.id AND t.is_active = 1
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE b.is_active = 1
        GROUP BY b.id, b.branch_name
        ORDER BY total DESC
    ")->fetchAll();

    if ($pdf->GetY() > 160) $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(10, 15, 30);
    $pdf->Cell(0, 8, 'Tasks by Branch', 0, 1, 'L');

    $bCols   = ['Branch', 'Total', 'WIP', 'Pending', 'Done', '% Done'];
    $bWidths = [80, 30, 30, 35, 30, 30];
    $bAligns = ['L', 'C', 'C', 'C', 'C', 'C'];
    tableHeader($pdf, $bCols, $bWidths);

    $odd = true;
    foreach ($branchData as $b) {
        $pct = $b['total'] ? round(($b['done'] / $b['total']) * 100) : 0;
        tableRow($pdf, [
            $b['branch_name'], $b['total'], $b['wip'],
            $b['pending'], $b['done'], $pct . '%'
        ], $bWidths, $odd, $bAligns);
        $odd = !$odd;
    }

    $filename = 'Executive_Report_' . date('Ymd_His') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// MODULE: department_wise
// ══════════════════════════════════════════════════════════════
elseif ($module === 'department_wise') {

    $pdf = new MISPdf('Department-wise Performance Report');
    $pdf->AddPage();

    $deptReport = $db->prepare("
        SELECT d.dept_name, d.color,
               COUNT(t.id) as total,
               SUM(CASE WHEN ts.status_name='WIP'      THEN 1 ELSE 0 END) as wip,
               SUM(CASE WHEN ts.status_name='Pending'  THEN 1 ELSE 0 END) as pending,
               SUM(CASE WHEN ts.status_name='HBC'      THEN 1 ELSE 0 END) as hbc,
               SUM(CASE WHEN ts.status_name='Done'     THEN 1 ELSE 0 END) as done,
               SUM(CASE WHEN ts.status_name='Next Year'THEN 1 ELSE 0 END) as next_year,
               SUM(CASE WHEN t.due_date < CURDATE()
                   AND ts.status_name != 'Done'        THEN 1 ELSE 0 END) as overdue
        FROM departments d
        LEFT JOIN tasks t        ON t.department_id = d.id
            AND t.is_active = 1
            AND t.created_at BETWEEN ? AND ?
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE d.is_active = 1
        GROUP BY d.id, d.dept_name, d.color
        ORDER BY total DESC
    ");
    $deptReport->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $deptReport = $deptReport->fetchAll();

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(0, 6, "Period: " . date('M j, Y', strtotime($from)) . " – " . date('M j, Y', strtotime($to)), 0, 1, 'L');
    $pdf->Ln(2);

    $cols   = ['Department', 'Total', 'WIP', 'Pending', 'HBC', 'Done', 'Next Year', 'Overdue', '% Done'];
    $widths = [60, 22, 22, 25, 22, 22, 28, 25, 22];
    $aligns = ['L', 'C', 'C', 'C', 'C', 'C', 'C', 'C', 'C'];
    tableHeader($pdf, $cols, $widths);

    $odd = true;
    foreach ($deptReport as $d) {
        $pct = $d['total'] ? round(($d['done'] / $d['total']) * 100) : 0;
        tableRow($pdf, [
            $d['dept_name'], $d['total'], $d['wip'], $d['pending'],
            $d['hbc'], $d['done'], $d['next_year'], $d['overdue'], $pct . '%'
        ], $widths, $odd, $aligns);
        $odd = !$odd;
    }

    $filename = 'Department_Report_' . date('Ymd_His') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// MODULE: branch_wise
// ══════════════════════════════════════════════════════════════
elseif ($module === 'branch_wise') {

    $pdf = new MISPdf('Branch-wise Performance Report');
    $pdf->AddPage();

    $branchReport = $db->prepare("
        SELECT b.branch_name, b.city,
               COUNT(t.id) as total,
               SUM(CASE WHEN ts.status_name='WIP'     THEN 1 ELSE 0 END) as wip,
               SUM(CASE WHEN ts.status_name='Pending' THEN 1 ELSE 0 END) as pending,
               SUM(CASE WHEN ts.status_name='HBC'     THEN 1 ELSE 0 END) as hbc,
               SUM(CASE WHEN ts.status_name='Done'    THEN 1 ELSE 0 END) as done,
               SUM(CASE WHEN t.due_date < CURDATE()
                   AND ts.status_name != 'Done'       THEN 1 ELSE 0 END) as overdue
        FROM branches b
        LEFT JOIN tasks t        ON t.branch_id = b.id
            AND t.is_active = 1
            AND t.created_at BETWEEN ? AND ?
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE b.is_active = 1
        GROUP BY b.id, b.branch_name, b.city
        ORDER BY total DESC
    ");
    $branchReport->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $branchReport = $branchReport->fetchAll();

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(0, 6, "Period: " . date('M j, Y', strtotime($from)) . " – " . date('M j, Y', strtotime($to)), 0, 1, 'L');
    $pdf->Ln(2);

    $cols   = ['Branch', 'City', 'Total', 'WIP', 'Pending', 'HBC', 'Done', 'Overdue', '% Done'];
    $widths = [55, 35, 22, 22, 25, 22, 22, 25, 22];
    $aligns = ['L', 'L', 'C', 'C', 'C', 'C', 'C', 'C', 'C'];
    tableHeader($pdf, $cols, $widths);

    $odd = true;
    foreach ($branchReport as $b) {
        $pct = $b['total'] ? round(($b['done'] / $b['total']) * 100) : 0;
        tableRow($pdf, [
            $b['branch_name'], $b['city'] ?? '—', $b['total'],
            $b['wip'], $b['pending'], $b['hbc'],
            $b['done'], $b['overdue'], $pct . '%'
        ], $widths, $odd, $aligns);
        $odd = !$odd;
    }

    $filename = 'Branch_Report_' . date('Ymd_His') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// MODULE: staff_wise
// ══════════════════════════════════════════════════════════════
elseif ($module === 'staff_wise') {

    $pdf = new MISPdf('Staff Performance Report');
    $pdf->AddPage();

    // Fix: join roles not u.role, status via task_status
    $staffData = $db->prepare("
        SELECT u.full_name, u.employee_id,
               b.branch_name, d.dept_name,
               COUNT(t.id) as total,
               SUM(CASE WHEN ts.status_name='Done'    THEN 1 ELSE 0 END) as done,
               SUM(CASE WHEN ts.status_name='WIP'     THEN 1 ELSE 0 END) as wip,
               SUM(CASE WHEN ts.status_name='Pending' THEN 1 ELSE 0 END) as pending,
               SUM(CASE WHEN t.due_date < CURDATE()
                   AND ts.status_name != 'Done'       THEN 1 ELSE 0 END) as overdue
        FROM users u
        LEFT JOIN roles r        ON r.id  = u.role_id
        LEFT JOIN branches b     ON b.id  = u.branch_id
        LEFT JOIN departments d  ON d.id  = u.department_id
        LEFT JOIN tasks t        ON t.assigned_to = u.id
            AND t.is_active = 1
            AND t.created_at BETWEEN ? AND ?
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE r.role_name = 'staff' AND u.is_active = 1
        GROUP BY u.id, u.full_name, u.employee_id, b.branch_name, d.dept_name
        ORDER BY total DESC
    ");
    $staffData->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $staffData = $staffData->fetchAll();

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(0, 6, "Period: " . date('M j, Y', strtotime($from)) . " – " . date('M j, Y', strtotime($to)) . "  ·  " . count($staffData) . " staff members", 0, 1, 'L');
    $pdf->Ln(2);

    $cols   = ['Staff Member', 'Emp ID', 'Branch', 'Department', 'Total', 'WIP', 'Pending', 'Done', 'Overdue', '% Done'];
    $widths = [48, 24, 35, 30, 18, 18, 22, 18, 22, 20];
    $aligns = ['L', 'C', 'L', 'L', 'C', 'C', 'C', 'C', 'C', 'C'];
    tableHeader($pdf, $cols, $widths);

    $odd = true;
    foreach ($staffData as $s) {
        if ($pdf->GetY() > 178) {
            $pdf->AddPage();
            tableHeader($pdf, $cols, $widths);
        }
        $pct = $s['total'] ? round(($s['done'] / $s['total']) * 100) : 0;
        tableRow($pdf, [
            mb_strimwidth($s['full_name'], 0, 28, '…'),
            $s['employee_id'] ?? '—',
            mb_strimwidth($s['branch_name'] ?? '—', 0, 20, '…'),
            mb_strimwidth($s['dept_name'] ?? '—', 0, 18, '…'),
            $s['total'], $s['wip'], $s['pending'],
            $s['done'], $s['overdue'], $pct . '%'
        ], $widths, $odd, $aligns);
        $odd = !$odd;
    }

    $filename = 'Staff_Performance_' . date('Ymd_His') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// MODULE: company_workflow
// ══════════════════════════════════════════════════════════════
elseif ($module === 'company_workflow') {

    $companyId = (int)($_GET['company_id'] ?? 0);

    // Fix: no c.department_id — removed that join
    $coStmt = $db->prepare("
        SELECT c.*, b.branch_name, ct.type_name AS company_type_name
        FROM companies c
        LEFT JOIN branches b       ON b.id  = c.branch_id
        LEFT JOIN company_types ct ON ct.id = c.company_type_id
        WHERE c.id = ?
    ");
    $coStmt->execute([$companyId]);
    $company = $coStmt->fetch();

    if (!$company) {
        die('Company not found');
    }

    $pdf = new MISPdf('Workflow Report: ' . $company['company_name']);
    $pdf->AddPage();

    // Company header
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(10, 15, 30);
    $pdf->Cell(0, 8, $company['company_name'], 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(0, 6,
        'Branch: ' . ($company['branch_name'] ?? '—') .
        '  ·  Type: ' . ($company['company_type_name'] ?? '—') .
        '  ·  PAN: ' . ($company['pan_number'] ?? '—') .
        '  ·  Code: ' . ($company['company_code'] ?? '—'),
        0, 1, 'L');
    $pdf->Ln(4);

    // Tasks for this company — fix: status via join
    $taskSt = $db->prepare("
        SELECT t.*, ts.status_name AS status,
               d.dept_name,
               ua.full_name AS assigned_to_name
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        LEFT JOIN departments d  ON d.id  = t.department_id
        LEFT JOIN users ua       ON ua.id = t.assigned_to
        WHERE t.company_id = ? AND t.is_active = 1
        ORDER BY t.created_at DESC
    ");
    $taskSt->execute([$companyId]);
    $tasks = $taskSt->fetchAll();

    if (empty($tasks)) {
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->SetTextColor(156, 163, 175);
        $pdf->Cell(0, 8, 'No tasks found for this company.', 0, 1, 'C');
    }

    foreach ($tasks as $t) {
        if ($pdf->GetY() > 170) $pdf->AddPage();

        // Task header
        $pdf->SetFillColor(17, 24, 39);
        $pdf->SetTextColor(201, 168, 76);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(0, 7,
            '  ' . $t['task_number'] . '  —  ' . mb_strimwidth($t['title'], 0, 80, '…'),
            0, 1, 'L', true);

        // Task meta
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(107, 114, 128);
        [$sr, $sg, $sb] = rgbFromHex($STATUS_COLORS[$t['status']] ?? '#9ca3af');
        $pdf->SetTextColor($sr, $sg, $sb);
        $pdf->Cell(40, 5, '  Status: ' . $t['status'], 0, 0, 'L');
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(50, 5, 'Dept: ' . ($t['dept_name'] ?? '—'), 0, 0, 'L');
        $pdf->Cell(60, 5, 'Assigned: ' . ($t['assigned_to_name'] ?? '—'), 0, 0, 'L');
        $pdf->Cell(50, 5, 'Due: ' . ($t['due_date'] ? date('M j, Y', strtotime($t['due_date'])) : '—'), 0, 1, 'L');

        // Workflow
        $wfSt = $db->prepare("
            SELECT tw.*,
                   u1.full_name AS from_name,
                   u2.full_name AS to_name,
                   d1.dept_name AS from_dept,
                   d2.dept_name AS to_dept
            FROM task_workflow tw
            LEFT JOIN users u1       ON u1.id = tw.from_user_id
            LEFT JOIN users u2       ON u2.id = tw.to_user_id
            LEFT JOIN departments d1 ON d1.id = tw.from_dept_id
            LEFT JOIN departments d2 ON d2.id = tw.to_dept_id
            WHERE tw.task_id = ?
            ORDER BY tw.created_at ASC
        ");
        try {
            $wfSt->execute([$t['id']]);
            $workflow = $wfSt->fetchAll();
        } catch (Exception $e) {
            $workflow = [];
        }

        if (!empty($workflow)) {
            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->SetTextColor(156, 163, 175);
            $pdf->Cell(0, 5, '  Workflow History:', 0, 1, 'L');

            foreach ($workflow as $w) {
                if ($pdf->GetY() > 185) $pdf->AddPage();
                $pdf->SetFont('helvetica', '', 7);
                $pdf->SetTextColor(55, 65, 81);

                $line = '    [' . date('M j, Y H:i', strtotime($w['created_at'])) . ']  '
                      . ucwords(str_replace('_', ' ', $w['action']));
                if ($w['from_name'])   $line .= '  by ' . $w['from_name'];
                if ($w['from_dept'])   $line .= ' (' . $w['from_dept'] . ')';
                if ($w['to_name'])     $line .= '  →  ' . $w['to_name'];
                if ($w['to_dept'] && $w['to_dept'] !== $w['from_dept'])
                                       $line .= ' (' . $w['to_dept'] . ')';
                if (!empty($w['new_status']) && !empty($w['old_status']))
                    $line .= '  [' . $w['old_status'] . ' → ' . $w['new_status'] . ']';

                $pdf->Cell(0, 5, mb_strimwidth($line, 0, 145, '…'), 0, 1, 'L');

                if (!empty($w['remarks'])) {
                    $pdf->SetFont('helvetica', 'I', 7);
                    $pdf->SetTextColor(107, 114, 128);
                    $pdf->Cell(0, 5, '      Note: ' . mb_strimwidth($w['remarks'], 0, 130, '…'), 0, 1, 'L');
                }
            }
        }

        $pdf->Ln(3);
        $pdf->SetDrawColor(229, 231, 235);
        $pdf->Line(12, $pdf->GetY(), $pdf->getPageWidth() - 12, $pdf->GetY());
        $pdf->Ln(3);
    }

    $filename = 'Workflow_' . preg_replace('/[^a-z0-9]/i', '_', $company['company_name']) . '_' . date('Ymd') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// MODULE: date_wise
// ══════════════════════════════════════════════════════════════
elseif ($module === 'date_wise') {

    $pdf = new MISPdf('Date-wise Task Trend Report');
    $pdf->AddPage();

    $trendData = $db->prepare("
        SELECT
            DATE_FORMAT(t.created_at, '%Y-%m-%d') AS day,
            COUNT(t.id) AS total,
            SUM(CASE WHEN ts.status_name='Done'    THEN 1 ELSE 0 END) AS done,
            SUM(CASE WHEN ts.status_name='WIP'     THEN 1 ELSE 0 END) AS wip,
            SUM(CASE WHEN ts.status_name='Pending' THEN 1 ELSE 0 END) AS pending
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active = 1
        AND t.created_at BETWEEN ? AND ?
        GROUP BY day
        ORDER BY day ASC
    ");
    $trendData->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $trendData = $trendData->fetchAll();

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(0, 6, "Period: " . date('M j, Y', strtotime($from)) . " – " . date('M j, Y', strtotime($to)), 0, 1, 'L');
    $pdf->Ln(2);

    $cols   = ['Date', 'Total Created', 'WIP', 'Pending', 'Done'];
    $widths = [50, 50, 50, 50, 50];
    $aligns = ['C', 'C', 'C', 'C', 'C'];
    tableHeader($pdf, $cols, $widths);

    $odd = true;
    $totalAll = 0;
    foreach ($trendData as $row) {
        if ($pdf->GetY() > 178) {
            $pdf->AddPage();
            tableHeader($pdf, $cols, $widths);
        }
        tableRow($pdf, [
            date('d M Y', strtotime($row['day'])),
            $row['total'], $row['wip'], $row['pending'], $row['done']
        ], $widths, $odd, $aligns);
        $totalAll += $row['total'];
        $odd = !$odd;
    }

    // Totals row
    $pdf->SetFillColor(10, 15, 30);
    $pdf->SetTextColor(201, 168, 76);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell($widths[0], 7, 'TOTAL', 1, 0, 'C', true);
    $pdf->Cell($widths[1], 7, $totalAll, 1, 0, 'C', true);
    $pdf->Cell($widths[2], 7, array_sum(array_column($trendData, 'wip')),     1, 0, 'C', true);
    $pdf->Cell($widths[3], 7, array_sum(array_column($trendData, 'pending')), 1, 0, 'C', true);
    $pdf->Cell($widths[4], 7, array_sum(array_column($trendData, 'done')),    1, 1, 'C', true);

    $filename = 'Date_Trend_' . date('Ymd_His') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// MODULE: report (admin reports page)
// ══════════════════════════════════════════════════════════════
elseif ($module === 'report') {

    $pdf = new MISPdf('Task Report');
    $pdf->AddPage();

    $where  = ['t.is_active = 1', 't.created_at BETWEEN ? AND ?'];
    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];

    if (!empty($_GET['branch_id'])) { $where[] = 't.branch_id = ?';    $params[] = (int)$_GET['branch_id']; }
    if (!empty($_GET['dept_id']))   { $where[] = 't.department_id = ?'; $params[] = (int)$_GET['dept_id']; }

    $ws = implode(' AND ', $where);

    $staffReport = $db->prepare("
        SELECT u.full_name, u.employee_id,
               b.branch_name,
               COUNT(t.id) as total,
               SUM(CASE WHEN ts.status_name='Done'    THEN 1 ELSE 0 END) as done,
               SUM(CASE WHEN ts.status_name='WIP'     THEN 1 ELSE 0 END) as wip,
               SUM(CASE WHEN ts.status_name='Pending' THEN 1 ELSE 0 END) as pending,
               SUM(CASE WHEN ts.status_name='HBC'     THEN 1 ELSE 0 END) as hbc
        FROM tasks t
        LEFT JOIN users u        ON u.id  = t.assigned_to
        LEFT JOIN branches b     ON b.id  = u.branch_id
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE {$ws}
        GROUP BY t.assigned_to, u.full_name, u.employee_id, b.branch_name
        ORDER BY total DESC
    ");
    $staffReport->execute($params);
    $staffReport = $staffReport->fetchAll();

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(0, 6, "Period: " . date('M j, Y', strtotime($from)) . " – " . date('M j, Y', strtotime($to)), 0, 1, 'L');
    $pdf->Ln(2);

    $cols   = ['Staff Member', 'Emp ID', 'Branch', 'Total', 'WIP', 'Pending', 'HBC', 'Done', '% Done'];
    $widths = [55, 28, 38, 20, 20, 25, 20, 20, 22];
    $aligns = ['L', 'C', 'L', 'C', 'C', 'C', 'C', 'C', 'C'];
    tableHeader($pdf, $cols, $widths);

    $odd = true;
    foreach ($staffReport as $s) {
        if ($pdf->GetY() > 178) {
            $pdf->AddPage();
            tableHeader($pdf, $cols, $widths);
        }
        $pct = $s['total'] ? round(($s['done'] / $s['total']) * 100) : 0;
        tableRow($pdf, [
            mb_strimwidth($s['full_name'] ?? 'Unassigned', 0, 28, '…'),
            $s['employee_id'] ?? '—',
            mb_strimwidth($s['branch_name'] ?? '—', 0, 20, '…'),
            $s['total'], $s['wip'], $s['pending'], $s['hbc'], $s['done'], $pct . '%'
        ], $widths, $odd, $aligns);
        $odd = !$odd;
    }

    $filename = 'Report_' . date('Ymd_His') . '.pdf';
}

// ── Fallback ──────────────────────────────────────────────────
else {
    $pdf = new MISPdf('Report');
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(0, 10, 'Unknown report module: ' . htmlspecialchars($module), 0, 1, 'C');
    $filename = 'Report_' . date('Ymd') . '.pdf';
}

// ── Output ────────────────────────────────────────────────────
ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$pdf->Output($filename, 'D');
exit;