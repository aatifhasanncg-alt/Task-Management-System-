<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helper.php';
// Ensure fiscal_year_helper functions are available
if (!function_exists('getFiscalYearId')) {
    require_once __DIR__ . '/../../config/fiscal_year_helper.php';
}
// Inline fallback if helper still not available
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
        $html = '<select name="' . htmlspecialchars($name) . '" class="' . $class . '"' . $req . ">
";
        $html .= '    <option value="">-- Select FY --</option>' . "
";
        foreach ($fys as $fy) {
            $isSel = ((string) $selected === (string) $fy['fy_code']);
            $sel = $isSel ? ' selected' : '';
            $star = $fy['is_current'] ? ' ★ Current' : '';
            $lbl = htmlspecialchars($fy['fy_label'] ?: $fy['fy_code']);
            $val = htmlspecialchars($fy['fy_code']);
            $style = $fy['is_current'] ? ' style="font-weight:700;color:#16a34a;"' : '';
            $html .= '    <option value="' . $val . '"' . $sel . $style . '>' . $lbl . $star . '</option>' . "
";
        }
        $html .= '</select>';
        return $html;
    }
}
requireAdmin();

$db = getDB();
$user = currentUser();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}

// ── Fiscal years — always from fiscal_years DB table ─────────────────────────
$fys = [];
try {
    $fys = $db->query("
        SELECT id, fy_code, fy_label, is_current
        FROM fiscal_years
        WHERE is_active = 1
        ORDER BY fy_code DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("view.php: fiscal_years query failed: " . $e->getMessage());
    // Fallback to constant if table missing
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

$adminStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$adminStmt->execute([$user['id']]);
$adminUser = $adminStmt->fetch();

$adminDeptStmt = $db->prepare("SELECT dept_code FROM departments WHERE id = ?");
$adminDeptStmt->execute([$adminUser['department_id'] ?? 0]);
$adminDeptCode = $adminDeptStmt->fetchColumn() ?: '';

$taskStmt = $db->prepare("
    SELECT t.*,
           d.dept_name, d.dept_code, d.color, d.icon AS dept_icon,
           b.branch_name,
           c.company_name,
           COALESCE(ts.status_name,'Pending') AS status,
           cb.full_name AS assigned_by_name,
           asgn.full_name AS assigned_to_name,
           asgn.email     AS assigned_to_email
    FROM tasks t
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

$canEditDept = ($adminDeptCode !== '' && $adminDeptCode === $task['dept_code']);

// ── Auto-add missing columns ──────────────────────────────────────────────────
$autoAlters = [
    ['task_tax', 'fiscal_year', "ALTER TABLE task_tax ADD COLUMN fiscal_year VARCHAR(10) NULL AFTER tax_type_id"],
    ['task_tax', 'fiscal_year_id', "ALTER TABLE task_tax ADD COLUMN fiscal_year_id INT NULL AFTER fiscal_year"],
    ['task_retail', 'fiscal_year_id', "ALTER TABLE task_retail ADD COLUMN fiscal_year_id INT NULL AFTER fiscal_year"],
    ['task_finance', 'fiscal_year_id', "ALTER TABLE task_finance ADD COLUMN fiscal_year_id INT NULL AFTER fiscal_year"],
    ['task_banking', 'fiscal_year', "ALTER TABLE task_banking ADD COLUMN fiscal_year VARCHAR(10) NULL AFTER completion_date"],
    ['task_banking', 'fiscal_year_id', "ALTER TABLE task_banking ADD COLUMN fiscal_year_id INT NULL AFTER fiscal_year"],
    ['task_corporate', 'fiscal_year', "ALTER TABLE task_corporate ADD COLUMN fiscal_year VARCHAR(10) NULL AFTER pan_no"],
    ['task_corporate', 'fiscal_year_id', "ALTER TABLE task_corporate ADD COLUMN fiscal_year_id INT NULL AFTER fiscal_year"],
];
foreach ($autoAlters as [$tbl, $col, $sql]) {
    try {
        $db->query("SELECT `{$col}` FROM `{$tbl}` LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec($sql);
        } catch (Exception $e2) {
        }
    }
}

// ── Load dept detail ──────────────────────────────────────────────────────────
$detailTableMap = ['RETAIL' => 'task_retail', 'TAX' => 'task_tax', 'BANK' => 'task_banking', 'CORP' => 'task_corporate', 'FIN' => 'task_finance'];
$detailTable = $detailTableMap[$task['dept_code']] ?? null;
$detail = null;

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

            case 'CORP':
                try {
                    $dSt = $db->prepare("
                        SELECT tc.*,
                               cg.grade_name AS grade_name,
                               au.full_name  AS assigned_to_name,
                               fb.full_name  AS finalised_by_name,
                               fy.fy_label   AS fiscal_year_label
                        FROM task_corporate tc
                        LEFT JOIN corporate_grades cg ON cg.id = tc.grade_id
                        LEFT JOIN users            au ON au.id = tc.assigned_to
                        LEFT JOIN users            fb ON fb.id = tc.finalised_by
                        LEFT JOIN fiscal_years     fy ON fy.id = tc.fiscal_year_id
                        WHERE tc.task_id = ?");
                    $dSt->execute([$id]);
                    $detail = $dSt->fetch();
                } catch (Exception $e) {
                    $detail = null;
                }
                break;

            case 'TAX':
                $dSt = $db->prepare("
                    SELECT tt.*,
                           tot.office_name   AS assigned_office_name,
                           tot.address       AS assigned_office_default_address,
                           tyt.tax_type_name AS tax_type_name,
                           ts2.status_name   AS status_name,
                           tcs.status_name   AS tax_clearance_status_name,
                           au.full_name AS assigned_to_name,   fr.full_name AS file_received_by_name,
                           ub.full_name AS updated_by_name,    vb.full_name AS verify_by_name
                    FROM task_tax tt
                    LEFT JOIN tax_office_types tot ON tot.id = tt.assigned_office_id
                    LEFT JOIN tax_type tyt          ON tyt.id = tt.tax_type_id
                    LEFT JOIN task_status ts2       ON ts2.id = tt.status_id
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
                           br.bank_name, a.auditor_name, a.firm_name AS auditor_firm, a.phone AS auditor_phone,
                           bcc.category_name AS client_category_name, ws.status_name AS work_status_name,
                           c.company_name, c.contact_person, c.contact_phone,
                           c.pan_number AS company_pan, ct.type_name AS company_type_name
                    FROM task_banking tb
                    LEFT JOIN bank_references br         ON br.id=tb.bank_reference_id
                    LEFT JOIN auditors a                 ON a.id=tb.auditor_id
                    LEFT JOIN bank_client_categories bcc ON bcc.id=tb.client_category_id
                    LEFT JOIN task_status ws             ON ws.id=tb.work_status_id
                    LEFT JOIN companies c                ON c.id=tb.company_id
                    LEFT JOIN company_types ct           ON ct.id=c.company_type_id
                    WHERE tb.task_id = ?");
                $dSt->execute([$id]);
                $detail = $dSt->fetch();
                break;

            case 'FIN':
                $dSt = $db->prepare("
                    SELECT tf.*,
                           fst.service_name AS service_type_name,
                           ps.status_name   AS payment_status_name,
                           tcs.status_name  AS tax_clearance_status_name
                    FROM task_finance tf
                    LEFT JOIN finance_service_types fst ON fst.id=tf.service_type_id
                    LEFT JOIN task_status ps            ON ps.id=tf.payment_status_id
                    LEFT JOIN task_status tcs           ON tcs.id=tf.tax_clearance_status_id
                    WHERE tf.task_id = ?");
                $dSt->execute([$id]);
                $detail = $dSt->fetch();
                break;
        }
    } catch (Exception $e) {
        $detail = null;
    }
}

// ── Lookups ───────────────────────────────────────────────────────────────────
$taskStatuses = $db->query("SELECT id, status_name FROM task_status ORDER BY id")->fetchAll();
$yesNo = $db->query("SELECT id, value FROM yes_no ORDER BY id")->fetchAll();
$allStaff = $db->query("
    SELECT u.id, u.full_name FROM users u
    LEFT JOIN departments d ON d.id=u.department_id
    JOIN roles r ON r.id=u.role_id
    WHERE r.role_name IN ('staff','admin') AND u.is_active=1
      AND (d.dept_code IS NULL OR d.dept_code != 'CORE')
    ORDER BY r.role_name, u.full_name")->fetchAll();

// ── Company data ──────────────────────────────────────────────────────────────
$companyData = null;
if ($task['company_id']) {
    $cpStmt = $db->prepare("
        SELECT c.*,
               ct.type_name AS company_type_name,
               ct.id        AS company_type_id_val,
               pv.type_name AS pan_vat_name,
               pv.id        AS pan_vat_id_val,
               yn.value     AS vat_client_value,
               yn.id        AS vat_client_id_val
        FROM companies c
        LEFT JOIN company_types ct ON ct.id = c.company_type_id
        LEFT JOIN pan_vat_types pv ON pv.type_name = IF(c.pan_number LIKE 'VAT%','VAT','PAN')
        LEFT JOIN yes_no        yn ON yn.value = 'No'
        WHERE c.id = ?");
    $cpStmt->execute([$task['company_id']]);
    $companyData = $cpStmt->fetch();
}
$companyPanRow = $companyData ? ['pan_number' => $companyData['pan_number'], 'company_name' => $companyData['company_name']] : null;
$companyTypeVal = $companyData['company_type_name'] ?? '';

// ── Assigned-to locked in all dept forms ─────────────────────────────────────
$taskAssignedToId = $task['assigned_to'] ?? null;
$taskAssignedToName = $task['assigned_to_name'] ?? '—';

// ── Comments & Workflow ───────────────────────────────────────────────────────
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

// ── Extra lookups for editing ─────────────────────────────────────────────────
$taxOfficeTypes = $taxTypes = $financeServiceTypes = $allBanks = $allCats = $allAuditors = [];
$companyTypes = $fileTypes = $panVatTypes = $yesNoOpts = $auditTypes2 = $corpGrades = [];
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
        $financeServiceTypes = $db->query("SELECT id,service_name FROM finance_service_types ORDER BY service_name")->fetchAll();
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
}

// ═══════════════════════════════════════════════════════════════════════════════
// POST HANDLERS
// ═══════════════════════════════════════════════════════════════════════════════

// POST: update_status
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
        // Build rich notification message
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
            ],
        ];
        // Notify assigned staff (skip if they are making the change)
        if (!empty($task['assigned_to']) && $task['assigned_to'] != $user['id']) {
            notify((int) $task['assigned_to'], "Status Updated: {$task['task_number']}", $statusMsg, 'status', $taskLink, true, $emailData);
        }
        // Notify task creator/admin (skip if same as current user or same as assigned staff)
        if (!empty($task['created_by']) && $task['created_by'] != $user['id'] && $task['created_by'] != $task['assigned_to']) {
            notify((int) $task['created_by'], "Status Updated: {$task['task_number']}", $statusMsg, 'status', $adminLink, true, $emailData);
        }
        logActivity("Status: {$task['task_number']} → {$newStatus}", 'tasks');
        setFlash('success', 'Status updated.');
        header("Location: view.php?id={$id}");
        exit;
    }
}

