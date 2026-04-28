<?php
/**
 * export_pdf.php — Universal PDF Export
 * ─────────────────────────────────────
 * Usage: export_pdf.php?module=MODULENAME&from=YYYY-MM-DD&to=YYYY-MM-DD[&extra_filters]
 *
 * Supported modules:
 *   tasks               — full task list (filters: dept_id, branch_id, staff_id, status)
 *   executive_report    — KPI summary + dept/branch breakdown
 *   department_wise     — dept performance with all statuses (dynamic from DB)
 *   branch_wise         — branch performance with all statuses (dynamic from DB)
 *   staff_wise          — per-staff task breakdown
 *   company_workflow    — workflow chain for a single company (?company_id=N)
 *   date_wise           — day-by-day task trend
 *   report              — admin staff-summary report
 *   dept_status_tasks   — detailed task list grouped by dept + status
 *                         (?dept_id=N&status=Done&branch_id=N)
 *   tax_htd             — Tax dept · Hetauda detailed task list (user_id=2 only)
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

$db = getDB();
$user = currentUser();
$module = $_GET['module'] ?? 'tasks';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

// ── Fetch all task statuses once ─────────────────────────────
$statusRows = $db->query("SELECT id, status_name, color FROM task_status ORDER BY id ASC")->fetchAll();
$statusMeta = array_column($statusRows, null, 'status_name');

// ── PDF Class ─────────────────────────────────────────────────
class MISPdf extends TCPDF
{
    private string $reportTitle;

    public function __construct(string $title)
    {
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

    public function Header(): void
    {
        $this->SetFillColor(10, 15, 30);
        $this->Rect(0, 0, $this->getPageWidth(), 22, 'F');
        $this->SetFillColor(201, 168, 76);
        $this->Rect(0, 22, $this->getPageWidth(), 1.5, 'F');
        // Logo box
        $this->SetFillColor(201, 168, 76);
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
        // Date stamp
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(201, 168, 76);
        $this->SetXY(-70, 8);
        $this->Cell(60, 5, 'Generated: ' . date('M j, Y H:i'), 0, 0, 'R');
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFillColor(248, 249, 250);
        $this->Rect(0, $this->getY() - 2, $this->getPageWidth(), 20, 'F');
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(156, 163, 175);
        $this->Cell(
            0,
            6,
            'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages() .
            '  ·  MISPro — ASK Global Advisory Pvt. Ltd.  ·  CONFIDENTIAL',
            0,
            0,
            'C'
        );
    }
}

// ══════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ══════════════════════════════════════════════════════════════

/** Convert #rrggbb to [r,g,b] */
function hex2rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3)
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** Section heading */
function pdfSection(MISPdf $pdf, string $title, string $color = '#1f2937'): void
{
    if ($pdf->GetY() > 160)
        $pdf->AddPage();
    $pdf->Ln(4);
    [$r, $g, $b] = hex2rgb($color);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor($r, $g, $b);
    $pdf->Cell(0, 8, $title, 0, 1, 'L');
    $pdf->SetTextColor(31, 41, 55);
}

/** Period + record count line */
function pdfPeriod(MISPdf $pdf, string $from, string $to, int $count = 0): void
{
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $line = 'Period: ' . date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));
    if ($count)
        $line .= '  ·  ' . $count . ' records';
    $pdf->Cell(0, 6, $line, 0, 1, 'L');
    $pdf->Ln(2);
}

/** Sub-label line (for dept/branch/status context) */
function pdfMeta(MISPdf $pdf, string $text): void
{
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(0, 5, $text, 0, 1, 'L');
    $pdf->Ln(1);
}

