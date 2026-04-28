<?php
/**
 * consulting/admin/plan_ajax.php
 * Returns JSON list of plans for a user+month (for the plan_create sidebar).
 */
require_once '../../../config/db.php';
require_once '../../../config/config.php';
require_once '../../../config/session.php';
require_once __DIR__ . '/../includes/helpers.php';
requireAnyRole();

header('Content-Type: application/json');

$db      = getDB();
$user    = currentUser();
$uid     = (int)$user['id'];
$isAdmin = ($user['role_name'] ?? '') === 'admin';

if (!isConsultingUser($db, $user)) { echo '[]'; exit; }

$month      = $_GET['month'] ?? date('Y-m');
$monthDate  = DateTime::createFromFormat('Y-m', $month) ?: new DateTime();
$monthStart = $monthDate->format('Y-m-01');

$reqUserId = (int)($_GET['user_id'] ?? $uid);
// Staff can only see their own plans
if (!$isAdmin) $reqUserId = $uid;

$deptId = (int)$user['department_id'];

$rows = $db->prepare("
    SELECT wp.id, wp.week_number, wp.week_start_date, wp.week_end_date,
           wp.status, COUNT(wpe.id) AS entry_count,
           COALESCE(SUM(wpe.planned_hours),0) AS total_hours
    FROM work_plans wp
    LEFT JOIN work_plan_entries wpe ON wpe.plan_id = wp.id
    WHERE wp.user_id      = ?
      AND wp.plan_month   = ?
      AND wp.department_id = ?
    GROUP BY wp.id
    ORDER BY wp.week_number ASC
");
$rows->execute([$reqUserId, $monthStart, $deptId]);
echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));