// POST: update_progress
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_progress']) && $canEditDept) {
    verifyCsrf();
    $p = $_POST['progress'] ?? [];
    $db->prepare("UPDATE task_retail SET work_status_id=?,finalisation_status_id=?,finalised_by=?,completed_date=?,tax_clearance_status_id=?,backup_status_id=?,follow_up_date=?,notes=? WHERE task_id=?")
        ->execute([($p['work_status_id'] ?? '') !== '' ? (int) $p['work_status_id'] : null, ($p['finalisation_status_id'] ?? '') !== '' ? (int) $p['finalisation_status_id'] : null, ($p['finalised_by'] ?? '') !== '' ? (int) $p['finalised_by'] : null, $p['completed_date'] ?: null, ($p['tax_clearance_status_id'] ?? '') !== '' ? (int) $p['tax_clearance_status_id'] : null, ($p['backup_status_id'] ?? '') !== '' ? (int) $p['backup_status_id'] : null, $p['follow_up_date'] ?: null, $p['notes'] ?: null, $id]);
    setFlash('success', 'Progress updated.');
    header("Location: view.php?id={$id}");
    exit;
}

function parseDate($d)
{
    if (empty($d))
        return null;
    $x = date_create($d);
    return $x ? date_format($x, 'Y-m-d') : null;
}

// POST: save_tax
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tax']) && $canEditDept) {
    verifyCsrf();
    $t2 = $_POST['tax'] ?? [];
    $firm = trim($t2['firm_name'] ?? '') ?: ($task['company_name'] ?? '');
    $biz = trim($t2['business_type'] ?? '') ?: $companyTypeVal;
    $pan = trim($t2['pan_number'] ?? '') ?: ($companyPanRow['pan_number'] ?? '');
    $officeAddr = trim($t2['assigned_office_address'] ?? '');
    $taxFy = trim($t2['fiscal_year'] ?? '');
    $taxFyId = getFiscalYearId($db, $taxFy);
    // Ensure columns exist before saving (handles fresh installs)
    try {
        $db->query("SELECT fiscal_year FROM task_tax LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE task_tax ADD COLUMN fiscal_year VARCHAR(10) NULL AFTER tax_type_id");
        } catch (Exception $e2) {
        }
    }
    try {
        $db->query("SELECT fiscal_year_id FROM task_tax LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE task_tax ADD COLUMN fiscal_year_id INT NULL AFTER fiscal_year");
        } catch (Exception $e2) {
        }
    }
    try {
        $db->query("SELECT assigned_office_address FROM task_tax LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE task_tax ADD COLUMN assigned_office_address VARCHAR(200) NULL AFTER assigned_office_id");
        } catch (Exception $e2) {
        }
    }
    $hasAddrCol = true;
    try {
        $db->query("SELECT assigned_office_address FROM task_tax LIMIT 1");
    } catch (Exception $e) {
        $hasAddrCol = false;
    }

    $ex = $db->prepare("SELECT id FROM task_tax WHERE task_id=?");
    $ex->execute([$id]);
    // Build param array — with or without address col
    if ($hasAddrCol) {
        $p = [$task['company_id'], $firm, ($t2['assigned_office_id'] ?? '') !== '' ? (int) $t2['assigned_office_id'] : null, $officeAddr ?: null, ($t2['tax_type_id'] ?? '') !== '' ? (int) $t2['tax_type_id'] : null, $taxFy, $taxFyId, trim($t2['submission_number'] ?? ''), trim($t2['udin_no'] ?? ''), $biz, $pan, ($t2['assigned_to'] ?? '') !== '' ? (int) $t2['assigned_to'] : null, ($t2['file_received_by'] ?? '') !== '' ? (int) $t2['file_received_by'] : null, ($t2['updated_by'] ?? '') !== '' ? (int) $t2['updated_by'] : null, ($t2['verify_by'] ?? '') !== '' ? (int) $t2['verify_by'] : null, ($t2['status_id'] ?? '') !== '' ? (int) $t2['status_id'] : null, ($t2['tax_clearance_status_id'] ?? '') !== '' ? (int) $t2['tax_clearance_status_id'] : null, ($t2['bills_issued'] ?? '') !== '' ? (float) $t2['bills_issued'] : 0, ($t2['fee_received'] ?? '') !== '' ? (float) $t2['fee_received'] : 0, ($t2['tds_payment'] ?? '') !== '' ? (float) $t2['tds_payment'] : 0, $t2['assigned_date'] ?: null, $t2['completed_date'] ?: null, $t2['follow_up_date'] ?: null, trim($t2['remarks'] ?? ''), trim($t2['notes'] ?? '')];
        if ($ex->fetch()) {
            $db->prepare("UPDATE task_tax SET company_id=?,firm_name=?,assigned_office_id=?,assigned_office_address=?,tax_type_id=?,fiscal_year=?,fiscal_year_id=?,submission_number=?,udin_no=?,business_type=?,pan_number=?,assigned_to=?,file_received_by=?,updated_by=?,verify_by=?,status_id=?,tax_clearance_status_id=?,bills_issued=?,fee_received=?,tds_payment=?,assigned_date=?,completed_date=?,follow_up_date=?,remarks=?,notes=? WHERE task_id=?")->execute(array_merge($p, [$id]));
        } else {
            $db->prepare("INSERT INTO task_tax(task_id,company_id,firm_name,assigned_office_id,assigned_office_address,tax_type_id,fiscal_year,fiscal_year_id,submission_number,udin_no,business_type,pan_number,assigned_to,file_received_by,updated_by,verify_by,status_id,tax_clearance_status_id,bills_issued,fee_received,tds_payment,assigned_date,completed_date,follow_up_date,remarks,notes)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$id], $p));
        }
    } else {
        $p = [$task['company_id'], $firm, ($t2['assigned_office_id'] ?? '') !== '' ? (int) $t2['assigned_office_id'] : null, ($t2['tax_type_id'] ?? '') !== '' ? (int) $t2['tax_type_id'] : null, $taxFy, $taxFyId, trim($t2['submission_number'] ?? ''), trim($t2['udin_no'] ?? ''), $biz, $pan, ($t2['assigned_to'] ?? '') !== '' ? (int) $t2['assigned_to'] : null, ($t2['file_received_by'] ?? '') !== '' ? (int) $t2['file_received_by'] : null, ($t2['updated_by'] ?? '') !== '' ? (int) $t2['updated_by'] : null, ($t2['verify_by'] ?? '') !== '' ? (int) $t2['verify_by'] : null, ($t2['status_id'] ?? '') !== '' ? (int) $t2['status_id'] : null, ($t2['tax_clearance_status_id'] ?? '') !== '' ? (int) $t2['tax_clearance_status_id'] : null, ($t2['bills_issued'] ?? '') !== '' ? (float) $t2['bills_issued'] : 0, ($t2['fee_received'] ?? '') !== '' ? (float) $t2['fee_received'] : 0, ($t2['tds_payment'] ?? '') !== '' ? (float) $t2['tds_payment'] : 0, $t2['assigned_date'] ?: null, $t2['completed_date'] ?: null, $t2['follow_up_date'] ?: null, trim($t2['remarks'] ?? ''), trim($t2['notes'] ?? '')];
        if ($ex->fetch()) {
            $db->prepare("UPDATE task_tax SET company_id=?,firm_name=?,assigned_office_id=?,tax_type_id=?,fiscal_year=?,fiscal_year_id=?,submission_number=?,udin_no=?,business_type=?,pan_number=?,assigned_to=?,file_received_by=?,updated_by=?,verify_by=?,status_id=?,tax_clearance_status_id=?,bills_issued=?,fee_received=?,tds_payment=?,assigned_date=?,completed_date=?,follow_up_date=?,remarks=?,notes=? WHERE task_id=?")->execute(array_merge($p, [$id]));
        } else {
            $db->prepare("INSERT INTO task_tax(task_id,company_id,firm_name,assigned_office_id,tax_type_id,fiscal_year,fiscal_year_id,submission_number,udin_no,business_type,pan_number,assigned_to,file_received_by,updated_by,verify_by,status_id,tax_clearance_status_id,bills_issued,fee_received,tds_payment,assigned_date,completed_date,follow_up_date,remarks,notes)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$id], $p));
        }
    }
    if (!empty($t2['status_id']))
        $db->prepare("UPDATE tasks SET status_id=?,updated_at=NOW() WHERE id=?")->execute([(int) $t2['status_id'], $id]);
    syncTaskFiscalYear($db, $id);
    logActivity("Tax saved: {$task['task_number']}", 'tasks');
    setFlash('success', 'Tax details saved.');
    header("Location: view.php?id={$id}");
    exit;
}

// POST: save_retail
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_retail']) && $canEditDept) {
    verifyCsrf();
    $r2 = $_POST['retail'] ?? [];
    $retFy = trim($r2['fiscal_year'] ?? '');
    $retFyId = getFiscalYearId($db, $retFy);
    // Ensure columns exist before saving
    try {
        $db->query("SELECT fiscal_year FROM task_retail LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE task_retail ADD COLUMN fiscal_year VARCHAR(10) NULL");
        } catch (Exception $e2) {
        }
    }
    try {
        $db->query("SELECT fiscal_year_id FROM task_retail LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE task_retail ADD COLUMN fiscal_year_id INT NULL AFTER fiscal_year");
        } catch (Exception $e2) {
        }
    }
    $p = [$task['company_id'], trim($r2['firm_name'] ?? '') ?: ($task['company_name'] ?? ''), ($r2['company_type_id'] ?? '') !== '' ? (int) $r2['company_type_id'] : null, ($r2['file_type_id'] ?? '') !== '' ? (int) $r2['file_type_id'] : null, ($r2['pan_vat_id'] ?? '') !== '' ? (int) $r2['pan_vat_id'] : null, ($r2['vat_client_id'] ?? '') !== '' ? (int) $r2['vat_client_id'] : null, $r2['return_type'] ?? null, $retFy, $retFyId, (int) ($r2['no_of_audit_year'] ?? 1), trim($r2['pan_no'] ?? ''), ($r2['assigned_to'] ?? '') !== '' ? (int) $r2['assigned_to'] : null, $r2['assigned_date'] ?: null, ($r2['audit_type_id'] ?? '') !== '' ? (int) $r2['audit_type_id'] : null, $r2['ecd'] ?: null, ($r2['opening_due'] ?? '') !== '' ? (float) $r2['opening_due'] : 0, ($r2['work_status_id'] ?? '') !== '' ? (int) $r2['work_status_id'] : null, ($r2['finalisation_status_id'] ?? '') !== '' ? (int) $r2['finalisation_status_id'] : null, ($r2['finalised_by'] ?? '') !== '' ? (int) $r2['finalised_by'] : null, $r2['completed_date'] ?: null, ($r2['tax_clearance_status_id'] ?? '') !== '' ? (int) $r2['tax_clearance_status_id'] : null, ($r2['backup_status_id'] ?? '') !== '' ? (int) $r2['backup_status_id'] : null, $r2['follow_up_date'] ?: null, trim($r2['notes'] ?? '')];
    $ex = $db->prepare("SELECT id FROM task_retail WHERE task_id=?");
    $ex->execute([$id]);
    if ($ex->fetch()) {
        $db->prepare("UPDATE task_retail SET company_id=?,firm_name=?,company_type_id=?,file_type_id=?,pan_vat_id=?,vat_client_id=?,return_type=?,fiscal_year=?,fiscal_year_id=?,no_of_audit_year=?,pan_no=?,assigned_to=?,assigned_date=?,audit_type_id=?,ecd=?,opening_due=?,work_status_id=?,finalisation_status_id=?,finalised_by=?,completed_date=?,tax_clearance_status_id=?,backup_status_id=?,follow_up_date=?,notes=? WHERE task_id=?")->execute(array_merge($p, [$id]));
    } else {
        $db->prepare("INSERT INTO task_retail(task_id,company_id,firm_name,company_type_id,file_type_id,pan_vat_id,vat_client_id,return_type,fiscal_year,fiscal_year_id,no_of_audit_year,pan_no,assigned_to,assigned_date,audit_type_id,ecd,opening_due,work_status_id,finalisation_status_id,finalised_by,completed_date,tax_clearance_status_id,backup_status_id,follow_up_date,notes)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$id], $p));
    }
    if (!empty($r2['work_status_id']))
        $db->prepare("UPDATE tasks SET status_id=?,updated_at=NOW() WHERE id=?")->execute([(int) $r2['work_status_id'], $id]);
    syncTaskFiscalYear($db, $id);
    logActivity("Retail saved: {$task['task_number']}", 'tasks');
    setFlash('success', 'Retail details saved.');
    header("Location: view.php?id={$id}");
    exit;
}

