<?php
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

function renderTable($entries) {
    echo '<div style="padding:0;">';
    echo '<table class="cn-table" style="margin:0;width:100%;">';
    echo '<thead><tr>
        <th>Client</th>
        <th style="width:90px;">Time In</th>
        <th style="width:90px;">Time Out</th>
        <th class="text-center" style="width:65px;">Hours</th>
        <th>Notes</th>
    </tr></thead>';
    echo '<tbody>';
    foreach ($entries as $e) {
        $name  = htmlspecialchars($e['company_name'] ?? '—');
        $code  = htmlspecialchars($e['company_code']  ?? '');
        $tin   = $e['planned_time_in']  ? date('h:i A', strtotime($e['planned_time_in']))  : '—';
        $tout  = $e['planned_time_out'] ? date('h:i A', strtotime($e['planned_time_out'])) : '—';
        $hours = number_format((float)$e['planned_hours'], 1);
        $notes = htmlspecialchars($e['notes'] ?? '—');
        echo "
        <tr>
            <td>
                <div style='font-weight:600;font-size:.82rem;'>{$name}</div>
                <div style='font-size:.68rem;color:#9ca3af;'>{$code}</div>
            </td>
            <td style='font-size:.81rem;'>{$tin}</td>
            <td style='font-size:.81rem;'>{$tout}</td>
            <td class='text-center'><strong style='color:#c9a84c;'>{$hours}h</strong></td>
            <td style='font-size:.77rem;color:#6b7280;'>{$notes}</td>
        </tr>";
    }
    echo '</tbody></table></div>';
}
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// Fetch entries (today + tomorrow)
$stmt = $db->prepare("
    SELECT wpe.*, c.company_name, c.company_code
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    LEFT JOIN companies c ON c.id = wpe.client_id
    WHERE wp.user_id = ?
      AND wpe.plan_date IN (?, ?)
    ORDER BY wpe.plan_date ASC, wpe.planned_time_in ASC
");
$stmt->execute([$uid, $today, $tomorrow]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Fetch this week's full schedule
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));

