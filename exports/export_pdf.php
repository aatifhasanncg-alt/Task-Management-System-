<?php
/**
 * export_pdf.php — Universal PDF Export
 * ─────────────────────────────────────
 * Usage: export_pdf.php?module=MODULENAME&from=YYYY-MM-DD&to=YYYY-MM-DD[&extra_filters]
 *
 * Supported modules:
 *   tasks            — full task list (filters: dept_id, branch_id, staff_id, status)
 *   executive_report — KPI summary + dept/branch breakdown
 *   department_wise  — dept performance with all statuses (dynamic from DB)
 *   branch_wise      — branch performance with all statuses (dynamic from DB)
 *   staff_wise       — per-staff task breakdown
 *   company_workflow — workflow chain for a single company (?company_id=N)
 *   date_wise        — day-by-day task trend
 *   report           — admin staff-summary report
 *
 * To add a new module:
 *   1. Add a new `elseif ($module === 'your_module')` block at the bottom
 *   2. Use the helper functions: pdfSection(), tableHeader(), tableRow(), kpiTiles()
 *   3. Set $filename at the end of your block
 */

require_once '../config/db.php';
require_once '../config/config.php';
require_once '../config/session.php';
requireAnyRole();

// ── Load TCPDF ────────────────────────────────────────────────
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php')) {
    require_once __DIR__ . '/../vendor/tecnickcom/tcpdf.php';
} else {
    die('TCPDF not found. Run: composer require tecnickcom/tcpdf');
}

$db     = getDB();
$module = $_GET['module'] ?? 'tasks';
$from   = $_GET['from']   ?? date('Y-m-01');
$to     = $_GET['to']     ?? date('Y-m-d');

// ── Fetch all task statuses once (used by dynamic modules) ────
$statusRows = $db->query("SELECT id, status_name, color FROM task_status ORDER BY id ASC")->fetchAll();
$statusMeta = array_column($statusRows, null, 'status_name'); // keyed by name

// ── PDF Class ─────────────────────────────────────────────────
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
        // Logo box
        $this->RoundedRect(8, 3, 16, 16, 3, '1111', 'F');
        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(10, 15, 30);
        $this->SetXY(8, 9);
        $this->Cell(16, 5, 'ASK', 0, 0, 'C');
        // Title
        $this->SetFont('helvetica', 'B', 12);
        $this->SetTextColor(201, 168, 76);
        $this->SetXY(28, 4);
        $this->Cell(160, 7, $this->reportTitle, 0, 0, 'L');
        // Subtitle
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(136, 153, 170);
        $this->SetXY(28, 12);
        $this->Cell(160, 5, 'ASK Global Advisory Pvt. Ltd. — MISPro', 0, 0, 'L');
        // Date
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
        $this->Cell(0, 6,
            'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages() .
            '  ·  MISPro — ASK Global Advisory Pvt. Ltd.  ·  CONFIDENTIAL',
            0, 0, 'C');
    }
}

// ══════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ══════════════════════════════════════════════════════════════

/** Convert #rrggbb hex to [r,g,b] array */
function hex2rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}

/** Print a section heading */
function pdfSection(MISPdf $pdf, string $title): void {
    if ($pdf->GetY() > 160) $pdf->AddPage();
    $pdf->Ln(4);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(10, 15, 30);
    $pdf->Cell(0, 8, $title, 0, 1, 'L');
}

/** Print period + row-count line */
function pdfPeriod(MISPdf $pdf, string $from, string $to, int $count = 0): void {
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $line = 'Period: ' . date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));
    if ($count) $line .= '  ·  ' . $count . ' records';
    $pdf->Cell(0, 6, $line, 0, 1, 'L');
    $pdf->Ln(2);
}

/** Print table header row */
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

/**
 * Print a single table data row.
 * @param array $aligns  Per-cell alignment ('L','C','R'). Defaults to 'L'.
 * @param array $highlights  Keys where value should be coloured: [colIndex => '#hexcolor']
 */