// POST: save_corporate
// task_corporate columns: task_id,company_id,firm_name,pan_no,grade_id,
//   assigned_to,finalised_by,completed_date,remarks,fiscal_year,fiscal_year_id
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_corporate']) && $canEditDept) {
    verifyCsrf();
    $co = $_POST['corporate'] ?? [];
    $coFy = trim($co['fiscal_year'] ?? '');
    $coFyId = getFiscalYearId($db, $coFy);
    // Ensure columns exist before saving
    try {
        $db->query("SELECT fiscal_year FROM task_corporate LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE task_corporate ADD COLUMN fiscal_year VARCHAR(10) NULL AFTER pan_no");
        } catch (Exception $e2) {
        }
    }
    try {
        $db->query("SELECT fiscal_year_id FROM task_corporate LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE task_corporate ADD COLUMN fiscal_year_id INT NULL AFTER fiscal_year");
        } catch (Exception $e2) {
        }
    }
    $p = [$task['company_id'], trim($co['firm_name'] ?? '') ?: ($task['company_name'] ?? ''), trim($co['pan_no'] ?? '') ?: ($companyData['pan_number'] ?? ''), ($co['grade_id'] ?? '') !== '' ? (int) $co['grade_id'] : null, ($co['assigned_to'] ?? '') !== '' ? (int) $co['assigned_to'] : $taskAssignedToId, ($co['finalised_by'] ?? '') !== '' ? (int) $co['finalised_by'] : null, $co['completed_date'] ?: null, trim($co['remarks'] ?? ''), $coFy, $coFyId];
    try {
        $ex = $db->prepare("SELECT id FROM task_corporate WHERE task_id=?");
        $ex->execute([$id]);
        if ($ex->fetch()) {
            $db->prepare("UPDATE task_corporate SET company_id=?,firm_name=?,pan_no=?,grade_id=?,assigned_to=?,finalised_by=?,completed_date=?,remarks=?,fiscal_year=?,fiscal_year_id=? WHERE task_id=?")->execute(array_merge($p, [$id]));
        } else {
            $db->prepare("INSERT INTO task_corporate(task_id,company_id,firm_name,pan_no,grade_id,assigned_to,finalised_by,completed_date,remarks,fiscal_year,fiscal_year_id)VALUES(?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$id], $p));
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

// POST: save_finance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_finance']) && $canEditDept) {
    verifyCsrf();
    $f = $_POST['finance'] ?? [];
    $finFy = trim($f['fiscal_year'] ?? '');
    $finFyId = getFiscalYearId($db, $finFy);
    // Ensure columns exist before saving
    try {
        $db->query("SELECT fiscal_year FROM task_finance LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE task_finance ADD COLUMN fiscal_year VARCHAR(10) NULL");
        } catch (Exception $e2) {
        }
    }
    try {
        $db->query("SELECT fiscal_year_id FROM task_finance LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE task_finance ADD COLUMN fiscal_year_id INT NULL AFTER fiscal_year");
        } catch (Exception $e2) {
        }
    }
    $p = [$task['company_id'], ($f['service_type_id'] ?? '') !== '' ? (int) $f['service_type_id'] : null, $finFy, $finFyId, ($f['total_amount'] ?? '') !== '' ? (float) $f['total_amount'] : 0, ($f['paid_amount'] ?? '') !== '' ? (float) $f['paid_amount'] : 0, $f['payment_date'] ?: null, trim($f['payment_method'] ?? ''), ($f['tax_clearance_status_id'] ?? '') !== '' ? (int) $f['tax_clearance_status_id'] : null, $f['tax_clearance_date'] ?: null, ($f['payment_status_id'] ?? '') !== '' ? (int) $f['payment_status_id'] : null, isset($f['is_completed']) ? 1 : 0, trim($f['remarks'] ?? '')];
    $ex = $db->prepare("SELECT id FROM task_finance WHERE task_id=?");
    $ex->execute([$id]);
    if ($ex->fetch()) {
        $db->prepare("UPDATE task_finance SET company_id=?,service_type_id=?,fiscal_year=?,fiscal_year_id=?,total_amount=?,paid_amount=?,payment_date=?,payment_method=?,tax_clearance_status_id=?,tax_clearance_date=?,payment_status_id=?,is_completed=?,remarks=? WHERE task_id=?")->execute(array_merge($p, [$id]));
    } else {
        $db->prepare("INSERT INTO task_finance(task_id,company_id,service_type_id,fiscal_year,fiscal_year_id,total_amount,paid_amount,payment_date,payment_method,tax_clearance_status_id,tax_clearance_date,payment_status_id,is_completed,remarks)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$id], $p));
    }
    if (isset($f['is_completed'])) {
        $did = $db->query("SELECT id FROM task_status WHERE status_name='Done'")->fetchColumn();
        $db->prepare("UPDATE tasks SET status_id=?,updated_at=NOW() WHERE id=?")->execute([$did, $id]);
    }
    syncTaskFiscalYear($db, $id);
    setFlash('success', 'Finance details saved.');
    header("Location: view.php?id={$id}");
    exit;
}

// POST: save_banking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_banking']) && $canEditDept) {
    verifyCsrf();
    $b = $_POST['banking'] ?? [];
    // Ensure columns exist before saving
    try {
        $db->query("SELECT fiscal_year FROM task_banking LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE task_banking ADD COLUMN fiscal_year VARCHAR(10) NULL AFTER completion_date");
        } catch (Exception $e2) {
        }
    }
    try {
        $db->query("SELECT fiscal_year_id FROM task_banking LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->exec("ALTER TABLE task_banking ADD COLUMN fiscal_year_id INT NULL AFTER fiscal_year");
        } catch (Exception $e2) {
        }
    }
    $p = [$task['id'], $task['company_id'], ($b['bank_reference_id'] ?? null), ($b['client_category_id'] ?? null), ($b['auditor_id'] ?? null), parseDate($b['assigned_date'] ?? null), parseDate($b['ecd'] ?? null), parseDate($b['completion_date'] ?? null), ($b['work_status_id'] ?? null), ($b['sales_check'] ?? null), ($b['audit_check'] ?? null), ($b['provisional_financial_statement'] ?? null), ($b['projected'] ?? null), ($b['consulting'] ?? null), ($b['nta'] ?? null), ($b['salary_certificate'] ?? null), ($b['ca_certification'] ?? null), ($b['etds'] ?? null), isset($b['bill_issued']) ? 1 : 0, $b['remarks'] ?? null];
    $ex = $db->prepare("SELECT id FROM task_banking WHERE task_id=?");
    $ex->execute([$task['id']]);
    if ($ex->fetch()) {
        $db->prepare("UPDATE task_banking SET company_id=?,bank_reference_id=?,client_category_id=?,auditor_id=?,assigned_date=?,ecd=?,completion_date=?,work_status_id=?,sales_check=?,audit_check=?,provisional_financial_statement=?,projected=?,consulting=?,nta=?,salary_certificate=?,ca_certification=?,etds=?,bill_issued=?,remarks=? WHERE task_id=?")->execute(array_merge(array_slice($p, 1), [$task['id']]));
    } else {
        $db->prepare("INSERT INTO task_banking(task_id,company_id,bank_reference_id,client_category_id,auditor_id,assigned_date,ecd,completion_date,work_status_id,sales_check,audit_check,provisional_financial_statement,projected,consulting,nta,salary_certificate,ca_certification,etds,bill_issued,remarks)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($p);
    }
    syncTaskFiscalYear($db, $task['id']);
    setFlash('success', 'Banking details saved.');
    header("Location: view.php?id={$task['id']}");
    exit;
}

// POST: add_comment
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

