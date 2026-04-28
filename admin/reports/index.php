<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
requireAdmin();

$db   = getDB();
$user = currentUser();
$pageTitle = 'Reports';

// ── Admin profile ─────────────────────────────────────────────────────────────
$adminStmt = $db->prepare("
    SELECT u.*, d.dept_name, d.dept_code, d.color AS dept_color, b.branch_name
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN branches b    ON b.id = u.branch_id
    WHERE u.id = ?
");
$adminStmt->execute([$user['id']]);
$adminUser       = $adminStmt->fetch();
$adminBranchId   = (int)($adminUser['branch_id']     ?? 0);
$adminDeptId     = (int)($adminUser['department_id'] ?? 0);
$isCoreAdminDept = (($adminUser['dept_code'] ?? '') === 'CORE');
$deptColor       = $adminUser['dept_color'] ?: '#c9a84c';

// ── Active tab ────────────────────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'dept';   // dept | self | allbranch

// ── Branch access control ─────────────────────────────────────────────────────
$userBranchLower  = strtolower(trim($adminUser['branch_name'] ?? ''));
$canSeeAllBranch  = str_contains($userBranchLower, 'head office')
                 || str_contains($userBranchLower, 'hetauda');

// ── Filters ───────────────────────────────────────────────────────────────────
$fromDate     = $_GET['from']           ?? date('Y-m-01');
$toDate       = $_GET['to']             ?? date('Y-m-d');
$employeeName = trim($_GET['employee_name'] ?? '');
$dateFrom     = $fromDate . ' 00:00:00';
$dateTo       = $toDate   . ' 23:59:59';

// ── Statuses ──────────────────────────────────────────────────────────────────
$allStatuses = $db->query("
    SELECT id, status_name, color, bg_color, icon
    FROM task_status
    WHERE status_name != 'Corporate Team'
    ORDER BY id ASC
")->fetchAll();

$statusMeta = [];
foreach ($allStatuses as $st) {
    $statusMeta[$st['status_name']] = $st;
}

// ── Find "Done" key ───────────────────────────────────────────────────────────
$doneKey = null;
foreach ($allStatuses as $st) {
    if (strtolower($st['status_name']) === 'done') {
        $doneKey = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
        break;
    }
}

// ── Scope WHERE (branch + dept) ───────────────────────────────────────────────
$scopeWhere = '(
    (t.branch_id = ? AND t.department_id = ?)
    OR EXISTS (SELECT 1 FROM task_workflow tw WHERE tw.task_id=t.id AND tw.action=\'transferred_dept\' AND tw.from_dept_id=?)
    OR EXISTS (SELECT 1 FROM task_workflow tw WHERE tw.task_id=t.id AND tw.action=\'transferred_dept\' AND tw.to_dept_id=?)
    OR t.created_by  = ?
    OR t.assigned_to = ?
)';
$scopeParams = [
    $adminBranchId, $adminDeptId,
    $adminDeptId,   $adminDeptId,
    $user['id'],    $user['id'],
];

// ════════════════════════════════════════════════════════════════════════
// TAB 1 — DEPARTMENT REPORT (branch + dept)
// ════════════════════════════════════════════════════════════════════════

// Status summary
$statusStmt = $db->prepare("
    SELECT ts.status_name AS status, COUNT(DISTINCT t.id) AS cnt
    FROM task_status ts
    LEFT JOIN tasks t ON t.status_id = ts.id AND t.is_active=1
        AND {$scopeWhere}
        AND t.created_at BETWEEN ? AND ?
    WHERE ts.status_name != 'Corporate Team'
    GROUP BY ts.id, ts.status_name ORDER BY ts.id
");
$statusStmt->execute(array_merge($scopeParams, [$dateFrom, $dateTo]));
$statusReport    = array_column($statusStmt->fetchAll(), 'cnt', 'status');
$totalDeptTasks  = array_sum($statusReport);
$doneCount       = 0;
foreach ($statusMeta as $sn => $sm) {
    if (strtolower($sn) === 'done') { $doneCount = $statusReport[$sn] ?? 0; break; }
}
$completionRate = $totalDeptTasks ? round(($doneCount / $totalDeptTasks) * 100) : 0;

// Transfer activity
$transferIn = $transferOut = 0;
try {
    $tIn = $db->prepare("SELECT COUNT(DISTINCT tw.task_id) FROM task_workflow tw
        JOIN tasks t ON t.id=tw.task_id AND t.is_active=1 AND t.branch_id=?
        WHERE tw.action='transferred_dept' AND tw.to_dept_id=? AND tw.created_at BETWEEN ? AND ?");
    $tIn->execute([$adminBranchId, $adminDeptId, $dateFrom, $dateTo]);
    $transferIn = (int)$tIn->fetchColumn();

    $tOut = $db->prepare("SELECT COUNT(DISTINCT tw.task_id) FROM task_workflow tw
        JOIN tasks t ON t.id=tw.task_id AND t.is_active=1 AND t.branch_id=?
        WHERE tw.action='transferred_dept' AND tw.from_dept_id=? AND tw.created_at BETWEEN ? AND ?");
    $tOut->execute([$adminBranchId, $adminDeptId, $dateFrom, $dateTo]);
    $transferOut = (int)$tOut->fetchColumn();
} catch (Exception $e) {}

// Staff performance (dept tab) — same branch + dept
$statusCols = '';
foreach ($allStatuses as $st) {
    $safe   = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
    $quoted = $db->quote($st['status_name']);
    $statusCols .= "SUM(CASE WHEN ut.status_name = {$quoted} THEN 1 ELSE 0 END) AS `{$safe}`,\n        ";
}

$nameWhere  = '';
$nameParams = [];
if ($employeeName) {
    $nameWhere    = 'AND u.full_name LIKE ?';
    $nameParams[] = "%{$employeeName}%";
}
$staffParams = array_merge(
    [$adminBranchId, $adminDeptId, $dateFrom, $dateTo],
    [$adminBranchId, $adminDeptId, $dateFrom, $dateTo],
    [$adminBranchId, $adminDeptId, $adminDeptId],
    $nameParams
);

$staffStmt = $db->prepare("
    SELECT u.id AS user_id, u.full_name, u.employee_id,
           b.branch_name, d.dept_name,
           COUNT(DISTINCT ut.task_id)   AS total,
           {$statusCols}
           SUM(COALESCE(ut.via_transfer, 0))         AS transferred_in_count,
           SUM(1 - COALESCE(ut.via_transfer, 0))     AS original_count
    FROM users u
    LEFT JOIN branches    b  ON b.id = u.branch_id
    LEFT JOIN departments d  ON d.id = u.department_id
    LEFT JOIN roles       r  ON r.id = u.role_id
    LEFT JOIN (
        SELECT t.id AS task_id, t.assigned_to AS user_id, ts.status_name, 0 AS via_transfer
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active=1
          AND t.branch_id = ? AND t.department_id = ?
          AND t.created_at BETWEEN ? AND ?
        UNION
        SELECT t.id AS task_id, tw.to_user_id AS user_id, ts.status_name, 1 AS via_transfer
        FROM task_workflow tw
        JOIN tasks t ON t.id = tw.task_id
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active=1
          AND t.branch_id = ? AND t.department_id = ?
          AND t.created_at BETWEEN ? AND ?
          AND tw.action IN ('transferred_staff','transferred_dept')
          AND tw.to_user_id IS NOT NULL
    ) AS ut ON ut.user_id = u.id
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    WHERE u.is_active=1
      AND r.role_name = 'staff'
      AND u.branch_id=?
      AND (
          u.department_id = ?
          OR uda.department_id = ?
      )
      {$nameWhere}
    GROUP BY u.id, u.full_name, u.employee_id, b.branch_name, d.dept_name
    ORDER BY total DESC, u.full_name ASC
");
$staffStmt->execute($staffParams);
$staffReport = $staffStmt->fetchAll();
// TEMP DEBUG — remove after confirming
if (!empty($staffReport)) {
    error_log('Sample staff row keys: ' . implode(', ', array_keys($staffReport[0])));
    error_log('doneKey value: ' . $doneKey);
}
// ════════════════════════════════════════════════════════════════════════
// TAB 2 — SELF PERFORMANCE (login user's own tasks)
// ════════════════════════════════════════════════════════════════════════

$selfStatusCols = '';
foreach ($allStatuses as $st) {
    $safe   = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
    $quoted = $db->quote($st['status_name']);
    $selfStatusCols .= "SUM(CASE WHEN ts.status_name = {$quoted} THEN 1 ELSE 0 END) AS `{$safe}`,\n        ";
}

$selfStmt = $db->prepare("
    SELECT
        COUNT(DISTINCT t.id) AS total,
        {$selfStatusCols}
        SUM(CASE WHEN t.due_date < CURDATE() AND ts.status_name != 'Done' THEN 1 ELSE 0 END) AS overdue
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE t.assigned_to = ? AND t.is_active = 1
      AND t.department_id = ?
      AND t.created_at BETWEEN ? AND ?
");
$selfStmt->execute([$user['id'], $adminDeptId, $dateFrom, $dateTo]);
$selfStats = $selfStmt->fetch();

// Self task list
$selfTaskStmt = $db->prepare("
    SELECT t.id, t.task_number, t.title, t.due_date, t.priority, t.created_at,
           ts.status_name AS status, ts.color AS status_color, ts.bg_color AS status_bg,
           c.company_name, d.dept_name, d.color AS dept_color
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    LEFT JOIN companies   c  ON c.id  = t.company_id
    LEFT JOIN departments d  ON d.id  = t.department_id
    WHERE t.assigned_to = ? AND t.is_active = 1
      AND t.department_id = ?
      AND t.created_at BETWEEN ? AND ?
    ORDER BY
        CASE WHEN t.due_date < CURDATE() AND ts.status_name != 'Done' THEN 0 ELSE 1 END,
        t.due_date ASC, t.created_at DESC
    LIMIT 100
");
$selfTaskStmt->execute([$user['id'], $adminDeptId, $dateFrom, $dateTo]);
$selfTasks = $selfTaskStmt->fetchAll();

$selfTotal      = (int)($selfStats['total'] ?? 0);
$selfDoneKey    = $doneKey;
$selfDone       = (int)($selfStats[$selfDoneKey] ?? 0);
$selfCompletion = $selfTotal > 0 ? round(($selfDone / $selfTotal) * 100) : 0;

// ════════════════════════════════════════════════════════════════════════
// TAB 3 — ALL BRANCHES (same dept, all branches)
// ════════════════════════════════════════════════════════════════════════

$allBranchStatusCols = $statusCols; // reuse same cols

$allBranchNameWhere  = '';
$allBranchNameParams = [];
if ($employeeName) {
    $allBranchNameWhere    = 'AND u.full_name LIKE ?';
    $allBranchNameParams[] = "%{$employeeName}%";
}

// Scope for all branches: dept only (no branch restriction)
$allBranchScopeWhere = '(
    t.department_id = ?
    OR EXISTS (SELECT 1 FROM task_workflow tw2 WHERE tw2.task_id=t.id AND tw2.action=\'transferred_dept\' AND tw2.from_dept_id=?)
    OR EXISTS (SELECT 1 FROM task_workflow tw2 WHERE tw2.task_id=t.id AND tw2.action=\'transferred_dept\' AND tw2.to_dept_id=?)
)';
$allBranchScopeParams = [$adminDeptId, $adminDeptId, $adminDeptId];

// Status summary — all branches
$abStatusStmt = $db->prepare("
    SELECT ts.status_name AS status, COUNT(DISTINCT t.id) AS cnt
    FROM task_status ts
    LEFT JOIN tasks t ON t.status_id = ts.id AND t.is_active=1
        AND {$allBranchScopeWhere}
        AND t.created_at BETWEEN ? AND ?
    WHERE ts.status_name != 'Corporate Team'
    GROUP BY ts.id, ts.status_name ORDER BY ts.id
");
$abStatusStmt->execute(array_merge($allBranchScopeParams, [$dateFrom, $dateTo]));
$abStatusReport   = array_column($abStatusStmt->fetchAll(), 'cnt', 'status');
$abTotalTasks     = array_sum($abStatusReport);
$abDoneCount      = 0;
foreach ($statusMeta as $sn => $sm) {
    if (strtolower($sn) === 'done') { $abDoneCount = $abStatusReport[$sn] ?? 0; break; }
}
$abCompletionRate = $abTotalTasks ? round(($abDoneCount / $abTotalTasks) * 100) : 0;

// Staff performance — all branches, same dept
$abStaffParams = array_merge(
    [$adminDeptId, $dateFrom, $dateTo],
    [$adminDeptId, $dateFrom, $dateTo],
    [$adminDeptId, $adminDeptId],
    $allBranchNameParams
);

$abStaffStmt = $db->prepare("
    SELECT u.id AS user_id, u.full_name, u.employee_id,
           b.branch_name, d.dept_name,
           COUNT(DISTINCT ut.task_id)   AS total,
           {$allBranchStatusCols}
           SUM(COALESCE(ut.via_transfer, 0))         AS transferred_in_count,
           SUM(1 - COALESCE(ut.via_transfer, 0))     AS original_count
    FROM users u
    LEFT JOIN branches    b  ON b.id = u.branch_id
    LEFT JOIN departments d  ON d.id = u.department_id
    LEFT JOIN roles       r  ON r.id = u.role_id
    LEFT JOIN (
        SELECT t.id AS task_id, t.assigned_to AS user_id, ts.status_name, 0 AS via_transfer
        FROM tasks t
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active=1
          AND t.department_id = ?
          AND t.created_at BETWEEN ? AND ?
        UNION
        SELECT t.id AS task_id, tw.to_user_id AS user_id, ts.status_name, 1 AS via_transfer
        FROM task_workflow tw
        JOIN tasks t ON t.id = tw.task_id
        LEFT JOIN task_status ts ON ts.id = t.status_id
        WHERE t.is_active=1
          AND t.department_id = ?
          AND t.created_at BETWEEN ? AND ?
          AND tw.action IN ('transferred_staff','transferred_dept')
          AND tw.to_user_id IS NOT NULL
    ) AS ut ON ut.user_id = u.id
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    WHERE u.is_active=1
      AND r.role_name = 'staff'
      AND (
          u.department_id = ?
          OR uda.department_id = ?
      )
      {$allBranchNameWhere}
    GROUP BY u.id, u.full_name, u.employee_id, b.branch_name, d.dept_name
    ORDER BY b.branch_name ASC, total DESC, u.full_name ASC
");
$abStaffStmt->execute($abStaffParams);
$abStaffReport = $abStaffStmt->fetchAll();

// ── Helpers ───────────────────────────────────────────────────────────────────
function donut(int $pct, string $color, int $size = 42): string
{
    $r    = ($size / 2) - 5;
    $circ = round(2 * M_PI * $r, 2);
    $dash = round($circ * $pct / 100, 2);
    $cx   = $size / 2;
    return <<<SVG
<svg width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}">
  <circle cx="{$cx}" cy="{$cx}" r="{$r}" fill="none" stroke="#f3f4f6" stroke-width="4"/>
  <circle cx="{$cx}" cy="{$cx}" r="{$r}" fill="none" stroke="{$color}" stroke-width="4"
          stroke-dasharray="{$dash} {$circ}" stroke-linecap="round"
          transform="rotate(-90 {$cx} {$cx})"/>
  <text x="{$cx}" y="{$cx}" dominant-baseline="central" text-anchor="middle"
        font-size="8" font-weight="700" fill="{$color}">{$pct}%</text>
</svg>
SVG;
}

function staffTable(array $staffReport, array $allStatuses, ?string $doneKey, string $deptColor, PDO $db, bool $showBranch = false): void
{
    if (empty($staffReport)) {
        echo '<div style="padding:3rem;text-align:center;color:#9ca3af;">
                <i class="fas fa-users fa-2x mb-2 d-block"></i>No staff found.
              </div>';
        return;
    }
    $grandTotal = $grandXfer = $grandOrig = 0;
    $grandStatusTotals = [];
    ?>
    <div class="table-responsive d-none d-md-block">
        <table class="table-mis w-100" style="font-size:.78rem;">
            <thead>
                <tr>
                    <th style="min-width:160px;">Staff Member</th>
                    <?php if ($showBranch): ?>
                    <th style="min-width:90px;font-size:.65rem;">Branch</th>
                    <?php endif; ?>
                    <th class="text-center" style="width:52px;">Total</th>
                    <?php foreach ($allStatuses as $st):
                        $sc = $st['color'] ?: '#9ca3af'; ?>
                        <th class="text-center" style="width:52px;">
                            <span style="color:<?= $sc ?>;font-size:.65rem;font-weight:700;">
                                <?= htmlspecialchars($st['status_name']) ?>
                            </span>
                        </th>
                    <?php endforeach; ?>
                    <th class="text-center" style="width:48px;font-size:.65rem;" title="Originally assigned">Orig</th>
                    <th class="text-center" style="width:48px;font-size:.65rem;" title="Via transfer">Xfer</th>
                    <th style="min-width:100px;">Done %</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staffReport as $idx => $s):
                    $doneCnt = (int)($s[$doneKey] ?? 0);
                    $donePct = $s['total'] > 0 ? round(($doneCnt / $s['total']) * 100) : 0;
                    $xferIn  = (int)($s['transferred_in_count'] ?? 0);
                    $origCnt = (int)($s['original_count']      ?? 0);
                    $grandTotal += (int)$s['total'];
                    $grandXfer  += $xferIn;
                    $grandOrig  += $origCnt;
                    $odd = $idx % 2 === 0;
                ?>
                <tr style="<?= $odd ? '' : 'background:#fafafa;' ?>">
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="avatar-circle avatar-sm flex-shrink-0"
                                 style="width:30px;height:30px;font-size:.65rem;background:<?= $deptColor ?>22;color:<?= $deptColor ?>;">
                                <?= strtoupper(substr($s['full_name'] ?? '?', 0, 2)) ?>
                            </div>
                            <div>
                                <div style="font-weight:600;font-size:.85rem;color:#1f2937;">
                                    <?= htmlspecialchars($s['full_name'] ?? '—') ?>
                                </div>
                                <div style="font-size:.68rem;color:#9ca3af;">
                                    <?= htmlspecialchars($s['employee_id'] ?? '') ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <?php if ($showBranch): ?>
                    <td style="font-size:.75rem;color:#6b7280;">
                        <?= htmlspecialchars($s['branch_name'] ?? '—') ?>
                    </td>
                    <?php endif; ?>
                    <td class="text-center">
                        <span style="font-size:1rem;font-weight:800;color:#1f2937;"><?= $s['total'] ?></span>
                    </td>
                    <?php foreach ($allStatuses as $st):
                        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                        $cnt  = (int)($s[$safe] ?? 0);
                        $sc   = $st['color']    ?: '#9ca3af';
                        $sbg  = $st['bg_color'] ?: '#f3f4f6';
                        $grandStatusTotals[$safe] = ($grandStatusTotals[$safe] ?? 0) + $cnt;
                    ?>
                    <td class="text-center">
                        <?php if ($cnt > 0): ?>
                            <span style="background:<?= $sbg ?>;color:<?= $sc ?>;
                                         font-size:.68rem;font-weight:700;
                                         padding:.15rem .45rem;border-radius:99px;
                                         display:inline-block;min-width:22px;text-align:center;">
                                <?= $cnt ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#e5e7eb;font-size:.7rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="text-center">
                        <span style="background:#f0fdf4;color:#16a34a;font-size:.68rem;
                                     font-weight:700;padding:.15rem .45rem;border-radius:99px;">
                            <?= $origCnt ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <?php if ($xferIn > 0): ?>
                            <span style="background:#eff6ff;color:#3b82f6;font-size:.68rem;
                                         font-weight:700;padding:.15rem .45rem;border-radius:99px;">
                                +<?= $xferIn ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#e5e7eb;font-size:.7rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= donut($donePct, $deptColor) ?>
                            <div style="flex:1;min-width:0;">
                                <div style="background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
                                    <div style="width:<?= $donePct ?>%;background:<?= $deptColor ?>;height:5px;border-radius:99px;"></div>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <!-- Grand total row -->
                <?php
                $grandDoneVal = $grandStatusTotals[$doneKey] ?? 0;
                $grandDonePct = $grandTotal ? round(($grandDoneVal / $grandTotal) * 100) : 0;
                ?>
                <tr style="background:linear-gradient(90deg,<?= $deptColor ?>10,transparent);
                           border-top:2px solid <?= $deptColor ?>33;font-weight:700;">
                    <td <?= $showBranch ? '' : '' ?>>
                        <div class="d-flex align-items-center gap-2">
                            <div style="width:30px;height:30px;border-radius:99px;background:<?= $deptColor ?>22;
                                        display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-sigma" style="font-size:.7rem;color:<?= $deptColor ?>;"></i>
                            </div>
                            <span style="font-size:.85rem;color:#1f2937;">Grand Total</span>
                        </div>
                    </td>
                    <?php if ($showBranch): ?>
                    <td></td>
                    <?php endif; ?>
                    <td class="text-center">
                        <span style="font-size:1rem;font-weight:800;color:<?= $deptColor ?>;"><?= $grandTotal ?></span>
                    </td>
                    <?php foreach ($allStatuses as $st):
                        $safe     = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                        $colTotal = $grandStatusTotals[$safe] ?? 0;
                        $sc       = $st['color'] ?: '#9ca3af';
                    ?>
                    <td class="text-center" style="font-size:.82rem;font-weight:700;color:<?= $sc ?>;">
                        <?= $colTotal ?: '—' ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="text-center" style="color:#16a34a;"><?= $grandOrig ?></td>
                    <td class="text-center" style="color:#3b82f6;"><?= $grandXfer ? '+' . $grandXfer : '—' ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= donut($grandDonePct, $deptColor) ?>
                            <div style="flex:1;">
                                <div style="background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
                                    <div style="width:<?= $grandDonePct ?>%;background:<?= $deptColor ?>;height:5px;border-radius:99px;"></div>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}

include '../../includes/header.php';
?>

<style>
.rpt-hero {
    background: linear-gradient(135deg, #0a0f1e 0%, #111827 60%, #1a2235 100%);
    border-radius: 16px; padding: 1.75rem 2rem; margin-bottom: 1.5rem;
    position: relative; overflow: hidden;
}
.rpt-hero::before {
    content: ''; position: absolute; inset: 0;
    background: radial-gradient(ellipse at 80% 50%, <?= $deptColor ?>18 0%, transparent 60%);
    pointer-events: none;
}
.rpt-stat {
    background: #fff; border-radius: 14px; border: 1px solid #f3f4f6;
    padding: 1.1rem 1rem 1rem; position: relative; overflow: hidden;
    transition: transform .15s, box-shadow .15s;
}
.rpt-stat:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.08); }
.rpt-stat::after {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    border-radius: 14px 14px 0 0; background: var(--sc);
}
.rpt-stat-val { font-size: 2rem; font-weight: 800; line-height: 1; color: var(--sc); }
.rpt-stat-label { font-size: .72rem; font-weight: 700; text-transform: uppercase;
                  letter-spacing: .06em; color: #9ca3af; margin-top: .25rem; }
.rpt-stat-bar { background: #f3f4f6; border-radius: 99px; height: 4px; margin-top: .75rem; overflow: hidden; }
.rpt-stat-fill { height: 100%; border-radius: 99px; background: var(--sc); }
.chart-card { background: #fff; border-radius: 14px; border: 1px solid #f3f4f6; overflow: hidden; }
.chart-card-header {
    padding: 1rem 1.25rem; border-bottom: 1px solid #f3f4f6;
    display: flex; align-items: center; justify-content: space-between;
}
.rpt-filter { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
              padding: 1rem 1.25rem; margin-bottom: 1.5rem; }
.completion-ring-card {
    background: linear-gradient(135deg, <?= $deptColor ?>10, <?= $deptColor ?>05);
    border: 1px solid <?= $deptColor ?>33; border-radius: 14px; padding: 1.25rem;
    display: flex; align-items: center; gap: 1.25rem; height: 100%;
}
/* Tabs */
.rpt-tab-bar {
    display: flex; gap: 0; border-bottom: 2px solid #f3f4f6;
    margin-bottom: 1.5rem; overflow-x: auto;
}
.rpt-tab {
    padding: .65rem 1.4rem; font-size: .85rem; font-weight: 600; cursor: pointer;
    border: none; background: none; border-bottom: 2px solid transparent;
    margin-bottom: -2px; color: #9ca3af; white-space: nowrap; transition: .15s;
    text-decoration: none; display: inline-flex; align-items: center; gap: .4rem;
}
.rpt-tab:hover { color: <?= $deptColor ?>; }
.rpt-tab.active { color: <?= $deptColor ?>; border-bottom-color: <?= $deptColor ?>; }
/* Self perf cards */
.self-stat-card {
    background: #fff; border-radius: 12px; border: 1px solid #f3f4f6;
    padding: 1rem; text-align: center; transition: .15s;
}
.self-stat-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); transform: translateY(-1px); }
.xfer-badge {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .72rem; font-weight: 700; padding: .3rem .75rem; border-radius: 99px;
}
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <!-- ── HERO ── -->
            <div class="rpt-hero mb-4">
                <div style="position:absolute;right:-20px;top:-30px;width:180px;height:180px;
                            border-radius:50%;background:<?= $deptColor ?>0d;
                            border:1px solid <?= $deptColor ?>22;"></div>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3"
                     style="position:relative;">
                    <div>
                        <div style="display:inline-flex;align-items:center;gap:.5rem;
                                    background:<?= $deptColor ?>22;border:1px solid <?= $deptColor ?>44;
                                    border-radius:99px;padding:.25rem .8rem;font-size:.72rem;
                                    font-weight:700;color:<?= $deptColor ?>;margin-bottom:.6rem;">
                            <i class="fas fa-chart-bar" style="font-size:.65rem;"></i> Task Reports
                        </div>
                        <h4 style="color:#fff;font-size:1.4rem;font-weight:800;margin:0 0 .2rem;">
                            <?= htmlspecialchars($adminUser['dept_name'] ?? 'Reports') ?>
                            <span style="font-size:.9rem;font-weight:400;color:#6b7280;margin-left:.5rem;">·</span>
                            <span style="font-size:.9rem;font-weight:500;color:#9ca3af;margin-left:.35rem;">
                                <?= htmlspecialchars($adminUser['branch_name'] ?? '') ?>
                            </span>
                        </h4>
                        <p style="color:#6b7280;font-size:.82rem;margin:0;">
                            <?= date('d M Y', strtotime($fromDate)) ?> — <?= date('d M Y', strtotime($toDate)) ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=report&<?= http_build_query(array_merge($_GET, ['branch_id' => $adminBranchId, 'dept_id' => $adminDeptId])) ?>"
                           style="background:#dc2626;color:#fff;border-radius:8px;padding:.45rem 1rem;
                                  font-size:.8rem;font-weight:600;display:inline-flex;
                                  align-items:center;gap:.4rem;text-decoration:none;">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=report&<?= http_build_query(array_merge($_GET, ['branch_id' => $adminBranchId, 'dept_id' => $adminDeptId])) ?>"
                           style="background:#16a34a;color:#fff;border-radius:8px;padding:.45rem 1rem;
                                  font-size:.8rem;font-weight:600;display:inline-flex;
                                  align-items:center;gap:.4rem;text-decoration:none;">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </a>
                    </div>
                </div>
            </div>

            <!-- ── FILTERS ── -->
            <div class="rpt-filter">
                <form method="GET" class="row g-2 align-items-end w-100">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                    <div class="col-md-2">
                        <label class="form-label-mis">From Date</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="<?= $fromDate ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label-mis">To Date</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="<?= $toDate ?>">
                    </div>
                    <?php if ($activeTab !== 'self'): ?>
                    <div class="col-md-3">
                        <label class="form-label-mis">Search Staff</label>
                        <input type="text" name="employee_name" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($employeeName) ?>" placeholder="Name…">
                    </div>
                    <?php endif; ?>
                    <div class="col-auto d-flex gap-1">
                        <button type="submit" class="btn btn-gold btn-sm">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                        <a href="index.php?tab=<?= $activeTab ?>" class="btn btn-outline-secondary btn-sm" title="Reset">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- ── TABS ── -->
            <div class="rpt-tab-bar">
                <a href="?tab=dept&from=<?= $fromDate ?>&to=<?= $toDate ?>"
                   class="rpt-tab <?= $activeTab==='dept'?'active':'' ?>">
                    <i class="fas fa-building"></i>
                    My Department
                    <span style="background:<?= $deptColor ?>22;color:<?= $deptColor ?>;
                                 padding:.1rem .45rem;border-radius:99px;font-size:.65rem;">
                        <?= $totalDeptTasks ?>
                    </span>
                </a>
                <a href="?tab=self&from=<?= $fromDate ?>&to=<?= $toDate ?>"
                   class="rpt-tab <?= $activeTab==='self'?'active':'' ?>">
                    <i class="fas fa-user-circle"></i>
                    My Performance
                    <span style="background:#eff6ff;color:#3b82f6;
                                 padding:.1rem .45rem;border-radius:99px;font-size:.65rem;">
                        <?= $selfTotal ?>
                    </span>
                </a>
                <?php if ($canSeeAllBranch): ?>
                <a href="?tab=allbranch&from=<?= $fromDate ?>&to=<?= $toDate ?>"
                   class="rpt-tab <?= $activeTab==='allbranch'?'active':'' ?>">
                    <i class="fas fa-globe"></i>
                    All Branches — <?= htmlspecialchars($adminUser['dept_name'] ?? 'Dept') ?>
                    <span style="background:#f0fdf4;color:#16a34a;
                                 padding:.1rem .45rem;border-radius:99px;font-size:.65rem;">
                        <?= $abTotalTasks ?>
                    </span>
                </a>
                <?php endif; ?>
            </div>

            <?php /* ══════════════════════════════════════════════
                   TAB 1 — DEPT REPORT
                   ══════════════════════════════════════════════ */
            if ($activeTab === 'dept'): ?>

                <!-- Transfer banner -->
                <?php if ($transferIn > 0 || $transferOut > 0): ?>
                <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1.25rem;">
                    <span style="font-size:.72rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">
                        <i class="fas fa-exchange-alt me-1"></i>Transfer Activity
                    </span>
                    <?php if ($transferIn > 0): ?>
                    <span class="xfer-badge" style="background:#ecfdf5;color:#16a34a;border:1px solid #a7f3d0;">
                        <i class="fas fa-arrow-down" style="font-size:.6rem;"></i> <?= $transferIn ?> received
                    </span>
                    <?php endif; ?>
                    <?php if ($transferOut > 0): ?>
                    <span class="xfer-badge" style="background:#fef2f2;color:#ef4444;border:1px solid #fecaca;">
                        <i class="fas fa-arrow-up" style="font-size:.6rem;"></i> <?= $transferOut ?> sent out
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Status cards + completion ring -->
                <div class="row g-3 mb-4">
                    <?php foreach ($allStatuses as $st):
                        $sn  = $st['status_name'];
                        $cnt = $statusReport[$sn] ?? 0;
                        $pct = $totalDeptTasks ? round(($cnt / $totalDeptTasks) * 100) : 0;
                        $sc  = $st['color']    ?: '#9ca3af';
                        $sbg = $st['bg_color'] ?: '#f3f4f6';
                        $rawI = trim($st['icon'] ?: 'fa-circle');
                        $ico  = str_starts_with($rawI, 'fa') ? $rawI : 'fa-' . $rawI;
                    ?>
                    <div class="col-6 col-md-3 col-xl-2">
                        <a href="<?= APP_URL ?>/admin/tasks/index.php?status=<?= urlencode($sn) ?>"
                           style="text-decoration:none;display:block;">
                            <div class="rpt-stat" style="--sc:<?= $sc ?>;">
                                <div style="position:absolute;top:.85rem;right:.85rem;width:30px;height:30px;
                                            border-radius:8px;background:<?= $sbg ?>;
                                            display:flex;align-items:center;justify-content:center;">
                                    <i class="fas <?= htmlspecialchars($ico) ?>" style="font-size:.75rem;color:<?= $sc ?>;"></i>
                                </div>
                                <div class="rpt-stat-val"><?= $cnt ?></div>
                                <div class="rpt-stat-label"><?= htmlspecialchars($sn) ?></div>
                                <div style="font-size:.68rem;color:#9ca3af;margin-top:.2rem;"><?= $pct ?>% of total</div>
                                <div class="rpt-stat-bar"><div class="rpt-stat-fill" style="width:<?= $pct ?>%;"></div></div>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>

                    <!-- Total card -->
                    <div class="col-6 col-md-3 col-xl-2">
                        <div class="rpt-stat" style="--sc:<?= $deptColor ?>;">
                            <div style="position:absolute;top:.85rem;right:.85rem;width:30px;height:30px;
                                        border-radius:8px;background:<?= $deptColor ?>18;
                                        display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-layer-group" style="font-size:.75rem;color:<?= $deptColor ?>;"></i>
                            </div>
                            <div class="rpt-stat-val"><?= $totalDeptTasks ?></div>
                            <div class="rpt-stat-label">Total Tasks</div>
                            <div style="font-size:.68rem;color:#9ca3af;margin-top:.2rem;">This branch &amp; dept</div>
                            <div class="rpt-stat-bar"><div class="rpt-stat-fill" style="width:100%;"></div></div>
                        </div>
                    </div>

                    <!-- Completion ring -->
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="completion-ring-card">
                            <?php
                            $rc    = 48; $rcirc = round(2 * M_PI * $rc, 2);
                            $rdsh  = round($rcirc * $completionRate / 100, 2);
                            ?>
                            <div style="position:relative;flex-shrink:0;">
                                <svg width="110" height="110" viewBox="0 0 110 110">
                                    <circle cx="55" cy="55" r="<?= $rc ?>" fill="none" stroke="#e5e7eb" stroke-width="8"/>
                                    <circle cx="55" cy="55" r="<?= $rc ?>" fill="none" stroke="<?= $deptColor ?>"
                                            stroke-width="8" stroke-dasharray="<?= $rdsh ?> <?= $rcirc ?>"
                                            stroke-linecap="round" transform="rotate(-90 55 55)"/>
                                </svg>
                                <div style="position:absolute;inset:0;display:flex;flex-direction:column;
                                            align-items:center;justify-content:center;">
                                    <div style="font-size:1.4rem;font-weight:800;color:<?= $deptColor ?>;line-height:1;">
                                        <?= $completionRate ?>%
                                    </div>
                                    <div style="font-size:.58rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">done</div>
                                </div>
                            </div>
                            <div>
                                <div style="font-size:.95rem;font-weight:700;color:#1f2937;">Completion Rate</div>
                                <div style="font-size:.75rem;color:#9ca3af;margin-top:.2rem;">
                                    <?= $doneCount ?> of <?= $totalDeptTasks ?> tasks completed
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Staff table -->
                <div class="chart-card mb-4">
                    <div class="chart-card-header">
                        <div>
                            <h5 style="margin:0;font-size:.95rem;font-weight:700;">
                                <i class="fas fa-users me-2" style="color:<?= $deptColor ?>;"></i>Staff Performance
                            </h5>
                            <small style="color:#9ca3af;">
                                <?= htmlspecialchars($adminUser['dept_name'] ?? '') ?> ·
                                <?= htmlspecialchars($adminUser['branch_name'] ?? '') ?> ·
                                <?= count($staffReport) ?> staff
                            </small>
                        </div>
                        <span style="font-size:.7rem;color:#6b7280;background:#f9fafb;
                                     padding:.25rem .65rem;border-radius:99px;border:1px solid #e5e7eb;">
                            <i class="fas fa-info-circle me-1 text-warning"></i>Includes transferred tasks
                        </span>
                    </div>
                    <?php staffTable($staffReport, $allStatuses, $doneKey, $deptColor, $db, false); ?>
                </div>

                <!-- Bar chart -->
                <?php if (!empty($staffReport)): ?>
                <div class="chart-card mb-4">
                    <div class="chart-card-header">
                        <h5 style="margin:0;font-size:.95rem;font-weight:700;">
                            <i class="fas fa-chart-bar me-2" style="color:<?= $deptColor ?>;"></i>Staff Task Breakdown
                        </h5>
                        <span style="font-size:.7rem;color:#9ca3af;">Stacked by status</span>
                    </div>
                    <div style="padding:1.25rem;">
                        <div style="height:300px;position:relative;">
                            <canvas id="staffChartDept"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            <?php /* ══════════════════════════════════════════════
                   TAB 2 — SELF PERFORMANCE
                   ══════════════════════════════════════════════ */
            elseif ($activeTab === 'self'): ?>

                <!-- Self summary cards -->
                <div class="row g-3 mb-4">
                    <!-- Total -->
                    <div class="col-6 col-md-3">
                        <div class="self-stat-card" style="border-top:3px solid <?= $deptColor ?>;">
                            <div style="font-size:2rem;font-weight:800;color:<?= $deptColor ?>;"><?= $selfTotal ?></div>
                            <div style="font-size:.72rem;color:#9ca3af;font-weight:700;text-transform:uppercase;">Total Assigned</div>
                        </div>
                    </div>
                    <!-- Per status -->
                    <?php foreach ($allStatuses as $st):
                        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                        $cnt  = (int)($selfStats[$safe] ?? 0);
                        $sc   = $st['color']    ?: '#9ca3af';
                        $sbg  = $st['bg_color'] ?: '#f9fafb';
                    ?>
                    <div class="col-6 col-md-3 col-xl-2">
                        <div class="self-stat-card" style="border-top:3px solid <?= $sc ?>;">
                            <div style="font-size:2rem;font-weight:800;color:<?= $sc ?>;"><?= $cnt ?></div>
                            <div style="font-size:.72rem;color:#9ca3af;font-weight:700;text-transform:uppercase;">
                                <?= htmlspecialchars($st['status_name']) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <!-- Overdue -->
                    <div class="col-6 col-md-3 col-xl-2">
                        <div class="self-stat-card" style="border-top:3px solid #ef4444;">
                            <div style="font-size:2rem;font-weight:800;color:#ef4444;">
                                <?= (int)($selfStats['overdue'] ?? 0) ?>
                            </div>
                            <div style="font-size:.72rem;color:#9ca3af;font-weight:700;text-transform:uppercase;">Overdue</div>
                        </div>
                    </div>
                    <!-- Completion ring -->
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="completion-ring-card">
                            <?php
                            $src   = 48; $scirc = round(2 * M_PI * $src, 2);
                            $sdsh  = round($scirc * $selfCompletion / 100, 2);
                            ?>
                            <div style="position:relative;flex-shrink:0;">
                                <svg width="110" height="110" viewBox="0 0 110 110">
                                    <circle cx="55" cy="55" r="<?= $src ?>" fill="none" stroke="#e5e7eb" stroke-width="8"/>
                                    <circle cx="55" cy="55" r="<?= $src ?>" fill="none" stroke="<?= $deptColor ?>"
                                            stroke-width="8" stroke-dasharray="<?= $sdsh ?> <?= $scirc ?>"
                                            stroke-linecap="round" transform="rotate(-90 55 55)"/>
                                </svg>
                                <div style="position:absolute;inset:0;display:flex;flex-direction:column;
                                            align-items:center;justify-content:center;">
                                    <div style="font-size:1.4rem;font-weight:800;color:<?= $deptColor ?>;line-height:1;">
                                        <?= $selfCompletion ?>%
                                    </div>
                                    <div style="font-size:.58rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">done</div>
                                </div>
                            </div>
                            <div>
                                <div style="font-size:.95rem;font-weight:700;color:#1f2937;">My Completion</div>
                                <div style="font-size:.75rem;color:#9ca3af;margin-top:.2rem;">
                                    <?= $selfDone ?> of <?= $selfTotal ?> tasks done
                                </div>
                                <div style="font-size:.72rem;color:#1f2937;margin-top:.4rem;font-weight:600;">
                                    <?= htmlspecialchars($adminUser['full_name'] ?? '') ?>
                                </div>
                                <div style="font-size:.68rem;color:#9ca3af;">
                                    <?= htmlspecialchars($adminUser['dept_name'] ?? '') ?> ·
                                    <?= htmlspecialchars($adminUser['branch_name'] ?? '') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Self bar chart -->
                <?php if ($selfTotal > 0): ?>
                <div class="chart-card mb-4">
                    <div class="chart-card-header">
                        <h5 style="margin:0;font-size:.95rem;font-weight:700;">
                            <i class="fas fa-chart-pie me-2" style="color:<?= $deptColor ?>;"></i>My Task Breakdown
                        </h5>
                    </div>
                    <div style="padding:1.25rem;">
                        <div style="max-width:380px;margin:0 auto;height:260px;position:relative;">
                            <canvas id="selfDoughnut"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Self task list -->
                <div class="chart-card mb-4">
                    <div class="chart-card-header">
                        <h5 style="margin:0;font-size:.95rem;font-weight:700;">
                            <i class="fas fa-list-check me-2" style="color:<?= $deptColor ?>;"></i>
                            My Assigned Tasks
                            <span style="font-size:.72rem;color:#9ca3af;font-weight:400;margin-left:.4rem;">
                                (<?= count($selfTasks) ?>)
                            </span>
                        </h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table-mis w-100" style="font-size:.78rem;">
                            <thead>
                                <tr>
                                    <th>Task #</th>
                                    <th>Title</th>
                                    <th>Company</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Due Date</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($selfTasks)): ?>
                                <tr>
                                    <td colspan="8" class="empty-state">
                                        <i class="fas fa-list-check"></i> No tasks assigned to you in this period
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach ($selfTasks as $t):
                                    $overdue = $t['due_date']
                                        && strtotime($t['due_date']) < time()
                                        && $t['status'] !== 'Done';
                                    $sc  = $t['status_color'] ?: '#9ca3af';
                                    $sbg = $t['status_bg']    ?: '#f3f4f6';
                                ?>
                                <tr <?= $overdue ? 'style="background:#fef2f2;"' : '' ?>>
                                    <td>
                                        <span class="task-number"><?= htmlspecialchars($t['task_number']) ?></span>
                                        <?php if ($overdue): ?>
                                            <div style="font-size:.6rem;color:#ef4444;font-weight:700;">OVERDUE</div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:500;">
                                        <?= htmlspecialchars($t['title']) ?>
                                    </td>
                                    <td style="font-size:.75rem;color:#6b7280;">
                                        <?= htmlspecialchars($t['company_name'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <span style="font-size:.7rem;background:<?= htmlspecialchars($t['dept_color']??'#ccc') ?>22;
                                                     color:<?= htmlspecialchars($t['dept_color']??'#666') ?>;
                                                     padding:.15rem .45rem;border-radius:99px;">
                                            <?= htmlspecialchars($t['dept_name'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="background:<?= $sbg ?>;color:<?= $sc ?>;
                                                     padding:.2rem .5rem;border-radius:99px;
                                                     font-size:.7rem;font-weight:600;">
                                            <?= htmlspecialchars($t['status'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge priority-<?= $t['priority'] ?>">
                                            <?= ucfirst($t['priority']) ?>
                                        </span>
                                    </td>
                                    <td style="font-size:.75rem;<?= $overdue ? 'color:#ef4444;font-weight:600;' : 'color:#9ca3af;' ?>">
                                        <?= $t['due_date'] ? date('d M Y', strtotime($t['due_date'])) : '—' ?>
                                    </td>
                                    <td>
                                        <a href="<?= APP_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>"
                                           class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-eye" style="font-size:.7rem;"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <!-- Dept Staff Performance in Self Tab — Head Office & Hetauda only -->
                <?php if ($canSeeAllBranch): ?>
                <div class="chart-card mb-4">
                    <div class="chart-card-header">
                        <div>
                            <h5 style="margin:0;font-size:.95rem;font-weight:700;">
                                <i class="fas fa-users me-2" style="color:<?= $deptColor ?>;"></i>
                                Department Staff Performance
                            </h5>
                            <small style="color:#9ca3af;">
                                <?= htmlspecialchars($adminUser['dept_name'] ?? '') ?> ·
                                <?= htmlspecialchars($adminUser['branch_name'] ?? '') ?> ·
                                <?= count($staffReport) ?> staff
                            </small>
                        </div>
                        <span style="font-size:.7rem;color:#6b7280;background:#f9fafb;
                                     padding:.25rem .65rem;border-radius:99px;border:1px solid #e5e7eb;">
                            <i class="fas fa-info-circle me-1 text-warning"></i>Your branch &amp; department
                        </span>
                    </div>
                    <?php staffTable($staffReport, $allStatuses, $doneKey, $deptColor, $db, false); ?>
                </div>

                <!-- Dept bar chart in self tab -->
                <?php if (!empty($staffReport)): ?>
                <div class="chart-card mb-4">
                    <div class="chart-card-header">
                        <h5 style="margin:0;font-size:.95rem;font-weight:700;">
                            <i class="fas fa-chart-bar me-2" style="color:<?= $deptColor ?>;"></i>
                            Department Task Breakdown
                        </h5>
                        <span style="font-size:.7rem;color:#9ca3af;">Stacked by status</span>
                    </div>
                    <div style="padding:1.25rem;">
                        <div style="height:300px;position:relative;">
                            <canvas id="staffChartSelfDept"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; // end $canSeeAllBranch ?>
            <?php /* ══════════════════════════════════════════════
                   TAB 3 — ALL BRANCHES
                   ══════════════════════════════════════════════ */
           elseif ($activeTab === 'allbranch' && $canSeeAllBranch): ?> 

                <!-- Status summary -->
                <div class="row g-3 mb-4">
                    <?php foreach ($allStatuses as $st):
                        $sn  = $st['status_name'];
                        $cnt = $abStatusReport[$sn] ?? 0;
                        $pct = $abTotalTasks ? round(($cnt / $abTotalTasks) * 100) : 0;
                        $sc  = $st['color']    ?: '#9ca3af';
                        $sbg = $st['bg_color'] ?: '#f3f4f6';
                        $rawI = trim($st['icon'] ?: 'fa-circle');
                        $ico  = str_starts_with($rawI, 'fa') ? $rawI : 'fa-' . $rawI;
                    ?>
                    <div class="col-6 col-md-3 col-xl-2">
                        <div class="rpt-stat" style="--sc:<?= $sc ?>;">
                            <div style="position:absolute;top:.85rem;right:.85rem;width:30px;height:30px;
                                        border-radius:8px;background:<?= $sbg ?>;
                                        display:flex;align-items:center;justify-content:center;">
                                <i class="fas <?= htmlspecialchars($ico) ?>" style="font-size:.75rem;color:<?= $sc ?>;"></i>
                            </div>
                            <div class="rpt-stat-val"><?= $cnt ?></div>
                            <div class="rpt-stat-label"><?= htmlspecialchars($sn) ?></div>
                            <div style="font-size:.68rem;color:#9ca3af;margin-top:.2rem;"><?= $pct ?>% of total</div>
                            <div class="rpt-stat-bar"><div class="rpt-stat-fill" style="width:<?= $pct ?>%;"></div></div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="col-6 col-md-3 col-xl-2">
                        <div class="rpt-stat" style="--sc:<?= $deptColor ?>;">
                            <div style="position:absolute;top:.85rem;right:.85rem;width:30px;height:30px;
                                        border-radius:8px;background:<?= $deptColor ?>18;
                                        display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-layer-group" style="font-size:.75rem;color:<?= $deptColor ?>;"></i>
                            </div>
                            <div class="rpt-stat-val"><?= $abTotalTasks ?></div>
                            <div class="rpt-stat-label">Total Tasks</div>
                            <div style="font-size:.68rem;color:#9ca3af;margin-top:.2rem;">All branches</div>
                            <div class="rpt-stat-bar"><div class="rpt-stat-fill" style="width:100%;"></div></div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="completion-ring-card">
                            <?php
                            $abrc   = 48; $abcirc = round(2 * M_PI * $abrc, 2);
                            $abdsh  = round($abcirc * $abCompletionRate / 100, 2);
                            ?>
                            <div style="position:relative;flex-shrink:0;">
                                <svg width="110" height="110" viewBox="0 0 110 110">
                                    <circle cx="55" cy="55" r="<?= $abrc ?>" fill="none" stroke="#e5e7eb" stroke-width="8"/>
                                    <circle cx="55" cy="55" r="<?= $abrc ?>" fill="none" stroke="<?= $deptColor ?>"
                                            stroke-width="8" stroke-dasharray="<?= $abdsh ?> <?= $abcirc ?>"
                                            stroke-linecap="round" transform="rotate(-90 55 55)"/>
                                </svg>
                                <div style="position:absolute;inset:0;display:flex;flex-direction:column;
                                            align-items:center;justify-content:center;">
                                    <div style="font-size:1.4rem;font-weight:800;color:<?= $deptColor ?>;line-height:1;">
                                        <?= $abCompletionRate ?>%
                                    </div>
                                    <div style="font-size:.58rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">done</div>
                                </div>
                            </div>
                            <div>
                                <div style="font-size:.95rem;font-weight:700;color:#1f2937;">All Branches Completion</div>
                                <div style="font-size:.75rem;color:#9ca3af;margin-top:.2rem;">
                                    <?= $abDoneCount ?> of <?= $abTotalTasks ?> tasks completed
                                </div>
                                <div style="font-size:.7rem;color:#c9a84c;margin-top:.3rem;font-weight:600;">
                                    <?= htmlspecialchars($adminUser['dept_name'] ?? '') ?> — All Branches
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- All-branch staff table -->
                <div class="chart-card mb-4">
                    <div class="chart-card-header">
                        <div>
                            <h5 style="margin:0;font-size:.95rem;font-weight:700;">
                                <i class="fas fa-users me-2" style="color:<?= $deptColor ?>;"></i>
                                Staff Performance — All Branches
                            </h5>
                            <small style="color:#9ca3af;">
                                <?= htmlspecialchars($adminUser['dept_name'] ?? '') ?> ·
                                All Branches · <?= count($abStaffReport) ?> staff
                            </small>
                        </div>
                        <span style="font-size:.7rem;color:#6b7280;background:#f9fafb;
                                     padding:.25rem .65rem;border-radius:99px;border:1px solid #e5e7eb;">
                            <i class="fas fa-info-circle me-1 text-warning"></i>Grouped by branch
                        </span>
                    </div>
                    <?php staffTable($abStaffReport, $allStatuses, $doneKey, $deptColor, $db, true); ?>
                </div>

                <!-- All-branch bar chart -->
                <?php if (!empty($abStaffReport)): ?>
                <div class="chart-card mb-4">
                    <div class="chart-card-header">
                        <h5 style="margin:0;font-size:.95rem;font-weight:700;">
                            <i class="fas fa-chart-bar me-2" style="color:<?= $deptColor ?>;"></i>
                            All Branches — Staff Task Breakdown
                        </h5>
                    </div>
                    <div style="padding:1.25rem;">
                        <div style="height:320px;position:relative;">
                            <canvas id="staffChartAllBranch"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const statuses = <?= json_encode(array_map(fn($st) => [
        'key'   => preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name'])),
        'label' => $st['status_name'],
        'color' => $st['color'] ?: '#9ca3af',
    ], $allStatuses)) ?>;

    // ── TAB 1: Dept staff bar chart ───────────────────────────────────────────
    <?php if ($activeTab === 'dept' && !empty($staffReport)): ?>
    (function () {
        const ctx = document.getElementById('staffChartDept');
        if (!ctx) return;
        const staff = <?= json_encode(array_map(function($s) use ($allStatuses) {
            $row = ['name' => explode(' ', $s['full_name'])[0] ?? 'N/A', 'total' => (int)$s['total']];
            foreach ($allStatuses as $st) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                $row[$safe] = (int)($s[$safe] ?? 0);
            }
            return $row;
        }, $staffReport)) ?>;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: staff.map(d => d.name),
                datasets: statuses.map(st => ({
                    label: st.label, data: staff.map(d => d[st.key] || 0),
                    backgroundColor: st.color, borderRadius: 4, borderSkipped: false,
                }))
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { usePointStyle: true, font: { size: 11 }, padding: 16 } },
                    tooltip: { callbacks: { footer: items => { const t = staff[items[0]?.dataIndex]?.total ?? 0; return t ? `Total: ${t}` : ''; } } }
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: { stacked: true, beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { stepSize: 1, font: { size: 11 } } }
                }
            }
        });
    })();
    <?php endif; ?>

    // ── TAB 2: Self doughnut ──────────────────────────────────────────────────
    <?php if ($activeTab === 'self' && $selfTotal > 0): ?>
    (function () {
        const ctx = document.getElementById('selfDoughnut');
        if (!ctx) return;
        const vals   = <?= json_encode(array_map(function($st) use ($selfStats) {
            $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
            return (int)($selfStats[$safe] ?? 0);
        }, $allStatuses)) ?>;
        const labels = statuses.map(s => s.label);
        const colors = statuses.map(s => s.color);
        new Chart(ctx, {
            type: 'doughnut',
            data: { labels, datasets: [{ data: vals, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '68%',
                plugins: {
                    legend: { position: 'right', labels: { usePointStyle: true, font: { size: 11 }, padding: 14 } },
                    tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}` } }
                }
            }
        });
    })();
    <?php endif; ?>

    // ── TAB 2b: Self tab — dept staff bar chart (Head Office & Hetauda only) ──
    <?php if ($activeTab === 'self' && !empty($staffReport) && $canSeeAllBranch): ?>
    (function () {
        const ctx = document.getElementById('staffChartSelfDept');
        if (!ctx) return;
        const staff = <?= json_encode(array_map(function($s) use ($allStatuses) {
            $row = ['name' => explode(' ', $s['full_name'])[0] ?? 'N/A', 'total' => (int)$s['total']];
            foreach ($allStatuses as $st) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                $row[$safe] = (int)($s[$safe] ?? 0);
            }
            return $row;
        }, $staffReport)) ?>;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: staff.map(d => d.name),
                datasets: statuses.map(st => ({
                    label: st.label, data: staff.map(d => d[st.key] || 0),
                    backgroundColor: st.color, borderRadius: 4, borderSkipped: false,
                }))
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { usePointStyle: true, font: { size: 11 }, padding: 16 } },
                    tooltip: { callbacks: { footer: items => { const t = staff[items[0]?.dataIndex]?.total ?? 0; return t ? `Total: ${t}` : ''; } } }
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: { stacked: true, beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { stepSize: 1, font: { size: 11 } } }
                }
            }
        });
    })();
    <?php endif; ?>
    // ── TAB 3: All Branches staff bar chart ───────────────────────────────────
    <?php if ($activeTab === 'allbranch' && !empty($abStaffReport)): ?>
    (function () {
        const ctx = document.getElementById('staffChartAllBranch');
        if (!ctx) { console.warn('staffChartAllBranch canvas not found'); return; }

        const staff = <?= json_encode(array_map(function($s) use ($allStatuses) {
            // Label: "FirstName (Branch)" so bars are distinguishable across branches
            $firstName  = explode(' ', $s['full_name'])[0] ?? 'N/A';
            $branchShort = explode(' ', $s['branch_name'])[0] ?? '';
            $row = [
                'name'  => $firstName . ($branchShort ? ' (' . $branchShort . ')' : ''),
                'total' => (int)$s['total'],
            ];
            foreach ($allStatuses as $st) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($st['status_name']));
                $row[$safe] = (int)($s[$safe] ?? 0);
            }
            return $row;
        }, $abStaffReport)) ?>;

        if (!staff.length) { console.warn('staffChartAllBranch: no data'); return; }

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: staff.map(d => d.name),
                datasets: statuses.map(st => ({
                    label          : st.label,
                    data           : staff.map(d => d[st.key] || 0),
                    backgroundColor: st.color,
                    borderRadius   : 4,
                    borderSkipped  : false,
                }))
            },
            options: {
                responsive         : true,
                maintainAspectRatio: false,
                interaction        : { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        labels  : { usePointStyle: true, font: { size: 11 }, padding: 16 }
                    },
                    tooltip: {
                        callbacks: {
                            footer: items => {
                                const t = staff[items[0]?.dataIndex]?.total ?? 0;
                                return t ? `Total: ${t}` : '';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid   : { display: false },
                        ticks  : { font: { size: 10 }, maxRotation: 45, minRotation: 30 }
                    },
                    y: {
                        stacked    : true,
                        beginAtZero: true,
                        grid       : { color: '#f3f4f6' },
                        ticks      : { stepSize: 1, font: { size: 11 } },
                        title      : { display: true, text: 'Tasks', font: { size: 11 }, color: '#9ca3af' }
                    }
                }
            }
        });
    })();
    <?php endif; ?>

});
</script>

<?php include '../../includes/footer.php'; ?>