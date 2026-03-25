<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAdmin();

$db   = getDB();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// Fetch company with type, branch, and industry
$coStmt = $db->prepare("
    SELECT c.*,
           ct.type_name    AS company_type_name,
           b.branch_name,
           i.industry_name,
           u.full_name     AS added_by_name
    FROM companies c
    LEFT JOIN company_types ct ON ct.id = c.company_type_id
    LEFT JOIN branches b       ON b.id  = c.branch_id
    LEFT JOIN industries i     ON i.id  = c.industry_id
    LEFT JOIN users u          ON u.id  = c.added_by
    WHERE c.id = ? AND c.is_active = 1
");
$coStmt->execute([$id]);
$company = $coStmt->fetch();
if (!$company) { setFlash('error', 'Company not found.'); header('Location: index.php'); exit; }

// All tasks for this company
$taskStmt = $db->prepare("
    SELECT t.*,
           ts.status_name AS status,
           d.dept_name, d.dept_code, d.color,
           b.branch_name,
           cb.full_name AS created_by_name,
           at.full_name AS assigned_to_name
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN departments d  ON d.id  = t.department_id
    LEFT JOIN branches b     ON b.id  = t.branch_id
    LEFT JOIN users cb       ON cb.id = t.created_by
    LEFT JOIN users at       ON at.id = t.assigned_to
    WHERE t.company_id = ? AND t.is_active = 1
    ORDER BY t.created_at DESC
");
$taskStmt->execute([$id]);
$tasks = $taskStmt->fetchAll();

// Task stats
$totalTasks   = count($tasks);
$statusCounts = [];
foreach ($tasks as $t) {
    $statusCounts[$t['status']] = ($statusCounts[$t['status']] ?? 0) + 1;
}

// Retail details for this company's retail tasks
$retailStmt = $db->prepare("
    SELECT tr.*,
           t.task_number, t.title,
           ws.status_name  AS work_status_name,
           fs.status_name  AS finalisation_status_name,
           tc.status_name  AS tax_clearance_status_name,
           bs.value        AS backup_status_value,
           at2.type_name   AS audit_type_name,
           fb.full_name    AS finalised_by_name,
           au.full_name    AS retail_assigned_to_name
    FROM task_retail tr
    JOIN tasks t            ON t.id   = tr.task_id
    LEFT JOIN task_status ws  ON ws.id  = tr.work_status_id
    LEFT JOIN task_status fs  ON fs.id  = tr.finalisation_status_id
    LEFT JOIN task_status tc  ON tc.id  = tr.tax_clearance_status_id
    LEFT JOIN yes_no bs       ON bs.id  = tr.backup_status_id
    LEFT JOIN audit_types at2 ON at2.id = tr.audit_type_id
    LEFT JOIN users fb        ON fb.id  = tr.finalised_by
    LEFT JOIN users au        ON au.id  = tr.assigned_to
    WHERE t.company_id = ? AND t.is_active = 1
    ORDER BY t.created_at DESC
");
$retailStmt->execute([$id]);
$retailDetails = $retailStmt->fetchAll();

// Workflow history for this company's tasks
$workflowStmt = $db->prepare("
    SELECT tw.*,
           t.task_number,
           fu.full_name AS from_user_name,
           tu.full_name AS to_user_name,
           fd.dept_name AS from_dept_name,
           td.dept_name AS to_dept_name
    FROM task_workflow tw
    JOIN tasks t              ON t.id   = tw.task_id
    LEFT JOIN users fu        ON fu.id  = tw.from_user_id
    LEFT JOIN users tu        ON tu.id  = tw.to_user_id
    LEFT JOIN departments fd  ON fd.id  = tw.from_dept_id
    LEFT JOIN departments td  ON td.id  = tw.to_dept_id
    WHERE t.company_id = ? AND t.is_active = 1
    ORDER BY tw.created_at DESC
    LIMIT 20
");
$workflowStmt->execute([$id]);
$workflow = $workflowStmt->fetchAll();

$pageTitle = 'Company: ' . $company['company_name'];

include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php include '../../includes/sidebar_admin.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>

<div style="padding:1.5rem 0;">
<?= flashHtml() ?>

<!-- Back -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <a href="index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Back
    </a>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/admin/tasks/assign.php?company_id=<?= $id ?>"
           class="btn btn-gold btn-sm">
            <i class="fas fa-plus me-1"></i>Assign Task
        </a>
    </div>
</div>

<div class="row g-4">

    <!-- ── LEFT COLUMN ── -->
    <div class="col-lg-8">

        <!-- Company Profile Card -->
        <div class="card-mis mb-4">
            <div class="card-mis-header">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:48px;height:48px;border-radius:10px;background:#0a0f1e;color:#c9a84c;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;flex-shrink:0;">
                        <?= strtoupper(substr($company['company_name'], 0, 2)) ?>
                    </div>
                    <div>
                        <h5 style="margin:0;font-size:1rem;"><?= htmlspecialchars($company['company_name']) ?></h5>
                        <div style="font-size:.75rem;color:#9ca3af;"><?= htmlspecialchars($company['company_code'] ?? '') ?></div>
                    </div>
                </div>
                <span class="badge" style="background:#f0fdf4;color:#16a34a;font-size:.75rem;">Active</span>
            </div>
            <div class="card-mis-body">
                <div class="row g-3">
                    <?php
                    $companyFields = [
                        'Company Type'    => $company['company_type_name'] ?? '—',
                        'Branch'          => $company['branch_name'] ?? '—',
                        'Industry'        => $company['industry_name'] ?? '—',
                        'PAN Number'      => $company['pan_number'] ?? '—',
                        'Reg Number'      => $company['reg_number'] ?? '—',
                        'Return Type'     => $company['return_type'] ?? '—',
                        'Contact Person'  => $company['contact_person'] ?? '—',
                        'Contact Phone'   => $company['contact_phone'] ?? '—',
                        'Contact Email'   => $company['contact_email'] ?? '—',
                        'Address'         => $company['address'] ?? '—',
                        'Added By'        => $company['added_by_name'] ?? '—',
                        'Added On'        => date('d M Y', strtotime($company['created_at'])),
                    ];
                    foreach ($companyFields as $label => $val):
                    ?>
                    <div class="col-md-4">
                        <div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;"><?= $label ?></div>
                        <div style="font-size:.87rem;margin-top:.2rem;color:#1f2937;"><?= htmlspecialchars($val) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Tasks Table -->
        <div class="card-mis mb-4">
            <div class="card-mis-header">
                <h5><i class="fas fa-list-check text-warning me-2"></i>Tasks (<?= $totalTasks ?>)</h5>
            </div>
            <div class="table-responsive">
                <table class="table-mis w-100">
                    <thead>
                        <tr>
                            <th>Task #</th>
                            <th>Title</th>
                            <th>Department</th>
                            <th>Assigned To</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Due Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($tasks)): ?>
                        <tr><td colspan="8" class="empty-state"><i class="fas fa-list-check"></i>No tasks yet</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tasks as $t):
                        $sClass  = 'status-' . strtolower(str_replace(' ', '-', $t['status']));
                        $overdue = $t['due_date'] && strtotime($t['due_date']) < time() && $t['status'] !== 'Done';
                    ?>
                        <tr <?= $overdue ? 'style="background:#fef2f2;"' : '' ?>>
                            <td>
                                <span class="task-number"><?= htmlspecialchars($t['task_number']) ?></span>
                                <?php if ($overdue): ?>
                                    <div style="font-size:.65rem;color:#ef4444;font-weight:600;">OVERDUE</div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.87rem;font-weight:500;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <?= htmlspecialchars($t['title']) ?>
                            </td>
                            <td>
                                <span style="font-size:.75rem;background:<?= htmlspecialchars($t['color'] ?? '#ccc') ?>22;color:<?= htmlspecialchars($t['color'] ?? '#666') ?>;padding:.2rem .5rem;border-radius:99px;">
                                    <?= htmlspecialchars($t['dept_name'] ?? '—') ?>
                                </span>
                            </td>
                            <td style="font-size:.82rem;"><?= htmlspecialchars($t['assigned_to_name'] ?? '—') ?></td>
                            <td><span class="status-badge <?= $sClass ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                            <td><span class="status-badge priority-<?= $t['priority'] ?>"><?= ucfirst($t['priority']) ?></span></td>
                            <td style="font-size:.78rem;<?= $overdue ? 'color:#ef4444;font-weight:600;' : 'color:#9ca3af;' ?>">
                                <?= $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—' ?>
                            </td>
                            <td>
                                <a href="<?= APP_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Retail Details per Task -->
        <?php if (!empty($retailDetails)): ?>
        <div class="card-mis mb-4">
            <div class="card-mis-header">
                <h5><i class="fas fa-store text-warning me-2"></i>Retail Task Details</h5>
            </div>
            <div class="card-mis-body">
                <?php foreach ($retailDetails as $r): ?>
                <div style="border:1px solid #f3f4f6;border-radius:10px;padding:1rem;margin-bottom:1rem;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <span class="task-number"><?= htmlspecialchars($r['task_number']) ?></span>
                            <span style="font-size:.82rem;color:#6b7280;margin-left:.5rem;"><?= htmlspecialchars($r['title']) ?></span>
                        </div>
                    </div>
                    <div class="row g-2">
                        <?php
                        $retailFields = [
                            'Firm Name'           => $r['firm_name'] ?? '—',
                            'Return Type'         => $r['return_type'] ?? '—',
                            'Fiscal Year'         => $r['fiscal_year'] ?? '—',
                            'Audit Years'         => $r['no_of_audit_year'] ?? '—',
                            'PAN No'              => $r['pan_no'] ?? '—',
                            'Audit Type'          => $r['audit_type_name'] ?? '—',
                            'ECD'                 => $r['ecd'] ? date('d M Y', strtotime($r['ecd'])) : '—',
                            'Opening Due'         => $r['opening_due'] ?? '—',
                            'Assigned To'         => $r['retail_assigned_to_name'] ?? '—',
                            'Assigned Date'       => $r['assigned_date'] ? date('d M Y', strtotime($r['assigned_date'])) : '—',
                            'Work Status'         => $r['work_status_name'] ?? '—',
                            'Finalisation Status' => $r['finalisation_status_name'] ?? '—',
                            'Finalised By'        => $r['finalised_by_name'] ?? '—',
                            'Completed Date'      => $r['completed_date'] ? date('d M Y', strtotime($r['completed_date'])) : '—',
                            'Tax Clearance'       => $r['tax_clearance_status_name'] ?? '—',
                            'Backup Status'       => $r['backup_status_value'] ?? '—',
                            'Follow-up Date'      => $r['follow_up_date'] ? date('d M Y', strtotime($r['follow_up_date'])) : '—',
                            'Notes'               => $r['notes'] ?? '—',
                        ];
                        foreach ($retailFields as $label => $val):
                        ?>
                        <div class="col-md-3 col-6">
                            <div style="font-size:.68rem;font-weight:700;color:#9ca3af;text-transform:uppercase;"><?= $label ?></div>
                            <div style="font-size:.82rem;color:#1f2937;"><?= htmlspecialchars($val) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
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
                <div style="position:relative;padding-left:1.5rem;">
                    <?php foreach ($workflow as $w): ?>
                    <div style="position:relative;margin-bottom:1.2rem;padding-left:1rem;border-left:2px solid #f3f4f6;">
                        <div style="position:absolute;left:-6px;top:4px;width:10px;height:10px;border-radius:50%;background:#c9a84c;border:2px solid #fff;"></div>
                        <div style="font-size:.8rem;font-weight:600;color:#1f2937;text-transform:capitalize;">
                            <?= htmlspecialchars(str_replace('_', ' ', $w['action'])) ?>
                            <span class="task-number ms-1" style="font-size:.7rem;"><?= htmlspecialchars($w['task_number']) ?></span>
                        </div>
                        <div style="font-size:.75rem;color:#6b7280;margin-top:.1rem;">
                            <?php if ($w['from_dept_name'] && $w['to_dept_name']): ?>
                                <?= htmlspecialchars($w['from_dept_name']) ?>
                                <i class="fas fa-arrow-right mx-1" style="font-size:.65rem;"></i>
                                <?= htmlspecialchars($w['to_dept_name']) ?>
                            <?php endif; ?>
                            <?php if ($w['from_user_name']): ?>
                                · by <?= htmlspecialchars($w['from_user_name']) ?>
                            <?php endif; ?>
                            <?php if ($w['to_user_name']): ?>
                                → <?= htmlspecialchars($w['to_user_name']) ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($w['remarks']): ?>
                        <div style="font-size:.73rem;color:#9ca3af;margin-top:.2rem;font-style:italic;">
                            "<?= htmlspecialchars($w['remarks']) ?>"
                        </div>
                        <?php endif; ?>
                        <div style="font-size:.7rem;color:#d1d5db;margin-top:.2rem;">
                            <?= date('d M Y, H:i', strtotime($w['created_at'])) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- end col-lg-8 -->

    <!-- ── RIGHT COLUMN ── -->
    <div class="col-lg-4">

        <!-- Task Status Summary -->
        <div class="card-mis mb-3">
            <div class="card-mis-header">
                <h5><i class="fas fa-chart-pie text-warning me-2"></i>Task Summary</h5>
            </div>
            <div class="card-mis-body">
                <?php if ($totalTasks > 0): ?>
                <?php foreach (TASK_STATUSES as $k => $s): ?>
                    <?php $cnt = $statusCounts[$k] ?? 0; if (!$cnt) continue; ?>
                    <?php $pct = round(($cnt / $totalTasks) * 100); ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between mb-1" style="font-size:.78rem;">
                            <span style="color:#1f2937;"><?= $s['label'] ?></span>
                            <span style="color:#9ca3af;"><?= $cnt ?> (<?= $pct ?>%)</span>
                        </div>
                        <div style="background:#f3f4f6;border-radius:99px;height:6px;">
                            <div style="width:<?= $pct ?>%;background:<?= $s['color'] ?>;height:100%;border-radius:99px;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="mt-3 pt-2 border-top d-flex justify-content-between" style="font-size:.82rem;">
                    <span style="color:#6b7280;">Total Tasks</span>
                    <strong style="color:#1f2937;"><?= $totalTasks ?></strong>
                </div>
                <?php else: ?>
                <div class="text-center py-3" style="color:#9ca3af;font-size:.85rem;">No tasks yet</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Company Quick Info -->
        <div class="card-mis mb-3 p-3" style="font-size:.82rem;color:#6b7280;">
            <div class="mb-2"><strong>Code:</strong> <?= htmlspecialchars($company['company_code'] ?? '—') ?></div>
            <div class="mb-2"><strong>PAN:</strong> <?= htmlspecialchars($company['pan_number'] ?? '—') ?></div>
            <div class="mb-2"><strong>Reg #:</strong> <?= htmlspecialchars($company['reg_number'] ?? '—') ?></div>
            <div class="mb-2"><strong>Industry:</strong> <?= htmlspecialchars($company['industry_name'] ?? '—') ?></div>
            <div class="mb-2"><strong>Return Type:</strong> <?= htmlspecialchars($company['return_type'] ?? '—') ?></div>
            <div class="mb-2"><strong>Branch:</strong> <?= htmlspecialchars($company['branch_name'] ?? '—') ?></div>
            <div><strong>Added:</strong> <?= date('d M Y', strtotime($company['created_at'])) ?></div>
        </div>

    </div><!-- end col-lg-4 -->

</div><!-- end row -->
</div>
<?php include '../../includes/footer.php'; ?>