$pageTitle = 'Task: ' . $task['task_number'];
$sClass = 'status-' . strtolower(str_replace(' ', '-', $task['status'] ?? ''));

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

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">
            <?= flashHtml() ?>

            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm"><i
                        class="fas fa-arrow-left me-1"></i>Back</a>
                <div class="d-flex gap-2 align-items-center">
                    <?php if (!$canEditDept): ?>
                        <span
                            style="font-size:.73rem;background:#fef3c7;color:#92400e;padding:.3rem .8rem;border-radius:99px;border:1px solid #fde68a;">
                            <i class="fas fa-eye me-1"></i>View only — dept details belong to
                            <strong><?= htmlspecialchars($task['dept_name']) ?></strong>
                        </span>
                    <?php endif; ?>
                    <a href="edit.php?id=<?= $id ?>" class="btn btn-gold btn-sm"><i class="fas fa-pen me-1"></i>Edit
                        Task</a>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">

                    <!-- Task Info -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <div>
                                <span
                                    class="task-number d-block mb-1"><?= htmlspecialchars($task['task_number']) ?></span>
                                <h5 style="font-size:1.05rem;margin:0;"><?= htmlspecialchars($task['title']) ?></h5>
                            </div>
                            <span
                                class="status-badge <?= $sClass ?>"><?= htmlspecialchars($task['status'] ?? 'Pending') ?></span>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3">
                                <?php foreach (['Department' => htmlspecialchars($task['dept_name'] ?? '—'), 'Branch' => htmlspecialchars($task['branch_name'] ?? '—'), 'Company' => htmlspecialchars($task['company_name'] ?? '—'), 'Created By' => htmlspecialchars($task['assigned_by_name'] ?? '—'), 'Assigned To' => htmlspecialchars($task['assigned_to_name'] ?? 'Unassigned'), 'Priority' => '<span class="status-badge priority-' . $task['priority'] . '">' . ucfirst($task['priority']) . '</span>', 'Due Date' => $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : '—', 'Fiscal Year' => htmlspecialchars($task['fiscal_year'] ?? '—'), 'Created' => date('d M Y, H:i', strtotime($task['created_at']))] as $label => $val): ?>
                                    <div class="col-md-4">
                                        <div
                                            style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                            <?= $label ?></div>
                                        <div style="font-size:.9rem;margin-top:.2rem;"><?= $val ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($task['description']): ?>
                                    <div class="col-12">
                                        <div
                                            style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                            Description</div>
                                        <div style="font-size:.88rem;margin-top:.2rem;">
                                            <?= nl2br(htmlspecialchars($task['description'])) ?></div>
                                    </div><?php endif; ?>
                                <?php if ($task['remarks']): ?>
                                    <div class="col-12">
                                        <div
                                            style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                            Remarks</div>
                                        <div style="font-size:.88rem;margin-top:.2rem;">
                                            <?= nl2br(htmlspecialchars($task['remarks'])) ?></div>
                                    </div><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!$canEditDept && $detailTable): ?>
                        <div
                            style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem;display:flex;align-items:center;gap:.6rem;">
                            <i class="fas fa-lock" style="color:#92400e;"></i>
                            <span style="font-size:.82rem;color:#92400e;"><strong><?= htmlspecialchars($task['dept_name']) ?>
                                    Department</strong> details — read only. Only admins from that department can edit these
                                fields.</span>
                        </div>
                    <?php endif; ?>

                    <!-- ════ TAX ════ -->
                    <?php if ($task['dept_code'] === 'TAX'): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-receipt text-warning me-2"></i>Tax Details</h5>
                                <?php if (!$canEditDept): ?><span
                                        style="font-size:.73rem;color:#92400e;background:#fef3c7;padding:.2rem .6rem;border-radius:99px;"><i
                                            class="fas fa-eye me-1"></i>View Only</span><?php endif; ?>
                            </div>
                            <div class="card-mis-body">
                                <?php if ($canEditDept):
                                    $savedAddressSuggestions = [];
                                    try {
                                        $savedAddressSuggestions = $db->query("SELECT DISTINCT assigned_office_address FROM task_tax WHERE assigned_office_address IS NOT NULL AND assigned_office_address!='' ORDER BY assigned_office_address")->fetchAll(PDO::FETCH_COLUMN);
                                    } catch (Exception $e) {
                                    }
                                    ?>
                                    <datalist id="office_address_list">
                                        <?php foreach ($savedAddressSuggestions as $addr): ?>
                                            <option value="<?= htmlspecialchars($addr) ?>"><?php endforeach; ?>
                                    </datalist>
                                <?php endif; ?>

                                <?php if ($detail): ?>
                                    <div class="row g-3 mb-4">
                                        <?php
                                        $officeDisplay = $detail['assigned_office_name'] ?? '—';
                                        $offAddrSaved = $detail['assigned_office_address'] ?? $detail['assigned_office_default_address'] ?? '';
                                        if ($offAddrSaved)
                                            $officeDisplay .= ' <span style="color:#6b7280;font-size:.8em;">– ' . htmlspecialchars($offAddrSaved) . '</span>';
                                        else
                                            $officeDisplay = htmlspecialchars($officeDisplay);
                                        foreach (['Firm Name' => htmlspecialchars($detail['firm_name'] ?? '—'), 'Assigned Office' => '<span style="background:#eff6ff;color:#3b82f6;padding:.2rem .6rem;border-radius:6px;font-weight:600;">' . $officeDisplay . '</span>', 'Tax Type' => '<span style="background:#f0fdf4;color:#16a34a;padding:.2rem .6rem;border-radius:6px;font-weight:600;">' . htmlspecialchars($detail['tax_type_name'] ?? '—') . '</span>', 'Fiscal Year' => htmlspecialchars($detail['fiscal_year'] ?? '—'), 'Business Type' => htmlspecialchars($detail['business_type'] ?? '—'), 'PAN Number' => htmlspecialchars($detail['pan_number'] ?? '—'), 'Assigned To' => htmlspecialchars($detail['assigned_to_name'] ?? '—'), 'File Received' => htmlspecialchars($detail['file_received_by_name'] ?? '—'), 'Verify By' => htmlspecialchars($detail['verify_by_name'] ?? '—'), 'Status' => htmlspecialchars($detail['status_name'] ?? '—'), 'Tax Clearance' => htmlspecialchars($detail['tax_clearance_status_name'] ?? '—'), 'Bills Issued' => 'Rs. ' . number_format($detail['bills_issued'] ?? 0, 2), 'Fee Received' => 'Rs. ' . number_format($detail['fee_received'] ?? 0, 2), 'TDS Payment' => 'Rs. ' . number_format($detail['tds_payment'] ?? 0, 2), 'Assigned Date' => ($detail['assigned_date'] ?? '') ? date('d M Y', strtotime($detail['assigned_date'])) : '—', 'Completed Date' => ($detail['completed_date'] ?? '') ? date('d M Y', strtotime($detail['completed_date'])) : '—', 'Follow-up Date' => ($detail['follow_up_date'] ?? '') ? date('d M Y', strtotime($detail['follow_up_date'])) : '—'] as $lbl => $val): ?>
                                            <div class="col-md-4">
                                                <div
                                                    style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                                    <?= $lbl ?></div>
                                                <div style="font-size:.88rem;margin-top:.2rem;"><?= $val ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php foreach ([['Submission Number', $detail['submission_number'] ?? '', 'https://ird.gov.np', '#3b82f6', 'IRD Portal'], ['UDIN Number', $detail['udin_no'] ?? '', 'https://udin.icai.org', '#8b5cf6', 'UDIN Portal']] as [$lbl, $val, $url, $col, $btn]): ?>
                                            <div class="col-md-6">
                                                <div
                                                    style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                                    <?= $lbl ?></div>
                                                <div class="d-flex align-items-center gap-2 mt-1"><span
                                                        style="font-size:.88rem;font-weight:600;"><?= htmlspecialchars($val ?: '—') ?></span><?php if ($val): ?><a
                                                            href="<?= $url ?>" target="_blank"
                                                            style="background:<?= $col ?>;color:white;padding:.2rem .6rem;border-radius:6px;font-size:.72rem;text-decoration:none;"><i
                                                                class="fas fa-external-link-alt me-1"></i><?= $btn ?></a><?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($detail['remarks']): ?>
                                            <div class="col-12">
                                                <div
                                                    style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                                    Remarks</div>
                                                <div style="font-size:.88rem;margin-top:.2rem;">
                                                    <?= nl2br(htmlspecialchars($detail['remarks'])) ?></div>
                                            </div><?php endif; ?>
                                    </div>
                                    <?php if ($canEditDept): ?>
                                        <hr style="border-color:#f3f4f6;"><?php endif; ?>
                                <?php elseif (!$canEditDept): ?>
                                    <div class="text-center py-4 text-muted"><i
                                            class="fas fa-file-circle-question fa-2x mb-2 d-block opacity-50"></i>No tax details
                                        recorded yet.</div>
                                <?php endif; ?>

                                <?php if ($canEditDept): ?>
                                    <div
                                        style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                        <i class="fas fa-pen me-1"></i><?= $detail ? 'Update' : 'Add' ?> Tax Details</div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="save_tax" value="1">
                                        <div class="row g-3">
                                            <div class="col-md-6"><label class="form-label-mis">Firm Name</label><input
                                                    type="text" name="tax[firm_name]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['firm_name'] ?? $task['company_name'] ?? '') ?>"
                                                    <?= $task['company_id'] ? 'readonly style="background:#f0fdf4;cursor:not-allowed;"' : '' ?>></div>
                                            <div class="col-md-3"><label class="form-label-mis">Assigned Office</label><select
                                                    name="tax[assigned_office_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($taxOfficeTypes as $o): ?>
                                                        <option value="<?= $o['id'] ?>"
                                                            <?= ($detail['assigned_office_id'] ?? '') == $o['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($o['office_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-3"><label class="form-label-mis">Office Branch
                                                    Address</label><input type="text" name="tax[assigned_office_address]"
                                                    class="form-control form-control-sm" list="office_address_list"
                                                    value="<?= htmlspecialchars($currentOfficeAddr) ?>"
                                                    placeholder="e.g. Lazimpat, Kathmandu"><small
                                                    style="font-size:.65rem;color:#9ca3af;">Previously used addresses appear as
                                                    suggestions</small></div>
                                            <div class="col-md-3"><label class="form-label-mis">Tax Type</label><select
                                                    name="tax[tax_type_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option><?php foreach ($taxTypes as $tt): ?>
                                                        <option value="<?= $tt['id'] ?>"
                                                            <?= ($detail['tax_type_id'] ?? '') == $tt['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($tt['tax_type_name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Fiscal Year</label>
                                                <?= fiscalYearSelect('tax[fiscal_year]', $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy), $fys) ?>
                                            </div>
                                            <div class="col-md-3"><label class="form-label-mis">Business Type</label><input
                                                    type="text" name="tax[business_type]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['business_type'] ?? $companyTypeVal) ?>"
                                                    <?= ($task['company_id'] && $companyTypeVal) ? 'readonly style="background:#f0fdf4;cursor:not-allowed;"' : '' ?>></div>
                                            <div class="col-md-3"><label class="form-label-mis">PAN
                                                    Number</label><?php if ($companyPanRow && $companyPanRow['pan_number']): ?><input
                                                        type="text" name="tax[pan_number]" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['pan_number'] ?? $companyPanRow['pan_number']) ?>"
                                                        readonly
                                                        style="background:#f0fdf4;font-weight:600;cursor:not-allowed;"><?php else: ?><input
                                                        type="text" name="tax[pan_number]" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail['pan_number'] ?? '') ?>"
                                                        placeholder="Enter PAN"><?php endif; ?></div>
                                            <div class="col-md-6"><label class="form-label-mis">Submission Number <span
                                                        style="font-size:.68rem;color:#9ca3af;">(from IRD)</span></label>
                                                <div class="input-group input-group-sm"><input type="text"
                                                        name="tax[submission_number]" class="form-control"
                                                        value="<?= htmlspecialchars($detail['submission_number'] ?? '') ?>"><a
                                                        href="https://ird.gov.np" target="_blank"
                                                        class="btn btn-outline-primary btn-sm"><i
                                                            class="fas fa-external-link-alt"></i></a></div>
                                            </div>
                                            <div class="col-md-6"><label class="form-label-mis">UDIN Number <span
                                                        style="font-size:.68rem;color:#9ca3af;">(from ICAN)</span></label>
                                                <div class="input-group input-group-sm"><input type="text" name="tax[udin_no]"
                                                        class="form-control"
                                                        value="<?= htmlspecialchars($detail['udin_no'] ?? '') ?>"><a
                                                        href="https://udin.icai.org" target="_blank"
                                                        class="btn btn-outline-secondary btn-sm"><i
                                                            class="fas fa-external-link-alt"></i></a></div>
                                            </div>
                                            <div class="col-md-4"><label class="form-label-mis">Status</label><select
                                                    name="tax[status_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>"
                                                            <?= ($detail['status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-4"><label class="form-label-mis">Tax Clearance
                                                    Status</label><select name="tax[tax_clearance_status_id]"
                                                    class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>"
                                                            <?= ($detail['tax_clearance_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <?php foreach (['file_received_by' => 'File Received By', 'updated_by' => 'Updated By', 'verify_by' => 'Verify By'] as $f => $l): ?>
                                                <div class="col-md-3"><label class="form-label-mis"><?= $l ?></label><select
                                                        name="tax[<?= $f ?>]" class="form-select form-select-sm">
                                                        <option value="">-- Select --</option><?php foreach ($allStaff as $s): ?>
                                                            <option value="<?= $s['id'] ?>" <?= ($detail[$f] ?? '') == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['full_name']) ?></option><?php endforeach; ?>
                                                    </select></div>
                                            <?php endforeach; ?>
                                            <div class="col-md-3"><label class="form-label-mis">Assigned To <span
                                                        style="font-size:.65rem;color:#3b82f6;margin-left:.3rem;"><i
                                                            class="fas fa-link me-1"></i>from task</span></label>
                                                <div class="form-control form-control-sm"
                                                    style="background:#eff6ff;color:#1d4ed8;font-weight:600;cursor:default;display:flex;align-items:center;gap:.4rem;">
                                                    <i class="fas fa-user-circle"
                                                        style="color:#3b82f6;"></i><?= htmlspecialchars($taskAssignedToName) ?>
                                                </div><input type="hidden" name="tax[assigned_to]"
                                                    value="<?= $taskAssignedToId ?>">
                                            </div>
                                            <?php foreach (['bills_issued' => 'Bills Issued (Rs.)', 'fee_received' => 'Fee Received (Rs.)', 'tds_payment' => 'TDS Payment (Rs.)'] as $f => $l): ?>
                                                <div class="col-md-4"><label class="form-label-mis"><?= $l ?></label><input
                                                        type="number" name="tax[<?= $f ?>]" class="form-control form-control-sm"
                                                        step="0.01" min="0" value="<?= htmlspecialchars($detail[$f] ?? '0') ?>"></div>
                                            <?php endforeach; ?>
                                            <?php foreach (['assigned_date' => 'Assigned Date', 'completed_date' => 'Completed Date', 'follow_up_date' => 'Follow-up Date'] as $f => $l): ?>
                                                <div class="col-md-4"><label class="form-label-mis"><?= $l ?></label><input
                                                        type="date" name="tax[<?= $f ?>]" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail[$f] ?? '') ?>"></div>
                                            <?php endforeach; ?>
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

                        <!-- ════ BANK ════ -->
                    <?php elseif ($task['dept_code'] === 'BANK'): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-landmark text-warning me-2"></i>Banking Details</h5>
                                <?php if (!$canEditDept): ?><span
                                        style="font-size:.73rem;color:#92400e;background:#fef3c7;padding:.2rem .6rem;border-radius:99px;"><i
                                            class="fas fa-eye me-1"></i>View Only</span><?php endif; ?>
                            </div>
                            <div class="card-mis-body">
                                <div
                                    style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:1rem;margin-bottom:1rem;">
                                    <div
                                        style="font-size:.72rem;font-weight:700;color:#16a34a;text-transform:uppercase;margin-bottom:.5rem;">
                                        <i class="fas fa-building me-1"></i>Client Info</div>
                                    <div class="row g-2">
                                        <?php foreach (['Company' => $detail['company_name'] ?? $task['company_name'] ?? '—', 'Contact' => $detail['contact_person'] ?? '—', 'Phone' => $detail['contact_phone'] ?? '—', 'PAN' => $detail['company_pan'] ?? '—', 'Type' => $detail['company_type_name'] ?? '—', 'Bank' => $detail['bank_name'] ?? '—', 'Category' => $detail['client_category_name'] ?? '—', 'Work Status' => $detail['work_status_name'] ?? '—', 'Assigned Date' => ($detail['assigned_date'] ?? '') ? date('d M Y', strtotime($detail['assigned_date'])) : '—', 'ECD' => ($detail['ecd'] ?? '') ? date('d M Y', strtotime($detail['ecd'])) : '—', 'Completion' => ($detail['completion_date'] ?? '') ? date('d M Y', strtotime($detail['completion_date'])) : '—'] as $lbl => $val): ?>
                                            <div class="col-md-4">
                                                <div
                                                    style="font-size:.68rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                                    <?= $lbl ?></div>
                                                <div style="font-size:.87rem;"><?= htmlspecialchars($val) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php if (!empty($detail['auditor_name'])): ?>
                                    <div
                                        style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:1rem;margin-bottom:1rem;">
                                        <div
                                            style="font-size:.72rem;font-weight:700;color:#3b82f6;text-transform:uppercase;margin-bottom:.5rem;">
                                            <i class="fas fa-user-tie me-1"></i>Auditor</div>
                                        <div class="row g-2">
                                            <?php foreach (['Name' => $detail['auditor_name'], 'Firm' => $detail['auditor_firm'], 'Phone' => $detail['auditor_phone']] as $lbl => $val): ?>
                                                <div class="col-md-4">
                                                    <div
                                                        style="font-size:.68rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                                        <?= $lbl ?></div>
                                                    <div style="font-size:.87rem;font-weight:500;">
                                                        <?= htmlspecialchars($val ?? '—') ?></div>
                                                </div><?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($detail && !$canEditDept): ?>
                                    <div style="background:#f9fafb;border-radius:10px;padding:1rem;margin-bottom:1rem;">
                                        <div
                                            style="font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                            Work Checklist</div>
                                        <div class="row g-2">
                                            <?php foreach (['Sales Check' => $detail['sales_check'] ?? '—', 'Audit' => $detail['audit_check'] ?? '—', 'Provisional/FS' => $detail['provisional_financial_statement'] ?? '—', 'Projected' => $detail['projected'] ?? '—', 'Consulting' => $detail['consulting'] ?? '—', 'NTA' => $detail['nta'] ?? '—', 'Salary Cert.' => $detail['salary_certificate'] ?? '—', 'CA Cert.' => $detail['ca_certification'] ?? '—', 'ETDS' => $detail['etds'] ?? '—', 'Bill Issued' => ($detail['bill_issued'] ?? 0) ? '✅ Yes' : 'No'] as $lbl => $val): ?>
                                                <div class="col-md-3 col-6">
                                                    <div
                                                        style="font-size:.68rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                                        <?= $lbl ?></div>
                                                    <div style="font-size:.87rem;font-weight:600;">
                                                        <?= htmlspecialchars((string) $val) ?></div>
                                                </div><?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!$detail && !$canEditDept): ?>
                                    <div class="text-center py-4 text-muted"><i
                                            class="fas fa-file-circle-question fa-2x mb-2 d-block opacity-50"></i>No banking
                                        details yet.</div><?php endif; ?>
                                <?php if ($canEditDept): ?>
                                    <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input
                                            type="hidden" name="save_banking" value="1">
                                        <div class="row g-3">
                                            <div class="col-md-6"><label class="form-label-mis">Bank Name</label><select
                                                    name="banking[bank_reference_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select Bank --</option>
                                                    <?php foreach ($allBanks as $bk): ?>
                                                        <option value="<?= $bk['id'] ?>"
                                                            <?= ($detail['bank_reference_id'] ?? '') == $bk['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($bk['bank_name'] . ($bk['address'] ? ' - ' . $bk['address'] : '')) ?>
                                                        </option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-3"><label class="form-label-mis">Category</label><select
                                                    name="banking[client_category_id]" class="form-select form-select-sm">
                                                    <option value="">--</option><?php foreach ($allCats as $cat): ?>
                                                        <option value="<?= $cat['id'] ?>"
                                                            <?= ($detail['client_category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($cat['category_name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-3"><label class="form-label-mis">Auditor</label><select
                                                    name="banking[auditor_id]" class="form-select form-select-sm">
                                                    <option value="">--</option><?php foreach ($allAuditors as $au): ?>
                                                        <option value="<?= $au['id'] ?>"
                                                            <?= ($detail['auditor_id'] ?? '') == $au['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($au['auditor_name'] . ($au['firm_name'] ? ' — ' . $au['firm_name'] : '')) ?>
                                                        </option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-3"><label class="form-label-mis">Work Status</label><select
                                                    name="banking[work_status_id]" class="form-select form-select-sm">
                                                    <option value="">--</option><?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>"
                                                            <?= ($detail['work_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <?php foreach (['assigned_date' => 'Assigned Date', 'ecd' => 'ECD', 'completion_date' => 'Completion Date'] as $f => $l): ?>
                                                <div class="col-md-3"><label class="form-label-mis"><?= $l ?></label><input
                                                        type="date" name="banking[<?= $f ?>]" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($detail[$f] ?? '') ?>"></div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div style="border-top:1px solid #f3f4f6;padding-top:1rem;margin-top:1rem;">
                                            <div
                                                style="font-size:.78rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                                Work Checklist</div>
                                            <div class="row g-3">
                                                <?php foreach (['sales_check' => 'Sales Check', 'audit_check' => 'Audit', 'provisional_financial_statement' => 'Provisional/FS', 'projected' => 'Projected', 'consulting' => 'Consulting', 'nta' => 'NTA', 'salary_certificate' => 'Salary Cert.', 'ca_certification' => 'CA Cert.', 'etds' => 'ETDS'] as $f => $l): ?>
                                                    <div class="col-md-3 col-6"><label
                                                            class="form-label-mis"><?= $l ?></label><input type="number"
                                                            name="banking[<?= $f ?>]" class="form-control form-control-sm"
                                                            value="<?= htmlspecialchars($detail[$f] ?? '') ?>" min="0"
                                                            placeholder="—"></div>
                                                <?php endforeach; ?>
                                                <div class="col-md-3 col-6"><label class="form-label-mis">Bill Issued</label>
                                                    <div class="form-check form-switch mt-2"><input class="form-check-input"
                                                            type="checkbox" name="banking[bill_issued]" value="1"
                                                            id="billIssued" <?= ($detail['bill_issued'] ?? 0) ? 'checked' : '' ?>><label class="form-check-label" for="billIssued">Yes</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mt-3"><label class="form-label-mis">Remarks</label><textarea
                                                name="banking[remarks]" class="form-control form-control-sm"
                                                rows="2"><?= htmlspecialchars($detail['remarks'] ?? '') ?></textarea></div>
                                        <div class="mt-3"><button type="submit" class="btn btn-gold btn-sm"><i
                                                    class="fas fa-save me-1"></i>Save Banking Details</button></div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ════ FINANCE ════ -->
                    <?php elseif ($task['dept_code'] === 'FIN'): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-coins text-warning me-2"></i>Finance Details</h5>
                                <?php if (!$canEditDept): ?><span
                                        style="font-size:.73rem;color:#92400e;background:#fef3c7;padding:.2rem .6rem;border-radius:99px;"><i
                                            class="fas fa-eye me-1"></i>View Only</span><?php endif; ?>
                            </div>
                            <div class="card-mis-body">
                                <?php if ($detail): ?>
                                    <div class="row g-3 mb-4">
                                        <?php foreach (['Service Type' => htmlspecialchars($detail['service_type_name'] ?? '—'), 'Fiscal Year' => htmlspecialchars($detail['fiscal_year'] ?? '—'), 'Total Amount' => 'Rs. ' . number_format($detail['total_amount'] ?? 0, 2), 'Paid Amount' => 'Rs. ' . number_format($detail['paid_amount'] ?? 0, 2), 'Due Amount' => 'Rs. ' . number_format($detail['due_amount'] ?? 0, 2), 'Payment Date' => ($detail['payment_date'] ?? '') ? date('d M Y', strtotime($detail['payment_date'])) : '—', 'Method' => htmlspecialchars($detail['payment_method'] ?? '—'), 'Payment Status' => htmlspecialchars($detail['payment_status_name'] ?? '—'), 'Tax Clearance' => htmlspecialchars($detail['tax_clearance_status_name'] ?? '—'), 'TC Date' => ($detail['tax_clearance_date'] ?? '') ? date('d M Y', strtotime($detail['tax_clearance_date'])) : '—', 'Completed' => ($detail['is_completed'] ?? 0) ? '✅ Yes' : 'No'] as $lbl => $val): ?>
                                            <div class="col-md-4">
                                                <div
                                                    style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                                    <?= $lbl ?></div>
                                                <div style="font-size:.88rem;margin-top:.2rem;"><?= $val ?></div>
                                            </div>
                                        <?php endforeach; ?>
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
                                        <i class="fas fa-pen me-1"></i><?= $detail ? 'Update' : 'Add' ?> Finance Details</div>
                                    <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input
                                            type="hidden" name="save_finance" value="1">
                                        <div class="row g-3">
                                            <div class="col-md-6"><label class="form-label-mis">Service Type</label><select
                                                    name="finance[service_type_id]" class="form-select form-select-sm">
                                                    <option value="">--</option><?php foreach ($financeServiceTypes as $fst): ?>
                                                        <option value="<?= $fst['id'] ?>"
                                                            <?= ($detail['service_type_id'] ?? '') == $fst['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($fst['service_name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Fiscal Year</label>
                                                <?= fiscalYearSelect('finance[fiscal_year]', $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy), $fys) ?>
                                            </div>
                                            <?php foreach (['total_amount' => 'Total Amount (Rs.)', 'paid_amount' => 'Paid Amount (Rs.)'] as $f => $l): ?>
                                                <div class="col-md-4"><label class="form-label-mis"><?= $l ?></label><input
                                                        type="number" name="finance[<?= $f ?>]" class="form-control form-control-sm"
                                                        step="0.01" min="0" value="<?= htmlspecialchars($detail[$f] ?? '0') ?>"></div>
                                            <?php endforeach; ?>
                                            <div class="col-md-4"><label class="form-label-mis">Due Amount</label><input
                                                    type="text" class="form-control form-control-sm"
                                                    value="Rs. <?= number_format($detail['due_amount'] ?? 0, 2) ?>" readonly
                                                    style="background:#f9fafb;"></div>
                                            <div class="col-md-4"><label class="form-label-mis">Payment Date</label><input
                                                    type="date" name="finance[payment_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['payment_date'] ?? '') ?>"></div>
                                            <div class="col-md-4"><label class="form-label-mis">Payment Method</label><select
                                                    name="finance[payment_method]" class="form-select form-select-sm">
                                                    <option value="">--</option>
                                                    <?php foreach (['Cash', 'Cheque', 'Online Transfer', 'Bank Deposit'] as $pm): ?>
                                                        <option value="<?= $pm ?>"
                                                            <?= ($detail['payment_method'] ?? '') === $pm ? 'selected' : '' ?>><?= $pm ?>
                                                        </option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-4"><label class="form-label-mis">Payment Status</label><select
                                                    name="finance[payment_status_id]" class="form-select form-select-sm">
                                                    <option value="">--</option><?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>"
                                                            <?= ($detail['payment_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-4"><label class="form-label-mis">Tax Clearance
                                                    Status</label><select name="finance[tax_clearance_status_id]"
                                                    class="form-select form-select-sm">
                                                    <option value="">--</option><?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>"
                                                            <?= ($detail['tax_clearance_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-4"><label class="form-label-mis">Tax Clearance Date</label><input
                                                    type="date" name="finance[tax_clearance_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['tax_clearance_date'] ?? '') ?>"></div>
                                            <div class="col-12"><label class="form-label-mis">Remarks</label><textarea
                                                    name="finance[remarks]" class="form-control form-control-sm"
                                                    rows="2"><?= htmlspecialchars($detail['remarks'] ?? '') ?></textarea></div>
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

                        <!-- ════ RETAIL ════ -->
                    <?php elseif ($task['dept_code'] === 'RETAIL'): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-store text-warning me-2"></i>Retail Details</h5>
                                <?php if (!$canEditDept): ?><span
                                        style="font-size:.73rem;color:#92400e;background:#fef3c7;padding:.2rem .6rem;border-radius:99px;"><i
                                            class="fas fa-eye me-1"></i>View Only</span><?php endif; ?>
                            </div>
                            <div class="card-mis-body">
                                <?php if ($detail): ?>
                                    <div class="row g-3 mb-4">
                                        <?php foreach (['Firm Name' => htmlspecialchars($detail['firm_name'] ?? '—'), 'Company Type' => htmlspecialchars($detail['company_type_name'] ?? '—'), 'File Type' => htmlspecialchars($detail['file_type_name'] ?? '—'), 'PAN / VAT' => htmlspecialchars($detail['pan_vat_name'] ?? '—'), 'VAT Client' => htmlspecialchars($detail['vat_client_value'] ?? '—'), 'Return Type' => htmlspecialchars($detail['return_type'] ?? '—'), 'Fiscal Year' => htmlspecialchars($detail['fiscal_year'] ?? '—'), 'Audit Years' => htmlspecialchars($detail['no_of_audit_year'] ?? '—'), 'PAN No' => htmlspecialchars($detail['pan_no'] ?? '—'), 'Audit Type' => htmlspecialchars($detail['audit_type_name'] ?? '—'), 'Assigned To' => htmlspecialchars($detail['retail_assigned_to_name'] ?? '—'), 'Assigned Date' => ($detail['assigned_date'] ?? '') ? date('d M Y', strtotime($detail['assigned_date'])) : '—', 'ECD' => ($detail['ecd'] ?? '') ? date('d M Y', strtotime($detail['ecd'])) : '—', 'Opening Due' => $detail['opening_due'] !== null ? 'Rs. ' . number_format($detail['opening_due'], 2) : '—', 'Work Status' => htmlspecialchars($detail['work_status_name'] ?? '—'), 'Finalisation' => htmlspecialchars($detail['finalisation_status_name'] ?? '—'), 'Finalised By' => htmlspecialchars($detail['finalised_by_name'] ?? '—'), 'Completed' => ($detail['completed_date'] ?? '') ? date('d M Y', strtotime($detail['completed_date'])) : '—', 'Tax Clearance' => htmlspecialchars($detail['tax_clearance_status_name'] ?? '—'), 'Backup' => htmlspecialchars($detail['backup_status_value'] ?? '—'), 'Follow-up' => ($detail['follow_up_date'] ?? '') ? date('d M Y', strtotime($detail['follow_up_date'])) : '—', 'Notes' => htmlspecialchars($detail['notes'] ?? '—')] as $lbl => $val): ?>
                                            <div class="col-md-4">
                                                <div
                                                    style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                                    <?= $lbl ?></div>
                                                <div style="font-size:.88rem;margin-top:.2rem;"><?= $val ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($canEditDept): ?>
                                        <hr style="border-color:#f3f4f6;"><?php endif; ?>
                                <?php elseif (!$canEditDept): ?>
                                    <div class="text-center py-4 text-muted"><i
                                            class="fas fa-file-circle-question fa-2x mb-2 d-block opacity-50"></i>
                                        <p style="font-size:.9rem;">No retail details yet.</p>
                                    </div>
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
                                        <i class="fas fa-pen me-1"></i><?= $detail ? 'Update' : 'Add' ?> Retail Details</div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="save_retail" value="1">
                                        <div class="row g-3">
                                            <div class="col-md-6"><label class="form-label-mis">Firm Name
                                                    <?php if ($task['company_id']): ?><span
                                                            style="font-size:.65rem;color:#16a34a;margin-left:.3rem;"><i
                                                                class="fas fa-link me-1"></i>from
                                                            company</span><?php endif; ?></label><input type="text"
                                                    name="retail[firm_name]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($cFirmName) ?>" <?= $roCo ?>></div>
                                            <div class="col-md-3"><label class="form-label-mis">Company Type
                                                    <?php if ($cCompType): ?><span
                                                            style="font-size:.65rem;color:#16a34a;margin-left:.3rem;"><i
                                                                class="fas fa-link me-1"></i>from
                                                            company</span><?php endif; ?></label>
                                                <?php if ($cCompType && $task['company_id']): ?><input type="text"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($cCompType) ?>" <?= $ro ?>><input type="hidden"
                                                        name="retail[company_type_id]"
                                                        value="<?= htmlspecialchars($cCompTypeId) ?>">
                                                <?php else: ?><select name="retail[company_type_id]"
                                                        class="form-select form-select-sm">
                                                        <option value="">-- Select --</option>
                                                        <?php foreach ($companyTypes as $ct): ?>
                                                            <option value="<?= $ct['id'] ?>" <?= $cCompTypeId == $ct['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ct['type_name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select><?php endif; ?>
                                            </div>
                                            <div class="col-md-3"><label class="form-label-mis">File Type</label><select
                                                    name="retail[file_type_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option><?php foreach ($fileTypes as $ft): ?>
                                                        <option value="<?= $ft['id'] ?>"
                                                            <?= ($detail['file_type_id'] ?? '') == $ft['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ft['type_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-3"><label class="form-label-mis">PAN / VAT</label><select
                                                    name="retail[pan_vat_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option><?php foreach ($panVatTypes as $pv): ?>
                                                        <option value="<?= $pv['id'] ?>" <?= $cPanVatId == $pv['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($pv['type_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-3"><label class="form-label-mis">VAT Client</label><select
                                                    name="retail[vat_client_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option><?php foreach ($yesNoOpts as $yn): ?>
                                                        <option value="<?= $yn['id'] ?>" <?= $cVatClientId == $yn['id'] ? 'selected' : '' ?>><?= htmlspecialchars($yn['value']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-3"><label class="form-label-mis">Return Type
                                                    <?php if ($cReturnType && $task['company_id']): ?><span
                                                            style="font-size:.65rem;color:#16a34a;margin-left:.3rem;"><i
                                                                class="fas fa-link me-1"></i>from
                                                            company</span><?php endif; ?></label>
                                                <?php if ($cReturnType && $task['company_id']): ?><input type="text"
                                                        class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($cReturnType) ?>" <?= $ro ?>><input type="hidden"
                                                        name="retail[return_type]" value="<?= htmlspecialchars($cReturnType) ?>">
                                                <?php else: ?><select name="retail[return_type]"
                                                        class="form-select form-select-sm">
                                                        <option value="">-- Select --</option>
                                                        <?php foreach (['D1', 'D2', 'D3', 'D4'] as $rt): ?>
                                                            <option value="<?= $rt ?>"
                                                                <?= ($detail['return_type'] ?? '') === $rt ? 'selected' : '' ?>><?= $rt ?>
                                                            </option><?php endforeach; ?>
                                                    </select><?php endif; ?>
                                            </div>
                                            <div class="col-md-3"><label class="form-label-mis">Audit Type</label><select
                                                    name="retail[audit_type_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option><?php foreach ($auditTypes2 as $at): ?>
                                                        <option value="<?= $at['id'] ?>"
                                                            <?= ($detail['audit_type_id'] ?? '') == $at['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($at['type_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Fiscal Year</label>
                                                <?= fiscalYearSelect('retail[fiscal_year]', $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy), $fys) ?>
                                            </div>
                                            <div class="col-md-3"><label class="form-label-mis">No. of Audit Years <span
                                                        style="font-size:.65rem;color:#f59e0b;margin-left:.3rem;"><i
                                                            class="fas fa-pen me-1"></i>editable</span></label><input
                                                    type="number" name="retail[no_of_audit_year]"
                                                    class="form-control form-control-sm" min="1"
                                                    value="<?= htmlspecialchars($detail['no_of_audit_year'] ?? '1') ?>"
                                                    style="border-color:#f59e0b;"></div>
                                            <div class="col-md-3"><label class="form-label-mis">PAN No
                                                    <?php if ($cPanNo && $task['company_id']): ?><span
                                                            style="font-size:.65rem;color:#16a34a;margin-left:.3rem;"><i
                                                                class="fas fa-link me-1"></i>from
                                                            company</span><?php endif; ?></label><input type="text"
                                                    name="retail[pan_no]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($cPanNo) ?>"
                                                    <?= ($cPanNo && $task['company_id']) ? $ro : '' ?>></div>
                                            <div class="col-md-4"><label class="form-label-mis">Assigned To <span
                                                        style="font-size:.65rem;color:#3b82f6;margin-left:.3rem;"><i
                                                            class="fas fa-link me-1"></i>from task</span></label>
                                                <div class="form-control form-control-sm"
                                                    style="background:#eff6ff;color:#1d4ed8;font-weight:600;cursor:default;display:flex;align-items:center;gap:.4rem;">
                                                    <i class="fas fa-user-circle"
                                                        style="color:#3b82f6;"></i><?= htmlspecialchars($taskAssignedToName) ?>
                                                </div><input type="hidden" name="retail[assigned_to]"
                                                    value="<?= $taskAssignedToId ?>">
                                            </div>
                                            <div class="col-md-4"><label class="form-label-mis">Assigned Date</label><input
                                                    type="date" name="retail[assigned_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['assigned_date'] ?? date('Y-m-d')) ?>">
                                            </div>
                                            <div class="col-md-4"><label class="form-label-mis">ECD</label><input type="date"
                                                    name="retail[ecd]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['ecd'] ?? '') ?>"></div>
                                            <div class="col-md-4"><label class="form-label-mis">Opening Due (Rs.)</label><input
                                                    type="number" step="0.01" name="retail[opening_due]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['opening_due'] ?? '0') ?>"></div>
                                            <div class="col-md-4"><label class="form-label-mis">Work Status</label><select
                                                    name="retail[work_status_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>"
                                                            <?= ($detail['work_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-4"><label class="form-label-mis">Finalisation
                                                    Status</label><select name="retail[finalisation_status_id]"
                                                    class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>"
                                                            <?= ($detail['finalisation_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-4"><label class="form-label-mis">Finalised By</label><select
                                                    name="retail[finalised_by]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option><?php foreach ($allStaff as $s): ?>
                                                        <option value="<?= $s['id'] ?>"
                                                            <?= ($detail['finalised_by'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($s['full_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-4"><label class="form-label-mis">Completed Date</label><input
                                                    type="date" name="retail[completed_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['completed_date'] ?? '') ?>"></div>
                                            <div class="col-md-4"><label class="form-label-mis">Tax Clearance
                                                    Status</label><select name="retail[tax_clearance_status_id]"
                                                    class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>"
                                                            <?= ($detail['tax_clearance_status_id'] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-4"><label class="form-label-mis">Backup Status</label><select
                                                    name="retail[backup_status_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option><?php foreach ($yesNoOpts as $yn): ?>
                                                        <option value="<?= $yn['id'] ?>"
                                                            <?= ($detail['backup_status_id'] ?? '') == $yn['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($yn['value']) ?></option><?php endforeach; ?>
                                                </select></div>
                                            <div class="col-md-4"><label class="form-label-mis">Follow-up Date</label><input
                                                    type="date" name="retail[follow_up_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($detail['follow_up_date'] ?? '') ?>"></div>
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

                        <!-- ════ CORP ════ -->
                    <?php elseif ($task['dept_code'] === 'CORP'): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-building text-warning me-2"></i>Corporate Details</h5>
                                <?php if (!$canEditDept): ?><span
                                        style="font-size:.73rem;color:#92400e;background:#fef3c7;padding:.2rem .6rem;border-radius:99px;"><i
                                            class="fas fa-eye me-1"></i>View Only</span><?php endif; ?>
                            </div>
                            <div class="card-mis-body">
                                <?php if ($detail): ?>
                                    <div class="row g-3 mb-4">
                                        <?php foreach ([
                                            'Firm Name' => htmlspecialchars($detail['firm_name'] ?? $task['company_name'] ?? '—'),
                                            'PAN No' => htmlspecialchars($detail['pan_no'] ?? ($companyData['pan_number'] ?? '—')),
                                            'Grade' => htmlspecialchars($detail['grade_name'] ?? '—'),
                                            'Fiscal Year' => htmlspecialchars($detail['fiscal_year_label'] ?: ($detail['fiscal_year'] ?? $task['fiscal_year'] ?? '—')),
                                            'Assigned To' => htmlspecialchars($detail['assigned_to_name'] ?? $taskAssignedToName),
                                            'Finalised By' => htmlspecialchars($detail['finalised_by_name'] ?? '—'),
                                            'Completed Date' => ($detail['completed_date'] ?? '') ? date('d M Y', strtotime($detail['completed_date'])) : '—',
                                            'Remarks' => htmlspecialchars($detail['remarks'] ?? '—'),
                                        ] as $lbl => $val): ?>
                                            <div class="col-md-4">
                                                <div
                                                    style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;">
                                                    <?= $lbl ?></div>
                                                <div style="font-size:.88rem;margin-top:.2rem;"><?= $val ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($canEditDept): ?>
                                        <hr style="border-color:#f3f4f6;"><?php endif; ?>
                                <?php elseif (!$canEditDept): ?>
                                    <div class="text-center py-4 text-muted"><i
                                            class="fas fa-file-circle-question fa-2x mb-2 d-block opacity-50"></i>
                                        <p style="font-size:.9rem;">No corporate details yet.</p>
                                    </div>
                                <?php endif; ?>

                                <?php if ($canEditDept):
                                    // Resolve: saved → company → task → current FY
                                    $cf_firm = $detail['firm_name'] ?? $task['company_name'] ?? '';
                                    $cf_pan = $detail['pan_no'] ?? ($companyData['pan_number'] ?? '');
                                    $cf_grade = $detail['grade_id'] ?? '';
                                    $cf_fy = $detail['fiscal_year'] ?? ($task['fiscal_year'] ?? $currentFy);
                                    $cf_at_id = $taskAssignedToId;
                                    $cf_at_name = $taskAssignedToName;
                                    $cf_fb = $detail['finalised_by'] ?? '';
                                    $cf_cd = $detail['completed_date'] ?? '';
                                    $cf_remarks = $detail['remarks'] ?? '';
                                    $isLinked = !empty($task['company_id']);
                                    $firmRo = $isLinked ? 'readonly style="background:#f0fdf4;color:#374151;font-weight:500;cursor:not-allowed;"' : '';
                                    $panRo = ($isLinked && !empty($companyData['pan_number'])) ? 'readonly style="background:#f0fdf4;color:#374151;font-weight:500;cursor:not-allowed;"' : '';
                                    ?>
                                    <div
                                        style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:.75rem;">
                                        <i class="fas fa-pen me-1"></i><?= $detail ? 'Update' : 'Add' ?> Corporate Details</div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="save_corporate" value="1">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label-mis">Firm Name <?php if ($isLinked): ?><span
                                                            style="font-size:.65rem;color:#16a34a;margin-left:.3rem;"><i
                                                                class="fas fa-link me-1"></i>from
                                                            company</span><?php endif; ?></label>
                                                <input type="text" name="corporate[firm_name]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($cf_firm) ?>" <?= $firmRo ?>>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label-mis">PAN No
                                                    <?php if ($isLinked && !empty($companyData['pan_number'])): ?><span
                                                            style="font-size:.65rem;color:#16a34a;margin-left:.3rem;"><i
                                                                class="fas fa-link me-1"></i>from
                                                            company</span><?php endif; ?></label>
                                                <input type="text" name="corporate[pan_no]" class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($cf_pan) ?>" <?= $panRo ?>>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label-mis">Grade</label>
                                                <select name="corporate[grade_id]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($corpGrades as $cg): ?>
                                                        <option value="<?= $cg['id'] ?>" <?= $cf_grade == $cg['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($cg['grade_name']) ?></option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Fiscal Year <span class="required-star">*</span>
                                                    <?php if (!($detail['fiscal_year'] ?? '') && ($task['fiscal_year'] ?? '')): ?><span
                                                            style="font-size:.65rem;color:#3b82f6;margin-left:.3rem;"><i
                                                                class="fas fa-link me-1"></i>from task</span><?php endif; ?>
                                                </label>
                                                <?= fiscalYearSelect('corporate[fiscal_year]', $cf_fy, $fys, 'form-select form-select-sm', true) ?>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Assigned To <span
                                                        style="font-size:.65rem;color:#3b82f6;margin-left:.3rem;"><i
                                                            class="fas fa-link me-1"></i>from task</span></label>
                                                <div class="form-control form-control-sm"
                                                    style="background:#eff6ff;color:#1d4ed8;font-weight:600;cursor:default;display:flex;align-items:center;gap:.4rem;">
                                                    <i class="fas fa-user-circle"
                                                        style="color:#3b82f6;"></i><?= htmlspecialchars($cf_at_name) ?></div>
                                                <input type="hidden" name="corporate[assigned_to]"
                                                    value="<?= htmlspecialchars($cf_at_id ?? '') ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label-mis">Finalised By</label>
                                                <select name="corporate[finalised_by]" class="form-select form-select-sm">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach ($allStaff as $s): ?>
                                                        <option value="<?= $s['id'] ?>" <?= $cf_fb == $s['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($s['full_name']) ?></option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4"><label class="form-label-mis">Completed Date</label><input
                                                    type="date" name="corporate[completed_date]"
                                                    class="form-control form-control-sm"
                                                    value="<?= htmlspecialchars($cf_cd) ?>"></div>
                                            <div class="col-12"><label class="form-label-mis">Remarks</label><textarea
                                                    name="corporate[remarks]" class="form-control form-control-sm"
                                                    rows="2"><?= htmlspecialchars($cf_remarks) ?></textarea></div>
                                            <div class="col-12"><button type="submit" class="btn btn-gold btn-sm"><i
                                                        class="fas fa-save me-1"></i>Save Corporate Details</button></div>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php elseif ($detailTable && !$detail): ?>
                        <div class="card-mis mb-4" style="border-left:3px solid #f59e0b;">
                            <div class="card-mis-body text-center py-4"><i
                                    class="fas fa-table fa-2x mb-2 d-block text-warning"></i>
                                <p style="font-size:.9rem;color:#6b7280;"><?= htmlspecialchars($task['dept_name']) ?>
                                    details not filled yet.</p>
                                <?php if ($canEditDept): ?><a href="edit.php?id=<?= $id ?>" class="btn btn-gold btn-sm"><i
                                            class="fas fa-plus me-1"></i>Add Details</a><?php endif; ?>
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
                                    <div class="avatar-circle avatar-sm flex-shrink-0">
                                        <?= strtoupper(substr($c['full_name'] ?? '?', 0, 2)) ?></div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex gap-2 align-items-center"><strong
                                                style="font-size:.85rem;"><?= htmlspecialchars($c['full_name']) ?></strong><span
                                                style="font-size:.72rem;color:#9ca3af;"><?= date('M j, Y H:i', strtotime($c['created_at'])) ?></span>
                                        </div>
                                        <div
                                            style="font-size:.88rem;margin-top:.25rem;background:#f9fafb;padding:.6rem .9rem;border-radius:8px;">
                                            <?= nl2br(htmlspecialchars($c['comment'])) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($comments)): ?>
                                <div class="text-muted text-center py-3" style="font-size:.85rem;">No comments yet.</div>
                            <?php endif; ?>
                            <form method="POST" class="mt-3 d-flex gap-2"><input type="hidden" name="csrf_token"
                                    value="<?= csrfToken() ?>"><input type="hidden" name="add_comment" value="1"><input
                                    type="text" name="comment" class="form-control" placeholder="Add a comment…"
                                    required><button type="submit"
                                    class="btn btn-gold btn-sm flex-shrink-0">Post</button></form>
                        </div>
                    </div>

                    <!-- Workflow -->
                    <?php if (!empty($workflow)): ?>
                        <div class="card-mis mt-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-history text-warning me-2"></i>Workflow History</h5>
                            </div>
                            <div class="card-mis-body">
                                <?php foreach ($workflow as $w): ?>
                                    <div class="d-flex gap-3 mb-3">
                                        <div
                                            style="width:32px;height:32px;border-radius:50%;background:#eff6ff;color:#3b82f6;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.75rem;">
                                            <i
                                                class="fas fa-<?= match ($w['action']) { 'created' => 'plus', 'assigned' => 'user-check', 'status_changed' => 'circle-dot', 'transferred_dept' => 'exchange-alt', 'transferred_staff' => 'user-arrows', 'completed' => 'check-circle', default => 'pen'} ?>"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div style="font-size:.82rem;font-weight:600;color:#1f2937;">
                                                <?= ucwords(str_replace('_', ' ', $w['action'])) ?>        <?php if ($w['from_name']): ?>
                                                    by
                                                    <?= htmlspecialchars($w['from_name']) ?>        <?php endif; ?>        <?php if ($w['to_name']): ?>
                                                    → <?= htmlspecialchars($w['to_name']) ?><?php endif; ?></div>
                                            <?php if ($w['from_dept'] || $w['to_dept']): ?>
                                                <div style="font-size:.75rem;color:#8b5cf6;">
                                                    <?= htmlspecialchars($w['from_dept'] ?? '') ?>            <?= ($w['from_dept'] && $w['to_dept']) ? ' → ' : '' ?>            <?= htmlspecialchars($w['to_dept'] ?? '') ?>
                                                </div><?php endif; ?>
                                            <?php if ($w['old_status'] || $w['new_status']): ?>
                                                <div style="font-size:.75rem;color:#9ca3af;">
                                                    <?= htmlspecialchars($w['old_status'] ?? '') ?>            <?= ($w['old_status'] && $w['new_status']) ? ' → ' : '' ?>            <?= htmlspecialchars($w['new_status'] ?? '') ?>
                                                </div><?php endif; ?>
                                            <?php if ($w['remarks']): ?>
                                                <div style="font-size:.78rem;color:#6b7280;font-style:italic;margin-top:.2rem;">
                                                    "<?= htmlspecialchars($w['remarks']) ?>"</div><?php endif; ?>
                                            <div style="font-size:.7rem;color:#9ca3af;margin-top:.2rem;">
                                                <?= date('d M Y, H:i', strtotime($w['created_at'])) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div><!-- col-lg-8 -->

                <!-- Right Column -->
                <div class="col-lg-4">

                    <?php if ($task['dept_code'] === 'RETAIL' && $detail && $canEditDept): ?>
                        <div class="card-mis mb-3">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-tasks text-warning me-2"></i>Work Progress</h5>
                            </div>
                            <div class="card-mis-body">
                                <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input
                                        type="hidden" name="update_progress" value="1">
                                    <div class="row g-3">
                                        <?php foreach ([['Work Status', 'work_status_id'], ['Finalisation Status', 'finalisation_status_id'], ['Tax Clearance Status', 'tax_clearance_status_id']] as [$lbl, $f]): ?>
                                            <div class="col-12"><label class="form-label-mis"><?= $lbl ?></label><select
                                                    name="progress[<?= $f ?>]" class="form-select form-select-sm">
                                                    <option value="">--</option><?php foreach ($taskStatuses as $ts): ?>
                                                        <option value="<?= $ts['id'] ?>"
                                                            <?= ($detail[$f] ?? '') == $ts['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ts['status_name']) ?></option><?php endforeach; ?>
                                                </select></div>
                                        <?php endforeach; ?>
                                        <div class="col-12"><label class="form-label-mis">Finalised By</label><select
                                                name="progress[finalised_by]" class="form-select form-select-sm">
                                                <option value="">--</option><?php foreach ($allStaff as $s): ?>
                                                    <option value="<?= $s['id'] ?>"
                                                        <?= ($detail['finalised_by'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($s['full_name']) ?></option><?php endforeach; ?>
                                            </select></div>
                                        <div class="col-12"><label class="form-label-mis">Completed Date</label><input
                                                type="date" name="progress[completed_date]"
                                                class="form-control form-control-sm"
                                                value="<?= htmlspecialchars($detail['completed_date'] ?? '') ?>"></div>
                                        <div class="col-12"><label class="form-label-mis">Backup Status</label><select
                                                name="progress[backup_status_id]" class="form-select form-select-sm">
                                                <option value="">--</option><?php foreach ($yesNo as $yn): ?>
                                                    <option value="<?= $yn['id'] ?>"
                                                        <?= ($detail['backup_status_id'] ?? '') == $yn['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($yn['value']) ?></option><?php endforeach; ?>
                                            </select></div>
                                        <div class="col-12"><label class="form-label-mis">Follow-up Date</label><input
                                                type="date" name="progress[follow_up_date]"
                                                class="form-control form-control-sm"
                                                value="<?= htmlspecialchars($detail['follow_up_date'] ?? '') ?>"></div>
                                        <div class="col-12"><label class="form-label-mis">Notes</label><textarea
                                                name="progress[notes]" class="form-control form-control-sm"
                                                rows="2"><?= htmlspecialchars($detail['notes'] ?? '') ?></textarea></div>
                                        <div class="col-12"><button type="submit" class="btn btn-gold w-100 btn-sm"><i
                                                    class="fas fa-save me-1"></i>Update Progress</button></div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card-mis mb-3">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-circle-dot text-warning me-2"></i>Update Status</h5>
                        </div>
                        <div class="card-mis-body">
                            <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input
                                    type="hidden" name="update_status" value="1">
                                <div class="mb-3">
                                    <?php foreach ($taskStatuses as $ts):
                                        $sKey = $ts['status_name'];
                                        $sCol = TASK_STATUSES[$sKey]['color'] ?? '#9ca3af';
                                        $sBg = TASK_STATUSES[$sKey]['bg'] ?? '#f3f4f6'; ?>
                                        <div class="form-check mb-2"><input class="form-check-input" type="radio"
                                                name="new_status" value="<?= htmlspecialchars($sKey) ?>"
                                                id="st_<?= $ts['id'] ?>" <?= ($task['status'] ?? '') === $sKey ? 'checked' : '' ?>><label class="form-check-label" for="st_<?= $ts['id'] ?>"><span
                                                    style="background:<?= $sBg ?>;color:<?= $sCol ?>;padding:.2rem .6rem;border-radius:99px;font-size:.78rem;font-weight:600;"><?= htmlspecialchars($sKey) ?></span></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" class="btn btn-gold w-100 btn-sm"><i
                                        class="fas fa-save me-1"></i>Update Status</button>
                            </form>
                        </div>
                    </div>

                    <div class="card-mis p-3" style="font-size:.8rem;color:#6b7280;border-left:3px solid var(--gold);">
                        <div class="mb-2"><strong>Task #:</strong> <?= htmlspecialchars($task['task_number']) ?></div>
                        <div class="mb-2"><strong>Department:</strong> <?= htmlspecialchars($task['dept_name'] ?? '—') ?>
                        </div>
                        <div class="mb-2"><strong>Branch:</strong> <?= htmlspecialchars($task['branch_name'] ?? '—') ?>
                        </div>
                        <div class="mb-2"><strong>Priority:</strong> <?= ucfirst($task['priority'] ?? '—') ?></div>
                        <div class="mb-2"><strong>Created:</strong>
                            <?= date('d M Y, H:i', strtotime($task['created_at'])) ?></div>
                        <div><strong>Updated:</strong> <?= date('d M Y, H:i', strtotime($task['updated_at'])) ?></div>
                    </div>

                </div><!-- col-lg-4 -->
            </div><!-- row -->
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>