$weekStmt = $db->prepare("
    SELECT wpe.*, c.company_name, c.company_code
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    LEFT JOIN companies c ON c.id = wpe.client_id
    WHERE wp.user_id = ?
      AND wpe.plan_date BETWEEN ? AND ?
    ORDER BY wpe.plan_date ASC, wpe.planned_time_in ASC
");
$weekStmt->execute([$uid, $weekStart, $weekEnd]);
$weekData = $weekStmt->fetchAll(PDO::FETCH_ASSOC);

// Group by date
$byDay = [];
foreach ($weekData as $wd) {
    $byDay[$wd['plan_date']][] = $wd;
}

// Total week hours
$weekTotalHours = array_sum(array_column($weekData, 'planned_hours'));
$weekTotalVisits = count($weekData);
// Group
$grouped = [
    'today' => [],
    'tomorrow' => []
];

foreach ($data as $d) {
    if ($d['plan_date'] == $today) {
        $grouped['today'][] = $d;
    } elseif ($d['plan_date'] == $tomorrow) {
        $grouped['tomorrow'][] = $d;
    }
}

$pageTitle = "Today's & Tomorrow's Plan";
include '../../includes/header.php';
?>

<link rel="stylesheet" href="consulting.css">

<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div class="cn-wrap">

            <?= flashHtml() ?>

            <!-- PAGE HERO -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge">
                            <i class="fas fa-calendar-day"></i> Daily Planning
                        </div>
                        <h4>Today & Tomorrow Plans</h4>
                        <p><?= date('d M Y') ?> · Quick View</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <span style="font-size:.78rem;color:#9ca3af;">
                            Week: <?= date('d M', strtotime($weekStart)) ?> – <?= date('d M', strtotime($weekEnd)) ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- WEEK KPI ROW -->
            <div class="kpi-row mb-4">
                <div class="kpi-tile" style="--kpi-color:#3b82f6;">
                    <div class="kpi-icon"><i class="fas fa-calendar-week" style="color:#3b82f6;"></i></div>
                    <div class="kpi-val"><?= $weekTotalVisits ?></div>
                    <div class="kpi-label">Week Visits</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#c9a84c;">
                    <div class="kpi-icon"><i class="fas fa-clock" style="color:#c9a84c;"></i></div>
                    <div class="kpi-val"><?= number_format($weekTotalHours, 1) ?>h</div>
                    <div class="kpi-label">Week Hours</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#10b981;">
                    <div class="kpi-icon"><i class="fas fa-sun" style="color:#10b981;"></i></div>
                    <div class="kpi-val"><?= count($grouped['today']) ?></div>
                    <div class="kpi-label">Today</div>
                </div>
                <div class="kpi-tile" style="--kpi-color:#8b5cf6;">
                    <div class="kpi-icon"><i class="fas fa-moon" style="color:#8b5cf6;"></i></div>
                    <div class="kpi-val"><?= count($grouped['tomorrow']) ?></div>
                    <div class="kpi-label">Tomorrow</div>
                </div>
            </div>

            <!-- TODAY + TOMORROW GRID -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

                <!-- TODAY -->
                <div class="cn-panel">
                    <div class="cn-panel-hd">
                        <span class="cn-panel-title">
                            <i class="fas fa-sun me-2" style="color:var(--gold)"></i>
                            Today — <?= date('l, d M') ?>
                        </span>
                        <span style="font-size:.72rem;color:#9ca3af;"><?= count($grouped['today']) ?> visit(s)</span>
                    </div>
                    <div style="padding:0;">
                        <?php if (empty($grouped['today'])): ?>
                            <div style="padding:30px;text-align:center;color:#9ca3af;font-size:.8rem;">
                                <i class="fas fa-calendar-times"
                                    style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                                No plans for today
                            </div>
                        <?php else:
                            renderTable($grouped['today']); endif; ?>
                    </div>
                </div>

                <!-- TOMORROW -->
                <div class="cn-panel">
                    <div class="cn-panel-hd">
                        <span class="cn-panel-title">
                            <i class="fas fa-moon me-2" style="color:#8b5cf6"></i>
                            Tomorrow — <?= date('l, d M', strtotime('+1 day')) ?>
                        </span>
                        <span style="font-size:.72rem;color:#9ca3af;"><?= count($grouped['tomorrow']) ?> visit(s)</span>
                    </div>
                    <div style="padding:0;">
                        <?php if (empty($grouped['tomorrow'])): ?>
                            <div style="padding:30px;text-align:center;color:#9ca3af;font-size:.8rem;">
                                <i class="fas fa-calendar-times"
                                    style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                                No plans for tomorrow
                            </div>
                        <?php else:
                            renderTable($grouped['tomorrow']); endif; ?>
                    </div>
                </div>

            </div>

            <!-- THIS WEEK SCHEDULE -->
            <div class="cn-panel mb-4">
                <div class="cn-panel-hd" style="justify-content:space-between;">
                    <span class="cn-panel-title">
                        <i class="fas fa-calendar-week me-2" style="color:var(--gold)"></i>
                        This Week's Schedule
                    </span>
                    <span style="font-size:.72rem;color:#9ca3af;">
                        <?= date('d M', strtotime($weekStart)) ?> – <?= date('d M', strtotime($weekEnd)) ?>
                        · <?= number_format($weekTotalHours, 1) ?>h planned
                    </span>
                </div>

                <?php if (empty($byDay)): ?>
                    <div style="padding:40px;text-align:center;color:#9ca3af;font-size:.8rem;">
                        <i class="fas fa-calendar-times" style="font-size:2rem;display:block;margin-bottom:10px;"></i>
                        No plans this week
                    </div>
                <?php else: ?>

                    <?php
                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    $dayDates = [];
                    foreach ($days as $i => $dayName) {
                        $dayDates[$dayName] = date('Y-m-d', strtotime($weekStart . ' +' . $i . ' days'));
                    }
                    ?>

                    <div style="padding:0;">
                        <?php foreach ($dayDates as $dayName => $dayDate):
                            $isToday = ($dayDate === $today);
                            $isTomorrow = ($dayDate === $tomorrow);
                            $isPast = ($dayDate < $today);
                            $dayEntries = $byDay[$dayDate] ?? [];
                            $dayHours = array_sum(array_column($dayEntries, 'planned_hours'));

                            $rowBg = $isToday ? '#fffbeb' : ($isPast && empty($dayEntries) ? '#fafafa' : '#fff');
                            $borderL = $isToday ? '3px solid #c9a84c' : ($isTomorrow ? '3px solid #8b5cf6' : '3px solid transparent');
                            ?>
                            <div style="border-left:<?= $borderL ?>;background:<?= $rowBg ?>;border-bottom:1px solid #f1f5f9;">
                                <!-- Day header -->
                                <div style="padding:10px 16px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;"
                                    onclick="toggleDay('day_<?= $dayDate ?>')">
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div
                                            style="width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;
                        background:<?= $isToday ? '#c9a84c' : ($isTomorrow ? '#8b5cf6' : ($isPast ? '#f1f5f9' : '#f1f5f9')) ?>;
                        color:<?= $isToday ? '#fff' : ($isTomorrow ? '#fff' : '#9ca3af') ?>;font-weight:700;font-size:.78rem;">
                                            <?= date('d', strtotime($dayDate)) ?>
                                        </div>
                                        <div>
                                            <div
                                                style="font-size:.85rem;font-weight:700;color:<?= $isPast && empty($dayEntries) ? '#d1d5db' : '#1f2937' ?>;">
                                                <?= $dayName ?>
                                                <?php if ($isToday): ?>
                                                    <span
                                                        style="background:#c9a84c;color:#fff;font-size:.62rem;padding:1px 6px;border-radius:10px;margin-left:5px;vertical-align:middle;">TODAY</span>
                                                <?php elseif ($isTomorrow): ?>
                                                    <span
                                                        style="background:#8b5cf6;color:#fff;font-size:.62rem;padding:1px 6px;border-radius:10px;margin-left:5px;vertical-align:middle;">TOMORROW</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size:.7rem;color:#9ca3af;">
                                                <?= date('d M Y', strtotime($dayDate)) ?></div>
                                        </div>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:12px;">
                                        <?php if (!empty($dayEntries)): ?>
                                            <span
                                                style="background:#f0fdf4;color:#15803d;padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:600;">
                                                <?= count($dayEntries) ?> visit(s)
                                            </span>
                                            <span style="font-size:.78rem;font-weight:700;color:#c9a84c;">
                                                <?= number_format($dayHours, 1) ?>h
                                            </span>
                                        <?php else: ?>
                                            <span style="font-size:.75rem;color:#d1d5db;">No visits</span>
                                        <?php endif; ?>
                                        <i class="fas fa-chevron-down" style="font-size:.7rem;color:#9ca3af;transition:.2s;"
                                            id="icon_<?= $dayDate ?>"></i>
                                    </div>
                                </div>

                                <!-- Day entries (collapsible) -->
                                <?php if (!empty($dayEntries)): ?>
                                    <div id="day_<?= $dayDate ?>"
                                        style="<?= $isToday || $isTomorrow ? '' : 'display:none;' ?>padding:0 16px 12px 62px;">
                                        <table class="cn-table" style="margin:0;border-radius:8px;overflow:hidden;">
                                            <thead>
                                                <tr>
                                                    <th>Client</th>
                                                    <th style="width:90px;">Time In</th>
                                                    <th style="width:90px;">Time Out</th>
                                                    <th class="text-center" style="width:65px;">Hours</th>
                                                    <th>Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($dayEntries as $e): ?>
                                                    <tr>
                                                        <td>
                                                            <div style="font-weight:600;font-size:.82rem;">
                                                                <?= htmlspecialchars($e['company_name'] ?? '—') ?></div>
                                                            <div style="font-size:.68rem;color:#9ca3af;">
                                                                <?= htmlspecialchars($e['company_code'] ?? '') ?></div>
                                                        </td>
                                                        <td style="font-size:.81rem;">
                                                            <?= $e['planned_time_in'] ? date('h:i A', strtotime($e['planned_time_in'])) : '—' ?>
                                                        </td>
                                                        <td style="font-size:.81rem;">
                                                            <?= $e['planned_time_out'] ? date('h:i A', strtotime($e['planned_time_out'])) : '—' ?>
                                                        </td>
                                                        <td class="text-center"><strong
                                                                style="color:#c9a84c;"><?= number_format((float) $e['planned_hours'], 1) ?>h</strong>
                                                        </td>
                                                        <td style="font-size:.77rem;color:#6b7280;">
                                                            <?= htmlspecialchars($e['notes'] ?? '—') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /cn-wrap -->
    </div>
</div>
<script>
function toggleDay(id) {
    const el   = document.getElementById(id);
    const date = id.replace('day_', '');
    const icon = document.getElementById('icon_' + date);
    if (!el) return;
    const open = el.style.display !== 'none';
    el.style.display = open ? 'none' : 'block';
    if (icon) icon.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
}
</script>
<?php include '../../includes/footer.php'; ?>