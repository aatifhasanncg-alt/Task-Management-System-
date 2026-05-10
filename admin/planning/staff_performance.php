<?php
/**
 * admin/planning/staff_performance.php
 * Staff Performance Report
 * Scope: login user (self) + staff where managed_by = $uid (primary OR via UDA)
 *        filtered to CON dept — no plan_entry_id references
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';

$db       = getDB();
$user     = currentUser();
$uid      = (int) $user['id'];
$branchId = (int) ($user['branch_id']     ?? 0);
$deptId   = (int) ($user['department_id'] ?? 0);

/* ── Resolve consulting dept via primary or UDA ───────────────── */
$__dm = $db->prepare("SELECT dept_code, dept_name FROM departments WHERE id=?");
$__dm->execute([$user['department_id']]);
$__deptMeta      = $__dm->fetch(PDO::FETCH_ASSOC);
$__primaryCode   = $__deptMeta['dept_code'] ?? '';
$__isConsPrimary = ($__primaryCode === 'CON' || stripos($__deptMeta['dept_name'] ?? '', 'consult') !== false);
$__isCoreAdmin   = ($__primaryCode === 'CORE');

$__udaQ = $db->prepare("
    SELECT d.id, d.dept_code FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ? AND (d.dept_code = 'CON' OR d.dept_name LIKE '%consult%')
    LIMIT 1
");
$__udaQ->execute([$uid]);
$__udaCons = $__udaQ->fetch(PDO::FETCH_ASSOC);

if ($__isConsPrimary)                 $deptId = (int) $user['department_id'];
elseif ($__isCoreAdmin && $__udaCons) $deptId = (int) $__udaCons['id'];
elseif ($__udaCons)                   $deptId = (int) $__udaCons['id'];

/* ── Month / filters ─────────────────────────────────────────── */
$now        = new DateTime();
$month      = $_GET['month'] ?? $now->format('Y-m');
$monthDate  = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthEnd   = $monthDate->format('Y-m-t');
$monthLabel = $monthDate->format('F Y');

$filterStaffId = (int) ($_GET['staff_id'] ?? 0) ?: null;
$filterFrom    = $_GET['from'] ?? $monthStart;
$filterTo      = $_GET['to']   ?? $monthEnd;
$filterFrom    = max($filterFrom, $monthStart);
$filterTo      = min($filterTo,   $monthEnd);

/* ── Department name ─────────────────────────────────────────── */
$deptNameStmt = $db->prepare("SELECT dept_name FROM departments WHERE id=?");
$deptNameStmt->execute([$deptId]);
$deptName = $deptNameStmt->fetchColumn() ?: 'Consulting';

/* ── Unread notifications ────────────────────────────────────── */
$notifCount = 0;
try {
    $notifCount = (int) $db->query("
        SELECT COUNT(*) FROM plan_notifications
        WHERE user_id={$uid} AND is_read=0
    ")->fetchColumn();
} catch (Exception $e) { $notifCount = 0; }

/* ══════════════════════════════════════════════════════════════
   SCOPE: self + staff managed_by login user (primary OR UDA)
          restricted to CON dept
══════════════════════════════════════════════════════════════ */
$scopeStmt = $db->prepare("
    SELECT DISTINCT u.id, u.full_name, u.employee_id, u.department_id,
           COALESCE(d.dept_name, '—') AS dept_name
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    LEFT JOIN departments d1 ON d1.id = u.department_id
    LEFT JOIN departments d2 ON d2.id = uda.department_id
    WHERE u.is_active = 1
      AND (
          /* always include self */
          u.id = ?
          OR (
              /* directly managed by login user */
              u.managed_by = ?
              AND (
                  (d1.dept_code = 'CON' OR d1.dept_name LIKE '%consult%')
                  OR (d2.dept_code = 'CON' OR d2.dept_name LIKE '%consult%')
              )
          )
          OR (
              /* managed via UDA row */
              uda.managed_by = ?
              AND (d2.dept_code = 'CON' OR d2.dept_name LIKE '%consult%')
          )
      )
    ORDER BY u.full_name
");
$scopeStmt->execute([$uid, $uid, $uid]);
$scopeStaff = $scopeStmt->fetchAll(PDO::FETCH_ASSOC);
$scopeIds   = array_unique(array_map('intval', array_column($scopeStaff, 'id')));
if (!in_array($uid, $scopeIds)) $scopeIds[] = $uid;
$inList = implode(',', $scopeIds) ?: '0';

$activeInList = $inList;
if ($filterStaffId && in_array($filterStaffId, $scopeIds))
    $activeInList = (string) $filterStaffId;

/* ══════════════════════════════════════════════════════════════
   A. FIELD KPIs (work_logs)
══════════════════════════════════════════════════════════════ */
$fieldKpi = $db->query("
    SELECT
        COUNT(*)                          AS total_logs,
        COALESCE(SUM(duration_hours), 0)  AS total_hours,
        SUM(visit_status='visited')       AS visited,
        SUM(visit_status='missed')        AS missed,
        SUM(visit_status='rescheduled')   AS rescheduled,
        COUNT(DISTINCT client_id)         AS unique_clients,
        COUNT(DISTINCT user_id)           AS active_staff
    FROM work_logs
    WHERE month_year='{$month}' AND user_id IN ({$activeInList})
")->fetch(PDO::FETCH_ASSOC);

/* ══════════════════════════════════════════════════════════════
   B. OFFICE KPIs (office_work_logs)
══════════════════════════════════════════════════════════════ */
$officeKpi = $db->query("
    SELECT
        COUNT(*)                                                       AS total_logs,
        COALESCE(SUM(TIMESTAMPDIFF(MINUTE,time_in,time_out)/60.0), 0) AS total_hours,
        SUM(status='completed')                                        AS completed,
        SUM(status='wip')                                              AS wip,
        SUM(status='holding')                                          AS holding,
        SUM(status='not_started')                                      AS not_started,
        COUNT(DISTINCT client_id)                                      AS unique_clients
    FROM office_work_logs
    WHERE log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
      AND user_id IN ({$activeInList})
")->fetch(PDO::FETCH_ASSOC);
$officeHours = round((float) ($officeKpi['total_hours'] ?? 0), 1);

/* ── Plan counts ─────────────────────────────────────────────── */
$pk = $db->query("
    SELECT
        COUNT(*)                AS total_plans,
        SUM(status='draft')     AS draft,
        SUM(status='submitted') AS submitted,
        SUM(status='approved')  AS approved,
        SUM(status='rejected')  AS rejected
    FROM work_plans
    WHERE plan_month='{$monthStart}' AND user_id IN ({$activeInList})
")->fetch(PDO::FETCH_ASSOC);

/* ── Planned vs Actual hours ─────────────────────────────────── */
$teamPlanned = (float) $db->query("
    SELECT COALESCE(SUM(wpe.planned_hours), 0)
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    WHERE wp.plan_month='{$monthStart}' AND wpe.assigned_to IN ({$activeInList})
")->fetchColumn();

$teamActual = (float) $db->query("
    SELECT COALESCE(SUM(duration_hours), 0)
    FROM work_logs
    WHERE month_year='{$month}' AND user_id IN ({$activeInList})
")->fetchColumn();

/* ── Match efficiency (plan_entry_id dropped — match by client+date+user) ── */
$teamMatch = $db->query("
    SELECT
        COUNT(DISTINCT wpe.id)                                    AS planned_count,
        COUNT(DISTINCT CASE
            WHEN wl.client_id = wpe.client_id
             AND wl.log_date  = wpe.plan_date
             AND wl.user_id   = wpe.assigned_to
            THEN wpe.id END)                                      AS matched_count,
        COALESCE(SUM(wpe.planned_hours), 0)                      AS planned_hrs
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    LEFT JOIN work_logs wl
        ON  wl.client_id = wpe.client_id
        AND wl.log_date  = wpe.plan_date
        AND wl.user_id   = wpe.assigned_to
    WHERE wp.plan_month='{$monthStart}' AND wpe.assigned_to IN ({$activeInList})
")->fetch(PDO::FETCH_ASSOC);

$plannedCount = (int)   ($teamMatch['planned_count'] ?? 0);
$matchedCount = (int)   ($teamMatch['matched_count'] ?? 0);
$plannedHrs   = (float) ($teamMatch['planned_hrs']   ?? 0);

$visitEffRaw  = $plannedCount > 0 ? round(($matchedCount / $plannedCount) * 100, 1) : 0;
$visitEff     = min($visitEffRaw, 100);
$hourEffRaw   = $plannedHrs   > 0 ? round(($teamActual   / $plannedHrs)   * 100, 1) : 0;
$hourEff      = min($hourEffRaw, 100);
$effColor     = $visitEff >= 80 ? '#10b981' : ($visitEff >= 50 ? '#f59e0b' : '#ef4444');
$hourEffColor = $hourEff  >= 80 ? '#10b981' : ($hourEff  >= 50 ? '#f59e0b' : '#ef4444');

/* ══════════════════════════════════════════════════════════════
   C. PER-STAFF ROWS (field + office combined)
      Match: client_id + plan_date + user_id (no plan_entry_id)
══════════════════════════════════════════════════════════════ */
$staffPerfRows = [];
if (!empty($scopeIds)) {
    $staffPerfRows = $db->query("
        SELECT
            u.id, u.full_name, u.employee_id, u.department_id,
            COALESCE(
                GROUP_CONCAT(DISTINCT d_all.dept_name ORDER BY d_all.dept_name SEPARATOR ', '),
                'No Dept'
            )                                           AS dept_label,
            COALESCE(SUM(wl.duration_hours), 0)         AS hours,
            COUNT(wl.id)                                AS logs,
            SUM(wl.visit_status='visited')              AS visited,
            SUM(wl.visit_status='missed')               AS missed,
            SUM(wl.visit_status='rescheduled')          AS rescheduled,
            COUNT(DISTINCT wl.client_id)                AS clients,
            COUNT(DISTINCT wl.log_date)                 AS active_days,
            MAX(wl.log_date)                            AS last_log_date,
            /* planned visit count */
            (SELECT COUNT(DISTINCT wpe2.id)
             FROM work_plan_entries wpe2
             JOIN work_plans wp2 ON wp2.id = wpe2.plan_id
             WHERE wpe2.assigned_to = u.id
               AND wp2.plan_month   = '{$monthStart}')  AS planned_visits,
            /* matched: client+date+user */
            (SELECT COUNT(DISTINCT CASE
                 WHEN wl2.client_id = wpe2.client_id
                  AND wl2.log_date  = wpe2.plan_date
                 THEN wpe2.id END)
             FROM work_plan_entries wpe2
             JOIN work_plans wp2 ON wp2.id = wpe2.plan_id
             LEFT JOIN work_logs wl2
                 ON  wl2.client_id = wpe2.client_id
                 AND wl2.log_date  = wpe2.plan_date
                 AND wl2.user_id   = wpe2.assigned_to
             WHERE wpe2.assigned_to = u.id
               AND wp2.plan_month   = '{$monthStart}')  AS matched_visits,
            /* planned hours */
            (SELECT COALESCE(SUM(wpe3.planned_hours), 0)
             FROM work_plan_entries wpe3
             JOIN work_plans wp3 ON wp3.id = wpe3.plan_id
             WHERE wpe3.assigned_to = u.id
               AND wp3.plan_month   = '{$monthStart}')  AS planned_hours,
            /* plan statuses */
            (SELECT COUNT(*) FROM work_plans
             WHERE user_id=u.id AND plan_month='{$monthStart}' AND status='approved')  AS plans_approved,
            (SELECT COUNT(*) FROM work_plans
             WHERE user_id=u.id AND plan_month='{$monthStart}' AND status='submitted') AS plans_submitted,
            (SELECT COUNT(*) FROM work_plans
             WHERE user_id=u.id AND plan_month='{$monthStart}' AND status='draft')     AS plans_draft,
            (SELECT COUNT(*) FROM work_plans
             WHERE user_id=u.id AND plan_month='{$monthStart}' AND status='rejected')  AS plans_rejected,
            /* office work */
            (SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE,owl.time_in,owl.time_out)/60.0), 0)
             FROM office_work_logs owl
             WHERE owl.user_id = u.id
               AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}')             AS office_hours,
            (SELECT COUNT(*)
             FROM office_work_logs owl
             WHERE owl.user_id = u.id
               AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}')             AS office_logs,
            (SELECT COUNT(*)
             FROM office_work_logs owl
             WHERE owl.user_id = u.id
               AND owl.log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
               AND owl.status  = 'completed')                                          AS office_completed
        FROM users u
        LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
        LEFT JOIN departments d_all ON (
            d_all.id = u.department_id OR d_all.id = uda.department_id
        )
        LEFT JOIN work_logs wl
            ON  wl.user_id   = u.id
            AND wl.month_year = '{$month}'
            AND wl.log_date  BETWEEN '{$filterFrom}' AND '{$filterTo}'
        WHERE u.id IN ({$activeInList}) AND u.is_active = 1
        GROUP BY u.id, u.full_name, u.employee_id, u.department_id
        ORDER BY hours DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/* ══════════════════════════════════════════════════════════════
   D. DAILY TREND
══════════════════════════════════════════════════════════════ */
$trendRows = $db->query("
    SELECT log_date,
           COALESCE(SUM(duration_hours), 0) AS hours,
           COUNT(*)                          AS visits,
           COUNT(DISTINCT user_id)           AS active_staff
    FROM work_logs
    WHERE log_date BETWEEN '{$monthStart}' AND '{$monthEnd}'
      AND user_id IN ({$activeInList})
    GROUP BY log_date ORDER BY log_date ASC
    LIMIT 31
")->fetchAll(PDO::FETCH_ASSOC);

/* ══════════════════════════════════════════════════════════════
   E. RECENT FIELD LOGS
══════════════════════════════════════════════════════════════ */
$recentSQL = "
    SELECT wl.log_date, wl.day_of_week, wl.time_in, wl.time_out,
           wl.duration_hours, wl.visit_status, wl.work_description,
           c.company_name, c.company_code,
           u.full_name AS staff_name
    FROM work_logs wl
    JOIN companies c ON c.id = wl.client_id
    JOIN users u     ON u.id = wl.user_id
    WHERE wl.month_year = '{$month}'
      AND wl.user_id IN ({$activeInList})
      AND wl.log_date BETWEEN '{$filterFrom}' AND '{$filterTo}'
";
if ($filterStaffId) $recentSQL .= " AND wl.user_id=" . (int) $filterStaffId;
$recentSQL .= " ORDER BY wl.log_date DESC, wl.created_at DESC LIMIT 15";
$recentLogs = $db->query($recentSQL)->fetchAll(PDO::FETCH_ASSOC);

/* ══════════════════════════════════════════════════════════════
   F. RECENT OFFICE LOGS
══════════════════════════════════════════════════════════════ */
$recentOffSQL = "
    SELECT owl.log_date, owl.time_in, owl.time_out, owl.description,
           owl.status,
           TIMESTAMPDIFF(MINUTE,owl.time_in,owl.time_out)/60.0 AS hrs,
           c.company_name, c.company_code,
           u.full_name AS staff_name, u.employee_id
    FROM office_work_logs owl
    JOIN companies c ON c.id = owl.client_id
    JOIN users u     ON u.id = owl.user_id
    WHERE owl.log_date BETWEEN '{$filterFrom}' AND '{$filterTo}'
      AND owl.user_id IN ({$activeInList})
";
if ($filterStaffId) $recentOffSQL .= " AND owl.user_id=" . (int) $filterStaffId;
$recentOffSQL .= " ORDER BY owl.log_date DESC, owl.time_in DESC LIMIT 10";
$recentOffice = $db->query($recentOffSQL)->fetchAll(PDO::FETCH_ASSOC);

/* ── Helpers ─────────────────────────────────────────────────── */
function vstBadgeSP(string $s): string {
    $map = [
        'visited'     => ['#ecfdf5','#10b981','fa-check-circle','Visited'],
        'missed'      => ['#fef2f2','#ef4444','fa-times-circle','Missed'],
        'rescheduled' => ['#fffbeb','#f59e0b','fa-redo',        'Rescheduled'],
    ];
    [$bg,$col,$ico,$lbl] = $map[$s] ?? ['#f9fafb','#9ca3af','fa-circle','—'];
    return "<span style='background:{$bg};color:{$col};padding:.15rem .55rem;border-radius:99px;
            font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;'>
            <i class=\"fas {$ico}\" style=\"font-size:.6rem;\"></i>{$lbl}</span>";
}
function planBadgeSP(int $a, int $s, int $d, int $r): string {
    if ($a) return "<span style='background:#ecfdf5;color:#10b981;padding:.12rem .5rem;border-radius:99px;font-size:.7rem;font-weight:600;'>✓ Approved</span>";
    if ($s) return "<span style='background:#eff6ff;color:#3b82f6;padding:.12rem .5rem;border-radius:99px;font-size:.7rem;font-weight:600;'>⟳ Submitted</span>";
    if ($d) return "<span style='background:#f9fafb;color:#9ca3af;padding:.12rem .5rem;border-radius:99px;font-size:.7rem;font-weight:600;'>✏ Draft</span>";
    if ($r) return "<span style='background:#fef2f2;color:#ef4444;padding:.12rem .5rem;border-radius:99px;font-size:.7rem;font-weight:600;'>✕ Rejected</span>";
    return "<span style='color:#d1d5db;font-size:.75rem;'>No Plan</span>";
}
function offStatusBadge(string $s): string {
    $map = [
        'completed'   => ['#f0fdf4','#15803d','Completed'],
        'wip'         => ['#fffbeb','#b45309','WIP'],
        'holding'     => ['#f5f3ff','#6d28d9','Holding'],
        'not_started' => ['#f9fafb','#9ca3af','Not Started'],
    ];
    [$bg,$col,$lbl] = $map[$s] ?? $map['not_started'];
    return "<span style='background:{$bg};color:{$col};padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:600;'>{$lbl}</span>";
}

$pageTitle = 'Staff Performance Report';
include '../../includes/header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

<div class="app-wrapper">
<?php include '../../includes/sidebar_admin.php'; ?>
<div class="main-content">
<?php include '../../includes/topbar.php'; ?>
<div style="padding:1.5rem 0;">
<?= flashHtml() ?>

<!-- ══ HERO ══════════════════════════════════════════════════ -->
<div class="page-hero mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="page-hero-badge">
                <i class="fas fa-users"></i> Staff Performance
                <?php if ($notifCount > 0): ?>
                    <span style="background:#ef4444;color:#fff;border-radius:99px;padding:.05rem .42rem;font-size:.65rem;font-weight:700;margin-left:.35rem;"><?= $notifCount ?></span>
                <?php endif; ?>
            </div>
            <h4>Staff Performance Report</h4>
            <p><?= htmlspecialchars($user['full_name']) ?> · <?= htmlspecialchars($deptName) ?> · <?= $monthLabel ?>
                <span style="font-size:.72rem;background:#f3f4f6;border-radius:99px;padding:.1rem .55rem;margin-left:.35rem;"><?= count($scopeStaff) ?> staff</span>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <input type="month" class="form-control form-control-sm" style="width:150px;"
                   value="<?= $month ?>" onchange="location='?month='+this.value">
            <a href="plan_approvals.php" class="btn btn-outline-secondary btn-sm position-relative">
                <i class="fas fa-check-circle me-1"></i>Approvals
                <?php if ($notifCount > 0): ?>
                    <span style="position:absolute;top:-5px;right:-5px;background:#ef4444;color:#fff;border-radius:50%;width:16px;height:16px;font-size:.6rem;font-weight:700;display:flex;align-items:center;justify-content:center;"><?= $notifCount ?></span>
                <?php endif; ?>
            </a>
            <a href="client_report.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-building me-1"></i>Client Report
            </a>
            <a href="../../index.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-th-large me-1"></i>Dashboard
            </a>
            <a href="<?= APP_URL ?>/exports/export_pdf.php?module=consulting_performance&view=monthly&month=<?= urlencode($month) ?>&staff_id=<?= $filterStaffId ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-file-pdf me-1" style="color:#ef4444;"></i>PDF
            </a>
            <a href="<?= APP_URL ?>/exports/export_excel.php?module=consulting_performance&view=monthly&month=<?= urlencode($month) ?>&staff_id=<?= $filterStaffId ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-file-excel me-1" style="color:#10b981;"></i>Excel
            </a>
        </div>
    </div>
</div>

<!-- ══ FILTERS ═══════════════════════════════════════════════ -->
<div class="card-mis mb-4">
    <div class="card-mis-body" style="padding:.75rem 1rem;">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label-mis">Month</label>
                <input type="month" id="filterMonth" class="form-control form-control-sm"
                       value="<?= $month ?>" onchange="applyFilters()">
            </div>
            <div class="col-md-3">
                <label class="form-label-mis">Staff Member</label>
                <select id="filterStaff" class="form-select form-select-sm" onchange="applyFilters()">
                    <option value="">— All Staff —</option>
                    <?php foreach ($scopeStaff as $sf): ?>
                        <option value="<?= $sf['id'] ?>" <?= $filterStaffId == $sf['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sf['full_name']) ?>
                            <?= $sf['id'] == $uid ? ' (You)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label-mis">Date Range</label>
                <div class="input-group input-group-sm">
                    <input type="date" id="filterFrom" class="form-control" value="<?= $filterFrom ?>" onchange="applyFilters()">
                    <input type="date" id="filterTo"   class="form-control" value="<?= $filterTo ?>"   onchange="applyFilters()">
                </div>
            </div>
            <div class="col-md-3">
                <a href="staff_performance.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm w-100">
                    <i class="fas fa-times me-1"></i>Clear Filters
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ══ KPI CARDS — FIELD ═════════════════════════════════════ -->
<div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;">
    <i class="fas fa-car" style="color:#c9a84c;margin-right:.3rem;"></i>Field Visits
</div>
<div class="row g-3 mb-3">
<?php
$totalHours = (float) ($fieldKpi['total_hours'] ?? 0);
$kpiCards = [
    ['fa-users',         '#8b5cf6','#f5f3ff', 'Active Staff',    (int)($fieldKpi['active_staff']   ??0)],
    ['fa-clock',         '#3b82f6','#eff6ff', 'Field Hours',     number_format($totalHours,1).'h'],
    ['fa-check-circle',  '#10b981','#ecfdf5', 'Visited',         (int)($fieldKpi['visited']        ??0)],
    ['fa-times-circle',  '#ef4444','#fef2f2', 'Missed',          (int)($fieldKpi['missed']         ??0)],
    ['fa-redo',          '#f59e0b','#fffbeb', 'Rescheduled',     (int)($fieldKpi['rescheduled']    ??0)],
    ['fa-building',      '#0ea5e9','#e0f2fe', 'Clients Served',  (int)($fieldKpi['unique_clients'] ??0)],
    ['fa-tachometer-alt',$effColor,'#f9fafb', 'Visit Efficiency',$visitEff.'%'],
    ['fa-calendar-check','#10b981','#ecfdf5', 'Plans Approved',  (int)($pk['approved']??0).'/'.(int)($pk['total_plans']??0)],
];
foreach ($kpiCards as [$icon,$col,$bg,$lbl,$val]): ?>
<div class="col-6 col-md-3">
    <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;padding:1rem 1.1rem;display:flex;align-items:center;gap:.8rem;box-shadow:0 1px 3px rgba(0,0,0,.04);">
        <div style="width:40px;height:40px;border-radius:10px;background:<?=$bg?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas <?=$icon?>" style="color:<?=$col?>;font-size:.95rem;"></i>
        </div>
        <div>
            <div style="font-size:1.35rem;font-weight:800;color:#1f2937;line-height:1.1;"><?=$val?></div>
            <div style="font-size:.7rem;color:#9ca3af;margin-top:.1rem;"><?=$lbl?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ══ KPI CARDS — OFFICE ════════════════════════════════════ -->
<div style="font-size:.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;margin-top:.25rem;">
    <i class="fas fa-laptop" style="color:#3b82f6;margin-right:.3rem;"></i>Office Work
</div>
<div class="row g-3 mb-4">
<?php
$offKpiCards = [
    ['fa-file-alt',    '#3b82f6','#eff6ff', 'Office Logs',      (int)($officeKpi['total_logs']    ??0)],
    ['fa-clock',       '#c9a84c','#fefce8', 'Office Hours',     number_format($officeHours,1).'h'],
    ['fa-check-double','#10b981','#ecfdf5', 'Completed',        (int)($officeKpi['completed']     ??0)],
    ['fa-spinner',     '#f59e0b','#fffbeb', 'WIP',              (int)($officeKpi['wip']           ??0)],
    ['fa-pause-circle','#8b5cf6','#f5f3ff', 'Holding',          (int)($officeKpi['holding']       ??0)],
    ['fa-circle',      '#9ca3af','#f9fafb', 'Not Started',      (int)($officeKpi['not_started']   ??0)],
    ['fa-building',    '#0ea5e9','#e0f2fe', 'Clients (Office)', (int)($officeKpi['unique_clients']??0)],
];
foreach ($offKpiCards as [$icon,$col,$bg,$lbl,$val]): ?>
<div class="col-6 col-md-3 col-lg-2">
    <div style="background:#fff;border-radius:12px;border:1px solid #f3f4f6;padding:.85rem 1rem;display:flex;align-items:center;gap:.65rem;box-shadow:0 1px 3px rgba(0,0,0,.04);">
        <div style="width:36px;height:36px;border-radius:9px;background:<?=$bg?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas <?=$icon?>" style="color:<?=$col?>;font-size:.88rem;"></i>
        </div>
        <div>
            <div style="font-size:1.2rem;font-weight:800;color:#1f2937;line-height:1.1;"><?=$val?></div>
            <div style="font-size:.67rem;color:#9ca3af;margin-top:.1rem;"><?=$lbl?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ══ PLANNED vs ACTUAL ═════════════════════════════════════ -->
<div class="card-mis mb-4">
    <div class="card-mis-header">
        <h5><i class="fas fa-chart-bar text-warning me-2"></i>Team Planned vs Actual — <?= $monthLabel ?></h5>
        <div style="display:flex;gap:14px;font-size:.75rem;">
            <span>Visit eff: <strong style="color:<?=$effColor?>"><?=$visitEff?>%</strong></span>
            <span>Hour eff:  <strong style="color:<?=$hourEffColor?>"><?=$hourEff?>%</strong></span>
        </div>
    </div>
    <div class="card-mis-body">
        <?php $maxH = max($plannedHrs, $teamActual, $officeHours, 1); ?>
        <?php foreach ([
            ['Planned Field', $plannedHrs,  $maxH, '#3b82f6'],
            ['Actual Field',  $teamActual,  $maxH, $hourEffColor],
            ['Office Hours',  $officeHours, $maxH, '#10b981'],
        ] as [$lbl,$val,$base,$col]):
            $w = $base > 0 ? min(100, round($val / $base * 100)) : 0; ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
            <span style="font-size:.76rem;color:#9ca3af;min-width:88px;"><?=$lbl?></span>
            <div style="flex:1;background:#f3f4f6;border-radius:99px;height:8px;overflow:hidden;">
                <div style="width:<?=$w?>%;background:<?=$col?>;height:100%;border-radius:99px;"></div>
            </div>
            <span style="font-size:.8rem;font-weight:700;color:<?=$col?>;min-width:50px;text-align:right;"><?=number_format($val,1)?>h</span>
        </div>
        <?php endforeach; ?>
        <div style="display:flex;gap:1.5rem;margin-top:.9rem;flex-wrap:wrap;padding-top:.75rem;border-top:1px solid #f3f4f6;">
            <?php foreach ([
                ['Planned Visits', $plannedCount,                    '#3b82f6'],
                ['Matched',        $matchedCount,                    '#10b981'],
                ['Unmatched',      max(0,$plannedCount-$matchedCount),'#ef4444'],
                ['Visit Eff.',     $visitEff.'%',                   $effColor],
                ['Hour Eff.',      $hourEff.'%',                    $hourEffColor],
            ] as [$lbl,$val,$col]): ?>
            <div style="font-size:.75rem;display:flex;flex-direction:column;gap:1px;">
                <span style="color:#9ca3af;"><?=$lbl?></span>
                <strong style="color:<?=$col?>;"><?=$val?></strong>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ══ CHARTS ROW ════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card-mis h-100">
            <div class="card-mis-header">
                <h5><i class="fas fa-chart-line text-warning me-2"></i>Daily Team Trend — <?=$monthLabel?></h5>
            </div>
            <div class="card-mis-body" style="height:260px;">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-2">
        <div class="card-mis h-100">
            <div class="card-mis-header"><h5><i class="fas fa-chart-pie text-warning me-2"></i>Status</h5></div>
            <div class="card-mis-body d-flex flex-column align-items-center">
                <div style="height:150px;width:100%;position:relative;"><canvas id="statusChart"></canvas></div>
                <div style="width:100%;margin-top:.5rem;">
                    <?php foreach ([
                        ['Visited',     $fieldKpi['visited']??0,     '#10b981'],
                        ['Missed',      $fieldKpi['missed']??0,      '#ef4444'],
                        ['Rescheduled', $fieldKpi['rescheduled']??0, '#f59e0b'],
                    ] as [$lbl,$cnt,$col]): ?>
                    <div style="display:flex;justify-content:space-between;padding:.18rem 0;font-size:.72rem;border-bottom:1px solid #f3f4f6;">
                        <div style="display:flex;align-items:center;gap:.35rem;">
                            <div style="width:8px;height:8px;border-radius:50%;background:<?=$col?>;"></div>
                            <span style="color:#374151;"><?=$lbl?></span>
                        </div>
                        <span style="font-weight:700;color:#1f2937;"><?=(int)$cnt?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3">
        <div class="card-mis h-100">
            <div class="card-mis-header"><h5><i class="fas fa-calendar-alt text-warning me-2"></i>Plans Status</h5></div>
            <div class="card-mis-body">
                <div class="row g-2">
                    <?php foreach ([
                        ['Draft',     $pk['draft']     ??0,'#9ca3af','#f9fafb'],
                        ['Submitted', $pk['submitted'] ??0,'#3b82f6','#eff6ff'],
                        ['Approved',  $pk['approved']  ??0,'#10b981','#ecfdf5'],
                        ['Rejected',  $pk['rejected']  ??0,'#ef4444','#fef2f2'],
                    ] as [$lbl,$cnt,$col,$bg]): ?>
                    <div class="col-6">
                        <div style="text-align:center;background:<?=$bg?>;border-radius:8px;padding:.7rem .3rem;border:1px solid <?=$col?>22;">
                            <div style="font-size:1.4rem;font-weight:800;color:<?=$col?>;"><?=(int)$cnt?></div>
                            <div style="font-size:.68rem;color:<?=$col?>;font-weight:600;"><?=$lbl?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ STAFF-BY-STAFF TABLE ══════════════════════════════════ -->
<?php if (!empty($staffPerfRows)): ?>
<div class="card-mis mb-4" style="border-top:3px solid #c9a84c;">
    <div class="card-mis-header">
        <h5><i class="fas fa-table text-warning me-2"></i>Staff-wise Breakdown — <?=$monthLabel?></h5>
        <span style="font-size:.78rem;color:#9ca3af;"><?=count($staffPerfRows)?> member(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr>
                    <th>#</th><th>Staff</th><th>Dept</th><th class="text-center">Plan</th>
                    <th class="text-center">Field Hrs</th><th class="text-center">Planned Hrs</th>
                    <th class="text-center">Office Hrs</th><th class="text-center">Days</th>
                    <th class="text-center">Clients</th>
                    <th class="text-center">Visited</th><th class="text-center">Missed</th>
                    <th class="text-center">Rescheduled</th>
                    <th class="text-center">Matched/Planned</th>
                    <th style="min-width:140px;">Visit Eff.</th>
                    <th>Last Log</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($staffPerfRows as $i => $s):
                $sPlanned    = (int)   ($s['planned_visits'] ?? 0);
                $sMatched    = (int)   ($s['matched_visits'] ?? 0);
                $sEffRaw     = $sPlanned > 0 ? round(($sMatched / $sPlanned) * 100) : 0;
                $sEff        = min($sEffRaw, 100);
                $sEffCol     = $sEff >= 80 ? '#10b981' : ($sEff >= 50 ? '#f59e0b' : '#ef4444');
                $sPlannedHrs = (float) ($s['planned_hours'] ?? 0);
                $sActualHrs  = (float) ($s['hours']         ?? 0);
                $isSelf      = ($s['id'] == $uid);
                $initials    = strtoupper(
                    substr($s['full_name'], 0, 1) .
                    (strpos($s['full_name'], ' ') !== false
                        ? substr($s['full_name'], strpos($s['full_name'], ' ') + 1, 1) : '')
                );
            ?>
            <tr <?= $isSelf ? 'style="background:rgba(201,168,76,.04);"' : '' ?>>
                <td style="color:#9ca3af;font-size:.75rem;"><?=$i+1?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:.6rem;">
                        <div style="width:32px;height:32px;border-radius:50%;
                            background:<?=$isSelf?'#fef3c7':'#c9a84c22'?>;
                            color:<?=$isSelf?'#b45309':'#c9a84c'?>;
                            display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;flex-shrink:0;">
                            <?=$initials?>
                        </div>
                        <div>
                            <div style="font-size:.85rem;font-weight:500;">
                                <?=htmlspecialchars($s['full_name'])?>
                                <?php if ($isSelf): ?><span style="font-size:.65rem;color:#c9a84c;font-weight:700;margin-left:3px;">YOU</span><?php endif; ?>
                            </div>
                            <div style="font-size:.68rem;color:#9ca3af;"><?=htmlspecialchars($s['employee_id']??'')?></div>
                        </div>
                    </div>
                </td>
                <td>
                    <span style="font-size:.73rem;background:#f3f4f6;color:#6b7280;padding:.1rem .45rem;border-radius:6px;">
                        <?=htmlspecialchars($s['dept_label'])?>
                    </span>
                </td>
                <td class="text-center"><?=planBadgeSP((int)$s['plans_approved'],(int)$s['plans_submitted'],(int)$s['plans_draft'],(int)$s['plans_rejected'])?></td>
                <td class="text-center"><span style="font-weight:700;color:#3b82f6;"><?=number_format($sActualHrs,1)?>h</span></td>
                <td class="text-center" style="color:#6366f1;"><?=$sPlannedHrs>0?number_format($sPlannedHrs,1).'h':'<span style="color:#d1d5db;">—</span>'?></td>
                <td class="text-center">
                    <?php if ((float)$s['office_hours'] > 0): ?>
                        <strong style="color:#10b981;"><?=number_format((float)$s['office_hours'],1)?>h</strong>
                        <div style="font-size:.65rem;color:#9ca3af;"><?=(int)$s['office_logs']?> logs</div>
                    <?php else: ?><span style="color:#d1d5db;font-size:.77rem;">—</span><?php endif; ?>
                </td>
                <td class="text-center" style="font-size:.82rem;color:#6b7280;"><?=(int)$s['active_days']?>d</td>
                <td class="text-center"><span style="font-size:.8rem;font-weight:600;color:#8b5cf6;"><?=(int)$s['clients']?></span></td>
                <td class="text-center"><span style="background:#ecfdf5;color:#10b981;padding:.15rem .5rem;border-radius:99px;font-size:.72rem;font-weight:600;"><?=(int)$s['visited']?></span></td>
                <td class="text-center">
                    <?php if((int)$s['missed']>0):?><span style="background:#fef2f2;color:#ef4444;padding:.15rem .5rem;border-radius:99px;font-size:.72rem;font-weight:600;"><?=(int)$s['missed']?></span>
                    <?php else:?><span style="color:#e5e7eb;font-size:.75rem;">—</span><?php endif;?>
                </td>
                <td class="text-center">
                    <?php if((int)$s['rescheduled']>0):?><span style="background:#fffbeb;color:#f59e0b;padding:.15rem .5rem;border-radius:99px;font-size:.72rem;font-weight:600;"><?=(int)$s['rescheduled']?></span>
                    <?php else:?><span style="color:#e5e7eb;font-size:.75rem;">—</span><?php endif;?>
                </td>
                <td class="text-center" style="font-size:.8rem;">
                    <span style="color:#3b82f6;font-weight:600;"><?=$sMatched?></span>
                    <span style="color:#9ca3af;"> / <?=$sPlanned?></span>
                </td>
                <td>
                    <?php if($sPlanned>0):?>
                    <div style="display:flex;align-items:center;gap:.4rem;">
                        <div style="flex:1;background:#f3f4f6;border-radius:99px;height:5px;overflow:hidden;">
                            <div style="width:<?=$sEff?>%;background:<?=$sEffCol?>;height:100%;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:.72rem;font-weight:700;color:<?=$sEffCol?>;min-width:38px;text-align:right;"><?=$sEff?>%</span>
                    </div>
                    <?php if($sEffRaw>100):?><div style="font-size:.63rem;color:#f59e0b;">⚠ <?=$sEffRaw?>% raw</div><?php endif;?>
                    <?php else:?><span style="font-size:.73rem;color:#9ca3af;">No plan</span><?php endif;?>
                </td>
                <td style="font-size:.75rem;color:#6b7280;"><?=$s['last_log_date']?date('d M',strtotime($s['last_log_date'])):'—'?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f9fafb;font-weight:700;">
                    <td colspan="4" style="padding:10px 14px;font-size:.82rem;color:#374151;"><i class="fas fa-calculator me-1 text-warning"></i>TOTAL</td>
                    <td class="text-center" style="color:#3b82f6;"><?=number_format($totalHours,1)?>h</td>
                    <td class="text-center" style="color:#6366f1;"><?=number_format($plannedHrs,1)?>h</td>
                    <td class="text-center" style="color:#10b981;"><?=number_format($officeHours,1)?>h</td>
                    <td class="text-center" style="color:#6b7280;">—</td>
                    <td class="text-center" style="color:#8b5cf6;"><?=(int)($fieldKpi['unique_clients']??0)?></td>
                    <td class="text-center" style="color:#10b981;"><?=(int)($fieldKpi['visited']??0)?></td>
                    <td class="text-center" style="color:#ef4444;"><?=(int)($fieldKpi['missed']??0)?></td>
                    <td class="text-center" style="color:#f59e0b;"><?=(int)($fieldKpi['rescheduled']??0)?></td>
                    <td class="text-center" style="color:#3b82f6;"><?=$matchedCount?> / <?=$plannedCount?></td>
                    <td><strong style="color:<?=$effColor?>"><?=$visitEff?>%</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ══ RECENT FIELD LOGS ════════════════════════════════════ -->
<div class="card-mis mb-4">
    <div class="card-mis-header">
        <h5><i class="fas fa-car text-warning me-2"></i>Recent Field Logs</h5>
        <a href="log_list.php?month=<?=$month?>" class="btn btn-outline-secondary btn-sm">View All</a>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr><th>Date</th><th>Staff</th><th>Client</th><th class="text-center">Time In</th><th class="text-center">Hours</th><th>Status</th><th>Description</th></tr>
            </thead>
            <tbody>
            <?php if (empty($recentLogs)): ?>
                <tr><td colspan="7" style="text-align:center;padding:24px;color:#9ca3af;font-size:.83rem;">No logs for selected range</td></tr>
            <?php endif; ?>
            <?php foreach ($recentLogs as $l): ?>
            <tr>
                <td>
                    <div style="font-size:.83rem;font-weight:500;white-space:nowrap;"><?=date('d M Y',strtotime($l['log_date']))?></div>
                    <div style="font-size:.68rem;color:#9ca3af;"><?=htmlspecialchars($l['day_of_week']??'')?></div>
                </td>
                <td style="font-size:.82rem;font-weight:500;"><?=htmlspecialchars($l['staff_name'])?></td>
                <td>
                    <div style="font-size:.83rem;font-weight:500;"><?=htmlspecialchars(mb_strimwidth($l['company_name']??'—',0,22,'…'))?></div>
                    <div style="font-size:.68rem;color:#9ca3af;"><?=htmlspecialchars($l['company_code']??'')?></div>
                </td>
                <td class="text-center" style="font-size:.78rem;color:#6b7280;"><?=$l['time_in']?date('g:i A',strtotime($l['time_in'])):'—'?></td>
                <td class="text-center">
                    <span style="font-weight:700;color:<?=(float)$l['duration_hours']>=4?'#10b981':((float)$l['duration_hours']>=2?'#f59e0b':'#ef4444')?>;">
                        <?=number_format((float)$l['duration_hours'],1)?>h
                    </span>
                </td>
                <td><?=vstBadgeSP($l['visit_status']??'')?></td>
                <td style="font-size:.75rem;color:#6b7280;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars(mb_strimwidth($l['work_description']??'—',0,45,'…'))?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══ RECENT OFFICE LOGS ════════════════════════════════════ -->
<?php if (!empty($recentOffice)): ?>
<div class="card-mis mb-4">
    <div class="card-mis-header">
        <h5><i class="fas fa-laptop text-warning me-2"></i>Recent Office Logs</h5>
        <span style="font-size:.78rem;color:#9ca3af;"><?=count($recentOffice)?> entries</span>
    </div>
    <div class="table-responsive">
        <table class="table-mis w-100">
            <thead>
                <tr><th>Date</th><th>Staff</th><th>Client</th><th class="text-center">In</th><th class="text-center">Out</th><th class="text-center">Hours</th><th>Description</th><th class="text-center">Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentOffice as $ol): ?>
            <tr>
                <td style="font-size:.83rem;font-weight:500;white-space:nowrap;"><?=date('d M Y',strtotime($ol['log_date']))?></td>
                <td>
                    <div style="font-size:.83rem;"><?=htmlspecialchars($ol['staff_name'])?></div>
                    <div style="font-size:.67rem;color:#9ca3af;"><?=htmlspecialchars($ol['employee_id']??'')?></div>
                </td>
                <td>
                    <div style="font-size:.83rem;font-weight:500;"><?=htmlspecialchars(mb_strimwidth($ol['company_name']??'—',0,20,'…'))?></div>
                    <div style="font-size:.67rem;color:#9ca3af;"><?=htmlspecialchars($ol['company_code']??'')?></div>
                </td>
                <td class="text-center" style="font-size:.78rem;"><?=$ol['time_in']?date('h:i A',strtotime($ol['time_in'])):'—'?></td>
                <td class="text-center" style="font-size:.78rem;"><?=$ol['time_out']?date('h:i A',strtotime($ol['time_out'])):'—'?></td>
                <td class="text-center"><strong style="color:<?=(float)$ol['hrs']>=4?'#10b981':((float)$ol['hrs']>=2?'#f59e0b':'#6b7280')?>"><?=number_format((float)$ol['hrs'],1)?>h</strong></td>
                <td style="font-size:.73rem;color:#6b7280;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars(mb_strimwidth($ol['description']??'—',0,50,'…'))?></td>
                <td class="text-center"><?=offStatusBadge($ol['status']??'not_started')?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

</div><!-- /padding -->
<?php include '../../includes/footer.php'; ?>
</div></div>

<script>
new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($r) => date('d M', strtotime($r['log_date'])), $trendRows)) ?>,
        datasets: [
            { label:'Hours', data:<?= json_encode(array_map(fn($r)=>(float)$r['hours'],$trendRows)) ?>,
              backgroundColor:'rgba(201,168,76,.25)',borderColor:'#c9a84c',borderWidth:2,borderRadius:4,yAxisID:'y' },
            { label:'Visits', type:'line', data:<?= json_encode(array_map(fn($r)=>(int)$r['visits'],$trendRows)) ?>,
              borderColor:'#3b82f6',backgroundColor:'rgba(59,130,246,.08)',pointBackgroundColor:'#3b82f6',
              pointRadius:4,tension:.4,fill:true,yAxisID:'y1' }
        ]
    },
    options: {
        responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
        plugins:{legend:{position:'top',labels:{usePointStyle:true,font:{size:11}}}},
        scales:{
            y: {position:'left',beginAtZero:true,grid:{color:'#f3f4f6'},ticks:{font:{size:10},callback:v=>v+'h'}},
            y1:{position:'right',beginAtZero:true,grid:{display:false},ticks:{font:{size:10},stepSize:1}},
            x: {grid:{display:false},ticks:{font:{size:10}}}
        }
    }
});
new Chart(document.getElementById('statusChart'), {
    type:'doughnut',
    data:{
        labels:['Visited','Missed','Rescheduled'],
        datasets:[{
            data:[<?=(int)($fieldKpi['visited']??0)?>,<?=(int)($fieldKpi['missed']??0)?>,<?=(int)($fieldKpi['rescheduled']??0)?>],
            backgroundColor:['#10b981','#ef4444','#f59e0b'],
            borderWidth:3,borderColor:'#fff',hoverOffset:6
        }]
    },
    options:{
        responsive:true,maintainAspectRatio:false,cutout:'68%',
        plugins:{legend:{display:false}}
    },
    plugins:[{
        id:'centre',
        afterDraw(chart){
            const {ctx,chartArea:{top,bottom,left,right}}=chart;
            const cx=(left+right)/2,cy=(top+bottom)/2;
            ctx.save();ctx.textAlign='center';ctx.textBaseline='middle';
            ctx.fillStyle='#1f2937';ctx.font='bold 16px sans-serif';
            ctx.fillText(<?=(int)($fieldKpi['total_logs']??0)?>,cx,cy-6);
            ctx.fillStyle='#9ca3af';ctx.font='10px sans-serif';
            ctx.fillText('total',cx,cy+9);ctx.restore();
        }
    }]
});
function applyFilters() {
    const p = new URLSearchParams({
        month:    document.getElementById('filterMonth').value,
        staff_id: document.getElementById('filterStaff').value,
        from:     document.getElementById('filterFrom').value,
        to:       document.getElementById('filterTo').value,
    });
    if (!p.get('staff_id')) p.delete('staff_id');
    location.href = 'staff_performance.php?' + p.toString();
}
</script>