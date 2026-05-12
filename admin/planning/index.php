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

$managedStmt = $db->prepare("
    SELECT DISTINCT u.id
    FROM users u
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    WHERE u.is_active = 1
      AND (u.managed_by = ? OR uda.managed_by = ?)
");
$managedStmt->execute([$uid, $uid]);
$managedIds = array_map('intval', array_column($managedStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
$selfAndManaged = array_unique(array_merge([$uid], $managedIds));
$selfManagedList = implode(',', $selfAndManaged) ?: (string) $uid;

$today = (new DateTime())->format('Y-m-d');
$tomorrow = (new DateTime('+1 day'))->format('Y-m-d');

$fullScopeIds = array_unique(array_merge([$uid], $managedIds));
$fullScopeList = implode(',', $fullScopeIds) ?: (string) $uid;

$notifCount = (int) $db->query("
    SELECT COUNT(*) FROM plan_notifications
    WHERE user_id={$uid} AND is_read=0
")->fetchColumn();

$db->prepare("
    UPDATE plan_notifications SET is_read=1
    WHERE user_id=? AND is_read=0 AND notify_for <= CURDATE()
")->execute([$uid]);

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
    $scopeIds = [$uid];
    $scopeStaff = [];
}
$inList = implode(',', array_map('intval', $scopeIds)) ?: '0';

$deptName = $db->prepare("SELECT dept_name FROM departments WHERE id=?");
$deptName->execute([$deptId]);
$deptName = $deptName->fetchColumn() ?: 'Consulting';

$kpi = $db->query("
    SELECT
        COUNT(*)                                AS total_logs,
        COALESCE(SUM(duration_hours),0)         AS total_hours,
        SUM(visit_status='visited')             AS visited,
        SUM(visit_status='missed')              AS missed,
        SUM(visit_status='rescheduled')         AS rescheduled,
        COUNT(DISTINCT client_id)               AS unique_clients
    FROM work_logs
    WHERE month_year='{$month}' AND user_id IN ({$fullScopeList})
")->fetch(PDO::FETCH_ASSOC);

$pk = $db->query("
    SELECT
        COUNT(*)                    AS total_plans,
        SUM(status='draft')         AS draft,
        SUM(status='submitted')     AS submitted,
        SUM(status='approved')      AS approved,
        SUM(status='rejected')      AS rejected
    FROM work_plans
    WHERE plan_month='{$monthStart}' AND user_id IN ({$fullScopeList})
")->fetch(PDO::FETCH_ASSOC);

$perfScope = $fullScopeList;

$officeKpi = $db->query("
    SELECT
        COUNT(*)                                                      AS total_logs,
        COALESCE(SUM(TIMESTAMPDIFF(MINUTE,time_in,time_out)/60.0),0) AS total_hours,
        SUM(status='completed')                                       AS completed,
        SUM(status='wip')                                             AS wip,
        COUNT(DISTINCT client_id)                                     AS unique_clients
    FROM office_work_logs
    WHERE log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
      AND user_id IN ({$fullScopeList})
")->fetch(PDO::FETCH_ASSOC);
$officeHours = round((float) ($officeKpi['total_hours'] ?? 0), 1);

$plannedHoursSelf = (float) $db->query("
    SELECT COALESCE(SUM(wpe.planned_hours),0)
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id=wpe.plan_id
    WHERE wp.plan_month='{$monthStart}' AND wpe.assigned_to IN ({$perfScope})
")->fetchColumn();

$actualHoursSelf = (float) $db->query("
    SELECT COALESCE(SUM(duration_hours),0)
    FROM work_logs WHERE month_year='{$month}' AND user_id IN ({$perfScope})
")->fetchColumn();

// CHANGE: matchRow query — remove any plan_entry_id references, use supervisor_id join
$matchRow = $db->query("
    SELECT
        COUNT(DISTINCT wpe.id)                                 AS planned_count,
        COUNT(DISTINCT CASE
            WHEN wl.client_id = wpe.client_id
             AND wl.log_date  = wpe.plan_date
             AND wl.user_id   = wpe.assigned_to
            THEN wpe.id END)                                   AS matched_count,
        COALESCE(SUM(wpe.planned_hours), 0)                   AS planned_hrs
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    LEFT JOIN work_logs wl
        ON wl.client_id   = wpe.client_id
       AND wl.log_date    = wpe.plan_date
       AND wl.user_id     = wpe.assigned_to
    WHERE wp.plan_month = '{$monthStart}'
      AND wpe.assigned_to IN ({$perfScope})
")->fetch(PDO::FETCH_ASSOC);

$plannedCount = (int) ($matchRow['planned_count'] ?? 0);
$matchedCount = (int) ($matchRow['matched_count'] ?? 0);
$plannedHrs = (float) ($matchRow['planned_hrs'] ?? 0);
$plannedHrs = $plannedHrs > 0 ? $plannedHrs : $plannedHoursSelf;
$actualHrs = $actualHoursSelf;

$visitEffRaw = $plannedCount > 0 ? round(($matchedCount / $plannedCount) * 100, 1) : 0;
$visitEff = min($visitEffRaw, 100);
$hourEffRaw = $plannedHrs > 0 ? round(($actualHrs / $plannedHrs) * 100, 1) : 0;
$hourEff = min($hourEffRaw, 100);
$efficiency = $visitEff;
$effColor = $efficiency >= 80 ? '#1D9E75' : ($efficiency >= 50 ? '#BA7517' : '#E24B4A');
$hourEffColor = $hourEff >= 80 ? '#1D9E75' : ($hourEff >= 50 ? '#BA7517' : '#E24B4A');

$totalHours = (float) ($kpi['total_hours'] ?? 0);
$visitedCnt = (int) ($kpi['visited'] ?? 0);
$missedCnt = (int) ($kpi['missed'] ?? 0);
$rescheduledCnt = (int) ($kpi['rescheduled'] ?? 0);

$pendingApprovals = (int) $db->query("
    SELECT COUNT(*) FROM work_plans
    WHERE user_id IN ({$fullScopeList}) AND status='submitted'
")->fetchColumn();

$rawEff = $plannedHrs > 0 ? round(($actualHrs / $plannedHrs) * 100) : 0;
$teamEff = min($rawEff, 100);
$teamEffCol = $teamEff >= 80 ? '#1D9E75' : ($teamEff >= 50 ? '#BA7517' : '#E24B4A');

$officeBreakdown = $db->query("
    SELECT
        COUNT(*)                                                      AS total_logs,
        COALESCE(SUM(TIMESTAMPDIFF(MINUTE,time_in,time_out)/60.0),0) AS total_hours,
        SUM(status='completed')                                       AS completed,
        SUM(status='wip')                                             AS wip,
        SUM(status='holding')                                         AS holding,
        SUM(status='not_started')                                     AS not_started,
        COUNT(DISTINCT client_id)                                     AS unique_clients
    FROM office_work_logs
    WHERE log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
      AND user_id IN ({$fullScopeList})
")->fetch(PDO::FETCH_ASSOC);

$offLogCount = (int) ($officeBreakdown['total_logs'] ?? 0);
$offHours = round((float) ($officeBreakdown['total_hours'] ?? 0), 1);
$offCompleted = (int) ($officeBreakdown['completed'] ?? 0);
$offWip = (int) ($officeBreakdown['wip'] ?? 0);
$offHolding = (int) ($officeBreakdown['holding'] ?? 0);
$offNotStart = (int) ($officeBreakdown['not_started'] ?? 0);
$offClients = (int) ($officeBreakdown['unique_clients'] ?? 0);
$offCompRate = $offLogCount > 0 ? round($offCompleted / $offLogCount * 100) : 0;

$recentOfficeLogs = $db->query("
    SELECT owl.*, u.full_name, u.employee_id AS emp_id,
           c.company_name, c.company_code, d.dept_name
    FROM office_work_logs owl
    JOIN users u       ON u.id  = owl.user_id
    JOIN companies c   ON c.id  = owl.client_id
    JOIN departments d ON d.id  = owl.department_id
    WHERE owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
      AND owl.user_id IN ({$fullScopeList})
    ORDER BY owl.log_date DESC, owl.time_in DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

$trendField = $db->query("
    SELECT month_year,
           COALESCE(SUM(duration_hours),0)   AS hours,
           SUM(visit_status='visited')        AS visited,
           SUM(visit_status='missed')         AS missed
    FROM work_logs
    WHERE user_id IN ({$fullScopeList})
    GROUP BY month_year ORDER BY month_year DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);
$trendField = array_reverse($trendField);

$trendOffice = $db->query("
    SELECT DATE_FORMAT(log_date,'%Y-%m') AS month_year,
           COALESCE(SUM(TIMESTAMPDIFF(MINUTE,time_in,time_out)/60.0),0) AS hours
    FROM office_work_logs
    WHERE user_id IN ({$fullScopeList})
    GROUP BY DATE_FORMAT(log_date,'%Y-%m') ORDER BY month_year DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);
$trendOffice = array_reverse($trendOffice);

$noLogStaff = [];
if (!empty($managedIds)) {
    $managedList = implode(',', $managedIds);
    $noLogStaff = $db->query("
        SELECT u.full_name, u.employee_id FROM users u
        WHERE u.is_active=1 AND u.id IN ({$managedList})
          AND u.id NOT IN (
              SELECT DISTINCT user_id FROM work_logs
              WHERE month_year='{$month}'
          )
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$jsLabels = json_encode(array_map(fn($t) => date('M', strtotime($t['month_year'] . '-01')), $trendField));
$jsFieldHours = json_encode(array_map(fn($t) => round((float) $t['hours'], 1), $trendField));
$jsFieldVisit = json_encode(array_map(fn($t) => (int) $t['visited'], $trendField));
$jsFieldMiss = json_encode(array_map(fn($t) => (int) $t['missed'], $trendField));

$offMap = [];
foreach ($trendField as $t) {
    $found = array_filter($trendOffice, fn($o) => $o['month_year'] === $t['month_year']);
    $offMap[] = $found ? round((float) array_values($found)[0]['hours'], 1) : 0;
}
$jsOfficeHours = json_encode($offMap);

$trendStmt = $db->query("
    SELECT log_date,
           COALESCE(SUM(duration_hours),0) AS hours,
           COUNT(*) AS visits
    FROM work_logs
    WHERE log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
      AND user_id IN ({$fullScopeList})
    GROUP BY log_date ORDER BY log_date ASC
    LIMIT 14
");
$trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

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

$staffPerf = [];
if (!empty($fullScopeIds)) {
    $fullScopeListStaff = implode(',', array_filter($fullScopeIds, fn($id) => $id !== $uid)) ?: '0';
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
                WHEN wl2.client_id = wpe2.client_id
                AND wl2.log_date  = wpe2.plan_date
                AND wl2.user_id   = wpe2.assigned_to
                THEN wpe2.id END)
            FROM work_plan_entries wpe2
            JOIN work_plans wp2 ON wp2.id = wpe2.plan_id
            LEFT JOIN work_logs wl2
                ON wl2.client_id = wpe2.client_id
                AND wl2.log_date  = wpe2.plan_date
                AND wl2.user_id   = wpe2.assigned_to
            WHERE wpe2.assigned_to = u.id
            AND wp2.plan_month = '{$monthStart}')  AS matched_visits
        FROM users u
        LEFT JOIN departments d_primary ON d_primary.id = u.department_id
        LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
        LEFT JOIN departments d_all ON (
            d_all.id = u.department_id
            OR d_all.id = uda.department_id
        )
        LEFT JOIN work_logs wl
            ON wl.user_id=u.id AND wl.month_year='{$month}'
        WHERE u.id IN ({$fullScopeListStaff}) AND u.is_active=1
        GROUP BY u.id, u.full_name, u.employee_id, u.department_id
        ORDER BY hours DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $staffPlannedHours = [];
    if (!empty($fullScopeIds)) {
        $rows = $db->query("
            SELECT wpe.assigned_to, COALESCE(SUM(wpe.planned_hours),0) AS planned_hours
            FROM work_plan_entries wpe
            JOIN work_plans wp ON wp.id = wpe.plan_id
            WHERE wp.plan_month='{$monthStart}' AND wpe.assigned_to IN ({$fullScopeListStaff})
            GROUP BY wpe.assigned_to
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $staffPlannedHours[(int) $r['assigned_to']] = (float) $r['planned_hours'];
        }
    }
}

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
      AND wl.user_id IN ({$fullScopeList})
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

$selfClientFilter = $filterClientId ? " AND client_id=" . (int) $filterClientId : "";
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

$todayPlans = $db->query("
    SELECT wpe.*, c.company_name, c.company_code,
           u.full_name AS staff_name
    FROM work_plan_entries wpe
    JOIN companies c   ON c.id  = wpe.client_id
    JOIN work_plans wp ON wp.id = wpe.plan_id
    JOIN users u       ON u.id  = wpe.assigned_to
    WHERE wpe.plan_date = '{$today}'
      AND wpe.assigned_to = {$uid}
    ORDER BY wpe.planned_time_in
")->fetchAll(PDO::FETCH_ASSOC);

$tomorrowPlans = $db->query("
    SELECT wpe.*, c.company_name, c.company_code,
           u.full_name AS staff_name
    FROM work_plan_entries wpe
    JOIN companies c   ON c.id  = wpe.client_id
    JOIN work_plans wp ON wp.id = wpe.plan_id
    JOIN users u       ON u.id  = wpe.assigned_to
    WHERE wpe.plan_date = '{$tomorrow}'
      AND wpe.assigned_to = {$uid}
    ORDER BY wpe.planned_time_in
")->fetchAll(PDO::FETCH_ASSOC);

$recentSQL = "
    SELECT wl.log_date, wl.day_of_week, wl.time_in, wl.time_out,
           wl.duration_hours, wl.visit_status, wl.work_description,
           c.company_name, c.company_code,
           u.full_name AS staff_name
    FROM work_logs wl
    JOIN companies c ON c.id=wl.client_id
    JOIN users u     ON u.id=wl.user_id
    WHERE wl.user_id IN ({$perfScope})
      AND wl.log_date BETWEEN '{$filterFrom}' AND '{$filterTo}'
";
if ($filterClientId)
    $recentSQL .= " AND wl.client_id=" . (int) $filterClientId;
$recentSQL .= " ORDER BY wl.log_date DESC, wl.created_at DESC LIMIT 10";
$recentLogs = $db->query($recentSQL)->fetchAll(PDO::FETCH_ASSOC);

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

$myClients = $db->query("
    SELECT DISTINCT c.id, c.company_name, c.company_code, c.pan_number
    FROM companies c
    WHERE c.is_active = 1
    ORDER BY c.company_name
")->fetchAll(PDO::FETCH_ASSOC);

function vstBadge(string $s): string
{
    $map = [
        'visited' => ['#E1F5EE', '#1D9E75', 'fa-check-circle', 'Visited'],
        'missed' => ['#FCEBEB', '#E24B4A', 'fa-times-circle', 'Missed'],
        'rescheduled' => ['#FAEEDA', '#BA7517', 'fa-redo', 'Rescheduled'],
    ];
    [$bg, $col, $ico, $lbl] = $map[$s] ?? ['#f9fafb', '#9ca3af', 'fa-circle', '—'];
    return "<span style='background:{$bg};color:{$col};padding:.18rem .6rem;border-radius:99px;
            font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;white-space:nowrap;'>
            <i class=\"fas {$ico}\" style=\"font-size:.6rem;\"></i>{$lbl}</span>";
}

function safeEff(float $actual, float $planned): array
{
    if ($planned <= 0)
        return [0, 0, '#9ca3af'];
    $raw = round(($actual / $planned) * 100, 1);
    $capped = min($raw, 100);
    $color = $capped >= 80 ? '#1D9E75' : ($capped >= 50 ? '#BA7517' : '#E24B4A');
    return [$capped, $raw, $color];
}

$pageTitle = 'Consulting Dashboard';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<!-- ═══════════════════════════════════════════════════════════
     HTML
════════════════════════════════════════════════════════════ -->
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
                        <a href="staff_performance.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-users me-1"></i> Staff Report
                        </a>
                        <a href="client_report.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-building me-1"></i> Client Report
                        </a>
                        <a href="log_list.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-history me-1"></i> All Logs
                        </a>
                        <a href="plan_list.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-list me-1"></i> All Plans
                        </a>
                        <a href="plan_approvals.php" class="btn btn-outline-secondary btn-sm"
                            style="position:relative;">
                            <i class="fas fa-check-circle me-1"></i> Approvals
                            <?php if ($notifCount > 0): ?>
                                <span class="badge bg-danger ms-1"><?= $notifCount ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- ══ MULTI-DEPT NOTICE ═══════════════════════════════════════ -->
            <?php
            if ($isAdmin):
                $noDeptStaff = array_filter($scopeStaff, fn($s) => empty($s['department_id']));
                if (!empty($noDeptStaff)):
                    ?>
                    <div class="cd-notice">
                        <i class="fas fa-layer-group" style="color:#f59e0b;flex-shrink:0;"></i>
                        <div>
                            <strong>Multi-dept staff included:</strong>
                            <?= implode(', ', array_map(fn($s) => htmlspecialchars($s['full_name']), $noDeptStaff)) ?>
                        </div>
                    </div>
                <?php endif; endif; ?>

            <!-- ══ FILTERS ════════════════════════════════════════════════ -->
            <div class="cd-filter">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label-mis"
                            style="font-size:11px;font-weight:600;color:var(--gray-txt);margin-bottom:4px;display:block;">Month</label>
                        <input type="month" id="filterMonth" class="form-control form-control-sm" value="<?= $month ?>"
                            onchange="applyFilters()">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label-mis"
                            style="font-size:11px;font-weight:600;color:var(--gray-txt);margin-bottom:4px;display:block;">Client</label>
                        <select id="filterClient" class="form-select form-select-sm">
                            <option value="">-- All Clients --</option>
                            <?php foreach ($myClients as $mc): ?>
                                <option value="<?= $mc['id'] ?>"
                                    data-code="<?= htmlspecialchars($mc['company_code'] ?? '') ?>"
                                    data-pan="<?= htmlspecialchars($mc['pan_number'] ?? '') ?>"
                                    <?= $filterClientId == $mc['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($mc['company_name']) ?>
                                    <?= $mc['company_code'] ? ' — ' . $mc['company_code'] : '' ?>
                                    <?= $mc['pan_number'] ? ' — ' . $mc['pan_number'] : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label-mis"
                            style="font-size:11px;font-weight:600;color:var(--gray-txt);margin-bottom:4px;display:block;">Date
                            Range</label>
                        <div class="input-group input-group-sm">
                            <input type="date" id="filterFrom" class="form-control" value="<?= $filterFrom ?>"
                                onchange="applyFilters()">
                            <input type="date" id="filterTo" class="form-control" value="<?= $filterTo ?>"
                                onchange="applyFilters()">
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="index.php?month=<?= $month ?>" class="cd-btn w-100" style="justify-content:center;">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </div>
            </div>

            <!-- ══ TODAY BANNER ═══════════════════════════════════════════ -->
            <?php
            $todayOfficeUserList = implode(',', array_map('intval', $selfAndManaged)) ?: (string) $uid;
            $todayOfficeLogs = $db->query("
                SELECT owl.*, c.company_name, c.company_code, u.full_name AS staff_name
                FROM office_work_logs owl
                JOIN companies c ON c.id = owl.client_id
                JOIN users u     ON u.id = owl.user_id
                WHERE owl.log_date = '{$today}'
                AND owl.user_id IN ({$todayOfficeUserList})
                ORDER BY u.full_name, owl.time_in
            ")->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php if (!empty($todayPlans) || !empty($todayOfficeLogs)): ?>
                <div class="cd-today-banner">
                    <div class="cd-banner-hd">
                        <i class="fas fa-calendar-check" style="color:var(--teal);font-size:1rem;flex-shrink:0;"></i>
                        <span class="cd-banner-date" style="color:var(--teal-dk);">Today — <?= date('d M Y') ?></span>
                    </div>

                    <?php if (!empty($todayPlans)): ?>
                        <div class="cd-banner-sub" style="color:var(--teal-dk);">
                            <i class="fas fa-car me-1"></i>Field Visits
                        </div>
                        <div style="display:flex;flex-wrap:wrap;">
                            <?php foreach ($todayPlans as $p): ?>
                                <span class="cd-visit-chip" style="background:#d1fae5;color:var(--teal-dk);">
                                    <?php if ((int) $p['assigned_to'] !== $uid): ?>
                                        <span style="background:#6ee7b7;border-radius:99px;padding:0 .35rem;font-size:.62rem;">
                                            <?= htmlspecialchars(explode(' ', $p['staff_name'])[0]) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($p['company_name']) ?>
                                    <?= $p['planned_time_in'] ? ' · ' . date('g:i A', strtotime($p['planned_time_in'])) : '' ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    $myOfficeLogs = array_filter($todayOfficeLogs, fn($ol) => (int) $ol['user_id'] === $uid);
                    $staffOfficeLogs = array_filter($todayOfficeLogs, fn($ol) => (int) $ol['user_id'] !== $uid);
                    ?>

                    <?php if (!empty($myOfficeLogs)): ?>
                        <div class="cd-banner-sub" style="color:var(--blue-dk);margin-top:8px;">
                            <i class="fas fa-laptop me-1"></i>My Office Work
                        </div>
                        <div style="display:flex;flex-wrap:wrap;">
                            <?php foreach ($myOfficeLogs as $ol):
                                $olStatusColor = match ($ol['status'] ?? '') {
                                    'completed' => 'var(--teal)',
                                    'wip' => '#f59e0b',
                                    'holding' => 'var(--purple)',
                                    default => '#9ca3af',
                                };
                                ?>
                                <span class="cd-visit-chip" style="background:var(--blue-lt);color:var(--blue-dk);">
                                    <?= htmlspecialchars($ol['company_name']) ?>
                                    <?= $ol['time_in'] ? ' · ' . date('g:i A', strtotime($ol['time_in'])) : '' ?>
                                    <span style="font-size:.65rem;margin-left:2px;">● <?= ucfirst($ol['status'] ?? '') ?></span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($staffOfficeLogs)): ?>
                        <div class="cd-banner-sub" style="color:var(--purple-dk);margin-top:8px;">
                            <i class="fas fa-users me-1"></i>Staff Office Work
                        </div>
                        <div style="display:flex;flex-wrap:wrap;">
                            <?php
                            $staffOfficeByUser = [];
                            foreach ($staffOfficeLogs as $ol) {
                                $staffOfficeByUser[$ol['staff_name']][] = $ol;
                            }
                            foreach ($staffOfficeByUser as $staffName => $staffLogs):
                                $firstName = htmlspecialchars(explode(' ', $staffName)[0]);
                                foreach ($staffLogs as $ol):
                                    ?>
                                    <span class="cd-visit-chip" style="background:var(--purple-lt);color:var(--purple-dk);">
                                        <span
                                            style="background:#c4b5fd;border-radius:99px;padding:0 .35rem;font-size:.62rem;"><?= $firstName ?></span>
                                        <?= htmlspecialchars($ol['company_name']) ?>
                                        <?= $ol['time_in'] ? ' · ' . date('g:i A', strtotime($ol['time_in'])) : '' ?>
                                        <span style="font-size:.65rem;margin-left:2px;">● <?= ucfirst($ol['status'] ?? '') ?></span>
                                    </span>
                                <?php endforeach; endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- ══ TOMORROW BANNER ════════════════════════════════════════ -->
            <?php if (!empty($tomorrowPlans)): ?>
                <div class="cd-tomorrow-banner">
                    <div class="cd-banner-hd">
                        <i class="fas fa-calendar" style="color:var(--gold);font-size:1rem;flex-shrink:0;"></i>
                        <span class="cd-banner-date" style="color:var(--gold-dk);">Tomorrow —
                            <?= date('d M Y', strtotime('+1 day')) ?></span>
                    </div>
                    <div class="cd-banner-sub" style="color:var(--gold-dk);">
                        <i class="fas fa-car me-1"></i>Field Visits
                    </div>
                    <div style="display:flex;flex-wrap:wrap;">
                        <?php foreach ($tomorrowPlans as $p): ?>
                            <span class="cd-visit-chip" style="background:#fef3c7;color:var(--gold-dk);">
                                <?php if ((int) $p['assigned_to'] !== $uid): ?>
                                    <span style="background:#fde68a;border-radius:99px;padding:0 .35rem;font-size:.62rem;">
                                        <?= htmlspecialchars(explode(' ', $p['staff_name'])[0]) ?>
                                    </span>
                                <?php endif; ?>
                                <?= htmlspecialchars($p['company_name']) ?>
                                <?= $p['planned_time_in'] ? ' · ' . date('g:i A', strtotime($p['planned_time_in'])) : '' ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════════
                 SECTION 1 — FIELD VISIT OVERVIEW
            ═══════════════════════════════════════════════════════════ -->
            <div class="cd-section">
                <span class="cd-section-lbl">
                    <i class="fas fa-car" style="color:var(--gold)"></i> Field Visit Overview
                </span>
            </div>

            <!-- KPI tiles -->
            <div class="cd-kpi-grid">
                <?php
                $fieldKpis = [
                    ['fa-users', 'var(--blue)', count($managedIds) + 1, 'Scope (Staff+Me)'],
                    ['fa-clock', 'var(--gold)', number_format($totalHours, 1) . 'h', 'Field Hours'],
                    ['fa-laptop', 'var(--teal)', number_format($offHours, 1) . 'h', 'Office Hours'],
                    ['fa-check-circle', 'var(--teal)', $visitedCnt, 'Visited'],
                    ['fa-times-circle', 'var(--red)', $missedCnt, 'Missed'],
                    ['fa-redo', 'var(--gold)', $rescheduledCnt, 'Rescheduled'],
                    ['fa-building', 'var(--purple)', (int) ($kpi['unique_clients'] ?? 0), 'Clients Served'],
                    ['fa-clipboard-list', 'var(--purple)', (int) ($kpi['total_logs'] ?? 0), 'Log Entries'],
                    ['fa-tachometer-alt', $teamEffCol, $teamEff . '%', 'Team Efficiency'],
                    ['fa-bell', 'var(--gold)', $pendingApprovals, 'Pending Approval'],
                    ['fa-calendar-check', 'var(--blue)', (int) ($pk['approved'] ?? 0) . '/' . (int) ($pk['total_plans'] ?? 0), 'Plans Approved'],
                ];
                foreach ($fieldKpis as [$ico, $col, $val, $lbl]): ?>
                    <div class="cd-kpi" style="--kpi-color:<?= $col ?>;">
                        <div class="cd-kpi::before" style="background:<?= $col ?>;"></div>
                        <style>
                            .cd-kpi:nth-child(<?= array_search([$ico, $col, $val, $lbl], $fieldKpis) + 1 ?>)::before {
                                background:
                                    <?= $col ?>
                                ;
                            }
                        </style>
                        <div class="cd-kpi-icon"><i class="fas <?= $ico ?>" style="color:<?= $col ?>"></i></div>
                        <div class="cd-kpi-val" style="color:<?= $col ?>"><?= $val ?></div>
                        <div class="cd-kpi-lbl"><?= $lbl ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php
            // Cleaner approach: inject KPI accent colors via inline style attribute
            ?>
            <style>
                <?php
                $fieldKpisForStyle = [
                    'var(--blue)',
                    'var(--gold)',
                    'var(--teal)',
                    'var(--teal)',
                    'var(--red)',
                    'var(--gold)',
                    'var(--purple)',
                    'var(--purple)',
                    $teamEffCol,
                    'var(--gold)',
                    'var(--blue)'
                ];
                foreach ($fieldKpisForStyle as $idx => $col):
                    ?>
                    .cd-kpi-grid .cd-kpi:nth-child(<?= $idx + 1 ?>)::before {
                        background:
                            <?= $col ?>
                        ;
                    }

                <?php endforeach; ?>
            </style>

            <!-- 3-col charts row: donut | planned vs actual | mini trend -->
            <div class="row g-3 mb-4">

                <!-- Visit status donut -->
                <div class="card-mis">
                    <div class="card-mis-hd">
                        <span class="card-mis-title">
                            <i class="fas fa-chart-pie" style="color:var(--gold)"></i> Visit Status
                        </span>
                        <span class="card-mis-sub"><?= $monthLabel ?></span>
                    </div>
                    <div class="cd-donut-wrap">
                        <div class="cd-donut-cw" style="width:130px;height:130px;">
                            <canvas id="exDonutVisit" width="130" height="130"></canvas>
                            <div class="cd-donut-ctr">
                                <span
                                    style="font-size:17px;font-weight:700;color:var(--text-dark);font-variant-numeric:tabular-nums;">
                                    <?= $visitedCnt + $missedCnt + $rescheduledCnt ?>
                                </span>
                                <span style="font-size:9px;color:var(--gray-txt)">total</span>
                            </div>
                        </div>
                        <div class="cd-donut-leg">
                            <?php
                            $tot = max($visitedCnt + $missedCnt + $rescheduledCnt, 1);
                            foreach ([
                                ['Visited', $visitedCnt, '#1D9E75', '#E1F5EE', '#085041'],
                                ['Missed', $missedCnt, '#E24B4A', '#FCEBEB', '#791F1F'],
                                ['Rescheduled', $rescheduledCnt, '#BA7517', '#FAEEDA', '#633806'],
                            ] as [$l, $c, $dot, $bglt, $tc]): ?>
                                <div class="cd-donut-row">
                                    <span
                                        style="width:7px;height:7px;border-radius:2px;background:<?= $dot ?>;flex-shrink:0;"></span>
                                    <span style="color:var(--gray-txt);flex:1;"><?= $l ?></span>
                                    <span
                                        style="font-weight:700;color:var(--text-dark);font-variant-numeric:tabular-nums;margin-right:4px;"><?= $c ?></span>
                                    <span class="cd-pill"
                                        style="background:<?= $bglt ?>;color:<?= $tc ?>;"><?= round($c / $tot * 100) ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="padding:0 16px 12px;">
                        <div class="cd-vsbar">
                            <?php if ($visitedCnt): ?>
                                <div style="flex:<?= $visitedCnt ?>;background:var(--teal);"></div>
                            <?php endif; ?>
                            <?php if ($missedCnt): ?>
                                <div style="flex:<?= $missedCnt ?>;background:var(--red);"></div>
                            <?php endif; ?>
                            <?php if ($rescheduledCnt): ?>
                                <div style="flex:<?= $rescheduledCnt ?>;background:var(--amber);"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Planned vs Actual -->
                <div class="card-mis">
                    <div class="card-mis-hd">
                        <span class="card-mis-title">
                            <i class="fas fa-sliders-h" style="color:var(--gold)"></i> Planned vs Actual
                        </span>
                    </div>
                    <div style="padding:14px 16px;">
                        <?php
                        $pvaRows = [
                            ['Planned hrs', $plannedHrs, $plannedHrs, 'var(--blue)'],
                            ['Field actual', $actualHrs, $plannedHrs, $teamEffCol],
                            ['Office hrs', $offHours, $plannedHrs, 'var(--teal)'],
                        ];
                        foreach ($pvaRows as [$lbl, $val, $base, $col]):
                            $w = $base > 0 ? min(round($val / $base * 100), 100) : 0;
                            ?>
                            <div class="cd-bar-row">
                                <span class="cd-bar-lbl"><?= $lbl ?></span>
                                <div class="cd-bar-track">
                                    <div class="cd-bar-fill" style="width:<?= $w ?>%;background:<?= $col ?>;"></div>
                                </div>
                                <span class="cd-bar-val"><?= number_format($val, 1) ?>h</span>
                            </div>
                        <?php endforeach; ?>

                        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--gray-mid);">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                                <?php foreach ([
                                    ['Planned', $plannedCount, 'var(--blue)'],
                                    ['Matched', $matchedCount, 'var(--teal)'],
                                    ['Unmatched', max(0, $plannedCount - $matchedCount), 'var(--red)'],
                                    ['Visit Eff.', $visitEff . '%', $effColor],
                                    ['Hour Eff.', $hourEff . '%', $hourEffColor],
                                ] as [$l, $v, $c]): ?>
                                    <div style="display:flex;flex-direction:column;gap:1px;">
                                        <span style="font-size:10px;color:var(--gray-txt);"><?= $l ?></span>
                                        <strong
                                            style="font-size:12px;color:<?= $c ?>;font-variant-numeric:tabular-nums;"><?= $v ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mini 6-month trend -->
                <div class="card-mis">
                    <div class="card-mis-hd">
                        <span class="card-mis-title">
                            <i class="fas fa-chart-bar" style="color:var(--gold)"></i> Monthly Trend
                        </span>
                        <span class="card-mis-sub">Last 6 months</span>
                    </div>
                    <div style="position:relative;height:150px;padding:10px 13px 0;">
                        <canvas id="exTrendMini"></canvas>
                    </div>
                    <div class="cd-leg">
                        <span><span class="cd-leg-dot" style="background:var(--blue);"></span>Field hrs</span>
                        <span><span class="cd-leg-dot" style="background:var(--teal);"></span>Office hrs</span>
                    </div>
                </div>

            </div><!-- /cd-grid-3 field -->

            <!-- ══════════════════════════════════════════════════════════
                 SECTION 2 — OFFICE WORK OVERVIEW
            ═══════════════════════════════════════════════════════════ -->
            <div class="cd-section">
                <span class="cd-section-lbl">
                    <i class="fas fa-laptop" style="color:var(--blue)"></i> Office Work Overview
                </span>
            </div>

            <div class="cd-kpi-grid">
                <?php
                $offKpis = [
                    ['fa-file-alt', 'var(--blue)', $offLogCount, 'Office Logs'],
                    ['fa-hourglass', 'var(--gold)', number_format($offHours, 1) . 'h', 'Office Hours'],
                    ['fa-building', 'var(--blue)', $offClients, 'Clients Served'],
                    ['fa-check-double', 'var(--teal)', $offCompleted, 'Completed'],
                    ['fa-spinner', 'var(--gold)', $offWip, 'In Progress'],
                    ['fa-pause-circle', 'var(--purple)', $offHolding, 'Holding'],
                    ['fa-circle', '#9ca3af', $offNotStart, 'Not Started'],
                ];
                $offKpiColors = ['var(--blue)', 'var(--gold)', 'var(--blue)', 'var(--teal)', 'var(--gold)', 'var(--purple)', '#9ca3af'];
                ?>
                <style>
                    <?php foreach ($offKpiColors as $idx => $col): ?>
                        .cd-kpi-grid+.cd-kpi-grid .cd-kpi:nth-child(<?= $idx + 1 ?>)::before {
                            background:
                                <?= $col ?>
                            ;
                        }

                    <?php endforeach; ?>
                </style>
                <?php foreach ($offKpis as $ki => [$ico, $col, $val, $lbl]): ?>
                    <div class="cd-kpi" style="<?= "border-left: 3px solid {$col};" ?>">
                        <div class="cd-kpi-icon"><i class="fas <?= $ico ?>" style="color:<?= $col ?>"></i></div>
                        <div class="cd-kpi-val" style="color:<?= $col ?>"><?= $val ?></div>
                        <div class="cd-kpi-lbl"><?= $lbl ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 2-col: status bars | completion donut -->
            <div class="row g-3 mb-4">

                <div class="card-mis">
                    <div class="card-mis-hd">
                        <span class="card-mis-title">
                            <i class="fas fa-tasks" style="color:var(--gold)"></i> Office Logs by Status
                        </span>
                    </div>
                    <div style="padding:14px 16px;">
                        <?php
                        $maxO = max($offCompleted, $offWip, $offHolding, $offNotStart, 1);
                        foreach ([
                            ['Completed', $offCompleted, 'var(--teal)'],
                            ['In Progress', $offWip, 'var(--gold)'],
                            ['Holding', $offHolding, 'var(--purple)'],
                            ['Not Started', $offNotStart, '#9ca3af'],
                        ] as [$lbl, $v, $c]):
                            $w = round($v / $maxO * 100);
                            ?>
                            <div class="cd-bar-row">
                                <span class="cd-bar-lbl"><?= $lbl ?></span>
                                <div class="cd-bar-track">
                                    <div class="cd-bar-fill" style="width:<?= $w ?>%;background:<?= $c ?>;"></div>
                                </div>
                                <span class="cd-bar-val"><?= $v ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card-mis">
                    <div class="card-mis-hd">
                        <span class="card-mis-title">
                            <i class="fas fa-chart-pie" style="color:var(--gold)"></i> Completion Rate
                        </span>
                    </div>
                    <div class="cd-donut-wrap">
                        <div class="cd-donut-cw" style="width:130px;height:130px;">
                            <canvas id="exOfficeDonut" width="130" height="130"></canvas>
                            <div class="cd-donut-ctr">
                                <span
                                    style="font-size:18px;font-weight:700;color:var(--teal);font-variant-numeric:tabular-nums;"><?= $offCompRate ?>%</span>
                                <span style="font-size:9px;color:var(--gray-txt);">done</span>
                            </div>
                        </div>
                        <div class="cd-donut-leg">
                            <?php foreach ([
                                ['Completed', 'var(--teal)', $offCompleted],
                                ['WIP', 'var(--gold)', $offWip],
                                ['Holding', 'var(--purple)', $offHolding],
                                ['Not started', '#9ca3af', $offNotStart],
                            ] as [$l, $c, $v]): ?>
                                <div class="cd-donut-row">
                                    <span
                                        style="width:7px;height:7px;border-radius:2px;background:<?= $c ?>;flex-shrink:0;"></span>
                                    <span style="color:var(--gray-txt);flex:1;"><?= $l ?></span>
                                    <span
                                        style="font-weight:700;color:var(--text-dark);font-variant-numeric:tabular-nums;"><?= $v ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Recent Office Logs table -->
            <div class="card-mis mb-3">
                <div class="card-mis-hd">
                    <span class="card-mis-title">
                        <i class="fas fa-table" style="color:var(--gold)"></i> Recent Office Logs
                    </span>
                    <span class="card-mis-sub"><?= $offLogCount ?> entries · <?= $monthLabel ?></span>
                </div>
                <?php if (empty($recentOfficeLogs)): ?>
                    <div style="padding:36px;text-align:center;color:var(--gray-txt);font-size:13px;">
                        <i class="fas fa-laptop" style="font-size:1.8rem;display:block;margin-bottom:8px;opacity:.3;"></i>
                        No office logs this month
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;" class="table-responsive cn-table-wrap">
                        <table class="cn-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Staff</th>
                                    <th>Client</th>
                                    <th>Dept</th>
                                    <th>Time In</th>
                                    <th>Time Out</th>
                                    <th style="text-align:center;">Hours</th>
                                    <th>Description</th>
                                    <th style="text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentOfficeLogs as $ol):
                                    $olHrs = ($ol['time_in'] && $ol['time_out'])
                                        ? (strtotime($ol['time_out']) - strtotime($ol['time_in'])) / 3600 : 0;
                                    $statusMap = [
                                        'completed' => ['background:#E1F5EE;color:#085041', 'Completed'],
                                        'wip' => ['background:#FAEEDA;color:#633806', 'WIP'],
                                        'holding' => ['background:#EEEDFE;color:#3C3489', 'Holding'],
                                        'not_started' => ['background:#f3f4f6;color:#6b7280', 'Not Started'],
                                    ];
                                    [$smStyle, $smLabel] = $statusMap[$ol['status']] ?? $statusMap['not_started'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600;"><?= date('d M', strtotime($ol['log_date'])) ?></div>
                                            <div style="font-size:10px;color:var(--gray-txt);">
                                                <?= date('D', strtotime($ol['log_date'])) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;"><?= htmlspecialchars($ol['full_name']) ?></div>
                                            <div style="font-size:10px;color:var(--gray-txt);">
                                                <?= htmlspecialchars($ol['emp_id'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;"><?= htmlspecialchars($ol['company_name']) ?></div>
                                            <div style="font-size:10px;color:var(--gray-txt);">
                                                <?= htmlspecialchars($ol['company_code'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td style="color:var(--gray-txt);font-size:11px;">
                                            <?= htmlspecialchars($ol['dept_name']) ?>
                                        </td>
                                        <td style="font-family:monospace;font-size:11px;">
                                            <?= $ol['time_in'] ? date('h:i A', strtotime($ol['time_in'])) : '—' ?>
                                        </td>
                                        <td style="font-family:monospace;font-size:11px;">
                                            <?= $ol['time_out'] ? date('h:i A', strtotime($ol['time_out'])) : '—' ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <strong
                                                style="color:var(--gold);font-variant-numeric:tabular-nums;"><?= number_format($olHrs, 1) ?>h</strong>
                                        </td>
                                        <td style="color:var(--gray-txt);max-width:180px;">
                                            <div
                                                style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:180px;">
                                                <?= htmlspecialchars(mb_strimwidth($ol['description'] ?? '', 0, 55, '…')) ?>
                                            </div>
                                        </td>
                                        <td style="text-align:center;">
                                            <span class="cd-pill" style="<?= $smStyle ?>"><?= $smLabel ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════
                 SECTION 3 — PERFORMANCE ANALYSIS
            ═══════════════════════════════════════════════════════════ -->
            <div class="cd-section">
                <span class="cd-section-lbl">
                    <i class="fas fa-chart-line" style="color:var(--purple)"></i> Performance Analysis
                </span>
            </div>

            <!-- Full 6-month trend chart -->
            <div class="card-mis mb-3">
                <div class="card-mis-hd">
                    <span class="card-mis-title">
                        <i class="fas fa-chart-line" style="color:var(--gold)"></i>
                        Field vs Office Hours — 6-Month Trend
                    </span>
                    <span class="card-mis-sub">My team</span>
                </div>
                <div style="position:relative;height:200px;padding:12px 15px 0;">
                    <canvas id="exTrendFull"></canvas>
                </div>
                <div class="cd-leg">
                    <span><span class="cd-leg-dot" style="background:var(--blue);"></span>Field hours</span>
                    <span><span class="cd-leg-dot"
                            style="background:var(--teal);border-top:2px dashed var(--teal);background:transparent;border:1.5px dashed var(--teal);"></span>Office
                        hours</span>
                    <span><span class="cd-leg-dot" style="background:var(--red);"></span>Missed visits</span>
                </div>
            </div>

            <!-- Tabbed performance section -->
            <div class="cd-tabs">
                <div class="cd-tab active" onclick="cdTab('staff',this)">
                    <i class="fas fa-users me-1"></i>Staff Performance
                </div>
                <div class="cd-tab" onclick="cdTab('clients',this)">
                    <i class="fas fa-building me-1"></i>Client Analytics
                </div>
                <div class="cd-tab" onclick="cdTab('charts',this)">
                    <i class="fas fa-chart-bar me-1"></i>Charts
                </div>
                <div class="cd-tab" onclick="cdTab('plans',this)">
                    <i class="fas fa-calendar-alt me-1"></i>Plans Status
                </div>
            </div>

            <!-- TAB: Staff Performance -->
            <div id="cd-tab-staff" class="cd-tab-pane active">
                <div class="cd-grid-main">

                    <!-- Staff compact list -->
                    <div class="card-mis">
                        <div class="card-mis-hd">
                            <span class="card-mis-title">
                                <i class="fas fa-users" style="color:var(--gold)"></i>
                                Staff Under Me — <?= $monthLabel ?>
                            </span>
                            <a href="staff_performance.php?month=<?= $month ?>"
                                style="font-size:11px;color:var(--blue);text-decoration:none;">Full Report →</a>
                        </div>
                        <?php if (empty($staffPerf)): ?>
                            <div style="padding:30px;text-align:center;color:var(--gray-txt);font-size:13px;">
                                No managed staff data this month
                            </div>
                        <?php else: ?>
                            <div style="padding:4px 15px 12px;">
                                <?php
                                $colors = ['#378ADD', '#1D9E75', '#BA7517', '#7F77DD', '#E24B4A'];
                                foreach ($staffPerf as $i => $sp):
                                    $spl = $staffPlannedHours[(int) $sp['id']] ?? 0;
                                    $seff = $spl > 0 ? min(round((float) $sp['hours'] / $spl * 100), 100) : 0;
                                    $sefc = $seff >= 80 ? 'var(--teal)' : ($seff >= 50 ? 'var(--gold)' : 'var(--red)');
                                    $ini = strtoupper(substr($sp['full_name'], 0, 1) . (strpos($sp['full_name'], ' ') !== false ? substr($sp['full_name'], strpos($sp['full_name'], ' ') + 1, 1) : ''));
                                    $c = $colors[$i % count($colors)];
                                    ?>
                                    <div class="cd-staff-row">
                                        <div class="cd-avatar" style="background:<?= $c ?>22;color:<?= $c ?>;"><?= $ini ?></div>
                                        <div style="flex:1;min-width:0;">
                                            <div
                                                style="font-size:12.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-dark);">
                                                <?= htmlspecialchars($sp['full_name']) ?>
                                            </div>
                                            <div style="font-size:10px;color:var(--gray-txt);">
                                                <?= htmlspecialchars($sp['employee_id'] ?? '') ?>
                                            </div>
                                            <div style="display:flex;align-items:center;gap:5px;margin-top:3px;">
                                                <div class="cd-eff-bar">
                                                    <div class="cd-eff-fill"
                                                        style="width:<?= $seff ?>%;background:<?= $sefc ?>;"></div>
                                                </div>
                                                <span
                                                    style="font-size:10px;color:<?= $sefc ?>;font-weight:600;"><?= $seff ?>%</span>
                                            </div>
                                        </div>
                                        <div style="text-align:right;flex-shrink:0;margin-left:8px;">
                                            <div
                                                style="font-size:13px;font-weight:700;color:var(--gold);font-variant-numeric:tabular-nums;">
                                                <?= number_format((float) $sp['hours'], 1) ?>h
                                            </div>
                                            <div style="display:flex;gap:3px;margin-top:3px;justify-content:flex-end;">
                                                <span class="cd-pill"
                                                    style="background:#E1F5EE;color:#085041;"><?= (int) $sp['visited'] ?>v</span>
                                                <span class="cd-pill"
                                                    style="background:#FCEBEB;color:#791F1F;"><?= (int) $sp['missed'] ?>m</span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right: Pending + Alerts -->
                    <div style="display:flex;flex-direction:column;gap:12px;min-width:0;">

                        <?php if ($pendingApprovals > 0):
                            $pendPlans = $db->query("
                                SELECT wp.*, u.full_name, u.employee_id,
                                       COUNT(wpe.id) AS entry_count,
                                       COALESCE(SUM(wpe.planned_hours),0) AS planned_hours
                                FROM work_plans wp
                                JOIN users u ON u.id=wp.user_id
                                LEFT JOIN work_plan_entries wpe ON wpe.plan_id=wp.id
                                WHERE wp.user_id IN ({$fullScopeList}) AND wp.status='submitted'
                                GROUP BY wp.id ORDER BY wp.created_at ASC LIMIT 6
                            ")->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <div class="card-mis">
                                <div class="card-mis-hd">
                                    <span class="card-mis-title">
                                        <i class="fas fa-bell" style="color:var(--gold)"></i> Pending Approvals
                                    </span>
                                    <a href="plan_approvals.php?month=<?= $month ?>"
                                        style="font-size:11px;color:var(--blue);text-decoration:none;">View all</a>
                                </div>
                                <div style="padding:10px 12px;">
                                    <?php foreach ($pendPlans as $pp): ?>
                                        <div class="cd-pending-card">
                                            <div style="font-size:12.5px;font-weight:600;color:var(--text-dark);">
                                                <?= htmlspecialchars($pp['full_name']) ?>
                                            </div>
                                            <div style="font-size:10px;color:var(--gray-txt);margin-bottom:6px;">
                                                Week <?= $pp['week_number'] ?> · <?= $pp['entry_count'] ?> entries ·
                                                <?= number_format($pp['planned_hours'], 1) ?>h
                                            </div>
                                            <a href="plan_view.php?id=<?= $pp['id'] ?>"
                                                style="display:block;text-align:center;background:var(--blue);color:#fff;
                                        padding:4px;border-radius:var(--radius-sm);font-size:11px;font-weight:600;text-decoration:none;">
                                                <i class="fas fa-eye me-1"></i>Review
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($noLogStaff)): ?>
                            <div class="card-mis">
                                <div class="card-mis-hd">
                                    <span class="card-mis-title">
                                        <i class="fas fa-exclamation-triangle" style="color:var(--red)"></i> Alerts
                                    </span>
                                </div>
                                <div style="padding:10px 12px;">
                                    <div style="font-size:11px;font-weight:600;color:var(--red);margin-bottom:6px;">
                                        <i class="fas fa-user-times me-1"></i>Staff with no logs
                                    </div>
                                    <?php foreach ($noLogStaff as $ns): ?>
                                        <div class="cd-alert-item">
                                            <i class="fas fa-circle" style="font-size:6px;color:var(--red);"></i>
                                            <span
                                                style="color:var(--red-dk);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                <?= htmlspecialchars($ns['full_name']) ?>
                                            </span>
                                            <span
                                                style="font-size:10px;color:var(--gray-txt);"><?= $ns['employee_id'] ?? '' ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                </div><!-- /cd-grid-main -->
            </div><!-- /tab-staff -->

            <!-- TAB: Client Analytics -->
            <div id="cd-tab-clients" class="cd-tab-pane">
                <?php if (!empty($clientPerf)): ?>
                    <!-- Top clients chart -->
                    <div class="card-mis mb-3">
                        <div class="card-mis-hd">
                            <span class="card-mis-title">
                                <i class="fas fa-chart-bar" style="color:var(--gold)"></i> Top Clients by Hours —
                                <?= $monthLabel ?>
                            </span>
                            <div style="display:flex;align-items:center;gap:.5rem;">
                                <span style="font-size:.75rem;color:var(--gray-txt);">Top <?= min(8, count($clientPerf)) ?>
                                    of <?= count($clientPerf) ?></span>
                                <?php if ($isAdmin): ?>
                                    <a href="client_report.php?month=<?= $month ?>"
                                        style="font-size:.75rem;color:var(--blue);text-decoration:none;">
                                        <i class="fas fa-external-link-alt me-1"></i>Full Report
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-mis-body"
                            style="height:<?= max(200, min(8, count($clientPerf)) * 42) ?>px;padding:12px 14px;">
                            <canvas id="clientChart"></canvas>
                        </div>
                    </div>
                <?php endif; ?>
            </div><!-- /tab-clients -->

            <!-- TAB: Charts -->
            <div id="cd-tab-charts" class="cd-tab-pane">
                <div class="row g-3 mb-3">
                    <div class="col-lg-6">
                        <div class="card-mis h-100">
                            <div class="card-mis-hd">
                                <span class="card-mis-title">
                                    <i class="fas fa-chart-line" style="color:var(--gold)"></i> Daily Trend —
                                    <?= $monthLabel ?>
                                </span>
                            </div>
                            <div style="height:260px;padding:10px 14px 12px;">
                                <canvas id="trendChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3">
                        <div class="card-mis h-100">
                            <div class="card-mis-hd">
                                <span class="card-mis-title">
                                    <i class="fas fa-chart-pie" style="color:var(--gold)"></i> Visit Status
                                </span>
                            </div>
                            <div style="padding:10px 14px 6px;">
                                <div style="height:170px;width:100%;position:relative;">
                                    <canvas id="statusChart"></canvas>
                                </div>
                                <div style="width:100%;margin-top:.6rem;">
                                    <?php foreach ([
                                        ['Visited', $kpi['visited'] ?? 0, '#1D9E75'],
                                        ['Missed', $kpi['missed'] ?? 0, '#E24B4A'],
                                        ['Rescheduled', $kpi['rescheduled'] ?? 0, '#BA7517'],
                                    ] as [$lbl, $cnt, $col]): ?>
                                        <div
                                            style="display:flex;justify-content:space-between;padding:.22rem 0;font-size:.74rem;border-bottom:1px solid var(--gray-mid);">
                                            <div style="display:flex;align-items:center;gap:.4rem;">
                                                <div style="width:8px;height:8px;border-radius:50%;background:<?= $col ?>;">
                                                </div>
                                                <span style="color:var(--text-dark);"><?= $lbl ?></span>
                                            </div>
                                            <span
                                                style="font-weight:700;color:var(--text-dark);font-variant-numeric:tabular-nums;"><?= (int) $cnt ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3">
                        <div class="card-mis h-100">
                            <div class="card-mis-hd">
                                <span class="card-mis-title">
                                    <i class="fas fa-layer-group" style="color:var(--gold)"></i> Weekly Hours
                                </span>
                            </div>
                            <div style="height:260px;padding:10px 14px 12px;">
                                <canvas id="weeklyChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div><!-- /tab-charts -->

            <!-- TAB: Plans Status -->
            <div id="cd-tab-plans" class="cd-tab-pane">
                <div class="card-mis mb-3">
                    <div class="card-mis-hd">
                        <span class="card-mis-title">
                            <i class="fas fa-calendar-alt" style="color:var(--gold)"></i> Plans Status —
                            <?= $monthLabel ?>
                        </span>
                        <a href="<?= $isAdmin ? 'plan_list' : 'staff/plan_list' ?>.php?month=<?= $month ?>"
                            class="cd-btn">
                            All Plans
                        </a>
                    </div>
                    <div style="padding:18px;">
                        <div class="row g-3">
                            <?php foreach ([
                                ['Draft', $pk['draft'] ?? 0, '#9ca3af', '#f9fafb'],
                                ['Submitted', $pk['submitted'] ?? 0, 'var(--blue)', 'var(--blue-lt)'],
                                ['Approved', $pk['approved'] ?? 0, 'var(--teal)', 'var(--teal-lt)'],
                                ['Rejected', $pk['rejected'] ?? 0, 'var(--red)', 'var(--red-lt)'],
                            ] as [$lbl, $cnt, $col, $bg]): ?>
                                <div class="col-6 col-md-3">
                                    <div class="cd-plan-tile" style="background:<?= $bg ?>;border:1px solid <?= $col ?>33;">
                                        <div class="cd-plan-num" style="color:<?= $col ?>;"><?= (int) $cnt ?></div>
                                        <div class="cd-plan-lbl" style="color:<?= $col ?>;"><?= $lbl ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div><!-- /tab-plans -->

            <!-- ══ PLANNED vs ACTUAL DETAIL BLOCK ════════════════════════ -->
            <div class="cd-pva-block">
                <div class="cd-pva-hd">
                    <h5
                        style="font-size:.95rem;font-weight:700;color:var(--text-dark);margin:0;display:flex;align-items:center;gap:.5rem;">
                        <i class="fas fa-chart-bar" style="color:var(--gold)"></i>
                        Planned vs Actual — <?= $monthLabel ?>
                    </h5>
                    <div style="display:flex;gap:14px;font-size:.75rem;flex-wrap:wrap;">
                        <span>Visit eff:
                            <strong style="color:<?= $effColor ?>">
                                <?= $visitEff ?>%
                                <?= $visitEffRaw > 100 ? '<span style="color:#f59e0b;font-size:.65rem;">(' . $visitEffRaw . '% raw)</span>' : '' ?>
                            </strong>
                        </span>
                        <span>Hour eff:
                            <strong style="color:<?= $hourEffColor ?>">
                                <?= $hourEff ?>%
                                <?= $hourEffRaw > 100 ? '<span style="color:#f59e0b;font-size:.65rem;">(' . $hourEffRaw . '% raw)</span>' : '' ?>
                            </strong>
                        </span>
                    </div>
                </div>

                <?php
                $maxH = max($plannedHrs, $actualHrs, 1);
                $pw = $plannedHrs > 0 ? round(($plannedHrs / $maxH) * 100) : 0;
                $aw = $actualHrs > 0 ? min(100, round(($actualHrs / $maxH) * 100)) : 0;
                ?>
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                    <span style="font-size:.76rem;color:var(--gray-txt);min-width:60px;">Planned</span>
                    <div style="flex:1;background:var(--gray-mid);border-radius:99px;height:8px;overflow:hidden;">
                        <div
                            style="width:<?= $pw ?>%;background:var(--blue);height:100%;border-radius:99px;transition:.4s;">
                        </div>
                    </div>
                    <span
                        style="font-size:.8rem;font-weight:700;color:var(--blue);min-width:50px;text-align:right;font-variant-numeric:tabular-nums;"><?= number_format($plannedHrs, 1) ?>h</span>
                </div>
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                    <span style="font-size:.76rem;color:var(--gray-txt);min-width:60px;">Actual</span>
                    <div style="flex:1;background:var(--gray-mid);border-radius:99px;height:8px;overflow:hidden;">
                        <div
                            style="width:<?= $aw ?>%;background:<?= $hourEffColor ?>;height:100%;border-radius:99px;transition:.4s;">
                        </div>
                    </div>
                    <span
                        style="font-size:.8rem;font-weight:700;color:<?= $hourEffColor ?>;min-width:50px;text-align:right;font-variant-numeric:tabular-nums;"><?= number_format($actualHrs, 1) ?>h</span>
                </div>

                <?php if ((int) ($kpi['total_logs'] ?? 0) > 0): ?>
                    <div style="font-size:.7rem;color:var(--gray-txt);margin-bottom:4px;">Visit breakdown</div>
                    <div style="display:flex;border-radius:6px;overflow:hidden;height:7px;">
                        <?php if ($kpi['visited']): ?>
                            <div style="flex:<?= $kpi['visited'] ?>;background:#1D9E75;"></div><?php endif; ?>
                        <?php if ($kpi['missed']): ?>
                            <div style="flex:<?= $kpi['missed'] ?>;background:#E24B4A;"></div><?php endif; ?>
                        <?php if ($kpi['rescheduled']): ?>
                            <div style="flex:<?= $kpi['rescheduled'] ?>;background:#BA7517;"></div><?php endif; ?>
                    </div>
                    <div style="display:flex;gap:14px;margin-top:5px;font-size:.7rem;flex-wrap:wrap;">
                        <span style="color:#1D9E75;">● Visited <?= $kpi['visited'] ?></span>
                        <span style="color:#E24B4A;">● Missed <?= $kpi['missed'] ?></span>
                        <span style="color:#BA7517;">● Rescheduled <?= $kpi['rescheduled'] ?></span>
                    </div>
                <?php endif; ?>

                <div
                    style="display:flex;gap:1.5rem;margin-top:.9rem;flex-wrap:wrap;padding-top:.75rem;border-top:1px solid var(--gray-mid);">
                    <?php foreach ([
                        ['Planned Visits', $plannedCount, 'var(--blue)'],
                        ['Matched', $matchedCount, 'var(--teal)'],
                        ['Unmatched', max(0, $plannedCount - $matchedCount), 'var(--red)'],
                        ['Visit Eff.', $visitEff . '%', $effColor],
                        ['Hour Eff.', $hourEff . '%', $hourEffColor],
                    ] as [$lbl, $val, $col]): ?>
                        <div style="font-size:.75rem;display:flex;flex-direction:column;gap:1px;">
                            <span style="color:var(--gray-txt);"><?= $lbl ?></span>
                            <strong style="color:<?= $col ?>;font-variant-numeric:tabular-nums;"><?= $val ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ══ STAFF UNDER ME TABLE (admin) ══════════════════════════ -->
            <?php if (!empty($staffPerf)): ?>
                <div class="card-mis mb-3" style="border-top:3px solid var(--gold);">
                    <div class="card-mis-hd">
                        <span class="card-mis-title">
                            <i class="fas fa-users" style="color:var(--gold)"></i>
                            Staff Under Me — <?= $monthLabel ?>
                        </span>
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            <span style="font-size:.78rem;color:var(--gray-txt);"><?= count($staffPerf) ?> member(s)</span>
                            <a href="staff_performance.php?month=<?= $month ?>"
                                style="font-size:.75rem;color:var(--blue);text-decoration:none;">
                                <i class="fas fa-external-link-alt me-1"></i>Full Report
                            </a>
                        </div>
                    </div>
                    <div style="overflow-x:auto;" class="table-responsive cn-table-wrap">
                        <table class="cn-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Staff</th>
                                    <th>Dept</th>
                                    <th style="text-align:center;">Hours</th>
                                    <th style="text-align:center;">Logs</th>
                                    <th style="text-align:center;">Clients</th>
                                    <th style="text-align:center;">Visited</th>
                                    <th style="text-align:center;">Missed</th>
                                    <th style="text-align:center;">Rescheduled</th>
                                    <th style="text-align:center;">Planned Visits</th>
                                    <th style="text-align:center;">Planned Hrs</th>
                                    <th style="text-align:center;">Actual Hrs</th>
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
                                    $sEffCol = $sEff >= 80 ? '#1D9E75' : ($sEff >= 50 ? '#BA7517' : '#E24B4A');
                                    $isNoDept = empty($s['department_id']);
                                    $initials = strtoupper(substr($s['full_name'], 0, 1) . (strpos($s['full_name'], ' ') !== false ? substr($s['full_name'], strpos($s['full_name'], ' ') + 1, 1) : ''));
                                    $sPlannedHrs = $staffPlannedHours[(int) $s['id']] ?? 0;
                                    $sActualHrs = (float) $s['hours'];
                                    [$sHourEff, $sHourEffRaw, $sHourEffCol] = safeEff($sActualHrs, $sPlannedHrs);

                                    $deptLabel = trim($s['dept_label'] ?? '');
                                    $deptList = array_filter(array_map('trim', explode(',', $deptLabel)));
                                    $isNoDeptDisplay = empty($deptList);
                                    ?>
                                    <tr <?= $isNoDept ? 'style="background:#fffdf0;"' : '' ?>>
                                        <td style="color:var(--gray-txt);font-size:.75rem;"><?= $i + 1 ?></td>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:.6rem;">
                                                <div class="cd-avatar"
                                                    style="background:<?= $isNoDept ? '#fef3c7' : '#BA751722' ?>;color:<?= $isNoDept ? '#b45309' : 'var(--gold)' ?>;">
                                                    <?= $initials ?>
                                                </div>
                                                <div>
                                                    <div style="font-size:.85rem;font-weight:500;color:var(--text-dark);">
                                                        <?= htmlspecialchars($s['full_name']) ?>
                                                        <?php if ($isNoDept): ?>
                                                            <i class="fas fa-layer-group" style="color:#f59e0b;font-size:.6rem;"
                                                                title="Multi-dept"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($s['employee_id']): ?>
                                                        <div style="font-size:.68rem;color:var(--gray-txt);">
                                                            <?= htmlspecialchars($s['employee_id']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($isNoDeptDisplay): ?>
                                                <span class="cd-chip" style="background:#fef3c7;color:#92400e;">No Dept</span>
                                            <?php else: ?>
                                                <div style="display:flex;flex-wrap:wrap;gap:.3rem;">
                                                    <?php foreach ($deptList as $d): ?>
                                                        <span class="cd-chip"
                                                            style="background:var(--gray-mid);color:#374151;"><?= htmlspecialchars($d) ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td
                                            style="text-align:center;font-weight:700;color:var(--blue);font-variant-numeric:tabular-nums;">
                                            <?= number_format($sActualHrs, 1) ?>h
                                        </td>
                                        <td style="text-align:center;"><?= (int) $s['logs'] ?></td>
                                        <td style="text-align:center;"><?= (int) $s['clients'] ?></td>
                                        <td style="text-align:center;">
                                            <span class="cd-chip chip-teal"><?= (int) $s['visited'] ?></span>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ((int) $s['missed'] > 0): ?>
                                                <span class="cd-chip chip-red"><?= (int) $s['missed'] ?></span>
                                            <?php else: ?><span style="color:var(--gray-bd);">—</span><?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ((int) $s['rescheduled'] > 0): ?>
                                                <span class="cd-chip chip-amber"><?= (int) $s['rescheduled'] ?></span>
                                            <?php else: ?><span style="color:var(--gray-bd);">—</span><?php endif; ?>
                                        </td>
                                        <td style="text-align:center;font-size:.8rem;">
                                            <span
                                                style="color:var(--blue);font-weight:600;font-variant-numeric:tabular-nums;"><?= $sMatched ?></span>
                                            <span style="color:var(--gray-txt);"> / <?= $sPlanned ?></span>
                                        </td>
                                        <td
                                            style="text-align:center;color:var(--blue);font-weight:600;font-size:.82rem;font-variant-numeric:tabular-nums;">
                                            <?= $sPlannedHrs > 0 ? number_format($sPlannedHrs, 1) . 'h' : '<span style="color:var(--gray-bd);">—</span>' ?>
                                        </td>
                                        <td
                                            style="text-align:center;font-weight:700;font-size:.82rem;font-variant-numeric:tabular-nums;
                                    color:<?= $sActualHrs >= 4 ? '#1D9E75' : ($sActualHrs >= 2 ? '#BA7517' : '#6b7280') ?>;">
                                            <?= number_format($sActualHrs, 1) ?>h
                                        </td>
                                        <td>
                                            <?php if ($sPlannedHrs > 0): ?>
                                                <div class="cd-range-wrap">
                                                    <div class="cd-range-track">
                                                        <div class="cd-range-fill"
                                                            style="width:<?= $sHourEff ?>%;background:<?= $sHourEffCol ?>;"></div>
                                                    </div>
                                                    <span
                                                        style="font-size:.72rem;font-weight:700;color:<?= $sHourEffCol ?>;min-width:38px;text-align:right;"><?= $sHourEff ?>%</span>
                                                </div>
                                                <?php if ($sHourEffRaw > 100): ?>
                                                    <div style="font-size:.63rem;color:#f59e0b;">⚠ <?= $sHourEffRaw ?>% raw</div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="font-size:.73rem;color:var(--gray-txt);">No plan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($sPlanned > 0): ?>
                                                <div class="cd-range-wrap">
                                                    <div class="cd-range-track">
                                                        <div class="cd-range-fill"
                                                            style="width:<?= $sEff ?>%;background:<?= $sEffCol ?>;"></div>
                                                    </div>
                                                    <span
                                                        style="font-size:.72rem;font-weight:700;color:<?= $sEffCol ?>;min-width:38px;text-align:right;"><?= $sEff ?>%</span>
                                                </div>
                                                <?php if ($sEffRaw > 100): ?>
                                                    <div style="font-size:.63rem;color:#f59e0b;">⚠ <?= $sEffRaw ?>% raw</div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="font-size:.73rem;color:var(--gray-txt);">No plan</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" style="color:var(--text-dark);">
                                        <i class="fas fa-calculator me-1" style="color:var(--gold)"></i>TOTAL
                                    </td>
                                    <td style="text-align:center;color:var(--gold);font-variant-numeric:tabular-nums;">
                                        <?= number_format(array_sum(array_column($staffPerf, 'hours')), 1) ?>h
                                    </td>
                                    <td style="text-align:center;"><?= array_sum(array_column($staffPerf, 'logs')) ?></td>
                                    <td style="text-align:center;"><?= array_sum(array_column($staffPerf, 'clients')) ?>
                                    </td>
                                    <td style="text-align:center;color:#1D9E75;">
                                        <?= array_sum(array_column($staffPerf, 'visited')) ?>
                                    </td>
                                    <td style="text-align:center;color:#E24B4A;">
                                        <?= array_sum(array_column($staffPerf, 'missed')) ?>
                                    </td>
                                    <td style="text-align:center;color:#BA7517;">
                                        <?= array_sum(array_column($staffPerf, 'rescheduled')) ?>
                                    </td>
                                    <td style="text-align:center;color:var(--gray-txt);">
                                        <?= array_sum(array_column($staffPerf, 'matched_visits')) ?> /
                                        <?= array_sum(array_column($staffPerf, 'planned_visits')) ?>
                                    </td>
                                    <td style="text-align:center;color:var(--blue);font-variant-numeric:tabular-nums;">
                                        <?= number_format(array_sum($staffPlannedHours), 1) ?>h
                                    </td>
                                    <td style="text-align:center;color:var(--gold);font-variant-numeric:tabular-nums;">
                                        <?= number_format(array_sum(array_column($staffPerf, 'hours')), 1) ?>h
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ══ CLIENT-WISE PERFORMANCE TABLE ════════════════════════ -->
            <?php if (!empty($clientPerf)): ?>
                <div class="card-mis mb-3" style="border-top:3px solid var(--purple);">
                    <div class="card-mis-hd">
                        <span class="card-mis-title">
                            <i class="fas fa-building" style="color:var(--gold)"></i>
                            Client-wise Performance — <?= $monthLabel ?>
                        </span>
                        <div style="display:flex;align-items:center;gap:.5rem;">
                            <span style="font-size:.78rem;color:var(--gray-txt);"><?= count($clientPerf) ?> client(s)</span>
                            <?php if ($isAdmin): ?>
                                <a href="client_report.php?month=<?= $month ?>"
                                    style="font-size:.75rem;color:var(--blue);text-decoration:none;">
                                    <i class="fas fa-external-link-alt me-1"></i>Full Report
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="overflow-x:auto;" class="table-responsive cn-table-wrap">
                        <table class="cn-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Client</th>
                                    <th style="text-align:center;">Staff</th>
                                    <th style="text-align:center;">Visits</th>
                                    <th style="text-align:center;">Visited</th>
                                    <th style="text-align:center;">Missed</th>
                                    <th style="text-align:center;">Rescheduled</th>
                                    <th style="text-align:center;">Planned Hrs</th>
                                    <th style="text-align:center;">Actual Hrs</th>
                                    <th style="min-width:150px;">Hour Efficiency</th>
                                    <th>First · Last Visit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clientPerf as $i => $cp):
                                    [$cEff, $cEffRaw, $cEffCol] = safeEff((float) $cp['actual_hours'], (float) $cp['planned_hours']);
                                    ?>
                                    <tr>
                                        <td style="color:var(--gray-txt);font-size:.75rem;"><?= $i + 1 ?></td>
                                        <td>
                                            <div style="font-weight:600;font-size:.85rem;color:var(--text-dark);">
                                                <?= htmlspecialchars($cp['company_name'] ?? '—') ?>
                                            </div>
                                            <div style="font-size:.7rem;color:var(--gray-txt);">
                                                <?= htmlspecialchars($cp['company_code'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td style="text-align:center;">
                                            <span
                                                style="font-size:.78rem;font-weight:600;color:var(--purple);"><?= (int) $cp['staff_count'] ?></span>
                                            <?php if ($cp['staff_names']): ?>
                                                <div style="font-size:.65rem;color:var(--gray-txt);max-width:100px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                                    title="<?= htmlspecialchars($cp['staff_names']) ?>">
                                                    <?= htmlspecialchars(mb_strimwidth($cp['staff_names'], 0, 18, '…')) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;font-weight:600;"><?= $cp['total_visits'] ?></td>
                                        <td style="text-align:center;">
                                            <span class="cd-chip chip-teal"><?= (int) $cp['visited'] ?></span>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ((int) $cp['missed'] > 0): ?>
                                                <span class="cd-chip chip-red"><?= (int) $cp['missed'] ?></span>
                                            <?php else: ?><span style="color:var(--gray-bd);">—</span><?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ((int) $cp['rescheduled'] > 0): ?>
                                                <span class="cd-chip chip-amber"><?= (int) $cp['rescheduled'] ?></span>
                                            <?php else: ?><span style="color:var(--gray-bd);">—</span><?php endif; ?>
                                        </td>
                                        <td
                                            style="text-align:center;color:var(--blue);font-weight:600;font-variant-numeric:tabular-nums;">
                                            <?= number_format((float) $cp['planned_hours'], 1) ?>h
                                        </td>
                                        <td style="text-align:center;">
                                            <strong
                                                style="color:<?= (float) $cp['actual_hours'] >= 4 ? '#1D9E75' : ((float) $cp['actual_hours'] >= 2 ? '#BA7517' : '#6b7280') ?>;font-variant-numeric:tabular-nums;">
                                                <?= number_format((float) $cp['actual_hours'], 1) ?>h
                                            </strong>
                                        </td>
                                        <td>
                                            <?php if ((float) $cp['planned_hours'] > 0): ?>
                                                <div class="cd-range-wrap">
                                                    <div class="cd-range-track">
                                                        <div class="cd-range-fill"
                                                            style="width:<?= $cEff ?>%;background:<?= $cEffCol ?>;"></div>
                                                    </div>
                                                    <span
                                                        style="font-size:.74rem;font-weight:700;color:<?= $cEffCol ?>;min-width:36px;text-align:right;"><?= $cEff ?>%</span>
                                                </div>
                                                <?php if ($cEffRaw > 100): ?>
                                                    <div style="font-size:.62rem;color:#f59e0b;margin-top:2px;">⚠ <?= $cEffRaw ?>% raw
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="font-size:.74rem;color:var(--gray-txt);">No plan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:.75rem;color:var(--gray-txt);white-space:nowrap;">
                                            <?= $cp['first_visit'] ? date('d M', strtotime($cp['first_visit'])) : '—' ?>
                                            <?php if ($cp['first_visit'] && $cp['last_visit'] && $cp['first_visit'] !== $cp['last_visit']): ?>
                                                <span style="color:var(--gray-bd);"> →
                                                </span><?= date('d M', strtotime($cp['last_visit'])) ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" style="color:var(--text-dark);">
                                        <i class="fas fa-calculator me-1" style="color:var(--gold)"></i>TOTAL
                                    </td>
                                    <td style="text-align:center;"><?= (int) ($kpi['total_logs'] ?? 0) ?></td>
                                    <td style="text-align:center;color:#1D9E75;"><?= (int) ($kpi['visited'] ?? 0) ?></td>
                                    <td style="text-align:center;color:#E24B4A;"><?= (int) ($kpi['missed'] ?? 0) ?></td>
                                    <td style="text-align:center;color:#BA7517;"><?= (int) ($kpi['rescheduled'] ?? 0) ?>
                                    </td>
                                    <td style="text-align:center;color:var(--blue);font-variant-numeric:tabular-nums;">
                                        <?= number_format(array_sum(array_column($clientPerf, 'planned_hours')), 1) ?>h
                                    </td>
                                    <td style="text-align:center;color:var(--gold);font-variant-numeric:tabular-nums;">
                                        <?= number_format($totalHours, 1) ?>h
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ══ MY OWN PERFORMANCE ════════════════════════════════════ -->
            <div class="card-mis mb-3" style="border-left:4px solid var(--gold);">
                <div class="card-mis-hd">
                    <span class="card-mis-title">
                        <i class="fas fa-user" style="color:var(--gold)"></i>
                        <?= $isAdmin ? 'Team Performance' : 'My Performance' ?> — <?= $monthLabel ?>
                    </span>
                </div>
                <div style="padding:16px;">
                    <div class="row g-3">
                        <?php
                        $myTotal = (int) ($mySelf['visited'] ?? 0) + (int) ($mySelf['missed'] ?? 0) + (int) ($mySelf['rescheduled'] ?? 0);
                        $myPct = $myTotal > 0 ? min(100, round(($mySelf['visited'] / $myTotal) * 100)) : 0;
                        $myCol = $myPct >= 80 ? '#1D9E75' : ($myPct >= 50 ? '#BA7517' : '#E24B4A');
                        foreach ([
                            ['fa-clock', 'var(--blue)', number_format((float) ($mySelf['hours'] ?? 0), 1) . 'h', 'Hours Logged'],
                            ['fa-list', 'var(--gold)', (int) ($mySelf['logs'] ?? 0), 'Log Entries'],
                            ['fa-building', 'var(--purple)', (int) ($mySelf['clients'] ?? 0), 'Clients'],
                            ['fa-check-circle', 'var(--teal)', (int) ($mySelf['visited'] ?? 0), 'Visited'],
                            ['fa-times-circle', 'var(--red)', (int) ($mySelf['missed'] ?? 0), 'Missed'],
                            ['fa-redo', 'var(--gold)', (int) ($mySelf['rescheduled'] ?? 0), 'Rescheduled'],
                        ] as [$ico, $col, $val, $lbl]):
                            ?>
                            <div class="col-6 col-md-2">
                                <div
                                    style="text-align:center;background:var(--gray-lt);border-radius:var(--radius-md);padding:.9rem .5rem;border:1px solid var(--gray-bd);">
                                    <i class="fas <?= $ico ?>"
                                        style="color:<?= $col ?>;font-size:1rem;margin-bottom:.3rem;display:block;"></i>
                                    <div
                                        style="font-size:1.2rem;font-weight:800;color:var(--text-dark);font-variant-numeric:tabular-nums;">
                                        <?= $val ?>
                                    </div>
                                    <div style="font-size:.68rem;color:var(--gray-txt);"><?= $lbl ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="col-12">
                            <div style="display:flex;align-items:center;gap:.5rem;margin-top:.25rem;">
                                <span style="font-size:.75rem;color:var(--gray-txt);min-width:80px;">Visit rate</span>
                                <div
                                    style="flex:1;background:var(--gray-mid);border-radius:99px;height:7px;overflow:hidden;">
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

            <!-- ══ DAY-WISE LOG ═══════════════════════════════════════════ -->
            <div class="card-mis mb-3">
                <div class="card-mis-hd">
                    <span class="card-mis-title">
                        <i class="fas fa-calendar-day" style="color:var(--gold)"></i>
                        My Day-wise Log — <?= $monthLabel ?>
                    </span>
                    <span class="card-mis-sub"><?= count($dayWiseLogs) ?> entries</span>
                </div>
                <div style="overflow-x:auto;" class="table-responsive cn-table-wrap">
                    <table class="cn-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Day</th>
                                <th>Client</th>
                                <th style="text-align:center;">Time In</th>
                                <th style="text-align:center;">Time Out</th>
                                <th style="text-align:center;">Hours</th>
                                <th>Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dayWiseLogs)): ?>
                                <tr>
                                    <td colspan="8"
                                        style="text-align:center;padding:28px;color:var(--gray-txt);font-size:.83rem;">
                                        <i class="fas fa-calendar-times"
                                            style="display:block;font-size:1.5rem;margin-bottom:6px;opacity:.4;"></i>
                                        No logs for selected range
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($dayWiseLogs as $dr): ?>
                                <tr>
                                    <td style="font-size:.83rem;font-weight:500;white-space:nowrap;">
                                        <?= date('d M Y', strtotime($dr['log_date'])) ?>
                                    </td>
                                    <td style="font-size:.75rem;color:var(--gray-txt);">
                                        <?= htmlspecialchars($dr['day_of_week'] ?? '') ?>
                                    </td>
                                    <td>
                                        <div style="font-size:.83rem;font-weight:500;">
                                            <?= htmlspecialchars($dr['company_name']) ?>
                                        </div>
                                        <div style="font-size:.68rem;color:var(--gray-txt);">
                                            <?= htmlspecialchars($dr['company_code'] ?? '') ?>
                                        </div>
                                    </td>
                                    <td style="text-align:center;font-size:.78rem;">
                                        <?= $dr['time_in'] ? date('g:i A', strtotime($dr['time_in'])) : '—' ?>
                                    </td>
                                    <td style="text-align:center;font-size:.78rem;">
                                        <?= $dr['time_out'] ? date('g:i A', strtotime($dr['time_out'])) : '—' ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <span
                                            style="font-weight:700;font-variant-numeric:tabular-nums;color:<?= (float) $dr['duration_hours'] >= 4 ? '#1D9E75' : ((float) $dr['duration_hours'] >= 2 ? '#BA7517' : '#E24B4A') ?>;">
                                            <?= number_format((float) $dr['duration_hours'], 1) ?>h
                                        </span>
                                    </td>
                                    <td><?= vstBadge($dr['visit_status'] ?? '') ?></td>
                                    <td
                                        style="font-size:.73rem;color:var(--gray-txt);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= htmlspecialchars(mb_strimwidth($dr['work_description'] ?? '—', 0, 45, '…')) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ══ RECENT LOGS ════════════════════════════════════════════ -->
            <div class="card-mis mb-3">
                <div class="card-mis-hd">
                    <span class="card-mis-title">
                        <i class="fas fa-history" style="color:var(--gold)"></i> Recent Logs
                    </span>
                    <a href="<?= $isAdmin ? 'log_list' : 'staff/log_list' ?>.php?month=<?= $month ?>" class="cd-btn">All
                        Logs</a>
                </div>
                <div style="overflow-x:auto;" class="table-responsive cn-table-wrap">
                    <table class="cn-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Client</th>
                                <?php if ($isAdmin): ?>
                                    <th>Staff</th><?php endif; ?>
                                <th style="text-align:center;">Time In</th>
                                <th style="text-align:center;">Hours</th>
                                <th>Status</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentLogs)): ?>
                                <tr>
                                    <td colspan="7"
                                        style="text-align:center;padding:24px;color:var(--gray-txt);font-size:.83rem;">
                                        <i class="fas fa-history"
                                            style="display:block;font-size:1.5rem;margin-bottom:6px;opacity:.3;"></i>
                                        No logs yet
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($recentLogs as $l): ?>
                                <tr>
                                    <td>
                                        <div style="font-size:.83rem;font-weight:500;white-space:nowrap;">
                                            <?= date('d M Y', strtotime($l['log_date'])) ?>
                                        </div>
                                        <div style="font-size:.68rem;color:var(--gray-txt);">
                                            <?= htmlspecialchars($l['day_of_week'] ?? '') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size:.83rem;font-weight:500;">
                                            <?= htmlspecialchars(mb_strimwidth($l['company_name'] ?? '—', 0, 20, '…')) ?>
                                        </div>
                                        <div style="font-size:.68rem;color:var(--gray-txt);">
                                            <?= htmlspecialchars($l['company_code'] ?? '') ?>
                                        </div>
                                    </td>
                                    <?php if ($isAdmin): ?>
                                        <td style="font-size:.78rem;">
                                            <?= htmlspecialchars(explode(' ', $l['staff_name'])[0] ?? '—') ?>
                                        </td>
                                    <?php endif; ?>
                                    <td style="text-align:center;font-size:.78rem;color:var(--gray-txt);">
                                        <?= $l['time_in'] ? date('g:i A', strtotime($l['time_in'])) : '—' ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <span
                                            style="font-weight:700;font-variant-numeric:tabular-nums;color:<?= (float) $l['duration_hours'] >= 4 ? '#1D9E75' : ((float) $l['duration_hours'] >= 2 ? '#BA7517' : '#E24B4A') ?>;">
                                            <?= number_format((float) $l['duration_hours'], 1) ?>h
                                        </span>
                                    </td>
                                    <td><?= vstBadge($l['visit_status'] ?? '') ?></td>
                                    <td
                                        style="font-size:.75rem;color:var(--gray-txt);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= htmlspecialchars(mb_strimwidth($l['work_description'] ?? '—', 0, 45, '…')) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /cd-wrap -->
        <?php include '../../includes/footer.php'; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT — all original chart logic preserved exactly
════════════════════════════════════════════════════════════ -->
<script>
    /* ── Tab switcher ─────────────────────────────────────────── */
    function cdTab(name, el) {
        document.querySelectorAll('.cd-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.cd-tab-pane').forEach(p => p.classList.remove('active'));
        el.classList.add('active');
        const pane = document.getElementById('cd-tab-' + name);
        if (pane) {
            pane.classList.add('active');
            setTimeout(() => Object.values(Chart.instances).forEach(c => c.resize()), 60);
        }
    }

    /* ── Original charts (untouched logic) ────────────────────── */
    new Chart(document.getElementById('trendChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_values($trendRows)) ?>.map(r => {
                const d = new Date(r.log_date + 'T00:00:00');
                return d.toLocaleDateString('en', { month: 'short', day: 'numeric' });
            }),
            datasets: [
                {
                    label: 'Hours',
                    data: <?= json_encode(array_values($trendRows)) ?>.map(r => parseFloat(r.hours) || 0),
                    backgroundColor: 'rgba(186,117,23,.2)', borderColor: '#BA7517',
                    borderWidth: 2, borderRadius: 4, yAxisID: 'y'
                },
                {
                    label: 'Visits', type: 'line',
                    data: <?= json_encode(array_values($trendRows)) ?>.map(r => parseInt(r.visits) || 0),
                    borderColor: '#378ADD', backgroundColor: 'rgba(55,138,221,.08)',
                    pointBackgroundColor: '#378ADD', pointRadius: 4, tension: .4, fill: true, yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
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
                backgroundColor: ['#1D9E75', '#E24B4A', '#BA7517'],
                borderWidth: 3, borderColor: '#fff', hoverOffset: 6
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '68%',
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}` } } }
        },
        plugins: [{
            id: 'centre',
            afterDraw(chart) {
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
                    label: 'Planned',
                    data: weeklyRows.map(r => parseFloat(weeklyPlanned[r.week_number] ?? 0)),
                    backgroundColor: 'rgba(55,138,221,.25)', borderColor: '#378ADD', borderWidth: 1.5, borderRadius: 4
                },
                {
                    label: 'Actual',
                    data: weeklyRows.map(r => parseFloat(r.actual_hours) || 0),
                    backgroundColor: 'rgba(186,117,23,.5)', borderColor: '#BA7517', borderWidth: 1.5, borderRadius: 4
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
                        backgroundColor: 'rgba(127,119,221,.65)', borderColor: '#7F77DD', borderWidth: 1.5, borderRadius: 5
                    },
                    {
                        label: 'Visits', data: <?= json_encode($topClientVisits) ?>,
                        backgroundColor: 'rgba(186,117,23,.4)', borderColor: '#BA7517', borderWidth: 1.5, borderRadius: 5
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

    /* ── Tom Select ────────────────────────────────────────────── */
    const clientTsIdx = new TomSelect('#filterClient', {
        placeholder: 'Search by name, code or PAN…',
        allowEmptyOption: true,
        maxOptions: 500,
        searchField: ['text'],
        score: function (search) {
            const s = search.toLowerCase();
            return function (item) {
                const text = (item.text || '').toLowerCase();
                const code = (item.$option?.dataset?.code || '').toLowerCase();
                const pan = (item.$option?.dataset?.pan || '').toLowerCase();
                return (text.includes(s) || code.includes(s) || pan.includes(s)) ? 1 : 0;
            };
        },
        render: {
            option: function (data, escape) {
                const code = data.$option?.dataset?.code || '';
                const pan = data.$option?.dataset?.pan || '';
                const name = escape(data.text.split(' — ')[0]);
                return `<div style="padding:3px 2px;">
                <div style="font-weight:600;font-size:.83rem;">${name}</div>
                <div style="font-size:.7rem;color:#9ca3af;display:flex;gap:8px;margin-top:1px;">
                    ${code ? `<span><i class="fas fa-tag" style="font-size:.6rem;"></i> ${escape(code)}</span>` : ''}
                    ${pan ? `<span><i class="fas fa-id-card" style="font-size:.6rem;"></i> PAN: ${escape(pan)}</span>` : ''}
                </div>
            </div>`;
            },
            item: function (data, escape) {
                const pan = data.$option?.dataset?.pan || '';
                const name = escape(data.text.split(' — ')[0]);
                return pan
                    ? `<div>${name} <span style="font-size:.7rem;color:#9ca3af;">(PAN: ${escape(pan)})</span></div>`
                    : `<div>${name}</div>`;
            }
        },
        onChange: function () { applyFilters(); }
    });

    /* ── Executive charts (original logic, updated color vars) ── */
    const EX_LABELS = <?= $jsLabels ?>;
    const EX_FIELD_HOURS = <?= $jsFieldHours ?>;
    const EX_FIELD_VISIT = <?= $jsFieldVisit ?>;
    const EX_FIELD_MISS = <?= $jsFieldMiss ?>;
    const EX_OFFICE_HOURS = <?= $jsOfficeHours ?>;

    const exBase = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } };

    new Chart(document.getElementById('exDonutVisit'), {
        type: 'doughnut',
        data: {
            labels: ['Visited', 'Missed', 'Rescheduled'],
            datasets: [{
                data: [<?= $visitedCnt ?>, <?= $missedCnt ?>, <?= $rescheduledCnt ?>],
                backgroundColor: ['#1D9E75', '#E24B4A', '#BA7517'],
                borderWidth: 3, borderColor: '#fff', hoverOffset: 4
            }]
        },
        options: { ...exBase, cutout: '76%' }
    });

    new Chart(document.getElementById('exOfficeDonut'), {
        type: 'doughnut',
        data: {
            labels: ['Completed', 'WIP', 'Holding', 'Not Started'],
            datasets: [{
                data: [<?= $offCompleted ?>, <?= $offWip ?>, <?= $offHolding ?>, <?= $offNotStart ?>],
                backgroundColor: ['#1D9E75', '#BA7517', '#7F77DD', '#d1d5db'],
                borderWidth: 3, borderColor: '#fff', hoverOffset: 4
            }]
        },
        options: { ...exBase, cutout: '76%' }
    });

    new Chart(document.getElementById('exTrendMini'), {
        type: 'bar',
        data: {
            labels: EX_LABELS,
            datasets: [
                { label: 'Field hrs', data: EX_FIELD_HOURS, backgroundColor: 'rgba(55,138,221,.2)', borderColor: '#378ADD', borderWidth: 1.5, borderRadius: 4 },
                { label: 'Office hrs', data: EX_OFFICE_HOURS, backgroundColor: 'rgba(29,158,117,.15)', borderColor: '#1D9E75', borderWidth: 1.5, borderRadius: 4 },
            ]
        },
        options: {
            ...exBase,
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#9ca3af' } },
                y: { grid: { color: 'rgba(0,0,0,.04)' }, ticks: { font: { size: 10 }, color: '#9ca3af', callback: v => v + 'h' }, beginAtZero: true }
            }
        }
    });

    new Chart(document.getElementById('exTrendFull'), {
        type: 'line',
        data: {
            labels: EX_LABELS,
            datasets: [
                { label: 'Field hrs', data: EX_FIELD_HOURS, borderColor: '#378ADD', backgroundColor: 'rgba(55,138,221,.1)', fill: true, tension: .35, pointRadius: 4, pointBackgroundColor: '#378ADD', borderWidth: 2 },
                { label: 'Office hrs', data: EX_OFFICE_HOURS, borderColor: '#1D9E75', backgroundColor: 'rgba(29,158,117,.08)', fill: true, tension: .35, pointRadius: 4, pointBackgroundColor: '#1D9E75', borderWidth: 2, borderDash: [5, 3] },
                { label: 'Missed', data: EX_FIELD_MISS, borderColor: '#E24B4A', backgroundColor: 'transparent', fill: false, tension: .35, pointRadius: 4, pointBackgroundColor: '#E24B4A', borderWidth: 1.5, borderDash: [3, 3] },
            ]
        },
        options: {
            ...exBase,
            scales: {
                x: { grid: { color: 'rgba(0,0,0,.03)' }, ticks: { font: { size: 11 }, color: '#9ca3af' } },
                y: { grid: { color: 'rgba(0,0,0,.04)' }, ticks: { font: { size: 11 }, color: '#9ca3af', callback: v => v + 'h' }, beginAtZero: true }
            },
            plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } }
        }
    });

    window.addEventListener('load', () => {
        Object.values(Chart.instances).forEach(c => c.resize());
    });

    function applyFilters() {
        const month = document.getElementById('filterMonth').value;
        const client = clientTsIdx.getValue();
        const from = document.getElementById('filterFrom').value;
        const to = document.getElementById('filterTo').value;
        const p = new URLSearchParams({ month });
        if (client) p.set('client_id', client);
        if (from) p.set('from', from);
        if (to) p.set('to', to);
        location.href = 'index.php?' + p.toString();
    }
</script>