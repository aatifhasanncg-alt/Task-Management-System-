<?php
/**
 * Shared plan notification helper.
 * Call after $db->commit() has already been called.
 *
 * Notifies:
 *   1. The plan owner's managed_by (users.managed_by, or UDA managed_by fallback)
 *   2. Every executive with CON in their dept/UDA
 *   3. Every manager with CON in their dept/UDA (branch managers — dept_code CORE — get the branch link)
 * Admins are NOT broadcast to company-wide; only via managed_by if applicable.
 */
function notifyPlanApprovers(
    PDO $db,
    int $planId,
    int $planUserId,
    int $actorId,
    string $actorName,
    int $weekNum,
    string $monthLabel,
    string $month,
    string $context = 'submitted'
): void {

    $buildLink = function (string $role, ?string $deptCode, int $pid, int $staffId) use ($month, $weekNum): string {
        if ($role === 'executive') {
            return APP_URL . '/executive/consulting/plans.php'
                . '?month=' . $month . '&staff_id=' . $staffId . '&status=submitted&week=' . $weekNum;
        }
        if ($role === 'manager') {
            $base = ($deptCode === 'CORE') ? '/manager/consulting/branch/' : '/manager/consulting/';
            return APP_URL . $base . 'plan_approval.php'
                . '?month=' . $month . '&staff_id=' . $staffId . '&status=submitted&week=' . $weekNum;
        }
        return APP_URL . '/admin/planning/plan_view.php?id=' . $pid;
    };

    $getRoleInfo = function (int $id) use ($db): array {
        $q = $db->prepare("
            SELECT r.role_name, d.dept_code
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.id = ?
        ");
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return [
            'role' => $row['role_name'] ?? 'admin',
            'dept_code' => $row['dept_code'] ?? null,
        ];
    };

    $notifyIds = [];
    $addNotify = function (int $id, string $role, ?string $deptCode) use (&$notifyIds) {
        if (!isset($notifyIds[$id])) {
            $notifyIds[$id] = ['role' => $role, 'dept_code' => $deptCode];
        }
    };

    // 1. managed_by from users table
    $mbQ = $db->prepare("SELECT managed_by FROM users WHERE id = ?");
    $mbQ->execute([$planUserId]);
    $mbFromUsers = $mbQ->fetchColumn() ?: null;
    if ($mbFromUsers) {
        $info = $getRoleInfo((int) $mbFromUsers);
        $addNotify((int) $mbFromUsers, $info['role'], $info['dept_code']);
    }

    // 2. managed_by from UDA for CON dept — fallback only if step 1 found nothing
    if (empty($notifyIds)) {
        $mbUdaQ = $db->prepare("
            SELECT uda.managed_by FROM user_department_assignments uda
            JOIN departments d ON d.id = uda.department_id
            WHERE uda.user_id = ?
              AND (d.dept_code = 'CON' OR d.dept_name LIKE '%consult%')
              AND uda.managed_by IS NOT NULL
            LIMIT 1
        ");
        $mbUdaQ->execute([$planUserId]);
        $mbFromUda = $mbUdaQ->fetchColumn() ?: null;
        if ($mbFromUda) {
            $info = $getRoleInfo((int) $mbFromUda);
            $addNotify((int) $mbFromUda, $info['role'], $info['dept_code']);
        }
    }

    // 3. Executives and managers (NOT admins) with CON in dept or UDA
    $conQ = $db->prepare("
        SELECT DISTINCT u.id, r.role_name, d.dept_code
        FROM users u
        JOIN roles r ON r.id = u.role_id
        LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
        LEFT JOIN departments d2 ON d2.id = uda.department_id
        WHERE u.is_active = 1
          AND r.role_name IN ('executive', 'manager')
          AND (
              d.dept_code = 'CON'  OR d.dept_name  LIKE '%consult%'
              OR d2.dept_code = 'CON' OR d2.dept_name LIKE '%consult%'
          )
    ");
    $conQ->execute();
    foreach ($conQ->fetchAll(PDO::FETCH_ASSOC) as $cu) {
        $addNotify((int) $cu['id'], $cu['role_name'], $cu['dept_code']);
    }

    if ($context === 'created_for_staff') {
        $onQ = $db->prepare("SELECT full_name FROM users WHERE id = ?");
        $onQ->execute([$planUserId]);
        $ownerName = $onQ->fetchColumn() ?: ('User #' . $planUserId);
        $notifTitle = 'Work Plan Created for Staff';
        $notifMsg = "{$actorName} created a draft work plan for {$ownerName} — Week {$weekNum}, {$monthLabel}.";
    } else {
        $notifTitle = 'Work Plan Pending Approval';
        $notifMsg = "{$actorName} submitted a work plan for Week {$weekNum}, {$monthLabel} — awaiting your approval.";
    }

    foreach ($notifyIds as $nid => $ninfo) {
        if ($nid === $planUserId) continue;
        if ($nid === $actorId && $context === 'created_for_staff') continue;

        notify(
            $nid,
            $notifTitle,
            $notifMsg,
            'task',
            $buildLink($ninfo['role'], $ninfo['dept_code'], $planId, $planUserId),
            true,
            ['template' => 'generic']
        );
    }

    if ($context === 'created_for_staff') {
        notify(
            $planUserId,
            'Work Plan Created for You',
            "{$actorName} created a work plan for you — Week {$weekNum}, {$monthLabel}.",
            'task',
            APP_URL . '/staff/planning/plan_view.php?id=' . $planId,
            true,
            ['template' => 'generic']
        );
    }
}