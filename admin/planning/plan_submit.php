<?php
/**
 * consulting/staff/plan_submit.php — Submit plan for approval
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
require_once '../../config/notify.php';
require_once '../../config/plan_notify.php';
requireAnyRole();

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: plan_list.php');
    exit;
}
verifyCsrf();

$planId = (int)($_POST['plan_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM work_plans WHERE id=? AND user_id=?");
$stmt->execute([$planId, $uid]);
$plan = $stmt->fetch();

if (!$plan || !in_array($plan['status'], ['draft', 'rejected'])) {
    setFlash('error', 'Plan not found or cannot be submitted.');
    header('Location: plan_list.php');
    exit;
}

// Update status to submitted
$db->prepare("UPDATE work_plans SET status='submitted', updated_at=NOW() WHERE id=?")
   ->execute([$planId]);

// Derive variables needed by notifyPlanApprovers
$planUserId = (int)$plan['user_id'];
$weekNum    = (int)$plan['week_number'];
$month      = substr($plan['plan_month'], 0, 7);
$monthLabel = date('F Y', strtotime($plan['plan_month']));

notifyPlanApprovers(
    $db,
    $planId,
    $planUserId,
    $uid,
    $user['full_name'] ?? ('User #' . $uid),
    $weekNum,
    $monthLabel,
    $month,
    'submitted'
);

logActivity('Submitted work plan #' . $planId, 'consulting');
setFlash('success', 'Plan submitted for approval!');
header('Location: plan_view.php?id=' . $planId);
exit;