function tableRow(
    MISPdf $pdf,
    array  $cells,
    array  $widths,
    bool   $odd,
    array  $aligns     = [],
    array  $highlights = []
): void {
    $pdf->SetFillColor($odd ? 255 : 248, $odd ? 255 : 250, $odd ? 255 : 251);
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetDrawColor(30, 42, 64);

    foreach ($cells as $i => $cell) {
        $align = $aligns[$i] ?? 'L';
        if (isset($highlights[$i])) {
            [$r,$g,$b] = hex2rgb($highlights[$i]);
            $pdf->SetTextColor($r, $g, $b);
        } else {
            $pdf->SetTextColor(31, 41, 55);
        }
        $pdf->Cell($widths[$i], 6, $cell, 1, 0, $align, !$odd);
    }
    $pdf->Ln();
}

/**
 * Render KPI tile row.
 * @param array $tiles  [['label', value, '#hexcolor'], ...]
 */
function kpiTiles(MISPdf $pdf, array $tiles): void {
    $x = 12; $y = $pdf->GetY();
    $tileW = min(43, (int)(258 / count($tiles)));
    foreach ($tiles as [$lbl, $val, $col]) {
        [$r,$g,$b] = hex2rgb($col);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($x, $y, $tileW, 19, 2, '1111', 'DF');
        $pdf->SetFillColor($r, $g, $b);
        $pdf->Rect($x, $y, 3, 19, 'F');
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY($x + 5, $y + 2);
        $pdf->Cell($tileW - 7, 8, (string)$val, 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->SetXY($x + 5, $y + 11);
        $pdf->Cell($tileW - 7, 6, $lbl, 0, 0, 'L');
        $x += $tileW + 2;
    }
    $pdf->SetY($y + 25);
}

/** Build dynamic SQL CASE columns from $statusRows */
function buildStatusCases(array $statusRows): string {
    $sql = '';
    foreach ($statusRows as $sr) {
        $sn = addslashes($sr['status_name']);
        $sql .= "SUM(CASE WHEN ts.status_name='{$sn}' THEN 1 ELSE 0 END) AS `s_{$sr['id']}`,\n";
    }
    return $sql;
}

/** Auto-paginate: add new page + reprint header if near bottom */
function checkPageBreak(MISPdf $pdf, array $cols, array $widths, int $threshold = 178): void {
    if ($pdf->GetY() > $threshold) {
        $pdf->AddPage();
        tableHeader($pdf, $cols, $widths);
    }
}

/** Get status colour from the global statusMeta map */
function statusColor(array $statusMeta, string $statusName): string {
    return $statusMeta[$statusName]['color'] ?? '#9ca3af';
}


// ══════════════════════════════════════════════════════════════
// MODULE: tasks
// ══════════════════════════════════════════════════════════════
if ($module === 'tasks') {

    $where  = ['t.is_active = 1', 't.created_at BETWEEN ? AND ?'];
    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];

    if (!empty($_GET['dept_id']))   { $where[] = 't.department_id = ?'; $params[] = (int)$_GET['dept_id']; }
    if (!empty($_GET['branch_id'])) { $where[] = 't.branch_id = ?';     $params[] = (int)$_GET['branch_id']; }
    if (!empty($_GET['staff_id']))  { $where[] = 't.assigned_to = ?';   $params[] = (int)$_GET['staff_id']; }
    if (!empty($_GET['status']))    { $where[] = 'ts.status_name = ?';  $params[] = $_GET['status']; }

    $ws  = implode(' AND ', $where);
    $st  = $db->prepare("
        SELECT t.task_number, t.title,
               ts.status_name AS status, ts.color AS status_color,
               t.priority,
               d.dept_name, b.branch_name, c.company_name,
               ua.full_name AS assigned_to,
               t.due_date, t.fiscal_year
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id  = t.status_id
        LEFT JOIN departments d  ON d.id   = t.department_id
        LEFT JOIN branches b     ON b.id   = t.branch_id
        LEFT JOIN companies c    ON c.id   = t.company_id
        LEFT JOIN users ua       ON ua.id  = t.assigned_to
        WHERE {$ws}
        ORDER BY t.created_at DESC
        LIMIT 2000
    ");
    $st->execute($params);
    $data = $st->fetchAll();

    $pdf = new MISPdf('Task List Report');
    $pdf->AddPage();
    pdfPeriod($pdf, $from, $to, count($data));

    $cols   = ['Task #','Title','Status','Priority','Dept','Branch','Company','Assigned To','Due Date','FY'];
    $widths = [24, 55, 22, 16, 22, 28, 32, 30, 22, 14];
    $aligns = ['L','L','C','C','L','L','L','L','C','C'];
    tableHeader($pdf, $cols, $widths);

    $odd = true;
    foreach ($data as $r) {
        checkPageBreak($pdf, $cols, $widths);
        tableRow($pdf, [
            $r['task_number'],
            mb_strimwidth($r['title'] ?? '', 0, 45, '…'),
            $r['status'] ?? '—',
            ucfirst($r['priority'] ?? ''),
            mb_strimwidth($r['dept_name']    ?? '—', 0, 14, '…'),
            mb_strimwidth($r['branch_name']  ?? '—', 0, 18, '…'),
            mb_strimwidth($r['company_name'] ?? '—', 0, 20, '…'),
            mb_strimwidth($r['assigned_to']  ?? '—', 0, 18, '…'),
            $r['due_date'] ? date('M j, Y', strtotime($r['due_date'])) : '—',
            $r['fiscal_year'] ?? '—',
        ], $widths, $odd, $aligns, [2 => $r['status_color'] ?? '#9ca3af']);
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

    // ── KPI tiles from DB ──
    $kpis = [['Total Tasks', $db->query("SELECT COUNT(*) FROM tasks WHERE is_active=1")->fetchColumn(), '#3b82f6']];
    foreach ($statusRows as $sr) {
        $cnt = $db->prepare("SELECT COUNT(*) FROM tasks t JOIN task_status ts ON ts.id=t.status_id WHERE ts.status_name=? AND t.is_active=1");
        $cnt->execute([$sr['status_name']]);
        $kpis[] = [$sr['status_name'], $cnt->fetchColumn(), $sr['color'] ?: '#9ca3af'];
    }
    $kpis[] = ['Companies', $db->query("SELECT COUNT(*) FROM companies WHERE is_active=1")->fetchColumn(), '#c9a84c'];
    $kpis[] = ['Staff',     $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_name='staff' AND u.is_active=1")->fetchColumn(), '#8b5cf6'];

    pdfSection($pdf, 'Key Performance Indicators');
    kpiTiles($pdf, $kpis);

    // ── Tasks by Department — dynamic statuses ──
    $sc   = buildStatusCases($statusRows);
    $dept = $db->query("
        SELECT d.dept_name, d.color,
               COUNT(t.id) as total, {$sc}
               SUM(CASE WHEN ts.status_name='Done' THEN 1 ELSE 0 END) as done
        FROM departments d
        LEFT JOIN tasks t        ON t.department_id = d.id AND t.is_active = 1
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE d.is_active = 1 AND d.dept_name != 'Core Admin'
        GROUP BY d.id, d.dept_name, d.color ORDER BY total DESC
    ")->fetchAll();

    pdfSection($pdf, 'Tasks by Department');
    $dCols   = array_merge(['Department','Total'], array_column($statusRows,'status_name'), ['% Done']);
    $dWidths = array_merge([65,20], array_fill(0, count($statusRows), 22), [22]);
    $dAligns = array_merge(['L','C'], array_fill(0, count($statusRows), 'C'), ['C']);
    tableHeader($pdf, $dCols, $dWidths);

    $odd = true;
    foreach ($dept as $d) {
        $pct  = $d['total'] ? round(($d['done'] / $d['total']) * 100) : 0;
        $cells = [$d['dept_name'], $d['total']];
        foreach ($statusRows as $sr) $cells[] = $d['s_' . $sr['id']] ?? 0;
        $cells[] = $pct . '%';
        tableRow($pdf, $cells, $dWidths, $odd, $dAligns);
        $odd = !$odd;
    }

    // ── Tasks by Branch ──
    $branch = $db->query("
        SELECT b.branch_name,
               COUNT(t.id) as total,
               SUM(CASE WHEN ts.status_name='Done'    THEN 1 ELSE 0 END) as done,
               SUM(CASE WHEN ts.status_name='WIP'     THEN 1 ELSE 0 END) as wip,
               SUM(CASE WHEN ts.status_name='Pending' THEN 1 ELSE 0 END) as pending
        FROM branches b
        LEFT JOIN tasks t        ON t.branch_id = b.id AND t.is_active = 1
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE b.is_active = 1
        GROUP BY b.id, b.branch_name ORDER BY total DESC
    ")->fetchAll();

    pdfSection($pdf, 'Tasks by Branch');
    $bCols   = ['Branch','Total','WIP','Pending','Done','% Done'];
    $bWidths = [80,30,30,35,30,30];
    $bAligns = ['L','C','C','C','C','C'];
    tableHeader($pdf, $bCols, $bWidths);

    $odd = true;
    foreach ($branch as $b) {
        $pct = $b['total'] ? round(($b['done'] / $b['total']) * 100) : 0;
        tableRow($pdf, [$b['branch_name'], $b['total'], $b['wip'], $b['pending'], $b['done'], $pct . '%'], $bWidths, $odd, $bAligns);
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
    pdfPeriod($pdf, $from, $to);

    $sc   = buildStatusCases($statusRows);
    $stmt = $db->prepare("
        SELECT d.dept_name, d.color,
               COUNT(t.id) as total, {$sc}
               SUM(CASE WHEN t.due_date < CURDATE() AND ts.status_name != 'Done' THEN 1 ELSE 0 END) as overdue
        FROM departments d
        LEFT JOIN tasks t        ON t.department_id = d.id AND t.is_active = 1
            AND t.created_at BETWEEN ? AND ?
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE d.is_active = 1 AND d.dept_name != 'Core Admin'
        GROUP BY d.id, d.dept_name, d.color ORDER BY total DESC
    ");
    $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $data = $stmt->fetchAll();

    $cols   = array_merge(['Department','Total'], array_column($statusRows,'status_name'), ['Overdue','% Done']);
    $widths = array_merge([60,20], array_fill(0, count($statusRows), 22), [22,22]);
    $aligns = array_merge(['L','C'], array_fill(0, count($statusRows), 'C'), ['C','C']);
    tableHeader($pdf, $cols, $widths);

    // Find done id for % calc
    $doneId = null;
    foreach ($statusRows as $sr) { if (strtolower($sr['status_name']) === 'done') { $doneId = $sr['id']; break; } }

    $odd = true;
    foreach ($data as $d) {
        $doneVal = $doneId ? (int)($d['s_' . $doneId] ?? 0) : 0;
        $pct     = $d['total'] ? round(($doneVal / $d['total']) * 100) : 0;
        $cells   = [$d['dept_name'], $d['total']];
        foreach ($statusRows as $sr) $cells[] = $d['s_' . $sr['id']] ?? 0;
        $cells[] = $d['overdue'];
        $cells[] = $pct . '%';
        // Highlight overdue in red
        $hi = [count($cells) - 2 => '#dc2626'];
        tableRow($pdf, $cells, $widths, $odd, $aligns, $hi);
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
    pdfPeriod($pdf, $from, $to);

    $sc   = buildStatusCases($statusRows);
    $stmt = $db->prepare("
        SELECT b.branch_name, b.city,
               COUNT(t.id) as total, {$sc}
               SUM(CASE WHEN t.due_date < CURDATE() AND ts.status_name != 'Done' THEN 1 ELSE 0 END) as overdue
        FROM branches b
        LEFT JOIN tasks t        ON t.branch_id = b.id AND t.is_active = 1
            AND t.created_at BETWEEN ? AND ?
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE b.is_active = 1
        GROUP BY b.id, b.branch_name, b.city ORDER BY total DESC
    ");
    $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $data = $stmt->fetchAll();

    $cols   = array_merge(['Branch','City','Total'], array_column($statusRows,'status_name'), ['Overdue','% Done']);
    $widths = array_merge([50,30,18], array_fill(0, count($statusRows), 20), [20,20]);
    $aligns = array_merge(['L','L','C'], array_fill(0, count($statusRows), 'C'), ['C','C']);
    tableHeader($pdf, $cols, $widths);

    $doneId = null;
    foreach ($statusRows as $sr) { if (strtolower($sr['status_name']) === 'done') { $doneId = $sr['id']; break; } }

    $odd = true;
    foreach ($data as $b) {
        $doneVal = $doneId ? (int)($b['s_' . $doneId] ?? 0) : 0;
        $pct     = $b['total'] ? round(($doneVal / $b['total']) * 100) : 0;
        $cells   = [$b['branch_name'], $b['city'] ?? '—', $b['total']];
        foreach ($statusRows as $sr) $cells[] = $b['s_' . $sr['id']] ?? 0;
        $cells[] = $b['overdue'];
        $cells[] = $pct . '%';
        $hi = [count($cells) - 2 => '#dc2626'];
        tableRow($pdf, $cells, $widths, $odd, $aligns, $hi);
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
    pdfPeriod($pdf, $from, $to);

    $stmt = $db->prepare("
        SELECT u.full_name, u.employee_id,
               b.branch_name, d.dept_name,
               COUNT(t.id) as total,
               SUM(CASE WHEN ts.status_name='Done'    THEN 1 ELSE 0 END) as done,
               SUM(CASE WHEN ts.status_name='WIP'     THEN 1 ELSE 0 END) as wip,
               SUM(CASE WHEN ts.status_name='Pending' THEN 1 ELSE 0 END) as pending,
               SUM(CASE WHEN t.due_date < CURDATE() AND ts.status_name != 'Done' THEN 1 ELSE 0 END) as overdue
        FROM users u
        LEFT JOIN roles r        ON r.id  = u.role_id
        LEFT JOIN branches b     ON b.id  = u.branch_id
        LEFT JOIN departments d  ON d.id  = u.department_id
        LEFT JOIN tasks t        ON t.assigned_to = u.id AND t.is_active = 1
            AND t.created_at BETWEEN ? AND ?
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE r.role_name = 'staff' AND u.is_active = 1
        GROUP BY u.id, u.full_name, u.employee_id, b.branch_name, d.dept_name
        ORDER BY total DESC
    ");
    $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $data = $stmt->fetchAll();

    $cols   = ['Staff Member','Emp ID','Branch','Department','Total','WIP','Pending','Done','Overdue','% Done'];
    $widths = [48, 24, 35, 30, 18, 18, 22, 18, 22, 20];
    $aligns = ['L','C','L','L','C','C','C','C','C','C'];
    tableHeader($pdf, $cols, $widths);

    $odd = true;
    foreach ($data as $s) {
        checkPageBreak($pdf, $cols, $widths);
        $pct = $s['total'] ? round(($s['done'] / $s['total']) * 100) : 0;
        tableRow($pdf, [
            mb_strimwidth($s['full_name'] ?? 'Unassigned', 0, 28, '…'),
            $s['employee_id'] ?? '—',
            mb_strimwidth($s['branch_name'] ?? '—', 0, 20, '…'),
            mb_strimwidth($s['dept_name']   ?? '—', 0, 18, '…'),
            $s['total'], $s['wip'], $s['pending'], $s['done'], $s['overdue'], $pct . '%'
        ], $widths, $odd, $aligns, [8 => '#dc2626']);
        $odd = !$odd;
    }

    $filename = 'Staff_Performance_' . date('Ymd_His') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// MODULE: company_workflow
// ══════════════════════════════════════════════════════════════
elseif ($module === 'company_workflow') {

    $companyId = (int)($_GET['company_id'] ?? 0);
    $coStmt    = $db->prepare("
        SELECT c.*, b.branch_name, ct.type_name AS company_type_name
        FROM companies c
        LEFT JOIN branches b       ON b.id  = c.branch_id
        LEFT JOIN company_types ct ON ct.id = c.company_type_id
        WHERE c.id = ?
    ");
    $coStmt->execute([$companyId]);
    $company = $coStmt->fetch();
    if (!$company) die('Company not found.');

    $pdf = new MISPdf('Workflow: ' . $company['company_name']);
    $pdf->AddPage();

    // Company header block
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

    $taskSt = $db->prepare("
        SELECT t.*, ts.status_name AS status, ts.color AS status_color,
               d.dept_name, ua.full_name AS assigned_to_name
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
        checkPageBreak($pdf, [], [], 170);

        // Task header bar
        $pdf->SetFillColor(17, 24, 39);
        $pdf->SetTextColor(201, 168, 76);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(0, 7, '  ' . $t['task_number'] . '  —  ' . mb_strimwidth($t['title'], 0, 80, '…'), 0, 1, 'L', true);

        // Task meta row
        [$sr,$sg,$sb] = hex2rgb($t['status_color'] ?? '#9ca3af');
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor($sr, $sg, $sb);
        $pdf->Cell(50, 5, '  Status: ' . $t['status'], 0, 0, 'L');
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(55, 5, 'Dept: ' . ($t['dept_name'] ?? '—'), 0, 0, 'L');
        $pdf->Cell(65, 5, 'Assigned: ' . ($t['assigned_to_name'] ?? '—'), 0, 0, 'L');
        $pdf->Cell(55, 5, 'Due: ' . ($t['due_date'] ? date('M j, Y', strtotime($t['due_date'])) : '—'), 0, 1, 'L');

        // Workflow events
        $wfSt = $db->prepare("
            SELECT tw.*,
                   u1.full_name AS from_name, u2.full_name AS to_name,
                   d1.dept_name AS from_dept,  d2.dept_name AS to_dept
            FROM task_workflow tw
            LEFT JOIN users u1       ON u1.id = tw.from_user_id
            LEFT JOIN users u2       ON u2.id = tw.to_user_id
            LEFT JOIN departments d1 ON d1.id = tw.from_dept_id
            LEFT JOIN departments d2 ON d2.id = tw.to_dept_id
            WHERE tw.task_id = ? ORDER BY tw.created_at ASC
        ");
        try { $wfSt->execute([$t['id']]); $wf = $wfSt->fetchAll(); }
        catch (Exception $e) { $wf = []; }

        if (!empty($wf)) {
            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->SetTextColor(156, 163, 175);
            $pdf->Cell(0, 5, '  Workflow:', 0, 1, 'L');

            foreach ($wf as $w) {
                checkPageBreak($pdf, [], [], 185);
                $pdf->SetFont('helvetica', '', 7);
                $pdf->SetTextColor(55, 65, 81);

                $line = '    [' . date('M j, Y H:i', strtotime($w['created_at'])) . ']  '
                      . ucwords(str_replace('_', ' ', $w['action']));
                if ($w['from_name']) $line .= '  by ' . $w['from_name'];
                if ($w['from_dept']) $line .= ' (' . $w['from_dept'] . ')';
                if ($w['to_name'])   $line .= '  →  ' . $w['to_name'];
                if ($w['to_dept'] && $w['to_dept'] !== $w['from_dept']) $line .= ' (' . $w['to_dept'] . ')';
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

        $pdf->Ln(2);
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
    pdfPeriod($pdf, $from, $to);

    $sc   = buildStatusCases($statusRows);
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(t.created_at,'%Y-%m-%d') AS day,
               COUNT(t.id) AS total, {$sc}
               0 AS _dummy
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active = 1 AND t.created_at BETWEEN ? AND ?
        GROUP BY day ORDER BY day ASC
    ");
    $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $data = $stmt->fetchAll();

    $cols   = array_merge(['Date','Total'], array_column($statusRows,'status_name'));
    $widths = array_merge([38, 22], array_fill(0, count($statusRows), 22));
    $aligns = array_fill(0, count($cols), 'C');
    tableHeader($pdf, $cols, $widths);

    $totals = array_fill(0, count($statusRows), 0);
    $grandTotal = 0;
    $odd = true;
    foreach ($data as $row) {
        checkPageBreak($pdf, $cols, $widths);
        $cells = [date('d M Y', strtotime($row['day'])), $row['total']];
        foreach ($statusRows as $i => $sr) {
            $v = (int)($row['s_' . $sr['id']] ?? 0);
            $cells[]    = $v;
            $totals[$i] += $v;
        }
        $grandTotal += $row['total'];
        tableRow($pdf, $cells, $widths, $odd, $aligns);
        $odd = !$odd;
    }

    // Totals row
    $pdf->SetFillColor(10, 15, 30);
    $pdf->SetTextColor(201, 168, 76);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell($widths[0], 7, 'TOTAL', 1, 0, 'C', true);
    $pdf->Cell($widths[1], 7, $grandTotal, 1, 0, 'C', true);
    foreach ($totals as $i => $tv) {
        $pdf->Cell($widths[$i + 2], 7, $tv, 1, 0, 'C', true);
    }
    $pdf->Ln();

    $filename = 'Date_Trend_' . date('Ymd_His') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// MODULE: report (admin staff-summary)
// ══════════════════════════════════════════════════════════════
elseif ($module === 'report') {

    $pdf = new MISPdf('Staff Summary Report');
    $pdf->AddPage();
    pdfPeriod($pdf, $from, $to);

    $where  = ['t.is_active = 1', 't.created_at BETWEEN ? AND ?'];
    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
    if (!empty($_GET['branch_id'])) { $where[] = 't.branch_id = ?';    $params[] = (int)$_GET['branch_id']; }
    if (!empty($_GET['dept_id']))   { $where[] = 't.department_id = ?'; $params[] = (int)$_GET['dept_id']; }
    $ws = implode(' AND ', $where);

    $sc   = buildStatusCases($statusRows);
    $stmt = $db->prepare("
        SELECT u.full_name, u.employee_id, b.branch_name,
               COUNT(t.id) as total, {$sc}
               SUM(CASE WHEN t.due_date < CURDATE() AND ts.status_name != 'Done' THEN 1 ELSE 0 END) as overdue
        FROM tasks t
        LEFT JOIN users u        ON u.id  = t.assigned_to
        LEFT JOIN branches b     ON b.id  = u.branch_id
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE {$ws}
        GROUP BY t.assigned_to, u.full_name, u.employee_id, b.branch_name
        ORDER BY total DESC
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    $cols   = array_merge(['Staff Member','Emp ID','Branch','Total'], array_column($statusRows,'status_name'), ['Overdue','% Done']);
    $sCount = count($statusRows);
    $widths = array_merge([48,22,35,18], array_fill(0, $sCount, 20), [20,20]);
    $aligns = array_merge(['L','C','L','C'], array_fill(0, $sCount,'C'), ['C','C']);
    tableHeader($pdf, $cols, $widths);

    $doneId = null;
    foreach ($statusRows as $sr) { if (strtolower($sr['status_name']) === 'done') { $doneId = $sr['id']; break; } }

    $odd = true;
    foreach ($data as $s) {
        checkPageBreak($pdf, $cols, $widths);
        $doneVal = $doneId ? (int)($s['s_' . $doneId] ?? 0) : 0;
        $pct     = $s['total'] ? round(($doneVal / $s['total']) * 100) : 0;
        $cells   = [
            mb_strimwidth($s['full_name'] ?? 'Unassigned', 0, 26, '…'),
            $s['employee_id'] ?? '—',
            mb_strimwidth($s['branch_name'] ?? '—', 0, 18, '…'),
            $s['total'],
        ];
        foreach ($statusRows as $sr) $cells[] = $s['s_' . $sr['id']] ?? 0;
        $cells[] = $s['overdue'];
        $cells[] = $pct . '%';
        $hi = [count($cells) - 2 => '#dc2626'];
        tableRow($pdf, $cells, $widths, $odd, $aligns, $hi);
        $odd = !$odd;
    }

    $filename = 'Report_' . date('Ymd_His') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// FALLBACK
// ══════════════════════════════════════════════════════════════
else {
    $pdf = new MISPdf('Unknown Report');
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(0, 10, 'Unknown report module: ' . htmlspecialchars($module), 0, 1, 'C');
    $filename = 'Report_' . date('Ymd') . '.pdf';
}
if (ob_get_length()) {
    ob_end_clean();
}
// ── Output PDF ────────────────────────────────────────────────

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$pdf->Output($filename, 'D');
exit;