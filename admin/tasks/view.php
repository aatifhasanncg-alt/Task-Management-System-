<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helper.php';

if (!function_exists('getFiscalYearId')) {
    require_once __DIR__ . '/../../config/fiscal_year_helper.php';
}
if (!function_exists('getFiscalYearId')) {
    function getFiscalYearId(PDO $db, ?string $code): ?int
    {
        if (!$code)
            return null;
        try {
            $s = $db->prepare("SELECT id FROM fiscal_years WHERE fy_code=? LIMIT 1");
            $s->execute([$code]);
            $v = $s->fetchColumn();
            return $v ? (int) $v : null;
        } catch (Exception $e) {
            return null;
        }
    }
}
if (!function_exists('syncTaskFiscalYear')) {
    function syncTaskFiscalYear(PDO $db, int $taskId): void
    {
        try {
            $db->prepare("CALL sync_task_fiscal_year(?)")->execute([$taskId]);
        } catch (Exception $e) {
        }
    }
}
if (!function_exists('fiscalYearSelect')) {
    function fiscalYearSelect(string $name, ?string $selected, array $fys, string $class = 'form-select form-select-sm', bool $required = false): string
    {
        $req = $required ? ' required' : '';
        $html = '<select name="' . htmlspecialchars($name) . '" class="' . $class . '"' . $req . ">\n";
        $html .= '    <option value="">-- Select FY --</option>' . "\n";
        foreach ($fys as $fy) {
            $isSel = ((string) $selected === (string) $fy['fy_code']);
            $sel = $isSel ? ' selected' : '';
            $star = $fy['is_current'] ? ' ★ Current' : '';
            $lbl = htmlspecialchars($fy['fy_label'] ?: $fy['fy_code']);
            $val = htmlspecialchars($fy['fy_code']);
            $style = $fy['is_current'] ? ' style="font-weight:700;color:#16a34a;"' : '';
            $html .= '    <option value="' . $val . '"' . $sel . $style . '>' . $lbl . $star . '</option>' . "\n";
        }
        $html .= '</select>';
        return $html;
    }
}

// ── Role detection ─────────────────────────────────────────────────────────────
$isAdmin = false;
$isStaff = false;
$currentRole = $_SESSION['role'] ?? $_SESSION['user']['role'] ?? '';
if (in_array($currentRole, ['admin', 'executive', 'superadmin'])) {
    $isAdmin = true;
} else {
    $isStaff = true;
}

$db = getDB();
$user = currentUser();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}

// ── Fiscal years ──────────────────────────────────────────────────────────────
$fys = [];
try {
    $fys = $db->query("SELECT id,fy_code,fy_label,is_current FROM fiscal_years WHERE is_active=1 ORDER BY fy_code DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    if (defined('FISCAL_YEARS')) {
        foreach (FISCAL_YEARS as $fyc) {
            $fys[] = ['id' => null, 'fy_code' => $fyc, 'fy_label' => $fyc, 'is_current' => (defined('FISCAL_YEAR') && $fyc === FISCAL_YEAR) ? 1 : 0];
        }
    }
}
$currentFy = '';
foreach ($fys as $fy) {
    if ($fy['is_current']) {
        $currentFy = $fy['fy_code'];
        break;
    }
}
if (!$currentFy && !empty($fys))
    $currentFy = $fys[0]['fy_code'];
if (!$currentFy && defined('FISCAL_YEAR'))
    $currentFy = FISCAL_YEAR;

// ── Admin profile ─────────────────────────────────────────────────────────────
$adminStmt = $db->prepare("SELECT * FROM users WHERE id=?");
$adminStmt->execute([$user['id']]);
$adminUser = $adminStmt->fetch();

$adminDeptStmt = $db->prepare("SELECT dept_code FROM departments WHERE id=?");
$adminDeptStmt->execute([$adminUser['department_id'] ?? 0]);
$adminDeptCode = $adminDeptStmt->fetchColumn() ?: '';

