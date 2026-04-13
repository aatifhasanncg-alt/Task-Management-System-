<?php
declare(strict_types=1);

/**
 * Change a user's role properly:
 * - Archives old employee ID
 * - DB trigger auto-assigns new employee ID
 * - Logs the change to user_role_history
 * - Clears their remember tokens (forces re-login with new role)
 */
function changeUserRole(
    int    $userId,
    int    $newRoleId,
    int    $newBranchId = 0,
    string $reason      = ''
): array {

    $db          = getDB();
    $changedBy   = currentUser()['id'];

    // ── Get current user data ─────────────────────────────────
    $stmt = $db->prepare("
        SELECT u.*, r.role_name
        FROM   users u
        JOIN   roles r ON r.id = u.role_id
        WHERE  u.id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return ['success' => false, 'error' => 'User not found.'];
    }

    if ($user['role_id'] === $newRoleId) {
        return ['success' => false, 'error' => 'User already has this role.'];
    }

    // ── Get new role name ─────────────────────────────────────
    $roleStmt = $db->prepare("SELECT role_name FROM roles WHERE id = ?");
    $roleStmt->execute([$newRoleId]);
    $newRole = $roleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$newRole) {
        return ['success' => false, 'error' => 'Invalid role selected.'];
    }

    try {
        $db->beginTransaction();

        // ── Step 1: Archive old employee ID ───────────────────
        $db->prepare("
            INSERT INTO retired_employee_ids 
                (user_id, employee_id, role_id, reason)
            VALUES (?, ?, ?, 'role_change')
        ")->execute([
            $userId,
            $user['employee_id'],
            $user['role_id'],
        ]);

        // ── Step 2: Update role (trigger auto-generates new ID)
        $db->prepare("
            UPDATE users 
            SET role_id   = ?,
                branch_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $newRoleId,
            $newBranchId ?: $user['branch_id'],
            $userId,
        ]);

        // ── Step 3: Get the newly assigned employee ID ────────
        $newIdStmt = $db->prepare("SELECT employee_id FROM users WHERE id = ?");
        $newIdStmt->execute([$userId]);
        $newEmployeeId = $newIdStmt->fetchColumn();

        // ── Step 4: Log to history ────────────────────────────
        $db->prepare("
            INSERT INTO user_role_history 
                (user_id, old_role_id, new_role_id, 
                 old_employee_id, new_employee_id,
                 old_branch_id, new_branch_id,
                 changed_by, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $userId,
            $user['role_id'],
            $newRoleId,
            $user['employee_id'],
            $newEmployeeId,
            $user['branch_id'],
            $newBranchId ?: $user['branch_id'],
            $changedBy,
            $reason,
        ]);

        // ── Step 5: Clear all login tokens (force re-login) ───
        $db->prepare("
            DELETE FROM remember_tokens WHERE user_id = ?
        ")->execute([$userId]);

        // ── Step 6: Log activity ──────────────────────────────
        logActivity(
            'Role Changed',
            'users',
            "user_id={$userId} | {$user['role_name']} → {$newRole['role_name']} | " .
            "emp_id: {$user['employee_id']} → {$newEmployeeId}"
        );

        $db->commit();

        return [
            'success'        => true,
            'old_role'       => $user['role_name'],
            'new_role'       => $newRole['role_name'],
            'old_employee_id'=> $user['employee_id'],
            'new_employee_id'=> $newEmployeeId,
        ];

    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get full role history for a user
 */
function getUserRoleHistory(int $userId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            urh.*,
            r1.role_name  AS old_role_name,
            r2.role_name  AS new_role_name,
            u.full_name   AS changed_by_name,
            b1.branch_name AS old_branch_name,
            b2.branch_name AS new_branch_name
        FROM user_role_history urh
        JOIN roles   r1 ON r1.id = urh.old_role_id
        JOIN roles   r2 ON r2.id = urh.new_role_id
        JOIN users   u  ON u.id  = urh.changed_by
        LEFT JOIN branches b1 ON b1.id = urh.old_branch_id
        LEFT JOIN branches b2 ON b2.id = urh.new_branch_id
        WHERE urh.user_id = ?
        ORDER BY urh.changed_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get all old employee IDs for a user
 */
function getRetiredEmployeeIds(int $userId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT rei.*, r.role_name
        FROM   retired_employee_ids rei
        JOIN   roles r ON r.id = rei.role_id
        WHERE  rei.user_id = ?
        ORDER  BY rei.retired_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}