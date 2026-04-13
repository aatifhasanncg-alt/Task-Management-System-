<?php
// admin/dashboard/index.php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAdmin();

$db = getDB();
$userSession = currentUser();
updateActiveAt($db, (int)$userSession['id']); 
$stmt = $db->prepare("
    SELECT u.*, r.role_name, b.branch_name, d.dept_name
    FROM users u
    LEFT JOIN roles       r ON r.id = u.role_id
    LEFT JOIN branches    b ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.id = ?
");
$stmt->execute([$userSession['id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Admin Dashboard';
$adminBranchId = (int) ($user['branch_id'] ?? 0);
$adminDeptId = (int) ($user['department_id'] ?? 0);
$adminUserId = (int) $userSession['id'];

// ── SCOPE WHERE — exactly 6 positional ? placeholders ────────────────────────
// Slot 1: branchId   (t.branch_id = ?)
// Slot 2: deptId     (t.department_id = ?)
// Slot 3: deptId     (tw.from_dept_id = ?)
// Slot 4: deptId     (tw.to_dept_id   = ?)
// Slot 5: userId     (t.created_by    = ?)
// Slot 6: userId     (t.assigned_to   = ?)
$scopeWhere = "(
    (t.branch_id = ? AND t.department_id = ?)
    OR EXISTS (
        SELECT 1 FROM task_workflow tw
        WHERE tw.task_id    = t.id
          AND tw.action     = 'transferred_dept'
          AND tw.from_dept_id = ?
    )
    OR EXISTS (
        SELECT 1 FROM task_workflow tw
        WHERE tw.task_id  = t.id
          AND tw.action   = 'transferred_dept'
          AND tw.to_dept_id = ?
    )
    OR t.created_by  = ?
    OR t.assigned_to = ?
)";

// One canonical set of 6 scope params — reuse via array_merge
$sp = [$adminBranchId, $adminDeptId, $adminDeptId, $adminDeptId, $adminUserId, $adminUserId];

$allStatuses = $db->query(
    "SELECT id, status_name, color, bg_color, icon
     FROM task_status
     WHERE status_name != 'Corporate Team'
     ORDER BY id"
)->fetchAll();

// ── 1. Status counts ─────────────────────────────────────────────────────────
// $scopeWhere appears once → 6 params
$byStatusStmt = $db->prepare("
    SELECT ts.status_name, COUNT(DISTINCT t.id) AS cnt
    FROM task_status ts
    LEFT JOIN tasks t
        ON  t.status_id = ts.id
        AND t.is_active = 1
        AND {$scopeWhere}
    WHERE ts.status_name != 'Corporate Team'
    GROUP BY ts.id, ts.status_name
");
$byStatusStmt->execute($sp);                // ✓ 6 params
$byStatus = array_column($byStatusStmt->fetchAll(), 'cnt', 'status_name');
$total = array_sum($byStatus);

// ── 2. Staff count ───────────────────────────────────────────────────────────
$scStmt = $db->prepare("
    SELECT COUNT(*) FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.role_name = 'staff' AND u.is_active = 1
      AND u.branch_id = ? AND u.department_id = ?
");
$scStmt->execute([$adminBranchId, $adminDeptId]);
$staffCount = (int) $scStmt->fetchColumn();

// ── 3. Transfer activity ──────────────────────────────────────────────────────
$transferIn = $transferOut = 0;
try {
    $tIn = $db->prepare("
        SELECT COUNT(DISTINCT tw.task_id)
        FROM task_workflow tw
        JOIN tasks t ON t.id = tw.task_id AND t.is_active=1 AND t.branch_id=?
        WHERE tw.action='transferred_dept' AND tw.to_dept_id=?
    ");
    $tIn->execute([$adminBranchId, $adminDeptId]);
    $transferIn = (int) $tIn->fetchColumn();

    $tOut = $db->prepare("
        SELECT COUNT(DISTINCT tw.task_id)
        FROM task_workflow tw
        JOIN tasks t ON t.id = tw.task_id AND t.is_active=1 AND t.branch_id=?
        WHERE tw.action='transferred_dept' AND tw.from_dept_id=?
    ");
    $tOut->execute([$adminBranchId, $adminDeptId]);
    $transferOut = (int) $tOut->fetchColumn();
} catch (Exception $e) {
}

// ── 4. Staff performance — UNION ─────────────────────────────────────────────
// $scopeWhere appears TWICE (once per UNION arm) → 6 × 2 = 12 scope params
// + final WHERE branch+dept = 2 params
// Total: 14 params
$statusCols = '';
foreach ($allStatuses as $st) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
    $quoted = $db->quote($st['status_name']);
    $statusCols .= "SUM(CASE WHEN ut.status_name = {$quoted} THEN 1 ELSE 0 END) AS `{$safe}`,\n        ";
}

// Staff performance: avoid ? inside UNION subquery (MariaDB limitation).
// Subquery fetches ALL active task assignments/transfers unscoped,
// then the outer WHERE restricts to staff in this dept+branch.
// This is safe because ut.user_id JOIN already limits rows to the staff list.
$deptDistStmt = $db->prepare("
    SELECT
        u.full_name,
        u.employee_id,
        COUNT(DISTINCT ut.task_id)  AS task_count,
        SUM(ut.via_transfer)        AS transferred_in_count,
        SUM(1 - ut.via_transfer)    AS original_count,
        {$statusCols}
        CASE (ROW_NUMBER() OVER (ORDER BY COUNT(DISTINCT ut.task_id) DESC) % 8)
            WHEN 0 THEN '#f59e0b' WHEN 1 THEN '#3b82f6' WHEN 2 THEN '#10b981'
            WHEN 3 THEN '#8b5cf6' WHEN 4 THEN '#ef4444' WHEN 5 THEN '#ec4899'
            WHEN 6 THEN '#06b6d4' WHEN 7 THEN '#f97316'
        END AS color
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    LEFT JOIN (
        -- arm (a): tasks currently assigned to a user
        SELECT t.id AS task_id, t.assigned_to AS user_id, ts.status_name, 0 AS via_transfer
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active = 1

        UNION

        -- arm (b): tasks transferred to a user
        SELECT t.id AS task_id, tw.to_user_id AS user_id, ts.status_name, 1 AS via_transfer
        FROM task_workflow tw
        JOIN  tasks t           ON t.id  = tw.task_id
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active = 1
          AND tw.action IN ('transferred_staff','transferred_dept')
          AND tw.to_user_id IS NOT NULL

    ) AS ut ON ut.user_id = u.id
    WHERE r.role_name     = 'staff'
      AND u.is_active     = 1
      AND u.branch_id     = ?
      AND u.department_id = ?
    GROUP BY u.id, u.full_name, u.employee_id
    ORDER BY task_count DESC
");
// Only 2 params now — branch + dept in the outer WHERE
$deptDistStmt->execute([$adminBranchId, $adminDeptId]);
$deptDist = $deptDistStmt->fetchAll();

// ── 5. Recent tasks ───────────────────────────────────────────────────────────
// $scopeWhere appears once → 6 params
$recentStmt = $db->prepare("
    SELECT t.*, ts.status_name AS status,
           d.dept_name, d.color, c.company_name,
           u.full_name AS assigned_to_name
    FROM tasks t
    LEFT JOIN departments d  ON d.id  = t.department_id
    LEFT JOIN companies   c  ON c.id  = t.company_id
    LEFT JOIN users       u  ON u.id  = t.assigned_to
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE t.is_active = 1
      AND t.branch_id     = ?
      AND t.department_id = ?
    ORDER BY t.created_at DESC
    LIMIT 6
");
$recentStmt->execute([$adminBranchId, $adminDeptId]);                // ✓ 6 params
$recentTasks = $recentStmt->fetchAll();

include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <!-- Hero -->
            <div class="page-hero">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="sidebar-user-role">
                            <i
                                class="fas fa-user-shield me-1"></i><?= htmlspecialchars($user['role_name'] ?? 'Admin') ?>
                        </div>
                        <h4>Hello, <?= htmlspecialchars($user['full_name'] ?? $userSession['username']) ?></h4>
                        <p style="margin:0;">
                            <?= htmlspecialchars($user['branch_name'] ?? '') ?>
                            <?php if (!empty($user['dept_name'])): ?>&mdash;
                                <?= htmlspecialchars($user['dept_name']) ?><?php endif; ?>
                        </p>
                    </div>
                    <a href="<?= APP_URL ?>/admin/tasks/assign.php"
                        class="btn-gold btn d-flex align-items-center gap-2">
                        <i class="fas fa-plus"></i> Assign Task
                    </a>
                </div>
            </div>

            <!-- Transfer activity banner -->
            <?php if ($transferIn > 0 || $transferOut > 0): ?>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;
            padding:.65rem 1rem;margin-bottom:1rem;
            display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
                    <span style="font-size:.78rem;color:#1d4ed8;font-weight:700;"><i
                            class="fas fa-exchange-alt me-1"></i>Transfer Activity</span>
                    <span style="font-size:.78rem;color:#16a34a;"><i
                            class="fas fa-arrow-down me-1"></i><strong><?= $transferIn ?></strong> received</span>
                    <span style="font-size:.78rem;color:#ef4444;"><i
                            class="fas fa-arrow-up me-1"></i><strong><?= $transferOut ?></strong> sent out</span>
                </div>
            <?php endif; ?>

            <!-- Stat cards -->
            <div class="row g-3 mb-4">
                <?php foreach ($allStatuses as $st):
                    $k    = $st['status_name'];
                    $cnt  = $byStatus[$k] ?? 0;
                    $col  = $st['color']    ?? '#6b7280';
                    $bg   = $st['bg_color'] ?? '#f3f4f6';
                    $icon = $st['icon']     ?? 'fa-circle';
                ?>
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="stat-card">
                            <div class="stat-card-icon" style="background:<?= htmlspecialchars($bg) ?>;color:<?= htmlspecialchars($col) ?>;">
                                <i class="fas <?= htmlspecialchars($icon) ?>"></i>
                            </div>
                            <div class="stat-card-value" style="color:<?= htmlspecialchars($col) ?>;">
                                <?= number_format($cnt) ?>
                            </div>
                            <div class="stat-card-label"><?= htmlspecialchars($k) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            
                <!-- Total Tasks -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background:#eff6ff;color:#3b82f6;">
                            <i class="fas fa-list-check"></i>
                        </div>
                        <div class="stat-card-value" style="color:#3b82f6;"><?= number_format($total) ?></div>
                        <div class="stat-card-label">Total Tasks</div>
                    </div>
                </div>
            
                <!-- My Staff -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-card">
                        <div class="stat-card-icon" style="background:#fdf2f8;color:#ec4899;">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-card-value" style="color:#ec4899;"><?= number_format($staffCount) ?></div>
                        <div class="stat-card-label">My Staff</div>
                    </div>
                </div>
            </div>
            <!-- Staff Work Distribution -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-chart-bar text-warning me-2"></i>Staff Work Distribution</h5>
                    <span style="font-size:.75rem;color:#9ca3af;">
                        <?= htmlspecialchars($user['branch_name'] ?? '') ?> ·
                        <?= htmlspecialchars($user['dept_name'] ?? '') ?>
                        <span
                            style="margin-left:.4rem;background:#eff6ff;color:#3b82f6;padding:.1rem .45rem;border-radius:99px;font-size:.68rem;">incl.
                            transferred tasks</span>
                    </span>
                </div>
                <div class="card-mis-body">
                    <?php if (!empty($deptDist) && array_sum(array_column($deptDist, 'task_count')) > 0): ?>
                        <div style="height:300px;position:relative;margin-bottom:1.5rem;">
                            <canvas id="staffBarChart"></canvas>
                        </div>
                        <div class="row g-2 mt-2">
                            <?php foreach ($deptDist as $s):
                                $doneKey = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower('Done'));
                                $doneCnt = (int) ($s[$doneKey] ?? 0);
                                $openCnt = max(0, (int) $s['task_count'] - $doneCnt);
                                $xferIn = (int) ($s['transferred_in_count'] ?? 0);
                                $donePct = $s['task_count'] > 0 ? round(($doneCnt / $s['task_count']) * 100) : 0;
                                ?>
                                <div class="col-md-4 col-6">
                                    <div
                                        style="background:#f9fafb;border-radius:10px;padding:.75rem 1rem;border-left:3px solid <?= htmlspecialchars($s['color']) ?>;">
                                        <div
                                            style="font-size:.82rem;font-weight:600;color:#1f2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                            <?= htmlspecialchars($s['full_name']) ?>
                                        </div>
                                        <div style="font-size:.7rem;color:#9ca3af;margin-bottom:.5rem;">
                                            <?= htmlspecialchars($s['employee_id'] ?? '') ?>
                                            <?php if ($xferIn > 0): ?>
                                                <span
                                                    style="background:#eff6ff;color:#3b82f6;padding:.05rem .35rem;border-radius:3px;margin-left:.3rem;font-size:.65rem;">+<?= $xferIn ?>
                                                    transferred in</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex gap-2 align-items-center">
                                            <div style="text-align:center;">
                                                <div style="font-size:1rem;font-weight:700;color:#f59e0b;"><?= $openCnt ?></div>
                                                <div style="font-size:.65rem;color:#9ca3af;">Open</div>
                                            </div>
                                            <div style="width:1px;background:#e5e7eb;align-self:stretch;"></div>
                                            <div style="text-align:center;">
                                                <div style="font-size:1rem;font-weight:700;color:#10b981;"><?= $doneCnt ?></div>
                                                <div style="font-size:.65rem;color:#9ca3af;">Done</div>
                                            </div>
                                            <div style="width:1px;background:#e5e7eb;align-self:stretch;"></div>
                                            <div style="text-align:center;">
                                                <div style="font-size:1rem;font-weight:700;color:#3b82f6;">
                                                    <?= $s['task_count'] ?></div>
                                                <div style="font-size:.65rem;color:#9ca3af;">Total</div>
                                            </div>
                                            <div style="flex:1;margin-left:.5rem;">
                                                <div style="background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
                                                    <div
                                                        style="width:<?= $donePct ?>%;background:#10b981;height:100%;border-radius:99px;">
                                                    </div>
                                                </div>
                                                <div style="font-size:.65rem;color:#9ca3af;text-align:right;"><?= $donePct ?>%
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5" style="color:#9ca3af;">
                            <i class="fas fa-users fa-2x mb-2 d-block"></i>
                            <p style="font-size:.85rem;margin:0;">No staff tasks found for your department.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <script>
                (function () {
                    const ctx = document.getElementById('staffBarChart');
                    if (!ctx) return;
                    const data = <?= json_encode(array_values(array_filter($deptDist, fn($d) => $d['task_count'] > 0))) ?>;
                    if (!data.length) return;
                    const statuses = <?= json_encode(array_map(function($st) {
                        return [
                            'key'   => preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name'])),
                            'label' => $st['status_name'],
                            'color' => $st['color'] ?? '#9ca3af',
                        ];
                    }, $allStatuses)) ?>;
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.map(d => d.full_name.split(' ')[0]),
                            datasets: statuses
                                .filter(s => data.some(d => (d[s.key] ?? 0) > 0))
                                .map(s => ({
                                    label: s.label,
                                    data: data.map(d => parseInt(d[s.key] ?? 0)),
                                    backgroundColor: s.color,
                                    borderRadius: 6, borderSkipped: false,
                                }))
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: {
                                    display: true, position: 'top',
                                    labels: { font: { size: 11 }, usePointStyle: true, padding: 14 }
                                },
                                tooltip: { callbacks: { afterBody: items => [`Total: ${data[items[0].dataIndex].task_count} tasks`] } }
                            },
                            scales: {
                                x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
                                y: { stacked: true, beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { stepSize: 1, font: { size: 11 } } }
                            }
                        }
                    });
                })();
            </script>

            <!-- Recent Tasks -->
            <div class="card-mis mt-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-clock text-warning me-2"></i>Recent Tasks</h5>
                    <a href="<?= APP_URL ?>/admin/tasks/index.php" class="btn btn-sm btn-outline-secondary">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table-mis w-100">
                        <thead>
                            <tr>
                                <th>Task #</th>
                                <th>Title</th>
                                <th>Department</th>
                                <th>Company</th>
                                <th>Assigned To</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTasks)): ?>
                                <tr>
                                    <td colspan="7" class="empty-state"><i class="fas fa-list-check"></i> No tasks yet</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($recentTasks as $t):
                                $sClass = 'status-' . strtolower(str_replace(' ', '-', $t['status'])); ?>
                                <tr>
                                    <td><span class="task-number"><?= htmlspecialchars($t['task_number']) ?></span></td>
                                    <td
                                        style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.87rem;font-weight:500;">
                                        <?= htmlspecialchars($t['title']) ?>
                                    </td>
                                    <td>
                                        <span
                                            style="font-size:.73rem;background:<?= htmlspecialchars($t['color'] ?? '#ccc') ?>22;color:<?= htmlspecialchars($t['color'] ?? '#666') ?>;padding:.2rem .5rem;border-radius:99px;">
                                            <?= htmlspecialchars($t['dept_name'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($t['company_name'] ?? '—') ?></td>
                                    <td style="font-size:.82rem;"><?= htmlspecialchars($t['assigned_to_name'] ?? '—') ?>
                                    </td>
                                    <td><span
                                            class="status-badge <?= $sClass ?>"><?= htmlspecialchars($t['status']) ?></span>
                                    </td>
                                    <td style="font-size:.78rem;color:#9ca3af;">
                                        <?= date('M j', strtotime($t['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- padding -->
    </div><!-- main-content -->
</div><!-- app-wrapper -->
<?php include '../../includes/footer.php'; ?>