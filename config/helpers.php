<?php
/**
 * consulting/includes/helpers.php
 * Shared utilities for the Consulting / Work-Planning module.
 * Include once at the top of every consulting page.
 */

// ── Dept detection ────────────────────────────────────────────
function isConsultingUser(PDO $db, array $user): bool {
    $deptId = (int)($user['department_id'] ?? 0);
    if (!$deptId) return false;
    $st = $db->prepare("
        SELECT id FROM departments
        WHERE id = ?
          AND (dept_code = 'CONS' OR LOWER(dept_name) LIKE '%consult%')
        LIMIT 1
    ");
    $st->execute([$deptId]);
    return (bool)$st->fetch();
}

function getConsultingDeptId(PDO $db): int {
    $row = $db->query("
        SELECT id FROM departments
        WHERE dept_code='CONS' OR LOWER(dept_name) LIKE '%consult%'
        LIMIT 1
    ")->fetch();
    return (int)($row['id'] ?? 0);
}

// ── Scope helpers ────────────────────────────────────────────
/**
 * Get all active staff under a consulting admin (same dept + branch).
 */
function getConsultingStaff(PDO $db, int $deptId, int $branchId): array {
    $st = $db->prepare("
        SELECT u.id, u.full_name, u.employee_id
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE r.role_name = 'staff'
          AND u.department_id = ?
          AND u.branch_id     = ?
          AND u.is_active     = 1
        ORDER BY u.full_name ASC
    ");
    $st->execute([$deptId, $branchId]);
    return $st->fetchAll();
}

/**
 * Build a safe IN(...) list string and return ids array.
 * Always includes the admin's own id so self-logs are visible.
 */
function buildScopeList(PDO $db, array $user, bool $isAdmin): array {
    $uid      = (int)$user['id'];
    $deptId   = (int)$user['department_id'];
    $branchId = (int)$user['branch_id'];
    $ids      = [$uid];
    if ($isAdmin) {
        $staff = getConsultingStaff($db, $deptId, $branchId);
        foreach ($staff as $s) $ids[] = (int)$s['id'];
    }
    return array_unique($ids);
}

// ── Date helpers ──────────────────────────────────────────────
function weekOfMonth(string $date): int {
    $d   = new DateTime($date);
    $dom = (int)$d->format('j');
    return (int)ceil($dom / 7);
}

/**
 * Return [start Y-m-d, end Y-m-d] for the Monday–Sunday week containing $date.
 */
function weekBounds(string $date): array {
    $d   = new DateTime($date);
    $dow = (int)$d->format('N'); // 1=Mon … 7=Sun
    $start = (clone $d)->modify('-' . ($dow - 1) . ' days');
    $end   = (clone $start)->modify('+6 days');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

/**
 * Return array of week blocks for a month:
 * [ ['week'=>1, 'start'=>'Y-m-d', 'end'=>'Y-m-d'], ... ]
 */
function getMonthWeeks(string $monthStart): array {
    $weeks = [];
    $d     = new DateTime($monthStart);
    $month = $d->format('m');
    $wNum  = 1;
    while ($d->format('m') === $month) {
        $start  = $d->format('Y-m-d');
        $dow    = (int)$d->format('w'); // 0=Sun
        $toSun  = $dow === 0 ? 6 : (7 - $dow);
        $endD   = (clone $d)->modify("+{$toSun} days");
        $mEnd   = new DateTime($d->format('Y-m-t'));
        if ($endD > $mEnd) $endD = $mEnd;
        $weeks[] = ['week' => $wNum, 'start' => $start, 'end' => $endD->format('Y-m-d')];
        $d = (clone $endD)->modify('+1 day');
        if ($d->format('m') !== $month) break;
        $wNum++;
        if ($wNum > 6) break;
    }
    return $weeks;
}

/**
 * Day-of-week string for a date.
 */
function dayOfWeek(string $date): string {
    return date('l', strtotime($date));
}

// ── Today/Tomorrow plan notifications for a user ─────────────
function getUpcomingPlanEntries(PDO $db, int $userId): array {
    $today    = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $st = $db->prepare("
        SELECT wpe.*,
               c.company_name, c.company_code,
               wp.week_number, wp.status AS plan_status,
               CASE WHEN wpe.plan_date = ? THEN 'today' ELSE 'tomorrow' END AS notify_type
        FROM work_plan_entries wpe
        JOIN work_plans wp    ON wp.id  = wpe.plan_id
        LEFT JOIN companies c ON c.id  = wpe.client_id
        WHERE wpe.assigned_to = ?
          AND wpe.plan_date IN (?, ?)
        ORDER BY wpe.plan_date ASC, wpe.planned_time_in ASC
    ");
    $st->execute([$today, $userId, $today, $tomorrow]);
    return $st->fetchAll();
}

// ── HTML badge helpers ────────────────────────────────────────
function visitBadge(string $status): string {
    $map = [
        'visited'     => ['#10b981', 'rgba(16,185,129,.15)'],
        'missed'      => ['#ef4444', 'rgba(239,68,68,.15)'],
        'rescheduled' => ['#f59e0b', 'rgba(245,158,11,.15)'],
    ];
    [$c, $bg] = $map[$status] ?? ['#9ca3af', 'rgba(156,163,175,.15)'];
    $label = ucfirst($status);
    return "<span style='display:inline-block;padding:2px 10px;border-radius:20px;
                         font-size:.7rem;font-weight:600;background:{$bg};color:{$c};'>
                {$label}</span>";
}

function planBadge(string $status): string {
    $map = [
        'draft'     => ['#9ca3af', 'rgba(107,114,128,.18)'],
        'submitted' => ['#3b82f6', 'rgba(59,130,246,.15)'],
        'approved'  => ['#10b981', 'rgba(16,185,129,.15)'],
        'rejected'  => ['#ef4444', 'rgba(239,68,68,.15)'],
    ];
    [$c, $bg] = $map[$status] ?? ['#9ca3af', 'rgba(156,163,175,.15)'];
    $label = ucfirst($status);
    return "<span style='display:inline-block;padding:2px 10px;border-radius:20px;
                         font-size:.7rem;font-weight:600;background:{$bg};color:{$c};'>
                {$label}</span>";
}

function priorityColor(string $priority): string {
    return match(strtolower($priority)) {
        'high'   => '#ef4444',
        'medium' => '#f59e0b',
        'low'    => '#10b981',
        default  => '#9ca3af',
    };
}

function hoursColor(float $hours): string {
    if ($hours >= 6) return '#10b981';
    if ($hours >= 3) return '#f59e0b';
    return '#ef4444';
}

// ── Guard: must be consulting user ───────────────────────────
function requireConsulting(PDO $db, array $user, string $redirectTo = '/'): void {
    if (!isConsultingUser($db, $user)) {
        header("Location: {$redirectTo}");
        exit;
    }
}

// ── Guard: admin only ────────────────────────────────────────
function requireConsultingAdmin(array $user, PDO $db): void {
    requireConsulting($db, $user);
    if (($user['role_name'] ?? '') !== 'admin') {
        header('Location: ' . APP_URL . '/consulting/index.php');
        exit;
    }
}