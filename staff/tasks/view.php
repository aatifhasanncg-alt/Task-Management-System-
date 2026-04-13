<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helper.php';

// ── Fiscal year helpers ───────────────────────────────────────────────────────
if (!function_exists('getFiscalYearId')) {
    require_once __DIR__ . '/../../config/fiscal_year_helper.php';
}
if (!function_exists('getFiscalYearId')) {
    function getFiscalYearId(PDO $db, ?string $code): ?int
    {
        if (!$code) return null;
        try {
            $s = $db->prepare("SELECT id FROM fiscal_years WHERE fy_code=? LIMIT 1");
            $s->execute([$code]);
            $v = $s->fetchColumn();
            return $v ? (int) $v : null;
        } catch (Exception $e) { return null; }
    }
}
if (!function_exists('syncTaskFiscalYear')) {
    function syncTaskFiscalYear(PDO $db, int $taskId): void
    {
        try { $db->prepare("CALL sync_task_fiscal_year(?)")->execute([$taskId]); } catch (Exception $e) {}
    }
}
if (!function_exists('fiscalYearSelect')) {
    function fiscalYearSelect(string $name, ?string $selected, array $fys, string $class = 'form-select form-select-sm', bool $required = false): string
    {
        $req  = $required ? ' required' : '';
        $html = '<select name="' . htmlspecialchars($name) . '" class="' . $class . '"' . $req . ">\n";
        $html .= '    <option value="">-- Select FY --</option>' . "\n";
        foreach ($fys as $fy) {
            $isSel = ((string) $selected === (string) $fy['fy_code']);
            $sel   = $isSel ? ' selected' : '';
            $star  = $fy['is_current'] ? ' ★ Current' : '';
            $lbl   = htmlspecialchars($fy['fy_label'] ?: $fy['fy_code']);
            $val   = htmlspecialchars($fy['fy_code']);
            $style = $fy['is_current'] ? ' style="font-weight:700;color:#16a34a;"' : '';
            $html .= '    <option value="' . $val . '"' . $sel . $style . '>' . $lbl . $star . '</option>' . "\n";
        }
        $html .= '</select>';
        return $html;
    }
}

requireAnyRole();

