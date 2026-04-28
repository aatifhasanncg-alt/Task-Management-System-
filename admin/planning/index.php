<?php
/**
 * consulting/index.php — Consulting Dashboard
 * Admin/Executive : sees all dept staff + own performance
 * Staff           : sees only own performance
 *
 * Changes from previous version:
 *  ✓ No-department staff (multi-dept) included in admin scope
 *  ✓ Notification badge from plan_notifications table
 *  ✓ Links to admin/planning/staff_performance.php & client_report.php
 *  ✓ Efficiency capped at 100 with raw figure shown as warning
 *  ✓ Staff table shows dept label (including "No Dept" for multi-dept staff)
 *  ✓ Mark notifications read on page load for current user
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];
$currentRole = $_SESSION['role'] ?? ($user['role_name'] ?? '');
$isAdmin = in_array($currentRole, ['admin', 'executive', 'superadmin']);
$deptId = (int) ($user['department_id'] ?? 0);

$branchId = (int) ($user['branch_id'] ?? 0);
// ── UDA consulting dept detection ─────────────────────────────
$__deptMetaQ = $db->prepare("SELECT dept_code, dept_name FROM departments WHERE id = ?");
$__deptMetaQ->execute([$user['department_id']]);
$__deptMeta = $__deptMetaQ->fetch(PDO::FETCH_ASSOC);
$__primaryCode = $__deptMeta['dept_code'] ?? '';
$__isConsPrimary = ($__primaryCode === 'CON' || stripos($__deptMeta['dept_name'] ?? '', 'consult') !== false);
$__isCoreAdmin = ($__primaryCode === 'CORE');

$__udaQ = $db->prepare("
    SELECT d.id, d.dept_code FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ? AND (d.dept_code = 'CON' OR d.dept_name LIKE '%consult%')
    LIMIT 1
");
$__udaQ->execute([$uid]);
$__udaCons = $__udaQ->fetch(PDO::FETCH_ASSOC);

if ($__isConsPrimary) {
    $deptId = (int) $user['department_id'];
} elseif ($__isCoreAdmin && $__udaCons) {
    $deptId = (int) $__udaCons['id'];
} elseif ($__udaCons) {
    $deptId = (int) $__udaCons['id'];
}
// ── Month / filter ────────────────────────────────────────────
$now = new DateTime();
$month = $_GET['month'] ?? $now->format('Y-m');
$monthDate = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthEnd = $monthDate->format('Y-m-t');
$monthLabel = $monthDate->format('F Y');

$filterClientId = (int) ($_GET['client_id'] ?? 0) ?: null;
$filterFrom = $_GET['from'] ?? $monthStart;
$filterTo = $_GET['to'] ?? $monthEnd;
$filterFrom = max($filterFrom, $monthStart);
$filterTo = min($filterTo, $monthEnd);

// ── Unread notifications (for badge) ─────────────────────────
$notifCount = (int) $db->query("
    SELECT COUNT(*) FROM plan_notifications
    WHERE user_id={$uid} AND is_read=0
")->fetchColumn();

// ── Mark today/tomorrow plan notifications as read ────────────
$db->prepare("
    UPDATE plan_notifications SET is_read=1
    WHERE user_id=? AND is_read=0 AND notify_for <= CURDATE()
")->execute([$uid]);

// ── Scope list (who we show data for) ────────────────────────
// Admin: all staff in same branch with same dept OR no dept (multi-dept staff)
// NEW
if ($isAdmin) {
    $scopeStaff = $db->query("
        SELECT DISTINCT u.id, u.full_name, u.employee_id, u.department_id,
               b.branch_name
        FROM users u
        LEFT JOIN branches b ON b.id = u.branch_id
        WHERE u.is_active = 1
          AND (
              u.id = {$uid}
              OR u.id IN (
                  SELECT u2.id FROM users u2
                  JOIN departments d ON d.id = u2.department_id AND d.dept_code = 'CON'
                  WHERE u2.is_active = 1
                  UNION
                  SELECT uda.user_id FROM user_department_assignments uda
                  JOIN departments d ON d.id = uda.department_id AND d.dept_code = 'CON'
              )
          )
        ORDER BY u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $scopeIds = array_unique(array_merge([$uid], array_column($scopeStaff, 'id')));
} else {
    $scopeIds  = [$uid];
    $scopeStaff = [];
}
$inList = implode(',', array_map('intval', $scopeIds)) ?: '0';

// ── Department name ───────────────────────────────────────────
$deptName = $db->prepare("SELECT dept_name FROM departments WHERE id=?");
$deptName->execute([$deptId]);
$deptName = $deptName->fetchColumn() ?: 'Consulting';

// ════════════════════════════════════════════════════════════════
// A. AGGREGATE KPIs (scoped)
// ════════════════════════════════════════════════════════════════
$kpi = $db->query("
    SELECT
        COUNT(*)                                AS total_logs,
        COALESCE(SUM(duration_hours),0)         AS total_hours,
        SUM(visit_status='visited')             AS visited,
        SUM(visit_status='missed')              AS missed,
        SUM(visit_status='rescheduled')         AS rescheduled,
        COUNT(DISTINCT client_id)               AS unique_clients
    FROM work_logs
    WHERE month_year='{$month}' AND user_id IN ({$inList})
")->fetch(PDO::FETCH_ASSOC);

$pk = $db->query("
    SELECT
        COUNT(*)                    AS total_plans,
        SUM(status='draft')         AS draft,
        SUM(status='submitted')     AS submitted,
        SUM(status='approved')      AS approved,
        SUM(status='rejected')      AS rejected
    FROM work_plans
    WHERE plan_month='{$monthStart}' AND user_id IN ({$inList})
")->fetch(PDO::FETCH_ASSOC);

// ── Planned hours (self) ──────────────────────────────────────
// ── Scope: admin sees full team, staff sees only self ─────────
$perfScope = $isAdmin ? $inList : (string)$uid;

// ── Planned hours ─────────────────────────────────────────────
$plannedHoursSelf = (float) $db->query("
    SELECT COALESCE(SUM(wpe.planned_hours),0)
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id=wpe.plan_id
    WHERE wp.plan_month='{$monthStart}' AND wpe.assigned_to IN ({$perfScope})
")->fetchColumn();

// ── Actual hours ──────────────────────────────────────────────
$actualHoursSelf = (float) $db->query("
    SELECT COALESCE(SUM(duration_hours),0)
    FROM work_logs WHERE month_year='{$month}' AND user_id IN ({$perfScope})
")->fetchColumn();

// ── Match-based efficiency ────────────────────────────────────
$matchRow = $db->query("
    SELECT
        COUNT(DISTINCT wpe.id)                                 AS planned_count,
        COUNT(DISTINCT CASE
            WHEN wl.client_id=wpe.client_id AND wl.log_date=wpe.plan_date
            THEN wpe.id END)                                   AS matched_count,
        COALESCE(SUM(wpe.planned_hours),0)                    AS planned_hrs
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id=wpe.plan_id
    LEFT JOIN work_logs wl
        ON wl.client_id=wpe.client_id
        AND wl.log_date=wpe.plan_date
        AND wl.user_id=wpe.assigned_to
    WHERE wp.plan_month='{$monthStart}' AND wpe.assigned_to IN ({$perfScope})
")->fetch(PDO::FETCH_ASSOC);

$plannedCount = (int) ($matchRow['planned_count'] ?? 0);
$matchedCount = (int) ($matchRow['matched_count'] ?? 0);
$plannedHrs   = (float) ($matchRow['planned_hrs'] ?? 0);
$plannedHrs   = $plannedHrs > 0 ? $plannedHrs : $plannedHoursSelf;
$actualHrs    = $actualHoursSelf;

$visitEffRaw = $plannedCount > 0 ? round(($matchedCount / $plannedCount) * 100, 1) : 0;
$visitEff = min($visitEffRaw, 100);
$hourEffRaw = $plannedHrs > 0 ? round(($actualHrs / $plannedHrs) * 100, 1) : 0;
$hourEff = min($hourEffRaw, 100);
$efficiency = $visitEff;
$effColor = $efficiency >= 80 ? '#10b981' : ($efficiency >= 50 ? '#f59e0b' : '#ef4444');
$hourEffColor = $hourEff >= 80 ? '#10b981' : ($hourEff >= 50 ? '#f59e0b' : '#ef4444');

$totalHours = (float) ($kpi['total_hours'] ?? 0);

// ════════════════════════════════════════════════════════════════
// B. DAILY TREND
// ════════════════════════════════════════════════════════════════
$trendStmt = $db->query("
    SELECT log_date,
           COALESCE(SUM(duration_hours),0) AS hours,
           COUNT(*) AS visits
    FROM work_logs
    WHERE log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
      AND user_id IN ({$inList})
    GROUP BY log_date ORDER BY log_date ASC
    LIMIT 14
");
$trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

// ════════════════════════════════════════════════════════════════
// C. WEEKLY HOURS
// ════════════════════════════════════════════════════════════════
$weeklyStmt = $db->query("
    SELECT week_number,
           COALESCE(SUM(duration_hours),0)         AS actual_hours,
           SUM(visit_status='visited')              AS visited,
           SUM(visit_status='missed')               AS missed,
           COUNT(*)                                 AS total_visits
    FROM work_logs
    WHERE month_year='{$month}' AND user_id IN ({$perfScope})
    GROUP BY week_number ORDER BY week_number ASC
");
$weeklyRows = $weeklyStmt->fetchAll(PDO::FETCH_ASSOC);

$weeklyPlannedStmt = $db->query("
    SELECT COALESCE(SUM(wpe.planned_hours),0) AS planned_hours,
           CEIL(DAY(wpe.plan_date)/7) AS week_num
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id=wpe.plan_id
    WHERE wp.plan_month='{$monthStart}' AND wpe.assigned_to IN ({$perfScope})
    GROUP BY CEIL(DAY(wpe.plan_date)/7)
");
$weeklyPlanned = [];
foreach ($weeklyPlannedStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $weeklyPlanned[(int) $r['week_num']] = (float) $r['planned_hours'];
}

// ════════════════════════════════════════════════════════════════
// D. STAFF-UNDER-ME PERFORMANCE (admin only) — with dept label
// ════════════════════════════════════════════════════════════════
$staffPerf = [];
if ($isAdmin && !empty($scopeIds)) {
    $staffPerf = $db->query("
        SELECT
            u.id, u.full_name, u.employee_id, u.department_id,
            GROUP_CONCAT(DISTINCT d_all.dept_name ORDER BY d_all.dept_name SEPARATOR ', ') AS dept_label,
            COALESCE(SUM(wl.duration_hours),0)         AS hours,
            COUNT(wl.id)                                AS logs,
            SUM(wl.visit_status='visited')              AS visited,
            SUM(wl.visit_status='missed')               AS missed,
            SUM(wl.visit_status='rescheduled')          AS rescheduled,
            COUNT(DISTINCT wl.client_id)                AS clients,
            (SELECT COUNT(DISTINCT wpe2.id)
             FROM work_plan_entries wpe2
             JOIN work_plans wp2 ON wp2.id=wpe2.plan_id
             WHERE wpe2.assigned_to=u.id AND wp2.plan_month='{$monthStart}')
                                                        AS planned_visits,
            (SELECT COUNT(DISTINCT CASE
                WHEN wl2.client_id=wpe2.client_id AND wl2.log_date=wpe2.plan_date
                THEN wpe2.id END)
             FROM work_plan_entries wpe2
             JOIN work_plans wp2 ON wp2.id=wpe2.plan_id
             LEFT JOIN work_logs wl2
                ON wl2.client_id=wpe2.client_id
                AND wl2.log_date=wpe2.plan_date
                AND wl2.user_id=wpe2.assigned_to
             WHERE wpe2.assigned_to=u.id AND wp2.plan_month='{$monthStart}')
                                                        AS matched_visits
        FROM users u
        LEFT JOIN departments d_primary ON d_primary.id = u.department_id
        LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
        LEFT JOIN departments d_all ON (
            d_all.id = u.department_id
            OR d_all.id = uda.department_id
        )
        LEFT JOIN work_logs wl
            ON wl.user_id=u.id AND wl.month_year='{$month}'
        WHERE u.id IN ({$inList}) AND u.is_active=1
        GROUP BY u.id, u.full_name, u.employee_id, u.department_id
        ORDER BY hours DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    // Fetch planned hours per staff for efficiency comparison
    $staffPlannedHours = [];
    if (!empty($scopeIds)) {
        $rows = $db->query("
            SELECT wpe.assigned_to, COALESCE(SUM(wpe.planned_hours),0) AS planned_hours
            FROM work_plan_entries wpe
            JOIN work_plans wp ON wp.id = wpe.plan_id
            WHERE wp.plan_month='{$monthStart}' AND wpe.assigned_to IN ({$inList})
            GROUP BY wpe.assigned_to
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $staffPlannedHours[(int)$r['assigned_to']] = (float)$r['planned_hours'];
        }
    }
}

// ════════════════════════════════════════════════════════════════
// E. CLIENT-WISE PERFORMANCE
// ════════════════════════════════════════════════════════════════
$clientPerf = $db->query("
    SELECT
        c.id                                        AS client_id,
        c.company_name,
        c.company_code,
        COUNT(wl.id)                                AS total_visits,
        SUM(wl.visit_status='visited')              AS visited,
        SUM(wl.visit_status='missed')               AS missed,
        SUM(wl.visit_status='rescheduled')          AS rescheduled,
        COALESCE(SUM(wl.duration_hours),0)          AS actual_hours,
        COUNT(DISTINCT wl.user_id)                  AS staff_count,
        GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ')
                                                    AS staff_names,
        (SELECT COALESCE(SUM(wpe.planned_hours),0)
         FROM work_plan_entries wpe
         JOIN work_plans wp ON wp.id=wpe.plan_id
         WHERE wpe.client_id=c.id
           AND wp.plan_month='{$monthStart}'
           AND wpe.assigned_to IN ({$inList}))      AS planned_hours,
        (SELECT COUNT(DISTINCT wpe.id)
         FROM work_plan_entries wpe
         JOIN work_plans wp ON wp.id=wpe.plan_id
         WHERE wpe.client_id=c.id
           AND wp.plan_month='{$monthStart}'
           AND wpe.assigned_to IN ({$inList}))      AS planned_entries,
        MIN(wl.log_date)                            AS first_visit,
        MAX(wl.log_date)                            AS last_visit
    FROM work_logs wl
    LEFT JOIN companies c ON c.id=wl.client_id
    LEFT JOIN users u ON u.id=wl.user_id
    WHERE wl.month_year='{$month}'
      AND wl.user_id IN ({$inList})
    GROUP BY c.id, c.company_name, c.company_code
    ORDER BY actual_hours DESC
")->fetchAll(PDO::FETCH_ASSOC);

$topClientNames = [];
$topClientHours = [];
$topClientVisits = [];
foreach (array_slice($clientPerf, 0, 8) as $cp) {
    $topClientNames[] = mb_strimwidth($cp['company_name'] ?? '—', 0, 16, '…');
    $topClientHours[] = (float) $cp['actual_hours'];
    $topClientVisits[] = (int) $cp['total_visits'];
}

// ════════════════════════════════════════════════════════════════
// F. MY OWN STATS (filtered)
// ════════════════════════════════════════════════════════════════
$selfClientFilter = $filterClientId ? " AND client_id=" . (int)$filterClientId : "";
$mySelf = $db->query("
    SELECT COALESCE(SUM(duration_hours),0) AS hours,
           COUNT(*) AS logs,
           SUM(visit_status='visited')      AS visited,
           SUM(visit_status='missed')       AS missed,
           SUM(visit_status='rescheduled')  AS rescheduled,
           COUNT(DISTINCT client_id)        AS clients
    FROM work_logs
    WHERE month_year='{$month}'
      AND user_id IN ({$perfScope})
      AND log_date BETWEEN '{$filterFrom}' AND '{$filterTo}'
      {$selfClientFilter}
")->fetch(PDO::FETCH_ASSOC);

// ════════════════════════════════════════════════════════════════
// G. TODAY / TOMORROW PLANS
// ════════════════════════════════════════════════════════════════
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$todayPlans = $db->query("
    SELECT wpe.*, c.company_name, c.company_code
    FROM work_plan_entries wpe
    JOIN companies c ON c.id=wpe.client_id
    JOIN work_plans wp ON wp.id=wpe.plan_id
    WHERE wpe.plan_date='{$today}' AND wpe.assigned_to IN ({$inList})
    ORDER BY wpe.planned_time_in
")->fetchAll(PDO::FETCH_ASSOC);

$tomorrowPlans = $db->query("
    SELECT wpe.*, c.company_name, c.company_code
    FROM work_plan_entries wpe
    JOIN companies c ON c.id=wpe.client_id
    JOIN work_plans wp ON wp.id=wpe.plan_id
    WHERE wpe.plan_date='{$tomorrow}' AND wpe.assigned_to IN ({$inList})
    ORDER BY wpe.planned_time_in
")->fetchAll(PDO::FETCH_ASSOC);

// ════════════════════════════════════════════════════════════════
// H. RECENT LOGS
// ════════════════════════════════════════════════════════════════
$recentSQL = "
    SELECT wl.log_date, wl.day_of_week, wl.time_in, wl.time_out,
           wl.duration_hours, wl.visit_status, wl.work_description,
           c.company_name, c.company_code, u.full_name AS staff_name
    FROM work_logs wl
    JOIN companies c ON c.id=wl.client_id
    JOIN users u     ON u.id=wl.user_id
    WHERE wl.month_year='{$month}'
      AND wl.user_id IN ({$perfScope})
      AND wl.log_date BETWEEN '{$filterFrom}' AND '{$filterTo}'
";
if ($filterClientId)
    $recentSQL .= " AND wl.client_id=" . (int) $filterClientId;
$recentSQL .= " ORDER BY wl.log_date DESC, wl.created_at DESC LIMIT 10";
$recentLogs = $db->query($recentSQL)->fetchAll(PDO::FETCH_ASSOC);

// ── Day-wise self logs ────────────────────────────────────────
$dayWiseLogs = $db->query("
    SELECT wl.log_date, wl.day_of_week, wl.time_in, wl.time_out,
           wl.duration_hours, wl.visit_status, wl.work_description,
           c.company_name, c.company_code
    FROM work_logs wl
    JOIN companies c ON c.id=wl.client_id
    WHERE wl.user_id IN ({$perfScope})
      AND wl.log_date BETWEEN '{$filterFrom}' AND '{$filterTo}'
      " . ($filterClientId ? "AND wl.client_id={$filterClientId}" : "") . "
    ORDER BY wl.log_date DESC, wl.time_in ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Client list for filter ────────────────────────────────────
// NEW
$myClients = $db->query("
    SELECT DISTINCT c.id, c.company_name, c.company_code, c.pan_number
    FROM companies c
    WHERE c.is_active = 1
    ORDER BY c.company_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Helpers ───────────────────────────────────────────────────
function vstBadge(string $s): string
{
    $map = [
        'visited' => ['#ecfdf5', '#10b981', 'fa-check-circle', 'Visited'],
        'missed' => ['#fef2f2', '#ef4444', 'fa-times-circle', 'Missed'],
        'rescheduled' => ['#fffbeb', '#f59e0b', 'fa-redo', 'Rescheduled'],
    ];
    [$bg, $col, $ico, $lbl] = $map[$s] ?? ['#f9fafb', '#9ca3af', 'fa-circle', '—'];
    return "<span style='background:{$bg};color:{$col};padding:.15rem .55rem;border-radius:99px;
            font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;white-space:nowrap;'>
            <i class=\"fas {$ico}\" style=\"font-size:.6rem;\"></i>{$lbl}</span>";
}

function safeEff(float $actual, float $planned): array
{
    if ($planned <= 0)
        return [0, 0, '#9ca3af'];
    $raw = round(($actual / $planned) * 100, 1);
    $capped = min($raw, 100);
    $color = $capped >= 80 ? '#10b981' : ($capped >= 50 ? '#f59e0b' : '#ef4444');
    return [$capped, $raw, $color];
}

$pageTitle = 'Consulting Dashboard';


include '../../includes/header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<div class="app-wrapper">
    <?php include $isAdmin ? '../../includes/sidebar_admin.php' : '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">
            <?= flashHtml() ?>

            <!-- ══ HERO ════════════════════════════════════════════════════ -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge">
                            <i class="fas fa-briefcase"></i> Consulting
                            <?php if ($notifCount > 0): ?>
                                <span style="background:#ef4444;color:#fff;border-radius:99px;padding:.05rem .42rem;
                             font-size:.65rem;font-weight:700;margin-left:.35rem;"><?= $notifCount ?></span>
                            <?php endif; ?>
                        </div>
                        <h4>Consulting Dashboard</h4>
                        <p>
                            <?= htmlspecialchars($user['full_name']) ?> ·
                            <?= $isAdmin ? htmlspecialchars($deptName) . ' — Team View' : 'My Performance' ?>
                            · <?= $monthLabel ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="month" class="form-control form-control-sm" style="width:150px;"
                            value="<?= $month ?>" onchange="location='?month='+this.value">
                        <?php if ($isAdmin): ?>
                            <a href="staff_performance.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-users me-1"></i>Staff Report
                            </a>
                            <a href="client_report.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-building me-1"></i>Client Report
                            </a>
                            <a href="log_list.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm"><i
                                    class="fas fa-history me-1"></i>All Logs</a>
                            <a href="plan_list.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm"><i
                                    class="fas fa-list me-1"></i>All Plans</a>
                            <a href="plan_approvals.php" class="btn btn-outline-secondary btn-sm position-relative">
                                <i class="fas fa-check-circle me-1"></i>Approvals
                                <?php if ($notifCount > 0): ?>
                                    <span style="position:absolute;top:-5px;right:-5px;background:#ef4444;color:#fff;
                             border-radius:50%;width:16px;height:16px;font-size:.6rem;font-weight:700;
                             display:flex;align-items:center;justify-content:center;"><?= $notifCount ?></span>
                                <?php endif; ?>
                            </a>
                        <?php else: ?>
                            <a href="staff/plan_create.php" class="btn-gold btn btn-sm"><i class="fas fa-plus me-1"></i>New
                                Plan</a>
                            <a href="staff/log_create.php" class="btn-gold btn btn-sm"><i class="fas fa-clock me-1"></i>Log
                                Visit</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ══ NO-DEPT STAFF NOTICE (admin only) ══════════════════════ -->
            <?php
            if ($isAdmin):
                $noDeptStaff = array_filter($scopeStaff, fn($s) => empty($s['department_id']));
                if (!empty($noDeptStaff)):
                    ?>
                    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.7rem 1rem;
            margin-bottom:.75rem;display:flex;align-items:center;gap:.65rem;">
                        <i class="fas fa-layer-group" style="color:#f59e0b;flex-shrink:0;"></i>
                        <div style="font-size:.78rem;color:#92400e;">
                            <strong>Multi-dept staff included:</strong>
                            <?= implode(', ', array_map(fn($s) => htmlspecialchars($s['full_name']), $noDeptStaff)) ?>
                        </div>
                    </div>
                <?php endif; endif; ?>

            <!-- ══ FILTERS ════════════════════════════════════════════════ -->
            <div class="card-mis mb-4">
                <div class="card-mis-body" style="padding:.75rem 1rem;">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label-mis">Month</label>
                            <input type="month" id="filterMonth" class="form-control form-control-sm"
                                value="<?= $month ?>" onchange="applyFilters()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-mis">Client</label>
                            <select id="filterClient" class="form-select form-select-sm">
                                <option value="">-- All Clients --</option>
                                <?php foreach ($myClients as $mc): ?>
                                <option value="<?= $mc['id'] ?>"
                                    data-code="<?= htmlspecialchars($mc['company_code'] ?? '') ?>"
                                    data-pan="<?= htmlspecialchars($mc['pan_number'] ?? '') ?>"
                                    <?= $filterClientId == $mc['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($mc['company_name']) ?>
                                    <?= $mc['company_code'] ? ' — '.$mc['company_code'] : '' ?>
                                    <?= $mc['pan_number']   ? ' — '.$mc['pan_number']   : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-mis">Date Range</label>
                            <div class="input-group input-group-sm">
                                <input type="date" id="filterFrom" class="form-control" value="<?= $filterFrom ?>"
                                    onchange="applyFilters()">
                                <input type="date" id="filterTo" class="form-control" value="<?= $filterTo ?>"
                                    onchange="applyFilters()">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <a href="index.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm w-100">
                                <i class="fas fa-times me-1"></i>Clear
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ TODAY / TOMORROW REMINDERS ════════════════════════════ -->
            <?php if (!empty($todayPlans)): ?>
                <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:.8rem 1rem;
            margin-bottom:.75rem;display:flex;align-items:flex-start;gap:.75rem;">
                    <i class="fas fa-calendar-check"
                        style="color:#10b981;font-size:1.1rem;margin-top:.1rem;flex-shrink:0;"></i>
                    <div>
                        <div style="font-size:.8rem;font-weight:700;color:#065f46;margin-bottom:.35rem;">
                            Today's Planned Visits — <?= date('d M Y') ?>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                            <?php foreach ($todayPlans as $p): ?>
                                <span
                                    style="background:#d1fae5;color:#047857;border-radius:99px;padding:.2rem .7rem;font-size:.73rem;font-weight:600;">
                                    <?= htmlspecialchars($p['company_name']) ?>
                                    <?= $p['planned_time_in'] ? ' · ' . date('g:i A', strtotime($p['planned_time_in'])) : '' ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!empty($tomorrowPlans)): ?>
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.8rem 1rem;
            margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.75rem;">
                    <i class="fas fa-calendar" style="color:#f59e0b;font-size:1.1rem;margin-top:.1rem;flex-shrink:0;"></i>
                    <div>
                        <div style="font-size:.8rem;font-weight:700;color:#92400e;margin-bottom:.35rem;">
                            Tomorrow's Planned Visits — <?= date('d M Y', strtotime('+1 day')) ?>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                            <?php foreach ($tomorrowPlans as $p): ?>
                                <span
                                    style="background:#fef3c7;color:#b45309;border-radius:99px;padding:.2rem .7rem;font-size:.73rem;font-weight:600;">
                                    <?= htmlspecialchars($p['company_name']) ?>
                                    <?= $p['planned_time_in'] ? ' · ' . date('g:i A', strtotime($p['planned_time_in'])) : '' ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ══ KPI CARDS ══════════════════════════════════════════════ -->
            <div class="row g-3 mb-4">
                <?php
                $kpiCards = [
                    ['fa-clock', '#3b82f6', '#eff6ff', 'Total Hours', number_format($totalHours, 1) . 'h'],
                    ['fa-check-circle', '#10b981', '#ecfdf5', 'Visited', (int) ($kpi['visited'] ?? 0)],
                    ['fa-times-circle', '#ef4444', '#fef2f2', 'Missed', (int) ($kpi['missed'] ?? 0)],
                    ['fa-redo', '#f59e0b', '#fffbeb', 'Rescheduled', (int) ($kpi['rescheduled'] ?? 0)],
                    ['fa-building', '#8b5cf6', '#f5f3ff', 'Clients Served', (int) ($kpi['unique_clients'] ?? 0)],
                    ['fa-clipboard-list', '#c9a84c', '#fefce8', 'Log Entries', (int) ($kpi['total_logs'] ?? 0)],
                    ['fa-tachometer-alt', $effColor, '#f9fafb', 'Visit Efficiency', $visitEff . '%'],
                    ['fa-calendar-check', '#0284c7', '#e0f2fe', 'Plans Approved', (int) ($pk['approved'] ?? 0) . '/' . (int) ($pk['total_plans'] ?? 0)],
                ];
                foreach ($kpiCards as [$icon, $col, $bg, $lbl, $val]):
                    ?>
                    <div class="col-6 col-md-3">
                        <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;
                padding:1rem 1.1rem;display:flex;align-items:center;gap:.8rem;
                box-shadow:0 1px 3px rgba(0,0,0,.04);">
                            <div style="width:40px;height:40px;border-radius:10px;background:<?= $bg ?>;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas <?= $icon ?>" style="color:<?= $col ?>;font-size:.95rem;"></i>
                            </div>
                            <div>
                                <div style="font-size:1.35rem;font-weight:800;color:#1f2937;line-height:1.1;"><?= $val ?>
                                </div>
                                <div style="font-size:.7rem;color:#9ca3af;margin-top:.1rem;"><?= $lbl ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ══ PLANNED vs ACTUAL BAR ══════════════════════════════════ -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-chart-bar text-warning me-2"></i>Planned vs Actual — <?= $monthLabel ?></h5>
                    <div style="display:flex;gap:14px;font-size:.75rem;">
                        <span>Visit eff: <strong
                                style="color:<?= $effColor ?>"><?= $visitEff ?>%<?= $visitEffRaw > 100 ? ' <span style="color:#f59e0b;font-size:.65rem;">(' . $visitEffRaw . '% raw)</span>' : '' ?></strong></span>
                        <span>Hour eff: <strong
                                style="color:<?= $hourEffColor ?>"><?= $hourEff ?>%<?= $hourEffRaw > 100 ? ' <span style="color:#f59e0b;font-size:.65rem;">(' . $hourEffRaw . '% raw)</span>' : '' ?></strong></span>
                    </div>
                </div>
                <div class="card-mis-body">
                    <?php
                    $maxH = max($plannedHrs, $actualHrs, 1);
                    $pw   = $plannedHrs > 0 ? round(($plannedHrs / $maxH) * 100) : 0;
                    $aw   = $actualHrs  > 0 ? min(100, round(($actualHrs / $maxH) * 100)) : 0;
                    ?>
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                        <span style="font-size:.76rem;color:#9ca3af;min-width:60px;">Planned</span>
                        <div style="flex:1;background:#f3f4f6;border-radius:99px;height:8px;overflow:hidden;">
                            <div
                                style="width:<?= $pw ?>%;background:#3b82f6;height:100%;border-radius:99px;transition:.4s;">
                            </div>
                        </div>
                        <span
                            style="font-size:.8rem;font-weight:700;color:#3b82f6;min-width:50px;text-align:right;"><?= number_format($plannedHrs, 1) ?>h</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                        <span style="font-size:.76rem;color:#9ca3af;min-width:60px;">Actual</span>
                        <div style="flex:1;background:#f3f4f6;border-radius:99px;height:8px;overflow:hidden;">
                            <div
                                style="width:<?= $aw ?>%;background:<?= $hourEffColor ?>;height:100%;border-radius:99px;transition:.4s;">
                            </div>
                        </div>
                        <span
                            style="font-size:.8rem;font-weight:700;color:<?= $hourEffColor ?>;min-width:50px;text-align:right;"><?= number_format($actualHrs, 1) ?>h</span>
                    </div>
                    <?php if ((int) ($kpi['total_logs'] ?? 0) > 0): ?>
                        <div style="font-size:.7rem;color:#9ca3af;margin-bottom:4px;">Visit breakdown</div>
                        <div style="display:flex;border-radius:6px;overflow:hidden;height:8px;">
                            <?php if ($kpi['visited']): ?>
                                <div style="flex:<?= $kpi['visited'] ?>;background:#10b981;"></div><?php endif; ?>
                            <?php if ($kpi['missed']): ?>
                                <div style="flex:<?= $kpi['missed'] ?>;background:#ef4444;"></div><?php endif; ?>
                            <?php if ($kpi['rescheduled']): ?>
                                <div style="flex:<?= $kpi['rescheduled'] ?>;background:#f59e0b;"></div><?php endif; ?>
                        </div>
                        <div style="display:flex;gap:14px;margin-top:5px;font-size:.7rem;">
                            <span style="color:#10b981;">● Visited <?= $kpi['visited'] ?></span>
                            <span style="color:#ef4444;">● Missed <?= $kpi['missed'] ?></span>
                            <span style="color:#f59e0b;">● Rescheduled <?= $kpi['rescheduled'] ?></span>
                        </div>
                    <?php endif; ?>
                    <div
                        style="display:flex;gap:1.5rem;margin-top:.9rem;flex-wrap:wrap;padding-top:.75rem;border-top:1px solid #f3f4f6;">
                        <?php foreach ([
                            ['Planned Visits', $plannedCount, '#3b82f6'],
                            ['Matched', $matchedCount, '#10b981'],
                            ['Unmatched', max(0, $plannedCount - $matchedCount), '#ef4444'],
                            ['Visit Eff.', $visitEff . '%', $effColor],
                            ['Hour Eff.', $hourEff . '%', $hourEffColor],
                        ] as [$lbl, $val, $col]): ?>
                            <div style="font-size:.75rem;display:flex;flex-direction:column;gap:1px;">
                                <span style="color:#9ca3af;"><?= $lbl ?></span>
                                <strong style="color:<?= $col ?>;"><?= $val ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ══ CHARTS ROW ═════════════════════════════════════════════ -->
            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="card-mis h-100">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-chart-line text-warning me-2"></i>Daily Trend — <?= $monthLabel ?></h5>
                        </div>
                        <div class="card-mis-body" style="height:260px;">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3">
                    <div class="card-mis h-100">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-chart-pie text-warning me-2"></i>Visit Status</h5>
                        </div>
                        <div class="card-mis-body d-flex flex-column align-items-center">
                            <div style="height:170px;width:100%;position:relative;">
                                <canvas id="statusChart"></canvas>
                            </div>
                            <div style="width:100%;margin-top:.6rem;">
                                <?php foreach ([
                                    ['Visited', $kpi['visited'] ?? 0, '#10b981'],
                                    ['Missed', $kpi['missed'] ?? 0, '#ef4444'],
                                    ['Rescheduled', $kpi['rescheduled'] ?? 0, '#f59e0b'],
                                ] as [$lbl, $cnt, $col]): ?>
                                    <div style="display:flex;justify-content:space-between;padding:.2rem 0;
                            font-size:.74rem;border-bottom:1px solid #f3f4f6;">
                                        <div style="display:flex;align-items:center;gap:.4rem;">
                                            <div style="width:9px;height:9px;border-radius:50%;background:<?= $col ?>;">
                                            </div>
                                            <span style="color:#374151;"><?= $lbl ?></span>
                                        </div>
                                        <span style="font-weight:700;color:#1f2937;"><?= (int) $cnt ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3">
                    <div class="card-mis h-100">
                        <div class="card-mis-header">
                            <h5><i class="fas fa-layer-group text-warning me-2"></i>Weekly Hours</h5>
                        </div>
                        <div class="card-mis-body" style="height:260px;">
                            <canvas id="weeklyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top clients chart -->
            <?php if (!empty($clientPerf)): ?>
                <div class="card-mis mb-4">
                    <div class="card-mis-header">
                        <h5><i class="fas fa-chart-bar text-warning me-2"></i>Top Clients by Hours — <?= $monthLabel ?></h5>
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            <span style="font-size:.75rem;color:#9ca3af;">Top <?= min(8, count($clientPerf)) ?> of
                                <?= count($clientPerf) ?></span>
                            <?php if ($isAdmin): ?>
                                <a href="client_report.php?month=<?= $month ?>"
                                    style="font-size:.75rem;color:#3b82f6;text-decoration:none;">
                                    <i class="fas fa-external-link-alt me-1"></i>Full Report
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-mis-body" style="height:<?= max(200, min(8, count($clientPerf)) * 42) ?>px;">
                        <canvas id="clientChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ══ STAFF UNDER ME (admin only) ═══════════════════════════ -->
            <?php if ($isAdmin && !empty($staffPerf)): ?>
                <div class="card-mis mb-4" style="border-top:3px solid #c9a84c;">
                    <div class="card-mis-header">
                        <h5><i class="fas fa-users text-warning me-2"></i>Staff Under Me — <?= $monthLabel ?></h5>
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            <span style="font-size:.78rem;color:#9ca3af;"><?= count($staffPerf) ?> member(s)</span>
                            <a href="staff_performance.php?month=<?= $month ?>"
                                style="font-size:.75rem;color:#3b82f6;text-decoration:none;">
                                <i class="fas fa-external-link-alt me-1"></i>Full Report
                            </a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table-mis w-100">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Staff</th>
                                    <th>Dept</th>
                                    <th class="text-center">Hours</th>
                                    <th class="text-center">Logs</th>
                                    <th class="text-center">Clients</th>
                                    <th class="text-center">Visited</th>
                                    <th class="text-center">Missed</th>
                                    <th class="text-center">Rescheduled</th>
                                    <th class="text-center">Planned Visits</th>
                                    <th class="text-center">Planned Hrs</th>
                                    <th class="text-center">Actual Hrs</th>
                                    <th style="min-width:130px;">Hour Efficiency</th>
                                    <th style="min-width:130px;">Visit Efficiency</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($staffPerf as $i => $s):
                                    $sPlanned = (int) ($s['planned_visits'] ?? 0);
                                    $sMatched = (int) ($s['matched_visits'] ?? 0);
                                    $sEffRaw = $sPlanned > 0 ? round(($sMatched / $sPlanned) * 100) : 0;
                                    $sEff = min($sEffRaw, 100);
                                    $sEffCol = $sEff >= 80 ? '#10b981' : ($sEff >= 50 ? '#f59e0b' : '#ef4444');
                                    $isNoDept = empty($s['department_id']);
                                    $initials = strtoupper(
                                        substr($s['full_name'], 0, 1) .
                                        (strpos($s['full_name'], ' ') !== false ? substr($s['full_name'], strpos($s['full_name'], ' ') + 1, 1) : '')
                                    );
                                    ?>
                                    <tr <?= $isNoDept ? 'style="background:#fffdf0;"' : '' ?>>
                                        <td style="color:#9ca3af;font-size:.75rem;"><?= $i + 1 ?></td>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:.6rem;">
                                                <div style="width:32px;height:32px;border-radius:50%;
                                    background:<?= $isNoDept ? '#fef3c7' : '#c9a84c22' ?>;
                                    color:<?= $isNoDept ? '#b45309' : '#c9a84c' ?>;
                                    display:flex;align-items:center;justify-content:center;
                                    font-size:.7rem;font-weight:700;flex-shrink:0;">
                                                    <?= $initials ?>
                                                </div>
                                                <div>
                                                    <div style="font-size:.85rem;font-weight:500;">
                                                        <?= htmlspecialchars($s['full_name']) ?>
                                                        <?php if ($isNoDept): ?>
                                                            <i class="fas fa-layer-group" style="color:#f59e0b;font-size:.6rem;"
                                                                title="Multi-dept"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($s['employee_id']): ?>
                                                        <div style="font-size:.68rem;color:#9ca3af;">
                                                            <?= htmlspecialchars($s['employee_id']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                            $deptLabel = trim($s['dept_label'] ?? '');
                                            $deptList = array_filter(array_map('trim', explode(',', $deptLabel)));
                                            $isNoDept = empty($deptList);
                                            ?>

                                            <?php if ($isNoDept): ?>
                                                <span style="font-size:.72rem;background:#fef3c7;color:#92400e;
                                                    padding:.1rem .4rem;border-radius:6px;">
                                                    No Dept
                                                </span>
                                            <?php else: ?>

                                                <div style="display:flex;flex-wrap:wrap;gap:.3rem;">
                                                    <?php foreach ($deptList as $d): ?>
                                                        <span style="font-size:.72rem;background:#f3f4f6;color:#374151;
                                                                padding:.1rem .45rem;border-radius:6px;">
                                                            <?= htmlspecialchars($d) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>

                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><span
                                                style="font-weight:700;color:#3b82f6;"><?= number_format((float) $s['hours'], 1) ?>h</span>
                                        </td>
                                        <td class="text-center"><?= (int) $s['logs'] ?></td>
                                        <td class="text-center"><?= (int) $s['clients'] ?></td>
                                        <td class="text-center">
                                            <span
                                                style="background:#ecfdf5;color:#10b981;padding:.15rem .5rem;border-radius:99px;font-size:.72rem;font-weight:600;"><?= (int) $s['visited'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int) $s['missed'] > 0): ?>
                                                <span
                                                    style="background:#fef2f2;color:#ef4444;padding:.15rem .5rem;border-radius:99px;font-size:.72rem;font-weight:600;"><?= (int) $s['missed'] ?></span>
                                            <?php else: ?><span style="color:#e5e7eb;font-size:.75rem;">—</span><?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int) $s['rescheduled'] > 0): ?>
                                                <span
                                                    style="background:#fffbeb;color:#f59e0b;padding:.15rem .5rem;border-radius:99px;font-size:.72rem;font-weight:600;"><?= (int) $s['rescheduled'] ?></span>
                                            <?php else: ?><span style="color:#e5e7eb;font-size:.75rem;">—</span><?php endif; ?>
                                        </td>
                                        <td class="text-center" style="font-size:.8rem;">
                                            <span style="color:#3b82f6;font-weight:600;"><?= $sMatched ?></span>
                                            <span style="color:#9ca3af;"> / <?= $sPlanned ?></span>
                                        </td>

                                        <?php
                                            $sPlannedHrs = $staffPlannedHours[(int)$s['id']] ?? 0;
                                            $sActualHrs  = (float)$s['hours'];
                                            [$sHourEff, $sHourEffRaw, $sHourEffCol] = safeEff($sActualHrs, $sPlannedHrs);
                                        ?>
                                        <td class="text-center" style="color:#3b82f6;font-weight:600;font-size:.82rem;">
                                            <?= $sPlannedHrs > 0 ? number_format($sPlannedHrs,1).'h' : '<span style="color:#d1d5db;">—</span>' ?>
                                        </td>
                                        <td class="text-center" style="font-weight:700;font-size:.82rem;
                                            color:<?= $sActualHrs>=4?'#10b981':($sActualHrs>=2?'#f59e0b':'#6b7280') ?>;">
                                            <?= number_format($sActualHrs,1) ?>h
                                        </td>
                                        <td>
                                            <?php if ($sPlannedHrs > 0): ?>
                                                <div style="display:flex;align-items:center;gap:.4rem;">
                                                    <div style="flex:1;background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
                                                        <div style="width:<?= $sHourEff ?>%;background:<?= $sHourEffCol ?>;height:100%;border-radius:99px;"></div>
                                                    </div>
                                                    <span style="font-size:.72rem;font-weight:700;color:<?= $sHourEffCol ?>;min-width:38px;text-align:right;">
                                                        <?= $sHourEff ?>%
                                                    </span>
                                                </div>
                                                <?php if ($sHourEffRaw > 100): ?>
                                                    <div style="font-size:.63rem;color:#f59e0b;">⚠ <?= $sHourEffRaw ?>% raw</div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="font-size:.73rem;color:#9ca3af;">No plan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($sPlanned > 0): ?>
                                                <div style="display:flex;align-items:center;gap:.4rem;">
                                                    <div style="flex:1;background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
                                                        <div style="width:<?= $sEff ?>%;background:<?= $sEffCol ?>;height:100%;border-radius:99px;"></div>
                                                    </div>
                                                    <span style="font-size:.72rem;font-weight:700;color:<?= $sEffCol ?>;min-width:38px;text-align:right;">
                                                        <?= $sEff ?>%
                                                    </span>
                                                </div>
                                                <?php if ($sEffRaw > 100): ?>
                                                    <div style="font-size:.63rem;color:#f59e0b;">⚠ <?= $sEffRaw ?>% raw</div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="font-size:.73rem;color:#9ca3af;">No plan</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f9fafb;font-weight:700;font-size:.8rem;">
                                    <td colspan="3" style="padding:10px 14px;color:#374151;">
                                        <i class="fas fa-calculator me-1 text-warning"></i>TOTAL
                                    </td>
                                    <td class="text-center" style="color:#c9a84c;">
                                        <?= number_format(array_sum(array_column($staffPerf,'hours')),1) ?>h
                                    </td>
                                    <td class="text-center"><?= array_sum(array_column($staffPerf,'logs')) ?></td>
                                    <td class="text-center"><?= array_sum(array_column($staffPerf,'clients')) ?></td>
                                    <td class="text-center" style="color:#10b981;"><?= array_sum(array_column($staffPerf,'visited')) ?></td>
                                    <td class="text-center" style="color:#ef4444;"><?= array_sum(array_column($staffPerf,'missed')) ?></td>
                                    <td class="text-center" style="color:#f59e0b;"><?= array_sum(array_column($staffPerf,'rescheduled')) ?></td>
                                    <td class="text-center" style="color:#9ca3af;">
                                        <?= array_sum(array_column($staffPerf,'matched_visits')) ?>
                                        / <?= array_sum(array_column($staffPerf,'planned_visits')) ?>
                                    </td>
                                    <td class="text-center" style="color:#3b82f6;">
                                        <?= number_format(array_sum($staffPlannedHours),1) ?>h
                                    </td>
                                    <td class="text-center" style="color:#c9a84c;">
                                        <?= number_format(array_sum(array_column($staffPerf,'hours')),1) ?>h
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ══ CLIENT-WISE PERFORMANCE ════════════════════════════════ -->
            <?php if (!empty($clientPerf)): ?>
                <div class="card-mis mb-4" style="border-top:3px solid #8b5cf6;">
                    <div class="card-mis-header">
                        <h5><i class="fas fa-building text-warning me-2"></i>Client-wise Performance — <?= $monthLabel ?>
                        </h5>
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            <span style="font-size:.78rem;color:#9ca3af;"><?= count($clientPerf) ?> client(s)</span>
                            <?php if ($isAdmin): ?>
                                <a href="client_report.php?month=<?= $month ?>"
                                    style="font-size:.75rem;color:#3b82f6;text-decoration:none;">
                                    <i class="fas fa-external-link-alt me-1"></i>Full Report
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table-mis w-100">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Client</th>
                                    <th class="text-center">Staff</th>
                                    <th class="text-center">Visits</th>
                                    <th class="text-center">Visited</th>
                                    <th class="text-center">Missed</th>
                                    <th class="text-center">Rescheduled</th>
                                    <th class="text-center">Planned Hrs</th>
                                    <th class="text-center">Actual Hrs</th>
                                    <th style="min-width:150px;">Hour Efficiency</th>
                                    <th>First · Last Visit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clientPerf as $i => $cp):
                                    [$cEff, $cEffRaw, $cEffCol] = safeEff((float) $cp['actual_hours'], (float) $cp['planned_hours']);
                                    ?>
                                    <tr>
                                        <td style="color:#9ca3af;font-size:.75rem;"><?= $i + 1 ?></td>
                                        <td>
                                            <div style="font-weight:600;font-size:.85rem;">
                                                <?= htmlspecialchars($cp['company_name'] ?? '—') ?></div>
                                            <div style="font-size:.7rem;color:#9ca3af;">
                                                <?= htmlspecialchars($cp['company_code'] ?? '') ?></div>
                                        </td>
                                        <td class="text-center">
                                            <span
                                                style="font-size:.78rem;font-weight:600;color:#8b5cf6;"><?= (int) $cp['staff_count'] ?></span>
                                            <?php if ($cp['staff_names']): ?>
                                                <div style="font-size:.65rem;color:#9ca3af;max-width:100px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                                    title="<?= htmlspecialchars($cp['staff_names']) ?>">
                                                    <?= htmlspecialchars(mb_strimwidth($cp['staff_names'], 0, 18, '…')) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><strong><?= $cp['total_visits'] ?></strong></td>
                                        <td class="text-center">
                                            <span
                                                style="background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:20px;font-size:.74rem;font-weight:600;"><?= (int) $cp['visited'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int) $cp['missed'] > 0): ?>
                                                <span
                                                    style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:20px;font-size:.74rem;font-weight:600;"><?= (int) $cp['missed'] ?></span>
                                            <?php else: ?><span style="color:#d1d5db;font-size:.77rem;">—</span><?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int) $cp['rescheduled'] > 0): ?>
                                                <span
                                                    style="background:#fffbeb;color:#b45309;padding:2px 8px;border-radius:20px;font-size:.74rem;font-weight:600;"><?= (int) $cp['rescheduled'] ?></span>
                                            <?php else: ?><span style="color:#d1d5db;font-size:.77rem;">—</span><?php endif; ?>
                                        </td>
                                        <td class="text-center" style="color:#3b82f6;font-weight:600;">
                                            <?= number_format((float) $cp['planned_hours'], 1) ?>h</td>
                                        <td class="text-center">
                                            <strong
                                                style="color:<?= (float) $cp['actual_hours'] >= 4 ? '#10b981' : ((float) $cp['actual_hours'] >= 2 ? '#f59e0b' : '#6b7280') ?>;">
                                                <?= number_format((float) $cp['actual_hours'], 1) ?>h
                                            </strong>
                                        </td>
                                        <td>
                                            <?php if ((float) $cp['planned_hours'] > 0): ?>
                                                <div style="display:flex;align-items:center;gap:.4rem;">
                                                    <div
                                                        style="flex:1;background:#f1f5f9;border-radius:99px;height:5px;overflow:hidden;">
                                                        <div
                                                            style="width:<?= $cEff ?>%;height:100%;background:<?= $cEffCol ?>;border-radius:99px;">
                                                        </div>
                                                    </div>
                                                    <span
                                                        style="font-size:.74rem;font-weight:700;color:<?= $cEffCol ?>;min-width:36px;text-align:right;"><?= $cEff ?>%</span>
                                                </div>
                                                <?php if ($cEffRaw > 100): ?>
                                                    <div style="font-size:.62rem;color:#f59e0b;margin-top:2px;">⚠ <?= $cEffRaw ?>% raw
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="font-size:.74rem;color:#9ca3af;">No plan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:.75rem;color:#6b7280;white-space:nowrap;">
                                            <?= $cp['first_visit'] ? date('d M', strtotime($cp['first_visit'])) : '—' ?>
                                            <?php if ($cp['first_visit'] && $cp['last_visit'] && $cp['first_visit'] !== $cp['last_visit']): ?>
                                                <span style="color:#d1d5db;"> →
                                                </span><?= date('d M', strtotime($cp['last_visit'])) ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f9fafb;font-weight:700;">
                                    <td colspan="3" style="padding:10px 14px;font-size:.82rem;color:#374151;">
                                        <i class="fas fa-calculator me-1 text-warning"></i>TOTAL
                                    </td>
                                    <td class="text-center"><?= (int) ($kpi['total_logs'] ?? 0) ?></td>
                                    <td class="text-center" style="color:#15803d;"><?= (int) ($kpi['visited'] ?? 0) ?></td>
                                    <td class="text-center" style="color:#b91c1c;"><?= (int) ($kpi['missed'] ?? 0) ?></td>
                                    <td class="text-center" style="color:#b45309;"><?= (int) ($kpi['rescheduled'] ?? 0) ?>
                                    </td>
                                    <td class="text-center" style="color:#3b82f6;">
                                        <?= number_format(array_sum(array_column($clientPerf, 'planned_hours')), 1) ?>h</td>
                                    <td class="text-center" style="color:#c9a84c;"><?= number_format($totalHours, 1) ?>h</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ══ MY OWN PERFORMANCE ═════════════════════════════════════ -->
            <div class="card-mis mb-4" style="border-left:4px solid #c9a84c;">
                <div class="card-mis-header">
                    <h5><i class="fas fa-user text-warning me-2"></i>
                        <?= $isAdmin ? 'Team Performance' : 'My Performance' ?> — <?= $monthLabel ?>
                    </h5>
                </div>
                <div class="card-mis-body">
                    <div class="row g-3">
                        <?php
                        $myTotal = (int) ($mySelf['visited'] ?? 0) + (int) ($mySelf['missed'] ?? 0) + (int) ($mySelf['rescheduled'] ?? 0);
                        $myPct = $myTotal > 0 ? min(100, round(($mySelf['visited'] / $myTotal) * 100)) : 0;
                        $myCol = $myPct >= 80 ? '#10b981' : ($myPct >= 50 ? '#f59e0b' : '#ef4444');
                        foreach ([
                            ['fa-clock', '#3b82f6', number_format((float) ($mySelf['hours'] ?? 0), 1) . 'h', 'Hours Logged'],
                            ['fa-list', '#c9a84c', (int) ($mySelf['logs'] ?? 0), 'Log Entries'],
                            ['fa-building', '#8b5cf6', (int) ($mySelf['clients'] ?? 0), 'Clients'],
                            ['fa-check-circle', '#10b981', (int) ($mySelf['visited'] ?? 0), 'Visited'],
                            ['fa-times-circle', '#ef4444', (int) ($mySelf['missed'] ?? 0), 'Missed'],
                            ['fa-redo', '#f59e0b', (int) ($mySelf['rescheduled'] ?? 0), 'Rescheduled'],
                        ] as [$ico, $col, $val, $lbl]):
                            ?>
                            <div class="col-6 col-md-2">
                                <div style="text-align:center;background:#f9fafb;border-radius:10px;padding:.85rem .5rem;">
                                    <i class="fas <?= $ico ?>"
                                        style="color:<?= $col ?>;font-size:1rem;margin-bottom:.3rem;display:block;"></i>
                                    <div style="font-size:1.2rem;font-weight:800;color:#1f2937;"><?= $val ?></div>
                                    <div style="font-size:.68rem;color:#9ca3af;"><?= $lbl ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="col-12">
                            <div style="display:flex;align-items:center;gap:.5rem;margin-top:.25rem;">
                                <span style="font-size:.75rem;color:#9ca3af;min-width:80px;">Visit rate</span>
                                <div style="flex:1;background:#f3f4f6;border-radius:99px;height:7px;overflow:hidden;">
                                    <div
                                        style="width:<?= $myPct ?>%;background:<?= $myCol ?>;height:100%;border-radius:99px;transition:.4s;">
                                    </div>
                                </div>
                                <span
                                    style="font-size:.78rem;font-weight:700;color:<?= $myCol ?>;min-width:38px;text-align:right;"><?= $myPct ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ DAY-WISE SELF SUMMARY ══════════════════════════════════ -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-calendar-day text-warning me-2"></i>My Day-wise Log — <?= $monthLabel ?></h5>
                    <span style="font-size:.75rem;color:#9ca3af;"><?= count($dayWiseLogs) ?> entries</span>
                </div>
                <div class="table-responsive">
                    <table class="table-mis w-100">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Day</th>
                                <th>Client</th>
                                <th class="text-center">Time In</th>
                                <th class="text-center">Time Out</th>
                                <th class="text-center">Hours</th>
                                <th>Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dayWiseLogs)): ?>
                                <tr>
                                    <td colspan="8" style="text-align:center;padding:24px;color:#9ca3af;font-size:.83rem;">
                                        <i class="fas fa-calendar-times"
                                            style="display:block;font-size:1.5rem;margin-bottom:6px;opacity:.4;"></i>
                                        No logs for selected range
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($dayWiseLogs as $dr): ?>
                                <tr>
                                    <td style="font-size:.83rem;font-weight:500;white-space:nowrap;">
                                        <?= date('d M Y', strtotime($dr['log_date'])) ?></td>
                                    <td style="font-size:.75rem;color:#9ca3af;">
                                        <?= htmlspecialchars($dr['day_of_week'] ?? '') ?></td>
                                    <td>
                                        <div style="font-size:.83rem;font-weight:500;">
                                            <?= htmlspecialchars($dr['company_name']) ?></div>
                                        <div style="font-size:.68rem;color:#9ca3af;">
                                            <?= htmlspecialchars($dr['company_code'] ?? '') ?></div>
                                    </td>
                                    <td class="text-center" style="font-size:.78rem;">
                                        <?= $dr['time_in'] ? date('g:i A', strtotime($dr['time_in'])) : '—' ?></td>
                                    <td class="text-center" style="font-size:.78rem;">
                                        <?= $dr['time_out'] ? date('g:i A', strtotime($dr['time_out'])) : '—' ?></td>
                                    <td class="text-center">
                                        <span
                                            style="font-weight:700;color:<?= (float) $dr['duration_hours'] >= 4 ? '#10b981' : ((float) $dr['duration_hours'] >= 2 ? '#f59e0b' : '#ef4444') ?>;">
                                            <?= number_format((float) $dr['duration_hours'], 1) ?>h
                                        </span>
                                    </td>
                                    <td><?= vstBadge($dr['visit_status'] ?? '') ?></td>
                                    <td
                                        style="font-size:.73rem;color:#6b7280;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= htmlspecialchars(mb_strimwidth($dr['work_description'] ?? '—', 0, 45, '…')) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ══ PLANS STATUS ═══════════════════════════════════════════ -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-calendar-alt text-warning me-2"></i>Plans Status — <?= $monthLabel ?></h5>
                    <a href="<?= $isAdmin ? 'plan_list' : 'staff/plan_list' ?>.php?month=<?= $month ?>"
                        class="btn btn-outline-secondary btn-sm">All Plans</a>
                </div>
                <div class="card-mis-body">
                    <div class="row g-3">
                        <?php foreach ([
                            ['Draft', $pk['draft'] ?? 0, '#9ca3af', '#f9fafb'],
                            ['Submitted', $pk['submitted'] ?? 0, '#3b82f6', '#eff6ff'],
                            ['Approved', $pk['approved'] ?? 0, '#10b981', '#ecfdf5'],
                            ['Rejected', $pk['rejected'] ?? 0, '#ef4444', '#fef2f2'],
                        ] as [$lbl, $cnt, $col, $bg]): ?>
                            <div class="col-6 col-md-3">
                                <div
                                    style="text-align:center;background:<?= $bg ?>;border-radius:10px;padding:1rem .5rem;border:1px solid <?= $col ?>22;">
                                    <div style="font-size:1.6rem;font-weight:800;color:<?= $col ?>;"><?= (int) $cnt ?></div>
                                    <div style="font-size:.75rem;color:<?= $col ?>;font-weight:600;"><?= $lbl ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ══ RECENT LOGS ════════════════════════════════════════════ -->
            <div class="card-mis mb-4">
                <div class="card-mis-header">
                    <h5><i class="fas fa-history text-warning me-2"></i>Recent Logs</h5>
                    <a href="<?= $isAdmin ? 'log_list' : 'staff/log_list' ?>.php?month=<?= $month ?>"
                        class="btn btn-outline-secondary btn-sm">All Logs</a>
                </div>
                <div class="table-responsive">
                    <table class="table-mis w-100">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Client</th>
                                <?php if ($isAdmin): ?>
                                    <th>Staff</th><?php endif; ?>
                                <th class="text-center">Time In</th>
                                <th class="text-center">Hours</th>
                                <th>Status</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentLogs)): ?>
                                <tr>
                                    <td colspan="7" class="empty-state"><i class="fas fa-history"></i> No logs yet</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($recentLogs as $l): ?>
                                <tr>
                                    <td>
                                        <div style="font-size:.83rem;font-weight:500;white-space:nowrap;">
                                            <?= date('d M Y', strtotime($l['log_date'])) ?></div>
                                        <div style="font-size:.68rem;color:#9ca3af;">
                                            <?= htmlspecialchars($l['day_of_week'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <div style="font-size:.83rem;font-weight:500;">
                                            <?= htmlspecialchars(mb_strimwidth($l['company_name'] ?? '—', 0, 20, '…')) ?></div>
                                        <div style="font-size:.68rem;color:#9ca3af;">
                                            <?= htmlspecialchars($l['company_code'] ?? '') ?></div>
                                    </td>
                                    <?php if ($isAdmin): ?>
                                        <td style="font-size:.78rem;">
                                            <?= htmlspecialchars(explode(' ', $l['staff_name'])[0] ?? '—') ?></td>
                                    <?php endif; ?>
                                    <td class="text-center" style="font-size:.78rem;color:#6b7280;">
                                        <?= $l['time_in'] ? date('g:i A', strtotime($l['time_in'])) : '—' ?>
                                    </td>
                                    <td class="text-center">
                                        <span
                                            style="font-weight:700;color:<?= (float) $l['duration_hours'] >= 4 ? '#10b981' : ((float) $l['duration_hours'] >= 2 ? '#f59e0b' : '#ef4444') ?>;">
                                            <?= number_format((float) $l['duration_hours'], 1) ?>h
                                        </span>
                                    </td>
                                    <td><?= vstBadge($l['visit_status'] ?? '') ?></td>
                                    <td
                                        style="font-size:.75rem;color:#6b7280;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= htmlspecialchars(mb_strimwidth($l['work_description'] ?? '—', 0, 45, '…')) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /padding -->
        <?php include '../../includes/footer.php'; ?>
    </div>
</div>

<script>
    new Chart(document.getElementById('trendChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_values($trendRows)) ?>.map(r => {
                const d = new Date(r.log_date + 'T00:00:00');
                return d.toLocaleDateString('en', { month: 'short', day: 'numeric' });
            }),
            datasets: [
                {
                    label: 'Hours', data: <?= json_encode(array_values($trendRows)) ?>.map(r => parseFloat(r.hours) || 0),
                    backgroundColor: 'rgba(201,168,76,.25)', borderColor: '#c9a84c', borderWidth: 2, borderRadius: 4, yAxisID: 'y'
                },
                {
                    label: 'Visits', type: 'line', data: <?= json_encode(array_values($trendRows)) ?>.map(r => parseInt(r.visits) || 0),
                    borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.08)', pointBackgroundColor: '#3b82f6',
                    pointRadius: 4, tension: .4, fill: true, yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: { size: 11 } } } },
            scales: {
                y: { position: 'left', beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, callback: v => v + 'h' } },
                y1: { position: 'right', beginAtZero: true, grid: { display: false }, ticks: { font: { size: 10 }, stepSize: 1 } },
                x: { grid: { display: false }, ticks: { font: { size: 10 } } }
            }
        }
    });

    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: ['Visited', 'Missed', 'Rescheduled'],
            datasets: [{
                data: [<?= (int) ($kpi['visited'] ?? 0) ?>, <?= (int) ($kpi['missed'] ?? 0) ?>, <?= (int) ($kpi['rescheduled'] ?? 0) ?>],
                backgroundColor: ['#10b981', '#ef4444', '#f59e0b'], borderWidth: 3, borderColor: '#fff', hoverOffset: 6
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '68%',
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}` } } }
        },
        plugins: [{
            id: 'centre', afterDraw(chart) {
                const { ctx, chartArea: { top, bottom, left, right } } = chart;
                const cx = (left + right) / 2, cy = (top + bottom) / 2;
                ctx.save(); ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                ctx.fillStyle = '#1f2937'; ctx.font = 'bold 18px sans-serif';
                ctx.fillText(<?= (int) ($kpi['total_logs'] ?? 0) ?>, cx, cy - 6);
                ctx.fillStyle = '#9ca3af'; ctx.font = '10px sans-serif';
                ctx.fillText('total', cx, cy + 9); ctx.restore();
            }
        }]
    });

    const weeklyRows = <?= json_encode(array_values($weeklyRows)) ?>;
    const weeklyPlanned = <?= json_encode($weeklyPlanned) ?>;
    new Chart(document.getElementById('weeklyChart'), {
        type: 'bar',
        data: {
            labels: weeklyRows.map(r => 'Week ' + r.week_number),
            datasets: [
                {
                    label: 'Planned', data: weeklyRows.map(r => parseFloat(weeklyPlanned[r.week_number] ?? 0)),
                    backgroundColor: 'rgba(59,130,246,.25)', borderColor: '#3b82f6', borderWidth: 1.5, borderRadius: 4
                },
                {
                    label: 'Actual', data: weeklyRows.map(r => parseFloat(r.actual_hours) || 0),
                    backgroundColor: 'rgba(201,168,76,.5)', borderColor: '#c9a84c', borderWidth: 1.5, borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: { size: 10 } } } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 }, callback: v => v + 'h' } }
            }
        }
    });

    <?php if (!empty($topClientNames)): ?>
        new Chart(document.getElementById('clientChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($topClientNames) ?>,
                datasets: [
                    {
                        label: 'Actual Hours', data: <?= json_encode($topClientHours) ?>,
                        backgroundColor: 'rgba(139,92,246,.65)', borderColor: '#8b5cf6', borderWidth: 1.5, borderRadius: 5
                    },
                    {
                        label: 'Visits', data: <?= json_encode($topClientVisits) ?>,
                        backgroundColor: 'rgba(201,168,76,.4)', borderColor: '#c9a84c', borderWidth: 1.5, borderRadius: 5
                    }
                ]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: { size: 11 } } } },
                scales: {
                    x: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 } } },
                    y: { grid: { display: false }, ticks: { font: { size: 11 } } }
                }
            }
        });
    <?php endif; ?>

const clientTsIdx = new TomSelect('#filterClient', {
    placeholder: 'Search by name, code or PAN…',
    allowEmptyOption: true,
    maxOptions: 500,
    searchField: ['text'],
    score: function(search) {
        const s = search.toLowerCase();
        return function(item) {
            const text = (item.text  || '').toLowerCase();
            const code = (item.$option?.dataset?.code || '').toLowerCase();
            const pan  = (item.$option?.dataset?.pan  || '').toLowerCase();
            return (text.includes(s) || code.includes(s) || pan.includes(s)) ? 1 : 0;
        };
    },
    render: {
        option: function(data, escape) {
            const code = data.$option?.dataset?.code || '';
            const pan  = data.$option?.dataset?.pan  || '';
            const name = escape(data.text.split(' — ')[0]);
            return `<div style="padding:3px 2px;">
                <div style="font-weight:600;font-size:.83rem;">${name}</div>
                <div style="font-size:.7rem;color:#9ca3af;display:flex;gap:8px;margin-top:1px;">
                    ${code ? `<span><i class="fas fa-tag" style="font-size:.6rem;"></i> ${escape(code)}</span>` : ''}
                    ${pan  ? `<span><i class="fas fa-id-card" style="font-size:.6rem;"></i> PAN: ${escape(pan)}</span>` : ''}
                </div>
            </div>`;
        },
        item: function(data, escape) {
            const pan  = data.$option?.dataset?.pan || '';
            const name = escape(data.text.split(' — ')[0]);
            return pan
                ? `<div>${name} <span style="font-size:.7rem;color:#9ca3af;">(PAN: ${escape(pan)})</span></div>`
                : `<div>${name}</div>`;
        }
    },
    onChange: function() { applyFilters(); }
});

function applyFilters() {
    const month  = document.getElementById('filterMonth').value;
    const client = clientTsIdx.getValue();
    const from   = document.getElementById('filterFrom').value;
    const to     = document.getElementById('filterTo').value;
    const p = new URLSearchParams({ month });
    if (client) p.set('client_id', client);
    if (from)   p.set('from', from);
    if (to)     p.set('to',   to);
    location.href = 'index.php?' + p.toString();
}
</script>