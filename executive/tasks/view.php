<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helper.php';
requireAdmin();

$db = getDB();
$user = currentUser();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php-error.log');
/* ── Fiscal years ─────────────────────────────────────────────────────────── */
$fys = getFiscalYears($db);
$currentFy = getCurrentFiscalYear($db);
if (!$currentFy) {
    $currentFy = $fys[0]['fy_code'] ?? null;
}

/* ── Current admin user ───────────────────────────────────────────────────── */
$adminStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$adminStmt->execute([$user['id']]);
$adminUser = $adminStmt->fetch();

$adminDeptStmt = $db->prepare("SELECT dept_code FROM departments WHERE id = ?");
$adminDeptStmt->execute([$adminUser['department_id'] ?? 0]);
$adminDeptCode = $adminDeptStmt->fetchColumn() ?: '';

/* ── Main task ────────────────────────────────────────────────────────────── */
$taskStmt = $db->prepare("
    SELECT t.*, a.auditor_name,
           d.dept_name, d.dept_code, d.color, d.icon AS dept_icon,
           b.branch_name,
           c.company_name,
           COALESCE(ts.status_name,'Pending') AS status,
           cb.full_name  AS assigned_by_name,
           asgn.full_name AS assigned_to_name,
           asgn.email     AS assigned_to_email
    FROM tasks t
    LEFT JOIN auditors   a  ON a.id  = t.auditor_id
    LEFT JOIN departments d  ON d.id  = t.department_id
    LEFT JOIN branches    b  ON b.id  = t.branch_id
    LEFT JOIN companies   c  ON c.id  = t.company_id
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN users       cb ON cb.id = t.created_by
    LEFT JOIN users       asgn ON asgn.id = t.assigned_to
    WHERE t.id = ? AND t.is_active = 1
");
$taskStmt->execute([$id]);
$task = $taskStmt->fetch();
if (!$task) {
    setFlash('error', 'Task not found.');
    header('Location: index.php');
    exit;
}

/* ── Department detail tables ─────────────────────────────────────────────── */
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
                    LEFT JOIN file_types ft    ON ft.id = tr.file_type_id
                    LEFT JOIN pan_vat_types pv ON pv.id = tr.pan_vat_id
                    LEFT JOIN yes_no vc        ON vc.id = tr.vat_client_id
                    LEFT JOIN audit_types at2  ON at2.id = tr.audit_type_id
                    LEFT JOIN task_status ws   ON ws.id = tr.work_status_id
                    LEFT JOIN task_status fs   ON fs.id = tr.finalisation_status_id
                    LEFT JOIN task_status tc   ON tc.id = tr.tax_clearance_status_id
                    LEFT JOIN yes_no bs        ON bs.id = tr.backup_status_id
                    LEFT JOIN users au         ON au.id = tr.assigned_to
                    LEFT JOIN users fb         ON fb.id = tr.finalised_by
                    WHERE tr.task_id = ?
                ");
                $dSt->execute([$id]);
                $detail = $dSt->fetch(PDO::FETCH_ASSOC);
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
                    LEFT JOIN tax_type tyt          ON tyt.id = tt.tax_type_id
                    LEFT JOIN task_status tcs       ON tcs.id = tt.tax_clearance_status_id
                    LEFT JOIN users au ON au.id = tt.assigned_to
                    LEFT JOIN users fr ON fr.id = tt.file_received_by
                    LEFT JOIN users ub ON ub.id = tt.updated_by
                    LEFT JOIN users vb ON vb.id = tt.verify_by
                    WHERE tt.task_id = ?
                ");
                $dSt->execute([$id]);
                $detail = $dSt->fetch(PDO::FETCH_ASSOC);
                break;

            case 'BANK':
                $dSt = $db->prepare("
                    SELECT tb.*,
                           br.bank_name,
                           bcc.category_name AS client_category_name,
                           c.company_name,
                           c.contact_person,
                           c.contact_phone,
                           c.pan_number      AS company_pan,
                           ct.type_name      AS company_type_name
                    FROM task_banking tb
                    LEFT JOIN bank_references br         ON br.id = tb.bank_reference_id
                    LEFT JOIN bank_client_categories bcc ON bcc.id = tb.client_category_id
                    LEFT JOIN companies c                ON c.id = tb.company_id
                    LEFT JOIN company_types ct           ON ct.id = c.company_type_id
                    WHERE tb.task_id = ?
                ");
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

/* ── Global lookups ───────────────────────────────────────────────────────── */
$taskStatuses = $db->query("SELECT id, status_name, color, bg_color FROM task_status ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$yesNo = $db->query("SELECT id, value FROM yes_no ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$allStaff = $db->query("
    SELECT u.id, u.full_name FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    JOIN roles r ON r.id = u.role_id
    WHERE r.role_name IN ('staff','admin') AND u.is_active = 1
      AND (d.dept_code IS NULL OR d.dept_code != 'CORE')
    ORDER BY r.role_name, u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Company data ─────────────────────────────────────────────────────────── */
$companyData = null;
$companyPanRow = null;
$companyTypeVal = '';

if ($task['company_id']) {
    $cpStmt = $db->prepare("
        SELECT c.*, ct.type_name AS company_type_name, ct.id AS company_type_id_val,
               pv.type_name AS pan_vat_name, pv.id AS pan_vat_id_val,
               yn.value AS vat_client_value, yn.id AS vat_client_id_val
        FROM companies c
        LEFT JOIN company_types ct ON ct.id = c.company_type_id
        LEFT JOIN pan_vat_types pv ON pv.type_name = IF(c.pan_number LIKE 'VAT%','VAT','PAN')
        LEFT JOIN yes_no        yn ON yn.value = 'No'
        WHERE c.id = ?
    ");
    $cpStmt->execute([$task['company_id']]);
    $companyData = $cpStmt->fetch(PDO::FETCH_ASSOC);
}
$companyPanRow = $companyData ? ['pan_number' => $companyData['pan_number'], 'company_name' => $companyData['company_name']] : null;
$companyTypeVal = $companyData['company_type_name'] ?? '';

$taskAssignedToId = $task['assigned_to'] ?? null;
$taskAssignedToName = $task['assigned_to_name'] ?? '—';

/* ── Comments & Workflow ──────────────────────────────────────────────────── */
$comments = $workflow = [];
/* ── Follow-up history ────────────────────────────────────────────────────── */
$followupHistory = [];
if (in_array($task['dept_code'], ['RETAIL', 'TAX', 'CORP'])) {
    try {
        $fuStmt = $db->prepare("
            SELECT tf.*, u.full_name AS added_by_name 
            FROM task_followups tf 
            LEFT JOIN users u ON u.id = tf.created_by 
            WHERE tf.task_id = ? 
            ORDER BY tf.created_at ASC
        ");
        $fuStmt->execute([$id]);
        $followupHistory = $fuStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    }
}
try {
    $c2 = $db->prepare("SELECT tc.*, u.full_name FROM task_comments tc LEFT JOIN users u ON u.id = tc.user_id WHERE tc.task_id = ? ORDER BY tc.created_at ASC");
    $c2->execute([$id]);
    $comments = $c2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $w2 = $db->prepare("
        SELECT tw.*, u1.full_name AS from_name, u2.full_name AS to_name,
               d1.dept_name AS from_dept, d2.dept_name AS to_dept
        FROM task_workflow tw
        LEFT JOIN users u1 ON u1.id = tw.from_user_id
        LEFT JOIN users u2 ON u2.id = tw.to_user_id
        LEFT JOIN departments d1 ON d1.id = tw.from_dept_id
        LEFT JOIN departments d2 ON d2.id = tw.to_dept_id
        WHERE tw.task_id = ? ORDER BY tw.created_at ASC
    ");
    $w2->execute([$id]);
    $workflow = $w2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

/* ── Lookup data for edit forms ───────────────────────────────────────────── */
$taxOfficeTypes = $taxTypes = $financeServiceTypes = $allBanks = $allCats = $allAuditors = [];
$companyTypes = $fileTypes = $panVatTypes = $yesNoOpts = $auditTypes2 = $corpGrades = [];

try {
    $taxOfficeTypes = $db->query("SELECT id, office_name, address FROM tax_office_types ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $taxTypes = $db->query("SELECT id, tax_type_name FROM tax_type ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $financeServiceTypes = $db->query("SELECT id, service_name FROM finance_service_types ORDER BY service_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $allBanks = $db->query("SELECT id, bank_name, address FROM bank_references WHERE is_active=1 ORDER BY bank_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $allCats = $db->query("SELECT id, category_name FROM bank_client_categories ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $allAuditors = $db->query("SELECT id, auditor_name, firm_name FROM auditors WHERE is_active=1 ORDER BY auditor_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $companyTypes = $db->query("SELECT id, type_name FROM company_types ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $fileTypes = $db->query("SELECT id, type_name FROM file_types ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $panVatTypes = $db->query("SELECT id, type_name FROM pan_vat_types ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $yesNoOpts = $db->query("SELECT id, value FROM yes_no ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $auditTypes2 = $db->query("SELECT id, type_name FROM audit_types ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
try {
    $corpGrades = $db->query("SELECT id, grade_name FROM corporate_grades WHERE is_active=1 ORDER BY grade_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

/* ── Office address helpers ───────────────────────────────────────────────── */
$currentOfficeAddr = '';
if ($detail && isset($detail['assigned_office_address'])) {
    $currentOfficeAddr = $detail['assigned_office_address'] ?? '';
}
if ($currentOfficeAddr === '' && $detail && !empty($detail['assigned_office_id'])) {
    foreach ($taxOfficeTypes as $_o) {
        if ($_o['id'] == $detail['assigned_office_id']) {
            $currentOfficeAddr = $_o['address'] ?? '';
            break;
        }
    }
}
$savedAddressSuggestions = [];
try {
    $savedAddressSuggestions = $db->query("SELECT DISTINCT assigned_office_address FROM task_tax WHERE assigned_office_address IS NOT NULL AND assigned_office_address != '' ORDER BY assigned_office_address")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
}

/* ══════════════════════════════════════════════════════════════════════════
   POST HANDLERS
══════════════════════════════════════════════════════════════════════════ */

function parseDate($d)
{
    if (empty($d))
        return null;
    $x = date_create($d);
    return $x ? date_format($x, 'Y-m-d') : null;
}

/* POST: update_status */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    verifyCsrf();
    $newStatus = $_POST['new_status'] ?? '';
    $valid = array_column($db->query("SELECT status_name FROM task_status")->fetchAll(), 'status_name');
    if (in_array($newStatus, $valid)) {
        $sid = $db->prepare("SELECT id FROM task_status WHERE status_name = ?");
        $sid->execute([$newStatus]);
        $nsid = (int) ($sid->fetchColumn() ?: 1);
        $db->prepare("UPDATE tasks SET status_id = ?, updated_at = NOW() WHERE id = ?")->execute([$nsid, $id]);
        try {
            $db->prepare("INSERT INTO task_workflow(task_id,action,from_user_id,old_status,new_status) VALUES(?,?,?,?,?)")
                ->execute([$id, 'status_changed', $user['id'], $task['status'], $newStatus]);
        } catch (Exception $e) {
        }
        $statusMsg = "Task #{$task['task_number']}";
        if (!empty($task['company_name']))
            $statusMsg .= " ({$task['company_name']})";
        $statusMsg .= " status changed from \"{$task['status']}\" to \"{$newStatus}\".";
        $taskLink = APP_URL . '/staff/tasks/view.php?id=' . $id;
        $adminLink = APP_URL . '/admin/tasks/view.php?id=' . $id;
        $emailData = [
            'template' => 'task_status_changed',
            'task' => [
                'id' => $id,
                'task_number' => $task['task_number'],
                'title' => $task['title'],
                'department' => $task['dept_name'] ?? '',
                'old_status' => $task['status'],
                'new_status' => $newStatus,
                'due_date' => $task['due_date'] ?? null,
                'fiscal_year' => $task['fiscal_year'] ?? '',
                'company' => $task['company_name'] ?? '',
                'priority' => $task['priority'] ?? '',
            ]
        ];
        if (!empty($task['assigned_to']) && $task['assigned_to'] != $user['id'])
            notify((int) $task['assigned_to'], "Status Updated: {$task['task_number']}", $statusMsg, 'status', $taskLink, true, $emailData);
        if (!empty($task['created_by']) && $task['created_by'] != $user['id'] && $task['created_by'] != $task['assigned_to'])
            notify((int) $task['created_by'], "Status Updated: {$task['task_number']}", $statusMsg, 'status', $adminLink, true, $emailData);
        logActivity("Status: {$task['task_number']} → {$newStatus}", 'tasks');
        setFlash('success', 'Status updated.');
        header("Location: view.php?id={$id}");
        exit;
    }
}

/* POST: save_tax */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tax'])) {
    verifyCsrf();
    $t2 = $_POST['tax'] ?? [];
    $firm = trim($t2['firm_name'] ?? '') ?: ($task['company_name'] ?? '');
    $biz = trim($t2['business_type'] ?? '') ?: $companyTypeVal;
    $pan = trim($t2['pan_number'] ?? '') ?: ($companyPanRow['pan_number'] ?? '');
    $offAddr = trim($t2['assigned_office_address'] ?? '');
    $taxFy = trim($t2['fiscal_year'] ?? '');
    $taxFyId = getFiscalYearId($db, $taxFy);
    foreach (['fiscal_year VARCHAR(10)', 'fiscal_year_id INT', 'assigned_office_address VARCHAR(200)'] as $colDef) {
        $col = explode(' ', $colDef)[0];
        try {
            $db->query("SELECT $col FROM task_tax LIMIT 1");
        } catch (Exception $e) {
            try {
                $db->exec("ALTER TABLE task_tax ADD COLUMN $colDef NULL");
            } catch (Exception $e2) {
            }
        }
    }
    // Save follow-up to task_followups table
    $newFuDate = trim($t2['follow_up_date'] ?? '');  // use $r2 for retail, $t2 for tax
    $newFuNote = trim($t2['follow_up_note'] ?? '');
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
        $task['company_id'],
        $firm,
        ($t2['assigned_office_id'] ?? '') !== '' ? (int) $t2['assigned_office_id'] : null,
        $offAddr ?: null,
        ($t2['tax_type_id'] ?? '') !== '' ? (int) $t2['tax_type_id'] : null,
        $taxFy,
        $taxFyId,
        trim($t2['submission_number'] ?? ''),
        trim($t2['udin_no'] ?? ''),
        $biz,
        $pan,
        ($t2['assigned_to'] ?? '') !== '' ? (int) $t2['assigned_to'] : null,
        ($t2['file_received_by'] ?? '') !== '' ? (int) $t2['file_received_by'] : null,
        ($t2['updated_by'] ?? '') !== '' ? (int) $t2['updated_by'] : null,
        ($t2['verify_by'] ?? '') !== '' ? (int) $t2['verify_by'] : null,
        ($t2['tax_clearance_status_id'] ?? '') !== '' ? (int) $t2['tax_clearance_status_id'] : null,
        ($t2['total_amount'] ?? '') !== '' ? (float) $t2['total_amount'] : 0,
        $t2['completed_date'] ?: null,
        trim($t2['remarks'] ?? ''),
        trim($t2['notes'] ?? ''),
    ];
    $ex = $db->prepare("SELECT id FROM task_tax WHERE task_id = ?");
    $ex->execute([$id]);
    if ($ex->fetch())
        $db->prepare("UPDATE task_tax SET 
            company_id=?,firm_name=?,assigned_office_id=?,assigned_office_address=?,
            tax_type_id=?,fiscal_year=?,fiscal_year_id=?,submission_number=?,udin_no=?,
            business_type=?,pan_number=?,assigned_to=?,file_received_by=?,updated_by=?,
            verify_by=?,tax_clearance_status_id=?,total_amount=?,completed_date=?,
            remarks=?,notes=? WHERE task_id=?")
            ->execute(array_merge($p, [$id]));
    else
        $db->prepare("INSERT INTO task_tax(
        task_id,company_id,firm_name,assigned_office_id,assigned_office_address,
        tax_type_id,fiscal_year,fiscal_year_id,submission_number,udin_no,
        business_type,pan_number,assigned_to,file_received_by,updated_by,
        verify_by,tax_clearance_status_id,total_amount,completed_date,
        remarks,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute(array_merge([$id], $p));
    if (!empty($t2['status_id']))
        $db->prepare("UPDATE tasks SET status_id=?,updated_at=NOW() WHERE id=?")->execute([(int) $t2['status_id'], $id]);
    syncTaskFiscalYear($db, $id);
    logActivity("Tax saved: {$task['task_number']}", 'tasks');
    setFlash('success', 'Tax details saved.');
    header("Location: view.php?id={$id}");
    exit;
}

/* POST: save_retail */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_retail'])) {
    verifyCsrf();
    $r2 = $_POST['retail'] ?? [];
    $retFy = trim($r2['fiscal_year'] ?? '');
    $retFyId = getFiscalYearId($db, $retFy);
    foreach (['fiscal_year VARCHAR(10)', 'fiscal_year_id INT'] as $colDef) {
        $col = explode(' ', $colDef)[0];
        try {
            $db->query("SELECT $col FROM task_retail LIMIT 1");
        } catch (Exception $e) {
            try {
                $db->exec("ALTER TABLE task_retail ADD COLUMN $colDef NULL");
            } catch (Exception $e2) {
            }
        }
    }
    // Save follow-up to task_followups table
    $newFuDate = trim($r2['follow_up_date'] ?? '');  // use $r2 for retail, $t2 for tax
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
        $task['company_id'],
        trim($r2['firm_name'] ?? '') ?: ($task['company_name'] ?? ''),
        ($r2['company_type_id'] ?? '') !== '' ? (int) $r2['company_type_id'] : null,
        ($r2['file_type_id'] ?? '') !== '' ? (int) $r2['file_type_id'] : null,
        ($r2['pan_vat_id'] ?? '') !== '' ? (int) $r2['pan_vat_id'] : null,
        ($r2['vat_client_id'] ?? '') !== '' ? (int) $r2['vat_client_id'] : null,
        $r2['return_type'] ?? null,
        $retFy,
        $retFyId,
        (int) ($r2['no_of_audit_year'] ?? 1),
        trim($r2['pan_no'] ?? ''),
        ($r2['assigned_to'] ?? '') !== '' ? (int) $r2['assigned_to'] : null,
        $r2['assigned_date'] ?: null,
        ($r2['audit_type_id'] ?? '') !== '' ? (int) $r2['audit_type_id'] : null,
        $r2['ecd'] ?: null,
        ($r2['opening_due'] ?? '') !== '' ? (float) $r2['opening_due'] : 0,
        ($r2['work_status_id'] ?? '') !== '' ? (int) $r2['work_status_id'] : null,
        ($r2['finalisation_status_id'] ?? '') !== '' ? (int) $r2['finalisation_status_id'] : null,
        ($r2['finalised_by'] ?? '') !== '' ? (int) $r2['finalised_by'] : null,
        $r2['completed_date'] ?: null,
        ($r2['tax_clearance_status_id'] ?? '') !== '' ? (int) $r2['tax_clearance_status_id'] : null,
        ($r2['backup_status_id'] ?? '') !== '' ? (int) $r2['backup_status_id'] : null,
        trim($r2['notes'] ?? ''),
    ];
    $ex = $db->prepare("SELECT id FROM task_retail WHERE task_id = ?");
    $ex->execute([$id]);
    if ($ex->fetch())
        $db->prepare("UPDATE task_retail SET 
            company_id=?,firm_name=?,company_type_id=?,file_type_id=?,pan_vat_id=?,
            vat_client_id=?,return_type=?,fiscal_year=?,fiscal_year_id=?,no_of_audit_year=?,
            pan_no=?,assigned_to=?,assigned_date=?,audit_type_id=?,ecd=?,opening_due=?,
            work_status_id=?,finalisation_status_id=?,finalised_by=?,completed_date=?,
            tax_clearance_status_id=?,backup_status_id=?,notes=? 
            WHERE task_id=?")
            ->execute(array_merge($p, [$id]));
    else
        $db->prepare("INSERT INTO task_retail(
            task_id,company_id,firm_name,company_type_id,file_type_id,pan_vat_id,
            vat_client_id,return_type,fiscal_year,fiscal_year_id,no_of_audit_year,
            pan_no,assigned_to,assigned_date,audit_type_id,ecd,opening_due,
            work_status_id,finalisation_status_id,finalised_by,completed_date,
            tax_clearance_status_id,backup_status_id,notes) 
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(array_merge([$id], $p));
    if (!empty($r2['work_status_id']))
        $db->prepare("UPDATE tasks SET status_id=?,updated_at=NOW() WHERE id=?")->execute([(int) $r2['work_status_id'], $id]);
    syncTaskFiscalYear($db, $id);
    logActivity("Retail saved: {$task['task_number']}", 'tasks');
    setFlash('success', 'Retail details saved.');
    header("Location: view.php?id={$id}");
    exit;
}

/* POST: save_corporate */
/* POST: save_corporate */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_corporate'])) {
    verifyCsrf();
    $co = $_POST['corporate'] ?? [];

    $intOrNull = fn($v) => ($v !== '' && $v !== null) ? (int)$v   : null;
    $fltOrNull = fn($v) => ($v !== '' && $v !== null) ? (float)$v : null;
    $strOrNull = fn($v) => ($v !== '' && $v !== null) ? trim($v)  : null;

    $coFyId = $intOrNull($co['fiscal_year_id'] ?? '');

    $sharedParams = [
        $task['company_id'],                                                              // company_id
        $intOrNull($co['company_type_id']          ?? ''),                                // company_type_id
        $intOrNull($co['file_type_id']             ?? ''),                                // file_type_id
        $intOrNull($co['pan_vat_id']               ?? ''),                                // pan_vat_id
        $intOrNull($co['vat_client_id']            ?? ''),                                // vat_client_id
        $strOrNull($co['return_type']              ?? ''),                                // return_type
        trim($co['firm_name'] ?? '') ?: ($task['company_name'] ?? ''),                   // firm_name
        trim($co['pan_no']    ?? '') ?: ($companyData['pan_number'] ?? null),             // pan_no
        $intOrNull($co['grade_id']                 ?? ''),                                // grade_id
        $intOrNull($co['assigned_to']              ?? '') ?? $taskAssignedToId,           // assigned_to
        $intOrNull($co['finalised_by']             ?? ''),                                // finalised_by
        $strOrNull($co['completed_date']           ?? ''),                                // completed_date
        $strOrNull($co['remarks']                  ?? ''),                                // remarks
        $coFyId,                                                                          // fiscal_year_id
        (int)($co['no_of_audit_year']              ?? 1),                                 // no_of_audit_year
        $intOrNull($co['audit_type_id']            ?? ''),                                // audit_type_id
        $strOrNull($co['ecd']                      ?? ''),                                // ecd
        $fltOrNull($co['opening_due']              ?? '') ?? 0,                           // opening_due
        $intOrNull($co['finalisation_status_id']   ?? ''),                                // finalisation_status_id
        $intOrNull($co['tax_clearance_status_id']  ?? ''),                                // tax_clearance_status_id
        $intOrNull($co['backup_status_id']         ?? ''),                                // backup_status_id
        $strOrNull($co['notes']                    ?? ''),                                // notes
    ];

    // Save follow-up
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

    try {
        $ex = $db->prepare("SELECT id FROM task_corporate WHERE task_id = ?");
        $ex->execute([$id]);

        if ($ex->fetch()) {
            // UPDATE — sharedParams covers all SET columns, task_id goes at the end for WHERE
            $db->prepare("
                UPDATE task_corporate SET
                    company_id=?, company_type_id=?, file_type_id=?, pan_vat_id=?,
                    vat_client_id=?, return_type=?, firm_name=?, pan_no=?, grade_id=?,
                    assigned_to=?, finalised_by=?, completed_date=?,
                    remarks=?, fiscal_year_id=?, no_of_audit_year=?, audit_type_id=?,
                    ecd=?, opening_due=?, finalisation_status_id=?,
                    tax_clearance_status_id=?, backup_status_id=?, notes=?
                WHERE task_id=?
            ")->execute(array_merge($sharedParams, [$id]));

        } else {
            // INSERT — prepend task_id, sharedParams covers all remaining columns
            $db->prepare("
                INSERT INTO task_corporate(
                    task_id,
                    company_id, company_type_id, file_type_id, pan_vat_id,
                    vat_client_id, return_type, firm_name, pan_no, grade_id,
                    assigned_to, finalised_by, completed_date,
                    remarks, fiscal_year_id, no_of_audit_year, audit_type_id,
                    ecd, opening_due, finalisation_status_id,
                    tax_clearance_status_id, backup_status_id, notes
                ) VALUES (
                    ?,
                    ?,?,?,?,
                    ?,?,?,?,?,
                    ?,?,?,
                    ?,?,?,?,
                    ?,?,?,
                    ?,?,?
                )
            ")->execute(array_merge([$id], $sharedParams));
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

/* POST: save_finance */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_finance'])) {
    verifyCsrf();
    $f = $_POST['finance'] ?? [];
    $finFy = trim($f['fiscal_year'] ?? '');
    $finFyId = getFiscalYearId($db, $finFy);
    foreach (['fiscal_year VARCHAR(10)', 'fiscal_year_id INT'] as $colDef) {
        $col = explode(' ', $colDef)[0];
        try {
            $db->query("SELECT $col FROM task_finance LIMIT 1");
        } catch (Exception $e) {
            try {
                $db->exec("ALTER TABLE task_finance ADD COLUMN $colDef NULL");
            } catch (Exception $e2) {
            }
        }
    }
    $p = [
        $task['company_id'],
        $finFy,
        $finFyId,
        ($f['total_amount'] ?? '') !== '' ? (float) $f['total_amount'] : 0,
        ($f['paid_amount'] ?? '') !== '' ? (float) $f['paid_amount'] : 0,
        $f['payment_date'] ?: null,
        trim($f['payment_method'] ?? ''),
        ($f['tax_clearance_status_id'] ?? '') !== '' ? (int) $f['tax_clearance_status_id'] : null,
        $f['tax_clearance_date'] ?: null,
        ($f['payment_status_id'] ?? '') !== '' ? (int) $f['payment_status_id'] : null,
        isset($f['is_completed']) ? 1 : 0,
        trim($f['remarks'] ?? ''),
    ];
    $ex = $db->prepare("SELECT id FROM task_finance WHERE task_id = ?");
    $ex->execute([$id]);
    if ($ex->fetch())
        $db->prepare("UPDATE task_finance SET company_id=?,fiscal_year=?,fiscal_year_id=?,total_amount=?,paid_amount=?,payment_date=?,payment_method=?,tax_clearance_status_id=?,tax_clearance_date=?,payment_status_id=?,is_completed=?,remarks=? WHERE task_id=?")
            ->execute(array_merge($p, [$id]));
    else
        $db->prepare("INSERT INTO task_finance(task_id,company_id,fiscal_year,fiscal_year_id,total_amount,paid_amount,payment_date,payment_method,tax_clearance_status_id,tax_clearance_date,payment_status_id,is_completed,remarks) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(array_merge([$id], $p));
    if (isset($f['is_completed'])) {
        $did = $db->query("SELECT id FROM task_status WHERE status_name='Done'")->fetchColumn();
        $db->prepare("UPDATE tasks SET status_id=?,updated_at=NOW() WHERE id=?")->execute([$did, $id]);
    }
    syncTaskFiscalYear($db, $id);
    setFlash('success', 'Finance details saved.');
    header("Location: view.php?id={$id}");
    exit;
}

/* POST: save_banking */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_banking'])) {
    verifyCsrf();
    $b = $_POST['banking'] ?? [];

    // Helper to convert empty string to null for integer fields
    $intOrNull = fn($v) => ($v !== '' && $v !== null) ? (int)$v : null;
    $strOrNull = fn($v) => ($v !== '' && $v !== null) ? (string)$v : null;

    $bank_reference_id  = $intOrNull($b['bank_reference_id']  ?? '');
    $client_category_id = $intOrNull($b['client_category_id'] ?? '');
    $ecd             = !empty($b['ecd'])             ? $b['ecd']             : null;
    $completion_date = !empty($b['completion_date']) ? $b['completion_date'] : null;

    $sales_check                    = $intOrNull($b['sales_check']                    ?? '');
    $audit_check                    = $intOrNull($b['audit_check']                    ?? '');
    $provisional_financial_statement = $intOrNull($b['provisional_financial_statement'] ?? '');
    $projected                      = $intOrNull($b['projected']                      ?? '');
    $consulting                     = $intOrNull($b['consulting']                     ?? '');
    $nta                            = $intOrNull($b['nta']                            ?? '');
    $salary_certificate             = $intOrNull($b['salary_certificate']             ?? '');
    $ca_certification               = $intOrNull($b['ca_certification']               ?? '');
    $etds                           = $intOrNull($b['etds']                           ?? '');
    $bill_issued                    = isset($b['bill_issued']) ? 1 : 0;
    $remarks                        = $strOrNull($b['remarks'] ?? '');

    $sharedParams = [
        $task['company_id'],
        $bank_reference_id,
        $client_category_id,
        $ecd,
        $completion_date,
        $sales_check,
        $audit_check,
        $provisional_financial_statement,
        $projected,
        $consulting,
        $nta,
        $salary_certificate,
        $ca_certification,
        $etds,
        $bill_issued,
        $remarks,
    ];

    $ex = $db->prepare("SELECT id FROM task_banking WHERE task_id = ?");
    $ex->execute([$task['id']]);

    if ($ex->fetch()) {
        $db->prepare("
            UPDATE task_banking SET
                company_id=?,
                bank_reference_id=?,
                client_category_id=?,
                ecd=?,
                completion_date=?,
                sales_check=?,
                audit_check=?,
                provisional_financial_statement=?,
                projected=?,
                consulting=?,
                nta=?,
                salary_certificate=?,
                ca_certification=?,
                etds=?,
                bill_issued=?,
                remarks=?
            WHERE task_id=?
        ")->execute(array_merge($sharedParams, [$task['id']]));
    } else {
        $db->prepare("
            INSERT INTO task_banking(
                task_id, company_id, bank_reference_id, client_category_id,
                ecd, completion_date,
                sales_check, audit_check, provisional_financial_statement, projected,
                consulting, nta, salary_certificate, ca_certification, etds,
                bill_issued, remarks
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute(array_merge([$task['id']], $sharedParams));
    }

    syncTaskFiscalYear($db, $task['id']);
    setFlash('success', 'Banking details saved.');
    header("Location: view.php?id={$task['id']}");
    exit;
}

/* POST: add_comment */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    verifyCsrf();
    $cmt = trim($_POST['comment'] ?? '');
    if ($cmt) {
        try {
            $db->prepare("INSERT INTO task_comments(task_id,user_id,comment) VALUES(?,?,?)")
                ->execute([$id, $user['id'], $cmt]);
        } catch (Exception $e) {
        }
        header("Location: view.php?id={$id}#comments");
        exit;
    }
}

/* ── View helpers ─────────────────────────────────────────────────────────── */
$pageTitle = 'Task: ' . $task['task_number'];
$sClass = 'status-' . strtolower(str_replace(' ', '-', $task['status'] ?? ''));

$currentStatusRow = null;
foreach ($taskStatuses as $ts) {
    if ($ts['status_name'] === ($task['status'] ?? '')) {
        $currentStatusRow = $ts;
        break;
    }
}

$deptColors = ['RETAIL' => '#f59e0b', 'TAX' => '#3b82f6', 'BANK' => '#10b981', 'CORP' => '#8b5cf6', 'FIN' => '#ef4444'];
$deptColor = $deptColors[$task['dept_code'] ?? ''] ?? '#9ca3af';
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
include '../../includes/header.php';
?>
<style>
    :root {
        --gold: #C9A227;
        --gold-light: #fdf6e3;
        --gold-border: #e9c96a;
    }

    /* ── Layout ──────────────────────────────────────────────────────────────── */
    .tv-wrap {
        padding: 1.5rem 0;
    }

    /* ── Cards ───────────────────────────────────────────────────────────────── */
    .tv-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
        margin-bottom: 1.25rem;
    }

    .tv-card-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: .85rem 1.25rem;
        border-bottom: 1px solid #f3f4f6;
        background: #fafafa;
    }

    .tv-card-head h5 {
        margin: 0;
        font-size: .93rem;
        font-weight: 700;
        color: #1f2937;
    }

    .tv-card-body {
        padding: 1.25rem;
    }

    /* ── Info grid ───────────────────────────────────────────────────────────── */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
        gap: .9rem;
    }

    .info-grid.wide {
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    }

    .ig-label {
        font-size: .67rem;
        font-weight: 700;
        color: #9ca3af;
        text-transform: uppercase;
        letter-spacing: .05em;
        margin-bottom: .2rem;
    }

    .ig-value {
        font-size: .87rem;
        color: #1f2937;
        font-weight: 500;
    }

    .ig-span {
        grid-column: 1 / -1;
    }

    /* ── Form helpers ────────────────────────────────────────────────────────── */
    .flabel {
        font-size: .7rem;
        font-weight: 700;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: .3rem;
        display: block;
    }

    .fsect {
        font-size: .75rem;
        font-weight: 700;
        color: #374151;
        text-transform: uppercase;
        letter-spacing: .05em;
        border-bottom: 1px solid #f3f4f6;
        padding-bottom: .35rem;
        margin: .5rem 0 .7rem;
    }

    .ro-field {
        background: #f8fafc;
        color: #374151;
        font-size: .87rem;
        padding: .3rem .65rem;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        gap: .4rem;
        font-weight: 500;
        min-height: 32px;
    }

    /* ── Required star ───────────────────────────────────────────────────────── */
    .req-star {
        color: #ef4444;
        margin-left: 2px;
    }

    /* ── Badges ──────────────────────────────────────────────────────────────── */
    .badge-linked {
        font-size: .6rem;
        color: #16a34a;
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        padding: .1rem .35rem;
        border-radius: 99px;
        margin-left: .35rem;
    }

    .badge-task {
        font-size: .6rem;
        color: #3b82f6;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        padding: .1rem .35rem;
        border-radius: 99px;
        margin-left: .35rem;
    }

    .priority-high {
        background: #fef2f2;
        color: #dc2626;
    }

    .priority-medium {
        background: #fefce8;
        color: #ca8a04;
    }

    .priority-low {
        background: #f0fdf4;
        color: #16a34a;
    }

    .s-pill {
        display: inline-flex;
        align-items: center;
        padding: .22rem .7rem;
        border-radius: 99px;
        font-size: .74rem;
        font-weight: 700;
    }

    /* ── Dept strip ──────────────────────────────────────────────────────────── */
    .dept-strip {
        height: 4px;
        border-radius: 4px 4px 0 0;
    }

    /* ── View/Edit toggle ────────────────────────────────────────────────────── */
    .ve-toggle {
        display: flex;
        gap: 5px;
    }

    .ve-btn {
        padding: .28rem .75rem;
        border-radius: 6px;
        font-size: .75rem;
        font-weight: 600;
        border: 1px solid #e5e7eb;
        cursor: pointer;
        transition: all .15s;
    }

    .ve-btn.active-view {
        background: #f3f4f6;
        color: #374151;
    }

    .ve-btn.active-edit {
        background: var(--gold);
        color: #fff;
        border-color: var(--gold);
    }

    .ve-btn.inactive {
        background: #f9fafb;
        color: #6b7280;
    }

    /* ── Empty state ─────────────────────────────────────────────────────────── */
    .empty-dept {
        text-align: center;
        padding: 2.5rem 1rem;
        color: #9ca3af;
    }

    .empty-dept i {
        font-size: 2rem;
        opacity: .35;
        display: block;
        margin-bottom: .5rem;
    }

    /* ── Money highlight ─────────────────────────────────────────────────────── */
    .money {
        font-weight: 700;
        color: #1f2937;
    }

    .money-pos {
        font-weight: 700;
        color: #16a34a;
    }

    .money-neg {
        font-weight: 700;
        color: #dc2626;
    }

    /* ── Checklist grid ──────────────────────────────────────────────────────── */
    .ck-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        gap: .45rem;
        margin-bottom: .75rem;
    }

    .ck-item {
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 7px;
        padding: .45rem .65rem;
    }

    /* ── Section highlight boxes ─────────────────────────────────────────────── */
    .sec-box {
        border-radius: 9px;
        padding: .85rem 1rem;
        margin-bottom: .9rem;
    }

    .sec-box-green {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
    }

    .sec-box-blue {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
    }

    /* ── Avatar ──────────────────────────────────────────────────────────────── */
    .av {
        border-radius: 50%;
        background: #dbeafe;
        color: #1d4ed8;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        flex-shrink: 0;
    }

    .av-sm {
        width: 32px;
        height: 32px;
        font-size: .75rem;
    }

    /* ── Comment bubble ──────────────────────────────────────────────────────── */
    .c-bubble {
        font-size: .87rem;
        background: #f9fafb;
        border: 1px solid #f3f4f6;
        padding: .55rem .9rem;
        border-radius: 0 8px 8px 8px;
    }

    /* ── Workflow icon ───────────────────────────────────────────────────────── */
    .wf-icon {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: #eff6ff;
        color: #3b82f6;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: .72rem;
    }

    /* ── Btn gold ────────────────────────────────────────────────────────────── */
    .btn-gold {
        background: var(--gold);
        color: #fff;
        border: none;
        font-weight: 600;
        font-size: .82rem;
    }

    .btn-gold:hover {
        background: #b08c1f;
        color: #fff;
    }

    /* ── Edit notice ─────────────────────────────────────────────────────────── */
    .edit-notice {
        background: #fffbf0;
        border: 1px solid var(--gold-border);
        border-radius: 8px;
        padding: .55rem 1rem;
        margin-bottom: 1rem;
        font-size: .81rem;
        color: #92400e;
    }
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="tv-wrap">
            <?= flashHtml() ?>

            <!-- Top bar -->
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back
                    to Tasks</a>
                <a href="edit.php?id=<?= $id ?>" class="btn btn-gold btn-sm"><i class="fas fa-pen me-1"></i>Edit
                    Task</a>
            </div>

            <div class="row g-4">
                <!-- ═══════════════ LEFT COLUMN ════════════════════════════ -->
                <div class="col-lg-8">

                    <!-- ── Task Overview ────────────────────────────────── -->
                    <div class="tv-card">
                        <div class="dept-strip" style="background:<?= $deptColor ?>"></div>
                        <div class="tv-card-head">
                            <div>
                                <span
                                    style="font-size:.68rem;font-weight:800;color:#9ca3af;letter-spacing:.08em;"><?= htmlspecialchars($task['task_number']) ?></span>
                                <h5 style="font-size:1.05rem;margin:.15rem 0 0;"><?= htmlspecialchars($task['title']) ?>
                                </h5>
                            </div>
                            <span class="s-pill"
                                style="background:<?= $currentStatusRow['bg_color'] ?? '#f3f4f6' ?>;color:<?= $currentStatusRow['color'] ?? '#374151' ?>;">
                                <?= htmlspecialchars($task['status'] ?? 'Pending') ?>
                            </span>
                        </div>
                        <div class="tv-card-body">
                            <div class="info-grid">
                                <?php
                                $overviewFields = [
                                    'Department' => '<span style="background:' . ($task['color'] ?? '#e5e7eb') . ';color:#fff;padding:.2rem .6rem;border-radius:6px;font-size:.8rem;font-weight:600;">' . htmlspecialchars($task['dept_name'] ?? '—') . '</span>',
                                    'Branch' => htmlspecialchars($task['branch_name'] ?? '—'),
                                    'Company' => htmlspecialchars($task['company_name'] ?? '—'),
                                    'Created By' => htmlspecialchars($task['assigned_by_name'] ?? '—'),
                                    'Assigned To' => htmlspecialchars($task['assigned_to_name'] ?? 'Unassigned'),
                                    'Priority' => '<span class="s-pill priority-' . ($task['priority'] ?? 'low') . '">' . ucfirst($task['priority'] ?? '—') . '</span>',
                                    'Due Date' => $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : '—',
                                    'Fiscal Year' => htmlspecialchars($task['fiscal_year'] ?? '—'),
                                    'Auditor' => htmlspecialchars($task['auditor_name'] ?? '—'),
                                    'Created' => date('d M Y, H:i', strtotime($task['created_at'])),
                                ];
                                foreach ($overviewFields as $label => $val): ?>
                                    <div>
                                        <div class="ig-label"><?= $label ?></div>
                                        <div class="ig-value"><?= $val ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($task['description']): ?>
                                    <div class="ig-span">
                                        <div class="ig-label">Description</div>
                                        <div class="ig-value" style="white-space:pre-line;">
                                            <?= htmlspecialchars($task['description']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($task['remarks']): ?>
                                    <div class="ig-span">
                                        <div class="ig-label">Remarks</div>
                                        <div class="ig-value" style="white-space:pre-line;">
                                            <?= htmlspecialchars($task['remarks']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ── Department Detail Card ───────────────────────── -->
                    <?php if ($detailTable): ?>
                        <div class="tv-card">
                            <?php
                            $deptIcons = ['RETAIL' => 'fas fa-store', 'TAX' => 'fas fa-receipt', 'BANK' => 'fas fa-landmark', 'CORP' => 'fas fa-building', 'FIN' => 'fas fa-coins'];
                            $deptIcon = $deptIcons[$task['dept_code']] ?? 'fas fa-file-alt';
                            ?>
                            <div class="tv-card-head">
                                <h5>
                                    <i class="<?= $deptIcon ?> me-2" style="color:<?= $deptColor ?>"></i>
                                    <?= htmlspecialchars($task['dept_name']) ?> Details
                                </h5>
                                <div class="ve-toggle">
                                    <button class="ve-btn active-view" id="btnView" onclick="switchMode('view')"><i
                                            class="fas fa-eye me-1"></i>View</button>
                                    <button class="ve-btn inactive" id="btnEdit" onclick="switchMode('edit')"><i
                                            class="fas fa-pen me-1"></i>Edit</button>
                                </div>
                            </div>
                            <div class="tv-card-body">

                                <!-- ══════ VIEW MODE ══════ -->
                                <div id="viewSection">
                                    <?php if (empty($detail)): ?>
                                        <div class="empty-dept">
                                            <i class="fas fa-file-circle-question"></i>
                                            <p style="font-size:.9rem;">No <?= htmlspecialchars($task['dept_name']) ?> details
                                                recorded yet.</p>
                                            <button class="btn btn-gold btn-sm" onclick="switchMode('edit')"><i
                                                    class="fas fa-plus me-1"></i>Add Details Now</button>
                                        </div>

                                    <?php else: ?>

                                        <?php /* ─────────── TAX VIEW ─────────── */ ?>
                                        <?php if ($task['dept_code'] === 'TAX'): ?>
                                            <div class="info-grid wide mb-3">
                                                <?php
                                                $offDisp = htmlspecialchars($detail['assigned_office_name'] ?? '—');
                                                $offAddr = $detail['assigned_office_address'] ?? $detail['assigned_office_default_address'] ?? '';
                                                if ($offAddr)
                                                    $offDisp .= ' <span style="color:#6b7280;font-size:.82em;">– ' . htmlspecialchars($offAddr) . '</span>';
                                                $taxViewRows = [
                                                    'Firm Name' => htmlspecialchars($detail['firm_name'] ?? '—'),
                                                    'Tax Type' => '<span style="background:#f0fdf4;color:#16a34a;padding:.2rem .55rem;border-radius:6px;font-size:.84rem;font-weight:600;">' . htmlspecialchars($detail['tax_type_name'] ?? '—') . '</span>',
                                                    'Assigned Office' => '<span style="background:#eff6ff;color:#1d4ed8;padding:.2rem .55rem;border-radius:6px;font-size:.84rem;font-weight:600;">' . $offDisp . '</span>',
                                                    'Fiscal Year' => htmlspecialchars($detail['fiscal_year'] ?? '—'),
                                                    'Business Type' => htmlspecialchars($detail['business_type'] ?? '—'),
                                                    'PAN Number' => htmlspecialchars($detail['pan_number'] ?? '—'),
                                                    'Assigned To' => htmlspecialchars($detail['assigned_to_name'] ?? '—'),
                                                    'File Received By' => htmlspecialchars($detail['file_received_by_name'] ?? '—'),
                                                    'Updated By' => htmlspecialchars($detail['updated_by_name'] ?? '—'),
                                                    'Verify By' => htmlspecialchars($detail['verify_by_name'] ?? '—'),
                                                    'Status' => htmlspecialchars($detail['status_name'] ?? '—'),
                                                    'Tax Clearance' => htmlspecialchars($detail['tax_clearance_status_name'] ?? '—'),
                                                    'Total Amount' => '<span class="money">Rs. ' . number_format($detail['total_amount'] ?? 0, 2) . '</span>',
                                                    'Assigned Date' => ($detail['assigned_date'] ?? '') ? date('d M Y', strtotime($detail['assigned_date'])) : '—',
                                                    'Completed Date' => ($detail['completed_date'] ?? '') ? date('d M Y', strtotime($detail['completed_date'])) : '—',
                                                    'Follow-up Date' => !empty($followupHistory)
                                                        ? date('d M Y', strtotime(end($followupHistory)['followup_date']))
                                                        : '—',
                                                ];
                                                foreach ($taxViewRows as $l => $v): ?>
                                                    <div>
                                                        <div class="ig-label"><?= $l ?></div>
                                                        <div class="ig-value"><?= $v ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php foreach ([
                                                ['Submission Number', $detail['submission_number'] ?? '', 'https://ird.gov.np', '#3b82f6', 'IRD Portal'],
                                                ['UDIN Number', $detail['udin_no'] ?? '', 'https://udin.icai.org', '#8b5cf6', 'ICAN Portal'],
                                            ] as [$l, $v, $url, $col, $btn]): ?>
                                                <div class="d-flex align-items-center gap-2 mb-2">
                                                    <span class="ig-label me-1"><?= $l ?>:</span>
                                                    <strong style="font-size:.87rem;"><?= htmlspecialchars($v ?: '—') ?></strong>
                                                    <?php if ($v): ?>
                                                        <a href="<?= $url ?>" target="_blank"
                                                            style="background:<?= $col ?>;color:#fff;padding:.18rem .55rem;border-radius:6px;font-size:.7rem;text-decoration:none;"><i
                                                                class="fas fa-external-link-alt me-1"></i><?= $btn ?></a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if ($detail['remarks'] ?? ''): ?>
                                                <div class="mt-3">
                                                    <div class="ig-label">Remarks</div>
                                                    <div class="ig-value" style="white-space:pre-line;">
                                                        <?= htmlspecialchars($detail['remarks']) ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($detail['notes'] ?? ''): ?>
                                                <div class="mt-2">
                                                    <div class="ig-label">Notes</div>
                                                    <div class="ig-value" style="white-space:pre-line;">
                                                        <?= htmlspecialchars($detail['notes']) ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php /* ─────────── BANKING VIEW ─────────── */ ?>
                                        <?php elseif ($task['dept_code'] === 'BANK'): ?>
                                            <div class="sec-box sec-box-green">
                                                <div class="fsect" style="color:#16a34a;border-bottom-color:#bbf7d0;"><i
                                                        class="fas fa-building me-1"></i>Client Info</div>
                                                <div class="info-grid">
                                                    <?php foreach ([
                                                        'Company' => $detail['company_name'] ?? $task['company_name'] ?? '—',
                                                        'Contact' => $detail['contact_person'] ?? '—',
                                                        'Phone' => $detail['contact_phone'] ?? '—',
                                                        'PAN' => $detail['company_pan'] ?? '—',
                                                        'Type' => $detail['company_type_name'] ?? '—',
                                                        'Bank' => $detail['bank_name'] ?? '—',
                                                        'Category' => $detail['client_category_name'] ?? '—',
                                                        'ECD' => ($detail['ecd'] ?? '') ? date('d M Y', strtotime($detail['ecd'])) : '—',
                                                        'Completion' => ($detail['completion_date'] ?? '') ? date('d M Y', strtotime($detail['completion_date'])) : '—',
                                                    ] as $l => $v): ?>
                                                        <div>
                                                            <div class="ig-label"><?= $l ?></div>
                                                            <div class="ig-value"><?= htmlspecialchars($v) ?></div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div class="fsect">Work Checklist</div>
                                            <div class="ck-grid">
                                                <?php foreach ([
                                                    'sales_check' => 'Sales Check',
                                                    'audit_check' => 'Audit',
                                                    'provisional_financial_statement' => 'Provisional/FS',
                                                    'projected' => 'Projected',
                                                    'consulting' => 'Consulting',
                                                    'nta' => 'NTA',
                                                    'salary_certificate' => 'Salary Cert.',
                                                    'ca_certification' => 'CA Cert.',
                                                    'etds' => 'ETDS',
                                                ] as $f => $l): ?>
                                                    <div class="ck-item">
                                                        <div class="ig-label"><?= $l ?></div>
                                                        <div style="font-size:.86rem;font-weight:600;">
                                                            <?= $detail[$f] !== null ? htmlspecialchars((string) $detail[$f]) : '—' ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <div class="ck-item">
                                                    <div class="ig-label">Bill Issued</div>
                                                    <div style="font-size:.86rem;">
                                                        <?= ($detail['bill_issued'] ?? 0) ? '<span style="color:#16a34a;font-weight:700;">✓ Yes</span>' : 'No' ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if ($detail['remarks'] ?? ''): ?>
                                                <div class="mt-2">
                                                    <div class="ig-label">Remarks</div>
                                                    <div class="ig-value"><?= htmlspecialchars($detail['remarks']) ?></div>
                                                </div>
                                            <?php endif; ?>

                                            <?php /* ─────────── FINANCE VIEW ─────────── */ ?>
                                        <?php elseif ($task['dept_code'] === 'FIN'): ?>
                                            <?php $due = ($detail['total_amount'] ?? 0) - ($detail['paid_amount'] ?? 0); ?>
                                            <div class="info-grid wide">
                                                <?php foreach ([
                                                    'Fiscal Year' => htmlspecialchars($detail['fiscal_year'] ?? '—'),
                                                    'Total Amount' => '<span class="money">Rs. ' . number_format($detail['total_amount'] ?? 0, 2) . '</span>',
                                                    'Paid Amount' => '<span class="money-pos">Rs. ' . number_format($detail['paid_amount'] ?? 0, 2) . '</span>',
                                                    'Due Amount' => '<span class="money-neg">Rs. ' . number_format($due, 2) . '</span>',
                                                    'Payment Date' => ($detail['payment_date'] ?? '') ? date('d M Y', strtotime($detail['payment_date'])) : '—',
                                                    'Method' => htmlspecialchars($methodLabels[$detail['payment_method']] ?? $detail['payment_method'] ?? '—'),
                                                    'Payment Status' => htmlspecialchars($detail['payment_status_id'] ? ucwords(str_replace('_', ' ', $detail['payment_status_id'])) : '—'),
                                                    'Tax Clearance' => htmlspecialchars($detail['tax_clearance_status_name'] ?? '—'),
                                                    'TC Date' => ($detail['tax_clearance_date'] ?? '') ? date('d M Y', strtotime($detail['tax_clearance_date'])) : '—',
                                                    'Completed' => ($detail['is_completed'] ?? 0) ? '<span style="color:#16a34a;font-weight:700;">✓ Yes</span>' : 'No',
                                                ] as $l => $v): ?>
                                                    <div>
                                                        <div class="ig-label"><?= $l ?></div>
                                                        <div class="ig-value"><?= $v ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php if ($detail['remarks'] ?? ''): ?>
                                                <div class="mt-3">
                                                    <div class="ig-label">Remarks</div>
                                                    <div class="ig-value"><?= htmlspecialchars($detail['remarks']) ?></div>
                                                </div>
                                            <?php endif; ?>

                                            <?php /* ─────────── RETAIL VIEW ─────────── */ ?>
                                        <?php elseif ($task['dept_code'] === 'RETAIL'): ?>
                                            <div class="info-grid wide">
                                                <?php foreach ([
                                                    'Firm Name' => htmlspecialchars($detail['firm_name'] ?? '—'),
                                                    'Company Type' => htmlspecialchars($detail['company_type_name'] ?? '—'),
                                                    'File Type' => htmlspecialchars($detail['file_type_name'] ?? '—'),
                                                    'PAN / VAT' => htmlspecialchars($detail['pan_vat_name'] ?? '—'),
                                                    'VAT Client' => htmlspecialchars($detail['vat_client_value'] ?? '—'),
                                                    'Return Type' => htmlspecialchars($detail['return_type'] ?? '—'),
                                                    'Fiscal Year' => htmlspecialchars($detail['fiscal_year'] ?? '—'),
                                                    'Audit Years' => htmlspecialchars((string) ($detail['no_of_audit_year'] ?? '—')),
                                                    'PAN No' => htmlspecialchars($detail['pan_no'] ?? '—'),
                                                    'Audit Type' => htmlspecialchars($detail['audit_type_name'] ?? '—'),
                                                    'Assigned To' => htmlspecialchars($detail['retail_assigned_to_name'] ?? '—'),
                                                    'Assigned Date' => ($detail['assigned_date'] ?? '') ? date('d M Y', strtotime($detail['assigned_date'])) : '—',
                                                    'ECD' => ($detail['ecd'] ?? '') ? date('d M Y', strtotime($detail['ecd'])) : '—',
                                                    'Opening Due' => $detail['opening_due'] !== null ? '<span class="money">Rs. ' . number_format($detail['opening_due'], 2) . '</span>' : '—',
                                                    'Work Status' => htmlspecialchars($detail['work_status_name'] ?? '—'),
                                                    'Finalisation' => htmlspecialchars($detail['finalisation_status_name'] ?? '—'),
                                                    'Finalised By' => htmlspecialchars($detail['finalised_by_name'] ?? '—'),
                                                    'Completed' => ($detail['completed_date'] ?? '') ? date('d M Y', strtotime($detail['completed_date'])) : '—',
                                                    'Tax Clearance' => htmlspecialchars($detail['tax_clearance_status_name'] ?? '—'),
                                                    'Backup Status' => htmlspecialchars($detail['backup_status_value'] ?? '—'),
                                                    'Follow-up Date' => !empty($followupHistory)
                                                        ? date('d M Y', strtotime(end($followupHistory)['followup_date']))
                                                        : '—',
                                                ] as $l => $v): ?>
                                                    <div>
                                                        <div class="ig-label"><?= $l ?></div>
                                                        <div class="ig-value"><?= $v ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php if ($detail['notes'] ?? ''): ?>
                                                <div class="mt-3">
                                                    <div class="ig-label">Notes</div>
                                                    <div class="ig-value" style="white-space:pre-line;">
                                                        <?= htmlspecialchars($detail['notes']) ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php /* ─────────── CORPORATE VIEW ─────────── */ ?>
                                        <?php elseif ($task['dept_code'] === 'CORP'): ?>
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
                                                    'ECD' => ($detail['ecd'] ?? '') ? date('d M Y', strtotime($detail['ecd'])) : '—',
                                                    'Opening Due' => $detail['opening_due'] !== null ? 'Rs. ' . number_format($detail['opening_due'], 2) : '—',
                                                    'Finalisation' => htmlspecialchars($detail['finalisation_status_name'] ?? '—'),
                                                    'Finalised By' => htmlspecialchars($detail['finalised_by_name'] ?? '—'),
                                                    'Completed Date' => ($detail['completed_date'] ?? '') ? date('d M Y', strtotime($detail['completed_date'])) : '—',
                                                    'Tax Clearance' => htmlspecialchars($detail['tax_clearance_status_name'] ?? '—'),
                                                    'Backup Status' => htmlspecialchars($detail['backup_status_value'] ?? '—'),
                                                    'Follow-up Date' => !empty($followupHistory)
                                                        ? date('d M Y', strtotime(end($followupHistory)['followup_date']))
                                                        : '—',
                                                ] as $l => $v): ?>
                                                    <div class="col-md-4">
                                                        <div class="vw-label"><?= $l ?></div>
                                                        <div class="vw-value"><?= $v ?></div>
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
                                            </div><!-- end row -->

                                        <?php endif; /* dept code switch end */ ?>

                                    <?php endif; /* empty detail end */ ?>
                                </div><!-- #viewSection -->

                                <!-- ══════ EDIT MODE ══════ -->
                                <div id="editSection" style="display:none;">
                                    <div class="edit-notice">
                                        <i class="fas fa-pen me-1"></i>
                                        <strong><?= $detail ? 'Update' : 'Add' ?>
                                            <?= htmlspecialchars($task['dept_name']) ?> Details</strong> — fields marked
                                        <span class="req-star">*</span> are required
                                    </div>

                                    <?php /* ─────────── TAX EDIT ─────────── */ ?>
                                    <?php if ($task['dept_code'] === 'TAX'): ?>
                                        <datalist id="office_address_list">
                                            <?php foreach ($savedAddressSuggestions as $addr): ?>
                                                <option value="<?= htmlspecialchars($addr) ?>">
                                                <?php endforeach; ?>
                                        </datalist>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="save_tax" value="1">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="flabel">Firm Name</label>
                                                    <input type="text" name="tax[firm_name]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['firm_name'] ?? $task['company_name'] ?? '') ?>"
                                                        <?= $task['company_id'] ? 'readonly style="background:#f0fdf4;cursor:not-allowed;"' : '' ?>>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="flabel">Assigned Office</label>
                                                    <select name="tax[assigned_office_id]" class="form-select form-select-sm"
                                                        id="officeSelect" onchange="fillOfficeAddr(this)">
                                                        <option value="">-- Select --</option>
                                                        <?php foreach ($taxOfficeTypes as $o): ?>
                                                            <option value="<?= $o['id'] ?>"
                                                                data-addr="<?= htmlspecialchars($o['address'] ?? '') ?>"
                                                                <?= ($detail['assigned_office_id'] ?? '') == $o['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($o['office_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="flabel">Office Branch Address</label>
                                                    <input type="text" name="tax[assigned_office_address]" id="officeAddr"
                                                        class="form-control form-control-sm" list="office_address_list"
                                                        value="<?= htmlspecialchars($currentOfficeAddr) ?>"
                                                        placeholder="e.g. Lazimpat, Kathmandu">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="flabel">Tax Type</label>
                                                    <select name="tax[tax_type_id]" class="form-select form-select-sm">
                                                        <option value="">-- Select --</option>
                                                        <?php foreach ($taxTypes as $tt): ?>
                                                            <option value="<?= $tt['id'] ?>" <?= ($detail['tax_type_id'] ?? '') == $tt['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($tt['tax_type_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="flabel">Fiscal Year</label>
                                                    <?= fiscalYearSelect('tax[fiscal_year]', $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy), $fys) ?>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="flabel">Business Type</label>
                                                    <input type="text" name="tax[business_type]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['business_type'] ?? $companyTypeVal) ?>"
                                                        <?= ($task['company_id'] && $companyTypeVal) ? 'readonly style="background:#f0fdf4;cursor:not-allowed;"' : '' ?>>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="flabel">PAN Number</label>
                                                    <?php if ($companyPanRow && $companyPanRow['pan_number']): ?>
                                                        <input type="text" name="tax[pan_number]"
                                                            class="form-control form-control-sm"
                                                            value="<?= htmlspecialchars($detail['pan_number'] ?? $companyPanRow['pan_number']) ?>"
                                                            readonly style="background:#f0fdf4;font-weight:600;cursor:not-allowed;">
                                                    <?php else: ?>
                                                        <input type="text" name="tax[pan_number]"
                                                            class="form-control form-control-sm"
                                                            value="<?= htmlspecialchars($detail['pan_number'] ?? '') ?>"
                                                            placeholder="Enter PAN">
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Assigned To <span class="badge-task"><i
                                                                class="fas fa-link me-1"></i>from task</span></label>
                                                    <div class="ro-field"><i class="fas fa-user-circle"
                                                            style="color:#3b82f6;font-size:14px;"></i><?= htmlspecialchars($taskAssignedToName) ?>
                                                    </div>
                                                    <input type="hidden" name="tax[assigned_to]"
                                                        value="<?= $taskAssignedToId ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">File Received By</label>
                                                    <select name="tax[file_received_by]" class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($allStaff as $s): ?>
                                                            <option value="<?= $s['id'] ?>" <?= ($detail['file_received_by'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($s['full_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Updated By</label>
                                                    <select name="tax[updated_by]" class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($allStaff as $s): ?>
                                                            <option value="<?= $s['id'] ?>" <?= ($detail['updated_by'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($s['full_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Verify By</label>
                                                    <select name="tax[verify_by]" class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($allStaff as $s): ?>
                                                            <option value="<?= $s['id'] ?>" <?= ($detail['verify_by'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($s['full_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Status</label>
                                                    <select name="tax[status_id]" class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($taskStatuses as $ts): ?>
                                                            <option value="<?= $ts['id'] ?>" <?= ($detail['status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($ts['status_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Tax Clearance Status</label>
                                                    <select name="tax[tax_clearance_status_id]"
                                                        class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($taskStatuses as $ts): ?>
                                                            <option value="<?= $ts['id'] ?>" <?= ($detail['tax_clearance_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($ts['status_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="flabel">Submission Number <span
                                                            style="font-size:.63rem;color:#9ca3af;">(from IRD)</span></label>
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" name="tax[submission_number]" class="form-control"
                                                            value="<?= htmlspecialchars($detail['submission_number'] ?? '') ?>">
                                                        <a href="https://ird.gov.np" target="_blank"
                                                            class="btn btn-outline-primary btn-sm"><i
                                                                class="fas fa-external-link-alt"></i></a>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="flabel">UDIN Number <span
                                                            style="font-size:.63rem;color:#9ca3af;">(from ICAN)</span></label>
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" name="tax[udin_no]" class="form-control"
                                                            value="<?= htmlspecialchars($detail['udin_no'] ?? '') ?>">
                                                        <a href="https://udin.icai.org" target="_blank"
                                                            class="btn btn-outline-secondary btn-sm"><i
                                                                class="fas fa-external-link-alt"></i></a>
                                                    </div>
                                                </div>
                                                <!-- total_amount: NOT NULL DEFAULT 0 -->
                                                <div class="col-md-4">
                                                    <label class="flabel">Total Amount (Rs.) <span
                                                            class="req-star">*</span></label>
                                                    <input type="number" name="tax[total_amount]"
                                                        class="form-control form-control-sm" step="0.01" min="0"
                                                        value="<?= htmlspecialchars((string) ($detail['total_amount'] ?? '0')) ?>"
                                                        required>
                                                </div>
                                                <?php foreach (['assigned_date' => 'Assigned Date', 'completed_date' => 'Completed Date'] as $f => $l): ?>
                                                    <div class="col-md-4">
                                                        <label class="flabel"><?= $l ?></label>
                                                        <input type="date" name="tax[<?= $f ?>]"
                                                            class="form-control form-control-sm"
                                                            value="<?= htmlspecialchars($detail[$f] ?? '') ?>">
                                                    </div>
                                                <?php endforeach; ?>
                                                <!-- follow_up_date -->
                                                <div class="col-md-4">
                                                    <label class="flabel">Follow-up Date</label>
                                                    <input type="date" name="corporate[follow_up_date]"
                                                        class="form-control form-control-sm" value="">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Follow-up Note <span
                                                            style="font-size:.63rem;color:#9ca3af;">(optional)</span></label>
                                                    <input type="text" name="corporate[follow_up_note]"
                                                        class="form-control form-control-sm" placeholder="e.g. Called client…">
                                                </div>
                                                <div class="col-12">
                                                    <label class="flabel">Remarks</label>
                                                    <textarea name="tax[remarks]" class="form-control form-control-sm"
                                                        rows="2"><?= htmlspecialchars($detail['remarks'] ?? '') ?></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <label class="flabel">Notes</label>
                                                    <textarea name="tax[notes]" class="form-control form-control-sm"
                                                        rows="2"><?= htmlspecialchars($detail['notes'] ?? '') ?></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-gold btn-sm"><i
                                                            class="fas fa-save me-1"></i>Save Tax Details</button>
                                                </div>
                                            </div>
                                        </form>

                                        <?php /* ─────────── BANKING EDIT ─────────── */ ?>
                                    <?php elseif ($task['dept_code'] === 'BANK'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="save_banking" value="1">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="flabel">Bank Name</label>
                                                    <select name="banking[bank_reference_id]"
                                                        class="form-select form-select-sm">
                                                        <option value="">-- Select Bank --</option>
                                                        <?php foreach ($allBanks as $bk): ?>
                                                            <option value="<?= $bk['id'] ?>" <?= ($detail['bank_reference_id'] ?? '') == $bk['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($bk['bank_name'] . ($bk['address'] ? ' - ' . $bk['address'] : '')) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="flabel">Category</label>
                                                    <select name="banking[client_category_id]"
                                                        class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($allCats as $cat): ?>
                                                            <option value="<?= $cat['id'] ?>" <?= ($detail['client_category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($cat['category_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="flabel">Auditor</label>
                                                    <select name="banking[auditor_id]" class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($allAuditors as $au): ?>
                                                            <option value="<?= $au['id'] ?>" <?= ($detail['auditor_id'] ?? '') == $au['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($au['auditor_name'] . ($au['firm_name'] ? ' — ' . $au['firm_name'] : '')) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <?php foreach (['ecd' => 'ECD', 'completion_date' => 'Completion Date'] as $f => $l): ?>
                                                    <div class="col-md-3">
                                                        <label class="flabel"><?= $l ?></label>
                                                        <input type="date" name="banking[<?= $f ?>]"
                                                            class="form-control form-control-sm"
                                                            value="<?= htmlspecialchars($detail[$f] ?? '') ?>">
                                                    </div>
                                                <?php endforeach; ?>
                                                <div class="col-12">
                                                    <div class="fsect">Work Checklist <span
                                                            style="font-size:.68rem;color:#9ca3af;font-weight:400;">(all
                                                            nullable — leave blank if N/A)</span></div>
                                                    <div class="row g-3">
                                                        <?php foreach ([
                                                            'sales_check' => 'Sales Check',
                                                            'audit_check' => 'Audit',
                                                            'provisional_financial_statement' => 'Provisional/FS',
                                                            'projected' => 'Projected',
                                                            'consulting' => 'Consulting',
                                                            'nta' => 'NTA',
                                                            'salary_certificate' => 'Salary Cert.',
                                                            'ca_certification' => 'CA Cert.',
                                                            'etds' => 'ETDS',
                                                        ] as $f => $l): ?>
                                                            <div class="col-md-3 col-6">
                                                                <label class="flabel"><?= $l ?></label>
                                                                <input type="number" name="banking[<?= $f ?>]"
                                                                    class="form-control form-control-sm"
                                                                    value="<?= htmlspecialchars((string) ($detail[$f] ?? '')) ?>"
                                                                    min="0" placeholder="—">
                                                            </div>
                                                        <?php endforeach; ?>
                                                        <div class="col-md-3 col-6">
                                                            <label class="flabel">Bill Issued</label>
                                                            <div class="form-check form-switch mt-2">
                                                                <input class="form-check-input" type="checkbox"
                                                                    name="banking[bill_issued]" value="1" id="billIssued"
                                                                    <?= ($detail['bill_issued'] ?? 0) ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="billIssued">Yes</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <label class="flabel">Remarks</label>
                                                    <textarea name="banking[remarks]" class="form-control form-control-sm"
                                                        rows="2"><?= htmlspecialchars($detail['remarks'] ?? '') ?></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-gold btn-sm"><i
                                                            class="fas fa-save me-1"></i>Save Banking Details</button>
                                                </div>
                                            </div>
                                        </form>

                                        <?php /* ─────────── FINANCE EDIT ─────────── */ ?>
                                    <?php elseif ($task['dept_code'] === 'FIN'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="save_finance" value="1">
                                            <div class="row g-3">

                                                <div class="col-md-3">
                                                    <label class="flabel">Fiscal Year</label>
                                                    <?= fiscalYearSelect('finance[fiscal_year]', $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy), $fys) ?>
                                                </div>
                                                <!-- total_amount: NOT NULL DEFAULT 0 -->
                                                <div class="col-md-4">
                                                    <label class="flabel">Total Amount (Rs.) <span
                                                            class="req-star">*</span></label>
                                                    <input type="number" name="finance[total_amount]" id="fin_total_amount"
                                                        class="form-control form-control-sm" step="0.01" min="0"
                                                        value="<?= htmlspecialchars((string) ($detail['total_amount'] ?? '0')) ?>"
                                                        required>
                                                </div>
                                                <!-- paid_amount: NOT NULL DEFAULT 0 -->
                                                <div class="col-md-4">
                                                    <label class="flabel">Paid Amount (Rs.) <span
                                                            class="req-star">*</span></label>
                                                    <input type="number" name="finance[paid_amount]" id="fin_paid_amount"
                                                        class="form-control form-control-sm" step="0.01" min="0"
                                                        value="<?= htmlspecialchars((string) ($detail['paid_amount'] ?? '0')) ?>"
                                                        required>
                                                </div>
                                                <!-- due_amount: GENERATED — read-only, never posted -->
                                                <div class="col-md-4">
                                                    <label class="flabel">Due Amount <span
                                                            style="font-size:.63rem;color:#9ca3af;">(auto-calculated)</span></label>
                                                    <input type="text" id="fin_due" class="form-control form-control-sm"
                                                        value="Rs. <?= number_format(($detail['total_amount'] ?? 0) - ($detail['paid_amount'] ?? 0), 2) ?>"
                                                        readonly style="background:#f9fafb;">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Payment Date</label>
                                                    <input type="date" name="finance[payment_date]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['payment_date'] ?? '') ?>">
                                                </div>
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
                                                <div class="col-md-4">
                                                    <label class="form-label-mis">Payment Status</label>

                                                    <select name="finance[payment_status_id]"
                                                        class="form-select form-select-sm">
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
                                                <div class="col-md-4">
                                                    <label class="flabel">Tax Clearance Status</label>
                                                    <select name="finance[tax_clearance_status_id]"
                                                        class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($taskStatuses as $ts): ?>
                                                            <option value="<?= $ts['id'] ?>" <?= ($detail['tax_clearance_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($ts['status_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Tax Clearance Date</label>
                                                    <input type="date" name="finance[tax_clearance_date]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['tax_clearance_date'] ?? '') ?>">
                                                </div>
                                                <div class="col-12">
                                                    <label class="flabel">Remarks</label>
                                                    <textarea name="finance[remarks]" class="form-control form-control-sm"
                                                        rows="2"><?= htmlspecialchars($detail['remarks'] ?? '') ?></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox"
                                                            name="finance[is_completed]" value="1" id="finDone"
                                                            <?= ($detail['is_completed'] ?? 0) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="finDone"
                                                            style="font-size:.85rem;">Mark as Completed (sets task to
                                                            Done)</label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-gold btn-sm"><i
                                                            class="fas fa-save me-1"></i>Save Finance Details</button>
                                                </div>
                                            </div>
                                        </form>

                                        <?php /* ─────────── RETAIL EDIT ─────────── */ ?>
                                    <?php elseif ($task['dept_code'] === 'RETAIL'): ?>
                                        <?php
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
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="save_retail" value="1">
                                            <div class="row g-3">
                                                <!-- firm_name: NOT NULL -->
                                                <div class="col-md-6">
                                                    <label class="flabel">Firm Name <span
                                                            class="req-star">*</span><?php if ($task['company_id']): ?><span
                                                                class="badge-linked"><i class="fas fa-link"></i> from
                                                                company</span><?php endif; ?></label>
                                                    <input type="text" name="retail[firm_name]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($cFirmName) ?>" <?= $roCo ?> required>
                                                </div>
                                                <!-- company_type_id: NOT NULL -->
                                                <div class="col-md-3">
                                                    <label class="flabel">Company Type <span
                                                            class="req-star">*</span><?php if ($cCompType): ?><span
                                                                class="badge-linked"><i class="fas fa-link"></i> from
                                                                company</span><?php endif; ?></label>
                                                    <?php if ($cCompType && $task['company_id']): ?>
                                                        <input type="text" class="form-control form-control-sm"
                                                            value="<?= htmlspecialchars($cCompType) ?>" <?= $ro ?>>
                                                        <input type="hidden" name="retail[company_type_id]"
                                                            value="<?= htmlspecialchars((string) $cCompTypeId) ?>">
                                                    <?php else: ?>
                                                        <select name="retail[company_type_id]" class="form-select form-select-sm"
                                                            required>
                                                            <option value="">-- Select --</option>
                                                            <?php foreach ($companyTypes as $ct): ?>
                                                                <option value="<?= $ct['id'] ?>" <?= $cCompTypeId == $ct['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ct['type_name']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php endif; ?>
                                                </div>
                                                <!-- file_type_id: NOT NULL -->
                                                <div class="col-md-3">
                                                    <label class="flabel">File Type <span class="req-star">*</span></label>
                                                    <select name="retail[file_type_id]" class="form-select form-select-sm"
                                                        required>
                                                        <option value="">-- Select --</option>
                                                        <?php foreach ($fileTypes as $ft): ?>
                                                            <option value="<?= $ft['id'] ?>" <?= ($detail['file_type_id'] ?? '') == $ft['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($ft['type_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <!-- pan_vat_id: NOT NULL -->
                                                <div class="col-md-3">
                                                    <label class="flabel">PAN / VAT <span class="req-star">*</span></label>
                                                    <select name="retail[pan_vat_id]" class="form-select form-select-sm"
                                                        required>
                                                        <option value="">-- Select --</option>
                                                        <?php foreach ($panVatTypes as $pv): ?>
                                                            <option value="<?= $pv['id'] ?>" <?= $cPanVatId == $pv['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pv['type_name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <!-- vat_client_id: NOT NULL -->
                                                <div class="col-md-3">
                                                    <label class="flabel">VAT Client <span class="req-star">*</span></label>
                                                    <select name="retail[vat_client_id]" class="form-select form-select-sm"
                                                        required>
                                                        <option value="">-- Select --</option>
                                                        <?php foreach ($yesNoOpts as $yn): ?>
                                                            <option value="<?= $yn['id'] ?>" <?= $cVatClientId == $yn['id'] ? 'selected' : '' ?>><?= htmlspecialchars($yn['value']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="flabel">Return Type
                                                        <?php if ($cReturnType && $task['company_id']): ?><span
                                                                class="badge-linked"><i class="fas fa-link"></i> from
                                                                company</span><?php endif; ?></label>
                                                    <?php if ($cReturnType && $task['company_id']): ?>
                                                        <input type="text" class="form-control form-control-sm"
                                                            value="<?= htmlspecialchars($cReturnType) ?>" <?= $ro ?>>
                                                        <input type="hidden" name="retail[return_type]"
                                                            value="<?= htmlspecialchars($cReturnType) ?>">
                                                    <?php else: ?>
                                                        <select name="retail[return_type]" class="form-select form-select-sm">
                                                            <option value="">--</option>
                                                            <?php foreach (['D1', 'D2', 'D3', 'D4'] as $rt): ?>
                                                                <option value="<?= $rt ?>" <?= ($detail['return_type'] ?? '') === $rt ? 'selected' : '' ?>><?= $rt ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="flabel">Audit Type</label>
                                                    <select name="retail[audit_type_id]" class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($auditTypes2 as $at): ?>
                                                            <option value="<?= $at['id'] ?>" <?= ($detail['audit_type_id'] ?? '') == $at['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($at['type_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="flabel">Fiscal Year</label>
                                                    <?= fiscalYearSelect('retail[fiscal_year]', $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy), $fys) ?>
                                                </div>
                                                <!-- no_of_audit_year: NOT NULL DEFAULT 1 -->
                                                <div class="col-md-3">
                                                    <label class="flabel">No. of Audit Years <span
                                                            class="req-star">*</span></label>
                                                    <input type="number" name="retail[no_of_audit_year]"
                                                        class="form-control form-control-sm" min="1"
                                                        value="<?= htmlspecialchars((string) ($detail['no_of_audit_year'] ?? '1')) ?>"
                                                        required style="border-color:#f59e0b;">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="flabel">PAN No
                                                        <?php if ($cPanNo && $task['company_id']): ?><span
                                                                class="badge-linked"><i class="fas fa-link"></i> from
                                                                company</span><?php endif; ?></label>
                                                    <input type="text" name="retail[pan_no]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($cPanNo) ?>" <?= ($cPanNo && $task['company_id']) ? $ro : '' ?>>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Assigned To <span class="badge-task"><i
                                                                class="fas fa-link"></i> from task</span></label>
                                                    <div class="ro-field"><i class="fas fa-user-circle"
                                                            style="color:#3b82f6;font-size:14px;"></i><?= htmlspecialchars($taskAssignedToName) ?>
                                                    </div>
                                                    <input type="hidden" name="retail[assigned_to]"
                                                        value="<?= $taskAssignedToId ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Assigned Date</label>
                                                    <input type="date" name="retail[assigned_date]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['assigned_date'] ?? date('Y-m-d')) ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">ECD</label>
                                                    <input type="date" name="retail[ecd]" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['ecd'] ?? '') ?>">
                                                </div>
                                                <!-- opening_due: NOT NULL DEFAULT 0 -->
                                                <div class="col-md-4">
                                                    <label class="flabel">Opening Due (Rs.) <span
                                                            class="req-star">*</span></label>
                                                    <input type="number" step="0.01" name="retail[opening_due]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars((string) ($detail['opening_due'] ?? '0')) ?>"
                                                        required>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Work Status</label>
                                                    <select name="retail[work_status_id]" class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($taskStatuses as $ts): ?>
                                                            <option value="<?= $ts['id'] ?>" <?= ($detail['work_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($ts['status_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Finalisation Status</label>
                                                    <select name="retail[finalisation_status_id]"
                                                        class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($taskStatuses as $ts): ?>
                                                            <option value="<?= $ts['id'] ?>" <?= ($detail['finalisation_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($ts['status_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Finalised By</label>
                                                    <select name="retail[finalised_by]" class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($allStaff as $s): ?>
                                                            <option value="<?= $s['id'] ?>" <?= ($detail['finalised_by'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($s['full_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Completed Date</label>
                                                    <input type="date" name="retail[completed_date]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['completed_date'] ?? '') ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Tax Clearance Status</label>
                                                    <select name="retail[tax_clearance_status_id]"
                                                        class="form-select form-select-sm">
                                                        <option value="">--</option>
                                                        <?php foreach ($taskStatuses as $ts): ?>
                                                            <option value="<?= $ts['id'] ?>" <?= ($detail['tax_clearance_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($ts['status_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Backup Status</label>
                                                    <select name="retail[backup_status_id]" class="form-select form-select-sm">
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
                                                    <label class="flabel">Follow-up Date</label>
                                                    <input type="date" name="corporate[follow_up_date]"
                                                        class="form-control form-control-sm" value="">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Follow-up Note <span
                                                            style="font-size:.63rem;color:#9ca3af;">(optional)</span></label>
                                                    <input type="text" name="corporate[follow_up_note]"
                                                        class="form-control form-control-sm" placeholder="e.g. Called client…">
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-gold btn-sm"><i
                                                            class="fas fa-save me-1"></i>Save Retail Details</button>
                                                </div>
                                            </div>
                                        </form>

                                        <?php /* ─────────── CORPORATE EDIT ─────────── */ ?>
                                    <?php elseif ($task['dept_code'] === 'CORP'): ?>
                                        <?php
                                        $isLinked = !empty($task['company_id']);
                                        $cf_firm = $detail['firm_name'] ?? $task['company_name'] ?? '';
                                        $cf_pan = $detail['pan_no'] ?? ($companyData['pan_number'] ?? '');
                                        $cf_grade = $detail['grade_id'] ?? '';
                                        $cf_fy = $detail['fiscal_year_id'] ?? '';
                                        $cf_fb = $detail['finalised_by'] ?? '';
                                        $cf_cd = $detail['completed_date'] ?? '';
                                        $cf_remarks = $detail['remarks'] ?? '';
                                        $cCompTypeId = $detail['company_type_id'] ?? ($companyData['company_type_id_val'] ?? '');
                                        $cCompType = $detail['company_type_name'] ?? ($companyData['company_type_name'] ?? '');
                                        $cReturnType = $detail['return_type'] ?? ($companyData['return_type'] ?? '');
                                        $cPanVatId = $detail['pan_vat_id'] ?? '';
                                        $cVatClientId = $detail['vat_client_id'] ?? '';
                                        $firmRo = $isLinked ? 'readonly style="background:#f0fdf4;color:#374151;font-weight:500;cursor:not-allowed;"' : '';
                                        $panRo = ($isLinked && !empty($companyData['pan_number'])) ? 'readonly style="background:#f0fdf4;color:#374151;font-weight:500;cursor:not-allowed;"' : '';
                                        ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="save_corporate" value="1">
                                            <div class="row g-3">

                                                <!-- firm_name -->
                                                <div class="col-md-6">
                                                    <label class="flabel">Firm Name
                                                        <?php if ($isLinked): ?><span class="badge-linked"><i
                                                                    class="fas fa-link"></i> from company</span><?php endif; ?>
                                                    </label>
                                                    <input type="text" name="corporate[firm_name]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($cf_firm) ?>" <?= $firmRo ?>>
                                                </div>

                                                <!-- pan_no -->
                                                <div class="col-md-3">
                                                    <label class="flabel">PAN No
                                                        <?php if ($isLinked && !empty($companyData['pan_number'])): ?><span
                                                                class="badge-linked"><i class="fas fa-link"></i> from
                                                                company</span><?php endif; ?>
                                                    </label>
                                                    <input type="text" name="corporate[pan_no]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($cf_pan) ?>" <?= $panRo ?>>
                                                </div>

                                                <!-- grade_id -->
                                                <div class="col-md-3">
                                                    <label class="flabel">Grade</label>
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
                                                    <label class="flabel">Company Type
                                                        <?php if ($cCompType && $isLinked): ?><span class="badge-linked"><i
                                                                    class="fas fa-link"></i> from company</span><?php endif; ?>
                                                    </label>
                                                    <?php if ($cCompType && $isLinked): ?>
                                                        <input type="text" class="form-control form-control-sm"
                                                            value="<?= htmlspecialchars($cCompType) ?>" <?= $firmRo ?>>
                                                        <input type="hidden" name="corporate[company_type_id]"
                                                            value="<?= htmlspecialchars((string) $cCompTypeId) ?>">
                                                    <?php else: ?>
                                                        <select name="corporate[company_type_id]"
                                                            class="form-select form-select-sm">
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
                                                    <label class="flabel">File Type</label>
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
                                                    <label class="flabel">PAN / VAT</label>
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
                                                    <label class="flabel">VAT Client</label>
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
                                                    <label class="flabel">Return Type
                                                        <?php if ($cReturnType && $isLinked): ?><span class="badge-linked"><i
                                                                    class="fas fa-link"></i> from company</span><?php endif; ?>
                                                    </label>
                                                    <?php if ($cReturnType && $isLinked): ?>
                                                        <input type="text" class="form-control form-control-sm"
                                                            value="<?= htmlspecialchars($cReturnType) ?>" <?= $firmRo ?>>
                                                        <input type="hidden" name="corporate[return_type]"
                                                            value="<?= htmlspecialchars($cReturnType) ?>">
                                                    <?php else: ?>
                                                        <select name="corporate[return_type]" class="form-select form-select-sm">
                                                            <option value="">--</option>
                                                            <?php foreach (['D1', 'D2', 'D3', 'D4'] as $rt): ?>
                                                                <option value="<?= $rt ?>" <?= ($detail['return_type'] ?? '') === $rt ? 'selected' : '' ?>><?= $rt ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- audit_type_id -->
                                                <div class="col-md-3">
                                                    <label class="flabel">Audit Type</label>
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
                                                    <label class="flabel">Fiscal Year</label>
                                                    <select name="corporate[fiscal_year_id]" class="form-select form-select-sm">
                                                        <option value="">-- Select FY --</option>
                                                        <?php foreach ($fys as $fy): ?>
                                                            <option value="<?= $fy['id'] ?>" <?= $cf_fy == $fy['id'] ? 'selected' : '' ?>             <?= $fy['is_current'] ? 'style="font-weight:700;color:#16a34a;"' : '' ?>>
                                                                <?= htmlspecialchars($fy['fy_label'] ?: $fy['fy_code']) ?>
                                                                <?= $fy['is_current'] ? ' ★' : '' ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <!-- no_of_audit_year -->
                                                <div class="col-md-3">
                                                    <label class="flabel">No. of Audit Years</label>
                                                    <input type="number" name="corporate[no_of_audit_year]"
                                                        class="form-control form-control-sm" min="1"
                                                        value="<?= htmlspecialchars((string) ($detail['no_of_audit_year'] ?? '1')) ?>">
                                                </div>

                                                <!-- assigned_to — from task, read-only -->
                                                <div class="col-md-4">
                                                    <label class="flabel">Assigned To
                                                        <span class="badge-task"><i class="fas fa-link"></i> from task</span>
                                                    </label>
                                                    <div class="ro-field" style="background:#eff6ff;color:#1d4ed8;">
                                                        <i class="fas fa-user-circle" style="font-size:14px;"></i>
                                                        <?= htmlspecialchars($taskAssignedToName) ?>
                                                    </div>
                                                    <input type="hidden" name="corporate[assigned_to]"
                                                        value="<?= htmlspecialchars((string) ($taskAssignedToId ?? '')) ?>">
                                                </div>

                                                <!-- ecd -->
                                                <div class="col-md-4">
                                                    <label class="flabel">ECD</label>
                                                    <input type="date" name="corporate[ecd]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['ecd'] ?? '') ?>">
                                                </div>

                                                <!-- opening_due -->
                                                <div class="col-md-4">
                                                    <label class="flabel">Opening Due (Rs.)</label>
                                                    <input type="number" step="0.01" name="corporate[opening_due]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars((string) ($detail['opening_due'] ?? '0')) ?>">
                                                </div>

                                                <!-- finalisation_status_id -->
                                                <div class="col-md-4">
                                                    <label class="flabel">Finalisation Status</label>
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
                                                    <label class="flabel">Finalised By</label>
                                                    <select name="corporate[finalised_by]" id="corp_finalised_by"
                                                        class="form-select form-select-sm">
                                                        <option value="">-- Search --</option>
                                                        <?php foreach ($allStaff as $s): ?>
                                                            <option value="<?= $s['id'] ?>" <?= $cf_fb == $s['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($s['full_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <!-- completed_date -->
                                                <div class="col-md-4">
                                                    <label class="flabel">Completed Date</label>
                                                    <input type="date" name="corporate[completed_date]"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($cf_cd) ?>">
                                                </div>

                                                <!-- tax_clearance_status_id -->
                                                <div class="col-md-4">
                                                    <label class="flabel">Tax Clearance Status</label>
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
                                                    <label class="flabel">Backup Status</label>
                                                    <select name="corporate[backup_status_id]"
                                                        class="form-select form-select-sm">
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
                                                    <label class="flabel">Follow-up Date</label>
                                                    <input type="date" name="corporate[follow_up_date]"
                                                        class="form-control form-control-sm" value="">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="flabel">Follow-up Note <span
                                                            style="font-size:.63rem;color:#9ca3af;">(optional)</span></label>
                                                    <input type="text" name="corporate[follow_up_note]"
                                                        class="form-control form-control-sm" placeholder="e.g. Called client…">
                                                </div>

                                                <!-- notes -->
                                                <div class="col-12">
                                                    <label class="flabel">Notes</label>
                                                    <textarea name="corporate[notes]" class="form-control form-control-sm"
                                                        rows="2"><?= htmlspecialchars($detail['notes'] ?? '') ?></textarea>
                                                </div>

                                                <!-- remarks -->
                                                <div class="col-12">
                                                    <label class="flabel">Remarks</label>
                                                    <textarea name="corporate[remarks]" class="form-control form-control-sm"
                                                        rows="2"><?= htmlspecialchars($cf_remarks) ?></textarea>
                                                </div>

                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-gold btn-sm">
                                                        <i class="fas fa-save me-1"></i>Save Corporate Details
                                                    </button>
                                                </div>

                                            </div>
                                        </form>
                                    <?php endif; /* dept edit switch */ ?>
                                </div><!-- #editSection -->
                            </div><!-- tv-card-body -->
                        </div><!-- dept card -->
                    <?php endif; /* detailTable */ ?>

                    <!-- ── Comments ──────────────────────────────────────── -->
                    <div class="tv-card" id="comments">
                        <div class="tv-card-head">
                            <h5><i class="fas fa-comments me-2" style="color:var(--gold)"></i>Comments
                                (<?= count($comments) ?>)</h5>
                        </div>
                        <div class="tv-card-body">
                            <?php if (empty($comments)): ?>
                                <div class="text-center py-3 text-muted" style="font-size:.85rem;">No comments yet. Be the
                                    first!</div>
                            <?php endif; ?>
                            <?php foreach ($comments as $c): ?>
                                <div class="d-flex gap-3 mb-3">
                                    <div class="av av-sm flex-shrink-0">
                                        <?= strtoupper(substr($c['full_name'] ?? '?', 0, 2)) ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex gap-2 align-items-center mb-1">
                                            <strong
                                                style="font-size:.85rem;"><?= htmlspecialchars($c['full_name']) ?></strong>
                                            <span
                                                style="font-size:.71rem;color:#9ca3af;"><?= date('M j, Y H:i', strtotime($c['created_at'])) ?></span>
                                        </div>
                                        <div class="c-bubble"><?= nl2br(htmlspecialchars($c['comment'])) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <form method="POST" class="mt-3 d-flex gap-2">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="add_comment" value="1">
                                <input type="text" name="comment" class="form-control" placeholder="Add a comment…"
                                    required>
                                <button type="submit" class="btn btn-gold btn-sm flex-shrink-0">Post</button>
                            </form>
                        </div>
                    </div>

                    <!-- ── Workflow History ──────────────────────────────── -->
                    <?php if (!empty($workflow)): ?>
                        <div class="tv-card">
                            <div class="tv-card-head">
                                <h5><i class="fas fa-history me-2" style="color:var(--gold)"></i>Workflow History</h5>
                            </div>
                            <div class="tv-card-body">
                                <?php foreach ($workflow as $w): ?>
                                    <div class="d-flex gap-3 mb-3">
                                        <div class="wf-icon">
                                            <i class="fas fa-<?= match ($w['action'] ?? '') {
                                                'created' => 'plus',
                                                'assigned' => 'user-check',
                                                'status_changed' => 'circle-dot',
                                                'transferred_dept' => 'exchange-alt',
                                                'transferred_staff' => 'user-arrows',
                                                'completed' => 'check-circle',
                                                default => 'pen',
                                            } ?>"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div style="font-size:.82rem;font-weight:600;color:#1f2937;">
                                                <?= ucwords(str_replace('_', ' ', $w['action'] ?? '')) ?>
                                                <?php if ($w['from_name']): ?> by
                                                    <?= htmlspecialchars($w['from_name']) ?>         <?php endif; ?>
                                                <?php if ($w['to_name']): ?> →
                                                    <?= htmlspecialchars($w['to_name']) ?>         <?php endif; ?>
                                            </div>
                                            <?php if ($w['from_dept'] || $w['to_dept']): ?>
                                                <div style="font-size:.74rem;color:#8b5cf6;">
                                                    <?= htmlspecialchars($w['from_dept'] ?? '') ?>
                                                    <?= ($w['from_dept'] && $w['to_dept']) ? ' → ' : '' ?>
                                                    <?= htmlspecialchars($w['to_dept'] ?? '') ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($w['old_status'] || $w['new_status']): ?>
                                                <div style="font-size:.74rem;color:#9ca3af;">
                                                    <?= htmlspecialchars($w['old_status'] ?? '') ?>
                                                    <?= ($w['old_status'] && $w['new_status']) ? ' → ' : '' ?>
                                                    <?= htmlspecialchars($w['new_status'] ?? '') ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($w['remarks'] ?? ''): ?>
                                                <div style="font-size:.77rem;color:#6b7280;font-style:italic;">
                                                    "<?= htmlspecialchars($w['remarks']) ?>"</div>
                                            <?php endif; ?>
                                            <div style="font-size:.69rem;color:#9ca3af;margin-top:.15rem;">
                                                <?= date('d M Y, H:i', strtotime($w['created_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div><!-- col-lg-8 -->

                <!-- ═══════════════ RIGHT COLUMN ═══════════════════════════ -->
                <div class="col-lg-4">

                    <!-- Status Update -->
                    <div class="tv-card">
                        <div class="tv-card-head">
                            <h5><i class="fas fa-circle-dot me-2" style="color:var(--gold)"></i>Update Status</h5>
                        </div>
                        <div class="tv-card-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="update_status" value="1">
                                <div class="mb-3">
                                    <?php foreach ($taskStatuses as $ts):
                                        $sKey = $ts['status_name'];
                                        $sCol = $ts['color'] ?? '#9ca3af';
                                        $sBg = $ts['bg_color'] ?? '#f3f4f6';
                                        ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="new_status"
                                                value="<?= htmlspecialchars($sKey) ?>" id="st_<?= $ts['id'] ?>"
                                                <?= ($task['status'] ?? '') === $sKey ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="st_<?= $ts['id'] ?>">
                                                <span class="s-pill"
                                                    style="background:<?= $sBg ?>;color:<?= $sCol ?>;"><?= htmlspecialchars($sKey) ?></span>
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
                    <div class="tv-card p-3"
                        style="font-size:.8rem;color:#6b7280;border-left:3px solid var(--gold);border-radius:0 12px 12px 0;">
                        <div
                            style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.6rem;">
                            Task Info</div>
                        <?php foreach ([
                            'Task #' => htmlspecialchars($task['task_number']),
                            'Department' => htmlspecialchars($task['dept_name'] ?? '—'),
                            'Branch' => htmlspecialchars($task['branch_name'] ?? '—'),
                            'Priority' => ucfirst($task['priority'] ?? '—'),
                            'Fiscal Year' => htmlspecialchars($task['fiscal_year'] ?? '—'),
                            'Created' => date('d M Y, H:i', strtotime($task['created_at'])),
                            'Updated' => date('d M Y, H:i', strtotime($task['updated_at'])),
                        ] as $k => $v): ?>
                            <div class="mb-2 d-flex justify-content-between">
                                <span style="color:#9ca3af;"><?= $k ?>:</span>
                                <span style="color:#374151;font-weight:600;text-align:right;max-width:65%;"><?= $v ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Follow-up History -->
                    <?php if (!empty($followupHistory)): ?>
                        <div class="tv-card mt-3">
                            <div class="tv-card-head">
                                <h5><i class="fas fa-clock-rotate-left me-2" style="color:var(--gold)"></i>Follow-up History
                                </h5>
                                <span
                                    style="background:#f59e0b;color:white;padding:.15rem .5rem;border-radius:99px;font-size:.68rem;font-weight:700;">
                                    <?= count($followupHistory) ?>
                                </span>
                            </div>
                            <div class="tv-card-body" style="max-height:320px;overflow-y:auto;">
                                <?php foreach ($followupHistory as $i => $fu): ?>
                                    <div class="d-flex gap-2 mb-3">
                                        <div style="width:22px;height:22px;border-radius:50%;background:#f59e0b;color:white;
                                                    display:flex;align-items:center;justify-content:center;
                                                    font-size:.65rem;font-weight:700;flex-shrink:0;">
                                            <?= $i + 1 ?>
                                        </div>
                                        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;
                                                    padding:.45rem .7rem;flex:1;">
                                            <div style="font-size:.8rem;font-weight:700;color:#92400e;">
                                                <?= date('d M Y', strtotime($fu['followup_date'])) ?>
                                                <span style="font-weight:400;color:#9ca3af;font-size:.7rem;">
                                                    by <?= htmlspecialchars($fu['added_by_name']) ?>
                                                </span>
                                            </div>
                                            <?php if ($fu['notes']): ?>
                                                <div style="font-size:.75rem;color:#6b7280;margin-top:.15rem;font-style:italic;">
                                                    "<?= htmlspecialchars($fu['notes']) ?>"
                                                </div>
                                            <?php endif; ?>
                                            <div style="font-size:.68rem;color:#9ca3af;margin-top:.1rem;">
                                                <?= date('d M Y, H:i', strtotime($fu['created_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div><!-- col-lg-4 -->
            </div><!-- row -->
        </div><!-- tv-wrap -->
    </div>
</div>

<script>
    /* ── View / Edit mode toggle ─────────────────────────────────────────── */
    function switchMode(mode) {
        const viewSec = document.getElementById('viewSection');
        const editSec = document.getElementById('editSection');
        const btnView = document.getElementById('btnView');
        const btnEdit = document.getElementById('btnEdit');
        if (mode === 'edit') {
            viewSec.style.display = 'none';
            editSec.style.display = 'block';
            btnView.className = 've-btn inactive';
            btnEdit.className = 've-btn active-edit';
        } else {
            viewSec.style.display = 'block';
            editSec.style.display = 'none';
            btnEdit.className = 've-btn inactive';
            btnView.className = 've-btn active-view';
        }
    }

    /* Auto-open edit mode via URL param */
    <?php if (isset($_GET['mode']) && $_GET['mode'] === 'edit'): ?>
        document.addEventListener('DOMContentLoaded', () => switchMode('edit'));
    <?php endif; ?>

    /* Auto-fill office address from select */
    function fillOfficeAddr(sel) {
        const opt = sel.options[sel.selectedIndex];
        const addr = opt ? opt.getAttribute('data-addr') : '';
        const fld = document.getElementById('officeAddr');
        if (fld && addr && !fld.value) fld.value = addr;
    }

    /* Finance: live due amount recalculation */
    document.addEventListener('DOMContentLoaded', function () {
        const total = document.getElementById('fin_total_amount');
        const paid = document.getElementById('fin_paid_amount');
        const due = document.getElementById('fin_due');
        function calcDue() {
            if (!total || !paid || !due) return;
            const d = (parseFloat(total.value) || 0) - (parseFloat(paid.value) || 0);
            due.value = 'Rs. ' + d.toFixed(2);
        }
        if (total) total.addEventListener('input', calcDue);
        if (paid) paid.addEventListener('input', calcDue);
    });
</script>
<?php include '../../includes/footer.php'; ?>