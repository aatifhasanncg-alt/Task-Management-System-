<?php 
// admin/users/change_role.php
require_once '../../config/db.php';
require_once '../../config/session.php';
require_once '../../config/role_manager.php';
requireExecutive();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $result = changeUserRole(
        userId:     (int)$_POST['user_id'],
        newRoleId:  (int)$_POST['new_role_id'],
        newBranchId:(int)($_POST['new_branch_id'] ?? 0),
        reason:     trim($_POST['reason'] ?? '')
    );

    if ($result['success']) {
        setFlash('success',
            "Role changed successfully. " .
            "Old ID: {$result['old_employee_id']} → " .
            "New ID: {$result['new_employee_id']}"
        );
    } else {
        setFlash('error', $result['error']);
    }

    header('Location: ../staff/view.php?id=' . $_POST['user_id']);
    exit;
}
?>