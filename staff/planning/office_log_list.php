<?php
/**
 * consulting/staff/office_log_list.php — Staff: My Office Work Logs
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];

$now   = new DateTime();
$month = $_GET['month'] ?? $now->format('Y-m');

// Validate month
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = $now->format('Y-m');
}

// Month navigation
$monthDt   = DateTime::createFromFormat('Y-m', $month);
$prevMonth = (clone $monthDt)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthDt)->modify('+1 month')->format('Y-m');
$monthLabel = $monthDt->format('F Y');

// Filters
$filterStatus = $_GET['status'] ?? '';
$filterClient = (int)($_GET['client_id'] ?? 0);

// Build query
$where  = ['owl.user_id = ?', "DATE_FORMAT(owl.log_date,'%Y-%m') = ?"];
$params = [$uid, $month];

if ($filterStatus && in_array($filterStatus, ['wip','completed'])) {
    $where[]  = 'owl.status = ?';
    $params[] = $filterStatus;
}
if ($filterClient) {
    $where[]  = 'owl.client_id = ?';
    $params[] = $filterClient;
}

$whereStr = implode(' AND ', $where);

$logs = $db->prepare("
    SELECT owl.*,
           c.company_name, c.company_code,
           ROUND(TIME_TO_SEC(TIMEDIFF(owl.time_out, owl.time_in)) / 3600, 2) AS duration_hours
    FROM office_work_logs owl
    JOIN companies c ON c.id = owl.client_id
    WHERE {$whereStr}
    ORDER BY owl.log_date DESC, owl.time_in DESC
");
$logs->execute($params);
$logs = $logs->fetchAll();

// Summary stats for this month (unfiltered)
$stats = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status='wip') AS wip_count,
        SUM(status='completed') AS completed_count,
        ROUND(SUM(TIME_TO_SEC(TIMEDIFF(time_out, time_in))) / 3600, 2) AS total_hours
    FROM office_work_logs
    WHERE user_id=? AND DATE_FORMAT(log_date,'%Y-%m')=?
");
$stats->execute([$uid, $month]);
$stats = $stats->fetch();

// Clients for filter dropdown
$clients = $db->prepare("
    SELECT DISTINCT c.id, c.company_name
    FROM office_work_logs owl
    JOIN companies c ON c.id = owl.client_id
    WHERE owl.user_id=? AND DATE_FORMAT(owl.log_date,'%Y-%m')=?
    ORDER BY c.company_name
");
$clients->execute([$uid, $month]);
$clients = $clients->fetchAll();

$statusMeta = [
    'wip'       => ['label' => 'WIP',       'color' => '#3b82f6', 'bg' => '#eff6ff', 'icon' => 'fa-spinner'],
    'completed' => ['label' => 'Completed', 'color' => '#10b981', 'bg' => '#f0fdf4', 'icon' => 'fa-check-circle'],
];

$pageTitle = 'Office Work Logs';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">

<div class="app-wrapper">
    <?php include '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <?= flashHtml() ?>

            <!-- Hero -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-building"></i> Office Work</div>
                        <h4>My Office Work Logs</h4>
                        <p><?= htmlspecialchars($user['full_name']) ?> · <?= $monthLabel ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="office_log_create.php" class="cn-btn cn-btn-gold cn-btn-sm">
                            <i class="fas fa-plus"></i> Log Work
                        </a>
                        <a href="log_list.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-car me-1"></i> Visit Logs
                        </a>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- Month navigator -->
            <div style="display:flex;align-items:center;justify-content:center;gap:12px;
                        margin-bottom:20px;">
                <a href="?month=<?= $prevMonth ?><?= $filterStatus ? '&status='.$filterStatus : '' ?>"
                   class="cn-btn cn-btn-out cn-btn-sm">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <div style="font-size:.95rem;font-weight:700;color:#1f2937;min-width:140px;text-align:center;">
                    <?= $monthLabel ?>
                </div>
                <a href="?month=<?= $nextMonth ?><?= $filterStatus ? '&status='.$filterStatus : '' ?>"
                   class="cn-btn cn-btn-out cn-btn-sm"
                   <?= $nextMonth > $now->format('Y-m') ? 'style="opacity:.4;pointer-events:none;"' : '' ?>>
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>

            <!-- Stats -->
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
                <?php
                $statCards = [
                    ['fa-list-check',    'Total Logs',      $stats['total']          ?? 0,  '#c9a84c', '#fffbeb'],
                    ['fa-spinner',       'WIP',             $stats['wip_count']      ?? 0,  '#3b82f6', '#eff6ff'],
                    ['fa-check-circle',  'Completed',       $stats['completed_count']?? 0,  '#10b981', '#f0fdf4'],
                    ['fa-clock',         'Total Hours',     ($stats['total_hours']   ?? 0) . 'h', '#8b5cf6', '#f5f3ff'],
                ];
                foreach ($statCards as [$icon, $label, $val, $color, $bg]):
                ?>
                <div style="background:<?= $bg ?>;border:1.5px solid <?= $color ?>22;border-radius:12px;
                            padding:14px 16px;text-align:center;">
                    <div style="width:36px;height:36px;border-radius:8px;background:<?= $color ?>20;
                                display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                        <i class="fas <?= $icon ?>" style="color:<?= $color ?>;font-size:.85rem;"></i>
                    </div>
                    <div style="font-size:1.4rem;font-weight:800;color:<?= $color ?>;"><?= $val ?></div>
                    <div style="font-size:.7rem;color:#9ca3af;margin-top:2px;"><?= $label ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <div class="cn-panel mb-3">
                <div style="padding:12px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <span style="font-size:.8rem;font-weight:600;color:#6b7280;">
                        <i class="fas fa-filter me-1"></i>Filter:
                    </span>

                    <!-- Status pills -->
                    <a href="?month=<?= $month ?><?= $filterClient ? '&client_id='.$filterClient : '' ?>"
                       style="font-size:.75rem;padding:.25rem .7rem;border-radius:99px;text-decoration:none;
                              border:1.5px solid <?= !$filterStatus ? '#c9a84c' : '#e5e7eb' ?>;
                              background:<?= !$filterStatus ? '#fffbeb' : '#fff' ?>;
                              color:<?= !$filterStatus ? '#c9a84c' : '#6b7280' ?>;font-weight:600;">
                        All
                    </a>
                    <?php foreach ($statusMeta as $sKey => $sm): ?>
                    <a href="?month=<?= $month ?>&status=<?= $sKey ?><?= $filterClient ? '&client_id='.$filterClient : '' ?>"
                       style="font-size:.75rem;padding:.25rem .7rem;border-radius:99px;text-decoration:none;
                              border:1.5px solid <?= $filterStatus === $sKey ? $sm['color'] : '#e5e7eb' ?>;
                              background:<?= $filterStatus === $sKey ? $sm['bg'] : '#fff' ?>;
                              color:<?= $filterStatus === $sKey ? $sm['color'] : '#6b7280' ?>;font-weight:600;">
                        <i class="fas <?= $sm['icon'] ?> me-1"></i><?= $sm['label'] ?>
                    </a>
                    <?php endforeach; ?>

                    <?php if (!empty($clients)): ?>
                    <select onchange="window.location='?month=<?= $month ?><?= $filterStatus ? '&status='.$filterStatus : '' ?>&client_id='+this.value"
                            style="font-size:.78rem;padding:.25rem .6rem;border:1.5px solid #e5e7eb;
                                   border-radius:8px;background:#fff;color:#374151;cursor:pointer;">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $cl): ?>
                            <option value="<?= $cl['id'] ?>" <?= $filterClient == $cl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cl['company_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                </div>
            </div>

            <!-- Log list -->
            <?php if (empty($logs)): ?>
                <div class="cn-panel">
                    <div style="padding:48px 24px;text-align:center;">
                        <div style="width:60px;height:60px;border-radius:50%;background:#f9fafb;
                                    display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                            <i class="fas fa-building" style="font-size:1.4rem;color:#d1d5db;"></i>
                        </div>
                        <div style="font-size:.95rem;font-weight:700;color:#374151;margin-bottom:6px;">
                            No office logs found
                        </div>
                        <div style="font-size:.8rem;color:#9ca3af;margin-bottom:16px;">
                            <?= $filterStatus ? 'Try clearing the filter or ' : '' ?>
                            Start by logging your first office work session.
                        </div>
                        <a href="office_log_create.php" class="cn-btn cn-btn-gold" style="display:inline-flex;">
                            <i class="fas fa-plus"></i> Log Office Work
                        </a>
                    </div>
                </div>

            <?php else: ?>

                <?php
                // Group by date
                $grouped = [];
                foreach ($logs as $l) {
                    $grouped[$l['log_date']][] = $l;
                }
                ?>

                <?php foreach ($grouped as $date => $dayLogs): ?>
                    <!-- Date header -->
                    <div style="display:flex;align-items:center;gap:10px;margin:18px 0 8px;">
                        <div style="font-size:.78rem;font-weight:700;color:#374151;white-space:nowrap;">
                            <?= date('l, d M Y', strtotime($date)) ?>
                            <?= $date === date('Y-m-d') ? '<span style="background:#fef3c7;color:#d97706;font-size:.65rem;padding:.1rem .4rem;border-radius:4px;margin-left:4px;">Today</span>' : '' ?>
                        </div>
                        <div style="flex:1;height:1px;background:#f3f4f6;"></div>
                        <?php
                        $dayHours = array_sum(array_column($dayLogs, 'duration_hours'));
                        ?>
                        <div style="font-size:.72rem;color:#9ca3af;white-space:nowrap;">
                            <?= count($dayLogs) ?> log<?= count($dayLogs) > 1 ? 's' : '' ?> · <?= $dayHours ?>h
                        </div>
                    </div>

                    <?php foreach ($dayLogs as $l):
                        $sm = $statusMeta[$l['status']] ?? $statusMeta['wip'];
                    ?>
                    <div class="cn-panel mb-2" style="border-left:3px solid <?= $sm['color'] ?>;">
                        <div style="padding:14px 16px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">

                                <!-- Left: Client + desc -->
                                <div style="flex:1;min-width:0;">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                                        <span style="font-size:.88rem;font-weight:700;color:#1f2937;">
                                            <?= htmlspecialchars($l['company_name']) ?>
                                        </span>
                                        <?php if ($l['company_code']): ?>
                                        <span style="font-size:.68rem;background:#f3f4f6;color:#6b7280;
                                                     border-radius:4px;padding:.1rem .4rem;font-weight:600;">
                                            <?= htmlspecialchars($l['company_code']) ?>
                                        </span>
                                        <?php endif; ?>
                                        <span style="font-size:.68rem;background:<?= $sm['bg'] ?>;
                                                     color:<?= $sm['color'] ?>;border-radius:4px;
                                                     padding:.1rem .4rem;font-weight:700;">
                                            <i class="fas <?= $sm['icon'] ?> me-1" style="font-size:.6rem;"></i>
                                            <?= $sm['label'] ?>
                                        </span>
                                    </div>
                                    <div style="font-size:.8rem;color:#6b7280;
                                                overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
                                                max-width:500px;">
                                        <?= htmlspecialchars(mb_strimwidth($l['description'], 0, 120, '…')) ?>
                                    </div>
                                    <?php if (!empty($l['notes'])): ?>
                                    <div style="font-size:.73rem;color:#f59e0b;margin-top:3px;">
                                        <i class="fas fa-sticky-note me-1"></i>
                                        <?= htmlspecialchars(mb_strimwidth($l['notes'], 0, 80, '…')) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Right: Time + actions -->
                                <div style="display:flex;align-items:center;gap:12px;flex-shrink:0;">
                                    <div style="text-align:center;">
                                        <div style="font-size:1rem;font-weight:800;color:#c9a84c;">
                                            <?= $l['duration_hours'] ?>h
                                        </div>
                                        <div style="font-size:.65rem;color:#9ca3af;white-space:nowrap;">
                                            <?= date('H:i', strtotime($l['time_in'])) ?>
                                            – <?= date('H:i', strtotime($l['time_out'])) ?>
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:6px;">
                                        <a href="office_log_view.php?id=<?= $l['id'] ?>"
                                           class="cn-btn cn-btn-out cn-btn-sm" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="office_log_edit.php?id=<?= $l['id'] ?>"
                                           class="cn-btn cn-btn-gold cn-btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>

            <?php endif; ?>

        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>