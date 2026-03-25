<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$currentUser = currentUser();
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid company.');
    header('Location: index.php');
    exit;
}

// ── POST: Edit company ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_company') {
    verifyCsrf();

    $fields = [
        'company_name'   => trim($_POST['company_name']   ?? ''),
        'contact_person' => trim($_POST['contact_person'] ?? ''),
        'contact_phone'  => trim($_POST['contact_phone']  ?? ''),
        'contact_email'  => trim($_POST['contact_email']  ?? ''),
        'pan_number'     => trim($_POST['pan_number']     ?? ''),
        'reg_number'     => trim($_POST['reg_number']     ?? ''),
        'return_type'    => trim($_POST['return_type']    ?? ''),
        'industry_id'    => (int)($_POST['industry_id']   ?? 0) ?: null,
        'address'        => trim($_POST['address']        ?? ''),
        'company_type_id'=> (int)($_POST['company_type_id'] ?? 0) ?: null,
        'branch_id'      => (int)($_POST['branch_id']     ?? 0) ?: null,
    ];

    $errors = [];
    if ($fields['company_name'] === '')
        $errors[] = 'Company name is required.';

    if (!$errors) {
        $db->prepare("
            UPDATE companies SET
                company_name    = ?,
                contact_person  = ?,
                contact_phone   = ?,
                contact_email   = ?,
                pan_number      = ?,
                reg_number      = ?,
                return_type     = ?,
                industry_id     = ?,
                address         = ?,
                company_type_id = ?,
                branch_id       = ?,
                updated_at      = NOW()
            WHERE id = ?
        ")->execute([
            $fields['company_name'],
            $fields['contact_person'],
            $fields['contact_phone'],
            $fields['contact_email'],
            $fields['pan_number'],
            $fields['reg_number'],
            $fields['return_type'] ?: null,
            $fields['industry_id'],
            $fields['address'],
            $fields['company_type_id'],
            $fields['branch_id'],
            $id,
        ]);
        logActivity("Edited company ID $id: {$fields['company_name']}", 'companies');
        setFlash('success', 'Company details updated.');
        header("Location: view.php?id={$id}");
        exit;
    }
    // If errors fall through — show them below
}

// Fetch company with industry
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
if (!$company) {
    setFlash('error', 'Company not found.');
    header('Location: index.php');
    exit;
}

// Lookups for edit form
$companyTypes  = $db->query("SELECT id, type_name FROM company_types ORDER BY type_name")->fetchAll();
$branches      = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();
$allIndustries = $db->query("SELECT id, industry_name FROM industries WHERE is_active=1 ORDER BY industry_name")->fetchAll();

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

$totalTasks   = count($tasks);
$statusCounts = [];
foreach ($tasks as $t) {
    $statusCounts[$t['status']] = ($statusCounts[$t['status']] ?? 0) + 1;
}

// Overdue count
$overdueCount = 0;
foreach ($tasks as $t) {
    if ($t['due_date'] && strtotime($t['due_date']) < time() && $t['status'] !== 'Done')
        $overdueCount++;
}

