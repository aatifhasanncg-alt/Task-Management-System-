<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAdmin();

$db   = getDB();
$user = currentUser();
$pageTitle = 'Companies';

$search      = trim($_GET['search']    ?? '');
$filterB     = (int)($_GET['branch_id'] ?? 0);
$filterT     = (int)($_GET['type_id']   ?? 0);
$filterInd   = (int)($_GET['industry_id'] ?? 0);
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 20;
$offset      = ($page - 1) * $perPage;

$where  = ['c.is_active = 1'];
$params = [];

// Admin scope: only their branch
if (!isExecutive()) {
    $adminStmt = $db->prepare("SELECT branch_id FROM users WHERE id = ?");
    $adminStmt->execute([$user['id']]);
    $adminBranchId = (int)$adminStmt->fetchColumn();
    if ($adminBranchId) {
        $where[]  = 'c.branch_id = ?';
        $params[] = $adminBranchId;
    }
}

if ($search) {
    $where[]  = '(c.company_name LIKE ? OR c.pan_number LIKE ? OR c.contact_person LIKE ? OR c.company_code LIKE ?)';
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($filterB)   { $where[] = 'c.branch_id = ?';        $params[] = $filterB; }
if ($filterT)   { $where[] = 'c.company_type_id = ?';  $params[] = $filterT; }
if ($filterInd) { $where[] = 'c.industry_id = ?';      $params[] = $filterInd; }

$ws = implode(' AND ', $where);

// Total count
$countSt = $db->prepare("SELECT COUNT(*) FROM companies c WHERE {$ws}");
$countSt->execute($params);
$total = (int)$countSt->fetchColumn();
$pages = (int)ceil($total / $perPage);

// Company list
$list = $db->prepare("
    SELECT c.*,
           ct.type_name    AS company_type_name,
           b.branch_name,
           i.industry_name,
           (SELECT COUNT(*) FROM tasks t WHERE t.company_id = c.id AND t.is_active = 1) AS task_count,
           (SELECT COUNT(*) FROM tasks t
            JOIN task_status ts ON ts.id = t.status_id
            WHERE t.company_id = c.id AND ts.status_name = 'Done') AS done_count
    FROM companies c
    LEFT JOIN company_types ct ON ct.id = c.company_type_id
    LEFT JOIN branches b       ON b.id  = c.branch_id
    LEFT JOIN industries i     ON i.id  = c.industry_id
    WHERE {$ws}
    ORDER BY c.company_name ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$list->execute($params);
$companies = $list->fetchAll();

// Dropdowns
$allTypes      = $db->query("SELECT id, type_name FROM company_types ORDER BY type_name")->fetchAll();
$allIndustries = $db->query("SELECT id, industry_name FROM industries WHERE is_active=1 ORDER BY industry_name")->fetchAll();

include '../../includes/header.php';
?>
<div class="app-wrapper">
<?php include '../../includes/sidebar_admin.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">

<div class="page-hero">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="page-hero-badge"><i class="fas fa-building"></i> Companies</div>
            <h4>Company Directory</h4>
            <p><?= number_format($total) ?> companies found</p>
        </div>
        <div class="d-flex gap-2">
            <a href="add.php" class="btn-gold btn"><i class="fas fa-plus me-1"></i>Add Company</a>
        </div>
    </div>
</div>

<?= flashHtml() ?>

<!-- Filters -->
<div class="filter-bar mb-4 w-100">
    <form method="GET" class="row g-2 align-items-end w-100">
        <div class="col-md-3">
            <label class="form-label-mis">Search</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="Name, code, PAN, contact..."
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label-mis">Company Type</label>
            <select name="type_id" class="form-select form-select-sm">
                <option value="">All Types</option>
                <?php foreach ($allTypes as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $filterT == $t['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t['type_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label-mis">Industry</label>
            <select name="industry_id" class="form-select form-select-sm">
                <option value="">All Industries</option>
                <?php foreach ($allIndustries as $ind): ?>
                    <option value="<?= $ind['id'] ?>" <?= $filterInd == $ind['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ind['industry_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-gold btn-sm w-100"><i class="fas fa-filter"></i> Filter</button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
        </div>
    </form>
</div>

<!-- Table -->
<div class="card-mis">
    <div class="card-mis-header">
        <h5><i class="fas fa-building text-warning me-2"></i>Companies</h5>
        <small class="text-muted"><?= $total ?> records</small>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Company</th>
                    <th>Branch</th>
                    <th>Industry</th>
                    <th>Contact</th>
                    <th>Tasks</th>
                    <th>Type</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($companies)): ?>
                <tr><td colspan="8" class="empty-state"><i class="fas fa-building"></i>No companies found</td></tr>
            <?php endif; ?>
            <?php foreach ($companies as $i => $co): ?>
                <tr>
                    <td class="text-muted" style="font-size:.78rem;"><?= $offset + $i + 1 ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div style="width:36px;height:36px;border-radius:8px;background:#0a0f1e;color:#c9a84c;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem;flex-shrink:0;">
                                <?= strtoupper(substr($co['company_name'], 0, 2)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold" style="font-size:.88rem;">
                                    <?= htmlspecialchars($co['company_name']) ?>
                                </div>
                                <div style="font-size:.72rem;color:#9ca3af;">
                                    <?= htmlspecialchars($co['company_code'] ?? '') ?>
                                    <?php if ($co['pan_number']): ?>
                                        · PAN: <?= htmlspecialchars($co['pan_number']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td style="font-size:.83rem;"><?= htmlspecialchars($co['branch_name'] ?? '—') ?></td>
                    <td style="font-size:.83rem;"><?= htmlspecialchars($co['industry_name'] ?? '—') ?></td>
                    <td>
                        <div style="font-size:.82rem;"><?= htmlspecialchars($co['contact_person'] ?? '—') ?></div>
                        <?php if ($co['contact_phone']): ?>
                            <div style="font-size:.73rem;color:#9ca3af;"><?= htmlspecialchars($co['contact_phone']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge status-wip"><?= $co['task_count'] ?> total</span>
                        <span class="status-badge status-file-returned ms-1"><?= $co['done_count'] ?> done</span>
                    </td>
                    <td>
                        <span class="badge bg-secondary" style="font-size:.72rem;">
                            <?= htmlspecialchars($co['company_type_name'] ?? '—') ?>
                        </span>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="<?= APP_URL ?>/admin/tasks/index.php?company_id=<?= $co['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" title="View Tasks">
                                <i class="fas fa-tasks"></i>
                            </a>
                            <a href="view.php?id=<?= $co['id'] ?>"
                               class="btn btn-sm btn-outline-info" title="View Company">
                                <i class="fas fa-eye"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center px-4 py-3 border-top">
        <small class="text-muted">
            Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?>
        </small>
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

</div>
<?php include '../../includes/footer.php'; ?>