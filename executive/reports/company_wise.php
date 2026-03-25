<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$pageTitle = 'Company-wise Report';

$search = trim($_GET['search'] ?? '');
$filterB = (int) ($_GET['branch_id'] ?? 0);
$companyId = (int) ($_GET['company_id'] ?? 0); // direct link from companies page
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = ['c.is_active = 1'];
$params = [];

if ($search) {
    $where[] = '(c.company_name LIKE ? OR c.pan_number LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterB) {
    $where[] = 'c.branch_id = ?';
    $params[] = $filterB;
}
if ($companyId) {
    $where[] = 'c.id = ?';
    $params[] = $companyId;
}

$ws = implode(' AND ', $where);

// Count
$cntSt = $db->prepare("SELECT COUNT(*) FROM companies c WHERE {$ws}");
$cntSt->execute($params);
$total = (int) $cntSt->fetchColumn();
$pages = (int) ceil($total / $perPage);

// Company list — no department_id, status via task_status join
$coSt = $db->prepare("
    SELECT c.*,
           b.branch_name,
           ct.type_name AS company_type_name,
           COUNT(DISTINCT t.id) AS task_count,
           SUM(CASE WHEN ts.status_name = 'Done' THEN 1 ELSE 0 END) AS done_count
    FROM companies c
    LEFT JOIN branches b       ON b.id  = c.branch_id
    LEFT JOIN company_types ct ON ct.id = c.company_type_id
    LEFT JOIN tasks t          ON t.company_id = c.id AND t.is_active = 1
    LEFT JOIN task_status ts   ON ts.id = t.status_id
    WHERE {$ws}
    GROUP BY c.id, c.company_name, c.pan_number, c.company_code,
             c.contact_person, c.contact_phone, c.branch_id,
             b.branch_name, ct.type_name
    ORDER BY task_count DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$coSt->execute($params);
$companies = $coSt->fetchAll();

$allBranches = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <div class="page-hero">
                <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-building"></i> Company Report</div>
                        <h4>Company-wise Workflow Report</h4>
                        <p>Full task history — who did what, when, and current status.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">

                        <!-- Back -->
                        <a href="index.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>

                        <!-- Export Excel -->
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=company_wise&search=<?= urlencode($search) ?>&branch_id=<?= $filterB ?>"
                            class="btn btn-success btn-sm">
                            <i class="fas fa-file-excel me-1"></i>Export Excel
                        </a>

                        <!-- Export PDF -->
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=company_wise&search=<?= urlencode($search) ?>&branch_id=<?= $filterB ?>"
                            class="btn btn-danger btn-sm">
                            <i class="fas fa-file-pdf me-1"></i>Export PDF
                        </a>

                    </div>
                </div>
            </div>

            <?= flashHtml() ?>

            <!-- Filters -->
            <div class="filter-bar mb-4 w-100">
                <form method="GET" class="row g-2 align-items-end w-100">
                    <div class="col-md-4">
                        <label class="form-label-mis">Search Company</label>
                        <input type="text" name="search" class="form-control form-control-sm"
                            placeholder="Name or PAN..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">Branch</label>
                        <select name="branch_id" class="form-select form-select-sm">
                            <option value="">All Branches</option>
                            <?php foreach ($allBranches as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $filterB == $b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['branch_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-1">
                        <button type="submit" class="btn btn-gold btn-sm w-100">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                        <a href="company_wise.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>

            <?php if (empty($companies)): ?>
                <div class="card-mis">
                    <div class="card-mis-body text-center py-5" style="color:#9ca3af;">
                        <i class="fas fa-building fa-2x mb-2 d-block"></i>
                        <p>No companies found.</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($companies as $co):

                // Tasks for this company — status via join, dept_name not department_name
                $taskSt = $db->prepare("
        SELECT t.*,
               ts.status_name AS status,
               d.dept_name, d.color,
               u.full_name AS assigned_to_name
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        LEFT JOIN departments d  ON d.id  = t.department_id
        LEFT JOIN users u        ON u.id  = t.assigned_to
        WHERE t.company_id = ? AND t.is_active = 1
        ORDER BY t.created_at DESC
    ");
                $taskSt->execute([$co['id']]);
                $coTasks = $taskSt->fetchAll();

                ?>
                <div class="card-mis mb-4">

                    <!-- Company Header -->
                    <div class="card-mis-header" style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">
                        <div>
                            <div class="d-flex align-items-center gap-3">
                                <div
                                    style="width:42px;height:42px;border-radius:10px;background:#0a0f1e;color:#c9a84c;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.82rem;flex-shrink:0;">
                                    <?= strtoupper(substr($co['company_name'], 0, 2)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;font-size:.95rem;color:#1f2937;">
                                        <?= htmlspecialchars($co['company_name']) ?>
                                    </div>
                                    <div class="d-flex gap-3 mt-1" style="font-size:.75rem;color:#9ca3af;">
                                        <?php if ($co['pan_number']): ?>
                                            <span><i class="fas fa-id-card me-1"></i>PAN:
                                                <?= htmlspecialchars($co['pan_number']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($co['company_code']): ?>
                                            <span><i
                                                    class="fas fa-hashtag me-1"></i><?= htmlspecialchars($co['company_code']) ?></span>
                                        <?php endif; ?>
                                        <span><i
                                                class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($co['branch_name'] ?? '—') ?></span>
                                        <?php if ($co['company_type_name']): ?>
                                            <span><i
                                                    class="fas fa-tag me-1"></i><?= htmlspecialchars($co['company_type_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <span class="status-badge status-wip"><?= $co['task_count'] ?> tasks</span>
                            <span class="status-badge status-done"><?= $co['done_count'] ?> done</span>
                        </div>
                    </div>

                    <!-- Tasks -->
                    <div class="card-mis-body">
                        <?php if (empty($coTasks)): ?>
                            <p class="text-center py-3" style="font-size:.83rem;color:#9ca3af;">No tasks for this company.</p>
                        <?php endif; ?>

                        <?php foreach ($coTasks as $t):
                            $sClass = 'status-' . strtolower(str_replace(' ', '-', $t['status'] ?? ''));

                            // Workflow for this task
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
                            ?>

                            <div class="mb-4" style="border-bottom:1px solid #f3f4f6;padding-bottom:1rem;">

                                <!-- Task header -->
                                <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="task-number"><?= htmlspecialchars($t['task_number']) ?></span>
                                        <strong style="font-size:.9rem;"><?= htmlspecialchars($t['title']) ?></strong>
                                        <span
                                            style="font-size:.75rem;background:<?= htmlspecialchars($t['color'] ?? '#ccc') ?>22;color:<?= htmlspecialchars($t['color'] ?? '#666') ?>;padding:.2rem .55rem;border-radius:99px;">
                                            <?= htmlspecialchars($t['dept_name'] ?? '—') ?>
                                        </span>
                                        <span
                                            class="status-badge <?= $sClass ?>"><?= htmlspecialchars($t['status'] ?? '—') ?></span>
                                        <?php if ($t['assigned_to_name']): ?>
                                            <span style="font-size:.75rem;color:#6b7280;">
                                                <i class="fas fa-user me-1"></i><?= htmlspecialchars($t['assigned_to_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span style="font-size:.72rem;color:#9ca3af;">
                                        <?= date('d M Y', strtotime($t['created_at'])) ?>
                                    </span>
                                </div>

                                <!-- Workflow chain -->
                                <?php if (!empty($workflow)): ?>
                                    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.25rem;margin-top:.5rem;">
                                        <?php
                                        $actionColors = [
                                            'created' => '#3b82f6',
                                            'assigned' => '#f59e0b',
                                            'status_changed' => '#8b5cf6',
                                            'transferred_staff' => '#06b6d4',
                                            'transferred_dept' => '#ec4899',
                                            'completed' => '#10b981',
                                        ];
                                        $actionLabels = [
                                            'created' => 'Created',
                                            'assigned' => 'Assigned',
                                            'status_changed' => 'Status Updated',
                                            'transferred_staff' => 'Transferred',
                                            'transferred_dept' => 'Dept Transfer',
                                            'completed' => 'Completed',
                                        ];
                                        foreach ($workflow as $i => $w):
                                            $isLast = $i === count($workflow) - 1;
                                            $aLabel = $actionLabels[$w['action']] ?? $w['action'];
                                            $aColor = $actionColors[$w['action']] ?? '#9ca3af';
                                            ?>
                                            <div
                                                style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:.4rem .65rem;text-align:center;min-width:90px;<?= $isLast ? 'border-color:' . $aColor . ';background:' . $aColor . '10;' : '' ?>">
                                                <div style="font-size:.72rem;font-weight:700;color:<?= $aColor ?>;"><?= $aLabel ?></div>
                                                <div style="font-size:.75rem;font-weight:500;color:#1f2937;margin:.1rem 0;">
                                                    <?= htmlspecialchars($w['from_name'] ?? 'System') ?>
                                                </div>
                                                <?php if ($w['from_dept']): ?>
                                                    <div style="font-size:.65rem;color:#9ca3af;"><?= htmlspecialchars($w['from_dept']) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div style="font-size:.65rem;color:#9ca3af;">
                                                    <?= date('d M, H:i', strtotime($w['created_at'])) ?>
                                                </div>
                                                <?php if (!empty($w['remarks'])): ?>
                                                    <div style="font-size:.65rem;color:#6b7280;font-style:italic;margin-top:.2rem;max-width:100px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                                        title="<?= htmlspecialchars($w['remarks']) ?>">
                                                        "<?= htmlspecialchars($w['remarks']) ?>"
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!$isLast): ?>
                                                <div style="color:#c9a84c;font-size:.8rem;font-weight:700;">→</div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div style="font-size:.78rem;color:#9ca3af;margin-top:.5rem;font-style:italic;">
                                        No workflow history yet.
                                    </div>
                                <?php endif; ?>

                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Pagination -->
            <?php if ($pages > 1): ?>
                <div class="d-flex justify-content-center mt-3">
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($p = 1; $p <= $pages; $p++): ?>
                                <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>">
                                        <?= $p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>

        </div>
        <?php include '../../includes/footer.php'; ?>