/** Table header row */
function tableHeader(MISPdf $pdf, array $cols, array $widths): void
{
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

/** Single table data row */
function tableRow(
    MISPdf $pdf,
    array $cells,
    array $widths,
    bool $odd,
    array $aligns = [],
    array $highlights = []
): void {
    $pdf->SetFillColor($odd ? 255 : 248, $odd ? 255 : 250, $odd ? 255 : 251);
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetDrawColor(30, 42, 64);
    foreach ($cells as $i => $cell) {
        $align = $aligns[$i] ?? 'L';
        if (isset($highlights[$i])) {
            [$r, $g, $b] = hex2rgb($highlights[$i]);
            $pdf->SetTextColor($r, $g, $b);
        } else {
            $pdf->SetTextColor(31, 41, 55);
        }
        $pdf->Cell($widths[$i], 6, $cell, 1, 0, $align, !$odd);
    }
    $pdf->Ln();
}

/** KPI tile row */
function kpiTiles(MISPdf $pdf, array $tiles): void
{
    $x = 12;
    $y = $pdf->GetY();
    $tileW = min(43, (int) (258 / count($tiles)));
    foreach ($tiles as [$lbl, $val, $col]) {
        [$r, $g, $b] = hex2rgb($col);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($x, $y, $tileW, 19, 2, '1111', 'DF');
        $pdf->SetFillColor($r, $g, $b);
        $pdf->Rect($x, $y, 3, 19, 'F');
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY($x + 5, $y + 2);
        $pdf->Cell($tileW - 7, 8, (string) $val, 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->SetXY($x + 5, $y + 11);
        $pdf->Cell($tileW - 7, 6, $lbl, 0, 0, 'L');
        $x += $tileW + 2;
    }
    $pdf->SetY($y + 25);
}

/** Build dynamic SQL CASE columns */
function buildStatusCases(array $statusRows): string
{
    $sql = '';
    foreach ($statusRows as $sr) {
        $sn = addslashes($sr['status_name']);
        $sql .= "SUM(CASE WHEN ts.status_name='{$sn}' ELSE 0 END) AS `s_{$sr['id']}`,\n";
    }
    return $sql;
}

/** Auto page break + reprint header */
function checkPageBreak(MISPdf $pdf, array $cols, array $widths, int $threshold = 178): void
{
    if ($pdf->GetY() > $threshold) {
        $pdf->AddPage();
        if (!empty($cols))
            tableHeader($pdf, $cols, $widths);
    }
}

/** Coloured divider bar (group separator) */
function groupDivider(MISPdf $pdf, string $label, string $hexColor = '#c9a84c', int $count = 0): void
{
    if ($pdf->GetY() > 170)
        $pdf->AddPage();
    $pdf->Ln(3);
    [$r, $g, $b] = hex2rgb($hexColor);
    $pdf->SetFillColor($r, $g, $b);
    $pdf->Rect(12, $pdf->GetY(), 3, 7, 'F');
    $pdf->SetFillColor(245, 247, 250);
    $pageW = $pdf->getPageWidth() - 24;
    $pdf->SetXY(15, $pdf->GetY());
    $pdf->SetFillColor(245, 247, 250);
    $pdf->Cell($pageW, 7, '', 1, 0, 'L', true);
    $pdf->SetXY(18, $pdf->GetY() - 7);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor($r, $g, $b);
    $labelText = $label . ($count ? '  (' . $count . ' tasks)' : '');
    $pdf->Cell($pageW - 6, 7, $labelText, 0, 1, 'L');
    $pdf->Ln(1);
}

/** Grand total footer row */
function grandTotalRow(MISPdf $pdf, array $totals, array $widths, array $aligns = []): void
{
    $pdf->SetFillColor(10, 15, 30);
    $pdf->SetTextColor(201, 168, 76);
    $pdf->SetFont('helvetica', 'B', 7);
    foreach ($totals as $i => $val) {
        $align = $aligns[$i] ?? 'C';
        $pdf->Cell($widths[$i], 7, (string) $val, 1, 0, $align, true);
    }
    $pdf->Ln();
}


// ══════════════════════════════════════════════════════════════
// MODULE: tasks
// ══════════════════════════════════════════════════════════════
if ($module === 'tasks') {

    // ── Get logged-in user's full profile ─────────────────────
    // currentUser() returns: id, username, full_name,
    //                        role_id, role_name,
    //                        branch_id, department_id
    $loggedInDeptId   = (int)($user['department_id'] ?? 0);
    $loggedInBranchId = (int)($user['branch_id']     ?? 0);
    $loggedInRole     = $user['role_name']            ?? '';

    $isExecutive = ($loggedInRole === 'executive');
    $isAdmin     = ($loggedInRole === 'admin');
    $isStaff     = ($loggedInRole === 'staff');

    // ── Resolve filters ───────────────────────────────────────
    // Rules:
    //   executive → can see everything; respects optional GET params
    //   admin     → locked to their own dept + branch; status from GET
    //   staff     → locked to their own dept + branch; status from GET

    if ($isExecutive) {
        // Executive: optional overrides from URL
        $filterDeptId   = !empty($_GET['dept_id'])   ? (int)$_GET['dept_id']   : null;
        $filterBranchId = !empty($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
        $filterStaffId  = !empty($_GET['staff_id'])  ? (int)$_GET['staff_id']  : null;
    } else {
        // Admin / Staff: always locked to their own dept & branch
        $filterDeptId   = $loggedInDeptId   ?: null;
        $filterBranchId = $loggedInBranchId ?: null;
        $filterStaffId  = !empty($_GET['staff_id'])  ? (int)$_GET['staff_id']  : null;
    }

    // Status filter applies to everyone
    $filterStatus = !empty($_GET['status']) ? trim($_GET['status']) : null;

    // ── Build WHERE + params ──────────────────────────────────
    $where  = ['t.is_active = 1', 't.created_at BETWEEN ? AND ?'];
    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];

    if ($filterDeptId)   { $where[] = 't.department_id = ?'; $params[] = $filterDeptId; }
    if ($filterBranchId) { $where[] = 't.branch_id = ?';     $params[] = $filterBranchId; }
    if ($filterStaffId)  { $where[] = 't.assigned_to = ?';   $params[] = $filterStaffId; }
    if ($filterStatus)   { $where[] = 'ts.status_name = ?';  $params[] = $filterStatus; }

    $ws = implode(' AND ', $where);

    $st = $db->prepare("
        SELECT t.task_number, t.title,
               ts.status_name AS status, ts.color AS status_color,
               t.priority,
               d.dept_name, b.branch_name, c.company_name,
               ua.full_name AS assigned_to,
               t.due_date, t.fiscal_year
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
    $st->execute($params);
    $data = $st->fetchAll();

    // ── Resolve label names for title + subtitle ──────────────
    $dn = null; $bn = null; $sn = null;

    if ($filterDeptId) {
        $dr = $db->prepare("SELECT dept_name FROM departments WHERE id = ?");
        $dr->execute([$filterDeptId]);
        $dn = $dr->fetchColumn() ?: null;
    }
    if ($filterBranchId) {
        $br = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
        $br->execute([$filterBranchId]);
        $bn = $br->fetchColumn() ?: null;
    }
    if ($filterStaffId) {
        $sr2 = $db->prepare("SELECT full_name FROM users WHERE id = ?");
        $sr2->execute([$filterStaffId]);
        $sn = $sr2->fetchColumn() ?: null;
    }

    // ── Dynamic PDF title ─────────────────────────────────────
    $titleParts = ['Task List Report'];
    if ($dn)           $titleParts[] = $dn;
    if ($bn)           $titleParts[] = $bn;
    if ($filterStatus) $titleParts[] = $filterStatus;

    $pdf = new MISPdf(implode(' — ', $titleParts));
    $pdf->AddPage();
    pdfPeriod($pdf, $from, $to, count($data));

    // ── Subtitle: show active scope ───────────────────────────
    $metaParts = [];
    if ($dn)                       $metaParts[] = 'Department: ' . $dn;
    if ($bn)                       $metaParts[] = 'Branch: '     . $bn;
    if ($sn)                       $metaParts[] = 'Staff: '      . $sn;
    if ($filterStatus)             $metaParts[] = 'Status: '     . $filterStatus;
    if (!$filterDeptId && $isExecutive) $metaParts[] = 'Scope: All Departments';
    if ($metaParts) pdfMeta($pdf, implode('  ·  ', $metaParts));

    // ── Columns (fixed Task # / Title overlap) ────────────────
    $cols   = ['Task #','Title','Status','Priority','Dept','Branch','Company','Assigned To','Due Date','FY'];
    $widths = [28, 62, 22, 16, 20, 26, 30, 28, 20, 13];
    $aligns = ['L','L','C','C','L','L','L','L','C','C'];
    tableHeader($pdf, $cols, $widths);

    $odd = true;
    foreach ($data as $r) {
        checkPageBreak($pdf, $cols, $widths);
        tableRow($pdf, [
            $r['task_number'],
            mb_strimwidth($r['title']        ?? '',  0, 50, '…'),
            $r['status']                     ?? '—',
            ucfirst($r['priority']           ?? ''),
            mb_strimwidth($r['dept_name']    ?? '—', 0, 14, '…'),
            mb_strimwidth($r['branch_name']  ?? '—', 0, 18, '…'),
            mb_strimwidth($r['company_name'] ?? '—', 0, 20, '…'),
            mb_strimwidth($r['assigned_to']  ?? '—', 0, 18, '…'),
            $r['due_date'] ? date('M j, Y', strtotime($r['due_date'])) : '—',
            $r['fiscal_year'] ?? '—',
        ], $widths, $odd, $aligns, [2 => $r['status_color'] ?? '#9ca3af']);
        $odd = !$odd;
    }

    // ── Filename ──────────────────────────────────────────────
    $fileSlug = implode('_', array_map(
        fn($p) => preg_replace('/[^a-z0-9]/i', '', $p),
        $titleParts
    ));
    $filename = $fileSlug . '_' . date('Ymd_His') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// MODULE: executive_report
// ══════════════════════════════════════════════════════════════
elseif ($module === 'executive_report') {

    $pdf = new MISPdf('Executive Analytics Report');
    $pdf->AddPage();

    $kpis = [['Total Tasks', $db->query("SELECT COUNT(*) FROM tasks WHERE is_active=1")->fetchColumn(), '#3b82f6']];
    foreach ($statusRows as $sr) {
        $cnt = $db->prepare("SELECT COUNT(*) FROM tasks t JOIN task_status ts ON ts.id=t.status_id WHERE ts.status_name=? AND t.is_active=1");
        $cnt->execute([$sr['status_name']]);
        $kpis[] = [$sr['status_name'], $cnt->fetchColumn(), $sr['color'] ?: '#9ca3af'];
    }
    $kpis[] = ['Companies', $db->query("SELECT COUNT(*) FROM companies WHERE is_active=1")->fetchColumn(), '#c9a84c'];
    $kpis[] = ['Staff', $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_name='staff' AND u.is_active=1")->fetchColumn(), '#8b5cf6'];

    pdfSection($pdf, 'Key Performance Indicators');
    kpiTiles($pdf, $kpis);

    $sc = buildStatusCases($statusRows);
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
    $dCols = array_merge(['Department', 'Total'], array_column($statusRows, 'status_name'), ['% Done']);
    $dWidths = array_merge([65, 20], array_fill(0, count($statusRows), 22), [22]);
    $dAligns = array_merge(['L', 'C'], array_fill(0, count($statusRows), 'C'), ['C']);
    tableHeader($pdf, $dCols, $dWidths);

    $odd = true;
    foreach ($dept as $d) {
        $pct = $d['total'] ? round(($d['done'] / $d['total']) * 100) : 0;
        $cells = [$d['dept_name'], $d['total']];
        foreach ($statusRows as $sr)
            $cells[] = $d['s_' . $sr['id']] ?? 0;
        $cells[] = $pct . '%';
        tableRow($pdf, $cells, $dWidths, $odd, $dAligns);
        $odd = !$odd;
    }

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
    $bCols = ['Branch', 'Total', 'WIP', 'Pending', 'Done', '% Done'];
    $bWidths = [80, 30, 30, 35, 30, 30];
    $bAligns = ['L', 'C', 'C', 'C', 'C', 'C'];
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

    $sc = buildStatusCases($statusRows);
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

    $cols = array_merge(['Department', 'Total'], array_column($statusRows, 'status_name'), ['Overdue', '% Done']);
    $widths = array_merge([60, 20], array_fill(0, count($statusRows), 22), [22, 22]);
    $aligns = array_merge(['L', 'C'], array_fill(0, count($statusRows), 'C'), ['C', 'C']);
    tableHeader($pdf, $cols, $widths);

    $doneId = null;
    foreach ($statusRows as $sr) {
        if (strtolower($sr['status_name']) === 'done') {
            $doneId = $sr['id'];
            break;
        }
    }

    $odd = true;
    foreach ($data as $d) {
        $doneVal = $doneId ? (int) ($d['s_' . $doneId] ?? 0) : 0;
        $pct = $d['total'] ? round(($doneVal / $d['total']) * 100) : 0;
        $cells = [$d['dept_name'], $d['total']];
        foreach ($statusRows as $sr)
            $cells[] = $d['s_' . $sr['id']] ?? 0;
        $cells[] = $d['overdue'];
        $cells[] = $pct . '%';
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

    $sc = buildStatusCases($statusRows);
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

    $cols = array_merge(['Branch', 'City', 'Total'], array_column($statusRows, 'status_name'), ['Overdue', '% Done']);
    $widths = array_merge([50, 30, 18], array_fill(0, count($statusRows), 20), [20, 20]);
    $aligns = array_merge(['L', 'L', 'C'], array_fill(0, count($statusRows), 'C'), ['C', 'C']);
    tableHeader($pdf, $cols, $widths);

    $doneId = null;
    foreach ($statusRows as $sr) {
        if (strtolower($sr['status_name']) === 'done') {
            $doneId = $sr['id'];
            break;
        }
    }

    $odd = true;
    foreach ($data as $b) {
        $doneVal = $doneId ? (int) ($b['s_' . $doneId] ?? 0) : 0;
        $pct = $b['total'] ? round(($doneVal / $b['total']) * 100) : 0;
        $cells = [$b['branch_name'], $b['city'] ?? '—', $b['total']];
        foreach ($statusRows as $sr)
            $cells[] = $b['s_' . $sr['id']] ?? 0;
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

    $cols = ['Staff Member', 'Emp ID', 'Branch', 'Department', 'Total', 'WIP', 'Pending', 'Done', 'Overdue', '% Done'];
    $widths = [48, 24, 35, 30, 18, 18, 22, 18, 22, 20];
    $aligns = ['L', 'C', 'L', 'L', 'C', 'C', 'C', 'C', 'C', 'C'];
    tableHeader($pdf, $cols, $widths);

    $odd = true;
    foreach ($data as $s) {
        checkPageBreak($pdf, $cols, $widths);
        $pct = $s['total'] ? round(($s['done'] / $s['total']) * 100) : 0;
        tableRow($pdf, [
            mb_strimwidth($s['full_name'] ?? 'Unassigned', 0, 28, '…'),
            $s['employee_id'] ?? '—',
            mb_strimwidth($s['branch_name'] ?? '—', 0, 20, '…'),
            mb_strimwidth($s['dept_name'] ?? '—', 0, 18, '…'),
            $s['total'],
            $s['wip'],
            $s['pending'],
            $s['done'],
            $s['overdue'],
            $pct . '%'
        ], $widths, $odd, $aligns, [8 => '#dc2626']);
        $odd = !$odd;
    }

    $filename = 'Staff_Performance_' . date('Ymd_His') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// MODULE: company_workflow
// ══════════════════════════════════════════════════════════════
elseif ($module === 'company_workflow') {

    $companyId = (int) ($_GET['company_id'] ?? 0);
    $coStmt = $db->prepare("
        SELECT c.*, b.branch_name, ct.type_name AS company_type_name
        FROM companies c
        LEFT JOIN branches b       ON b.id  = c.branch_id
        LEFT JOIN company_types ct ON ct.id = c.company_type_id
        WHERE c.id = ?
    ");
    $coStmt->execute([$companyId]);
    $company = $coStmt->fetch();
    if (!$company)
        die('Company not found.');

    $pdf = new MISPdf('Workflow: ' . $company['company_name']);
    $pdf->AddPage();

    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(10, 15, 30);
    $pdf->Cell(0, 8, $company['company_name'], 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(
        0,
        6,
        'Branch: ' . ($company['branch_name'] ?? '—') .
        '  ·  Type: ' . ($company['company_type_name'] ?? '—') .
        '  ·  PAN: ' . ($company['pan_number'] ?? '—') .
        '  ·  Code: ' . ($company['company_code'] ?? '—'),
        0,
        1,
        'L'
    );
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
        $pdf->SetFillColor(17, 24, 39);
        $pdf->SetTextColor(201, 168, 76);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(0, 7, '  ' . $t['task_number'] . '  —  ' . mb_strimwidth($t['title'], 0, 80, '…'), 0, 1, 'L', true);

        [$sr, $sg, $sb] = hex2rgb($t['status_color'] ?? '#9ca3af');
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor($sr, $sg, $sb);
        $pdf->Cell(50, 5, '  Status: ' . $t['status'], 0, 0, 'L');
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(55, 5, 'Dept: ' . ($t['dept_name'] ?? '—'), 0, 0, 'L');
        $pdf->Cell(65, 5, 'Assigned: ' . ($t['assigned_to_name'] ?? '—'), 0, 0, 'L');
        $pdf->Cell(55, 5, 'Due: ' . ($t['due_date'] ? date('M j, Y', strtotime($t['due_date'])) : '—'), 0, 1, 'L');

        $wfSt = $db->prepare("
            SELECT tw.*, u1.full_name AS from_name, u2.full_name AS to_name,
                   d1.dept_name AS from_dept, d2.dept_name AS to_dept
            FROM task_workflow tw
            LEFT JOIN users u1       ON u1.id = tw.from_user_id
            LEFT JOIN users u2       ON u2.id = tw.to_user_id
            LEFT JOIN departments d1 ON d1.id = tw.from_dept_id
            LEFT JOIN departments d2 ON d2.id = tw.to_dept_id
            WHERE tw.task_id = ? ORDER BY tw.created_at ASC
        ");
        try {
            $wfSt->execute([$t['id']]);
            $wf = $wfSt->fetchAll();
        } catch (Exception $e) {
            $wf = [];
        }

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
                if ($w['from_name'])
                    $line .= '  by ' . $w['from_name'];
                if ($w['from_dept'])
                    $line .= ' (' . $w['from_dept'] . ')';
                if ($w['to_name'])
                    $line .= '  →  ' . $w['to_name'];
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

    $sc = buildStatusCases($statusRows);
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(t.created_at,'%Y-%m-%d') AS day,
               COUNT(t.id) AS total, {$sc} 0 AS _dummy
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active = 1 AND t.created_at BETWEEN ? AND ?
        GROUP BY day ORDER BY day ASC
    ");
    $stmt->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $data = $stmt->fetchAll();

    $cols = array_merge(['Date', 'Total'], array_column($statusRows, 'status_name'));
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
            $v = (int) ($row['s_' . $sr['id']] ?? 0);
            $cells[] = $v;
            $totals[$i] += $v;
        }
        $grandTotal += $row['total'];
        tableRow($pdf, $cells, $widths, $odd, $aligns);
        $odd = !$odd;
    }

    // Grand total row
    $pdf->SetFillColor(10, 15, 30);
    $pdf->SetTextColor(201, 168, 76);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell($widths[0], 7, 'TOTAL', 1, 0, 'C', true);
    $pdf->Cell($widths[1], 7, $grandTotal, 1, 0, 'C', true);
    foreach ($totals as $i => $tv)
        $pdf->Cell($widths[$i + 2], 7, $tv, 1, 0, 'C', true);
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

    $where = ['t.is_active = 1', 't.created_at BETWEEN ? AND ?'];
    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
    if (!empty($_GET['branch_id'])) {
        $where[] = 't.branch_id = ?';
        $params[] = (int) $_GET['branch_id'];
    }
    if (!empty($_GET['dept_id'])) {
        $where[] = 't.department_id = ?';
        $params[] = (int) $_GET['dept_id'];
    }
    $ws = implode(' AND ', $where);

    $sc = buildStatusCases($statusRows);
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

    $cols = array_merge(['Staff Member', 'Emp ID', 'Branch', 'Total'], array_column($statusRows, 'status_name'), ['Overdue', '% Done']);
    $sCount = count($statusRows);
    $widths = array_merge([48, 22, 35, 18], array_fill(0, $sCount, 20), [20, 20]);
    $aligns = array_merge(['L', 'C', 'L', 'C'], array_fill(0, $sCount, 'C'), ['C', 'C']);
    tableHeader($pdf, $cols, $widths);

    $doneId = null;
    foreach ($statusRows as $sr) {
        if (strtolower($sr['status_name']) === 'done') {
            $doneId = $sr['id'];
            break;
        }
    }

    $odd = true;
    foreach ($data as $s) {
        checkPageBreak($pdf, $cols, $widths);
        $doneVal = $doneId ? (int) ($s['s_' . $doneId] ?? 0) : 0;
        $pct = $s['total'] ? round(($doneVal / $s['total']) * 100) : 0;
        $cells = [
            mb_strimwidth($s['full_name'] ?? 'Unassigned', 0, 26, '…'),
            $s['employee_id'] ?? '—',
            mb_strimwidth($s['branch_name'] ?? '—', 0, 18, '…'),
            $s['total'],
        ];
        foreach ($statusRows as $sr)
            $cells[] = $s['s_' . $sr['id']] ?? 0;
        $cells[] = $s['overdue'];
        $cells[] = $pct . '%';
        $hi = [count($cells) - 2 => '#dc2626'];
        tableRow($pdf, $cells, $widths, $odd, $aligns, $hi);
        $odd = !$odd;
    }

    $filename = 'Report_' . date('Ymd_His') . '.pdf';
}

// ══════════════════════════════════════════════════════════════
// MODULE: dept_status_tasks
// ══════════════════════════════════════════════════════════════
// URL: export_pdf.php?module=dept_status_tasks
//      &from=YYYY-MM-DD&to=YYYY-MM-DD
//      [&dept_id=N]       ← filter single dept; omit for all depts
//      [&branch_id=N]     ← filter single branch; omit for all branches
//      [&status=Done]     ← filter single status; omit for all statuses
//
// Output: tasks grouped by Department → then Status, with full detail
//         columns: Task#, Title, Company, PAN, FY, Assigned To, Priority,
//                  Due Date, Created, Overdue flag
// ══════════════════════════════════════════════════════════════
elseif ($module === 'dept_status_tasks') {

    // ── Resolve optional filter labels for the report title ───────────────────
    $filterDeptId = !empty($_GET['dept_id']) ? (int) $_GET['dept_id'] : null;
    $filterBranchId = !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : null;
    $filterStatus = !empty($_GET['status']) ? trim($_GET['status']) : null;

    $titleParts = ['Dept & Status Task Report'];
    if ($filterDeptId) {
        $dr = $db->prepare("SELECT dept_name, color FROM departments WHERE id = ?");
        $dr->execute([$filterDeptId]);
        $deptInfo = $dr->fetch();
        $titleParts[] = $deptInfo['dept_name'] ?? 'Unknown Dept';
    }
    if ($filterBranchId) {
        $br = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
        $br->execute([$filterBranchId]);
        $branchInfo = $br->fetch();
        $titleParts[] = $branchInfo['branch_name'] ?? 'Unknown Branch';
    }
    if ($filterStatus)
        $titleParts[] = $filterStatus;

    $reportTitle = implode(' — ', $titleParts);

    $pdf = new MISPdf($reportTitle);
    $pdf->AddPage();

    // ── Meta line ─────────────────────────────────────────────────────────────
    pdfPeriod($pdf, $from, $to);
    $metaParts = [];
    if (isset($deptInfo))
        $metaParts[] = 'Department: ' . ($deptInfo['dept_name'] ?? '—');
    if (isset($branchInfo))
        $metaParts[] = 'Branch: ' . ($branchInfo['branch_name'] ?? '—');
    if ($filterStatus)
        $metaParts[] = 'Status: ' . $filterStatus;
    if ($metaParts)
        pdfMeta($pdf, implode('  ·  ', $metaParts));

    // ── Build WHERE + params ──────────────────────────────────────────────────
    $where = ['t.is_active = 1', 't.created_at BETWEEN ? AND ?'];
    $params = [$from . ' 00:00:00', $to . ' 23:59:59'];

    if ($filterDeptId) {
        $where[] = 't.department_id = ?';
        $params[] = $filterDeptId;
    }
    if ($filterBranchId) {
        $where[] = 't.branch_id = ?';
        $params[] = $filterBranchId;
    }
    if ($filterStatus) {
        $where[] = 'ts.status_name = ?';
        $params[] = $filterStatus;
    }

    $ws = implode(' AND ', $where);

    // ── Fetch tasks — ordered dept → status → created ─────────────────────────
    $stmt = $db->prepare("
        SELECT
            t.id,
            t.task_number,
            t.title,
            t.priority,
            t.due_date,
            t.created_at,
            t.fiscal_year,
            ts.status_name  AS status,
            ts.color        AS status_color,
            d.dept_name,
            d.color         AS dept_color,
            b.branch_name,
            c.company_name,
            c.pan_number,
            ua.full_name    AS assigned_name,
            ua.employee_id  AS assigned_emp_id,
            CASE
                WHEN t.due_date IS NOT NULL
                     AND t.due_date < CURDATE()
                     AND ts.status_name != 'Done' THEN 'Yes'
                ELSE '—'
            END AS is_overdue
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        LEFT JOIN departments d  ON d.id  = t.department_id
        LEFT JOIN branches b     ON b.id  = t.branch_id
        LEFT JOIN companies c    ON c.id  = t.company_id
        LEFT JOIN users ua       ON ua.id = t.assigned_to
        WHERE {$ws}
        ORDER BY
            d.dept_name   ASC,
            ts.status_name ASC,
            t.due_date    ASC,
            t.created_at  DESC
        LIMIT 3000
    ");
    $stmt->execute($params);
    $allTasks = $stmt->fetchAll();

    if (empty($allTasks)) {
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->SetTextColor(156, 163, 175);
        $pdf->Cell(0, 10, 'No tasks found for the selected filters.', 0, 1, 'C');
        $filename = 'DeptStatus_Tasks_' . date('Ymd_His') . '.pdf';
    } else {

        // ── Summary KPI tiles ─────────────────────────────────────────────────
        $totalAll = count($allTasks);
        $overdueAll = count(array_filter($allTasks, fn($r) => $r['is_overdue'] === 'Yes'));

        // Count per status dynamically
        $statusCounts = [];
        foreach ($allTasks as $t) {
            $statusCounts[$t['status']] = ($statusCounts[$t['status']] ?? 0) + 1;
        }
        $tiles = [['Total Tasks', $totalAll, '#3b82f6']];
        foreach ($statusCounts as $sn => $sc) {
            $color = $statusMeta[$sn]['color'] ?? '#9ca3af';
            $tiles[] = [$sn, $sc, $color];
        }
        $tiles[] = ['Overdue', $overdueAll, '#dc2626'];
        kpiTiles($pdf, $tiles);

        // ── Group tasks by Dept → Status ──────────────────────────────────────
        $grouped = [];
        foreach ($allTasks as $t) {
            $grouped[$t['dept_name']][$t['status']][] = $t;
        }

        // ── Table columns ─────────────────────────────────────────────────────
        $cols = ['#', 'Task #', 'Title', 'Company', 'PAN', 'FY', 'Assigned To', 'Priority', 'Due Date', 'Created', 'OD'];
        // OD = Overdue flag
        $widths = [8, 22, 52, 34, 22, 12, 30, 16, 22, 22, 10];
        $aligns = ['C', 'L', 'L', 'L', 'C', 'C', 'L', 'C', 'C', 'C', 'C'];

        $grandRowCount = 0;
        $rowSeq = 0; // global sequential row number

        foreach ($grouped as $deptName => $statusGroups) {

            // Dept colour lookup
            $dColor = '#c9a84c';
            foreach ($allTasks as $t) {
                if ($t['dept_name'] === $deptName) {
                    $dColor = $t['dept_color'] ?: '#c9a84c';
                    break;
                }
            }

            // Dept divider
            $deptTotal = array_sum(array_map('count', $statusGroups));
            groupDivider($pdf, $deptName, $dColor, $deptTotal);

            foreach ($statusGroups as $statusName => $tasks) {

                $statusColor = $statusMeta[$statusName]['color'] ?? '#9ca3af';

                // Status sub-header
                if ($pdf->GetY() > 172)
                    $pdf->AddPage();
                [$sr, $sg, $sb] = hex2rgb($statusColor);
                $pdf->SetFont('helvetica', 'B', 7.5);
                $pdf->SetTextColor($sr, $sg, $sb);
                $pdf->SetX(15);
                $pdf->Cell(0, 6, '  ▶  ' . $statusName . '  (' . count($tasks) . ' tasks)', 0, 1, 'L');
                $pdf->Ln(1);

                // Table header for this group
                tableHeader($pdf, $cols, $widths);

                $odd = true;
                foreach ($tasks as $t) {
                    checkPageBreak($pdf, $cols, $widths);
                    $rowSeq++;
                    $grandRowCount++;

                    // Priority colour
                    $priColor = match (strtolower($t['priority'])) {
                        'high' => '#dc2626',
                        'medium' => '#f59e0b',
                        'low' => '#16a34a',
                        default => '#9ca3af',
                    };

                    tableRow($pdf, [
                        $rowSeq,
                        $t['task_number'],
                        mb_strimwidth($t['title'] ?? '', 0, 40, '…'),
                        mb_strimwidth($t['company_name'] ?? '—', 0, 20, '…'),
                        $t['pan_number'] ?? '—',
                        $t['fiscal_year'] ?? '—',
                        mb_strimwidth($t['assigned_name'] ?? 'Unassigned', 0, 18, '…'),
                        ucfirst($t['priority'] ?? ''),
                        $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—',
                        date('d M Y', strtotime($t['created_at'])),
                        $t['is_overdue'],
                    ], $widths, $odd, $aligns, [
                        7 => $priColor,
                        10 => $t['is_overdue'] === 'Yes' ? '#dc2626' : '#9ca3af',
                    ]);
                    $odd = !$odd;
                }

                // Status subtotal row
                $pdf->SetFillColor(245, 247, 250);
                $pdf->SetFont('helvetica', 'B', 7);
                $pdf->SetTextColor($sr, $sg, $sb);
                $pdf->Cell($widths[0] + $widths[1], 6, 'Subtotal', 1, 0, 'R', true);
                $pdf->Cell(array_sum(array_slice($widths, 2, 7)), 6, count($tasks) . ' tasks', 1, 0, 'C', true);
                $odCount = count(array_filter($tasks, fn($t) => $t['is_overdue'] === 'Yes'));
                $pdf->SetTextColor($odCount > 0 ? 220 : 156, $odCount > 0 ? 38 : 163, $odCount > 0 ? 38 : 175);
                $pdf->Cell($widths[10], 6, $odCount ?: '—', 1, 1, 'C', true);

                $pdf->Ln(3);
            }
        }

        // ── Grand total row ───────────────────────────────────────────────────
        if ($pdf->GetY() > 178)
            $pdf->AddPage();
        $pdf->Ln(2);
        $pdf->SetFillColor(10, 15, 30);
        $pdf->SetTextColor(201, 168, 76);
        $pdf->SetFont('helvetica', 'B', 8);
        $gtW = array_sum($widths);
        $pdf->Cell($gtW * 0.6, 8, 'GRAND TOTAL', 1, 0, 'R', true);
        $pdf->Cell($gtW * 0.25, 8, $grandRowCount . ' tasks', 1, 0, 'C', true);
        $pdf->SetTextColor(239, 68, 68);
        $pdf->Cell(
            $gtW - ($gtW * 0.6) - ($gtW * 0.25),
            8,
            'OD: ' . $overdueAll,
            1,
            1,
            'C',
            true
        );

        $filename = 'DeptStatus_Tasks_' . date('Ymd_His') . '.pdf';
    }
}

// ══════════════════════════════════════════════════════════════
// MODULE: tax_htd
// ══════════════════════════════════════════════════════════════
// URL: export_pdf.php?module=tax_htd
//      &from=YYYY-MM-DD&to=YYYY-MM-DD
//      [&status=Done]   ← optional single-status filter
//
// Access: user id = 2 only
// Output: Tax dept · Hetauda branch tasks
//         Grouped by Status, with full detail per task
// ══════════════════════════════════════════════════════════════
elseif ($module === 'tax_htd') {

    // ── Access control ────────────────────────────────────────────────────────
    if ((int) $user['id'] !== 2) {
        http_response_code(403);
        die('403 — Access Denied.');
    }

    // ── Resolve IDs dynamically ───────────────────────────────────────────────
    $taxDeptRow = $db->query("
        SELECT id, dept_name, color FROM departments
        WHERE dept_code = 'TAX' OR LOWER(dept_name) LIKE '%tax%' LIMIT 1
    ")->fetch();
    $htdBranchRow = $db->query("
        SELECT id, branch_name FROM branches
        WHERE LOWER(branch_name) LIKE '%hetauda%' LIMIT 1
    ")->fetch();

    if (!$taxDeptRow || !$htdBranchRow)
        die('Tax dept or Hetauda branch not configured.');

    $taxDeptId = (int) $taxDeptRow['id'];
    $htdBranchId = (int) $htdBranchRow['id'];
    $deptColor = $taxDeptRow['color'] ?: '#c9a84c';
    $filterStatus = !empty($_GET['status']) ? trim($_GET['status']) : null;

    // ── Report title ──────────────────────────────────────────────────────────
    $rTitle = 'Tax Department — Hetauda Branch';
    if ($filterStatus)
        $rTitle .= ' — ' . $filterStatus;

    $pdf = new MISPdf($rTitle);
    $pdf->AddPage();

    // ── Meta ──────────────────────────────────────────────────────────────────
    pdfPeriod($pdf, $from, $to);
    $metaStr = 'Department: ' . $taxDeptRow['dept_name']
        . '  ·  Branch: ' . $htdBranchRow['branch_name'];
    if ($filterStatus)
        $metaStr .= '  ·  Status: ' . $filterStatus;
    pdfMeta($pdf, $metaStr);

    // ── Query ─────────────────────────────────────────────────────────────────
    $where = [
        't.is_active = 1',
        't.branch_id = ?',
        't.department_id = ?',
        't.created_at BETWEEN ? AND ?',
    ];
    $params = [$htdBranchId, $taxDeptId, $from . ' 00:00:00', $to . ' 23:59:59'];

    if ($filterStatus) {
        $where[] = 'ts.status_name = ?';
        $params[] = $filterStatus;
    }

    $ws = implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT
            t.id,
            t.task_number,
            t.title,
            t.priority,
            t.due_date,
            t.created_at,
            t.fiscal_year,
            ts.status_name  AS status,
            ts.color        AS status_color,
            c.company_name,
            c.pan_number,
            ua.full_name    AS assigned_name,
            ua.employee_id  AS assigned_emp_id,
            uc.full_name    AS created_name,
            CASE
                WHEN t.due_date IS NOT NULL
                     AND t.due_date < CURDATE()
                     AND ts.status_name != 'Done' THEN 'Yes'
                ELSE '—'
            END AS is_overdue
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        LEFT JOIN companies   c  ON c.id  = t.company_id
        LEFT JOIN users       ua ON ua.id = t.assigned_to
        LEFT JOIN users       uc ON uc.id = t.created_by
        WHERE {$ws}
        ORDER BY ts.status_name ASC, t.due_date ASC, t.created_at DESC
        LIMIT 2000
    ");
    $stmt->execute($params);
    $allTasks = $stmt->fetchAll();

    if (empty($allTasks)) {
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->SetTextColor(156, 163, 175);
        $pdf->Cell(0, 10, 'No tasks found for the selected filters.', 0, 1, 'C');
    } else {

        // ── KPI tiles ─────────────────────────────────────────────────────────
        $totalAll = count($allTasks);
        $overdueAll = count(array_filter($allTasks, fn($r) => $r['is_overdue'] === 'Yes'));
        $statusCounts = [];
        foreach ($allTasks as $t) {
            $statusCounts[$t['status']] = ($statusCounts[$t['status']] ?? 0) + 1;
        }
        $tiles = [['Total Tasks', $totalAll, $deptColor]];
        foreach ($statusCounts as $sn => $sc) {
            $color = $statusMeta[$sn]['color'] ?? '#9ca3af';
            $tiles[] = [$sn, $sc, $color];
        }
        $tiles[] = ['Overdue', $overdueAll, '#dc2626'];
        kpiTiles($pdf, $tiles);

        // ── Group by status ───────────────────────────────────────────────────
        $grouped = [];
        foreach ($allTasks as $t) {
            $grouped[$t['status']][] = $t;
        }

        // ── Columns ───────────────────────────────────────────────────────────
        $cols = ['#', 'Task #', 'Title', 'Company', 'PAN', 'FY', 'Assigned To', 'Emp ID', 'Priority', 'Due Date', 'Created By', 'Created', 'OD'];
        $widths = [7, 22, 48, 32, 20, 12, 28, 18, 15, 22, 24, 20, 10];
        $aligns = ['C', 'L', 'L', 'L', 'C', 'C', 'L', 'C', 'C', 'C', 'L', 'C', 'C'];

        $rowSeq = 0;

        foreach ($grouped as $statusName => $tasks) {
            $statusColor = $statusMeta[$statusName]['color'] ?? '#9ca3af';

            // Status group divider
            groupDivider($pdf, $statusName, $statusColor, count($tasks));
            tableHeader($pdf, $cols, $widths);

            $odd = true;
            foreach ($tasks as $t) {
                checkPageBreak($pdf, $cols, $widths);
                $rowSeq++;

                $priColor = match (strtolower($t['priority'])) {
                    'high' => '#dc2626',
                    'medium' => '#f59e0b',
                    'low' => '#16a34a',
                    default => '#9ca3af',
                };

                tableRow($pdf, [
                    $rowSeq,
                    $t['task_number'],
                    mb_strimwidth($t['title'] ?? '', 0, 38, '…'),
                    mb_strimwidth($t['company_name'] ?? '—', 0, 20, '…'),
                    $t['pan_number'] ?? '—',
                    $t['fiscal_year'] ?? '—',
                    mb_strimwidth($t['assigned_name'] ?? 'Unassigned', 0, 16, '…'),
                    $t['assigned_emp_id'] ?? '—',
                    ucfirst($t['priority'] ?? ''),
                    $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—',
                    mb_strimwidth($t['created_name'] ?? '—', 0, 14, '…'),
                    date('d M Y', strtotime($t['created_at'])),
                    $t['is_overdue'],
                ], $widths, $odd, $aligns, [
                    8 => $priColor,
                    12 => $t['is_overdue'] === 'Yes' ? '#dc2626' : '#9ca3af',
                ]);
                $odd = !$odd;
            }

            // Status subtotal
            [$sr, $sg, $sb] = hex2rgb($statusColor);
            $pdf->SetFillColor(245, 247, 250);
            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->SetTextColor($sr, $sg, $sb);
            $colSpanA = $widths[0] + $widths[1];
            $colSpanB = array_sum(array_slice($widths, 2, 9));
            $pdf->Cell($colSpanA, 6, 'Subtotal', 1, 0, 'R', true);
            $pdf->Cell($colSpanB, 6, count($tasks) . ' tasks', 1, 0, 'C', true);
            $odCount = count(array_filter($tasks, fn($t) => $t['is_overdue'] === 'Yes'));
            $pdf->SetTextColor($odCount > 0 ? 220 : 156, $odCount > 0 ? 38 : 163, $odCount > 0 ? 38 : 175);
            $pdf->Cell($widths[11] + $widths[12], 6, 'OD: ' . ($odCount ?: '0'), 1, 1, 'C', true);
            $pdf->Ln(3);
        }

        // ── Grand total ───────────────────────────────────────────────────────
        if ($pdf->GetY() > 178)
            $pdf->AddPage();
        $pdf->Ln(2);
        [$dr, $dg, $db2] = hex2rgb($deptColor);
        $pdf->SetFillColor(10, 15, 30);
        $pdf->SetTextColor(201, 168, 76);
        $pdf->SetFont('helvetica', 'B', 8);
        $gtW = array_sum($widths);
        $pdf->Cell($gtW * 0.55, 8, 'GRAND TOTAL — Tax Dept · Hetauda', 1, 0, 'R', true);
        $pdf->Cell($gtW * 0.3, 8, $totalAll . ' tasks', 1, 0, 'C', true);
        $pdf->SetTextColor(239, 68, 68);
        $pdf->Cell($gtW - ($gtW * 0.55) - ($gtW * 0.3), 8, 'OD: ' . $overdueAll, 1, 1, 'C', true);
    }

    $safeStatus = $filterStatus ? '_' . preg_replace('/[^a-z0-9]/i', '', $filterStatus) : '';
    $filename = 'Tax_HTD' . $safeStatus . '_' . date('Ymd_His') . '.pdf';
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

// ── Output ────────────────────────────────────────────────────
if (ob_get_length())
    ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$pdf->Output($filename, 'D');
exit;