$db   = getDB();
$user = currentUser();
$id   = (int) ($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// ── Current user profile ──────────────────────────────────────────────────────
$staffProfileStmt = $db->prepare("SELECT u.*, d.dept_code FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?");
$staffProfileStmt->execute([$user['id']]);
$staffProfile = $staffProfileStmt->fetch();

// ── Fiscal years ──────────────────────────────────────────────────────────────
$fys = [];
try {
    $fys = $db->query("SELECT id, fy_code, fy_label, is_current FROM fiscal_years WHERE is_active = 1 ORDER BY fy_code DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    if (defined('FISCAL_YEARS')) {
        foreach (FISCAL_YEARS as $fyc) {
            $fys[] = ['id' => null, 'fy_code' => $fyc, 'fy_label' => $fyc,
                      'is_current' => (defined('FISCAL_YEAR') && $fyc === FISCAL_YEAR) ? 1 : 0];
        }
    }
}
$currentFy = '';
foreach ($fys as $fy) { if ($fy['is_current']) { $currentFy = $fy['fy_code']; break; } }
if (!$currentFy && !empty($fys))         $currentFy = $fys[0]['fy_code'];
if (!$currentFy && defined('FISCAL_YEAR')) $currentFy = FISCAL_YEAR;

// ── Fetch task ────────────────────────────────────────────────────────────────
$taskStmt = $db->prepare("
    SELECT t.*,
           d.dept_name, d.dept_code, d.color,
           b.branch_name,
           c.company_name, c.pan_number AS company_pan,
           ts.status_name AS status,
           cb.full_name   AS created_by_name,
           asgn.full_name AS assigned_to_name,
           asgn.email     AS assigned_to_email
    FROM tasks t
    LEFT JOIN departments d  ON d.id  = t.department_id
    LEFT JOIN branches    b  ON b.id  = t.branch_id
    LEFT JOIN companies   c  ON c.id  = t.company_id
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN users       cb ON cb.id = t.created_by
    LEFT JOIN users    asgn  ON asgn.id = t.assigned_to
    WHERE t.id = ? AND t.is_active = 1
");
$taskStmt->execute([$id]);
$task = $taskStmt->fetch();

if (!$task) {
    setFlash('error', 'Task not found.');
    header('Location: index.php');
    exit;
}

// Staff can only view their own assigned tasks
if (!isAdmin() && !isExecutive() && $task['assigned_to'] != $user['id']) {
    setFlash('error', 'Access denied.');
    header('Location: index.php');
    exit;
}

$isMyTask = $task['assigned_to'] == $user['id'];
$isDone   = $task['status'] === 'Done';

// ── dept detail ───────────────────────────────────────────────────────────────
$detailTableMap = [
    'RETAIL' => 'task_retail',
    'TAX'    => 'task_tax',
    'BANK'   => 'task_banking',
    'CORP'   => 'task_corporate',
    'FIN'    => 'task_finance',
];
$detailTable = $detailTableMap[$task['dept_code']] ?? null;
$detail      = null;

if ($detailTable) {
    try {
        switch ($task['dept_code']) {
            case 'RETAIL':
                $dSt = $db->prepare("
                    SELECT tr.*,
                           ct.type_name   AS company_type_name, ft.type_name AS file_type_name,
                           pv.type_name   AS pan_vat_name,       vc.value    AS vat_client_value,
                           at2.type_name  AS audit_type_name,
                           ws.status_name AS work_status_name,   fs.status_name AS finalisation_status_name,
                           tc.status_name AS tax_clearance_status_name, bs.value AS backup_status_value,
                           au.full_name   AS retail_assigned_to_name, fb.full_name AS finalised_by_name
                    FROM task_retail tr
                    LEFT JOIN company_types ct ON ct.id=tr.company_type_id
                    LEFT JOIN file_types ft    ON ft.id=tr.file_type_id
                    LEFT JOIN pan_vat_types pv ON pv.id=tr.pan_vat_id
                    LEFT JOIN yes_no vc        ON vc.id=tr.vat_client_id
                    LEFT JOIN audit_types at2  ON at2.id=tr.audit_type_id
                    LEFT JOIN task_status ws   ON ws.id=tr.work_status_id
                    LEFT JOIN task_status fs   ON fs.id=tr.finalisation_status_id
                    LEFT JOIN task_status tc   ON tc.id=tr.tax_clearance_status_id
                    LEFT JOIN yes_no bs        ON bs.id=tr.backup_status_id
                    LEFT JOIN users au ON au.id=tr.assigned_to
                    LEFT JOIN users fb ON fb.id=tr.finalised_by
                    WHERE tr.task_id = ?");
                $dSt->execute([$id]);
                $detail = $dSt->fetch();
                break;

            case 'TAX':
                $dSt = $db->prepare("
                    SELECT tt.*,
                           tot.office_name   AS assigned_office_name,
                           tot.address       AS assigned_office_default_address,
                           tyt.tax_type_name AS tax_type_name,
                           tcs.status_name   AS tax_clearance_status_name,
                           au.full_name AS assigned_to_name,   fr.full_name AS file_received_by_name,
                           ub.full_name AS updated_by_name,    vb.full_name AS verify_by_name
                    FROM task_tax tt
                    LEFT JOIN tax_office_types tot ON tot.id = tt.assigned_office_id
                    LEFT JOIN tax_type tyt          ON tyt.id = tt.tax_type_id
                    LEFT JOIN task_status tcs       ON tcs.id = tt.tax_clearance_status_id
                    LEFT JOIN users au ON au.id=tt.assigned_to
                    LEFT JOIN users fr ON fr.id=tt.file_received_by
                    LEFT JOIN users ub ON ub.id=tt.updated_by
                    LEFT JOIN users vb ON vb.id=tt.verify_by
                    WHERE tt.task_id = ?");
                $dSt->execute([$id]);
                $detail = $dSt->fetch();
                break;

            case 'BANK':
                $dSt = $db->prepare("
                    SELECT tb.*,
                           br.bank_name,
                           bcc.category_name AS client_category_name,
                           c.company_name, c.contact_person, c.contact_phone,
                           c.pan_number AS company_pan, ct.type_name AS company_type_name
                    FROM task_banking tb
                    LEFT JOIN bank_references br         ON br.id=tb.bank_reference_id
                    LEFT JOIN bank_client_categories bcc ON bcc.id=tb.client_category_id
                    LEFT JOIN companies c                ON c.id=tb.company_id
                    LEFT JOIN company_types ct           ON ct.id=c.company_type_id
                    WHERE tb.task_id = ?");
                $dSt->execute([$id]);
                $detail = $dSt->fetch(PDO::FETCH_ASSOC);
                break;

            default:
                $dSt = $db->prepare("SELECT * FROM {$detailTable} WHERE task_id = ?");
                $dSt->execute([$id]);
                $detail = $dSt->fetch();
        }
    } catch (Exception $e) { $detail = null; }
}

// ── Company data ──────────────────────────────────────────────────────────────
$companyData    = null;
$companyTypeVal = '';
$companyPanRow  = null;
if ($task['company_id']) {
    $cpStmt = $db->prepare("
        SELECT c.*, ct.type_name AS company_type_name, ct.id AS company_type_id_val
        FROM companies c
        LEFT JOIN company_types ct ON ct.id = c.company_type_id
        WHERE c.id = ?");
    $cpStmt->execute([$task['company_id']]);
    $companyData    = $cpStmt->fetch();
    $companyTypeVal = $companyData['company_type_name'] ?? '';
    $companyPanRow  = ['pan_number' => $companyData['pan_number'] ?? '', 'company_name' => $companyData['company_name'] ?? ''];
}

// ── Task statuses ─────────────────────────────────────────────────────────────
$taskStatuses = $db->query("SELECT id, status_name, color, bg_color, icon FROM task_status ORDER BY id ASC")->fetchAll();

// ── Lookups for TAX edit ──────────────────────────────────────────────────────
$taxOfficeTypes = $taxTypes = $taxStaff = $allStaff = [];
if ($task['dept_code'] === 'TAX') {
    try { $taxOfficeTypes = $db->query("SELECT id, office_name, address FROM tax_office_types ORDER BY office_name")->fetchAll(); } catch (Exception $e) {}
    try { $taxTypes       = $db->query("SELECT id, tax_type_name FROM tax_type ORDER BY id")->fetchAll(); } catch (Exception $e) {}
    try {
        $taxStaff = $db->query("
            SELECT u.id, u.full_name
            FROM users u
            JOIN departments d ON d.id = u.department_id
            JOIN roles r ON r.id = u.role_id
            WHERE d.dept_code = 'TAX' AND u.is_active = 1
            ORDER BY u.full_name")->fetchAll();
    } catch (Exception $e) {}
    try {
        $allStaff = $db->query("
            SELECT u.id, u.full_name FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            JOIN roles r ON r.id = u.role_id
            WHERE r.role_name IN ('staff','admin','executive') AND u.is_active = 1
            ORDER BY u.full_name")->fetchAll();
    } catch (Exception $e) {}
}
// ── Lookups for BANK edit ─────────────────────────────────────────────────────
$allBanks = $allCats = [];
$isNikitaBank = (
    ($staffProfile['dept_code'] ?? '') === 'BANK'
    && strtolower(trim($staffProfile['full_name'] ?? '')) === 'nikita adhikari'
);
if ($task['dept_code'] === 'BANK' && $isNikitaBank) {
    try { $allBanks = $db->query("SELECT id,bank_name,address FROM bank_references WHERE is_active=1 ORDER BY bank_name")->fetchAll(); } catch (Exception $e) {}
    try { $allCats  = $db->query("SELECT id,category_name FROM bank_client_categories ORDER BY category_name")->fetchAll(); } catch (Exception $e) {}
}
// ── Saved address suggestions for TAX ────────────────────────────────────────
$savedAddressSuggestions = [];
if ($task['dept_code'] === 'TAX') {
    try {
        $savedAddressSuggestions = $db->query("
            SELECT DISTINCT assigned_office_address FROM task_tax
            WHERE assigned_office_address IS NOT NULL AND assigned_office_address != ''
            ORDER BY assigned_office_address")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
}

// Current office address
$currentOfficeAddr = '';
if ($detail && isset($detail['assigned_office_address'])) $currentOfficeAddr = $detail['assigned_office_address'] ?? '';
if ($currentOfficeAddr === '' && $detail && !empty($detail['assigned_office_id'])) {
    foreach ($taxOfficeTypes as $_o) {
        if ($_o['id'] == $detail['assigned_office_id']) { $currentOfficeAddr = $_o['address'] ?? ''; break; }
    }
}

// ── Follow-up history ─────────────────────────────────────────────────────────
$followupHistory = [];

if (in_array($task['dept_code'], ['RETAIL', 'TAX'])) {

    $fuStmt = $db->prepare("
        SELECT 
            tf.id,
            tf.task_id,
            tf.followup_date,
            tf.notes,
            tf.created_by,
            tf.created_at,
            u.full_name AS added_by_name
        FROM task_followups tf
        LEFT JOIN users u ON u.id = tf.created_by
        WHERE tf.task_id = ?
        ORDER BY tf.followup_date ASC
    ");

    $fuStmt->execute([$id]);
    $followupHistory = $fuStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Staff in same branch AND same department for transfer ─────────────────────
$sameBranchStaff = $db->prepare("
    SELECT u.id, u.full_name, u.employee_id, r.role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.role_name IN ('staff', 'admin')
      AND u.is_active = 1
      AND u.branch_id = ?
      AND u.department_id = ?
      AND u.id != ?
    ORDER BY r.role_name, u.full_name");
$sameBranchStaff->execute([$staffProfile['branch_id'], $task['department_id'], $user['id']]);
$sameBranchStaff = $sameBranchStaff->fetchAll();

// ── Finance department in same branch for dept-transfer ──────────────────────
$branchDepts = $db->prepare("
    SELECT DISTINCT d.id, d.dept_name, d.dept_code
    FROM departments d
    JOIN users u ON u.department_id = d.id
    WHERE u.branch_id = ?
      AND d.is_active = 1
      AND d.dept_code = 'FIN'
      AND d.id != ?
    ORDER BY d.dept_name");
$branchDepts->execute([$staffProfile['branch_id'], $task['department_id']]);
$branchDepts = $branchDepts->fetchAll();

// ── All staff+admin per dept for dept-transfer (branch-scoped) ────────────────
$branchAllStaff = $db->prepare("
    SELECT u.id, u.full_name, u.employee_id, u.department_id, d.dept_code, r.role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE r.role_name IN ('staff', 'admin')
      AND u.is_active = 1
      AND u.branch_id = ?
      AND u.id != ?
    ORDER BY u.full_name");
$branchAllStaff->execute([$staffProfile['branch_id'], $user['id']]);
$branchAllStaff = $branchAllStaff->fetchAll();

// ── Workflow & Comments ───────────────────────────────────────────────────────
$workflow = $comments = [];
try {
    $w2 = $db->prepare("
        SELECT tw.*, u1.full_name AS from_name, u2.full_name AS to_name,
               d1.dept_name AS from_dept, d2.dept_name AS to_dept
        FROM task_workflow tw
        LEFT JOIN users u1 ON u1.id = tw.from_user_id
        LEFT JOIN users u2 ON u2.id = tw.to_user_id
        LEFT JOIN departments d1 ON d1.id = tw.from_dept_id
        LEFT JOIN departments d2 ON d2.id = tw.to_dept_id
        WHERE tw.task_id = ? ORDER BY tw.created_at DESC LIMIT 10");
    $w2->execute([$id]);
    $workflow = $w2->fetchAll();
} catch (Exception $e) {}
try {
    $c2 = $db->prepare("SELECT tc.*, u.full_name FROM task_comments tc LEFT JOIN users u ON u.id = tc.user_id WHERE tc.task_id = ? ORDER BY tc.created_at ASC");
    $c2->execute([$id]);
    $comments = $c2->fetchAll();
} catch (Exception $e) {}

$taskAssignedToId   = $task['assigned_to'] ?? null;
$taskAssignedToName = $task['assigned_to_name'] ?? '—';

// ═════════════════════════════════════════════════════════════════════════════
// POST HANDLERS
// ═════════════════════════════════════════════════════════════════════════════

// ── POST: save_tax ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tax']) && $task['dept_code'] === 'TAX') {
    verifyCsrf();
    $t2         = $_POST['tax'] ?? [];
    $firm       = trim($t2['firm_name'] ?? '') ?: ($task['company_name'] ?? '');
    $biz        = trim($t2['business_type'] ?? '') ?: $companyTypeVal;
    $pan        = trim($t2['pan_number'] ?? '') ?: ($companyPanRow['pan_number'] ?? '');
    $officeAddr = trim($t2['assigned_office_address'] ?? '');
    $taxFy      = trim($t2['fiscal_year'] ?? '');
    $taxFyId    = getFiscalYearId($db, $taxFy);

    // Ensure columns exist
    foreach ([
        'fiscal_year VARCHAR(10) NULL AFTER tax_type_id',
        'fiscal_year_id INT NULL AFTER fiscal_year',
        'assigned_office_address VARCHAR(200) NULL AFTER assigned_office_id',
        'total_amount DECIMAL(12,2) DEFAULT 0 AFTER tax_clearance_status_id',
    ] as $colDef) {
        $colName = explode(' ', trim($colDef))[0];
        try { $db->query("SELECT $colName FROM task_tax LIMIT 1"); } catch (Exception $e) {
            try { $db->exec("ALTER TABLE task_tax ADD COLUMN $colDef"); } catch (Exception $e2) {}
        }
    }

    $hasAddrCol = true;
    try { $db->query("SELECT assigned_office_address FROM task_tax LIMIT 1"); } catch (Exception $e) { $hasAddrCol = false; }

    $ex = $db->prepare("SELECT id FROM task_tax WHERE task_id=?");
    $ex->execute([$id]);

    if ($hasAddrCol) {
        $p = [
            $task['company_id'], $firm,
            ($t2['assigned_office_id'] ?? '') !== '' ? (int) $t2['assigned_office_id'] : null,
            $officeAddr ?: null,
            ($t2['tax_type_id'] ?? '') !== '' ? (int) $t2['tax_type_id'] : null,
            $taxFy, $taxFyId,
            trim($t2['submission_number'] ?? ''),
            trim($t2['udin_no'] ?? ''),
            $biz, $pan,
            ($t2['assigned_to'] ?? '') !== '' ? (int) $t2['assigned_to'] : null,
            ($t2['file_received_by'] ?? '') !== '' ? (int) $t2['file_received_by'] : null,
            ($t2['updated_by'] ?? '') !== '' ? (int) $t2['updated_by'] : null,
            ($t2['verify_by'] ?? '') !== '' ? (int) $t2['verify_by'] : null,
            ($t2['tax_clearance_status_id'] ?? '') !== '' ? (int) $t2['tax_clearance_status_id'] : null,
            ($t2['total_amount'] ?? '') !== '' ? (float) $t2['total_amount'] : 0,
            $t2['completed_date'] ?: null,
            $t2['follow_up_date'] ?: null,
            trim($t2['remarks'] ?? ''),
            trim($t2['notes'] ?? ''),
        ];
        if ($ex->fetch()) {
            $db->prepare("UPDATE task_tax SET company_id=?,firm_name=?,assigned_office_id=?,assigned_office_address=?,
                tax_type_id=?,fiscal_year=?,fiscal_year_id=?,submission_number=?,udin_no=?,business_type=?,pan_number=?,
                assigned_to=?,file_received_by=?,updated_by=?,verify_by=?,tax_clearance_status_id=?,
                total_amount=?,completed_date=?,follow_up_date=?,remarks=?,notes=?
                WHERE task_id=?")->execute(array_merge($p, [$id]));
        } else {
            $db->prepare("INSERT INTO task_tax(task_id,company_id,firm_name,assigned_office_id,assigned_office_address,
                tax_type_id,fiscal_year,fiscal_year_id,submission_number,udin_no,business_type,pan_number,
                assigned_to,file_received_by,updated_by,verify_by,tax_clearance_status_id,
                total_amount,completed_date,follow_up_date,remarks,notes)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$id], $p));
        }
    } else {
        $p = [
            $task['company_id'], $firm,
            ($t2['assigned_office_id'] ?? '') !== '' ? (int) $t2['assigned_office_id'] : null,
            ($t2['tax_type_id'] ?? '') !== '' ? (int) $t2['tax_type_id'] : null,
            $taxFy, $taxFyId,
            trim($t2['submission_number'] ?? ''),
            trim($t2['udin_no'] ?? ''),
            $biz, $pan,
            ($t2['assigned_to'] ?? '') !== '' ? (int) $t2['assigned_to'] : null,
            ($t2['file_received_by'] ?? '') !== '' ? (int) $t2['file_received_by'] : null,
            ($t2['updated_by'] ?? '') !== '' ? (int) $t2['updated_by'] : null,
            ($t2['verify_by'] ?? '') !== '' ? (int) $t2['verify_by'] : null,
            ($t2['tax_clearance_status_id'] ?? '') !== '' ? (int) $t2['tax_clearance_status_id'] : null,
            ($t2['total_amount'] ?? '') !== '' ? (float) $t2['total_amount'] : 0,
            $t2['completed_date'] ?: null,
            $t2['follow_up_date'] ?: null,
            trim($t2['remarks'] ?? ''),
            trim($t2['notes'] ?? ''),
        ];
        if ($ex->fetch()) {
            $db->prepare("UPDATE task_tax SET company_id=?,firm_name=?,assigned_office_id=?,tax_type_id=?,fiscal_year=?,fiscal_year_id=?,submission_number=?,udin_no=?,business_type=?,pan_number=?,assigned_to=?,file_received_by=?,updated_by=?,verify_by=?,tax_clearance_status_id=?,total_amount=?,completed_date=?,follow_up_date=?,remarks=?,notes=? WHERE task_id=?")->execute(array_merge($p, [$id]));
        } else {
            $db->prepare("INSERT INTO task_tax(task_id,company_id,firm_name,assigned_office_id,tax_type_id,fiscal_year,fiscal_year_id,submission_number,udin_no,business_type,pan_number,assigned_to,file_received_by,updated_by,verify_by,tax_clearance_status_id,total_amount,completed_date,follow_up_date,remarks,notes)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$id], $p));
        }
    }

    // Track follow-up history
    $newFollowUpDate = $t2['follow_up_date'] ?? '';
    $newFollowUpNote = trim($t2['follow_up_note'] ?? '');
    if ($newFollowUpDate) {
        $lastFu = $db->prepare("SELECT followup_date FROM task_followups WHERE task_id=? ORDER BY created_at DESC LIMIT 1");
        $lastFu->execute([$id]);
        $lastDate = $lastFu->fetchColumn();
        if ($lastDate !== $newFollowUpDate) {
            $db->prepare("
                INSERT INTO task_followups(task_id,followup_date,notes,created_by)
                VALUES(?,?,?,?)
            ")->execute([$id, $newFollowUpDate, $newFollowUpNote ?: null, $user['id']]);
        }
    }

    if (!empty($t2['status_id'])) {
        $db->prepare("UPDATE tasks SET status_id=?,updated_at=NOW() WHERE id=?")->execute([(int) $t2['status_id'], $id]);
    }
    syncTaskFiscalYear($db, $id);
    logActivity("Tax saved: {$task['task_number']}", 'tasks');
    setFlash('success', 'Tax details saved.');
    header("Location: view.php?id={$id}");
    exit;
}
// ── POST: save_banking (staff — Nikita only) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_banking'])
    && $task['dept_code'] === 'BANK' && $isNikitaBank) {
    verifyCsrf();
    $b = $_POST['banking'] ?? [];

    // Ensure FY columns exist
    foreach (['fiscal_year VARCHAR(10) NULL AFTER completion_date', 'fiscal_year_id INT NULL AFTER fiscal_year'] as $colDef) {
        $colName = explode(' ', trim($colDef))[0];
        try { $db->query("SELECT $colName FROM task_banking LIMIT 1"); } catch (Exception $e) {
            try { $db->exec("ALTER TABLE task_banking ADD COLUMN $colDef"); } catch (Exception $e2) {}
        }
    }

    foreach (['assigned_date', 'ecd', 'completion_date'] as $df) {
        $b[$df] = !empty($b[$df]) ? $b[$df] : null;
    }
    foreach (['sales_check','audit_check','provisional_financial_statement','projected','consulting','nta','salary_certificate','ca_certification','etds'] as $nf) {
        $b[$nf] = (isset($b[$nf]) && $b[$nf] !== '') ? (int)$b[$nf] : null;
    }
    $b['bill_issued']    = !empty($b['bill_issued']) ? 1 : 0;
    $bankRefId           = ($b['bank_reference_id'] ?? '') !== '' ? (int)$b['bank_reference_id'] : null;
    $clientCatId         = ($b['client_category_id'] ?? '') !== '' ? (int)$b['client_category_id'] : null;

    $ex = $db->prepare("SELECT id FROM task_banking WHERE task_id=?");
    $ex->execute([$task['id']]);

    if ($ex->fetch()) {
        $db->prepare("
            UPDATE task_banking SET
                company_id=?, bank_reference_id=?, client_category_id=?,
                ecd=?, completion_date=?,
                sales_check=?, audit_check=?, provisional_financial_statement=?,
                projected=?, consulting=?, nta=?, salary_certificate=?,
                ca_certification=?, etds=?,
                od=?, term=?, interest_rate=?,
                bill_issued=?, remarks=?
            WHERE task_id=?
        ")->execute([
            $task['company_id'], $bankRefId, $clientCatId,
            $b['ecd'], $b['completion_date'],
            $b['sales_check'], $b['audit_check'], $b['provisional_financial_statement'],
            $b['projected'], $b['consulting'], $b['nta'], $b['salary_certificate'],
            $b['ca_certification'], $b['etds'],
            ($b['od'] ?? '') !== '' ? (float)$b['od'] : null,
            ($b['term'] ?? '') !== '' ? (float)$b['term'] : null,
            ($b['interest_rate'] ?? '') !== '' ? (float)$b['interest_rate'] : null,
            $b['bill_issued'], $b['remarks'] ?? null,
            $task['id'],
        ]);
    } else {
        $db->prepare("
            INSERT INTO task_banking(
                task_id, company_id, bank_reference_id, client_category_id,
                ecd, completion_date,
                sales_check, audit_check, provisional_financial_statement, projected,
                consulting, nta, salary_certificate, ca_certification, etds,
                od, term, interest_rate,
                bill_issued, remarks
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $task['id'], $task['company_id'], $bankRefId, $clientCatId,
            $b['ecd'], $b['completion_date'],
            $b['sales_check'], $b['audit_check'], $b['provisional_financial_statement'],
            $b['projected'], $b['consulting'], $b['nta'], $b['salary_certificate'],
            $b['ca_certification'], $b['etds'],
            ($b['od'] ?? '') !== '' ? (float)$b['od'] : null,
            ($b['term'] ?? '') !== '' ? (float)$b['term'] : null,
            ($b['interest_rate'] ?? '') !== '' ? (float)$b['interest_rate'] : null,
            $b['bill_issued'], $b['remarks'] ?? null,
        ]);
    }

    syncTaskFiscalYear($db, $task['id']);
    logActivity("Banking saved by staff: {$task['task_number']}", 'tasks');
    setFlash('success', 'Banking details saved.');
    header("Location: view.php?id={$task['id']}");
    exit;
}
// ── POST: update_work_status (RETAIL) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_work_status'])) {
    verifyCsrf();
    $newWorkStatusId = (int) ($_POST['work_status_id'] ?? 0);
    $workNotes       = trim($_POST['work_notes'] ?? '');

    if ($newWorkStatusId && $task['dept_code'] === 'RETAIL') {
        $stNameStmt = $db->prepare("SELECT status_name FROM task_status WHERE id = ?");
        $stNameStmt->execute([$newWorkStatusId]);
        $newWorkStatusName = $stNameStmt->fetchColumn();

        $db->prepare("UPDATE task_retail SET work_status_id=?, notes=? WHERE task_id=?")
           ->execute([$newWorkStatusId, $workNotes ?: null, $id]);
        $db->prepare("UPDATE tasks SET status_id=?, updated_at=NOW() WHERE id=?")
           ->execute([$newWorkStatusId, $id]);
        if ($newWorkStatusName === 'Done') {
            $db->prepare("UPDATE task_retail SET completed_date=CURDATE() WHERE task_id=?")->execute([$id]);
        }
        try {
            $db->prepare("INSERT INTO task_workflow(task_id,action,from_user_id,old_status,new_status,remarks)VALUES(?,?,?,?,?,?)")
               ->execute([$id, 'status_changed', $user['id'], $task['status'], $newWorkStatusName, $workNotes]);
        } catch (Exception $e) {}

        if (!empty($task['created_by'])) {
            $workMsg  = "Task #{$task['task_number']}";
            if (!empty($task['company_name'])) $workMsg .= " ({$task['company_name']})";
            $workMsg .= " — work status updated to \"{$newWorkStatusName}\" by {$staffProfile['full_name']}.";
            if ($workNotes) $workMsg .= "\n\nNote: {$workNotes}";
            notify((int) $task['created_by'],
                $newWorkStatusName === 'Done' ? "Task Completed: {$task['task_number']}" : "Work Status Updated: {$task['task_number']}",
                $workMsg, 'status', APP_URL . '/admin/tasks/view.php?id=' . $id, true,
                ['template' => 'task_status_changed', 'task' => ['id' => $id, 'task_number' => $task['task_number'], 'title' => $task['title'], 'old_status' => $task['status'], 'new_status' => $newWorkStatusName, 'due_date' => $task['due_date'] ?? null, 'company' => $task['company_name'] ?? '', 'priority' => $task['priority'] ?? '']]);
        }
        logActivity("Work status updated: {$task['task_number']} → {$newWorkStatusName}", 'tasks');
        setFlash('success', 'Work status updated.');
        header("Location: view.php?id={$id}");
        exit;
    }
}

// ── POST: update_status (general) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    verifyCsrf();
    $newStatus    = $_POST['new_status'] ?? '';
    $validStatuses = array_column($db->query("SELECT status_name FROM task_status")->fetchAll(), 'status_name');
    if (in_array($newStatus, $validStatuses)) {
        $stRow = $db->prepare("SELECT id FROM task_status WHERE status_name=?");
        $stRow->execute([$newStatus]);
        $newStatusId = (int) ($stRow->fetchColumn() ?: 1);
        $db->prepare("UPDATE tasks SET status_id=?, updated_at=NOW() WHERE id=?")->execute([$newStatusId, $id]);
        try {
            $db->prepare("INSERT INTO task_workflow(task_id,action,from_user_id,old_status,new_status)VALUES(?,?,?,?,?)")
               ->execute([$id, 'status_changed', $user['id'], $task['status'], $newStatus]);
        } catch (Exception $e) {}
        if (!empty($task['created_by']) && $task['created_by'] != $user['id']) {
            $msg = "Task #{$task['task_number']}";
            if (!empty($task['company_name'])) $msg .= " ({$task['company_name']})";
            $msg .= " — status changed from \"{$task['status']}\" to \"{$newStatus}\" by {$staffProfile['full_name']}.";
            notify((int) $task['created_by'], "Status Updated: {$task['task_number']}", $msg, 'status',
                APP_URL . '/admin/tasks/view.php?id=' . $id, true,
                ['template' => 'task_status_changed', 'task' => ['id' => $id, 'task_number' => $task['task_number'], 'title' => $task['title'], 'old_status' => $task['status'], 'new_status' => $newStatus, 'due_date' => $task['due_date'] ?? null, 'company' => $task['company_name'] ?? '', 'priority' => $task['priority'] ?? '']]);
        }
        logActivity("Status update: {$task['task_number']} → {$newStatus}", 'tasks');
        setFlash('success', 'Status updated.');
        header("Location: view.php?id={$id}");
        exit;
    }
}

// ── POST: transfer_staff ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_staff'])) {
    verifyCsrf();
    $newStaffId   = (int) ($_POST['new_staff_id'] ?? 0);
    $transferNote = trim($_POST['transfer_note'] ?? '');

    if (!$newStaffId) { setFlash('error', 'Please select a staff member.'); header("Location: view.php?id={$id}"); exit; }

    $verifyStmt = $db->prepare("SELECT id, full_name, email FROM users WHERE id=? AND branch_id=? AND is_active=1");
    $verifyStmt->execute([$newStaffId, $staffProfile['branch_id']]);
    $newStaff = $verifyStmt->fetch();

    if (!$newStaff) { setFlash('error', 'Invalid staff selection.'); header("Location: view.php?id={$id}"); exit; }

    $db->prepare("UPDATE tasks SET assigned_to=?, updated_at=NOW() WHERE id=?")->execute([$newStaffId, $id]);
    try {
        $db->prepare("INSERT INTO task_workflow(task_id,action,from_user_id,to_user_id,from_dept_id,to_dept_id,old_status,new_status,remarks)VALUES(?,?,?,?,?,?,?,?,?)")
           ->execute([$id, 'transferred_staff', $user['id'], $newStaffId, $task['department_id'], $task['department_id'], $task['status'], $task['status'], $transferNote ?: null]);
    } catch (Exception $e) {}

    $staffMsg  = "Task {$task['task_number']} — \"{$task['title']}\" has been transferred to you by {$staffProfile['full_name']}.";
    if (!empty($task['company_name'])) $staffMsg .= "\nClient: {$task['company_name']}";
    if ($transferNote) $staffMsg .= "\n\n📋 Transfer Note:\n{$transferNote}";

    notify($newStaffId, "Task Assigned: {$task['task_number']}", $staffMsg, 'transfer',
        APP_URL . '/staff/tasks/view.php?id=' . $id, true,
        ['template' => 'task_assigned', 'task' => [
            'id'          => $id,
            'task_number' => $task['task_number'],
            'title'       => $task['title'],
            'department'  => $task['dept_name'] ?? '',
            'due_date'    => $task['due_date'] ?? null,
            'company'     => $task['company_name'] ?? '',
            'priority'    => $task['priority'] ?? '',
            'transfer_note' => $transferNote,
            'transferred_by' => $staffProfile['full_name'],
        ]]);

    logActivity("Task transferred to staff: {$task['task_number']} → {$newStaff['full_name']}", 'tasks');
    setFlash('success', "Task transferred to {$newStaff['full_name']} successfully.");
    header('Location: index.php');
    exit;
}

// ── POST: transfer_department ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_department'])) {
    verifyCsrf();
    $newDeptId    = (int) ($_POST['new_dept_id'] ?? 0);
    $newAssignTo  = ($_POST['transfer_dept_staff'] ?? '') !== '' ? (int) $_POST['transfer_dept_staff'] : null;
    $transferNote = trim($_POST['dept_transfer_note'] ?? '');

    if (!$newDeptId || $newDeptId === (int) $task['department_id']) {
        setFlash('error', 'Please select a different department.');
        header("Location: view.php?id={$id}"); exit;
    }

    // Verify dept belongs to same branch
    $deptVerify = $db->prepare("
        SELECT d.id, d.dept_name FROM departments d
        JOIN users u ON u.department_id = d.id
        WHERE d.id = ? AND u.branch_id = ? AND d.is_active = 1
        LIMIT 1");
    $deptVerify->execute([$newDeptId, $staffProfile['branch_id']]);
    $newDept = $deptVerify->fetch();

    if (!$newDept) { setFlash('error', 'Invalid department selection.'); header("Location: view.php?id={$id}"); exit; }

    // Verify assigned-to user is in same branch
    if ($newAssignTo) {
        $assignVerify = $db->prepare("SELECT id, full_name, email FROM users WHERE id=? AND branch_id=? AND is_active=1");
        $assignVerify->execute([$newAssignTo, $staffProfile['branch_id']]);
        $newAssignUser = $assignVerify->fetch();
        if (!$newAssignUser) { $newAssignTo = null; $newAssignUser = null; }
    } else {
        $newAssignUser = null;
    }

    $oldDeptId = (int) $task['department_id'];
    $db->prepare("UPDATE tasks SET department_id=?, assigned_to=?, updated_at=NOW() WHERE id=?")
       ->execute([$newDeptId, $newAssignTo, $id]);

    try {
        $db->prepare("INSERT INTO task_workflow(task_id,action,from_user_id,from_dept_id,to_dept_id,to_user_id,old_status,new_status,remarks)VALUES(?,?,?,?,?,?,?,?,?)")
           ->execute([$id, 'transferred_dept', $user['id'], $oldDeptId, $newDeptId, $newAssignTo, $task['status'], $task['status'], $transferNote ?: null]);
    } catch (Exception $e) {}

    // Notify the newly assigned user (if any)
    if ($newAssignTo && $newAssignUser) {
        $deptMsg  = "Task {$task['task_number']} — \"{$task['title']}\" has been transferred to the {$newDept['dept_name']} department and assigned to you by {$staffProfile['full_name']}.";
        if (!empty($task['company_name'])) $deptMsg .= "\nClient: {$task['company_name']}";
        if ($transferNote) $deptMsg .= "\n\n📋 Transfer Reason:\n{$transferNote}";

        notify($newAssignTo, "Task Transferred to You: {$task['task_number']}", $deptMsg, 'transfer',
            APP_URL . '/staff/tasks/view.php?id=' . $id, true,
            ['template' => 'task_assigned', 'task' => [
                'id'             => $id,
                'task_number'    => $task['task_number'],
                'title'          => $task['title'],
                'department'     => $newDept['dept_name'],
                'due_date'       => $task['due_date'] ?? null,
                'company'        => $task['company_name'] ?? '',
                'priority'       => $task['priority'] ?? '',
                'transfer_note'  => $transferNote,
                'transferred_by' => $staffProfile['full_name'],
            ]]);
    }

    // Notify the creator/admin
    if (!empty($task['created_by']) && $task['created_by'] != $user['id']) {
        $adminMsg = "Task {$task['task_number']} was transferred from {$task['dept_name']} to {$newDept['dept_name']} by {$staffProfile['full_name']}.";
        if ($transferNote) $adminMsg .= "\nReason: {$transferNote}";
        notify((int) $task['created_by'], "Task Dept Transferred: {$task['task_number']}", $adminMsg, 'transfer',
            APP_URL . '/admin/tasks/view.php?id=' . $id, false);
    }

    logActivity("Task dept transferred: {$task['task_number']} → {$newDept['dept_name']}", 'tasks');
    setFlash('success', "Task transferred to {$newDept['dept_name']} department.");
    header("Location: view.php?id={$id}");
    exit;
}

// ── POST: add_comment ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    verifyCsrf();
    $comment = trim($_POST['comment'] ?? '');
    if ($comment) {
        try { $db->prepare("INSERT INTO task_comments(task_id,user_id,comment)VALUES(?,?,?)")->execute([$id, $user['id'], $comment]); } catch (Exception $e) {}
        header("Location: view.php?id={$id}#comments");
        exit;
    }
}

// ── Page setup ────────────────────────────────────────────────────────────────
$pageTitle = 'Task: ' . $task['task_number'];
$sClass    = 'status-' . strtolower(str_replace(' ', '-', $task['status'] ?? ''));
$isOverdue = $task['due_date'] && strtotime($task['due_date']) < time() && !$isDone;

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div style="padding:1.5rem 0;">
            <?= flashHtml() ?>

            <!-- Header -->
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
                <div class="d-flex align-items-center gap-2">
                    <span class="task-number"><?= htmlspecialchars($task['task_number']) ?></span>
                    <span class="status-badge <?= $sClass ?>"><?= htmlspecialchars($task['status'] ?? '') ?></span>
                    <?php if ($isOverdue): ?>
                        <span class="badge" style="background:#fef2f2;color:#ef4444;font-size:.75rem;">⚠️ OVERDUE</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-4">

                <!-- ══════════════ LEFT COLUMN ══════════════ -->
                <div class="col-lg-8">

                    <!-- Task Info Card -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <div>
                                <span class="task-number d-block mb-1"><?= htmlspecialchars($task['task_number']) ?></span>
                                <h5 style="font-size:1.05rem;margin:0;"><?= htmlspecialchars($task['title']) ?></h5>
                            </div>
                            <span class="status-badge <?= $sClass ?>"><?= htmlspecialchars($task['status'] ?? 'Pending') ?></span>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3">
                                <?php foreach ([
                                    'Department'  => htmlspecialchars($task['dept_name'] ?? '—'),
                                    'Branch'      => htmlspecialchars($task['branch_name'] ?? '—'),
                                    'Company'     => htmlspecialchars($task['company_name'] ?? '—'),
                                    'Assigned By' => htmlspecialchars($task['created_by_name'] ?? '—'),
                                    'Assigned To' => htmlspecialchars($task['assigned_to_name'] ?? 'Unassigned'),
                                    'Priority'    => '<span class="status-badge priority-' . $task['priority'] . '">' . ucfirst($task['priority']) . '</span>',
                                    'Due Date'    => '<span style="' . ($isOverdue ? 'color:#ef4444;font-weight:600;' : '') . '">' . ($task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : '—') . ($isOverdue ? ' ⚠️' : '') . '</span>',
                                    'Fiscal Year' => htmlspecialchars($task['fiscal_year'] ?? '—'),
                                    'Created'     => date('d M Y, H:i', strtotime($task['created_at'])),
                                ] as $label => $val): ?>
                                    <div class="col-md-4">
                                        <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;"><?= $label ?></div>
                                        <div style="font-size:.9rem;margin-top:.2rem;"><?= $val ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($task['description']): ?>
                                    <div class="col-12">
                                        <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">Description</div>
                                        <div style="font-size:.88rem;margin-top:.2rem;"><?= nl2br(htmlspecialchars($task['description'])) ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($task['remarks']): ?>
                                    <div class="col-12">
                                        <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">Remarks</div>
                                        <div style="font-size:.88rem;margin-top:.2rem;"><?= nl2br(htmlspecialchars($task['remarks'])) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ════ TAX DETAILS ════ -->
                    <?php if ($task['dept_code'] === 'TAX'): ?>
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-receipt text-warning me-2"></i>Tax Details</h5>
                        </div>
                        <div class="card-mis-body">

                            <datalist id="office_address_list">
                                <?php foreach ($savedAddressSuggestions as $addr): ?>
                                    <option value="<?= htmlspecialchars($addr) ?>">
                                <?php endforeach; ?>
                            </datalist>

                            <!-- Saved details view -->
                            <?php if ($detail): ?>
                                <div class="row g-3 mb-4">
                                    <?php
                                    $officeDisplay = $detail['assigned_office_name'] ?? '—';
                                    $offAddrSaved  = $detail['assigned_office_address'] ?? $detail['assigned_office_default_address'] ?? '';
                                    if ($offAddrSaved)
                                        $officeDisplay .= ' <span style="color:#6b7280;font-size:.8em;">– ' . htmlspecialchars($offAddrSaved) . '</span>';
                                    else
                                        $officeDisplay = htmlspecialchars($officeDisplay);
                                    foreach ([
                                        'Firm Name'       => htmlspecialchars($detail['firm_name'] ?? '—'),
                                        'Assigned Office' => '<span style="background:#eff6ff;color:#3b82f6;padding:.2rem .6rem;border-radius:6px;font-weight:600;">' . $officeDisplay . '</span>',
                                        'Tax Type'        => '<span style="background:#f0fdf4;color:#16a34a;padding:.2rem .6rem;border-radius:6px;font-weight:600;">' . htmlspecialchars($detail['tax_type_name'] ?? '—') . '</span>',
                                        'Fiscal Year'     => htmlspecialchars($detail['fiscal_year'] ?? '—'),
                                        'Business Type'   => htmlspecialchars($detail['business_type'] ?? '—'),
                                        'PAN Number'      => htmlspecialchars($detail['pan_number'] ?? '—'),
                                        'Assigned To'     => htmlspecialchars($detail['assigned_to_name'] ?? '—'),
                                        'File Received'   => htmlspecialchars($detail['file_received_by_name'] ?? '—'),
                                        'Updated By'      => htmlspecialchars($detail['updated_by_name'] ?? '—'),
                                        'Verify By'       => htmlspecialchars($detail['verify_by_name'] ?? '—'),
                                        'Tax Clearance'   => htmlspecialchars($detail['tax_clearance_status_name'] ?? '—'),
                                        'Total Amount'    => 'Rs. ' . number_format($detail['total_amount'] ?? 0, 2),
                                        'Completed Date'  => ($detail['completed_date'] ?? '') ? date('d M Y', strtotime($detail['completed_date'])) : '—',
                                        'Follow-up Date'  => ($detail['follow_up_date'] ?? '') ? date('d M Y', strtotime($detail['follow_up_date'])) : '—',
                                    ] as $lbl => $val): ?>
                                        <div class="col-md-4">
                                            <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;"><?= $lbl ?></div>
                                            <div style="font-size:.88rem;margin-top:.2rem;"><?= $val ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php foreach ([
                                        ['Submission Number', $detail['submission_number'] ?? '', 'https://ird.gov.np', '#3b82f6', 'IRD Portal'],
                                        ['UDIN Number',       $detail['udin_no']           ?? '', 'https://udin.ican.org.np/', '#8b5cf6', 'UDIN Portal'],
                                    ] as [$lbl, $val, $url, $col, $btn]): ?>
                                        <div class="col-md-6">
                                            <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;"><?= $lbl ?></div>
                                            <div class="d-flex align-items-center gap-2 mt-1">
                                                <span style="font-size:.88rem;font-weight:600;"><?= htmlspecialchars($val ?: '—') ?></span>
                                                <?php if ($val): ?>
                                                    <a href="<?= $url ?>" target="_blank" style="background:<?= $col ?>;color:white;padding:.2rem .6rem;border-radius:6px;font-size:.72rem;text-decoration:none;">
                                                        <i class="fas fa-external-link-alt me-1"></i><?= $btn ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!empty($detail['remarks'])): ?>
                                        <div class="col-12">
                                            <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">Remarks</div>
                                            <div style="font-size:.88rem;margin-top:.2rem;"><?= nl2br(htmlspecialchars($detail['remarks'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($detail['notes'])): ?>
                                        <div class="col-12">
                                            <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">Notes</div>
                                            <div style="font-size:.88rem;margin-top:.2rem;"><?= nl2br(htmlspecialchars($detail['notes'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <hr style="border-color:#f3f4f6;">
                                <div style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                    <i class="fas fa-pen me-1"></i>Update Tax Details
                                </div>
                            <?php else: ?>
                                <div style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                    <i class="fas fa-plus me-1"></i>Add Tax Details
                                </div>
                            <?php endif; ?>

                            <!-- Follow-up History -->
                            <?php if (!empty($followupHistory)): ?>
                                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:1rem;margin-bottom:1rem;">
                                    <div style="font-size:.72rem;font-weight:700;color:#92400e;text-transform:uppercase;margin-bottom:.75rem;">
                                        <i class="fas fa-clock-rotate-left me-1"></i>Follow-up History
                                        <span style="background:#f59e0b;color:white;padding:.15rem .5rem;border-radius:99px;font-size:.68rem;margin-left:.4rem;"><?= count($followupHistory) ?> times</span>
                                    </div>
                                    <div style="position:relative;">
                                        <div style="position:absolute;left:11px;top:0;bottom:0;width:2px;background:#fde68a;"></div>
                                        <?php foreach ($followupHistory as $i => $fu): ?>
                                            <div style="display:flex;gap:.75rem;margin-bottom:.75rem;position:relative;">
                                                <div style="width:24px;height:24px;border-radius:50%;background:#f59e0b;color:white;display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;flex-shrink:0;z-index:1;"><?= $i + 1 ?></div>
                                                <div style="background:white;border:1px solid #fde68a;border-radius:8px;padding:.5rem .75rem;flex:1;">
                                                    <div style="font-size:.82rem;font-weight:700;color:#92400e;">
                                                        <?= date('d M Y', strtotime($fu['followup_date'])) ?>
                                                        <span style="font-weight:400;color:#9ca3af;font-size:.72rem;margin-left:.5rem;">set on <?= date('d M Y, H:i', strtotime($fu['created_at'])) ?> by <?= htmlspecialchars($fu['added_by_name']) ?></span>
                                                    </div>
                                                    <?php if ($fu['notes']): ?><div style="font-size:.78rem;color:#6b7280;margin-top:.2rem;font-style:italic;">"<?= htmlspecialchars($fu['notes']) ?>"</div><?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Tax Edit Form -->
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="save_tax" value="1">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label-mis">Firm Name</label>
                                        <input type="text" name="tax[firm_name]" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars($detail['firm_name'] ?? $task['company_name'] ?? '') ?>"
                                               <?= $task['company_id'] ? 'readonly style="background:#f0fdf4;cursor:not-allowed;"' : '' ?>>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label-mis">Assigned Office</label>
                                        <select name="tax[assigned_office_id]" class="form-select form-select-sm">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($taxOfficeTypes as $o): ?>
                                                <option value="<?= $o['id'] ?>" <?= ($detail['assigned_office_id'] ?? '') == $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['office_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label-mis">Office Branch Address</label>
                                        <input type="text" name="tax[assigned_office_address]" class="form-control form-control-sm"
                                               list="office_address_list" value="<?= htmlspecialchars($currentOfficeAddr) ?>" placeholder="e.g. Lazimpat, Kathmandu">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label-mis">Tax Type</label>
                                        <select name="tax[tax_type_id]" class="form-select form-select-sm">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($taxTypes as $tt): ?>
                                                <option value="<?= $tt['id'] ?>" <?= ($detail['tax_type_id'] ?? '') == $tt['id'] ? 'selected' : '' ?>><?= htmlspecialchars($tt['tax_type_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label-mis">Fiscal Year</label>
                                        <?= fiscalYearSelect('tax[fiscal_year]', $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy), $fys) ?>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label-mis">Business Type</label>
                                        <input type="text" name="tax[business_type]" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars($detail['business_type'] ?? $companyTypeVal) ?>"
                                               <?= ($task['company_id'] && $companyTypeVal) ? 'readonly style="background:#f0fdf4;cursor:not-allowed;"' : '' ?>>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label-mis">PAN Number</label>
                                        <?php if ($companyPanRow && $companyPanRow['pan_number']): ?>
                                            <input type="text" name="tax[pan_number]" class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars($detail['pan_number'] ?? $companyPanRow['pan_number']) ?>"
                                                   readonly style="background:#f0fdf4;font-weight:600;cursor:not-allowed;">
                                        <?php else: ?>
                                            <input type="text" name="tax[pan_number]" class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars($detail['pan_number'] ?? '') ?>" placeholder="Enter PAN">
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-mis">Submission Number <span style="font-size:.68rem;color:#9ca3af;">(from IRD)</span></label>
                                        <div class="input-group input-group-sm">
                                            <input type="text" name="tax[submission_number]" class="form-control" value="<?= htmlspecialchars($detail['submission_number'] ?? '') ?>">
                                            <a href="https://taxpayerportal.ird.gov.np/taxpayer/app.html" target="_blank" class="btn btn-outline-primary btn-sm"><i class="fas fa-external-link-alt"></i></a>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-mis">UDIN Number <span style="font-size:.68rem;color:#9ca3af;">(from ICAN)</span></label>
                                        <div class="input-group input-group-sm">
                                            <input type="text" name="tax[udin_no]" class="form-control" value="<?= htmlspecialchars($detail['udin_no'] ?? '') ?>">
                                            <a href="https://udin.ican.org.np/" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="fas fa-external-link-alt"></i></a>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Total Amount (Rs.)</label>
                                        <input type="number" name="tax[total_amount]" class="form-control form-control-sm" step="0.01" min="0" value="<?= htmlspecialchars($detail['total_amount'] ?? '0') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Tax Clearance Status</label>
                                        <select name="tax[tax_clearance_status_id]" class="form-select form-select-sm">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($taskStatuses as $ts): ?>
                                                <option value="<?= $ts['id'] ?>" <?= ($detail['tax_clearance_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ts['status_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Assigned To <span style="font-size:.65rem;color:#3b82f6;margin-left:.3rem;"><i class="fas fa-link me-1"></i>from task</span></label>
                                        <div class="form-control form-control-sm" style="background:#eff6ff;color:#1d4ed8;font-weight:600;cursor:default;display:flex;align-items:center;gap:.4rem;">
                                            <i class="fas fa-user-circle" style="color:#3b82f6;"></i><?= htmlspecialchars($taskAssignedToName) ?>
                                        </div>
                                        <input type="hidden" name="tax[assigned_to]" value="<?= $taskAssignedToId ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">File Received By</label>
                                        <select name="tax[file_received_by]" id="tax_file_received_by" class="form-select form-select-sm">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($allStaff as $s): ?>
                                                <option value="<?= $s['id'] ?>" <?= ($detail['file_received_by'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['full_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Updated By <span style="font-size:.65rem;color:#8b5cf6;margin-left:.3rem;"><i class="fas fa-filter me-1"></i>Tax dept only</span></label>
                                        <select name="tax[updated_by]" id="tax_updated_by" class="form-select form-select-sm">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($taxStaff as $s): ?>
                                                <option value="<?= $s['id'] ?>" <?= ($detail['updated_by'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['full_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Verify By</label>
                                        <select name="tax[verify_by]" id="tax_verify_by" class="form-select form-select-sm">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($taxStaff as $s): ?>
                                                <option value="<?= $s['id'] ?>" <?= ($detail['verify_by'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['full_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Completed Date</label>
                                        <input type="date" name="tax[completed_date]" class="form-control form-control-sm" value="<?= htmlspecialchars($detail['completed_date'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Follow-up Date</label>
                                        <input type="date" name="tax[follow_up_date]" class="form-control form-control-sm" value="<?= htmlspecialchars($detail['follow_up_date'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label-mis">Follow-up Note <span style="font-size:.65rem;color:#9ca3af;">(optional)</span></label>
                                        <input type="text" name="tax[follow_up_note]" class="form-control form-control-sm" placeholder="e.g. Called client, waiting for docs...">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label-mis">Remarks</label>
                                        <textarea name="tax[remarks]" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($detail['remarks'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label-mis">Notes</label>
                                        <textarea name="tax[notes]" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($detail['notes'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-gold btn-sm"><i class="fas fa-save me-1"></i>Save Tax Details</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- ════ RETAIL DETAILS ════ -->
                    <?php elseif ($task['dept_code'] === 'RETAIL' && $detail): ?>
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-store text-warning me-2"></i>Retail Details</h5>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3">
                                <?php foreach ([
                                    'Firm Name'           => $detail['firm_name']                ?? '—',
                                    'Company Type'        => $detail['company_type_name']        ?? '—',
                                    'File Type'           => $detail['file_type_name']           ?? '—',
                                    'PAN / VAT'           => $detail['pan_vat_name']             ?? '—',
                                    'VAT Client'          => $detail['vat_client_value']         ?? '—',
                                    'Return Type'         => $detail['return_type']              ?? '—',
                                    'Fiscal Year'         => $detail['fiscal_year']              ?? '—',
                                    'No. of Audit Years'  => $detail['no_of_audit_year']         ?? '—',
                                    'PAN No'              => $detail['pan_no']                   ?? '—',
                                    'Assigned Date'       => ($detail['assigned_date'] ?? '') ? date('d M Y', strtotime($detail['assigned_date'])) : '—',
                                    'Audit Type'          => $detail['audit_type_name']          ?? '—',
                                    'ECD'                 => ($detail['ecd'] ?? '') ? date('d M Y', strtotime($detail['ecd'])) : '—',
                                    'Opening Due'         => $detail['opening_due'] !== null ? 'Rs. ' . number_format($detail['opening_due'], 2) : '—',
                                    'Work Status'         => $detail['work_status_name']         ?? '—',
                                    'Finalisation Status' => $detail['finalisation_status_name'] ?? '—',
                                    'Finalised By'        => $detail['finalised_by_name']        ?? '—',
                                    'Completed Date'      => ($detail['completed_date'] ?? '') ? date('d M Y', strtotime($detail['completed_date'])) : '—',
                                    'Tax Clearance'       => $detail['tax_clearance_status_name'] ?? '—',
                                    'Backup'              => $detail['backup_status_value']      ?? '—',
                                    'Follow-up Date'      => ($detail['follow_up_date'] ?? '') ? date('d M Y', strtotime($detail['follow_up_date'])) : '—',
                                    'Notes'               => $detail['notes']                    ?? '—',
                                ] as $label => $val): ?>
                                    <div class="col-md-4">
                                        <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;"><?= $label ?></div>
                                        <div style="font-size:.88rem;margin-top:.2rem;"><?= htmlspecialchars($val) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Follow-up history for RETAIL -->
                    <?php if (!empty($followupHistory)): ?>
                        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:1rem;margin-bottom:1.5rem;">
                            <div style="font-size:.72rem;font-weight:700;color:#92400e;text-transform:uppercase;margin-bottom:.75rem;">
                                <i class="fas fa-clock-rotate-left me-1"></i>Follow-up History
                                <span style="background:#f59e0b;color:white;padding:.15rem .5rem;border-radius:99px;font-size:.68rem;margin-left:.4rem;"><?= count($followupHistory) ?> times</span>
                            </div>
                            <div style="position:relative;">
                                <div style="position:absolute;left:11px;top:0;bottom:0;width:2px;background:#fde68a;"></div>
                                <?php foreach ($followupHistory as $i => $fu): ?>
                                    <div style="display:flex;gap:.75rem;margin-bottom:.75rem;position:relative;">
                                        <div style="width:24px;height:24px;border-radius:50%;background:#f59e0b;color:white;display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;flex-shrink:0;z-index:1;"><?= $i + 1 ?></div>
                                        <div style="background:white;border:1px solid #fde68a;border-radius:8px;padding:.5rem .75rem;flex:1;">
                                            <div style="font-size:.82rem;font-weight:700;color:#92400e;">
                                                <?= date('d M Y', strtotime($fu['followup_date'])) ?>
                                                <span style="font-weight:400;color:#9ca3af;font-size:.72rem;margin-left:.5rem;">set on <?= date('d M Y, H:i', strtotime($fu['created_at'])) ?> by <?= htmlspecialchars($fu['added_by_name']) ?></span>
                                            </div>
                                            <?php if ($fu['notes']): ?><div style="font-size:.78rem;color:#6b7280;margin-top:.2rem;font-style:italic;">"<?= htmlspecialchars($fu['notes']) ?>"</div><?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                   <!-- ════ BANK DETAILS ════ -->
                    <?php elseif ($task['dept_code'] === 'BANK' && $isNikitaBank): ?>
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-landmark text-warning me-2"></i>Banking Details</h5>
                        </div>
                        <div class="card-mis-body">

                            <!-- Client info panel (always shown) -->
                            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:1rem;margin-bottom:1rem;">
                                <div style="font-size:.72rem;font-weight:700;color:#16a34a;text-transform:uppercase;margin-bottom:.5rem;">
                                    <i class="fas fa-building me-1"></i>Client Info
                                </div>
                                <div class="row g-2">
                                    <?php foreach ([
                                        'Company'    => $detail['company_name']      ?? ($task['company_name'] ?? '—'),
                                        'Contact'    => $detail['contact_person']    ?? '—',
                                        'Phone'      => $detail['contact_phone']     ?? '—',
                                        'PAN'        => $detail['company_pan']       ?? '—',
                                        'Type'       => $detail['company_type_name'] ?? '—',
                                        'Bank'       => $detail['bank_name']         ?? '—',
                                        'Category'   => $detail['client_category_name'] ?? '—',
                                        'ECD'        => ($detail['ecd'] ?? '') ? date('d M Y', strtotime($detail['ecd'])) : '—',
                                        'Completion' => ($detail['completion_date'] ?? '') ? date('d M Y', strtotime($detail['completion_date'])) : '—',
                                    ] as $lbl => $val): ?>
                                        <div class="col-md-4">
                                            <div style="font-size:.68rem;font-weight:700;color:#9ca3af;text-transform:uppercase;"><?= $lbl ?></div>
                                            <div style="font-size:.87rem;"><?= htmlspecialchars($val) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Saved checklist view -->
                            <?php if ($detail): ?>
                                <div style="background:#f9fafb;border-radius:10px;padding:1rem;margin-bottom:1rem;">
                                    <div style="font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">Saved Values</div>
                                    <div class="row g-2">
                                        <?php foreach ([
                                            'Sales Check'    => $detail['sales_check']     ?? '—',
                                            'Audit'          => $detail['audit_check']     ?? '—',
                                            'Provisional/FS' => $detail['provisional_financial_statement'] ?? '—',
                                            'Projected'      => $detail['projected']       ?? '—',
                                            'Consulting'     => $detail['consulting']      ?? '—',
                                            'NTA'            => $detail['nta']             ?? '—',
                                            'Salary Cert.'   => $detail['salary_certificate'] ?? '—',
                                            'CA Cert.'       => $detail['ca_certification'] ?? '—',
                                            'ETDS'           => $detail['etds']            ?? '—',
                                            'OD (Rs.)'       => $detail['od']   !== null ? 'Rs. '.number_format($detail['od'], 2).'L' : '—',
                                            'Term Loan (Rs.)'=> $detail['term'] !== null ? 'Rs. '.number_format($detail['term'], 2).'L' : '—',
                                            'Interest Rate %'=> $detail['interest_rate'] !== null ? $detail['interest_rate'].'%' : '—',
                                            'Bill Issued'    => ($detail['bill_issued'] ?? 0) ? '✅ Yes' : 'No',
                                        ] as $lbl => $val): ?>
                                            <div class="col-md-3 col-6">
                                                <div style="font-size:.68rem;font-weight:700;color:#9ca3af;text-transform:uppercase;"><?= $lbl ?></div>
                                                <div style="font-size:.87rem;font-weight:600;"><?= htmlspecialchars((string)$val) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (!empty($detail['remarks'])): ?>
                                        <div class="mt-2">
                                            <div style="font-size:.68rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">Remarks</div>
                                            <div style="font-size:.87rem;"><?= nl2br(htmlspecialchars($detail['remarks'])) ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <hr style="border-color:#f3f4f6;">
                                <div style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                    <i class="fas fa-pen me-1"></i>Update Banking Details
                                </div>
                            <?php else: ?>
                                <div style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                    <i class="fas fa-plus me-1"></i>Add Banking Details
                                </div>
                            <?php endif; ?>

                            <!-- Banking edit form -->
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="save_banking" value="1">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label-mis">Bank Name</label>
                                        <select name="banking[bank_reference_id]" id="bank_select" class="form-select form-select-sm">
                                            <option value="">-- Select Bank --</option>
                                            <?php foreach ($allBanks as $bk): ?>
                                                <option value="<?= $bk['id'] ?>"
                                                    <?= ($detail['bank_reference_id'] ?? '') == $bk['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($bk['bank_name']) ?>
                                                    <?php if (!empty($bk['address'])): ?> - <?= htmlspecialchars($bk['address']) ?><?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label-mis">Category</label>
                                        <select name="banking[client_category_id]" class="form-select form-select-sm">
                                            <option value="">--</option>
                                            <?php foreach ($allCats as $cat): ?>
                                                <option value="<?= $cat['id'] ?>"
                                                    <?= ($detail['client_category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($cat['category_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php foreach (['ecd' => 'ECD', 'completion_date' => 'Completion Date'] as $f => $l): ?>
                                        <div class="col-md-3">
                                            <label class="form-label-mis"><?= $l ?></label>
                                            <input type="date" name="banking[<?= $f ?>]" class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars($detail[$f] ?? '') ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div style="border-top:1px solid #f3f4f6;padding-top:1rem;margin-top:1rem;">
                                    <div style="font-size:.78rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">Work Checklist</div>
                                    <div class="row g-3">
                                        <?php foreach ([
                                            'sales_check'                    => 'Sales Check',
                                            'audit_check'                    => 'Audit',
                                            'provisional_financial_statement'=> 'Provisional/FS',
                                            'projected'                      => 'Projected',
                                            'consulting'                     => 'Consulting',
                                            'nta'                            => 'NTA',
                                            'salary_certificate'             => 'Salary Cert.',
                                            'ca_certification'               => 'CA Cert.',
                                            'etds'                           => 'ETDS',
                                        ] as $f => $l): ?>
                                            <div class="col-md-3 col-6">
                                                <label class="form-label-mis"><?= $l ?></label>
                                                <input type="number" name="banking[<?= $f ?>]"
                                                       class="form-control form-control-sm"
                                                       value="<?= htmlspecialchars($detail[$f] ?? '') ?>"
                                                       min="0" placeholder="—">
                                            </div>
                                        <?php endforeach; ?>

                                        <div class="col-md-3 col-6">
                                            <label class="form-label-mis">OD (Rs.) <small class="text-muted">in lakh</small></label>
                                            <input type="number" name="banking[od]" class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars($detail['od'] ?? '') ?>"
                                                   step="0.01" min="0" placeholder="—">
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <label class="form-label-mis">Term Loan (Rs.) <small class="text-muted">in lakh</small></label>
                                            <input type="number" name="banking[term]" class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars($detail['term'] ?? '') ?>"
                                                   step="0.01" min="0" placeholder="—">
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <label class="form-label-mis">Interest Rate (%)</label>
                                            <input type="number" name="banking[interest_rate]" class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars($detail['interest_rate'] ?? '') ?>"
                                                   step="0.01" min="0" max="100" placeholder="—">
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <label class="form-label-mis">Bill Issued</label>
                                            <div class="form-check form-switch mt-2">
                                                <input class="form-check-input" type="checkbox" name="banking[bill_issued]"
                                                       value="1" id="billIssued"
                                                       <?= ($detail['bill_issued'] ?? 0) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="billIssued">Yes</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <label class="form-label-mis">Remarks</label>
                                    <textarea name="banking[remarks]" class="form-control form-control-sm"
                                              rows="2"><?= htmlspecialchars($detail['remarks'] ?? '') ?></textarea>
                                </div>
                                <div class="mt-3">
                                    <button type="submit" class="btn btn-gold btn-sm">
                                        <i class="fas fa-save me-1"></i>Save Banking Details
                                    </button>
                                </div>
                            </form>

                        </div>
                    </div>

                    <!-- ════ OTHER DEPT DETAILS ════ -->
                    <?php elseif ($detail): ?>
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-table text-warning me-2"></i><?= htmlspecialchars($task['dept_name']) ?> Details</h5>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3">
                                <?php foreach ($detail as $key => $val):
                                    if (in_array($key, ['id', 'task_id']) || $val === null || $val === '') continue;
                                    $label = ucwords(str_replace('_', ' ', $key));
                                ?>
                                    <div class="col-md-4">
                                        <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;"><?= $label ?></div>
                                        <div style="font-size:.88rem;margin-top:.2rem;"><?= htmlspecialchars($val) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Workflow History -->
                    <?php if (!empty($workflow)): ?>
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-code-branch text-warning me-2"></i>Workflow History</h5>
                        </div>
                        <div class="card-mis-body">
                            <div style="padding-left:1rem;">
                                <?php foreach ($workflow as $w): ?>
                                    <div style="position:relative;margin-bottom:1rem;padding-left:1.2rem;border-left:2px solid #f3f4f6;">
                                        <div style="position:absolute;left:-6px;top:4px;width:10px;height:10px;border-radius:50%;background:#c9a84c;border:2px solid #fff;"></div>
                                        <div style="font-size:.82rem;font-weight:600;color:#1f2937;text-transform:capitalize;">
                                            <?= htmlspecialchars(str_replace('_', ' ', $w['action'])) ?>
                                        </div>
                                        <div style="font-size:.75rem;color:#6b7280;margin-top:.1rem;">
                                            <?php if ($w['from_name']): ?>by <?= htmlspecialchars($w['from_name']) ?><?php endif; ?>
                                            <?php if ($w['to_name']): ?> → <?= htmlspecialchars($w['to_name']) ?><?php endif; ?>
                                            <?php if (!empty($w['from_dept']) && !empty($w['to_dept']) && $w['from_dept'] !== $w['to_dept']): ?>
                                                · <?= htmlspecialchars($w['from_dept']) ?> <i class="fas fa-arrow-right mx-1" style="font-size:.65rem;"></i> <?= htmlspecialchars($w['to_dept']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($w['old_status']) || !empty($w['new_status'])): ?>
                                            <div style="font-size:.75rem;color:#9ca3af;"><?= htmlspecialchars($w['old_status'] ?? '') ?><?= ($w['old_status'] && $w['new_status']) ? ' → ' : '' ?><?= htmlspecialchars($w['new_status'] ?? '') ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($w['remarks'])): ?>
                                            <div style="font-size:.73rem;color:#9ca3af;font-style:italic;margin-top:.1rem;">"<?= htmlspecialchars($w['remarks']) ?>"</div>
                                        <?php endif; ?>
                                        <div style="font-size:.7rem;color:#d1d5db;margin-top:.1rem;"><?= date('d M Y, H:i', strtotime($w['created_at'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Comments -->
                    <div class="card-mis" id="comments">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-comments text-warning me-2"></i>Comments (<?= count($comments) ?>)</h5>
                        </div>
                        <div class="card-mis-body">
                            <?php foreach ($comments as $c): ?>
                                <div class="d-flex gap-3 mb-3">
                                    <div class="avatar-circle avatar-sm flex-shrink-0"><?= strtoupper(substr($c['full_name'] ?? '?', 0, 2)) ?></div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex gap-2 align-items-center">
                                            <strong style="font-size:.85rem;"><?= htmlspecialchars($c['full_name']) ?></strong>
                                            <span style="font-size:.72rem;color:#9ca3af;"><?= date('M j, Y H:i', strtotime($c['created_at'])) ?></span>
                                        </div>
                                        <div style="font-size:.88rem;margin-top:.25rem;background:#f9fafb;padding:.6rem .9rem;border-radius:8px;"><?= nl2br(htmlspecialchars($c['comment'])) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($comments)): ?>
                                <div class="text-muted text-center py-3" style="font-size:.85rem;">No comments yet.</div>
                            <?php endif; ?>
                            <form method="POST" class="mt-3 d-flex gap-2">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="add_comment" value="1">
                                <input type="text" name="comment" class="form-control" placeholder="Add a comment…" required>
                                <button type="submit" class="btn btn-gold btn-sm flex-shrink-0">Post</button>
                            </form>
                        </div>
                    </div>

                </div><!-- end col-lg-8 -->

                <!-- ══════════════ RIGHT COLUMN ══════════════ -->
                <div class="col-lg-4">

                    <?php if ($isMyTask): ?>

                        <!-- ── Status / work update — only when NOT done ── -->
                        <?php if (!$isDone): ?>

                            <!-- Update Work Status (RETAIL) -->
                            <?php if ($task['dept_code'] === 'RETAIL' && $detail): ?>
                            <div class="card-mis mb-3" style="border-left:3px solid #f59e0b;">
                                <div class="card-mis-header">
                                    <h5><i class="fas fa-tasks text-warning me-2"></i>Update Work Status</h5>
                                </div>
                                <div class="card-mis-body">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="update_work_status" value="1">
                                        <div class="mb-3">
                                            <label class="form-label-mis">Work Status</label>
                                            <select name="work_status_id" class="form-select form-select-sm" required>
                                                <option value="">-- Select --</option>
                                                <?php foreach ($taskStatuses as $ts): ?>
                                                    <option value="<?= $ts['id'] ?>" <?= ($detail['work_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ts['status_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label-mis">Notes</label>
                                            <textarea name="work_notes" class="form-control form-control-sm" rows="2" placeholder="Add a note about your progress..."><?= htmlspecialchars($detail['notes'] ?? '') ?></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-gold w-100 btn-sm"><i class="fas fa-save me-1"></i>Save Work Status</button>
                                    </form>
                                </div>
                            </div>

                            <!-- Update Status (TAX and others) -->
                            <?php else: ?>
                            <div class="card-mis mb-3" style="border-left:3px solid #f59e0b;">
                                <div class="card-mis-header">
                                    <h5><i class="fas fa-circle-dot text-warning me-2"></i>Update Status</h5>
                                </div>
                                <div class="card-mis-body">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <div class="mb-3">
                                            <?php foreach ($taskStatuses as $ts):
                                                $isChecked = ($task['status'] ?? '') === $ts['status_name'];
                                                $sc        = $ts['color']    ?: '#9ca3af';
                                                $sbg       = $ts['bg_color'] ?: $sc . '18';
                                                $rawIco    = trim($ts['icon'] ?: 'fa-circle');
                                                $iClass    = str_starts_with($rawIco, 'fa') ? $rawIco : 'fa-' . $rawIco;
                                            ?>
                                                <label style="display:block;cursor:pointer;margin-bottom:.5rem;">
                                                    <input type="radio" name="new_status" value="<?= htmlspecialchars($ts['status_name']) ?>"
                                                           id="st_<?= $ts['id'] ?>" <?= $isChecked ? 'checked' : '' ?>
                                                           style="display:none;" class="status-radio">
                                                    <div class="status-radio-tile <?= $isChecked ? 'is-selected' : '' ?>"
                                                         data-color="<?= htmlspecialchars($sc) ?>" data-bg="<?= htmlspecialchars($sbg) ?>"
                                                         style="display:flex;align-items:center;gap:.6rem;padding:.5rem .75rem;border-radius:8px;
                                                                border:1.5px solid <?= $isChecked ? $sc : $sc . '44' ?>;
                                                                background:<?= $isChecked ? $sbg : 'transparent' ?>;transition:.15s;">
                                                        <i class="fas <?= htmlspecialchars($iClass) ?>" style="font-size:.75rem;color:<?= htmlspecialchars($sc) ?>;flex-shrink:0;width:14px;text-align:center;"></i>
                                                        <span style="font-size:.8rem;font-weight:600;color:<?= htmlspecialchars($sc) ?>;flex:1;"><?= htmlspecialchars($ts['status_name']) ?></span>
                                                        <?php if ($isChecked): ?><span style="font-size:.65rem;color:#9ca3af;font-weight:400;">current</span><?php endif; ?>
                                                    </div>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="submit" class="btn btn-gold w-100 btn-sm"><i class="fas fa-save me-1"></i>Update Status</button>
                                    </form>
                                </div>
                            </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <!-- Done badge — shown instead of status controls -->
                            <div class="card-mis mb-3" style="border-left:3px solid #10b981;">
                                <div class="card-mis-body text-center py-3">
                                    <i class="fas fa-check-circle fa-2x mb-2 d-block" style="color:#10b981;"></i>
                                    <div style="font-size:.9rem;font-weight:600;color:#10b981;">Task Completed</div>
                                    <div style="font-size:.78rem;color:#9ca3af;margin-top:.3rem;">You can still transfer this task below.</div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- ── Transfer to Staff — always visible for task owner ── -->
                        <?php if (!empty($sameBranchStaff)): ?>
                        <div class="card-mis mb-3" style="border-left:3px solid #3b82f6;">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-user-arrows me-2" style="color:#3b82f6;"></i>Transfer to Staff</h5>
                            </div>
                            <div class="card-mis-body">
                                <p style="font-size:.77rem;color:#9ca3af;margin-bottom:.75rem;">
                                    Transfer to another staff or admin in the same department and branch.
                                </p>
                                <form method="POST" onsubmit="return confirm('Transfer this task to selected staff?');">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="transfer_staff" value="1">
                                    <div class="mb-2">
                                        <label class="form-label-mis">Search & Select Staff</label>
                                        <select name="new_staff_id" id="staff_transfer_select" class="form-select form-select-sm" required>
                                            <option value="">-- Search by name --</option>
                                            <?php foreach ($sameBranchStaff as $s): ?>
                                                <option value="<?= $s['id'] ?>" data-role="<?= htmlspecialchars($s['role_name']) ?>">
                                                    <?= htmlspecialchars($s['full_name']) ?>
                                                    <?= $s['employee_id'] ? ' (' . $s['employee_id'] . ')' : '' ?>
                                                    — <?= ucfirst($s['role_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label-mis">Transfer Note / Instructions</label>
                                        <textarea name="transfer_note" class="form-control form-control-sm" rows="2"
                                            placeholder="What work has been done / what needs to be done..."></textarea>
                                    </div>
                                    <button type="submit" class="btn w-100 btn-sm"
                                        style="background:#3b82f6;color:#fff;border:none;border-radius:8px;padding:.5rem;">
                                        <i class="fas fa-exchange-alt me-1"></i>Transfer Task
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- ── Transfer to Finance Dept — always visible for task owner ── -->
                        <?php if (!empty($branchDepts)): ?>
                        <div class="card-mis mb-3" style="border-left:3px solid #8b5cf6;">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-coins me-2" style="color:#8b5cf6;"></i>Transfer to Finance</h5>
                            </div>
                            <div class="card-mis-body">
                                <p style="font-size:.77rem;color:#9ca3af;margin-bottom:.75rem;">
                                    Transfer this task to the Finance department in <strong><?= htmlspecialchars($task['branch_name'] ?? 'your branch') ?></strong>.
                                    The assigned person will be notified with your reason.
                                </p>
                                <form method="POST" onsubmit="return confirm('Transfer this task to Finance department?');">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="transfer_department" value="1">
                                    <!-- Finance is the only option — pre-select it -->
                                    <input type="hidden" name="new_dept_id" value="<?= htmlspecialchars($branchDepts[0]['id']) ?>">
                                    <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:.5rem .75rem;margin-bottom:.75rem;font-size:.82rem;color:#6d28d9;font-weight:600;">
                                        <i class="fas fa-coins me-1"></i><?= htmlspecialchars($branchDepts[0]['dept_name']) ?>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label-mis">Assign To (optional)</label>
                                        <select name="transfer_dept_staff" id="dept_staff_select" class="form-select form-select-sm">
                                            <option value="">-- Unassigned / dept admin decides --</option>
                                            <?php foreach ($branchAllStaff as $s):
                                                if ((int) $s['department_id'] !== (int) $branchDepts[0]['id']) continue; ?>
                                                <option value="<?= $s['id'] ?>">
                                                    <?= htmlspecialchars($s['full_name']) ?>
                                                    <?= $s['employee_id'] ? ' (' . $s['employee_id'] . ')' : '' ?>
                                                    — <?= ucfirst($s['role_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label-mis">Transfer Reason <span style="color:#ef4444;">*</span></label>
                                        <textarea name="dept_transfer_note" class="form-control form-control-sm" rows="3"
                                            placeholder="Explain why this task needs Finance department attention..."
                                            required></textarea>
                                        <div style="font-size:.68rem;color:#9ca3af;margin-top:.3rem;">
                                            <i class="fas fa-envelope me-1"></i>This reason will be sent to the assigned person via email and app notification.
                                        </div>
                                    </div>
                                    <button type="submit" class="btn w-100 btn-sm"
                                        style="background:#8b5cf6;color:#fff;border:none;border-radius:8px;padding:.5rem;">
                                        <i class="fas fa-coins me-1"></i>Transfer to Finance
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- Not assigned to current user -->
                        <div class="card-mis mb-3" style="border-left:3px solid #9ca3af;">
                            <div class="card-mis-body text-center py-4">
                                <i class="fas fa-eye fa-2x mb-2 d-block" style="color:#9ca3af;"></i>
                                <div style="font-size:.88rem;color:#6b7280;">
                                    This task is assigned to<br>
                                    <strong><?= htmlspecialchars($task['assigned_to_name'] ?? '—') ?></strong>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Task Meta -->
                    <div class="card-mis p-3" style="font-size:.8rem;color:#6b7280;border-left:3px solid var(--gold);">
                        <div class="mb-2"><strong>Task #:</strong> <?= htmlspecialchars($task['task_number']) ?></div>
                        <div class="mb-2"><strong>Department:</strong> <?= htmlspecialchars($task['dept_name'] ?? '—') ?></div>
                        <div class="mb-2"><strong>Branch:</strong> <?= htmlspecialchars($task['branch_name'] ?? '—') ?></div>
                        <div class="mb-2"><strong>Priority:</strong>
                            <span class="status-badge priority-<?= $task['priority'] ?>"><?= ucfirst($task['priority']) ?></span>
                        </div>
                        <div class="mb-2"><strong>Due Date:</strong>
                            <span style="<?= $isOverdue ? 'color:#ef4444;font-weight:600;' : '' ?>">
                                <?= $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : '—' ?>
                                <?= $isOverdue ? ' ⚠️' : '' ?>
                            </span>
                        </div>
                        <div class="mb-2"><strong>Created:</strong> <?= date('d M Y, H:i', strtotime($task['created_at'])) ?></div>
                        <div><strong>Updated:</strong> <?= date('d M Y, H:i', strtotime($task['updated_at'])) ?></div>
                    </div>

                </div><!-- end col-lg-4 -->
            </div><!-- end row -->
        </div>

        <?php include '../../includes/footer.php'; ?>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── TomSelect: Tax dept selects ──────────────────────────────────────────
    ['tax_file_received_by', 'tax_updated_by', 'tax_verify_by'].forEach(function (selId) {
        const el = document.getElementById(selId);
        if (el) new TomSelect('#' + selId, { placeholder: 'Search by name...', allowEmptyOption: true, maxOptions: 500 });
    });

    // ── TomSelect: Staff transfer ────────────────────────────────────────────
    const staffTransferEl = document.getElementById('staff_transfer_select');
    if (staffTransferEl) {
        new TomSelect('#staff_transfer_select', {
            placeholder: 'Search by name or ID...',
            allowEmptyOption: true,
            maxOptions: 500,
        });
    }

    // ── TomSelect: Finance dept assign-to ────────────────────────────────────
    const deptStaffEl = document.getElementById('dept_staff_select');
    if (deptStaffEl) {
        new TomSelect('#dept_staff_select', {
            placeholder: 'Search by name or ID...',
            allowEmptyOption: true,
            maxOptions: 500,
        });
    }
// TomSelect: Bank select (staff banking)
const bankSelectEl = document.getElementById('bank_select');
if (bankSelectEl) {
    new TomSelect('#bank_select', { placeholder: 'Search bank or address...', allowEmptyOption: true, maxOptions: 500 });
}
    // ── Status radio tile interaction ────────────────────────────────────────
    document.querySelectorAll('.status-radio').forEach(radio => {
        radio.addEventListener('change', function () {
            document.querySelectorAll('.status-radio-tile').forEach(tile => {
                const c = tile.dataset.color;
                tile.style.borderColor = c + '44';
                tile.style.background  = 'transparent';
                tile.classList.remove('is-selected');
            });
            const tile = this.nextElementSibling;
            tile.style.borderColor = tile.dataset.color;
            tile.style.background  = tile.dataset.bg;
            tile.classList.add('is-selected');
        });
    });

    document.querySelectorAll('.status-radio-tile').forEach(tile => {
        tile.addEventListener('click', function () {
            const radio = this.previousElementSibling;
            radio.checked = true;
            radio.dispatchEvent(new Event('change'));
        });
    });

});
</script>