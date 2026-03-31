<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireExecutive();

$db = getDB();
$pageTitle = 'Company-wise Report';

$search = trim($_GET['search'] ?? '');
$filterB = (int) ($_GET['branch_id'] ?? 0);
$companyId = (int) ($_GET['company_id'] ?? 0); // direct link or expand
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ['c.is_active = 1'];
$params = [];

if ($search) {
    // Search by name, PAN, OR company code
    $where[] = '(c.company_name LIKE ? OR c.pan_number LIKE ? OR c.company_code LIKE ?)';
    $params[] = "%$search%";
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

// Company list with task counts
$coSt = $db->prepare("
    SELECT c.id, c.company_name, c.company_code, c.pan_number,
           b.branch_name,
           ct.type_name AS company_type_name,
           COUNT(DISTINCT t.id)                                          AS task_count,
           SUM(CASE WHEN ts.status_name = 'Done'  THEN 1 ELSE 0 END)   AS done_count,
           SUM(CASE WHEN ts.status_name != 'Done'
                     AND t.due_date IS NOT NULL
                     AND t.due_date < NOW()        THEN 1 ELSE 0 END)   AS overdue_count
    FROM companies c
    LEFT JOIN branches b       ON b.id  = c.branch_id
    LEFT JOIN company_types ct ON ct.id = c.company_type_id
    LEFT JOIN tasks t          ON t.company_id = c.id AND t.is_active = 1
    LEFT JOIN task_status ts   ON ts.id = t.status_id
    WHERE {$ws}
    GROUP BY c.id, c.company_name, c.company_code, c.pan_number,
             b.branch_name, ct.type_name
    ORDER BY c.company_name ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$coSt->execute($params);
$companies = $coSt->fetchAll();

$allBranches = $db->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name")->fetchAll();

// If a single company is expanded, pre-load its workflow data
$expandedData = [];
if ($companyId && count($companies) === 1) {
    $co = $companies[0];

    // Tasks
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
    $taskSt->execute([$companyId]);
    $expandedData[$companyId]['tasks'] = $taskSt->fetchAll();
}

include '../../includes/header.php';
?>
<style>
    .badge {
        font-size: .7rem;
        padding: .25rem .55rem;
        border-radius: 999px;
    }

    .bg-primary-soft {
        background: rgba(59, 130, 246, 0.12);
    }

    .bg-danger-soft {
        background: rgba(239, 68, 68, 0.12);
    }
</style>
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
                        <p>Browse all companies — click a row to expand its full task &amp; workflow history.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="index.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </a>

                    </div>
                </div>
            </div>

            <?= flashHtml() ?>

            <!-- Filters -->
            <div class="filter-bar mb-4 w-100">
                <form method="GET" class="row g-2 align-items-end w-100">
                    <div class="col-md-4">
                        <label class="form-label-mis">Search</label>
                        <div style="position:relative;">
                            <input type="text" name="search" class="form-control form-control-sm"
                                placeholder="Company name, PAN, or Code…" value="<?= htmlspecialchars($search) ?>"
                                style="padding-left:2rem;">
                            <i class="fas fa-search"
                                style="position:absolute;left:.6rem;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:.75rem;pointer-events:none;"></i>
                        </div>
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
                    <div class="col-md-3 d-flex align-items-end gap-1">
                        <button type="submit" class="btn btn-gold btn-sm w-100">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                        <a href="company_wise.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                    <div class="col-md-3 d-flex align-items-end justify-content-end">
                        <small class="text-muted"><?= number_format($total) ?> companies found</small>
                    </div>
                </form>
            </div>

            <!-- Company List -->
            <?php if (empty($companies)): ?>
                <div class="card-mis">
                    <div class="card-mis-body text-center py-5" style="color:#9ca3af;">
                        <i class="fas fa-building fa-2x mb-2 d-block"></i>
                        <p>No companies found.</p>
                    </div>
                </div>
            <?php else: ?>

                <!-- Summary strip -->
                <div class="card-mis mb-3 p-3">
                    <div class="d-flex gap-4 flex-wrap" style="font-size:.82rem;">
                        <?php
                        $totalTasks = array_sum(array_column($companies, 'task_count'));
                        $totalDone = array_sum(array_column($companies, 'done_count'));
                        $totalOverdue = array_sum(array_column($companies, 'overdue_count'));
                        ?>
                        <div><span style="color:#9ca3af;">Companies:</span> <strong><?= number_format($total) ?></strong>
                        </div>
                        <div><span style="color:#9ca3af;">Tasks:</span> <strong><?= number_format($totalTasks) ?></strong>
                        </div>
                        <div><span style="color:#10b981;">Done:</span> <strong><?= number_format($totalDone) ?></strong>
                        </div>
                        <?php if ($totalOverdue > 0): ?>
                            <div><span style="color:#ef4444;">Overdue:</span>
                                <strong><?= number_format($totalOverdue) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Company cards (accordion-style) -->
                <div id="company-list">
                    <?php foreach ($companies as $co):
                        $isExpanded = ($companyId === (int) $co['id']);
                        $hasOverdue = (int) $co['overdue_count'] > 0;
                        $completionPct = $co['task_count'] > 0 ? round(($co['done_count'] / $co['task_count']) * 100) : 0;
                        ?>
                        <div class="company-card card-mis mb-2" id="co-<?= $co['id'] ?>">

                            <!-- Company Row (clickable header) -->
                            <div class="company-row" onclick="toggleCompany(<?= $co['id'] ?>)" style="display:flex;align-items:center;justify-content:space-between;
                                   padding:.9rem 1.25rem;cursor:pointer;gap:1rem;flex-wrap:wrap;
                                   border-radius:inherit;transition:background .15s;"
                                onmouseenter="this.style.background='#f9fafb'"
                                onmouseleave="this.style.background='transparent'">

                                <!-- Left: avatar + info -->
                                <div class="d-flex align-items-center gap-3">
                                    <div style="width:40px;height:40px;border-radius:10px;background:#0a0f1e;color:#c9a84c;
                                    display:flex;align-items:center;justify-content:center;
                                    font-weight:700;font-size:.8rem;flex-shrink:0;">
                                        <?= strtoupper(substr($co['company_name'], 0, 2)) ?>
                                    </div>
                                    <div>
                                        <div style="font-weight:600;font-size:.9rem;color:#1f2937;">
                                            <?= htmlspecialchars($co['company_name']) ?>
                                        </div>
                                        <div class="d-flex gap-3 flex-wrap"
                                            style="font-size:.72rem;color:#9ca3af;margin-top:.15rem;">
                                            <?php if ($co['company_code']): ?>
                                                <span><i
                                                        class="fas fa-hashtag me-1"></i><?= htmlspecialchars($co['company_code']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($co['pan_number']): ?>
                                                <span><i
                                                        class="fas fa-id-card me-1"></i><?= htmlspecialchars($co['pan_number']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($co['branch_name']): ?>
                                                <span><i
                                                        class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($co['branch_name']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($co['company_type_name']): ?>
                                                <span><i
                                                        class="fas fa-tag me-1"></i><?= htmlspecialchars($co['company_type_name']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right: stats + chevron -->
                                <div class="d-flex align-items-center gap-3">
                                    <?php if ($co['task_count'] > 0): ?>
                                        <!-- Mini progress bar -->
                                        <div style="min-width:80px;">
                                            <div class="d-flex justify-content-between mb-1"
                                                style="font-size:.65rem;color:#9ca3af;">
                                                <span><?= $co['done_count'] ?>/<?= $co['task_count'] ?></span>
                                                <span><?= $completionPct ?>%</span>
                                            </div>
                                            <div style="background:#f3f4f6;border-radius:99px;height:4px;width:80px;">
                                                <div
                                                    style="width:<?= $completionPct ?>%;background:#10b981;height:100%;border-radius:99px;">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">

                                        <!-- Left: Status Info -->
                                        <div class="d-flex gap-1 align-items-center">
                                            <?php if ($co['task_count'] > 0): ?>
                                                <span class="badge bg-primary-soft text-primary fw-semibold">
                                                    <?= $co['task_count'] ?> tasks
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted">
                                                    No tasks
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($hasOverdue): ?>
                                                <span class="badge bg-danger-soft text-danger fw-semibold">
                                                    ⚠ <?= $co['overdue_count'] ?> overdue
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Right: Actions -->
                                        <div class="d-flex align-items-center gap-2">

                                            <!-- Export Excel -->
                                            <a href="<?= APP_URL ?>/exports/export_excel.php?module=company_wise&company_id=<?= $co['id'] ?>"
                                                class="btn btn-sm btn-outline-success d-flex align-items-center gap-1"
                                                onclick="event.stopPropagation();" title="Export to Excel">
                                                <i class="fas fa-file-excel"></i>
                                                <span class="d-none d-md-inline">Excel</span>
                                            </a>

                                            <!-- View Company -->
                                            <a href="<?= APP_URL ?>/executive/companies/view.php?id=<?= $co['id'] ?>"
                                                class="btn btn-sm btn-outline-secondary" onclick="event.stopPropagation();"
                                                title="View company">
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>

                                        </div>
                                    </div>

                                    <i class="fas fa-chevron-down chevron-icon" id="chev-<?= $co['id'] ?>" style="color:#9ca3af;font-size:.75rem;transition:transform .25s;
                                    <?= $isExpanded ? 'transform:rotate(180deg);' : '' ?>"></i>
                                </div>
                            </div>

                            <!-- Expandable workflow panel -->
                            <div class="workflow-panel" id="panel-<?= $co['id'] ?>" style="display:<?= $isExpanded ? 'block' : 'none' ?>;
                                   border-top:1px solid #f3f4f6;">
                                <div class="workflow-inner p-3" id="inner-<?= $co['id'] ?>">
                                    <!-- Loaded via JS -->
                                    <div class="text-center py-4" style="color:#9ca3af;font-size:.83rem;">
                                        <i class="fas fa-spinner fa-spin me-1"></i> Loading workflow…
                                    </div>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <small class="text-muted">
                            Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?>
                        </small>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link"
                                            href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹</a>
                                    </li>
                                <?php endif; ?>
                                <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                                    <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                                        <a class="page-link"
                                            href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($page < $pages): ?>
                                    <li class="page-item">
                                        <a class="page-link"
                                            href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">›</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>
        <?php include '../../includes/footer.php'; ?>
    </div>
</div>

<!-- Inline workflow loader (AJAX-like via fetch to workflow_ajax.php) -->
<script>
    const loadedPanels = new Set();

    function toggleCompany(id) {
        const panel = document.getElementById('panel-' + id);
        const chev = document.getElementById('chev-' + id);
        const inner = document.getElementById('inner-' + id);

        const isOpen = panel.style.display === 'block';

        if (isOpen) {
            panel.style.display = 'none';
            chev.style.transform = '';
        } else {
            panel.style.display = 'block';
            chev.style.transform = 'rotate(180deg)';

            // Load workflow only once
            if (!loadedPanels.has(id)) {
                loadedPanels.add(id);
                fetch('workflow_panel.php?company_id=' + id)
                    .then(r => r.text())
                    .then(html => { inner.innerHTML = html; })
                    .catch(() => { inner.innerHTML = '<p class="text-center py-3" style="color:#ef4444;">Failed to load. Please try again.</p>'; });
            }
        }
    }

    // Auto-expand if company_id was passed in URL
    <?php if ($companyId && !empty($companies)): ?>
        window.addEventListener('DOMContentLoaded', () => {
            toggleCompany(<?= $companyId ?>);
        });
    <?php endif; ?>
</script>