// ── Task ──────────────────────────────────────────────────────────────────────
$taskStmt = $db->prepare("
    SELECT t.*,
           a.auditor_name,
           d.dept_name, d.dept_code, d.color AS dept_color, d.icon AS dept_icon,
           b.branch_name,
           c.company_name,
           COALESCE(ts.status_name,'Pending') AS status,
           cb.full_name  AS assigned_by_name,
           asgn.full_name AS assigned_to_name,
           asgn.email     AS assigned_to_email
    FROM tasks t
    LEFT JOIN auditors    a    ON a.id   = t.auditor_id
    LEFT JOIN departments d    ON d.id   = t.department_id
    LEFT JOIN branches    b    ON b.id   = t.branch_id
    LEFT JOIN companies   c    ON c.id   = t.company_id
    LEFT JOIN task_status ts   ON ts.id  = t.status_id
    LEFT JOIN users       cb   ON cb.id  = t.created_by
    LEFT JOIN users       asgn ON asgn.id = t.assigned_to
    WHERE t.id=? AND t.is_active=1
");
$taskStmt->execute([$id]);
$task = $taskStmt->fetch();
if (!$task) {
    setFlash('error', 'Task not found.');
    header('Location: index.php');
    exit;
}

if ($isStaff && $task['assigned_to'] != $user['id']) {
    setFlash('error', 'Access denied.');
    header('Location: index.php');
    exit;
}

$isCoreAdminDept = ($adminDeptCode === 'CORE');
// Check if user has UDA assignment for the task's department
$udaEditCheck = false;
if (!$isStaff && $adminDeptCode !== $task['dept_code']) {
    $udaEditStmt = $db->prepare("
        SELECT COUNT(*) FROM user_department_assignments uda
        JOIN departments d ON d.id = uda.department_id
        WHERE uda.user_id = ?
          AND d.dept_code = ?
    ");
    $udaEditStmt->execute([$user['id'], $task['dept_code']]);
    $udaEditCheck = (int) $udaEditStmt->fetchColumn() > 0;
}

// canViewDept: can see the dept detail section (read-only or editable)
$canViewDept = $isCoreAdminDept
    || ($adminDeptCode !== '' && $adminDeptCode === $task['dept_code'])
    || ($isStaff && $task['assigned_to'] == $user['id'])
    || $udaEditCheck;  // ← UDA users can view too

// canEditDept: can fill/save the dept detail forms
$canEditDept = ($adminDeptCode !== '' && $adminDeptCode === $task['dept_code'])
    || ($isStaff && $task['assigned_to'] == $user['id'])
    || $udaEditCheck;

// ── Detail table map ──────────────────────────────────────────────────────────
// Exact column names from SQL schemas
$detailTableMap = [
    'RETAIL' => 'task_retail',
    'TAX' => 'task_tax',
    'BANK' => 'task_banking',
    'CORP' => 'task_corporate',
    'FIN' => 'task_finance',
];
$detailTable = $detailTableMap[$task['dept_code']] ?? null;
$detail = null;

if ($detailTable) {
    try {
        switch ($task['dept_code']) {
            case 'RETAIL':
                // Columns: id,task_id,company_id,firm_name,company_type_id,file_type_id,pan_vat_id,
                //          vat_client_id,return_type,fiscal_year,fiscal_year_id,no_of_audit_year,
                //          pan_no,assigned_to,finalised_by,assigned_date,audit_type_id,ecd,
                //          opening_due,work_status_id,finalisation_status_id,completed_date,
                //          tax_clearance_status_id,backup_status_id,follow_up_date,notes
                $dSt = $db->prepare("
                    SELECT tr.*,
                           ct.type_name   AS company_type_name,
                           ft.type_name   AS file_type_name,
                           pv.type_name   AS pan_vat_name,
                           vc.value       AS vat_client_value,
                           at2.type_name  AS audit_type_name,
                           ws.status_name AS work_status_name,
                           fs.status_name AS finalisation_status_name,
                           tc.status_name AS tax_clearance_status_name,
                           bs.value       AS backup_status_value,
                           au.full_name   AS retail_assigned_to_name,
                           fb.full_name   AS finalised_by_name
                    FROM task_retail tr
                    LEFT JOIN company_types ct ON ct.id = tr.company_type_id
                    LEFT JOIN file_types    ft ON ft.id = tr.file_type_id
                    LEFT JOIN pan_vat_types pv ON pv.id = tr.pan_vat_id
                    LEFT JOIN yes_no        vc ON vc.id = tr.vat_client_id
                    LEFT JOIN audit_types  at2 ON at2.id = tr.audit_type_id
                    LEFT JOIN task_status   ws ON ws.id = tr.work_status_id
                    LEFT JOIN task_status   fs ON fs.id = tr.finalisation_status_id
                    LEFT JOIN task_status   tc ON tc.id = tr.tax_clearance_status_id
                    LEFT JOIN yes_no        bs ON bs.id = tr.backup_status_id
                    LEFT JOIN users         au ON au.id = tr.assigned_to
                    LEFT JOIN users         fb ON fb.id = tr.finalised_by
                    WHERE tr.task_id=?");
                $dSt->execute([$id]);
                $detail = $dSt->fetch();
                break;

            case 'CORP':
                $dSt = $db->prepare("
                    SELECT tc.*,
                        cg.grade_name   AS grade_name,
                        ct.type_name    AS company_type_name,
                        ft.type_name    AS file_type_name,
                        pv.type_name    AS pan_vat_name,
                        vc.value        AS vat_client_value,
                        at2.type_name   AS audit_type_name,
                        fs.status_name  AS finalisation_status_name,
                        tcs.status_name AS tax_clearance_status_name,
                        bs.value        AS backup_status_value,
                        au.full_name    AS assigned_to_name,
                        fb.full_name    AS finalised_by_name,
                        fy.fy_code      AS fiscal_year,
                        fy.fy_label     AS fiscal_year_label
                    FROM task_corporate tc
                    LEFT JOIN corporate_grades cg  ON cg.id  = tc.grade_id
                    LEFT JOIN company_types    ct  ON ct.id  = tc.company_type_id
                    LEFT JOIN file_types       ft  ON ft.id  = tc.file_type_id
                    LEFT JOIN pan_vat_types    pv  ON pv.id  = tc.pan_vat_id
                    LEFT JOIN yes_no           vc  ON vc.id  = tc.vat_client_id
                    LEFT JOIN audit_types      at2 ON at2.id = tc.audit_type_id
                    LEFT JOIN task_status      fs  ON fs.id  = tc.finalisation_status_id
                    LEFT JOIN task_status      tcs ON tcs.id = tc.tax_clearance_status_id
                    LEFT JOIN yes_no           bs  ON bs.id  = tc.backup_status_id
                    LEFT JOIN users            au  ON au.id  = tc.assigned_to
                    LEFT JOIN users            fb  ON fb.id  = tc.finalised_by
                    LEFT JOIN fiscal_years     fy  ON fy.id  = tc.fiscal_year_id
                    WHERE tc.task_id = ?
                ");
                $dSt->execute([$id]);
                $detail = $dSt->fetch(PDO::FETCH_ASSOC);
                break;

            case 'TAX':
                // Columns: id,task_id,company_id,assign_date,firm_name,assigned_office_id,
                //          assigned_office_address,tax_type_id,fiscal_year,fiscal_year_id,
                //          submission_number,udin_no,business_type,pan_number,assigned_to,
                //          file_received_by,updated_by,verify_by,tax_clearance_status_id,
                //          total_amount,completed_date,remarks,notes,created_at,updated_at,follow_up_date
                $dSt = $db->prepare("
                    SELECT tt.*,
                           tot.office_name   AS assigned_office_name,
                           tot.address       AS assigned_office_default_address,
                           tyt.tax_type_name AS tax_type_name,
                           tcs.status_name   AS tax_clearance_status_name,
                           au.full_name  AS assigned_to_name,
                           fr.full_name  AS file_received_by_name,
                           ub.full_name  AS updated_by_name,
                           vb.full_name  AS verify_by_name
                    FROM task_tax tt
                    LEFT JOIN tax_office_types tot ON tot.id = tt.assigned_office_id
                    LEFT JOIN tax_type         tyt ON tyt.id = tt.tax_type_id
                    LEFT JOIN task_status      tcs ON tcs.id = tt.tax_clearance_status_id
                    LEFT JOIN users            au  ON au.id  = tt.assigned_to
                    LEFT JOIN users            fr  ON fr.id  = tt.file_received_by
                    LEFT JOIN users            ub  ON ub.id  = tt.updated_by
                    LEFT JOIN users            vb  ON vb.id  = tt.verify_by
                    WHERE tt.task_id=?");
                $dSt->execute([$id]);
                $detail = $dSt->fetch();
                break;

            case 'BANK':
                // Columns: id,task_id,company_id,assigned_date,bank_reference_id,client_category_id,
                //          ecd,completion_date,sales_check,audit_check,provisional_financial_statement,
                //          projected,consulting,nta,salary_certificate,ca_certification,etds,
                //          od,term,interest_rate,bill_issued,remarks,created_at,updated_at,
                //          fiscal_year,fiscal_year_id
                $dSt = $db->prepare("
                    SELECT tb.*,
                           br.bank_name,
                           bcc.category_name AS client_category_name,
                           c.company_name  AS cmp_name,
                           c.contact_person, c.contact_phone,
                           c.pan_number    AS company_pan,
                           ct.type_name    AS company_type_name
                    FROM task_banking tb
                    LEFT JOIN bank_references        br  ON br.id  = tb.bank_reference_id
                    LEFT JOIN bank_client_categories bcc ON bcc.id = tb.client_category_id
                    LEFT JOIN companies              c   ON c.id   = tb.company_id
                    LEFT JOIN company_types          ct  ON ct.id  = c.company_type_id
                    WHERE tb.task_id=?");
                $dSt->execute([$id]);
                $detail = $dSt->fetch(PDO::FETCH_ASSOC);
                break;

            case 'FIN':
                $dSt = $db->prepare("
                        SELECT tf.*,
                            tcs.status_name AS tax_clearance_status_name
                        FROM task_finance tf
                        LEFT JOIN task_status tcs ON tcs.id = tf.tax_clearance_status_id
                        WHERE tf.task_id=?
                    ");
                $dSt->execute([$id]);
                $detail = $dSt->fetch();
                break;
        }
    } catch (Exception $e) {
        $detail = null;
    }
}

// ── Lookups ───────────────────────────────────────────────────────────────────
$taskStatuses = $db->query("SELECT id,status_name,color,bg_color FROM task_status ORDER BY id")->fetchAll();
$yesNo = $db->query("SELECT id,value FROM yes_no ORDER BY id")->fetchAll();
$allStaff = $db->query("
    SELECT u.id,u.full_name FROM users u
    LEFT JOIN departments d ON d.id=u.department_id
    JOIN roles r ON r.id=u.role_id
    WHERE r.role_name IN ('staff','admin') AND u.is_active=1
      AND (d.dept_code IS NULL OR d.dept_code!='CORE')
    ORDER BY r.role_name,u.full_name")->fetchAll();
$allFinal = $db->query("
    SELECT u.id,u.full_name,u.employee_id,r.role_name FROM users u
    LEFT JOIN departments d ON d.id=u.department_id
    JOIN roles r ON r.id=u.role_id
    WHERE u.is_active=1
      AND (d.dept_code IS NULL OR d.dept_code!='CORE')
      AND r.role_name IN ('admin','executive')
    ORDER BY u.full_name ASC")->fetchAll();
$allDepts = $db->query("SELECT id,dept_name,dept_code FROM departments WHERE is_active=1 ORDER BY dept_name")->fetchAll();
$allBranches = $db->query("SELECT id,branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();

// ── Company data ──────────────────────────────────────────────────────────────
$companyData = null;
$companyPanRow = null;
$companyTypeVal = '';
if ($task['company_id']) {
    $cpStmt = $db->prepare("
        SELECT c.*,ct.type_name AS company_type_name,ct.id AS company_type_id_val
        FROM companies c
        LEFT JOIN company_types ct ON ct.id=c.company_type_id
        WHERE c.id=?");
    $cpStmt->execute([$task['company_id']]);
    $companyData = $cpStmt->fetch();
    $companyPanRow = $companyData ? ['pan_number' => $companyData['pan_number'], 'company_name' => $companyData['company_name']] : null;
    $companyTypeVal = $companyData['company_type_name'] ?? '';
}

$taskAssignedToId = $task['assigned_to'] ?? null;
$taskAssignedToName = $task['assigned_to_name'] ?? '—';

// ── Extra edit lookups ─────────────────────────────────────────────────────────
$taxOfficeTypes = $taxTypes = $financeServiceTypes = $allBanks = $allCats = $allAuditors = [];
$companyTypes = $fileTypes = $panVatTypes = $yesNoOpts = $auditTypes2 = $corpGrades = [];
$taxStaff = [];
if ($canEditDept) {
    try {
        $taxOfficeTypes = $db->query("SELECT id,office_name,address FROM tax_office_types ORDER BY office_name")->fetchAll();
    } catch (Exception $e) {
    }
    try {
        $taxTypes = $db->query("SELECT id,tax_type_name FROM tax_type ORDER BY id")->fetchAll();
    } catch (Exception $e) {
    }
    try {
        $allBanks = $db->query("SELECT id,bank_name,address FROM bank_references WHERE is_active=1 ORDER BY bank_name")->fetchAll();
    } catch (Exception $e) {
    }
    try {
        $allCats = $db->query("SELECT id,category_name FROM bank_client_categories ORDER BY category_name")->fetchAll();
    } catch (Exception $e) {
    }
    try {
        $allAuditors = $db->query("SELECT id,auditor_name,firm_name FROM auditors WHERE is_active=1 ORDER BY auditor_name")->fetchAll();
    } catch (Exception $e) {
    }
    try {
        $companyTypes = $db->query("SELECT id,type_name FROM company_types ORDER BY type_name")->fetchAll();
    } catch (Exception $e) {
    }
    try {
        $fileTypes = $db->query("SELECT id,type_name FROM file_types ORDER BY type_name")->fetchAll();
    } catch (Exception $e) {
    }
    try {
        $panVatTypes = $db->query("SELECT id,type_name FROM pan_vat_types ORDER BY type_name")->fetchAll();
    } catch (Exception $e) {
    }
    try {
        $yesNoOpts = $db->query("SELECT id,value FROM yes_no ORDER BY id")->fetchAll();
    } catch (Exception $e) {
    }
    try {
        $auditTypes2 = $db->query("SELECT id,type_name FROM audit_types ORDER BY type_name")->fetchAll();
    } catch (Exception $e) {
    }
    try {
        $corpGrades = $db->query("SELECT id,grade_name FROM corporate_grades WHERE is_active=1 ORDER BY grade_name")->fetchAll();
    } catch (Exception $e) {
    }
    try {
        $taxStaff = $db->query("
            SELECT u.id,u.full_name FROM users u
            JOIN departments d ON d.id=u.department_id
            JOIN roles r ON r.id=u.role_id
            WHERE d.dept_code='TAX' AND u.is_active=1 ORDER BY u.full_name")->fetchAll();
    } catch (Exception $e) {
    }
}

// ── Comments & Workflow ────────────────────────────────────────────────────────
$comments = $workflow = [];
try {
    $c2 = $db->prepare("SELECT tc.*,u.full_name FROM task_comments tc LEFT JOIN users u ON u.id=tc.user_id WHERE tc.task_id=? ORDER BY tc.created_at ASC");
    $c2->execute([$id]);
    $comments = $c2->fetchAll();
} catch (Exception $e) {
}
try {
    $w2 = $db->prepare("SELECT tw.*,u1.full_name AS from_name,u2.full_name AS to_name,d1.dept_name AS from_dept,d2.dept_name AS to_dept FROM task_workflow tw LEFT JOIN users u1 ON u1.id=tw.from_user_id LEFT JOIN users u2 ON u2.id=tw.to_user_id LEFT JOIN departments d1 ON d1.id=tw.from_dept_id LEFT JOIN departments d2 ON d2.id=tw.to_dept_id WHERE tw.task_id=? ORDER BY tw.created_at ASC");
    $w2->execute([$id]);
    $workflow = $w2->fetchAll();
} catch (Exception $e) {
}

// ── Follow-up history ─────────────────────────────────────────────────────────
$followupHistory = [];
if (in_array($task['dept_code'], ['RETAIL', 'TAX', 'CORP'])) {
    try {
        $fuStmt = $db->prepare("SELECT tf.*,u.full_name AS added_by_name FROM task_followups tf LEFT JOIN users u ON u.id=tf.created_by WHERE tf.task_id=? ORDER BY tf.created_at ASC");
        $fuStmt->execute([$id]);
        $followupHistory = $fuStmt->fetchAll();
    } catch (Exception $e) {
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function parseDate($d)
{
    if (empty($d))
        return null;
    $x = date_create($d);
    return $x ? date_format($x, 'Y-m-d') : null;
}

$currentOfficeAddr = '';
if ($detail && isset($detail['assigned_office_address']))
    $currentOfficeAddr = $detail['assigned_office_address'] ?? '';
if ($currentOfficeAddr === '' && $detail && !empty($detail['assigned_office_id'])) {
    foreach ($taxOfficeTypes as $_o) {
        if ($_o['id'] == $detail['assigned_office_id']) {
            $currentOfficeAddr = $_o['address'] ?? '';
            break;
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// POST HANDLERS
// ═════════════════════════════════════════════════════════════════════════════

// update_status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    verifyCsrf();
    $newStatus = $_POST['new_status'] ?? '';
    $valid = array_column($db->query("SELECT status_name FROM task_status")->fetchAll(), 'status_name');
    if (in_array($newStatus, $valid)) {
        $sid = $db->prepare("SELECT id FROM task_status WHERE status_name=?");
        $sid->execute([$newStatus]);
        $nsid = (int) ($sid->fetchColumn() ?: 1);
        $db->prepare("UPDATE tasks SET status_id=?,updated_at=NOW() WHERE id=?")->execute([$nsid, $id]);
        try {
            $db->prepare("INSERT INTO task_workflow(task_id,action,from_user_id,old_status,new_status)VALUES(?,?,?,?,?)")->execute([$id, 'status_changed', $user['id'], $task['status'], $newStatus]);
        } catch (Exception $e) {
        }
        logActivity("Status: {$task['task_number']} → {$newStatus}", 'tasks');
        setFlash('success', 'Status updated.');
        header("Location: view.php?id={$id}");
        exit;
    }
}

// transfer_department
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_department'])) {
    verifyCsrf();
    $newDeptId = (int) ($_POST['new_department_id'] ?? 0);
    $newAssignTo = (($_POST['transfer_assigned_to'] ?? '') !== '' ? (int) $_POST['transfer_assigned_to'] : null);
    $transferNote = trim($_POST['transfer_note'] ?? '');
    if ($newDeptId && $newDeptId !== (int) $task['department_id']) {
        $oldDeptId = (int) $task['department_id'];
        $db->prepare("UPDATE tasks SET department_id=?,assigned_to=?,updated_at=NOW() WHERE id=?")->execute([$newDeptId, $newAssignTo, $id]);
        try {
            $db->prepare("INSERT INTO task_workflow(task_id,action,from_user_id,from_dept_id,to_dept_id,to_user_id,remarks)VALUES(?,?,?,?,?,?,?)")->execute([$id, 'transferred_dept', $user['id'], $oldDeptId, $newDeptId, $newAssignTo, $transferNote ?: null]);
        } catch (Exception $e) {
        }
        logActivity("Transferred dept: {$task['task_number']}", 'tasks');
        setFlash('success', 'Task transferred.');
    } else {
        setFlash('error', 'Select a different department.');
    }
    header("Location: view.php?id={$id}");
    exit;
}

// ── save_retail ───────────────────────────────────────────────────────────────
// NOT NULL: task_id (auto), company_id (from task), firm_name, company_type_id, file_type_id, pan_vat_id, vat_client_id
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_retail']) && $canEditDept) {
    verifyCsrf();
    $r2 = $_POST['retail'] ?? [];
    $retFy = trim($r2['fiscal_year'] ?? '');
    $retFyId = getFiscalYearId($db, $retFy);

    // Save follow-up to task_followups table ONLY
    $newFuDate = trim($r2['follow_up_date'] ?? '');
    $newFuNote = trim($r2['follow_up_note'] ?? '');
    if ($newFuDate) {
        $lastFu = $db->prepare("SELECT followup_date FROM task_followups WHERE task_id=? ORDER BY created_at DESC LIMIT 1");
        $lastFu->execute([$id]);
        $lastDate = $lastFu->fetchColumn();
        if ($lastDate !== $newFuDate) {
            $db->prepare("INSERT INTO task_followups(task_id,followup_date,notes,created_by) VALUES(?,?,?,?)")
                ->execute([$id, $newFuDate, $newFuNote ?: null, $user['id']]);
        }
    }

    $p = [
        (int) $task['company_id'],
        trim($r2['firm_name'] ?? '') ?: ($task['company_name'] ?? ''),
        ($r2['company_type_id'] ?? '') !== '' ? (int) $r2['company_type_id'] : null,
        ($r2['file_type_id'] ?? '') !== '' ? (int) $r2['file_type_id'] : null,
        ($r2['pan_vat_id'] ?? '') !== '' ? (int) $r2['pan_vat_id'] : null,
        ($r2['vat_client_id'] ?? '') !== '' ? (int) $r2['vat_client_id'] : null,
        in_array($r2['return_type'] ?? '', ['N/A', 'D1', 'D2', 'D3', 'D4']) ? $r2['return_type'] : null,
        $retFy ?: null,
        $retFyId,
        (int) ($r2['no_of_audit_year'] ?? 1),
        trim($r2['pan_no'] ?? '') ?: null,
        ($r2['assigned_to'] ?? '') !== '' ? (int) $r2['assigned_to'] : null,
        ($r2['finalised_by'] ?? '') !== '' ? (int) $r2['finalised_by'] : null,
        $r2['assigned_date'] ?: null,
        ($r2['audit_type_id'] ?? '') !== '' ? (int) $r2['audit_type_id'] : null,
        $r2['ecd'] ?: null,
        ($r2['opening_due'] ?? '') !== '' ? (float) $r2['opening_due'] : 0,
        ($r2['work_status_id'] ?? '') !== '' ? (int) $r2['work_status_id'] : null,
        ($r2['finalisation_status_id'] ?? '') !== '' ? (int) $r2['finalisation_status_id'] : null,
        $r2['completed_date'] ?: null,
        ($r2['tax_clearance_status_id'] ?? '') !== '' ? (int) $r2['tax_clearance_status_id'] : null,
        ($r2['backup_status_id'] ?? '') !== '' ? (int) $r2['backup_status_id'] : null,
        trim($r2['notes'] ?? '') ?: null,
    ];

    $ex = $db->prepare("SELECT id FROM task_retail WHERE task_id=?");
    $ex->execute([$id]);
    if ($ex->fetch()) {
        $db->prepare("UPDATE task_retail SET
            company_id=?,firm_name=?,company_type_id=?,file_type_id=?,pan_vat_id=?,vat_client_id=?,
            return_type=?,fiscal_year=?,fiscal_year_id=?,no_of_audit_year=?,pan_no=?,
            assigned_to=?,finalised_by=?,assigned_date=?,audit_type_id=?,ecd=?,opening_due=?,
            work_status_id=?,finalisation_status_id=?,completed_date=?,tax_clearance_status_id=?,
            backup_status_id=?,notes=?
            WHERE task_id=?")->execute(array_merge($p, [$id]));
    } else {
        $db->prepare("INSERT INTO task_retail(
            task_id,company_id,firm_name,company_type_id,file_type_id,pan_vat_id,vat_client_id,
            return_type,fiscal_year,fiscal_year_id,no_of_audit_year,pan_no,
            assigned_to,finalised_by,assigned_date,audit_type_id,ecd,opening_due,
            work_status_id,finalisation_status_id,completed_date,tax_clearance_status_id,
            backup_status_id,notes)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$id], $p));
    }
    if (!empty($r2['work_status_id']))
        $db->prepare("UPDATE tasks SET status_id=?,updated_at=NOW() WHERE id=?")->execute([(int) $r2['work_status_id'], $id]);
    syncTaskFiscalYear($db, $id);
    logActivity("Retail saved: {$task['task_number']}", 'tasks');
    setFlash('success', 'Retail details saved.');
    header("Location: view.php?id={$id}");
    exit;
}

// ── save_tax ──────────────────────────────────────────────────────────────────
// NOT NULL: task_id (auto), company_id (from task). All other columns nullable.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tax']) && $canEditDept) {
    verifyCsrf();
    $t2 = $_POST['tax'] ?? [];
    $taxFy = trim($t2['fiscal_year'] ?? '');
    $taxFyId = getFiscalYearId($db, $taxFy);

    // Track follow-up history
    $newFuDate = trim($t2['follow_up_date'] ?? '');
    $newFuNote = trim($t2['follow_up_note'] ?? '');
    if ($newFuDate) {
        $lastFu = $db->prepare("SELECT followup_date FROM task_followups WHERE task_id=? ORDER BY created_at DESC LIMIT 1");
        $lastFu->execute([$id]);
        $lastDate = $lastFu->fetchColumn();
        if ($lastDate !== $newFuDate) {
            $db->prepare("INSERT INTO task_followups(task_id,followup_date,notes,created_by)VALUES(?,?,?,?)")->execute([$id, $newFuDate, $newFuNote ?: null, $user['id']]);
        }
    }

    // Exact columns: company_id,firm_name,assigned_office_id,assigned_office_address,
    //                tax_type_id,fiscal_year,fiscal_year_id,submission_number,udin_no,
    //                business_type,pan_number,assigned_to,file_received_by,updated_by,
    //                verify_by,tax_clearance_status_id,total_amount,completed_date,
    //                remarks,notes,follow_up_date
    $p = [
        (int) $task['company_id'],                                               // company_id NOT NULL
        trim($t2['firm_name'] ?? '') ?: ($task['company_name'] ?? ''),              // firm_name nullable
        ($t2['assigned_office_id'] ?? '') !== '' ? (int) $t2['assigned_office_id'] : null,
        trim($t2['assigned_office_address'] ?? '') ?: null,
        ($t2['tax_type_id'] ?? '') !== '' ? (int) $t2['tax_type_id'] : null,
        $taxFy ?: null,
        $taxFyId,
        trim($t2['submission_number'] ?? '') ?: null,
        trim($t2['udin_no'] ?? '') ?: null,
        trim($t2['business_type'] ?? '') ?: null,
        trim($t2['pan_number'] ?? '') ?: null,
        ($t2['assigned_to'] ?? '') !== '' ? (int) $t2['assigned_to'] : null,
        ($t2['file_received_by'] ?? '') !== '' ? (int) $t2['file_received_by'] : null,
        ($t2['updated_by'] ?? '') !== '' ? (int) $t2['updated_by'] : null,
        ($t2['verify_by'] ?? '') !== '' ? (int) $t2['verify_by'] : null,
        ($t2['tax_clearance_status_id'] ?? '') !== '' ? (int) $t2['tax_clearance_status_id'] : null,
        ($t2['total_amount'] ?? '') !== '' ? (float) $t2['total_amount'] : 0,           // DEFAULT 0
        $t2['completed_date'] ?: null,
        trim($t2['remarks'] ?? '') ?: null,
        trim($t2['notes'] ?? '') ?: null,
    ];

    $ex = $db->prepare("SELECT id FROM task_tax WHERE task_id=?");
    $ex->execute([$id]);
    if ($ex->fetch()) {
        $db->prepare("UPDATE task_tax SET
            company_id=?,firm_name=?,assigned_office_id=?,assigned_office_address=?,
            tax_type_id=?,fiscal_year=?,fiscal_year_id=?,submission_number=?,udin_no=?,
            business_type=?,pan_number=?,assigned_to=?,file_received_by=?,updated_by=?,
            verify_by=?,tax_clearance_status_id=?,total_amount=?,completed_date=?,
            remarks=?,notes=?
            WHERE task_id=?")->execute(array_merge($p, [$id]));
    } else {
        $db->prepare("INSERT INTO task_tax(
            task_id,company_id,firm_name,assigned_office_id,assigned_office_address,
            tax_type_id,fiscal_year,fiscal_year_id,submission_number,udin_no,
            business_type,pan_number,assigned_to,file_received_by,updated_by,
            verify_by,tax_clearance_status_id,total_amount,completed_date,
            remarks,notes)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$id], $p));
    }
    if (!empty($t2['status_id']))
        $db->prepare("UPDATE tasks SET status_id=?,updated_at=NOW() WHERE id=?")->execute([(int) $t2['status_id'], $id]);
    syncTaskFiscalYear($db, $id);
    logActivity("Tax saved: {$task['task_number']}", 'tasks');
    setFlash('success', 'Tax details saved.');
    header("Location: view.php?id={$id}");
    exit;
}

// ── save_banking ──────────────────────────────────────────────────────────────
// NOT NULL: task_id (auto). All other columns nullable / have defaults.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_banking']) && $canEditDept) {
    verifyCsrf();
    $b = $_POST['banking'] ?? [];

    foreach (['assigned_date', 'ecd', 'completion_date'] as $df) {
        $b[$df] = !empty($b[$df]) ? parseDate($b[$df]) : null;
    }
    foreach (['sales_check', 'audit_check', 'provisional_financial_statement', 'projected', 'consulting', 'nta', 'salary_certificate', 'ca_certification', 'etds'] as $nf) {
        $b[$nf] = (isset($b[$nf]) && $b[$nf] !== '') ? (int) $b[$nf] : null;
    }
    $b['bill_issued'] = !empty($b['bill_issued']) ? 1 : 0;
    $bankRefId = ($b['bank_reference_id'] ?? '') !== '' ? (int) $b['bank_reference_id'] : null;
    $clientCatId = ($b['client_category_id'] ?? '') !== '' ? (int) $b['client_category_id'] : null;

    $ex = $db->prepare("SELECT id FROM task_banking WHERE task_id=?");
    $ex->execute([$task['id']]);

    // Exact columns: company_id,assigned_date,bank_reference_id,client_category_id,
    //                ecd,completion_date,sales_check,audit_check,provisional_financial_statement,
    //                projected,consulting,nta,salary_certificate,ca_certification,etds,
    //                od,term,interest_rate,bill_issued,remarks
    $vals = [
        $task['company_id'] ?? null,
        $b['assigned_date'],
        $bankRefId,
        $clientCatId,
        $b['ecd'],
        $b['completion_date'],
        $b['sales_check'],
        $b['audit_check'],
        $b['provisional_financial_statement'],
        $b['projected'],
        $b['consulting'],
        $b['nta'],
        $b['salary_certificate'],
        $b['ca_certification'],
        $b['etds'],
        ($b['od'] ?? '') !== '' ? (float) $b['od'] : null,
        ($b['term'] ?? '') !== '' ? (float) $b['term'] : null,
        ($b['interest_rate'] ?? '') !== '' ? (float) $b['interest_rate'] : null,
        $b['bill_issued'],
        trim($b['remarks'] ?? '') ?: null,
    ];

    if ($ex->fetch()) {
        $db->prepare("UPDATE task_banking SET
            company_id=?,assigned_date=?,bank_reference_id=?,client_category_id=?,
            ecd=?,completion_date=?,sales_check=?,audit_check=?,
            provisional_financial_statement=?,projected=?,consulting=?,nta=?,
            salary_certificate=?,ca_certification=?,etds=?,
            od=?,term=?,interest_rate=?,bill_issued=?,remarks=?
            WHERE task_id=?")->execute(array_merge($vals, [$task['id']]));
    } else {
        $db->prepare("INSERT INTO task_banking(
            task_id,company_id,assigned_date,bank_reference_id,client_category_id,
            ecd,completion_date,sales_check,audit_check,
            provisional_financial_statement,projected,consulting,nta,
            salary_certificate,ca_certification,etds,
            od,term,interest_rate,bill_issued,remarks)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$task['id']], $vals));
    }

    // Sync bank_summary
    if ($bankRefId && $task['branch_id']) {
        try {
            $row = $db->prepare("SELECT COUNT(*) AS total_files,SUM(ts.status_name='Done') AS completed,SUM(ts.status_name='HBC') AS hbc,SUM(ts.status_name IN('Pending','WIP','Not Started')) AS pending FROM task_banking tb JOIN tasks t ON t.id=tb.task_id JOIN task_status ts ON ts.id=t.status_id WHERE tb.bank_reference_id=? AND t.branch_id=? AND t.is_active=1");
            $row->execute([$bankRefId, $task['branch_id']]);
            $counts = $row->fetch(PDO::FETCH_ASSOC);
            $db->prepare("INSERT INTO bank_summary(bank_reference_id,branch_id,fiscal_year,total_files,completed,hbc,pending,updated_by,updated_at)VALUES(?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE total_files=VALUES(total_files),completed=VALUES(completed),hbc=VALUES(hbc),pending=VALUES(pending),updated_by=VALUES(updated_by),updated_at=NOW()")
                ->execute([$bankRefId, $task['branch_id'], $task['fiscal_year'] ?? '', (int) ($counts['total_files'] ?? 0), (int) ($counts['completed'] ?? 0), (int) ($counts['hbc'] ?? 0), (int) ($counts['pending'] ?? 0), $user['id']]);
        } catch (Exception $e) {
        }
    }
    syncTaskFiscalYear($db, $task['id']);
    setFlash('success', 'Banking details saved.');
    header("Location: view.php?id={$task['id']}");
    exit;
}

// ── save_corporate ────────────────────────────────────────────────────────────
// NOT NULL: task_id (auto), company_id (from task). All other columns nullable.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_corporate'])) {
    verifyCsrf();
    $co = $_POST['corporate'] ?? [];
    $coFyId = ($co['fiscal_year_id'] ?? '') !== '' ? (int) $co['fiscal_year_id'] : getFiscalYearId($db, $co['fiscal_year_id'] ?? '');
    // Save follow-up to task_followups ONLY
    $newFuDate = trim($co['follow_up_date'] ?? '');
    $newFuNote = trim($co['follow_up_note'] ?? '');
    if ($newFuDate) {
        $lastFu = $db->prepare("SELECT followup_date FROM task_followups WHERE task_id=? ORDER BY created_at DESC LIMIT 1");
        $lastFu->execute([$id]);
        $lastDate = $lastFu->fetchColumn();
        if ($lastDate !== $newFuDate) {
            $db->prepare("INSERT INTO task_followups(task_id,followup_date,notes,created_by) VALUES(?,?,?,?)")
                ->execute([$id, $newFuDate, $newFuNote ?: null, $user['id']]);
        }
    }
    $p = [
        $task['company_id'],                                                                      // company_id
        ($co['company_type_id'] ?? '') !== '' ? (int) $co['company_type_id'] : null,               // company_type_id
        ($co['file_type_id'] ?? '') !== '' ? (int) $co['file_type_id'] : null,               // file_type_id
        ($co['pan_vat_id'] ?? '') !== '' ? (int) $co['pan_vat_id'] : null,               // pan_vat_id
        ($co['vat_client_id'] ?? '') !== '' ? (int) $co['vat_client_id'] : null,               // vat_client_id
        in_array($co['return_type'] ?? '', ['N/A', 'D1', 'D2', 'D3', 'D4']) ? $co['return_type'] : null,                                                               // return_type
        trim($co['firm_name'] ?? '') ?: ($task['company_name'] ?? ''),                            // firm_name
        trim($co['pan_no'] ?? '') ?: ($companyData['pan_number'] ?? null),                     // pan_no
        ($co['grade_id'] ?? '') !== '' ? (int) $co['grade_id'] : null,                            // grade_id
        ($co['assigned_to'] ?? '') !== '' ? (int) $co['assigned_to'] : $taskAssignedToId,          // assigned_to
        ($co['finalised_by'] ?? '') !== '' ? (int) $co['finalised_by'] : null,                    // finalised_by
        $co['completed_date'] ?: null,                                                            // completed_date
        trim($co['remarks'] ?? '') ?: null,                                                       // remarks
        $coFyId,                                                                                  // fiscal_year_id
        (int) ($co['no_of_audit_year'] ?? 1),                                                     // no_of_audit_year
        ($co['audit_type_id'] ?? '') !== '' ? (int) $co['audit_type_id'] : null,                  // audit_type_id
        $co['ecd'] ?: null,                                                                       // ecd
        ($co['opening_due'] ?? '') !== '' ? (float) $co['opening_due'] : 0,                       // opening_due
        ($co['finalisation_status_id'] ?? '') !== '' ? (int) $co['finalisation_status_id'] : null, // finalisation_status_id
        ($co['tax_clearance_status_id'] ?? '') !== '' ? (int) $co['tax_clearance_status_id'] : null, // tax_clearance_status_id
        ($co['backup_status_id'] ?? '') !== '' ? (int) $co['backup_status_id'] : null,             // backup_status_id
        trim($co['notes'] ?? '') ?: null,                                                         // notes
    ];

    try {
        $ex = $db->prepare("SELECT id FROM task_corporate WHERE task_id = ?");
        $ex->execute([$id]);
        if ($ex->fetch()) {
            $db->prepare("
                UPDATE task_corporate SET
                    company_id=?, company_type_id=?, file_type_id=?, pan_vat_id=?,
                    vat_client_id=?, return_type=?, firm_name=?, pan_no=?, grade_id=?,
                    assigned_to=?, finalised_by=?, completed_date=?, 
                    remarks=?, fiscal_year_id=?, no_of_audit_year=?, audit_type_id=?,
                    ecd=?, opening_due=?, finalisation_status_id=?,
                    tax_clearance_status_id=?, backup_status_id=?,
                     notes=?
                WHERE task_id=?
            ")->execute(array_merge($p, [$id]));
        } else {
            $db->prepare("
                INSERT INTO task_corporate(
                    task_id, company_id, company_type_id, file_type_id, pan_vat_id,
                    vat_client_id, return_type, firm_name, pan_no, grade_id,
                    assigned_to, finalised_by, completed_date, 
                    remarks, fiscal_year_id, no_of_audit_year, audit_type_id,
                    ecd, opening_due, finalisation_status_id,
                    tax_clearance_status_id, backup_status_id,
                     notes
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute(array_merge([$id], $p));
        }
        syncTaskFiscalYear($db, $id);
        logActivity("Corporate saved: {$task['task_number']}", 'tasks');
        setFlash('success', 'Corporate details saved.');
    } catch (Exception $e) {
        setFlash('error', 'Could not save: ' . $e->getMessage());
    }
    header("Location: view.php?id={$id}");
    exit;
}

// ── save_finance ──────────────────────────────────────────────────────────────
// NOT NULL: task_id (auto), company_id (from task). due_amount is GENERATED (do not insert).
// All other columns nullable / have defaults.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_finance']) && $canEditDept) {
    verifyCsrf();
    $f = $_POST['finance'] ?? [];
    $finFy = trim($f['fiscal_year'] ?? '');
    $finFyId = getFiscalYearId($db, $finFy);

    // Ensure fiscal_year_id column exists
    try {
        $db->query("SELECT fiscal_year_id FROM task_finance LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE task_finance ADD COLUMN fiscal_year_id INT NULL AFTER fiscal_year");
        } catch (Exception $e2) {
        }
    }

    // Exact columns: company_id,fiscal_year,fiscal_year_id,total_amount,paid_amount,
    //                payment_date,payment_method,tax_clearance_status_id,tax_clearance_date,
    //                payment_status_id,is_completed,remarks
    // NOTE: due_amount is GENERATED ALWAYS — NEVER include in INSERT/UPDATE
    $p = [
        !empty($task['company_id']) ? (int) $task['company_id'] : null,
        $finFy ?: null,
        $finFyId,
        ($f['total_amount'] ?? '') !== '' ? (float) $f['total_amount'] : 0,
        ($f['paid_amount'] ?? '') !== '' ? (float) $f['paid_amount'] : 0,
        $f['payment_date'] ?: null,
        trim($f['payment_method'] ?? '') ?: null,
        ($f['tax_clearance_status_id'] ?? '') !== '' ? (int) $f['tax_clearance_status_id'] : null,
        $f['tax_clearance_date'] ?: null,

        // ✅ ENUM FIX
        $f['payment_status_id'] ?: null,

        isset($f['is_completed']) ? 1 : 0,
        trim($f['remarks'] ?? '') ?: null,
    ];

    $ex = $db->prepare("SELECT id FROM task_finance WHERE task_id=?");
    $ex->execute([$id]);
    if ($ex->fetch()) {
        $db->prepare("UPDATE task_finance SET
    company_id=?,fiscal_year=?,fiscal_year_id=?,total_amount=?,paid_amount=?,
    payment_date=?,payment_method=?,tax_clearance_status_id=?,tax_clearance_date=?,
    payment_status_id=?,is_completed=?,remarks=?
    WHERE task_id=?")->execute(array_merge($p, [$id]));
    } else {
        $db->prepare("INSERT INTO task_finance(
    task_id,company_id,fiscal_year,fiscal_year_id,total_amount,paid_amount,
    payment_date,payment_method,tax_clearance_status_id,tax_clearance_date,
    payment_status_id,is_completed,remarks)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$id], $p));
    }
    if (isset($f['is_completed'])) {
        $did = $db->query("SELECT id FROM task_status WHERE status_name='Done'")->fetchColumn();
        if ($did)
            $db->prepare("UPDATE tasks SET status_id=?,updated_at=NOW() WHERE id=?")->execute([$did, $id]);
    }
    syncTaskFiscalYear($db, $id);
    setFlash('success', 'Finance details saved.');
    header("Location: view.php?id={$id}");
    exit;
}
$methodLabels = [
    'cash' => 'Cash',
    'cheque' => 'Cheque',
    'bank_transfer' => 'Bank Transfer',
    'online_banking' => 'Internet Banking',
    'esewa' => 'eSewa',
    'khalti' => 'Khalti',
    'imepay' => 'IME Pay',
    'qr_payment' => 'QR Payment',
    'card' => 'Card Payment',
];

// add_comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    verifyCsrf();
    $cmt = trim($_POST['comment'] ?? '');
    if ($cmt) {
        try {
            $db->prepare("INSERT INTO task_comments(task_id,user_id,comment)VALUES(?,?,?)")->execute([$id, $user['id'], $cmt]);
        } catch (Exception $e) {
        }
        header("Location: view.php?id={$id}#comments");
        exit;
    }
}

// ── Page setup ────────────────────────────────────────────────────────────────
$pageTitle = 'Task: ' . $task['task_number'];
$sClass = 'status-' . strtolower(str_replace(' ', '-', $task['status'] ?? ''));
$deptColor = $task['dept_color'] ?? '#c9a84c';
$sidebarFile = $isAdmin ? '../../includes/sidebar_admin.php' : '../../includes/sidebar.php';

include '../../includes/header.php';
?>
<style>
    /* ── View page styles ── */
    .vw-section {
        background: #fff;
        border-radius: 14px;
        border: 1px solid #f0f0f0;
        margin-bottom: 1.5rem;
        overflow: hidden;
        box-shadow: 0 1px 4px rgba(0, 0, 0, .04);
    }

    .vw-section-header {
        padding: .9rem 1.25rem;
        border-bottom: 1px solid #f3f4f6;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #fafafa;
    }

    .vw-section-header h5 {
        margin: 0;
        font-size: .93rem;
        font-weight: 700;
    }

    .vw-section-body {
        padding: 1.25rem;
    }

    .vw-label {
        font-size: .68rem;
        font-weight: 700;
        color: #9ca3af;
        text-transform: uppercase;
        letter-spacing: .05em;
    }

    .vw-value {
        font-size: .88rem;
        margin-top: .2rem;
        color: #1f2937;
    }

    .req-star {
        color: #ef4444;
        margin-left: 2px;
    }

    .form-label-mis {
        font-size: .75rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: .3rem;
        display: block;
    }

    .followup-timeline {
        position: relative;
    }

    .followup-timeline::before {
        content: '';
        position: absolute;
        left: 11px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #fde68a;
    }
</style>

<div class="app-wrapper">
    <?php include $sidebarFile; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">
            <?= flashHtml() ?>

            <!-- Top bar -->
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm"><i
                        class="fas fa-arrow-left me-1"></i>Back</a>
                <div class="d-flex gap-2 align-items-center">
                    <?php if (!$canEditDept): ?>
                        <span
                            style="font-size:.73rem;background:#fef3c7;color:#92400e;padding:.3rem .8rem;border-radius:99px;border:1px solid #fde68a;">
                            <i class="fas fa-eye me-1"></i>View only —
                            <strong><?= htmlspecialchars($task['dept_name']) ?></strong>
                        </span>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <a href="edit.php?id=<?= $id ?>" class="btn btn-gold btn-sm"><i class="fas fa-pen me-1"></i>Edit
                            Task</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-4">
                <!-- ═══════════ LEFT COLUMN ═══════════ -->
                <div class="col-lg-8">

                    <!-- Task Info -->
                    <div class="vw-section">
                        <div class="vw-section-header">
                            <div>
                                <span
                                    class="task-number d-block mb-1"><?= htmlspecialchars($task['task_number']) ?></span>
                                <h5><?= htmlspecialchars($task['title']) ?></h5>
                            </div>
                            <span
                                class="status-badge <?= $sClass ?>"><?= htmlspecialchars($task['status'] ?? 'Pending') ?></span>
                        </div>
                        <div class="vw-section-body">
                            <div class="row g-3">
                                <?php foreach ([
                                    'Department' => htmlspecialchars($task['dept_name'] ?? '—'),
                                    'Branch' => htmlspecialchars($task['branch_name'] ?? '—'),
                                    'Company' => htmlspecialchars($task['company_name'] ?? '—'),
                                    'Created By' => htmlspecialchars($task['assigned_by_name'] ?? '—'),
                                    'Assigned To' => htmlspecialchars($task['assigned_to_name'] ?? 'Unassigned'),
                                    'Priority' => '<span class="status-badge priority-' . $task['priority'] . '">' . ucfirst($task['priority']) . '</span>',
                                    'Due Date' => $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : '—',
                                    'Fiscal Year' => htmlspecialchars($task['fiscal_year'] ?? '—'),
                                    'Auditor' => htmlspecialchars($task['auditor_name'] ?? '—'),
                                    'Created' => date('d M Y, H:i', strtotime($task['created_at'])),
                                ] as $label => $val): ?>
                                    <div class="col-md-4">
                                        <div class="vw-label"><?= $label ?></div>
                                        <div class="vw-value"><?= $val ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($task['description']): ?>
                                    <div class="col-12">
                                        <div class="vw-label">Description</div>
                                        <div class="vw-value"><?= nl2br(htmlspecialchars($task['description'])) ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($task['remarks']): ?>
                                    <div class="col-12">
                                        <div class="vw-label">Remarks</div>
                                        <div class="vw-value"><?= nl2br(htmlspecialchars($task['remarks'])) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ════════════════════════════════════════════
                         TAX DEPT — task_tax
                         Nullable: all except task_id, company_id
                    ════════════════════════════════════════════ -->
                    <?php if ($task['dept_code'] === 'TAX' && ($isCoreAdminDept || $canViewDept)): ?>
                        <div class="vw-section">
                            <div class="vw-section-header">
                                <h5><i class="fas fa-receipt text-warning me-2"></i>Tax Details</h5>
                                <?php if (!$canEditDept): ?><span
                                        style="font-size:.73rem;color:#92400e;background:#fef3c7;padding:.2rem .6rem;border-radius:99px;"><i
                                            class="fas fa-eye me-1"></i>View Only</span><?php endif; ?>
                            </div>
                            <div class="vw-section-body">

                                <?php if ($detail): ?>
                                    <!-- View block -->
                                    <div class="row g-3 mb-4">
                                        <?php
                                        $offDisplay = $detail['assigned_office_name'] ?? '—';
                                        $offAddr = $detail['assigned_office_address'] ?? $detail['assigned_office_default_address'] ?? '';
                                        if ($offAddr)
                                            $offDisplay .= ' <span style="color:#6b7280;font-size:.8em;">– ' . htmlspecialchars($offAddr) . '</span>';
                                        $viewFields = [
                                            'Firm Name' => htmlspecialchars($detail['firm_name'] ?? '—'),
                                            'Assigned Office' => '<span style="background:#eff6ff;color:#3b82f6;padding:.2rem .5rem;border-radius:6px;font-weight:600;">' . $offDisplay . '</span>',
                                            'Tax Type' => '<span style="background:#f0fdf4;color:#16a34a;padding:.2rem .5rem;border-radius:6px;font-weight:600;">' . htmlspecialchars($detail['tax_type_name'] ?? '—') . '</span>',
                                            'Fiscal Year' => htmlspecialchars($detail['fiscal_year'] ?? '—'),
                                            'Business Type' => htmlspecialchars($detail['business_type'] ?? '—'),
                                            'PAN Number' => htmlspecialchars($detail['pan_number'] ?? '—'),
                                            'Assigned To' => htmlspecialchars($detail['assigned_to_name'] ?? '—'),
                                            'File Received By' => htmlspecialchars($detail['file_received_by_name'] ?? '—'),
                                            'Updated By' => htmlspecialchars($detail['updated_by_name'] ?? '—'),
                                            'Verify By' => htmlspecialchars($detail['verify_by_name'] ?? '—'),
                                            'Tax Clearance' => htmlspecialchars($detail['tax_clearance_status_name'] ?? '—'),
                                            'Total Amount' => 'Rs. ' . number_format($detail['total_amount'] ?? 0, 2),
                                            'Completed Date' => ($detail['completed_date'] ?? '') ? date('d M Y', strtotime($detail['completed_date'])) : '—',
                                        ];
                                        foreach ($viewFields as $lbl => $val): ?>
                                            <div class="col-md-4">
                                                <div class="vw-label"><?= $lbl ?></div>
                                                <div class="vw-value"><?= $val ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php foreach ([['Submission Number', $detail['submission_number'] ?? '', 'https://ird.gov.np', '#3b82f6', 'IRD Portal'], ['UDIN Number', $detail['udin_no'] ?? '', 'https://udin.ican.org.np/', '#8b5cf6', 'UDIN Portal']] as [$lbl, $val, $url, $col, $btn]): ?>
                                            <div class="col-md-6">
                                                <div class="vw-label"><?= $lbl ?></div>
                                                <div class="d-flex align-items-center gap-2 mt-1">
                                                    <span
                                                        style="font-size:.88rem;font-weight:600;"><?= htmlspecialchars($val ?: '—') ?></span>
                                                    <?php if ($val): ?><a href="<?= $url ?>" target="_blank"
                                                            style="background:<?= $col ?>;color:white;padding:.2rem .6rem;border-radius:6px;font-size:.72rem;text-decoration:none;"><i
                                                                class="fas fa-external-link-alt me-1"></i><?= $btn ?></a><?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (!empty($detail['remarks'])): ?>
                                            <div class="col-12">
                                                <div class="vw-label">Remarks</div>
                                                <div class="vw-value"><?= nl2br(htmlspecialchars($detail['remarks'])) ?></div>
                                            </div><?php endif; ?>
                                        <?php if (!empty($detail['notes'])): ?>
                                            <div class="col-12">
                                                <div class="vw-label">Notes</div>
                                                <div class="vw-value"><?= nl2br(htmlspecialchars($detail['notes'])) ?></div>
                                            </div><?php endif; ?>
                                    </div>
                                    <?php if ($canEditDept): ?>
                                        <hr style="border-color:#f3f4f6;margin-bottom:1.25rem;"><?php endif; ?>
                                <?php elseif (!$canEditDept): ?>
                                    <div class="text-center py-4 text-muted"><i
                                            class="fas fa-file-circle-question fa-2x mb-2 d-block opacity-50"></i>No tax details
                                        recorded yet.</div>
                                <?php endif; ?>



                                <!-- Edit form — all fields nullable so no required except firm_name hint -->
                                <?php if ($canEditDept): ?>
                                    <?php
                                    $savedAddrSugg = [];
                                    try {
                                        $savedAddrSugg = $db->query("SELECT DISTINCT assigned_office_address FROM task_tax WHERE assigned_office_address IS NOT NULL AND assigned_office_address!='' ORDER BY assigned_office_address")->fetchAll(PDO::FETCH_COLUMN);
                                    } catch (Exception $e) {
                                    }
                                    ?>
                                    <datalist id="office_address_list"><?php foreach ($savedAddrSugg as $addr): ?>
                                            <option value="<?= htmlspecialchars($addr) ?>"><?php endforeach; ?>
                                    </datalist>
                                    <div
                                        style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                        <i class="fas fa-pen me-1"></i><?= $detail ? 'Update' : 'Add' ?> Tax Details
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="save_tax" value="1">
                                        <div class="row g-3">
                                            <!-- firm_name: nullable in DB but good practice to fill -->
                                            <div class="col-md-6">
                                                <label class="form-label-mis">Firm Name</label>
                                                <input type="text" name="tax[firm_name]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['firm_name'] ?? $task['company_name'] ?? '') ?>"
                                                    <?= $task['company_id'] ? 'readonly style="background:#f0fdf4;cursor:not-allowed;"' : '' ?>>
                                            </div>
                                            <!-- assigned_office_id: nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Assigned Office</label>
                                                <select name="tax[assigned_office_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($taxOfficeTypes as $o): ?>
                                                        <option value="<?= $o['id'] ?>" <?= ($detail['assigned_office_id'] ?? '') == $o['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($o['office_name']) ?>
                                                        </option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- assigned_office_address: nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Office Address</label>
                                                <input type="text" name="tax[assigned_office_address]"
                                                    class="form-control form-control-sm" list="office_address_list"
                                                    value="<?= htmlspecialchars($currentOfficeAddr) ?>"
                                                    placeholder="e.g. Lazimpat, Kathmandu">
                                            </div>
                                            <!-- tax_type_id: nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Tax Type</label>
                                                <select name="tax[tax_type_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($taxTypes as $tt): ?>
                                                        <option value="<?= $tt['id'] ?>" <?= ($detail['tax_type_id'] ?? '') == $tt['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($tt['tax_type_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- fiscal_year: nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Fiscal Year</label>
                                                <?= fiscalYearSelect('tax[fiscal_year]', $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy), $fys) ?>
                                            </div>
                                            <!-- business_type: nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Business Type</label>
                                                <input type="text" name="tax[business_type]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['business_type'] ?? $companyTypeVal) ?>"
                                                    <?= ($task['company_id'] && $companyTypeVal) ? 'readonly style="background:#f0fdf4;cursor:not-allowed;"' : '' ?>>
                                            </div>
                                            <!-- pan_number: nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">PAN Number</label>
                                                <?php if ($companyPanRow && $companyPanRow['pan_number']): ?>
                                                    <input type="text" name="tax[pan_number]" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['pan_number'] ?? $companyPanRow['pan_number']) ?>"
                                                        readonly style="background:#f0fdf4;font-weight:600;cursor:not-allowed;">
                                                <?php else: ?>
                                                    <input type="text" name="tax[pan_number]" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['pan_number'] ?? '') ?>"
                                                        placeholder="Enter PAN">
                                                <?php endif; ?>
                                            </div>
                                            <!-- submission_number: nullable -->
                                            <div class="col-md-6">
                                                <label class="form-label-mis">Submission Number <span
                                                        style="font-size:.65rem;color:#9ca3af;">(from IRD —
                                                        nullable)</span></label>
                                                <div class="input-group input-group-sm">
                                                    <input type="text" name="tax[submission_number]" class="form-control"
                                                        value="<?= htmlspecialchars($detail['submission_number'] ?? '') ?>">
                                                    <a href="https://taxpayerportal.ird.gov.np/taxpayer/app.html"
                                                        target="_blank" class="btn btn-outline-primary btn-sm"><i
                                                            class="fas fa-external-link-alt"></i></a>
                                                </div>
                                            </div>
                                            <!-- udin_no: nullable -->
                                            <div class="col-md-6">
                                                <label class="form-label-mis">UDIN Number <span
                                                        style="font-size:.65rem;color:#9ca3af;">(from ICAN —
                                                        nullable)</span></label>
                                                <div class="input-group input-group-sm">
                                                    <input type="text" name="tax[udin_no]" class="form-control"
                                                        value="<?= htmlspecialchars($detail['udin_no'] ?? '') ?>">
                                                    <a href="https://udin.ican.org.np/" target="_blank"
                                                        class="btn btn-outline-secondary btn-sm"><i
                                                            class="fas fa-external-link-alt"></i></a>
                                                </div>
                                            </div>
                                            <!-- total_amount: NOT NULL DEFAULT 0 -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Total Amount (Rs.) <span
                                                        class="req-star">*</span></label>
                                                <input type="number" name="tax[total_amount]"
                                                    class="form-control form-control-sm" step="0.01" min="0"
                                                    value="<?= htmlspecialchars($detail['total_amount'] ?? '0') ?>" required>
                                            </div>
                                            <!-- tax_clearance_status_id: nullable -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Tax Clearance Status</label>
                                                <select name="tax[tax_clearance_status_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>" <?= ($detail['tax_clearance_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?>
                                                        </option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- file_received_by: nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">File Received By</label>
                                                <select name="tax[file_received_by]" id="tax_file_received_by"
                                                    class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($allStaff as $s): ?>
                                                        <option value="<?= $s['id'] ?>" <?= ($detail['file_received_by'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($s['full_name']) ?>
                                                        </option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- updated_by: nullable — TAX dept only -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Updated By <span
                                                        style="font-size:.65rem;color:#8b5cf6;margin-left:.2rem;"><i
                                                            class="fas fa-filter me-1"></i>TAX only</span></label>
                                                <select name="tax[updated_by]" id="tax_updated_by"
                                                    class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($taxStaff as $s): ?>
                                                        <option value="<?= $s['id'] ?>" <?= ($detail['updated_by'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($s['full_name']) ?>
                                                        </option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- verify_by: nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Verify By</label>
                                                <select name="tax[verify_by]" id="tax_verify_by"
                                                    class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($allStaff as $s): ?>
                                                        <option value="<?= $s['id'] ?>" <?= ($detail['verify_by'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($s['full_name']) ?>
                                                        </option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- assigned_to: nullable (linked from task) -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Assigned To <span
                                                        style="font-size:.65rem;color:#3b82f6;margin-left:.2rem;"><i
                                                            class="fas fa-link me-1"></i>from task</span></label>
                                                <div class="form-control form-control-sm"
                                                    style="background:#eff6ff;color:#1d4ed8;font-weight:600;cursor:default;"><i
                                                        class="fas fa-user-circle text-primary me-1"></i><?= htmlspecialchars($taskAssignedToName) ?>
                                                </div>
                                                <input type="hidden" name="tax[assigned_to]" value="<?= $taskAssignedToId ?>">
                                            </div>
                                            <!-- completed_date: nullable -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Completed Date</label>
                                                <input type="date" name="tax[completed_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['completed_date'] ?? '') ?>">
                                            </div>
                                            <!-- follow_up_date: nullable -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Follow-up Date</label>
                                                <input type="date" name="tax[follow_up_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['follow_up_date'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Follow-up Note <span
                                                        style="font-size:.65rem;color:#9ca3af;">(optional)</span></label>
                                                <input type="text" name="tax[follow_up_note]"
                                                    class="form-control form-control-sm" placeholder="e.g. Called client…">
                                            </div>
                                            <!-- remarks,notes: nullable -->
                                            <div class="col-12"><label class="form-label-mis">Remarks</label><textarea
                                                    name="tax[remarks]" class="form-control form-control-sm"
                                                    rows="2"><?= htmlspecialchars($detail['remarks'] ?? '') ?></textarea></div>
                                            <div class="col-12"><label class="form-label-mis">Notes</label><textarea
                                                    name="tax[notes]" class="form-control form-control-sm"
                                                    rows="2"><?= htmlspecialchars($detail['notes'] ?? '') ?></textarea></div>
                                            <div class="col-12"><button type="submit" class="btn btn-gold btn-sm"><i
                                                        class="fas fa-save me-1"></i>Save Tax Details</button></div>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ════════════════════════════════════════════
                         BANKING DEPT — task_banking
                         NOT NULL: task_id only. All others nullable.
                    ════════════════════════════════════════════ -->
                    <?php elseif ($task['dept_code'] === 'BANK' && ($isCoreAdminDept || $canViewDept)): ?>
                        <div class="vw-section">
                            <div class="vw-section-header">
                                <h5><i class="fas fa-landmark text-warning me-2"></i>Banking Details</h5>
                                <?php if (!$canEditDept): ?><span
                                        style="font-size:.73rem;color:#92400e;background:#fef3c7;padding:.2rem .6rem;border-radius:99px;"><i
                                            class="fas fa-eye me-1"></i>View Only</span><?php endif; ?>
                            </div>
                            <div class="vw-section-body">
                                <!-- Client info panel -->
                                <div
                                    style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:1rem;margin-bottom:1rem;">
                                    <div
                                        style="font-size:.72rem;font-weight:700;color:#16a34a;text-transform:uppercase;margin-bottom:.5rem;">
                                        <i class="fas fa-building me-1"></i>Client Info
                                    </div>
                                    <div class="row g-2">
                                        <?php foreach (['Company' => $detail['cmp_name'] ?? ($task['company_name'] ?? '—'), 'Contact' => $detail['contact_person'] ?? '—', 'Phone' => $detail['contact_phone'] ?? '—', 'PAN' => $detail['company_pan'] ?? '—', 'Type' => $detail['company_type_name'] ?? '—', 'Bank' => $detail['bank_name'] ?? '—', 'Category' => $detail['client_category_name'] ?? '—', 'ECD' => ($detail['ecd'] ?? '') ? date('d M Y', strtotime($detail['ecd'])) : '—', 'Completion' => ($detail['completion_date'] ?? '') ? date('d M Y', strtotime($detail['completion_date'])) : '—'] as $lbl => $val): ?>
                                            <div class="col-md-4">
                                                <div class="vw-label"><?= $lbl ?></div>
                                                <div class="vw-value"><?= htmlspecialchars((string) $val) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <!-- Checklist view -->
                                <?php if ($detail && !$canEditDept): ?>
                                    <div style="background:#f9fafb;border-radius:10px;padding:1rem;">
                                        <div
                                            style="font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                            Work Checklist</div>
                                        <div class="row g-2">
                                            <?php foreach ([
                                                'Sales Check' => $detail['sales_check'] ?? '—',
                                                'Audit' => $detail['audit_check'] ?? '—',
                                                'Provisional/FS' => $detail['provisional_financial_statement'] ?? '—',
                                                'Projected' => $detail['projected'] ?? '—',
                                                'Consulting' => $detail['consulting'] ?? '—',
                                                'NTA' => $detail['nta'] ?? '—',
                                                'Salary Cert.' => $detail['salary_certificate'] ?? '—',
                                                'CA Cert.' => $detail['ca_certification'] ?? '—',
                                                'ETDS' => $detail['etds'] ?? '—',
                                                'OD (Rs.)' => $detail['od'] !== null ? 'Rs. ' . number_format($detail['od'], 2) : '—',
                                                'Term Loan (Rs.)' => $detail['term'] !== null ? 'Rs. ' . number_format($detail['term'], 2) : '—',
                                                'Interest Rate %' => $detail['interest_rate'] !== null ? $detail['interest_rate'] . '%' : '—',
                                                'Bill Issued' => ($detail['bill_issued'] ?? 0) ? '✅ Yes' : 'No',
                                            ] as $lbl => $val): ?>
                                                <div class="col-md-3 col-6">
                                                    <div class="vw-label"><?= $lbl ?></div>
                                                    <div class="vw-value"><?= htmlspecialchars((string) $val) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($detail['remarks']): ?>
                                            <div class="mt-2">
                                                <div class="vw-label">Remarks</div>
                                                <div class="vw-value"><?= nl2br(htmlspecialchars($detail['remarks'])) ?></div>
                                            </div><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!$detail && !$canEditDept): ?>
                                    <div class="text-center py-4 text-muted"><i
                                            class="fas fa-file-circle-question fa-2x mb-2 d-block opacity-50"></i>No banking
                                        details yet.</div><?php endif; ?>

                                <!-- Edit form — all banking columns nullable -->
                                <?php if ($canEditDept): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="save_banking" value="1">
                                        <div class="row g-3">
                                            <!-- bank_reference_id: nullable -->
                                            <div class="col-md-6">
                                                <label class="form-label-mis">Bank Name</label>
                                                <select name="banking[bank_reference_id]" id="bank_select"
                                                    class="form-select form-select-sm">
                                                    <option value="">-- Select Bank --</option>
                                                    <?php foreach ($allBanks as $bk): ?>
                                                        <option value="<?= $bk['id'] ?>" <?= ($detail['bank_reference_id'] ?? '') == $bk['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($bk['bank_name']) ?>
                                                            <?= !empty($bk['address']) ? (' – ' . htmlspecialchars($bk['address'])) : '' ?>
                                                        </option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- client_category_id: nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Category</label>
                                                <select name="banking[client_category_id]" class="form-select form-select-sm">
                                                    <option value="">--</option>
                                                    <?php foreach ($allCats as $cat): ?>
                                                        <option value="<?= $cat['id'] ?>" <?= ($detail['client_category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($cat['category_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- assigned_date: nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Assigned Date</label>
                                                <input type="date" name="banking[assigned_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['assigned_date'] ?? '') ?>">
                                            </div>
                                            <!-- ecd: nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">ECD</label>
                                                <input type="date" name="banking[ecd]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['ecd'] ?? '') ?>">
                                            </div>
                                            <!-- completion_date: nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Completion Date</label>
                                                <input type="date" name="banking[completion_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['completion_date'] ?? '') ?>">
                                            </div>
                                        </div>
                                        <!-- Checklist — all int nullable -->
                                        <div style="border-top:1px solid #f3f4f6;padding-top:1rem;margin-top:1rem;">
                                            <div
                                                style="font-size:.78rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                                Work Checklist <span style="font-weight:400;font-size:.7rem;color:#9ca3af;">(all
                                                    nullable — leave blank if N/A)</span></div>
                                            <div class="row g-3">
                                                <?php foreach (['sales_check' => 'Sales Check', 'audit_check' => 'Audit', 'provisional_financial_statement' => 'Provisional/FS', 'projected' => 'Projected', 'consulting' => 'Consulting', 'nta' => 'NTA', 'salary_certificate' => 'Salary Cert.', 'ca_certification' => 'CA Cert.', 'etds' => 'ETDS'] as $f => $l): ?>
                                                    <div class="col-md-3 col-6">
                                                        <label class="form-label-mis"><?= $l ?></label>
                                                        <input type="number" name="banking[<?= $f ?>]"
                                                            class="form-control form-control-sm"
                                                            value="<?= htmlspecialchars($detail[$f] ?? '') ?>" min="0"
                                                            placeholder="—">
                                                    </div>
                                                <?php endforeach; ?>
                                                <!-- od, term, interest_rate: nullable DECIMAL -->
                                                <div class="col-md-3 col-6">
                                                    <label class="form-label-mis">OD (Rs. lakh)</label>
                                                    <input type="number" name="banking[od]" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['od'] ?? '') ?>" step="0.01" min="0"
                                                        placeholder="—">
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <label class="form-label-mis">Term Loan (Rs. lakh)</label>
                                                    <input type="number" name="banking[term]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['term'] ?? '') ?>" step="0.01"
                                                        min="0" placeholder="—">
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <label class="form-label-mis">Interest Rate (%)</label>
                                                    <input type="number" name="banking[interest_rate]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['interest_rate'] ?? '') ?>"
                                                        step="0.01" min="0" max="100" placeholder="—">
                                                </div>
                                                <!-- bill_issued: DEFAULT 0 -->
                                                <div class="col-md-3 col-6">
                                                    <label class="form-label-mis">Bill Issued</label>
                                                    <div class="form-check form-switch mt-2">
                                                        <input class="form-check-input" type="checkbox"
                                                            name="banking[bill_issued]" value="1" id="billIssued"
                                                            <?= ($detail['bill_issued'] ?? 0) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="billIssued">Yes</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- remarks: nullable -->
                                        <div class="mt-3"><label class="form-label-mis">Remarks</label><textarea
                                                name="banking[remarks]" class="form-control form-control-sm"
                                                rows="2"><?= htmlspecialchars($detail['remarks'] ?? '') ?></textarea></div>
                                        <div class="mt-3"><button type="submit" class="btn btn-gold btn-sm"><i
                                                    class="fas fa-save me-1"></i>Save Banking Details</button></div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ════════════════════════════════════════════
                         FINANCE DEPT — task_finance
                         NOT NULL: task_id, company_id
                         due_amount: GENERATED — never in INSERT/UPDATE
                    ════════════════════════════════════════════ -->
                    <?php elseif ($task['dept_code'] === 'FIN' && ($isCoreAdminDept || $canViewDept)): ?>
                        <div class="vw-section">
                            <div class="vw-section-header">
                                <h5><i class="fas fa-coins text-warning me-2"></i>Finance Details</h5>
                                <?php if (!$canEditDept): ?><span
                                        style="font-size:.73rem;color:#92400e;background:#fef3c7;padding:.2rem .6rem;border-radius:99px;"><i
                                            class="fas fa-eye me-1"></i>View Only</span><?php endif; ?>
                            </div>
                            <div class="vw-section-body">
                                <?php if ($detail): ?>
                                    <div class="row g-3 mb-4">
                                        <?php foreach ([
                                            'Fiscal Year' => htmlspecialchars($detail['fiscal_year'] ?? '—'),
                                            'Total Amount' => 'Rs. ' . number_format($detail['total_amount'] ?? 0, 2),
                                            'Paid Amount' => 'Rs. ' . number_format($detail['paid_amount'] ?? 0, 2),
                                            'Due Amount' => 'Rs. ' . number_format($detail['due_amount'] ?? 0, 2), // GENERATED
                                            'Payment Date' => ($detail['payment_date'] ?? '') ? date('d M Y', strtotime($detail['payment_date'])) : '—',

                                            'Method' => htmlspecialchars($methodLabels[$detail['payment_method']] ?? $detail['payment_method'] ?? '—'),
                                            'Payment Status' => htmlspecialchars($detail['payment_status_id'] ? ucwords(str_replace('_', ' ', $detail['payment_status_id'])) : '—'),
                                            'Tax Clearance' => htmlspecialchars($detail['tax_clearance_status_name'] ?? '—'),
                                            'TC Date' => ($detail['tax_clearance_date'] ?? '') ? date('d M Y', strtotime($detail['tax_clearance_date'])) : '—',
                                            'Completed' => ($detail['is_completed'] ?? 0) ? '✅ Yes' : 'No',
                                        ] as $lbl => $val): ?>
                                            <div class="col-md-4">
                                                <div class="vw-label"><?= $lbl ?></div>
                                                <div class="vw-value"><?= $val ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($detail['remarks']): ?>
                                            <div class="col-12">
                                                <div class="vw-label">Remarks</div>
                                                <div class="vw-value"><?= nl2br(htmlspecialchars($detail['remarks'])) ?></div>
                                            </div><?php endif; ?>
                                    </div>
                                    <?php if ($canEditDept): ?>
                                        <hr style="border-color:#f3f4f6;"><?php endif; ?>
                                <?php elseif (!$canEditDept): ?>
                                    <div class="text-center py-4 text-muted"><i
                                            class="fas fa-file-circle-question fa-2x mb-2 d-block opacity-50"></i>No finance
                                        details yet.</div>
                                <?php endif; ?>
                                <?php if ($canEditDept): ?>
                                    <div
                                        style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                        <i class="fas fa-pen me-1"></i><?= $detail ? 'Update' : 'Add' ?> Finance Details
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="save_finance" value="1">
                                        <div class="row g-3">
                                            <!-- fiscal_year: nullable -->
                                            <div class="col-md-3"><label class="form-label-mis">Fiscal
                                                    Year</label><?= fiscalYearSelect('finance[fiscal_year]', $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy), $fys) ?>
                                            </div>
                                            <!-- total_amount: DEFAULT 0 — mark required -->
                                            <div class="col-md-4"><label class="form-label-mis">Total Amount (Rs.) <span
                                                        class="req-star">*</span></label><input type="number"
                                                    name="finance[total_amount]" class="form-control form-control-sm"
                                                    step="0.01" min="0"
                                                    value="<?= htmlspecialchars($detail['total_amount'] ?? '0') ?>" required>
                                            </div>
                                            <!-- paid_amount: DEFAULT 0 — mark required -->
                                            <div class="col-md-4"><label class="form-label-mis">Paid Amount (Rs.) <span
                                                        class="req-star">*</span></label><input type="number"
                                                    name="finance[paid_amount]" class="form-control form-control-sm" step="0.01"
                                                    min="0" value="<?= htmlspecialchars($detail['paid_amount'] ?? '0') ?>"
                                                    required></div>
                                            <!-- due_amount: GENERATED — read-only display only, not posted -->
                                            <div class="col-md-4"><label class="form-label-mis">Due Amount <span
                                                        style="font-size:.65rem;color:#9ca3af;">(auto-calculated)</span></label><input
                                                    type="text" class="form-control form-control-sm"
                                                    value="Rs. <?= number_format($detail['due_amount'] ?? 0, 2) ?>" readonly
                                                    style="background:#f9fafb;"></div>
                                            <!-- payment_date: nullable -->
                                            <div class="col-md-4"><label class="form-label-mis">Payment Date</label><input
                                                    type="date" name="finance[payment_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['payment_date'] ?? '') ?>"></div>
                                            <!-- payment_method: nullable -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Payment Method</label>

                                                <select name="finance[payment_method]" class="form-select form-select-sm">
                                                    <option value="">--</option>

                                                    <?php
                                                    $methods = [
                                                        'cash' => 'Cash',
                                                        'cheque' => 'Cheque',
                                                        'bank_transfer' => 'Bank Transfer',
                                                        'online_banking' => 'Internet Banking',
                                                        'esewa' => 'eSewa',
                                                        'khalti' => 'Khalti',
                                                        'imepay' => 'IME Pay',
                                                        'qr_payment' => 'QR Payment',
                                                        'card' => 'Card Payment',
                                                    ];

                                                    foreach ($methods as $value => $label): ?>
                                                        <option value="<?= $value ?>" <?= ($detail['payment_method'] ?? '') === $value ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($label) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- payment_status_id: nullable -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Payment Status</label>

                                                <select name="finance[payment_status_id]" class="form-select form-select-sm"
                                                    required>
                                                    <option value="">--</option>

                                                    <?php
                                                    $statuses = [
                                                        'unpaid' => 'Unpaid',
                                                        'paid' => 'Paid',
                                                        'partial' => 'Partial Paid',
                                                        'due' => 'Due',
                                                        'cancelled' => 'Cancelled',
                                                        'refunded' => 'Refunded'
                                                    ];

                                                    foreach ($statuses as $key => $label): ?>
                                                        <option value="<?= $key ?>" <?= ($detail['payment_status_id'] ?? '') == $key ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($label) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- tax_clearance_status_id: nullable -->
                                            <div class="col-md-4"><label class="form-label-mis">Tax Clearance
                                                    Status</label><select name="finance[tax_clearance_status_id]"
                                                    class="form-select form-select-sm">
                                                    <option value="">--</option><?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>" <?= ($detail['tax_clearance_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?>
                                                        </option><?php endforeach; ?>
                                                </select></div>
                                            <!-- tax_clearance_date: nullable -->
                                            <div class="col-md-4"><label class="form-label-mis">Tax Clearance Date</label><input
                                                    type="date" name="finance[tax_clearance_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['tax_clearance_date'] ?? '') ?>"></div>
                                            <!-- remarks: nullable -->
                                            <div class="col-12"><label class="form-label-mis">Remarks</label><textarea
                                                    name="finance[remarks]" class="form-control form-control-sm"
                                                    rows="2"><?= htmlspecialchars($detail['remarks'] ?? '') ?></textarea></div>
                                            <!-- is_completed: DEFAULT 0 -->
                                            <div class="col-12">
                                                <div class="form-check"><input class="form-check-input" type="checkbox"
                                                        name="finance[is_completed]" value="1" id="finDone"
                                                        <?= ($detail['is_completed'] ?? 0) ? 'checked' : '' ?>><label
                                                        class="form-check-label" for="finDone" style="font-size:.85rem;">Mark as
                                                        Completed (sets task to Done)</label></div>
                                            </div>
                                            <div class="col-12"><button type="submit" class="btn btn-gold btn-sm"><i
                                                        class="fas fa-save me-1"></i>Save Finance Details</button></div>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ════════════════════════════════════════════
                         RETAIL DEPT — task_retail
                         NOT NULL: task_id, company_id, firm_name,
                                   company_type_id, file_type_id,
                                   pan_vat_id, vat_client_id
                    ════════════════════════════════════════════ -->
                    <?php elseif ($task['dept_code'] === 'RETAIL' && ($isCoreAdminDept || $canViewDept)): ?>
                        <div class="vw-section">
                            <div class="vw-section-header">
                                <h5><i class="fas fa-store text-warning me-2"></i>Retail Details</h5>
                                <?php if (!$canEditDept): ?><span
                                        style="font-size:.73rem;color:#92400e;background:#fef3c7;padding:.2rem .6rem;border-radius:99px;"><i
                                            class="fas fa-eye me-1"></i>View Only</span><?php endif; ?>
                            </div>
                            <div class="vw-section-body">
                                <?php if ($detail): ?>
                                    <div class="row g-3 mb-4">
                                        <?php foreach ([
                                            'Firm Name' => htmlspecialchars($detail['firm_name'] ?? '—'),
                                            'Company Type' => htmlspecialchars($detail['company_type_name'] ?? '—'),
                                            'File Type' => htmlspecialchars($detail['file_type_name'] ?? '—'),
                                            'PAN / VAT' => htmlspecialchars($detail['pan_vat_name'] ?? '—'),
                                            'VAT Client' => htmlspecialchars($detail['vat_client_value'] ?? '—'),
                                            'Return Type' => htmlspecialchars($detail['return_type'] ?? '—'),
                                            'Fiscal Year' => htmlspecialchars($detail['fiscal_year'] ?? '—'),
                                            'Audit Years' => htmlspecialchars($detail['no_of_audit_year'] ?? '—'),
                                            'PAN No' => htmlspecialchars($detail['pan_no'] ?? '—'),
                                            'Audit Type' => htmlspecialchars($detail['audit_type_name'] ?? '—'),
                                            'Assigned To' => htmlspecialchars($detail['retail_assigned_to_name'] ?? '—'),
                                            'Assigned Date' => ($detail['assigned_date'] ?? '') ? date('d M Y', strtotime($detail['assigned_date'])) : '—',
                                            'ECD' => ($detail['ecd'] ?? '') ? date('d M Y', strtotime($detail['ecd'])) : '—',
                                            'Opening Due' => $detail['opening_due'] !== null ? 'Rs. ' . number_format($detail['opening_due'], 2) : '—',
                                            'Finalisation' => htmlspecialchars($detail['finalisation_status_name'] ?? '—'),
                                            'Finalised By' => htmlspecialchars($detail['finalised_by_name'] ?? '—'),
                                            'Completed' => ($detail['completed_date'] ?? '') ? date('d M Y', strtotime($detail['completed_date'])) : '—',
                                            'Tax Clearance' => htmlspecialchars($detail['tax_clearance_status_name'] ?? '—'),
                                            'Backup' => htmlspecialchars($detail['backup_status_value'] ?? '—'),
                                            'Notes' => htmlspecialchars($detail['notes'] ?? '—'),
                                        ] as $lbl => $val): ?>
                                            <div class="col-md-4">
                                                <div class="vw-label"><?= $lbl ?></div>
                                                <div class="vw-value"><?= $val ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($canEditDept): ?>
                                        <hr style="border-color:#f3f4f6;"><?php endif; ?>
                                <?php elseif (!$canEditDept): ?>
                                    <div class="text-center py-4 text-muted"><i
                                            class="fas fa-file-circle-question fa-2x mb-2 d-block opacity-50"></i>No retail
                                        details yet.</div>
                                <?php endif; ?>


                                <?php if ($canEditDept):
                                    $ro = 'readonly style="background:#f0fdf4;color:#374151;font-weight:500;cursor:not-allowed;"';
                                    $roCo = $task['company_id'] ? $ro : '';
                                    $cFirmName = $detail['firm_name'] ?? $task['company_name'] ?? '';
                                    $cCompTypeId = $detail['company_type_id'] ?? ($companyData['company_type_id_val'] ?? '');
                                    $cCompType = $detail['company_type_name'] ?? ($companyData['company_type_name'] ?? '');
                                    $cReturnType = $detail['return_type'] ?? ($companyData['return_type'] ?? '');
                                    $cPanNo = $detail['pan_no'] ?? ($companyData['pan_number'] ?? '');
                                    $cPanVatId = $detail['pan_vat_id'] ?? '';
                                    $cVatClientId = $detail['vat_client_id'] ?? '';
                                    ?>
                                    <div
                                        style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                        <i class="fas fa-pen me-1"></i><?= $detail ? 'Update' : 'Add' ?> Retail Details
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="save_retail" value="1">
                                        <div class="row g-3">
                                            <!-- firm_name: NOT NULL -->
                                            <div class="col-md-6">
                                                <label class="form-label-mis">Firm Name <span
                                                        class="req-star">*</span><?php if ($task['company_id']): ?><span
                                                            style="font-size:.65rem;color:#16a34a;margin-left:.3rem;"><i
                                                                class="fas fa-link me-1"></i>from
                                                            company</span><?php endif; ?></label>
                                                <input type="text" name="retail[firm_name]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($cFirmName) ?>" <?= $roCo ?> required>
                                            </div>
                                            <!-- company_type_id: NOT NULL -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Company Type <span
                                                        class="req-star">*</span><?php if ($cCompType): ?><span
                                                            style="font-size:.65rem;color:#16a34a;margin-left:.3rem;"><i
                                                                class="fas fa-link me-1"></i>from
                                                            company</span><?php endif; ?></label>
                                                <?php if ($cCompType && $task['company_id']): ?>
                                                    <input type="text" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($cCompType) ?>" <?= $ro ?>>
                                                    <input type="hidden" name="retail[company_type_id]"
                                                        value="<?= htmlspecialchars($cCompTypeId) ?>">
                                                <?php else: ?>
                                                    <select name="retail[company_type_id]" class="form-select form-select-sm"
                                                        required>
                                                        <option value="">-- Select <span class="req-star">*</span> --</option>
                                                        <?php foreach ($companyTypes as $ct): ?>
                                                            <option value="<?= $ct['id'] ?>" <?= $cCompTypeId == $ct['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ct['type_name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                            </div>
                                            <!-- file_type_id: NOT NULL -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">File Type <span class="req-star">*</span></label>
                                                <select name="retail[file_type_id]" class="form-select form-select-sm" required>
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($fileTypes as $ft): ?>
                                                        <option value="<?= $ft['id'] ?>" <?= ($detail['file_type_id'] ?? '') == $ft['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ft['type_name']) ?>
                                                        </option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- pan_vat_id: NOT NULL -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">PAN / VAT <span class="req-star">*</span></label>
                                                <select name="retail[pan_vat_id]" class="form-select form-select-sm" required>
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($panVatTypes as $pv): ?>
                                                        <option value="<?= $pv['id'] ?>" <?= $cPanVatId == $pv['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($pv['type_name']) ?>
                                                        </option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- vat_client_id: NOT NULL -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">VAT Client <span class="req-star">*</span></label>
                                                <select name="retail[vat_client_id]" class="form-select form-select-sm"
                                                    required>
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($yesNoOpts as $yn): ?>
                                                        <option value="<?= $yn['id'] ?>" <?= $cVatClientId == $yn['id'] ? 'selected' : '' ?>><?= htmlspecialchars($yn['value']) ?></option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- return_type: ENUM nullable -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Return
                                                    Type<?php if ($cReturnType && $task['company_id']): ?><span
                                                            style="font-size:.65rem;color:#16a34a;margin-left:.3rem;"><i
                                                                class="fas fa-link me-1"></i>from
                                                            company</span><?php endif; ?></label>
                                                <?php if ($cReturnType && $task['company_id']): ?>
                                                    <input type="text" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($cReturnType) ?>" <?= $ro ?>>
                                                    <?php $validRT = in_array($cReturnType, ['N/A', 'D1', 'D2', 'D3', 'D4']) ? $cReturnType : null; ?>
                                                    <input type="hidden" name="retail[return_type]"
                                                        value="<?= htmlspecialchars($validRT ?? '') ?>">
                                                <?php else: ?>
                                                    <select name="retail[return_type]" class="form-select form-select-sm">
                                                        <option value="">-- Select --</option>
                                                        <?php foreach (['N/A', 'D1', 'D2', 'D3', 'D4'] as $rt): ?>
                                                            <option value="<?= $rt ?>" <?= ($detail['return_type'] ?? '') === $rt ? 'selected' : '' ?>><?= $rt ?>
                                                            </option><?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                            </div>
                                            <!-- audit_type_id: nullable -->
                                            <div class="col-md-3"><label class="form-label-mis">Audit Type</label><select
                                                    name="retail[audit_type_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($auditTypes2 as $at): ?>
                                                        <option value="<?= $at['id'] ?>" <?= ($detail['audit_type_id'] ?? '') == $at['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($at['type_name']) ?>
                                                        </option><?php endforeach; ?>
                                                </select></div>
                                            <!-- fiscal_year: nullable -->
                                            <div class="col-md-3"><label class="form-label-mis">Fiscal
                                                    Year</label><?= fiscalYearSelect('retail[fiscal_year]', $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy), $fys) ?>
                                            </div>
                                            <!-- no_of_audit_year: DEFAULT 1 -->
                                            <div class="col-md-3"><label class="form-label-mis">No. of Audit Years <span
                                                        class="req-star">*</span></label><input type="number"
                                                    name="retail[no_of_audit_year]" class="form-control form-control-sm" min="1"
                                                    value="<?= htmlspecialchars($detail['no_of_audit_year'] ?? '1') ?>" required
                                                    style="border-color:#f59e0b;"></div>
                                            <!-- pan_no: nullable -->
                                            <div class="col-md-3"><label class="form-label-mis">PAN
                                                    No<?php if ($cPanNo && $task['company_id']): ?><span
                                                            style="font-size:.65rem;color:#16a34a;margin-left:.3rem;"><i
                                                                class="fas fa-link me-1"></i>from
                                                            company</span><?php endif; ?></label><input type="text"
                                                    name="retail[pan_no]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($cPanNo) ?>" <?= ($cPanNo && $task['company_id']) ? $ro : '' ?>></div>
                                            <!-- assigned_to: nullable (from task) -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Assigned To <span
                                                        style="font-size:.65rem;color:#3b82f6;margin-left:.2rem;"><i
                                                            class="fas fa-link me-1"></i>from task</span></label>
                                                <div class="form-control form-control-sm"
                                                    style="background:#eff6ff;color:#1d4ed8;font-weight:600;cursor:default;"><i
                                                        class="fas fa-user-circle text-primary me-1"></i><?= htmlspecialchars($taskAssignedToName) ?>
                                                </div>
                                                <input type="hidden" name="retail[assigned_to]"
                                                    value="<?= $taskAssignedToId ?>">
                                            </div>
                                            <!-- ecd: nullable -->
                                            <div class="col-md-4"><label class="form-label-mis">ECD</label><input type="date"
                                                    name="retail[ecd]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['ecd'] ?? '') ?>"></div>
                                            <!-- opening_due: DEFAULT 0 -->
                                            <div class="col-md-4"><label class="form-label-mis">Opening Due (Rs.) <span
                                                        class="req-star">*</span></label><input type="number" step="0.01"
                                                    name="retail[opening_due]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['opening_due'] ?? '0') ?>" required>
                                            </div>
                                            <!-- finalisation_status_id: nullable -->
                                            <div class="col-md-4"><label class="form-label-mis">Finalisation
                                                    Status</label><select name="retail[finalisation_status_id]"
                                                    class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>" <?= ($detail['finalisation_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?>
                                                        </option><?php endforeach; ?>
                                                </select></div>
                                            <!-- finalised_by: nullable -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Finalised By</label>
                                                <select name="retail[finalised_by]" id="retail_finalised_by"
                                                    class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($allFinal as $s): ?>
                                                        <option value="<?= $s['id'] ?>" <?= ($detail['finalised_by'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($s['full_name']) ?>
                                                            <?= !empty($s['employee_id']) ? (' (' . $s['employee_id'] . ')') : '' ?>
                                                        </option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <!-- completed_date: nullable -->
                                            <div class="col-md-4"><label class="form-label-mis">Completed Date</label><input
                                                    type="date" name="retail[completed_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['completed_date'] ?? '') ?>"></div>
                                            <!-- tax_clearance_status_id: nullable -->
                                            <div class="col-md-4"><label class="form-label-mis">Tax Clearance
                                                    Status</label><select name="retail[tax_clearance_status_id]"
                                                    class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>" <?= ($detail['tax_clearance_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?>
                                                        </option><?php endforeach; ?>
                                                </select></div>
                                            <!-- backup_status_id: nullable -->
                                            <div class="col-md-4"><label class="form-label-mis">Backup Status</label><select
                                                    name="retail[backup_status_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option><?php foreach ($yesNoOpts as $yn): ?>
                                                        <option value="<?= $yn['id'] ?>" <?= ($detail['backup_status_id'] ?? '') == $yn['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($yn['value']) ?>
                                                        </option><?php endforeach; ?>
                                                </select></div>
                                            <!-- follow_up_date: nullable -->
                                            <div class="col-md-3"><label class="form-label-mis">Follow-up Date</label><input
                                                    type="date" name="retail[follow_up_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['follow_up_date'] ?? '') ?>"></div>
                                            <div class="col-md-4"><label class="form-label-mis">Follow-up Note <span
                                                        style="font-size:.65rem;color:#9ca3af;">(optional)</span></label><input
                                                    type="text" name="retail[follow_up_note]"
                                                    class="form-control form-control-sm" placeholder="e.g. Called client…">
                                            </div>
                                            <!-- notes: nullable -->
                                            <div class="col-12"><label class="form-label-mis">Notes</label><textarea
                                                    name="retail[notes]" class="form-control form-control-sm"
                                                    rows="2"><?= htmlspecialchars($detail['notes'] ?? '') ?></textarea></div>
                                            <div class="col-12"><button type="submit" class="btn btn-gold btn-sm"><i
                                                        class="fas fa-save me-1"></i>Save Retail Details</button></div>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ════════════════════════════════════════════
                         CORPORATE DEPT — task_corporate
                         NOT NULL: task_id, company_id
                         All other columns nullable
                    ════════════════════════════════════════════ -->
                    <?php elseif ($task['dept_code'] === 'CORP' && ($isCoreAdminDept || $canViewDept)): ?>
                        <div class="vw-section">
                            <div class="vw-section-header">
                                <h5><i class="fas fa-building text-warning me-2"></i>Corporate Details</h5>
                                <?php if (!$canEditDept): ?><span
                                        style="font-size:.73rem;color:#92400e;background:#fef3c7;padding:.2rem .6rem;border-radius:99px;"><i
                                            class="fas fa-eye me-1"></i>View Only</span><?php endif; ?>
                            </div>
                            <div class="vw-section-body">
                                <?php if ($detail && $task['dept_code'] === 'CORP'): ?>
                                    <div class="row g-3 mb-4">
                                        <?php foreach ([
                                            'Firm Name' => htmlspecialchars($detail['firm_name'] ?? $task['company_name'] ?? '—'),
                                            'PAN No' => htmlspecialchars($detail['pan_no'] ?? ($companyData['pan_number'] ?? '—')),
                                            'Grade' => htmlspecialchars($detail['grade_name'] ?? '—'),
                                            'Company Type' => htmlspecialchars($detail['company_type_name'] ?? '—'),
                                            'File Type' => htmlspecialchars($detail['file_type_name'] ?? '—'),
                                            'PAN / VAT' => htmlspecialchars($detail['pan_vat_name'] ?? '—'),
                                            'VAT Client' => htmlspecialchars($detail['vat_client_value'] ?? '—'),
                                            'Return Type' => htmlspecialchars($detail['return_type'] ?? '—'),
                                            'Fiscal Year' => htmlspecialchars($detail['fiscal_year_label'] ?: ($detail['fiscal_year'] ?? $task['fiscal_year'] ?? '—')),
                                            'No. of Audit Years' => htmlspecialchars((string) ($detail['no_of_audit_year'] ?? '—')),
                                            'Audit Type' => htmlspecialchars($detail['audit_type_name'] ?? '—'),
                                            'Assigned To' => htmlspecialchars($detail['assigned_to_name'] ?? $taskAssignedToName),
                                            'Assigned Date' => ($detail['assigned_date'] ?? '') ? date('d M Y', strtotime($detail['assigned_date'])) : '—',
                                            'ECD' => ($detail['ecd'] ?? '') ? date('d M Y', strtotime($detail['ecd'])) : '—',
                                            'Opening Due' => $detail['opening_due'] !== null ? 'Rs. ' . number_format($detail['opening_due'], 2) : '—',
                                            'Finalisation' => htmlspecialchars($detail['finalisation_status_name'] ?? '—'),
                                            'Finalised By' => htmlspecialchars($detail['finalised_by_name'] ?? '—'),
                                            'Completed Date' => ($detail['completed_date'] ?? '') ? date('d M Y', strtotime($detail['completed_date'])) : '—',
                                            'Tax Clearance' => htmlspecialchars($detail['tax_clearance_status_name'] ?? '—'),
                                            'Backup Status' => htmlspecialchars($detail['backup_status_value'] ?? '—'),
                                        ] as $lbl => $val): ?>
                                            <div class="col-md-4">
                                                <div class="vw-label"><?= $lbl ?></div>
                                                <div class="vw-value"><?= $val ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($detail['notes'] ?? ''): ?>
                                            <div class="col-12">
                                                <div class="vw-label">Notes</div>
                                                <div class="vw-value"><?= nl2br(htmlspecialchars($detail['notes'])) ?></div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($detail['remarks'] ?? ''): ?>
                                            <div class="col-12">
                                                <div class="vw-label">Remarks</div>
                                                <div class="vw-value"><?= nl2br(htmlspecialchars($detail['remarks'])) ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($canEditDept): ?>
                                        <hr style="border-color:#f3f4f6;"><?php endif; ?>

                                <?php elseif (!$canEditDept): ?>
                                    <div class="text-center py-4 text-muted"><i
                                            class="fas fa-file-circle-question fa-2x mb-2 d-block opacity-50"></i>No corporate
                                        details yet.</div>
                                <?php endif; ?>

                                <?php if ($canEditDept):
                                    $cf_firm = $detail['firm_name'] ?? $task['company_name'] ?? '';
                                    $cf_pan = $detail['pan_no'] ?? ($companyData['pan_number'] ?? '');
                                    $cf_grade = $detail['grade_id'] ?? '';
                                    $cf_fy = $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy);
                                    $cf_fb = $detail['finalised_by'] ?? '';
                                    $cf_cd = $detail['completed_date'] ?? '';
                                    $cf_rem = $detail['remarks'] ?? '';
                                    $isLinked = !empty($task['company_id']);
                                    $firmRo = $isLinked ? 'readonly style="background:#f0fdf4;color:#374151;font-weight:500;cursor:not-allowed;"' : '';
                                    $panRo = ($isLinked && !empty($companyData['pan_number'])) ? 'readonly style="background:#f0fdf4;color:#374151;font-weight:500;cursor:not-allowed;"' : '';
                                    ?>
                                    <div
                                        style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                        <i class="fas fa-pen me-1"></i><?= $detail ? 'Update' : 'Add' ?> Corporate Details
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="save_corporate" value="1">
                                        <div class="row g-3">

                                            <?php
                                            $isLinked = !empty($task['company_id']);
                                            $cf_firm = $detail['firm_name'] ?? $task['company_name'] ?? '';
                                            $cf_pan = $detail['pan_no'] ?? ($companyData['pan_number'] ?? '');
                                            $cf_grade = $detail['grade_id'] ?? '';
                                            $cf_fb = $detail['finalised_by'] ?? '';
                                            $cf_cd = $detail['completed_date'] ?? '';
                                            $cf_rem = $detail['remarks'] ?? '';
                                            $cCompTypeId = $detail['company_type_id'] ?? ($companyData['company_type_id_val'] ?? '');
                                            $cCompType = $detail['company_type_name'] ?? ($companyData['company_type_name'] ?? '');
                                            $cReturnType = $detail['return_type'] ?? ($companyData['return_type'] ?? '');
                                            $cPanVatId = $detail['pan_vat_id'] ?? '';
                                            $cVatClientId = $detail['vat_client_id'] ?? '';
                                            $firmRo = $isLinked ? 'readonly style="background:#f0fdf4;color:#374151;font-weight:500;cursor:not-allowed;"' : '';
                                            $panRo = ($isLinked && !empty($companyData['pan_number'])) ? 'readonly style="background:#f0fdf4;color:#374151;font-weight:500;cursor:not-allowed;"' : '';
                                            ?>

                                            <!-- firm_name -->
                                            <div class="col-md-6">
                                                <label class="form-label-mis">Firm Name
                                                    <?php if ($isLinked): ?><span class="badge-linked"><i
                                                                class="fas fa-link"></i> from company</span><?php endif; ?>
                                                </label>
                                                <input type="text" name="corporate[firm_name]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($cf_firm) ?>" <?= $firmRo ?>>
                                            </div>

                                            <!-- pan_no -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">PAN No
                                                    <?php if ($isLinked && !empty($companyData['pan_number'])): ?><span
                                                            class="badge-linked"><i class="fas fa-link"></i> from
                                                            company</span><?php endif; ?>
                                                </label>
                                                <input type="text" name="corporate[pan_no]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($cf_pan) ?>" <?= $panRo ?>>
                                            </div>

                                            <!-- grade_id -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Grade</label>
                                                <select name="corporate[grade_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($corpGrades as $cg): ?>
                                                        <option value="<?= $cg['id'] ?>" <?= $cf_grade == $cg['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($cg['grade_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <!-- company_type_id -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Company Type
                                                    <?php if ($cCompType && $isLinked): ?><span class="badge-linked"><i
                                                                class="fas fa-link"></i> from company</span><?php endif; ?>
                                                </label>
                                                <?php if ($cCompType && $isLinked): ?>
                                                    <input type="text" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($cCompType) ?>" <?= $firmRo ?>>
                                                    <input type="hidden" name="corporate[company_type_id]"
                                                        value="<?= htmlspecialchars((string) $cCompTypeId) ?>">
                                                <?php else: ?>
                                                    <select name="corporate[company_type_id]" class="form-select form-select-sm">
                                                        <option value="">-- Select --</option>
                                                        <?php foreach ($companyTypes as $ct): ?>
                                                            <option value="<?= $ct['id'] ?>" <?= $cCompTypeId == $ct['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($ct['type_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                            </div>

                                            <!-- file_type_id -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">File Type</label>
                                                <select name="corporate[file_type_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($fileTypes as $ft): ?>
                                                        <option value="<?= $ft['id'] ?>" <?= ($detail['file_type_id'] ?? '') == $ft['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ft['type_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <!-- pan_vat_id -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">PAN / VAT</label>
                                                <select name="corporate[pan_vat_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($panVatTypes as $pv): ?>
                                                        <option value="<?= $pv['id'] ?>" <?= $cPanVatId == $pv['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($pv['type_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <!-- vat_client_id -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">VAT Client</label>
                                                <select name="corporate[vat_client_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($yesNoOpts as $yn): ?>
                                                        <option value="<?= $yn['id'] ?>" <?= $cVatClientId == $yn['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($yn['value']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <!-- return_type -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Return Type
                                                    <?php if ($cReturnType && $isLinked): ?><span class="badge-linked"><i
                                                                class="fas fa-link"></i> from company</span><?php endif; ?>
                                                </label>
                                                <?php if ($cReturnType && $isLinked): ?>
                                                    <input type="text" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($cReturnType) ?>" <?= $firmRo ?>>
                                                    <?php $validCoRT = in_array($cReturnType, ['N/A', 'D1', 'D2', 'D3', 'D4']) ? $cReturnType : null; ?>
                                                    <input type="hidden" name="corporate[return_type]"
                                                        value="<?= htmlspecialchars($validCoRT ?? '') ?>">
                                                <?php else: ?>
                                                    <select name="corporate[return_type]" class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach (['N/A', 'D1', 'D2', 'D3', 'D4'] as $rt): ?>
                                                            <option value="<?= $rt ?>" <?= ($detail['return_type'] ?? '') === $rt ? 'selected' : '' ?>><?= $rt ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                            </div>

                                            <!-- audit_type_id -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Audit Type</label>
                                                <select name="corporate[audit_type_id]" class="form-select form-select-sm">
                                                    <option value="">--</option>
                                                    <?php foreach ($auditTypes2 as $at): ?>
                                                        <option value="<?= $at['id'] ?>" <?= ($detail['audit_type_id'] ?? '') == $at['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($at['type_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <!-- fiscal_year_id -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Fiscal Year</label>
                                                <?= fiscalYearSelect('corporate[fiscal_year_id]', $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy), $fys) ?>
                                            </div>

                                            <!-- no_of_audit_year -->
                                            <div class="col-md-3">
                                                <label class="form-label-mis">No. of Audit Years</label>
                                                <input type="number" name="corporate[no_of_audit_year]"
                                                    class="form-control form-control-sm" min="1"
                                                    value="<?= htmlspecialchars((string) ($detail['no_of_audit_year'] ?? '1')) ?>">
                                            </div>

                                            <!-- assigned_to — from task, read-only -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Assigned To <span class="badge-task"><i
                                                            class="fas fa-link"></i> from task</span></label>
                                                <div class="ro-field" style="background:#eff6ff;color:#1d4ed8;">
                                                    <i class="fas fa-user-circle" style="font-size:14px;"></i>
                                                    <?= htmlspecialchars($taskAssignedToName) ?>
                                                </div>
                                                <input type="hidden" name="corporate[assigned_to]"
                                                    value="<?= htmlspecialchars((string) ($taskAssignedToId ?? '')) ?>">
                                            </div>


                                            <!-- ecd -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">ECD</label>
                                                <input type="date" name="corporate[ecd]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['ecd'] ?? '') ?>">
                                            </div>

                                            <!-- opening_due -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Opening Due (Rs.)</label>
                                                <input type="number" step="0.01" name="corporate[opening_due]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars((string) ($detail['opening_due'] ?? '0')) ?>">
                                            </div>

                                            <!-- finalisation_status_id -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Finalisation Status</label>
                                                <select name="corporate[finalisation_status_id]"
                                                    class="form-select form-select-sm">
                                                    <option value="">--</option>
                                                    <?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>" <?= ($detail['finalisation_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <!-- finalised_by — TomSelect -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Finalised By</label>
                                                <select name="corporate[finalised_by]" id="corp_finalised_by"
                                                    class="form-select form-select-sm">
                                                    <option value="">-- Search --</option>
                                                    <?php foreach ($allFinal as $s): ?>
                                                        <option value="<?= $s['id'] ?>" <?= $cf_fb == $s['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($s['full_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <!-- completed_date -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Completed Date</label>
                                                <input type="date" name="corporate[completed_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($cf_cd) ?>">
                                            </div>

                                            <!-- tax_clearance_status_id -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Tax Clearance Status</label>
                                                <select name="corporate[tax_clearance_status_id]"
                                                    class="form-select form-select-sm">
                                                    <option value="">--</option>
                                                    <?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>" <?= ($detail['tax_clearance_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <!-- backup_status_id -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Backup Status</label>
                                                <select name="corporate[backup_status_id]" class="form-select form-select-sm">
                                                    <option value="">--</option>
                                                    <?php foreach ($yesNoOpts as $yn): ?>
                                                        <option value="<?= $yn['id'] ?>" <?= ($detail['backup_status_id'] ?? '') == $yn['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($yn['value']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <!-- follow_up_date -->
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Follow-up Date</label>
                                                <input type="date" name="corporate[follow_up_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['follow_up_date'] ?? '') ?>">
                                            </div>

                                            <!-- notes -->
                                            <div class="col-md-4"><label class="form-label-mis">Follow-up Note <span
                                                        style="font-size:.65rem;color:#9ca3af;">(optional)</span></label><input
                                                    type="text" name="corporate[follow_up_note]"
                                                    class="form-control form-control-sm" placeholder="e.g. Called client…">
                                            </div>

                                            <!-- remarks -->
                                            <div class="col-12">
                                                <label class="form-label-mis">Remarks</label>
                                                <textarea name="corporate[remarks]" class="form-control form-control-sm"
                                                    rows="2"><?= htmlspecialchars($cf_rem) ?></textarea>
                                            </div>

                                            <div class="col-12">
                                                <button type="submit" class="btn btn-gold btn-sm">
                                                    <i class="fas fa-save me-1"></i>Save Corporate Details
                                                </button>
                                            </div>

                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php elseif ($detailTable && !$detail && $canEditDept): ?>
                        <div class="vw-section" style="border-left:3px solid #f59e0b;">
                            <div class="vw-section-body text-center py-4">
                                <i class="fas fa-table fa-2x mb-2 d-block text-warning"></i>
                                <p style="font-size:.9rem;color:#6b7280;"><?= htmlspecialchars($task['dept_name']) ?>
                                    details not filled yet.</p>
                                <a href="edit.php?id=<?= $id ?>" class="btn btn-gold btn-sm"><i
                                        class="fas fa-plus me-1"></i>Add Details</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ════ Comments ════ -->
                    <div class="vw-section" id="comments">
                        <div class="vw-section-header">
                            <h5><i class="fas fa-comments text-warning me-2"></i>Comments (<?= count($comments) ?>)</h5>
                        </div>
                        <div class="vw-section-body">
                            <?php foreach ($comments as $c): ?>
                                <div class="d-flex gap-3 mb-3">
                                    <div class="avatar-circle avatar-sm flex-shrink-0">
                                        <?= strtoupper(substr($c['full_name'] ?? '?', 0, 2)) ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex gap-2 align-items-center"><strong
                                                style="font-size:.85rem;"><?= htmlspecialchars($c['full_name']) ?></strong><span
                                                style="font-size:.72rem;color:#9ca3af;"><?= date('M j, Y H:i', strtotime($c['created_at'])) ?></span>
                                        </div>
                                        <div
                                            style="font-size:.88rem;margin-top:.25rem;background:#f9fafb;padding:.6rem .9rem;border-radius:8px;">
                                            <?= nl2br(htmlspecialchars($c['comment'])) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($comments)): ?>
                                <div class="text-muted text-center py-3" style="font-size:.85rem;">No comments yet.</div>
                            <?php endif; ?>
                            <form method="POST" class="mt-3 d-flex gap-2">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="add_comment" value="1">
                                <input type="text" name="comment" class="form-control" placeholder="Add a comment…"
                                    required>
                                <button type="submit" class="btn btn-gold btn-sm flex-shrink-0">Post</button>
                            </form>
                        </div>
                    </div>

                    <!-- ════ Workflow ════ -->
                    <?php if (!empty($workflow)): ?>
                        <div class="vw-section mt-4">
                            <div class="vw-section-header">
                                <h5><i class="fas fa-history text-warning me-2"></i>Workflow History</h5>
                            </div>
                            <div class="vw-section-body">
                                <?php foreach ($workflow as $w): ?>
                                    <div class="d-flex gap-3 mb-3">
                                        <div
                                            style="width:32px;height:32px;border-radius:50%;background:#eff6ff;color:#3b82f6;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.75rem;">
                                            <i
                                                class="fas fa-<?= match ($w['action']) { 'created' => 'plus', 'assigned' => 'user-check', 'status_changed' => 'circle-dot', 'transferred_dept' => 'exchange-alt', 'transferred_staff' => 'user-arrows', 'completed' => 'check-circle', default => 'pen'} ?>"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div style="font-size:.82rem;font-weight:600;color:#1f2937;">
                                                <?= ucwords(str_replace('_', ' ', $w['action'])) ?>
                                                <?php if ($w['from_name']): ?>
                                                    by
                                                    <?= htmlspecialchars($w['from_name']) ?>         <?php endif; ?>
                                                <?php if ($w['to_name']): ?>
                                                    → <?= htmlspecialchars($w['to_name']) ?><?php endif; ?>
                                            </div>
                                            <?php if ($w['from_dept'] || $w['to_dept']): ?>
                                                <div style="font-size:.75rem;color:#8b5cf6;">
                                                    <?= htmlspecialchars($w['from_dept'] ?? '') ?>
                                                    <?= ($w['from_dept'] && $w['to_dept']) ? ' → ' : '' ?>
                                                    <?= htmlspecialchars($w['to_dept'] ?? '') ?>
                                                </div><?php endif; ?>
                                            <?php if ($w['old_status'] || $w['new_status']): ?>
                                                <div style="font-size:.75rem;color:#9ca3af;">
                                                    <?= htmlspecialchars($w['old_status'] ?? '') ?>
                                                    <?= ($w['old_status'] && $w['new_status']) ? ' → ' : '' ?>
                                                    <?= htmlspecialchars($w['new_status'] ?? '') ?>
                                                </div><?php endif; ?>
                                            <?php if ($w['remarks']): ?>
                                                <div style="font-size:.78rem;color:#6b7280;font-style:italic;">
                                                    "<?= htmlspecialchars($w['remarks']) ?>"</div><?php endif; ?>
                                            <div style="font-size:.7rem;color:#9ca3af;margin-top:.2rem;">
                                                <?= date('d M Y, H:i', strtotime($w['created_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div><!-- col-lg-8 -->

                <!-- ═══════════ RIGHT COLUMN ═══════════ -->
                <div class="col-lg-4">

                    <!-- Update Status -->
                    <div class="vw-section mb-3">
                        <div class="vw-section-header">
                            <h5><i class="fas fa-circle-dot text-warning me-2"></i>Update Status</h5>
                        </div>
                        <div class="vw-section-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="update_status" value="1">
                                <div class="mb-3">
                                    <?php foreach ($taskStatuses as $ts):
                                        $sCol = $ts['color'] ?? '#9ca3af';
                                        $sBg = $ts['bg_color'] ?? '#f3f4f6'; ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="new_status"
                                                value="<?= htmlspecialchars($ts['status_name']) ?>" id="st_<?= $ts['id'] ?>"
                                                <?= ($task['status'] ?? '') === $ts['status_name'] ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="st_<?= $ts['id'] ?>">
                                                <span
                                                    style="background:<?= $sBg ?>;color:<?= $sCol ?>;padding:.2rem .6rem;border-radius:99px;font-size:.78rem;font-weight:600;"><?= htmlspecialchars($ts['status_name']) ?></span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" class="btn btn-gold w-100 btn-sm"><i
                                        class="fas fa-save me-1"></i>Update Status</button>
                            </form>
                        </div>
                    </div>
                    <!-- Task Meta -->
                    <div class="vw-section p-3"
                        style="font-size:.8rem;color:#6b7280;border-left:3px solid var(--gold,#c9a84c);">
                        <div class="mb-2"><strong>Task #:</strong> <?= htmlspecialchars($task['task_number']) ?></div>
                        <div class="mb-2"><strong>Department:</strong>
                            <?= htmlspecialchars($task['dept_name'] ?? '—') ?>
                        </div>
                        <div class="mb-2"><strong>Branch:</strong> <?= htmlspecialchars($task['branch_name'] ?? '—') ?>
                        </div>
                        <div class="mb-2"><strong>Priority:</strong> <?= ucfirst($task['priority'] ?? '—') ?></div>
                        <div class="mb-2"><strong>Created:</strong>
                            <?= date('d M Y, H:i', strtotime($task['created_at'])) ?></div>
                        <div><strong>Updated:</strong> <?= date('d M Y, H:i', strtotime($task['updated_at'])) ?></div>
                    </div>
                    <!-- Follow-up history -->
                    <?php if (!empty($followupHistory)): ?>
                        <div
                            style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:1rem;margin-bottom:1rem;">
                            <div
                                style="font-size:.72rem;font-weight:700;color:#92400e;text-transform:uppercase;margin-bottom:.75rem;">
                                <i class="fas fa-clock-rotate-left me-1"></i>Follow-up History
                                <span
                                    style="background:#f59e0b;color:white;padding:.15rem .5rem;border-radius:99px;font-size:.68rem;margin-left:.4rem;"><?= count($followupHistory) ?>
                                    times</span>
                            </div>
                            <div class="followup-timeline">
                                <?php foreach ($followupHistory as $i => $fu): ?>
                                    <div class="d-flex gap-2 mb-3 position-relative">
                                        <div
                                            style="width:24px;height:24px;border-radius:50%;background:#f59e0b;color:white;display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;flex-shrink:0;z-index:1;">
                                            <?= $i + 1 ?>
                                        </div>
                                        <div
                                            style="background:white;border:1px solid #fde68a;border-radius:8px;padding:.5rem .75rem;flex:1;">
                                            <div style="font-size:.82rem;font-weight:700;color:#92400e;">
                                                <?= date('d M Y', strtotime($fu['followup_date'])) ?> <span
                                                    style="font-weight:400;color:#9ca3af;font-size:.72rem;">set
                                                    <?= date('d M Y, H:i', strtotime($fu['created_at'])) ?> by
                                                    <?= htmlspecialchars($fu['added_by_name']) ?></span>
                                            </div>
                                            <?php if ($fu['notes']): ?>
                                                <div style="font-size:.78rem;color:#6b7280;margin-top:.2rem;font-style:italic;">
                                                    "<?= htmlspecialchars($fu['notes']) ?>"</div><?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div><!-- col-lg-4 -->
            </div><!-- row -->
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // TomSelect — TAX fields
        ['tax_file_received_by', 'tax_updated_by', 'tax_verify_by'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) new TomSelect('#' + id, { placeholder: 'Search by name…', allowEmptyOption: true, maxOptions: 500 });
        });
        // TomSelect — Retail/Corp finalised_by
        ['retail_finalised_by', 'corp_finalised_by'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) new TomSelect('#' + id, { placeholder: 'Search by name or ID…', allowEmptyOption: true, maxOptions: 500 });
        });
        // TomSelect — bank select
        if (document.getElementById('bank_select'))
            new TomSelect('#bank_select', { placeholder: 'Search bank…', allowEmptyOption: true, maxOptions: 500 });

        // Transfer dept → filter staff
        const deptSel = document.getElementById('transfer_dept_select');
        const staffSel = document.getElementById('transfer_staff_select');
        if (deptSel && staffSel) {
            const allOpts = Array.from(staffSel.options).map(o => ({ value: o.value, text: o.text, deptCode: o.dataset.deptcode || '' }));
            deptSel.addEventListener('change', function () {
                const code = deptSel.options[deptSel.selectedIndex]?.dataset?.deptcode || '';
                staffSel.innerHTML = '<option value="">-- Select Staff --</option>';
                allOpts.forEach(o => {
                    if (!o.value) return;
                    if (!code || o.deptCode === code) {
                        const opt = document.createElement('option');
                        opt.value = o.value; opt.text = o.text; opt.dataset.deptcode = o.deptCode;
                        staffSel.appendChild(opt);
                    }
                });
            });
        }
    });
</script>
<?php include '../../includes/footer.php'; ?>