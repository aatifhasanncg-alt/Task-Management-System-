<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../vendor/GoogleAuthenticator.php';


$db = getDB();
$currentUser = currentUser(); // ← renamed — never overwrite this
$pageTitle = 'View Staff';

$staffId = (int) ($_GET['id'] ?? 0);
if (!$staffId) {
    setFlash('error', 'Invalid staff ID.');
    header('Location: index.php');
    exit;
}

// Fetch the STAFF being viewed — use $staffUser not $user
$staffStmt = $db->prepare("
    SELECT u.*,
           r.role_name,
           b.branch_name,
           d.dept_name
    FROM users u
    LEFT JOIN roles r       ON r.id = u.role_id
    LEFT JOIN branches b    ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.id = ?
");
$staffStmt->execute([$staffId]);
$staffUser = $staffStmt->fetch();

if (!$staffUser) {
    setFlash('error', 'Staff not found.');
    header('Location: index.php');
    exit;
}
// Fetch all departments (primary + UDA assignments)
$udaDeptStmt = $db->prepare("
    SELECT d.id, d.dept_name,
           CASE WHEN d.id = ? THEN 1 ELSE 0 END AS is_primary
    FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ?
    UNION
    SELECT d.id, d.dept_name, 1 AS is_primary
    FROM departments d
    WHERE d.id = ?
    ORDER BY is_primary DESC, dept_name ASC
");
$udaDeptStmt->execute([
    $staffUser['department_id'],
    $staffId,
    $staffUser['department_id']
]);
$allStaffDepts = $udaDeptStmt->fetchAll(PDO::FETCH_ASSOC);

// Deduplicate
$seen = [];
$allStaffDepts = array_filter($allStaffDepts, function ($d) use (&$seen) {
    if (in_array($d['id'], $seen))
        return false;
    $seen[] = $d['id'];
    return true;
});
$allStaffDepts = array_values($allStaffDepts);

// Detect logged-in admin's dept — used to filter which depts to show
$viewerDeptId = (int) ($currentUser['department_id'] ?? 0);
$viewerDeptStmt = $db->prepare("SELECT dept_code FROM departments WHERE id = ?");
$viewerDeptStmt->execute([$viewerDeptId]);
$viewerDeptCode = $viewerDeptStmt->fetchColumn() ?: '';
$viewerIsCoreAdmin = ($viewerDeptCode === 'CORE');

// Fetch viewer's UDA depts too
$viewerUdaStmt = $db->prepare("
    SELECT department_id FROM user_department_assignments WHERE user_id = ?
");
$viewerUdaStmt->execute([(int) $currentUser['id']]);
$viewerUdaDepts = array_column($viewerUdaStmt->fetchAll(), 'department_id');
$viewerAllDeptIds = array_unique(array_merge([$viewerDeptId], $viewerUdaDepts));

// Filter $allStaffDepts to only show depts the viewer has access to
// (Core admin sees all, others see only their own depts that overlap)
if (!$viewerIsCoreAdmin) {
    $allStaffDepts = array_values(array_filter($allStaffDepts, function ($d) use ($viewerAllDeptIds) {
        return in_array($d['id'], $viewerAllDeptIds);
    }));
}
// Per-dept task stats (only for visible depts)
$allStatuses = $db->query("
    SELECT id, status_name, color, bg_color
    FROM task_status
    WHERE status_name != 'Corporate Team'
    ORDER BY id
")->fetchAll();

$deptTaskStats = [];
if (!empty($allStaffDepts)) {
    $visibleDeptIds = array_column($allStaffDepts, 'id');
    $inList = implode(',', array_fill(0, count($visibleDeptIds), '?'));
    $dtsStmt = $db->prepare("
        SELECT
            t.department_id,
            d.dept_name,
            d.color        AS dept_color,
            ts.status_name,
            ts.color       AS status_color,
            ts.bg_color    AS status_bg,
            COUNT(DISTINCT t.id) AS cnt
        FROM tasks t
        JOIN departments d  ON d.id  = t.department_id
        JOIN task_status ts ON ts.id = t.status_id
        WHERE t.assigned_to    = ?
          AND t.is_active      = 1
          AND t.department_id IN ({$inList})
          AND ts.status_name  != 'Corporate Team'
        GROUP BY t.department_id, d.dept_name, d.color,
                 ts.status_name, ts.color, ts.bg_color
        ORDER BY ts.id
    ");
    $dtsStmt->execute(array_merge([$staffId], $visibleDeptIds));
    foreach ($dtsStmt->fetchAll() as $row) {
        $did = $row['department_id'];
        $deptTaskStats[$did]['dept_name'] = $row['dept_name'];
        $deptTaskStats[$did]['dept_color'] = $row['dept_color'];
        $deptTaskStats[$did]['statuses'][$row['status_name']] = [
            'cnt' => (int) $row['cnt'],
            'color' => $row['status_color'],
            'bg' => $row['status_bg'],
        ];
    }
}

// Only generate QR if ga_secret exists
$ga = new PHPGangsta_GoogleAuthenticator();

// Only generate QR if ga_secret exists
$ga = new PHPGangsta_GoogleAuthenticator();
$qr = null;
if (!empty($staffUser['ga_secret'])) {
    $qr = $ga->getQRCodeGoogleUrl(
        $staffUser['email'],
        $staffUser['ga_secret'],
        'ASK MIS'
    );
}

// Task stats for this staff member
$taskStmt = $db->prepare("
    SELECT 
        ts.status_name,
        COUNT(t.id) as total
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE t.assigned_to = ? AND t.is_active = 1
    GROUP BY ts.status_name
");
$taskStmt->execute([$staffId]);
$statusData = $taskStmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['Done'=>5,...]

// Total tasks
$totalTasks = array_sum($statusData);

// Overdue count
$overdueStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM tasks t
    LEFT JOIN task_status ts ON ts.id = t.status_id
    WHERE t.assigned_to = ?
      AND t.is_active = 1
      AND t.due_date < CURDATE()
      AND ts.status_name != 'Done'
");
$overdueStmt->execute([$staffId]);
$overdueTasks = $overdueStmt->fetchColumn();
$lastSeen = formatLastSeen(
    $staffUser['active_at'] ?? null,
    $staffUser['last_login'] ?? null
);
include '../../includes/header.php';
?>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
                <div class="d-flex gap-2">
                    <a href="reset_password.php?id=<?= $staffId ?>" class="btn btn-warning btn-sm">
                        <i class="fas fa-key me-1"></i>Reset Password
                    </a>


                </div>
            </div>

            <div class="row g-4">

                <!-- LEFT -->
                <div class="col-lg-8">

                    <!-- Personal Info -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-user text-warning me-2"></i>Personal Information</h5>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3">
                                <?php
                                $personalFields = [
                                    'Full Name' => $staffUser['full_name'],
                                    'Employee ID' => $staffUser['employee_id'] ?? '—',
                                    'Email' => $staffUser['email'],
                                    'Phone' => $staffUser['phone'] ?? '—',
                                    'Joining Date' => $staffUser['joining_date']
                                        ? date('d M Y', strtotime($staffUser['joining_date'])) : '—',
                                    'Address' => $staffUser['address'] ?? '—',
                                ];
                                foreach ($personalFields as $label => $val):
                                    ?>
                                    <div class="col-md-4">
                                        <div
                                            style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                            <?= $label ?>
                                        </div>
                                        <div style="font-size:.88rem;margin-top:.2rem;color:#1f2937;">
                                            <?= htmlspecialchars($val) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Account Info -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-user-shield text-warning me-2"></i>Account Information</h5>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3">
                                <?php

                                $accountFields = [
                                    'Username' => $staffUser['username'],
                                    'Role' => ucfirst($staffUser['role_name'] ?? '—'),
                                    'Branch' => $staffUser['branch_name'] ?? '—',
                                    'Created' => date('d M Y', strtotime($staffUser['created_at'])),
                                ]; ?>

                                <?php foreach ($accountFields as $label => $val): ?>
                                    <div class="col-md-4">
                                        <div
                                            style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                            <?= $label ?>
                                        </div>
                                        <div style="font-size:.88rem;margin-top:.2rem;color:#1f2937;">
                                            <?= htmlspecialchars($val) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Departments — full width, table form -->
                                <div class="col-12">
                                    <div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;
                letter-spacing:.05em;margin-bottom:.5rem;">
                                        Departments
                                    </div>
                                    <?php if (empty($allStaffDepts)): ?>
                                        <span style="font-size:.85rem;color:#9ca3af;">—</span>
                                    <?php else: ?>
                                        <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
                                            <thead>
                                                <tr style="background:#f9fafb;">
                                                    <th style="padding:.35rem .6rem;text-align:left;font-size:.68rem;
                           color:#9ca3af;font-weight:700;text-transform:uppercase;
                           border-bottom:1px solid #e5e7eb;">#</th>
                                                    <th style="padding:.35rem .6rem;text-align:left;font-size:.68rem;
                           color:#9ca3af;font-weight:700;text-transform:uppercase;
                           border-bottom:1px solid #e5e7eb;">Department</th>
                                                    <th style="padding:.35rem .6rem;text-align:left;font-size:.68rem;
                           color:#9ca3af;font-weight:700;text-transform:uppercase;
                           border-bottom:1px solid #e5e7eb;">Type</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($allStaffDepts as $i => $dept): ?>
                                                    <tr style="border-bottom:1px solid #f3f4f6;">
                                                        <td style="padding:.35rem .6rem;color:#9ca3af;"><?= $i + 1 ?></td>
                                                        <td style="padding:.35rem .6rem;font-weight:600;color:#1f2937;">
                                                            <?= htmlspecialchars($dept['dept_name']) ?>
                                                        </td>
                                                        <td style="padding:.35rem .6rem;">
                                                            <?php if ($dept['is_primary']): ?>
                                                                <span style="background:#fef3c7;color:#92400e;font-size:.68rem;
                                     padding:.15rem .5rem;border-radius:99px;font-weight:700;">
                                                                    ★ Primary
                                                                </span>
                                                            <?php else: ?>
                                                                <span style="background:#eff6ff;color:#3b82f6;font-size:.68rem;
                                     padding:.15rem .5rem;border-radius:99px;font-weight:600;">
                                                                    Additional
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>

                                <!-- Last Active — rich UI -->
                                <div class="col-md-4">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                        Last Active
                                    </div>
                                    <div style="margin-top:.3rem;display:flex;align-items:center;gap:.5rem;">
                                        <span style="width:8px;height:8px;border-radius:50%;
                                                         background:<?= $lastSeen['dot'] ?>;
                                                         flex-shrink:0;
                                                         <?= $lastSeen['online'] ? 'box-shadow:0 0 0 3px rgba(16,185,129,.2);' : '' ?>
                                                         display:inline-block;"></span>
                                        <span style="font-size:.85rem;font-weight:600;color:<?= $lastSeen['color'] ?>;">
                                            <?= htmlspecialchars($lastSeen['label']) ?>
                                        </span>
                                    </div>
                                    <?php if (!$lastSeen['online'] && ($staffUser['active_at'] ?? $staffUser['last_login'] ?? null)): ?>
                                        <div style="font-size:.7rem;color:#d1d5db;margin-top:.1rem;">
                                            <?= date('d M Y, H:i', strtotime($staffUser['active_at'] ?? $staffUser['last_login'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Status -->
                                <div class="col-md-4">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                        Status</div>
                                    <div style="margin-top:.2rem;">
                                        <?php if ($staffUser['is_active']): ?>
                                            <span
                                                style="background:#ecfdf5;color:#10b981;padding:.2rem .65rem;border-radius:99px;font-size:.78rem;font-weight:600;">
                                                <i class="fas fa-circle me-1" style="font-size:.5rem;"></i>Active
                                            </span>
                                        <?php else: ?>
                                            <span
                                                style="background:#fef2f2;color:#ef4444;padding:.2rem .65rem;border-radius:99px;font-size:.78rem;font-weight:600;">
                                                <i class="fas fa-circle me-1" style="font-size:.5rem;"></i>Inactive
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- 2FA Status -->
                                <div class="col-md-4">
                                    <div
                                        style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">
                                        2FA Status</div>
                                    <div style="margin-top:.2rem;">
                                        <?php if ($staffUser['ga_enabled']): ?>
                                            <span
                                                style="background:#ecfdf5;color:#10b981;padding:.2rem .65rem;border-radius:99px;font-size:.78rem;font-weight:600;">
                                                <i class="fas fa-shield-alt me-1"></i>Enabled
                                            </span>
                                        <?php else: ?>
                                            <span
                                                style="background:#fff7ed;color:#f59e0b;padding:.2rem .65rem;border-radius:99px;font-size:.78rem;font-weight:600;">
                                                <i class="fas fa-shield me-1"></i>Not Activated
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Task Stats -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-chart-bar text-warning me-2"></i>Task Statistics</h5>
                        </div>
                        <div class="card-mis-body">
                            <div class="row g-3 text-center">

                                <!-- Total -->
                                <div class="col">
                                    <div style="background:#eff6ff;border-radius:10px;padding:.75rem .5rem;">
                                        <i class="fas fa-list-check" style="color:#3b82f6;font-size:1.1rem;"></i>
                                        <div style="font-size:1.4rem;font-weight:700;color:#3b82f6;">
                                            <?= $totalTasks ?>
                                        </div>
                                        <div style="font-size:.7rem;color:#6b7280;">Total</div>
                                    </div>
                                </div>

                                <!-- Dynamic Status -->
                                <?php foreach ($statusData as $status => $count):
                                    $percent = $totalTasks > 0 ? round(($count / $totalTasks) * 100) : 0;

                                    // Color mapping (optional fallback)
                                    $colorMap = [
                                        'Done' => ['#10b981', '#ecfdf5', 'fa-check-circle'],
                                        'WIP' => ['#f59e0b', '#fffbeb', 'fa-spinner'],
                                        'Pending' => ['#ef4444', '#fef2f2', 'fa-clock'],
                                    ];

                                    [$color, $bg, $icon] = $colorMap[$status] ?? ['#6b7280', '#f3f4f6', 'fa-circle'];
                                    ?>
                                    <div class="col">
                                        <div style="background:<?= $bg ?>;border-radius:10px;padding:.75rem .5rem;">
                                            <i class="fas <?= $icon ?>" style="color:<?= $color ?>;"></i>

                                            <div style="font-size:1.2rem;font-weight:700;color:<?= $color ?>;">
                                                <?= $count ?>
                                            </div>

                                            <div style="font-size:.7rem;color:#6b7280;">
                                                <?= htmlspecialchars($status) ?>
                                            </div>

                                            <!-- Percentage -->
                                            <div style="font-size:.65rem;color:#9ca3af;">
                                                <?= $percent ?>%
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Overdue -->
                                <div class="col">
                                    <div style="background:#fef2f2;border-radius:10px;padding:.75rem .5rem;">
                                        <i class="fas fa-exclamation-circle" style="color:#dc2626;"></i>
                                        <div style="font-size:1.2rem;font-weight:700;color:#dc2626;">
                                            <?= $overdueTasks ?>
                                        </div>
                                        <div style="font-size:.7rem;color:#6b7280;">Overdue</div>

                                        <?php
                                        $overduePercent = $totalTasks > 0 ? round(($overdueTasks / $totalTasks) * 100) : 0;
                                        ?>
                                        <div style="font-size:.65rem;color:#9ca3af;">
                                            <?= $overduePercent ?>%
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                    <!-- Per-Department Task Breakdown -->
                    <?php if (!empty($allStaffDepts)): ?>
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-layer-group text-warning me-2"></i>Tasks by Department</h5>
                                <?php if (!$viewerIsCoreAdmin): ?>
                                    <span style="font-size:.68rem;color:#9ca3af;background:#f9fafb;
                     padding:.2rem .6rem;border-radius:99px;border:1px solid #e5e7eb;">
                                        <i class="fas fa-eye me-1"></i>Your dept only
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="card-mis-body">
                                <?php foreach ($allStaffDepts as $dept):
                                    $did = $dept['id'];
                                    $dColor = $dept['color'] ?? ($deptTaskStats[$did]['dept_color'] ?? '#9ca3af');
                                    $statuses = $deptTaskStats[$did]['statuses'] ?? [];
                                    $deptTotal = array_sum(array_column($statuses, 'cnt'));
                                    $doneCnt = 0;
                                    foreach ($statuses as $sn => $sv) {
                                        if (strtolower($sn) === 'done')
                                            $doneCnt = $sv['cnt'];
                                    }
                                    $donePct = $deptTotal > 0 ? round(($doneCnt / $deptTotal) * 100) : 0;
                                    ?>
                                    <div style="background:#f9fafb;border-radius:10px;padding:.85rem 1rem;
                    margin-bottom:.75rem;border-left:4px solid <?= htmlspecialchars($dColor) ?>;">
                                        <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                                            <div class="d-flex align-items-center gap-2">
                                                <div style="width:8px;height:8px;border-radius:50%;
                                background:<?= htmlspecialchars($dColor) ?>;flex-shrink:0;"></div>
                                                <span style="font-size:.87rem;font-weight:700;color:#1f2937;">
                                                    <?= htmlspecialchars($dept['dept_name']) ?>
                                                </span>
                                                <?php if ($dept['is_primary']): ?>
                                                    <span style="background:#fef3c7;color:#92400e;font-size:.65rem;
                                     padding:.1rem .4rem;border-radius:99px;font-weight:700;">★ Primary</span>
                                                <?php else: ?>
                                                    <span style="background:#eff6ff;color:#3b82f6;font-size:.65rem;
                                     padding:.1rem .4rem;border-radius:99px;font-weight:600;">Additional</span>
                                                <?php endif; ?>
                                            </div>
                                            <span
                                                style="font-size:.82rem;font-weight:700;color:<?= htmlspecialchars($dColor) ?>;">
                                                <?= $deptTotal ?> task
                                                <?= $deptTotal !== 1 ? 's' : '' ?>
                                            </span>
                                        </div>

                                        <?php if (empty($statuses)): ?>
                                            <div style="font-size:.78rem;color:#9ca3af;text-align:center;padding:.5rem 0;">
                                                No tasks in this department
                                            </div>
                                        <?php else: ?>
                                            <div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.6rem;">
                                                <?php foreach ($statuses as $sn => $sv): ?>
                                                    <span style="background:<?= htmlspecialchars($sv['bg'] ?: '#f3f4f6') ?>;
                                 color:<?= htmlspecialchars($sv['color'] ?: '#6b7280') ?>;
                                 font-size:.72rem;font-weight:700;
                                 padding:.2rem .55rem;border-radius:99px;
                                 display:inline-flex;align-items:center;gap:.3rem;">
                                                        <?= htmlspecialchars($sn) ?>
                                                        <span style="background:rgba(0,0,0,.08);border-radius:99px;
                                     padding:.05rem .3rem;font-size:.65rem;">
                                                            <?= $sv['cnt'] ?>
                                                        </span>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                            <div style="background:#e5e7eb;border-radius:99px;height:6px;overflow:hidden;">
                                                <div style="width:<?= $donePct ?>%;height:100%;border-radius:99px;
                                background:<?= htmlspecialchars($dColor) ?>;"></div>
                                            </div>
                                            <div style="font-size:.68rem;color:#9ca3af;text-align:right;margin-top:.2rem;">
                                                <?= $donePct ?>% done ·
                                            <?= $doneCnt ?>/
                                                <?= $deptTotal ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- RIGHT -->
                <div class="col-lg-4">

                    <!-- Google 2FA -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-shield-alt text-warning me-2"></i>Google 2FA</h5>
                            <?php if ($staffUser['ga_enabled']): ?>
                                <span
                                    style="background:#ecfdf5;color:#10b981;padding:.2rem .55rem;border-radius:99px;font-size:.72rem;font-weight:600;">Active</span>
                            <?php else: ?>
                                <span
                                    style="background:#fff7ed;color:#f59e0b;padding:.2rem .55rem;border-radius:99px;font-size:.72rem;font-weight:600;">Pending</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-mis-body text-center">

                            <?php if (!empty($staffUser['ga_secret'])): ?>
                                <!-- QR Code -->
                                <div
                                    style="background:#fff;padding:12px;border-radius:10px;border:2px solid #e5e7eb;display:inline-block;margin-bottom:.75rem;">
                                    <img src="<?= htmlspecialchars($qr) ?>" width="170" height="170" alt="2FA QR Code"
                                        style="display:block;">
                                </div>
                                <p style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">
                                    Scan using <strong>Google Authenticator</strong>
                                </p>
                                <!-- Secret Key -->
                                <div
                                    style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:.65rem;text-align:left;">
                                    <div
                                        style="font-size:.7rem;color:#9ca3af;margin-bottom:.3rem;text-transform:uppercase;font-weight:600;">
                                        Secret Key</div>
                                    <div class="d-flex align-items-center gap-2">
                                        <code id="staffGaSecret"
                                            style="font-size:.88rem;font-weight:700;letter-spacing:.1em;flex:1;color:#1f2937;word-break:break-all;">
                                                <?= htmlspecialchars($staffUser['ga_secret']) ?>
                                            </code>
                                        <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0"
                                            onclick="copyStaffSecret()">
                                            <i class="fas fa-copy" id="copyStaffIcon"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php if (!$staffUser['ga_enabled']): ?>
                                    <div
                                        style="margin-top:.75rem;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:.6rem .75rem;font-size:.78rem;color:#92400e;text-align:left;">
                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                        Staff has not activated 2FA yet. Share the QR code or secret key with them.
                                    </div>
                                <?php endif; ?>

                            <?php else: ?>
                                <div class="text-center py-3" style="color:#9ca3af;">
                                    <i class="fas fa-shield fa-2x mb-2 d-block"></i>
                                    <p style="font-size:.85rem;">No 2FA secret set.</p>
                                    <a href="regenerate_2fa.php?id=<?= $staffId ?>" class="btn btn-gold btn-sm">
                                        <i class="fas fa-plus me-1"></i>Generate 2FA Secret
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Admin Actions -->
                    <div class="card-mis">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-cog text-warning me-2"></i>Admin Actions</h5>
                        </div>
                        <div class="card-mis-body">
                            <a href="reset_password.php?id=<?= $staffId ?>" class="btn btn-warning w-100 mb-2">
                                <i class="fas fa-key me-1"></i>Reset Password
                            </a>
                            <a href="regenerate_2fa.php?id=<?= $staffId ?>"
                                class="btn btn-outline-secondary w-100 mb-2">
                                <i class="fas fa-sync me-1"></i>Regenerate 2FA Secret
                            </a>

                        </div>
                    </div>

                </div>
            </div>
            <?php
            // In any user detail/profile page
            require_once '../../config/role_manager.php';

            $history = getUserRoleHistory($staffId);
            $retiredIds = getRetiredEmployeeIds($staffId);
            ?>

            <!-- Role Change History -->
            <div class="card-mis mt-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-history text-warning me-2"></i>Role Change History</h5>
                </div>
                <div class="card-mis-body p-0">
                    <?php if (empty($history)): ?>
                        <p class="text-muted p-3 mb-0">No role changes recorded.</p>
                    <?php else: ?>
                        <table class="table table-sm mb-0">
                            <thead style="background:#f9fafb;font-size:.78rem;">
                                <tr>
                                    <th>Date</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Old ID</th>
                                    <th>New ID</th>
                                    <th>Branch</th>
                                    <th>Changed By</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody style="font-size:.82rem;">
                                <?php foreach ($history as $h): ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($h['changed_at'])) ?></td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <?= htmlspecialchars($h['old_role_name']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning text-dark">
                                                <?= htmlspecialchars($h['new_role_name']) ?>
                                            </span>
                                        </td>
                                        <td><code><?= htmlspecialchars($h['old_employee_id']) ?></code></td>
                                        <td><code><?= htmlspecialchars($h['new_employee_id']) ?></code></td>
                                        <td>
                                            <?php if ($h['old_branch_name'] !== $h['new_branch_name']): ?>
                                                <?= htmlspecialchars($h['old_branch_name'] ?? '—') ?>
                                                → <?= htmlspecialchars($h['new_branch_name'] ?? '—') ?>
                                            <?php else: ?>
                                                <?= htmlspecialchars($h['new_branch_name'] ?? '—') ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($h['changed_by_name']) ?></td>
                                        <td class="text-muted"><?= htmlspecialchars($h['reason'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php include '../../includes/footer.php'; ?>

        <script>
            function copyStaffSecret() {
                const text = document.getElementById('staffGaSecret').textContent.trim();
                navigator.clipboard.writeText(text).then(() => {
                    const icon = document.getElementById('copyStaffIcon');
                    icon.className = 'fas fa-check';
                    icon.style.color = '#10b981';
                    setTimeout(() => { icon.className = 'fas fa-copy'; icon.style.color = ''; }, 2000);
                });
            }
        </script>