<?php
/**
 * consulting/dashboard.php — Performance Dashboard
 * Shows logged-in user's own performance:
 *   • Visit logs  (work_logs)
 *   • Office logs (office_work_logs)
 *   • Client-wise, date-wise, monthly trend, team summary (admin)
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];
$currentRole = $_SESSION['role'] ?? ($user['role'] ?? '');
$isAdmin = in_array($currentRole, ['admin', 'executive']);
$branchId = (int) $user['branch_id'];

// ── Resolve consulting department ID for this user ─────────────────────────
$__deptMetaQ = $db->prepare(
    "SELECT dept_code, dept_name FROM departments WHERE id = ?"
);
$__deptMetaQ->execute([$user['department_id']]);
$__deptMeta = $__deptMetaQ->fetch(PDO::FETCH_ASSOC);
$__primaryCode = $__deptMeta['dept_code'] ?? '';
$__isConsPrimary = ($__primaryCode === 'CON'
    || stripos($__deptMeta['dept_name'] ?? '', 'consult') !== false);
$__isCoreAdmin = ($__primaryCode === 'CORE');

$__udaQ = $db->prepare("
    SELECT d.id, d.dept_code
    FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ?
      AND (d.dept_code = 'CON' OR d.dept_name LIKE '%consult%')
    LIMIT 1
");
$__udaQ->execute([$uid]);
$__udaCons = $__udaQ->fetch(PDO::FETCH_ASSOC);

if ($__isConsPrimary) {
    $deptId = (int) $user['department_id'];
} elseif ($__udaCons) {
    $deptId = (int) $__udaCons['id'];
} else {
    $deptId = (int) $user['department_id'];
}

// ── Month selection ────────────────────────────────────────────────────────
$now = new DateTime();
$month = $_GET['month'] ?? $now->format('Y-m');
$monthDate = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthLabel = $monthDate->format('F Y');
$monthStart = $monthDate->format('Y-m-01');

// ═══════════════════════════════════════════════════════════════════════════
//  VISIT LOGS (work_logs)
// ═══════════════════════════════════════════════════════════════════════════

// ── Planned hours (work_plan_entries → work_plans) ─────────────────────────
$stPlanned = $db->prepare("
    SELECT COALESCE(SUM(wpe.planned_hours), 0)
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    WHERE wpe.assigned_to  = ?
      AND wp.plan_month    = ?
      AND wp.department_id = ?
");
$stPlanned->execute([$uid, $monthStart, $deptId]);
$plannedHours = (float) $stPlanned->fetchColumn();

// ── Actual visit hours ─────────────────────────────────────────────────────
$stActual = $db->prepare("
    SELECT COALESCE(SUM(duration_hours), 0)
    FROM work_logs
    WHERE user_id       = ?
      AND month_year    = ?
      AND department_id = ?
");
$stActual->execute([$uid, $month, $deptId]);
$actualHours = (float) $stActual->fetchColumn();

// "Time Efficiency": planned hrs ÷ actual hrs × 100
// If planned=2h, actual=1h → 200% (completed in half the time)
// If planned=2h, actual=2h → 100%
// If planned=2h, actual=4h →  50% (took twice as long)
$efficiencyRaw = $actualHours > 0 && $plannedHours > 0
    ? round(($plannedHours / $actualHours) * 100, 1)
    : ($plannedHours > 0 && $actualHours == 0 ? 0 : null);

$efficiency = $efficiencyRaw !== null ? min($efficiencyRaw, 200) : 0; // cap at 200%
$overDelivered = $efficiencyRaw > 100;

// ── Visit status breakdown ─────────────────────────────────────────────────
$stVst = $db->prepare("
    SELECT visit_status, COUNT(*) AS cnt
    FROM work_logs
    WHERE user_id       = ?
      AND month_year    = ?
      AND department_id = ?
    GROUP BY visit_status
");
$stVst->execute([$uid, $month, $deptId]);
$vst = [];
foreach ($stVst->fetchAll() as $r) {
    $vst[$r['visit_status']] = (int) $r['cnt'];
}
$visited = $vst['visited'] ?? 0;
$missed = $vst['missed'] ?? 0;
$rescheduled = $vst['rescheduled'] ?? 0;
$totalLogs = $visited + $missed + $rescheduled;

// ── Unique clients (visit logs) ────────────────────────────────────────────
$stUC = $db->prepare("
    SELECT COUNT(DISTINCT client_id)
    FROM work_logs
    WHERE user_id       = ?
      AND month_year    = ?
      AND department_id = ?
");
$stUC->execute([$uid, $month, $deptId]);
$uniqueVisitClients = (int) $stUC->fetchColumn();

// ═══════════════════════════════════════════════════════════════════════════
//  OFFICE LOGS (office_work_logs)
// ═══════════════════════════════════════════════════════════════════════════

// ── Office hours & stats ───────────────────────────────────────────────────
$stOff = $db->prepare("
    SELECT
        COUNT(*)                                                     AS total_entries,
        COALESCE(SUM(TIMESTAMPDIFF(MINUTE, time_in, time_out)/60), 0) AS total_hours,
        SUM(status = 'completed')                                    AS completed,
        SUM(status = 'wip')                                          AS wip,
        SUM(status = 'holding')                                      AS holding,
        SUM(status = 'not_started')                                  AS not_started,
        COUNT(DISTINCT client_id)                                    AS unique_clients
    FROM office_work_logs
    WHERE user_id = ?
        AND DATE_FORMAT(log_date, '%Y-%m') = ?
");
$stOff->execute([$uid, $month]);
$offStats = $stOff->fetch(PDO::FETCH_ASSOC);
$officeHours = round((float) $offStats['total_hours'], 2);
$officeEntries = (int) $offStats['total_entries'];
$officeCompleted = (int) $offStats['completed'];
$officeWip = (int) $offStats['wip'];
$officeHolding = (int) $offStats['holding'];
$officeNotStarted = (int) $offStats['not_started'];
$officeClients = (int) $offStats['unique_clients'];

// ── Client-wise VISIT performance ──────────────────────────────────────────
$stClientVisit = $db->prepare("
    SELECT
        c.id                                         AS client_id,
        c.company_name,
        c.company_code,
        COUNT(wl.id)                                 AS total_visits,
        SUM(wl.visit_status = 'visited')             AS visited,
        SUM(wl.visit_status = 'missed')              AS missed,
        SUM(wl.visit_status = 'rescheduled')         AS rescheduled,
        COALESCE(SUM(wl.duration_hours), 0)          AS actual_hours,
        COALESCE((
            SELECT SUM(wpe.planned_hours)
            FROM work_plan_entries wpe
            JOIN work_plans wp ON wp.id = wpe.plan_id
            WHERE wpe.assigned_to   = ?
              AND wpe.client_id     = c.id
              AND wp.plan_month     = ?
              AND wp.department_id  = ?
        ), 0)                                        AS planned_hours,
        MIN(wl.log_date)                             AS first_visit,
        MAX(wl.log_date)                             AS last_visit
    FROM work_logs wl
    LEFT JOIN companies c ON c.id = wl.client_id
    WHERE wl.user_id       = ?
      AND wl.month_year    = ?
      AND wl.department_id = ?
    GROUP BY c.id, c.company_name, c.company_code
    ORDER BY actual_hours DESC, total_visits DESC
");
$stClientVisit->execute([$uid, $monthStart, $deptId, $uid, $month, $deptId]);
$clientVisitPerf = $stClientVisit->fetchAll(PDO::FETCH_ASSOC);

// ── Client-wise OFFICE performance ─────────────────────────────────────────
$stClientOff = $db->prepare("
    SELECT
        c.id                                                          AS client_id,
        c.company_name,
        c.company_code,
        COUNT(owl.id)                                                 AS total_entries,
        COALESCE(SUM(TIMESTAMPDIFF(MINUTE,owl.time_in,owl.time_out)/60), 0) AS total_hours,
        SUM(owl.status = 'completed')                                 AS completed,
        SUM(owl.status = 'wip')                                       AS wip,
        SUM(owl.status = 'holding')                                   AS holding,
        MIN(owl.log_date)                                             AS first_log,
        MAX(owl.log_date)                                             AS last_log
    FROM office_work_logs owl
    LEFT JOIN companies c ON c.id = owl.client_id
    WHERE owl.user_id       = ?
      AND owl.department_id = ?
      AND DATE_FORMAT(owl.log_date, '%Y-%m') = ?
    GROUP BY c.id, c.company_name, c.company_code
    ORDER BY total_hours DESC, total_entries DESC
");
$stClientOff->execute([$uid, $deptId, $month]);
$clientOffPerf = $stClientOff->fetchAll(PDO::FETCH_ASSOC);

// ── Date-wise VISIT performance ────────────────────────────────────────────
$stDateVisit = $db->prepare("
    SELECT
        wl.log_date,
        wl.day_of_week,
        COUNT(wl.id)                                    AS total_visits,
        SUM(wl.visit_status = 'visited')                AS visited,
        SUM(wl.visit_status = 'missed')                 AS missed,
        SUM(wl.visit_status = 'rescheduled')            AS rescheduled,
        COALESCE(SUM(wl.duration_hours), 0)             AS actual_hours,
        GROUP_CONCAT(c.company_name ORDER BY wl.time_in SEPARATOR ', ') AS clients_visited
    FROM work_logs wl
    LEFT JOIN companies c ON c.id = wl.client_id
    WHERE wl.user_id       = ?
      AND wl.month_year    = ?
      AND wl.department_id = ?
    GROUP BY wl.log_date, wl.day_of_week
    ORDER BY wl.log_date ASC
");
$stDateVisit->execute([$uid, $month, $deptId]);
$dateVisitPerf = $stDateVisit->fetchAll(PDO::FETCH_ASSOC);

// ── Date-wise OFFICE performance ───────────────────────────────────────────
$stDateOff = $db->prepare("
    SELECT
        owl.log_date,
        DAYNAME(owl.log_date)                                          AS day_of_week,
        COUNT(owl.id)                                                  AS total_entries,
        COALESCE(SUM(TIMESTAMPDIFF(MINUTE,owl.time_in,owl.time_out)/60),0) AS total_hours,
        SUM(owl.status = 'completed')                                  AS completed,
        SUM(owl.status = 'wip')                                        AS wip,
        GROUP_CONCAT(c.company_name ORDER BY owl.time_in SEPARATOR ', ') AS clients_worked
    FROM office_work_logs owl
    LEFT JOIN companies c ON c.id = owl.client_id
    WHERE owl.user_id       = ?
      AND owl.department_id = ?
      AND DATE_FORMAT(owl.log_date, '%Y-%m') = ?
    GROUP BY owl.log_date
    ORDER BY owl.log_date ASC
");
$stDateOff->execute([$uid, $deptId, $month]);
$dateOffPerf = $stDateOff->fetchAll(PDO::FETCH_ASSOC);

// ── Monthly trend — last 6 months (both log types) ─────────────────────────
$stTrend = $db->prepare("
    SELECT
        v.month_year,
        COALESCE(v.actual_hours, 0)    AS visit_hours,
        COALESCE(v.total_logs, 0)      AS visit_logs,
        COALESCE(v.visited, 0)         AS visited,
        COALESCE(o.office_hours, 0)    AS office_hours,
        COALESCE(o.office_entries, 0)  AS office_entries
    FROM (
        SELECT
            month_year,
            COALESCE(SUM(duration_hours), 0) AS actual_hours,
            COUNT(*)                          AS total_logs,
            SUM(visit_status = 'visited')     AS visited
        FROM work_logs
        WHERE user_id = ? AND department_id = ?
        GROUP BY month_year
        ORDER BY month_year DESC
        LIMIT 6
    ) v
    LEFT JOIN (
        SELECT
            DATE_FORMAT(log_date, '%Y-%m')                              AS month_year,
            COALESCE(SUM(TIMESTAMPDIFF(MINUTE,time_in,time_out)/60), 0) AS office_hours,
            COUNT(*)                                                     AS office_entries
        FROM office_work_logs
        WHERE user_id = ? AND department_id = ?
        GROUP BY DATE_FORMAT(log_date, '%Y-%m')
    ) o ON o.month_year = v.month_year
    ORDER BY v.month_year ASC
");
$stTrend->execute([$uid, $deptId, $uid, $deptId]);
$trendRows = $stTrend->fetchAll(PDO::FETCH_ASSOC);

// ── Team summary (admin only — subordinates under login user) ──────────────
$teamRows = [];
if ($isAdmin) {
    $stTeam = $db->prepare("
        SELECT DISTINCT
            u.id,
            u.full_name,
            u.employee_id,
            COUNT(DISTINCT wl.id)                        AS visit_logs,
            COALESCE(SUM(wl.duration_hours), 0)          AS visit_hours,
            SUM(wl.visit_status = 'visited')             AS v_visited,
            SUM(wl.visit_status = 'missed')              AS v_missed,
            COUNT(DISTINCT wl.client_id)                 AS visit_clients,
            COUNT(DISTINCT owl.id)                       AS office_entries,
            COALESCE(SUM(TIMESTAMPDIFF(MINUTE,owl.time_in,owl.time_out)/60),0) AS office_hours,
            COUNT(DISTINCT owl.client_id)                AS office_clients
        FROM users u
        LEFT JOIN work_logs wl
            ON  wl.user_id       = u.id
            AND wl.month_year    = ?
            AND wl.department_id = ?
        LEFT JOIN office_work_logs owl
            ON  owl.user_id       = u.id
            AND owl.department_id = ?
            AND DATE_FORMAT(owl.log_date,'%Y-%m') = ?
        WHERE u.is_active = 1
          AND u.id != ?
          AND (
              u.managed_by = ?
              OR
              EXISTS (
                  SELECT 1 FROM user_department_assignments uda2
                  JOIN departments d2 ON d2.id = uda2.department_id
                  WHERE uda2.user_id    = u.id
                    AND uda2.managed_by = ?
                    AND (d2.dept_code   = 'CON' OR d2.dept_name LIKE '%consult%')
              )
          )
          AND (
              EXISTS (
                  SELECT 1 FROM departments dp
                  WHERE dp.id = u.department_id
                    AND (dp.dept_code = 'CON' OR dp.dept_name LIKE '%consult%')
              )
              OR
              EXISTS (
                  SELECT 1 FROM user_department_assignments uda3
                  JOIN departments d3 ON d3.id = uda3.department_id
                  WHERE uda3.user_id = u.id
                    AND (d3.dept_code = 'CON' OR d3.dept_name LIKE '%consult%')
              )
          )
        GROUP BY u.id, u.full_name, u.employee_id
        ORDER BY visit_hours DESC, office_hours DESC
    ");
    $stTeam->execute([
        $month, $deptId,
        $deptId, $month,
        $uid, $uid, $uid,
    ]);
    $teamRows = $stTeam->fetchAll(PDO::FETCH_ASSOC);
}

// ── Chart data ─────────────────────────────────────────────────────────────
$chartLabels      = array_column($trendRows, 'month_year');
$chartVisitHours  = array_map(fn($r) => (float) $r['visit_hours'],  $trendRows);
$chartOfficeHours = array_map(fn($r) => (float) $r['office_hours'], $trendRows);
$chartVisitLogs   = array_map(fn($r) => (int)   $r['visit_logs'],   $trendRows);

$pageTitle = 'Performance Dashboard';
include '../../includes/header.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <!-- ══ Page Hero ══════════════════════════════════════════════ -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge">
                            <i class="fas fa-chart-bar"></i> Performance
                        </div>
                        <h4>Performance Dashboard</h4>
                        <p>
                            <?= htmlspecialchars($user['full_name']) ?> ·
                            <?= $isAdmin ? 'Admin View' : 'My Performance' ?> ·
                            <?= $monthLabel ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="month" class="form-control form-control-sm" style="width:155px;"
                            value="<?= $month ?>" onchange="location='?month='+this.value">
                        <?php if ($isAdmin): ?>
                            <a href="log_list.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-history me-1"></i>All Logs
                            </a>
                            <a href="plan_list.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-list me-1"></i>All Plans
                            </a>
                        <?php else: ?>
                            <a href="staff/plan_create.php" class="btn-gold btn btn-sm">
                                <i class="fas fa-plus me-1"></i>New Plan
                            </a>
                            <a href="staff/log_create.php" class="btn-gold btn btn-sm">
                                <i class="fas fa-clock me-1"></i>Log Visit
                            </a>
                            <a href="staff/office_log_create.php" class="btn-gold btn btn-sm">
                                <i class="fas fa-building me-1"></i>Office Log
                            </a>
                        <?php endif; ?>
                        <a href="<?= APP_URL ?>/exports/export_pdf.php?module=consulting_performance&month=<?= urlencode($month) ?>"
                            class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-file-pdf me-1" style="color:#ef4444;"></i>PDF
                        </a>
                        <a href="<?= APP_URL ?>/exports/export_excel.php?module=consulting_performance&month=<?= urlencode($month) ?>"
                            class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-file-excel me-1" style="color:#10b981;"></i>Excel
                        </a>
                    </div>
                </div>
            </div>

            <!-- ══ TAB SWITCHER ══════════════════════════════════════════ -->
            <ul class="nav nav-tabs mb-4" id="dashTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabVisit">
                        <i class="fas fa-route me-1 text-warning"></i>Visit Logs
                        <span class="badge bg-secondary ms-1"><?= $totalLogs ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabOffice">
                        <i class="fas fa-building me-1 text-warning"></i>Office Logs
                        <span class="badge bg-secondary ms-1"><?= $officeEntries ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabTrend">
                        <i class="fas fa-chart-line me-1 text-warning"></i>Trend &amp; History
                    </button>
                </li>
                <?php if ($isAdmin && !empty($teamRows)): ?>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabTeam">
                            <i class="fas fa-users me-1 text-warning"></i>Team
                            <span class="badge bg-secondary ms-1"><?= count($teamRows) ?></span>
                        </button>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="tab-content">

                <!-- ══════════════════════════════════════════════════════
                     TAB 1 — VISIT LOGS
                ═════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade show active" id="tabVisit">

                    <!-- KPI row — visit -->
                    <div class="kpi-row mb-4">
                        <?php
                        $kpis = [
                            ['icon' => 'fa-clipboard-list', 'color' => '#3b82f6', 'val' => $totalLogs,           'label' => 'Total Visits'],
                            ['icon' => 'fa-clock',          'color' => '#c9a84c', 'val' => number_format($actualHours,1).'h', 'label' => 'Actual Hours'],
                            ['icon' => 'fa-building',       'color' => '#8b5cf6', 'val' => $uniqueVisitClients,  'label' => 'Clients Visited'],
                            ['icon' => 'fa-check-circle',   'color' => '#10b981', 'val' => $visited,             'label' => 'Visited'],
                            ['icon' => 'fa-times-circle',   'color' => '#ef4444', 'val' => $missed,              'label' => 'Missed'],
                            ['icon' => 'fa-redo',           'color' => '#f59e0b', 'val' => $rescheduled,         'label' => 'Rescheduled'],
                        ];
                        foreach ($kpis as $k): ?>
                            <div class="kpi-tile" style="--kpi-color:<?= $k['color'] ?>;">
                                <div class="kpi-icon"><i class="fas <?= $k['icon'] ?>" style="color:<?= $k['color'] ?>;"></i></div>
                                <div class="kpi-val"><?= $k['val'] ?></div>
                                <div class="kpi-label"><?= $k['label'] ?></div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Efficiency tile -->
                        <?php
                        $effColor = $efficiencyRaw === null ? '#9ca3af'
                            : ($efficiencyRaw >= 100 ? '#10b981' : ($efficiencyRaw >= 60 ? '#f59e0b' : '#ef4444'));
                        $effLabel = ($efficiencyRaw >= 100) ? '✅ Ahead of Plan' : '⚠ Behind Plan';
                        ?>
                        <div class="kpi-tile" style="--kpi-color:<?= $effColor ?>;">
                            <div class="kpi-icon"><i class="fas fa-tachometer-alt" style="color:<?= $effColor ?>;"></i></div>
                            <div class="kpi-val" style="color:<?= $effColor ?>;">
                                <?= $efficiencyRaw !== null ? $efficiencyRaw . '%' : '—' ?>
                                <?php if ($efficiencyRaw > 100): ?>
                                    <span style="font-size:.6rem;background:#dcfce7;color:#15803d;padding:1px 5px;border-radius:4px;vertical-align:middle;">↑ Fast</span>
                                <?php endif; ?>
                            </div>
                            <div class="kpi-label">Time Efficiency</div>
                            <div class="kpi-delta" style="color:#9ca3af;font-size:.68rem;">
                                <?= number_format($plannedHours, 1) ?>h planned · <?= number_format($actualHours, 1) ?>h used
                            </div>
                            <?php if ($efficiencyRaw !== null): ?>
                                <div style="font-size:.63rem;font-weight:600;margin-top:3px;color:<?= $effColor ?>;">
                                    <?= $effLabel ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Planned vs Actual bar + visit breakdown -->
                    <div class="row g-4 mb-4">
                        <div class="col-lg-4">
                            <div class="card-mis h-100">
                                <div class="card-mis-header">
                                    <h5><i class="fas fa-chart-pie text-warning me-2"></i>Visit Breakdown</h5>
                                </div>
                                <div class="card-mis-body">
                                    <?php
                                    $maxH = max($plannedHours, $actualHours, 1);
                                    $pw   = round(($plannedHours / $maxH) * 100);
                                    $aw   = round(($actualHours  / $maxH) * 100);
                                    $ec   = $effColor;
                                    ?>
                                    <div style="margin-bottom:16px;">
                                        <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#9ca3af;margin-bottom:4px;">
                                            <span>Planned</span>
                                            <strong style="color:#3b82f6;"><?= number_format($plannedHours, 1) ?>h</strong>
                                        </div>
                                        <div style="background:#f1f5f9;border-radius:99px;height:7px;overflow:hidden;">
                                            <div style="width:<?= $pw ?>%;height:100%;background:#3b82f6;border-radius:99px;"></div>
                                        </div>
                                        <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#9ca3af;margin:8px 0 4px;">
                                            <span>Actual</span>
                                            <strong style="color:<?= $ec ?>;"><?= number_format($actualHours, 1) ?>h</strong>
                                        </div>
                                        <div style="background:#f1f5f9;border-radius:99px;height:7px;overflow:hidden;">
                                            <div style="width:<?= $aw ?>%;height:100%;background:<?= $ec ?>;border-radius:99px;"></div>
                                        </div>
                                    </div>
                                    <hr style="margin:12px 0;">
                                    <?php if ($totalLogs > 0): ?>
                                        <div style="font-size:.72rem;color:#9ca3af;margin-bottom:5px;">Visit Status Distribution</div>
                                        <div style="display:flex;border-radius:6px;overflow:hidden;height:12px;margin-bottom:6px;">
                                            <?php if ($visited):    ?><div style="flex:<?= $visited ?>;background:#10b981;" title="Visited: <?= $visited ?>"></div><?php endif; ?>
                                            <?php if ($missed):     ?><div style="flex:<?= $missed ?>;background:#ef4444;"  title="Missed: <?= $missed ?>"></div><?php endif; ?>
                                            <?php if ($rescheduled):?><div style="flex:<?= $rescheduled ?>;background:#f59e0b;" title="Rescheduled: <?= $rescheduled ?>"></div><?php endif; ?>
                                        </div>
                                        <div style="display:flex;gap:10px;font-size:.72rem;flex-wrap:wrap;">
                                            <span style="color:#10b981;">● Visited <?= $visited ?> (<?= $totalLogs ? round($visited / $totalLogs * 100) : 0 ?>%)</span>
                                            <span style="color:#ef4444;">● Missed <?= $missed ?></span>
                                            <span style="color:#f59e0b;">● Rescheduled <?= $rescheduled ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div style="text-align:center;color:#9ca3af;font-size:.82rem;padding:12px 0;">No logs recorded this month</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Plan Coverage card — FIXED if/elseif/else/endif -->
                        <div class="col-lg-8">
                            <div class="card-mis h-100">
                                <div class="card-mis-header">
                                    <h5><i class="fas fa-calendar-check text-warning me-2"></i>Plan Coverage — <?= $monthLabel ?></h5>
                                </div>
                                <div class="card-mis-body"
                                    style="height:200px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;">
                                    <?php if ($plannedHours > 0 && $actualHours > 0): ?>
                                        <div style="font-size:2rem;font-weight:800;color:#3b82f6;"><?= number_format($plannedHours, 1) ?>h</div>
                                        <div style="color:#9ca3af;font-size:.83rem;">planned this month</div>
                                        <div style="margin-top:8px;font-size:1.2rem;font-weight:700;color:#c9a84c;"><?= number_format($actualHours, 1) ?>h</div>
                                        <div style="color:#9ca3af;font-size:.83rem;">time actually used</div>
                                        <div style="margin-top:10px;font-size:1.4rem;font-weight:700;color:<?= $effColor ?>;">
                                            <?= $efficiencyRaw ?>% Time Efficiency
                                        </div>
                                        <div style="font-size:.72rem;color:#9ca3af;margin-top:4px;">
                                            <?php if ($efficiencyRaw >= 100): ?>
                                                ✅ Finished faster than planned
                                            <?php else: ?>
                                                ⚠ Used more time than planned
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($plannedHours > 0): ?>
                                        <i class="fas fa-hourglass-start" style="font-size:2rem;color:#d1d5db;"></i>
                                        <div style="color:#9ca3af;font-size:.85rem;margin-top:8px;"><?= number_format($plannedHours, 1) ?>h planned — no logs yet</div>
                                    <?php else: ?>
                                        <i class="fas fa-calendar-times" style="font-size:2rem;color:#d1d5db;"></i>
                                        <div style="color:#9ca3af;font-size:.85rem;">No work plan found for <?= $monthLabel ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Client-wise VISIT performance -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-route text-warning me-2"></i>Client-wise Visit Performance — <?= $monthLabel ?></h5>
                            <span style="font-size:.78rem;color:#9ca3af;"><?= count($clientVisitPerf) ?> clients</span>
                        </div>
                        <?php if (empty($clientVisitPerf)): ?>
                            <div class="card-mis-body">
                                <div class="empty-state">
                                    <i class="fas fa-building"></i>
                                    <h6>No visit logs for <?= $monthLabel ?></h6>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-mis w-100">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Client</th>
                                            <th class="text-center">Visits</th>
                                            <th class="text-center">Visited</th>
                                            <th class="text-center">Missed</th>
                                            <th class="text-center">Rescheduled</th>
                                            <th class="text-center">Planned Hrs</th>
                                            <th class="text-center">Actual Hrs</th>
                                            <th class="text-center">Efficiency</th>
                                            <th>First</th>
                                            <th>Last</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($clientVisitPerf as $i => $cp):
                                            $effRaw = ((float)$cp['planned_hours'] > 0 && (float)$cp['actual_hours'] > 0)
                                                ? round((float)$cp['planned_hours'] / (float)$cp['actual_hours'] * 100)
                                                : null;
                                            $eff = $effRaw !== null ? min($effRaw, 200) : null;
                                            $ec  = $eff === null ? '#9ca3af'
                                                 : ($eff >= 100 ? '#10b981' : ($eff >= 60 ? '#f59e0b' : '#ef4444'));
                                            ?>
                                            <tr>
                                                <td style="color:#9ca3af;font-size:.75rem;"><?= $i + 1 ?></td>
                                                <td>
                                                    <div style="font-weight:600;font-size:.85rem;"><?= htmlspecialchars($cp['company_name'] ?? '—') ?></div>
                                                    <div style="font-size:.7rem;color:#9ca3af;"><?= htmlspecialchars($cp['company_code'] ?? '') ?></div>
                                                </td>
                                                <td class="text-center"><strong><?= $cp['total_visits'] ?></strong></td>
                                                <td class="text-center">
                                                    <span style="background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;"><?= $cp['visited'] ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($cp['missed'] > 0): ?>
                                                        <span style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;"><?= $cp['missed'] ?></span>
                                                    <?php else: ?>
                                                        <span style="color:#d1d5db;font-size:.78rem;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($cp['rescheduled'] > 0): ?>
                                                        <span style="background:#fffbeb;color:#b45309;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;"><?= $cp['rescheduled'] ?></span>
                                                    <?php else: ?>
                                                        <span style="color:#d1d5db;font-size:.78rem;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center" style="color:#3b82f6;font-weight:600;">
                                                    <?= number_format((float) $cp['planned_hours'], 1) ?>h
                                                </td>
                                                <td class="text-center">
                                                    <strong style="color:<?= hoursColor((float) $cp['actual_hours']) ?>;"><?= number_format((float) $cp['actual_hours'], 1) ?>h</strong>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($eff !== null): ?>
                                                        <div style="display:flex;align-items:center;gap:5px;justify-content:center;">
                                                            <div style="flex:1;max-width:55px;background:#f1f5f9;border-radius:99px;height:5px;overflow:hidden;">
                                                                <div style="width:<?= $eff ?>%;height:100%;background:<?= $ec ?>;border-radius:99px;"></div>
                                                            </div>
                                                            <span style="font-size:.75rem;font-weight:700;color:<?= $ec ?>;"><?= $eff ?>%</span>
                                                        </div>
                                                        <?php if ($effRaw > 100): ?>
                                                            <div style="font-size:.63rem;color:#f59e0b;margin-top:2px;">⚠ <?= $effRaw ?>%</div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span style="font-size:.75rem;color:#9ca3af;">No plan</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size:.78rem;color:#6b7280;white-space:nowrap;">
                                                    <?= $cp['first_visit'] ? date('d M', strtotime($cp['first_visit'])) : '—' ?>
                                                </td>
                                                <td style="font-size:.78rem;color:#6b7280;white-space:nowrap;">
                                                    <?= $cp['last_visit'] ? date('d M', strtotime($cp['last_visit'])) : '—' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr style="background:#f9fafb;font-weight:700;">
                                            <td colspan="2" style="padding:10px 14px;font-size:.82rem;color:#374151;"><i class="fas fa-calculator me-1 text-warning"></i>TOTAL</td>
                                            <td class="text-center"><?= $totalLogs ?></td>
                                            <td class="text-center" style="color:#15803d;"><?= $visited ?></td>
                                            <td class="text-center" style="color:#b91c1c;"><?= $missed ?></td>
                                            <td class="text-center" style="color:#b45309;"><?= $rescheduled ?></td>
                                            <td class="text-center" style="color:#3b82f6;"><?= number_format($plannedHours, 1) ?>h</td>
                                            <td class="text-center" style="color:#c9a84c;"><?= number_format($actualHours, 1) ?>h</td>
                                            <td class="text-center" style="color:<?= $effColor ?>;"><?= $efficiency ?>%</td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Date-wise VISIT performance -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-calendar-day text-warning me-2"></i>Date-wise Visit Performance — <?= $monthLabel ?></h5>
                            <span style="font-size:.78rem;color:#9ca3af;"><?= count($dateVisitPerf) ?> active day(s)</span>
                        </div>
                        <?php if (empty($dateVisitPerf)): ?>
                            <div class="card-mis-body">
                                <div class="empty-state"><i class="fas fa-calendar-times"></i>
                                    <h6>No visit data for <?= $monthLabel ?></h6>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-mis w-100">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th class="text-center">Visits</th>
                                            <th class="text-center">Visited</th>
                                            <th class="text-center">Missed</th>
                                            <th class="text-center">Rescheduled</th>
                                            <th class="text-center">Hours</th>
                                            <th>Clients</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $runHours = 0;
                                        foreach ($dateVisitPerf as $dp):
                                            $runHours += (float) $dp['actual_hours']; ?>
                                            <tr>
                                                <td><strong style="font-size:.85rem;"><?= date('d M Y', strtotime($dp['log_date'])) ?></strong></td>
                                                <td><span style="font-size:.78rem;color:#6b7280;"><?= $dp['day_of_week'] ?></span></td>
                                                <td class="text-center"><strong><?= $dp['total_visits'] ?></strong></td>
                                                <td class="text-center">
                                                    <span style="background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;"><?= $dp['visited'] ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($dp['missed'] > 0): ?>
                                                        <span style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;"><?= $dp['missed'] ?></span>
                                                    <?php else: ?>
                                                        <span style="color:#d1d5db;font-size:.78rem;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($dp['rescheduled'] > 0): ?>
                                                        <span style="background:#fffbeb;color:#b45309;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;"><?= $dp['rescheduled'] ?></span>
                                                    <?php else: ?>
                                                        <span style="color:#d1d5db;font-size:.78rem;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <strong style="color:<?= hoursColor((float) $dp['actual_hours']) ?>;"><?= number_format((float) $dp['actual_hours'], 1) ?>h</strong>
                                                    <span style="font-size:.68rem;color:#9ca3af;">(<?= number_format($runHours, 1) ?>h)</span>
                                                </td>
                                                <td style="font-size:.77rem;color:#6b7280;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                                    title="<?= htmlspecialchars($dp['clients_visited'] ?? '') ?>">
                                                    <?= htmlspecialchars(mb_strimwidth($dp['clients_visited'] ?? '—', 0, 50, '…')) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr style="background:#f9fafb;font-weight:700;">
                                            <td colspan="2" style="padding:10px 14px;font-size:.82rem;color:#374151;"><i class="fas fa-calculator me-1 text-warning"></i>TOTAL</td>
                                            <td class="text-center"><?= $totalLogs ?></td>
                                            <td class="text-center" style="color:#15803d;"><?= $visited ?></td>
                                            <td class="text-center" style="color:#b91c1c;"><?= $missed ?></td>
                                            <td class="text-center" style="color:#b45309;"><?= $rescheduled ?></td>
                                            <td class="text-center" style="color:#c9a84c;"><?= number_format($actualHours, 1) ?>h</td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                </div><!-- /tabVisit -->

                <!-- ══════════════════════════════════════════════════════
                     TAB 2 — OFFICE LOGS
                ═════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade" id="tabOffice">

                    <!-- KPI row — office -->
                    <div class="kpi-row mb-4">
                        <?php
                        $offKpis = [
                            ['icon' => 'fa-briefcase',    'color' => '#3b82f6', 'val' => $officeEntries,                      'label' => 'Total Entries'],
                            ['icon' => 'fa-clock',        'color' => '#c9a84c', 'val' => number_format($officeHours,1).'h',    'label' => 'Office Hours'],
                            ['icon' => 'fa-building',     'color' => '#8b5cf6', 'val' => $officeClients,                      'label' => 'Clients Worked'],
                            ['icon' => 'fa-check-circle', 'color' => '#10b981', 'val' => $officeCompleted,                    'label' => 'Completed'],
                            ['icon' => 'fa-spinner',      'color' => '#3b82f6', 'val' => $officeWip,                          'label' => 'WIP'],
                            ['icon' => 'fa-pause-circle', 'color' => '#f59e0b', 'val' => $officeHolding,                      'label' => 'Holding'],
                            ['icon' => 'fa-circle',       'color' => '#9ca3af', 'val' => $officeNotStarted,                   'label' => 'Not Started'],
                        ];
                        foreach ($offKpis as $k): ?>
                            <div class="kpi-tile" style="--kpi-color:<?= $k['color'] ?>;">
                                <div class="kpi-icon"><i class="fas <?= $k['icon'] ?>" style="color:<?= $k['color'] ?>;"></i></div>
                                <div class="kpi-val"><?= $k['val'] ?></div>
                                <div class="kpi-label"><?= $k['label'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Client-wise OFFICE performance -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-building text-warning me-2"></i>Client-wise Office Performance — <?= $monthLabel ?></h5>
                            <span style="font-size:.78rem;color:#9ca3af;"><?= count($clientOffPerf) ?> clients</span>
                        </div>
                        <?php if (empty($clientOffPerf)): ?>
                            <div class="card-mis-body">
                                <div class="empty-state"><i class="fas fa-building"></i>
                                    <h6>No office logs for <?= $monthLabel ?></h6>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-mis w-100">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Client</th>
                                            <th class="text-center">Entries</th>
                                            <th class="text-center">Hours</th>
                                            <th class="text-center">Completed</th>
                                            <th class="text-center">WIP</th>
                                            <th class="text-center">Holding</th>
                                            <th>First Log</th>
                                            <th>Last Log</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $totalOffH = 0;
                                        foreach ($clientOffPerf as $i => $cp):
                                            $totalOffH += (float) $cp['total_hours']; ?>
                                            <tr>
                                                <td style="color:#9ca3af;font-size:.75rem;"><?= $i + 1 ?></td>
                                                <td>
                                                    <div style="font-weight:600;font-size:.85rem;"><?= htmlspecialchars($cp['company_name'] ?? '—') ?></div>
                                                    <div style="font-size:.7rem;color:#9ca3af;"><?= htmlspecialchars($cp['company_code'] ?? '') ?></div>
                                                </td>
                                                <td class="text-center"><strong><?= $cp['total_entries'] ?></strong></td>
                                                <td class="text-center"><strong style="color:#c9a84c;"><?= number_format((float) $cp['total_hours'], 1) ?>h</strong></td>
                                                <td class="text-center">
                                                    <span style="background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;"><?= $cp['completed'] ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($cp['wip'] > 0): ?>
                                                        <span style="background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;"><?= $cp['wip'] ?></span>
                                                    <?php else: ?>
                                                        <span style="color:#d1d5db;font-size:.78rem;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($cp['holding'] > 0): ?>
                                                        <span style="background:#fffbeb;color:#b45309;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;"><?= $cp['holding'] ?></span>
                                                    <?php else: ?>
                                                        <span style="color:#d1d5db;font-size:.78rem;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size:.78rem;color:#6b7280;white-space:nowrap;">
                                                    <?= $cp['first_log'] ? date('d M', strtotime($cp['first_log'])) : '—' ?>
                                                </td>
                                                <td style="font-size:.78rem;color:#6b7280;white-space:nowrap;">
                                                    <?= $cp['last_log'] ? date('d M', strtotime($cp['last_log'])) : '—' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr style="background:#f9fafb;font-weight:700;">
                                            <td colspan="2" style="padding:10px 14px;font-size:.82rem;color:#374151;"><i class="fas fa-calculator me-1 text-warning"></i>TOTAL</td>
                                            <td class="text-center"><?= $officeEntries ?></td>
                                            <td class="text-center" style="color:#c9a84c;"><?= number_format($officeHours, 1) ?>h</td>
                                            <td class="text-center" style="color:#15803d;"><?= $officeCompleted ?></td>
                                            <td class="text-center" style="color:#1d4ed8;"><?= $officeWip ?></td>
                                            <td class="text-center" style="color:#b45309;"><?= $officeHolding ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Date-wise OFFICE performance -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-calendar-day text-warning me-2"></i>Date-wise Office Performance — <?= $monthLabel ?></h5>
                            <span style="font-size:.78rem;color:#9ca3af;"><?= count($dateOffPerf) ?> active day(s)</span>
                        </div>
                        <?php if (empty($dateOffPerf)): ?>
                            <div class="card-mis-body">
                                <div class="empty-state"><i class="fas fa-calendar-times"></i>
                                    <h6>No office data for <?= $monthLabel ?></h6>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-mis w-100">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th class="text-center">Entries</th>
                                            <th class="text-center">Hours</th>
                                            <th class="text-center">Completed</th>
                                            <th class="text-center">WIP</th>
                                            <th>Clients</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $runOffH = 0;
                                        foreach ($dateOffPerf as $dp):
                                            $runOffH += (float) $dp['total_hours']; ?>
                                            <tr>
                                                <td><strong style="font-size:.85rem;"><?= date('d M Y', strtotime($dp['log_date'])) ?></strong></td>
                                                <td><span style="font-size:.78rem;color:#6b7280;"><?= $dp['day_of_week'] ?></span></td>
                                                <td class="text-center"><strong><?= $dp['total_entries'] ?></strong></td>
                                                <td class="text-center">
                                                    <strong style="color:#c9a84c;"><?= number_format((float) $dp['total_hours'], 1) ?>h</strong>
                                                    <span style="font-size:.68rem;color:#9ca3af;">(<?= number_format($runOffH, 1) ?>h)</span>
                                                </td>
                                                <td class="text-center">
                                                    <span style="background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;"><?= $dp['completed'] ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($dp['wip'] > 0): ?>
                                                        <span style="background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:20px;font-size:.75rem;font-weight:600;"><?= $dp['wip'] ?></span>
                                                    <?php else: ?>
                                                        <span style="color:#d1d5db;font-size:.78rem;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size:.77rem;color:#6b7280;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                                    title="<?= htmlspecialchars($dp['clients_worked'] ?? '') ?>">
                                                    <?= htmlspecialchars(mb_strimwidth($dp['clients_worked'] ?? '—', 0, 50, '…')) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr style="background:#f9fafb;font-weight:700;">
                                            <td colspan="2" style="padding:10px 14px;font-size:.82rem;color:#374151;"><i class="fas fa-calculator me-1 text-warning"></i>TOTAL</td>
                                            <td class="text-center"><?= $officeEntries ?></td>
                                            <td class="text-center" style="color:#c9a84c;"><?= number_format($officeHours, 1) ?>h</td>
                                            <td class="text-center" style="color:#15803d;"><?= $officeCompleted ?></td>
                                            <td class="text-center" style="color:#1d4ed8;"><?= $officeWip ?></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                </div><!-- /tabOffice -->

                <!-- ══════════════════════════════════════════════════════
                     TAB 3 — TREND & HISTORY
                ═════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade" id="tabTrend">

                    <!-- Combined trend chart -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-chart-line text-warning me-2"></i>Hours Trend — Last 6 Months</h5>
                        </div>
                        <div class="card-mis-body" style="height:300px;">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>

                    <!-- Monthly history table -->
                    <div class="card-mis mb-4">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-history text-warning me-2"></i>My Monthly Performance History</h5>
                        </div>
                        <?php if (empty($trendRows)): ?>
                            <div class="card-mis-body">
                                <div class="empty-state"><i class="fas fa-chart-line"></i>
                                    <h6>No historical data yet</h6>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-mis w-100">
                                    <thead>
                                        <tr>
                                            <th>Month</th>
                                            <th class="text-center">Visit Logs</th>
                                            <th class="text-center">Visited</th>
                                            <th class="text-center">Visit Hours</th>
                                            <th class="text-center">Office Entries</th>
                                            <th class="text-center">Office Hours</th>
                                            <th class="text-center">Total Hours</th>
                                            <th>Visit Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_reverse($trendRows) as $tr):
                                            $vRate   = $tr['visit_logs'] > 0 ? round($tr['visited'] / $tr['visit_logs'] * 100) : 0;
                                            $vrColor = $vRate >= 80 ? '#10b981' : ($vRate >= 50 ? '#f59e0b' : '#ef4444');
                                            $totalH  = (float) $tr['visit_hours'] + (float) $tr['office_hours'];
                                            ?>
                                            <tr <?= $tr['month_year'] === $month ? 'style="background:rgba(201,168,76,.04);"' : '' ?>>
                                                <td>
                                                    <strong style="<?= $tr['month_year'] === $month ? 'color:#c9a84c;' : '' ?>">
                                                        <?= date('F Y', strtotime($tr['month_year'] . '-01')) ?>
                                                        <?= $tr['month_year'] === $month ? ' ← current' : '' ?>
                                                    </strong>
                                                </td>
                                                <td class="text-center"><?= $tr['visit_logs'] ?></td>
                                                <td class="text-center" style="color:#15803d;font-weight:600;"><?= $tr['visited'] ?></td>
                                                <td class="text-center" style="color:#c9a84c;font-weight:700;"><?= number_format((float) $tr['visit_hours'], 1) ?>h</td>
                                                <td class="text-center"><?= $tr['office_entries'] ?></td>
                                                <td class="text-center" style="color:#3b82f6;font-weight:700;"><?= number_format((float) $tr['office_hours'], 1) ?>h</td>
                                                <td class="text-center" style="color:#8b5cf6;font-weight:700;"><?= number_format($totalH, 1) ?>h</td>
                                                <td>
                                                    <div class="perf-bar">
                                                        <div class="perf-bar-track">
                                                            <div class="perf-bar-fill" style="width:<?= $vRate ?>%;background:<?= $vrColor ?>;"></div>
                                                        </div>
                                                        <span style="font-size:.78rem;font-weight:700;color:<?= $vrColor ?>;min-width:35px;"><?= $vRate ?>%</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                </div><!-- /tabTrend -->

                <!-- ══════════════════════════════════════════════════════
                     TAB 4 — TEAM (admin only)
                ═════════════════════════════════════════════════════════ -->
                <?php if ($isAdmin): ?>
                    <div class="tab-pane fade" id="tabTeam">
                        <?php if (empty($teamRows)): ?>
                            <div class="card-mis mb-4">
                                <div class="card-mis-header">
                                    <h5><i class="fas fa-users text-warning me-2"></i>Team Performance — <?= $monthLabel ?></h5>
                                </div>
                                <div class="card-mis-body">
                                    <div class="empty-state"><i class="fas fa-users"></i>
                                        <h6>No team data for <?= $monthLabel ?></h6>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card-mis mb-4">
                                <div class="card-mis-header">
                                    <h5><i class="fas fa-users text-warning me-2"></i>Team Performance — <?= $monthLabel ?></h5>
                                    <span style="font-size:.78rem;color:#9ca3af;"><?= count($teamRows) ?> staff member(s)</span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table-mis w-100">
                                        <thead>
                                            <tr>
                                                <th>Staff</th>
                                                <th class="text-center">Visit Logs</th>
                                                <th class="text-center">Visited</th>
                                                <th class="text-center">Missed</th>
                                                <th class="text-center">Visit Clients</th>
                                                <th class="text-center">Visit Hours</th>
                                                <th class="text-center">Office Entries</th>
                                                <th class="text-center">Office Hours</th>
                                                <th class="text-center">Office Clients</th>
                                                <th>Visit Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($teamRows as $tr):
                                                $vr  = $tr['visit_logs'] > 0 ? round($tr['v_visited'] / $tr['visit_logs'] * 100) : 0;
                                                $vrC = $vr >= 80 ? '#10b981' : ($vr >= 50 ? '#f59e0b' : '#ef4444');
                                                ?>
                                                <tr>
                                                    <td>
                                                        <div style="font-weight:600;font-size:.85rem;"><?= htmlspecialchars($tr['full_name']) ?></div>
                                                        <div style="font-size:.7rem;color:#9ca3af;"><?= htmlspecialchars($tr['employee_id'] ?? '') ?></div>
                                                    </td>
                                                    <td class="text-center"><?= $tr['visit_logs'] ?></td>
                                                    <td class="text-center" style="color:#15803d;font-weight:600;"><?= $tr['v_visited'] ?></td>
                                                    <td class="text-center" style="color:<?= $tr['v_missed'] > 0 ? '#b91c1c' : '#9ca3af' ?>;font-weight:<?= $tr['v_missed'] > 0 ? '600' : '400' ?>;">
                                                        <?= $tr['v_missed'] ?: '—' ?>
                                                    </td>
                                                    <td class="text-center"><?= $tr['visit_clients'] ?></td>
                                                    <td class="text-center" style="color:#c9a84c;font-weight:700;"><?= number_format((float) $tr['visit_hours'], 1) ?>h</td>
                                                    <td class="text-center"><?= $tr['office_entries'] ?></td>
                                                    <td class="text-center" style="color:#3b82f6;font-weight:700;"><?= number_format((float) $tr['office_hours'], 1) ?>h</td>
                                                    <td class="text-center"><?= $tr['office_clients'] ?></td>
                                                    <td>
                                                        <?php if ($tr['visit_logs'] > 0): ?>
                                                            <div class="perf-bar">
                                                                <div class="perf-bar-track">
                                                                    <div class="perf-bar-fill" style="width:<?= $vr ?>%;background:<?= $vrC ?>;"></div>
                                                                </div>
                                                                <span style="font-size:.78rem;font-weight:700;color:<?= $vrC ?>;min-width:35px;"><?= $vr ?>%</span>
                                                            </div>
                                                        <?php else: ?>
                                                            <span style="font-size:.78rem;color:#9ca3af;">No logs</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div><!-- /tabTeam -->
                <?php endif; ?>

            </div><!-- /tab-content -->

        </div><!-- /padding wrapper -->
        <?php include '../../includes/footer.php'; ?>
    </div><!-- /main-content -->
</div><!-- /app-wrapper -->

<script>
    const chartLabels      = <?= json_encode($chartLabels) ?>;
    const chartVisitHours  = <?= json_encode($chartVisitHours) ?>;
    const chartOfficeHours = <?= json_encode($chartOfficeHours) ?>;
    const chartVisitLogs   = <?= json_encode($chartVisitLogs) ?>;

    new Chart(document.getElementById('trendChart'), {
        type: 'bar',
        data: {
            labels: chartLabels,
            datasets: [
                {
                    label: 'Visit Hours',
                    data: chartVisitHours,
                    backgroundColor: 'rgba(201,168,76,0.7)',
                    borderColor: '#c9a84c',
                    borderWidth: 1.5,
                    borderRadius: 5,
                    yAxisID: 'y'
                },
                {
                    label: 'Office Hours',
                    data: chartOfficeHours,
                    backgroundColor: 'rgba(59,130,246,0.65)',
                    borderColor: '#3b82f6',
                    borderWidth: 1.5,
                    borderRadius: 5,
                    yAxisID: 'y'
                },
                {
                    label: 'Total Visits',
                    data: chartVisitLogs,
                    type: 'line',
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.10)',
                    borderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    tension: 0.3,
                    fill: false,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x:  { ticks: { color: '#4b5563', font: { size: 11 } }, grid: { display: false } },
                y:  {
                    position: 'left',
                    beginAtZero: true,
                    ticks: { color: '#c9a84c', font: { size: 11 }, callback: v => v + 'h' },
                    grid: { color: '#f1f5f9' }
                },
                y1: {
                    position: 'right',
                    beginAtZero: true,
                    ticks: { color: '#10b981', font: { size: 11 } },
                    grid: { drawOnChartArea: false }
                }
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: { font: { size: 11 }, boxWidth: 12, color: '#374151' }
                }
            }
        }
    });
</script>