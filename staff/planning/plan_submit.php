<?php
/**
 * consulting/staff/plan_submit.php — Submit plan for approval
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];


if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: plan_list.php'); exit; }
verifyCsrf();

$planId = (int)($_POST['plan_id'] ?? 0);

$stmt = $db->prepare("
    SELECT * FROM work_plans WHERE id=? AND user_id=? AND department_id=?
");
$stmt->execute([$planId, $uid, (int)$user['department_id']]);
$plan = $stmt->fetch();

if (!$plan || !in_array($plan['status'], ['draft', 'rejected'])) {
    setFlash('error', 'Plan not found or cannot be submitted.');
    header('Location: plan_list.php');
    exit;
}

$upd = $db->prepare("UPDATE work_plans SET status='submitted', updated_at=NOW() WHERE id=?");
$upd->execute([$planId]);

// Notify supervisor/admin
$adminStmt = $db->prepare("
    SELECT u.id FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.role_name = 'admin'
      AND u.department_id = ?
      AND u.branch_id = ?
      AND u.is_active = 1
    LIMIT 5
");
$adminStmt->execute([(int)$user['department_id'], (int)$user['branch_id']]);
$admins = $adminStmt->fetchAll();

foreach ($admins as $admin) {
    notify(
        $admin['id'],
        'Work Plan Submitted',
        $user['full_name'] . ' submitted a work plan for Week ' . $plan['week_number'] . ' for your approval.',
        'task',
        APP_URL . '/admin/planning/plan_view.php?id=' . $planId,
        true,
        []
    );
}

logActivity('Submitted work plan #' . $planId, 'consulting');
setFlash('success', 'Plan submitted for approval!');
header('Location: plan_view.php?id=' . $planId);
exit;