// Workflow history
$workflow = [];
try {
    $wfStmt = $db->prepare("
        SELECT tw.*, t.task_number,
               fu.full_name AS from_user_name, tu.full_name AS to_user_name,
               fd.dept_name AS from_dept_name,  td.dept_name AS to_dept_name
        FROM task_workflow tw
        JOIN tasks t             ON t.id  = tw.task_id
        LEFT JOIN users fu       ON fu.id = tw.from_user_id
        LEFT JOIN users tu       ON tu.id = tw.to_user_id
        LEFT JOIN departments fd ON fd.id = tw.from_dept_id
        LEFT JOIN departments td ON td.id = tw.to_dept_id
        WHERE t.company_id = ? AND t.is_active = 1
        ORDER BY tw.created_at DESC
        LIMIT 30
    ");
    $wfStmt->execute([$id]);
    $workflow = $wfStmt->fetchAll();
} catch (Exception $e) {}

$pageTitle = 'Company: ' . $company['company_name'];

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger rounded-3 mb-3">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Back + Actions -->
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
                <div class="d-flex gap-2">
                    <?php if (isCoreAdmin()): ?>
                        <button onclick="openEditModal()" class="btn btn-gold btn-sm">
                            <i class="fas fa-pen me-1"></i>Edit Company
                        </button>
                    <?php endif; ?>
                    <a href="<?= APP_URL ?>/admin/tasks/assign.php?company_id=<?= $id ?>"
                        class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-plus me-1"></i>Assign Task
                    </a>
                    <a href="<?= APP_URL ?>/executive/reports/company_wise.php?company_id=<?= $id ?>"
                        class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-sitemap me-1"></i>Workflow Report
                    </a>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">

                    <!-- Company Profile -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:50px;height:50px;border-radius:12px;background:#0a0f1e;color:#c9a84c;
                                    display:flex;align-items:center;justify-content:center;
                                    font-weight:700;font-size:.95rem;flex-shrink:0;">
                                    <?= strtoupper(substr($company['company_name'], 0, 2)) ?>
                                </div>
                                <div>
                                    <h5 style="margin:0;font-size:1rem;">
                                        <?= htmlspecialchars($company['company_name']) ?>
                                    </h5>
                                    <div style="font-size:.75rem;color:#9ca3af;">
                                        <?= htmlspecialchars($company['company_code'] ?? '') ?>
                                        <?php if ($company['company_type_name']): ?>
                                            · <?= htmlspecialchars($company['company_type_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <span style="background:#ecfdf5;color:#10b981;font-size:.75rem;padding:.25rem .65rem;border-radius:99px;font-weight:600;">
                                Active
                            </span>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3">
                                <?php foreach ([
                                    'Company Type'  => $company['company_type_name'] ?? '—',
                                    'Branch'        => $company['branch_name'] ?? '—',
                                    'Industry'      => $company['industry_name'] ?? '—',
                                    'PAN Number'    => $company['pan_number'] ?? '—',
                                    'Reg Number'    => $company['reg_number'] ?? '—',
                                    'Return Type'   => $company['return_type'] ?? '—',
                                    'Contact Person'=> $company['contact_person'] ?? '—',
                                    'Contact Phone' => $company['contact_phone'] ?? '—',
                                    'Contact Email' => $company['contact_email'] ?? '—',
                                    'Address'       => $company['address'] ?? '—',
                                    'Added By'      => $company['added_by_name'] ?? '—',
                                    'Added On'      => date('d M Y', strtotime($company['created_at'])),
                                ] as $label => $val): ?>
                                    <div class="col-md-4">
                                        <div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                            <?= $label ?>
                                        </div>
                                        <div style="font-size:.87rem;margin-top:.2rem;color:#1f2937;">
                                            <?= htmlspecialchars($val) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tasks Table -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-list-check text-warning me-2"></i>Tasks (<?= $totalTasks ?>)</h5>
                            <?php if ($overdueCount > 0): ?>
                                <span style="background:#fef2f2;color:#ef4444;font-size:.72rem;padding:.2rem .55rem;border-radius:99px;font-weight:600;">
                                    <?= $overdueCount ?> overdue
                                </span>
                            <?php endif; ?>
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
                                        <tr>
                                            <td colspan="8" class="empty-state"><i class="fas fa-list-check"></i>No tasks yet</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($tasks as $t):
                                        $sClass  = 'status-' . strtolower(str_replace(' ', '-', $t['status']));
                                        $overdue = $t['due_date'] && strtotime($t['due_date']) < time() && $t['status'] !== 'Done';
                                    ?>
                                        <tr <?= $overdue ? 'style="background:#fef2f2;"' : '' ?>>
                                            <td>
                                                <span class="task-number"><?= htmlspecialchars($t['task_number']) ?></span>
                                                <?php if ($overdue): ?>
                                                    <div style="font-size:.62rem;color:#ef4444;font-weight:700;">OVERDUE</div>
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
                                            <td style="font-size:.82rem;">
                                                <?= htmlspecialchars($t['assigned_to_name'] ?? '—') ?>
                                            </td>
                                            <td><span class="status-badge <?= $sClass ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                                            <td>
                                                <span style="font-size:.78rem;font-weight:600;color:<?= ['urgent' => '#ef4444', 'high' => '#f59e0b', 'medium' => '#3b82f6', 'low' => '#9ca3af'][$t['priority']] ?? '#9ca3af' ?>;">
                                                    <?= ucfirst($t['priority']) ?>
                                                </span>
                                            </td>
                                            <td style="font-size:.78rem;<?= $overdue ? 'color:#ef4444;font-weight:600;' : 'color:#9ca3af;' ?>">
                                                <?= $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—' ?>
                                            </td>
                                            <td>
                                                <a href="<?= APP_URL ?>/executive/tasks/view.php?id=<?= $t['id'] ?>"
                                                    class="btn btn-sm btn-outline-secondary"><i class="fas fa-eye"></i></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Workflow History -->
                    <?php if (!empty($workflow)): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-code-branch text-warning me-2"></i>Workflow History</h5>
                                <small class="text-muted"><?= count($workflow) ?> events</small>
                            </div>
                            <div class="card-mis-body">
                                <div style="padding-left:.75rem;">
                                    <?php
                                    $actionColors = ['created' => '#3b82f6', 'assigned' => '#f59e0b', 'status_changed' => '#8b5cf6', 'transferred_staff' => '#06b6d4', 'transferred_dept' => '#ec4899', 'completed' => '#10b981', 'remarked' => '#9ca3af'];
                                    $actionLabels = ['created' => 'Created', 'assigned' => 'Assigned', 'status_changed' => 'Status Changed', 'transferred_staff' => 'Transferred to Staff', 'transferred_dept' => 'Transferred to Dept', 'completed' => 'Completed', 'remarked' => 'Remarked'];
                                    foreach ($workflow as $w):
                                        $ac = $actionColors[$w['action']] ?? '#9ca3af';
                                        $al = $actionLabels[$w['action']] ?? ucwords(str_replace('_', ' ', $w['action']));
                                    ?>
                                        <div style="position:relative;margin-bottom:1rem;padding-left:1.25rem;border-left:2px solid #f3f4f6;">
                                            <div style="position:absolute;left:-5px;top:4px;width:9px;height:9px;border-radius:50%;background:<?= $ac ?>;border:2px solid #fff;"></div>
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <span style="font-size:.78rem;font-weight:700;color:<?= $ac ?>;"><?= $al ?></span>
                                                <span class="task-number" style="font-size:.68rem;"><?= htmlspecialchars($w['task_number']) ?></span>
                                            </div>
                                            <div style="font-size:.75rem;color:#6b7280;margin-top:.1rem;">
                                                <?php if ($w['from_user_name']): ?>by <strong><?= htmlspecialchars($w['from_user_name']) ?></strong><?php endif; ?>
                                                <?php if ($w['to_user_name']): ?>→ <strong><?= htmlspecialchars($w['to_user_name']) ?></strong><?php endif; ?>
                                                <?php if ($w['from_dept_name'] && $w['to_dept_name'] && $w['from_dept_name'] !== $w['to_dept_name']): ?>
                                                    · <?= htmlspecialchars($w['from_dept_name']) ?> <i class="fas fa-arrow-right mx-1" style="font-size:.6rem;"></i> <?= htmlspecialchars($w['to_dept_name']) ?>
                                                <?php endif; ?>
                                                <?php if ($w['old_status'] && $w['new_status'] && $w['old_status'] !== $w['new_status']): ?>
                                                    <span style="background:#f3f4f6;border-radius:4px;padding:.1rem .35rem;font-size:.68rem;margin-left:.3rem;"><?= htmlspecialchars($w['old_status']) ?> → <?= htmlspecialchars($w['new_status']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($w['remarks']): ?>
                                                <div style="font-size:.72rem;color:#9ca3af;font-style:italic;margin-top:.2rem;">"<?= htmlspecialchars($w['remarks']) ?>"</div>
                                            <?php endif; ?>
                                            <div style="font-size:.68rem;color:#d1d5db;margin-top:.15rem;">
                                                <?= date('d M Y, H:i', strtotime($w['created_at'])) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                </div><!-- col-lg-8 -->

                <!-- RIGHT COLUMN -->
                <div class="col-lg-4">

                    <!-- Task Summary -->
                    <div class="card-mis mb-3">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-chart-pie text-warning me-2"></i>Task Summary</h5>
                        </div>
                        <div class="card-mis-body">
                            <?php if ($totalTasks > 0): ?>
                                <div class="row g-2 mb-3">
                                    <?php foreach ([['Total', $totalTasks, '#3b82f6', '#eff6ff'], ['Done', $statusCounts['Done'] ?? 0, '#10b981', '#ecfdf5'], ['Overdue', $overdueCount, '#ef4444', '#fef2f2']] as [$label, $val, $color, $bg]): ?>
                                        <div class="col-4 text-center">
                                            <div style="background:<?= $bg ?>;border-radius:8px;padding:.6rem .3rem;">
                                                <div style="font-size:1.3rem;font-weight:700;color:<?= $color ?>;"><?= $val ?></div>
                                                <div style="font-size:.68rem;color:#6b7280;"><?= $label ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php foreach (TASK_STATUSES as $k => $s):
                                    $cnt = $statusCounts[$k] ?? 0;
                                    if (!$cnt) continue;
                                    $pct = round(($cnt / $totalTasks) * 100);
                                ?>
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between mb-1" style="font-size:.75rem;">
                                            <span style="color:#1f2937;"><?= $s['label'] ?></span>
                                            <span style="color:#9ca3af;"><?= $cnt ?> (<?= $pct ?>%)</span>
                                        </div>
                                        <div style="background:#f3f4f6;border-radius:99px;height:5px;">
                                            <div style="width:<?= $pct ?>%;background:<?= $s['color'] ?>;height:100%;border-radius:99px;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php
                                $doneCount      = $statusCounts['Done'] ?? 0;
                                $completionRate = $totalTasks ? round(($doneCount / $totalTasks) * 100) : 0;
                                $circumference  = round(2 * 3.14159 * 32);
                                $dashArr        = round($circumference * $completionRate / 100);
                                ?>
                                <div style="border-top:1px solid #f3f4f6;padding-top:1rem;margin-top:.75rem;text-align:center;">
                                    <div style="font-size:.75rem;color:#9ca3af;margin-bottom:.5rem;">Completion Rate</div>
                                    <div style="position:relative;display:inline-block;">
                                        <svg width="80" height="80" viewBox="0 0 80 80">
                                            <circle cx="40" cy="40" r="32" fill="none" stroke="#f3f4f6" stroke-width="8"/>
                                            <circle cx="40" cy="40" r="32" fill="none" stroke="#10b981" stroke-width="8"
                                                stroke-dasharray="<?= $dashArr ?> <?= $circumference ?>"
                                                stroke-linecap="round" transform="rotate(-90 40 40)"/>
                                        </svg>
                                        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:.85rem;font-weight:700;color:#10b981;">
                                            <?= $completionRate ?>%
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-3" style="color:#9ca3af;font-size:.85rem;">No tasks yet</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Info -->
                    <div class="card-mis mb-3 p-3" style="font-size:.82rem;color:#6b7280;">
                        <?php foreach ([
                            'Code'        => $company['company_code'] ?? '—',
                            'PAN'         => $company['pan_number'] ?? '—',
                            'Reg #'       => $company['reg_number'] ?? '—',
                            'Industry'    => $company['industry_name'] ?? '—',
                            'Return Type' => $company['return_type'] ?? '—',
                            'Branch'      => $company['branch_name'] ?? '—',
                            'Type'        => $company['company_type_name'] ?? '—',
                            'Added'       => date('d M Y', strtotime($company['created_at'])),
                        ] as $k => $v): ?>
                            <div class="mb-2"><strong><?= $k ?>:</strong> <?= htmlspecialchars($v) ?></div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Dept breakdown -->
                    <?php if ($totalTasks > 0):
                        $deptGroups = [];
                        foreach ($tasks as $t) {
                            $key = $t['dept_name'] ?? 'Unknown';
                            if (!isset($deptGroups[$key]))
                                $deptGroups[$key] = ['count' => 0, 'color' => $t['color'] ?? '#ccc'];
                            $deptGroups[$key]['count']++;
                        }
                        arsort($deptGroups);
                    ?>
                        <div class="card-mis p-3">
                            <div style="font-size:.8rem;font-weight:600;color:#1f2937;margin-bottom:.75rem;">Tasks by Department</div>
                            <?php foreach ($deptGroups as $deptName => $info):
                                $dpct = round(($info['count'] / $totalTasks) * 100);
                            ?>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between mb-1" style="font-size:.75rem;">
                                        <div class="d-flex align-items-center gap-1">
                                            <div style="width:7px;height:7px;border-radius:50%;background:<?= htmlspecialchars($info['color']) ?>;flex-shrink:0;"></div>
                                            <span style="color:#1f2937;"><?= htmlspecialchars($deptName) ?></span>
                                        </div>
                                        <span style="color:#9ca3af;"><?= $info['count'] ?></span>
                                    </div>
                                    <div style="background:#f3f4f6;border-radius:99px;height:4px;">
                                        <div style="width:<?= $dpct ?>%;background:<?= htmlspecialchars($info['color']) ?>;height:100%;border-radius:99px;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                </div><!-- col-lg-4 -->
            </div><!-- row -->
        </div>
    </div>
</div>

<!-- ── Edit Company Modal ──────────────────────────────────────────────────── -->
<div id="edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
            z-index:9999;align-items:flex-start;justify-content:center;
            overflow-y:auto;padding:2rem 1rem;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:620px;
                box-shadow:0 24px 60px rgba(0,0,0,.2);margin:auto;">

        <!-- Modal Header -->
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid #f3f4f6;
                    display:flex;align-items:center;justify-content:space-between;">
            <h5 style="margin:0;font-size:1rem;font-weight:700;">
                <i class="fas fa-pen text-warning me-2"></i>Edit Company Details
            </h5>
            <button onclick="closeEditModal()"
                style="background:none;border:none;font-size:1.1rem;color:#9ca3af;cursor:pointer;">✕</button>
        </div>

        <!-- Modal Body -->
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="edit_company">
            <div style="padding:1.5rem;display:grid;grid-template-columns:1fr 1fr;gap:1rem;">

                <!-- Full-width: Company Name -->
                <div style="grid-column:1/-1;">
                    <label class="form-label-mis">Company Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="company_name" class="form-control form-control-sm"
                        value="<?= htmlspecialchars($company['company_name']) ?>" required>
                </div>

                <!-- Company Type -->
                <div>
                    <label class="form-label-mis">Company Type</label>
                    <select name="company_type_id" class="form-select form-select-sm">
                        <option value="">-- Select --</option>
                        <?php foreach ($companyTypes as $ct): ?>
                            <option value="<?= $ct['id'] ?>" <?= $company['company_type_id'] == $ct['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ct['type_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Branch -->
                <div>
                    <label class="form-label-mis">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">-- Select --</option>
                        <?php foreach ($branches as $br): ?>
                            <option value="<?= $br['id'] ?>" <?= $company['branch_id'] == $br['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($br['branch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- PAN -->
                <div>
                    <label class="form-label-mis">PAN Number</label>
                    <input type="text" name="pan_number" class="form-control form-control-sm"
                        value="<?= htmlspecialchars($company['pan_number'] ?? '') ?>">
                </div>

                <!-- Reg Number -->
                <div>
                    <label class="form-label-mis">Registration Number</label>
                    <input type="text" name="reg_number" class="form-control form-control-sm"
                        value="<?= htmlspecialchars($company['reg_number'] ?? '') ?>">
                </div>

                <!-- Return Type -->
                <div>
                    <label class="form-label-mis">Return Type</label>
                    <select name="return_type" class="form-select form-select-sm">
                        <option value="">-- Select --</option>
                        <?php foreach (['D1', 'D2', 'D3', 'D4'] as $rt): ?>
                            <option value="<?= $rt ?>" <?= ($company['return_type'] ?? '') === $rt ? 'selected' : '' ?>>
                                <?= $rt ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Industry -->
                <div>
                    <label class="form-label-mis">Industry</label>
                    <select name="industry_id" class="form-select form-select-sm">
                        <option value="">-- Select --</option>
                        <?php foreach ($allIndustries as $ind): ?>
                            <option value="<?= $ind['id'] ?>"
                                <?= ($company['industry_id'] ?? '') == $ind['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ind['industry_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Contact Person -->
                <div>
                    <label class="form-label-mis">Contact Person</label>
                    <input type="text" name="contact_person" class="form-control form-control-sm"
                        value="<?= htmlspecialchars($company['contact_person'] ?? '') ?>">
                </div>

                <!-- Contact Phone -->
                <div>
                    <label class="form-label-mis">Contact Phone</label>
                    <input type="text" name="contact_phone" class="form-control form-control-sm"
                        value="<?= htmlspecialchars($company['contact_phone'] ?? '') ?>">
                </div>

                <!-- Contact Email — full width -->
                <div style="grid-column:1/-1;">
                    <label class="form-label-mis">Contact Email</label>
                    <input type="email" name="contact_email" class="form-control form-control-sm"
                        value="<?= htmlspecialchars($company['contact_email'] ?? '') ?>">
                </div>

                <!-- Address — full width -->
                <div style="grid-column:1/-1;">
                    <label class="form-label-mis">Address</label>
                    <textarea name="address" class="form-control form-control-sm" rows="2"
                        placeholder="Street, City, District…"><?= htmlspecialchars($company['address'] ?? '') ?></textarea>
                </div>

            </div>

            <!-- Modal Footer -->
            <div style="padding:1rem 1.5rem;border-top:1px solid #f3f4f6;
                        display:flex;justify-content:flex-end;gap:.75rem;">
                <button type="button" onclick="closeEditModal()"
                    style="background:#f3f4f6;color:#6b7280;border:none;border-radius:8px;
                           padding:.55rem 1.1rem;font-size:.85rem;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit" class="btn btn-gold btn-sm" style="padding:.55rem 1.25rem;">
                    <i class="fas fa-save me-1"></i>Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal() {
        const m = document.getElementById('edit-modal');
        m.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function closeEditModal() {
        document.getElementById('edit-modal').style.display = 'none';
        document.body.style.overflow = '';
    }
    document.getElementById('edit-modal').addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEditModal(); });
</script>

<?php include '../../includes/footer.php'; ?>