<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/mailer.php'; // ← brings in sendMail() via PHPMailer/SMTP

$db = getDB();

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// ── TODAY reminders ──────────────────────────────────────────
$stmtToday = $db->prepare("
    SELECT wpe.*,
           wp.user_id, wp.id AS plan_id,
           u.email, u.full_name,
           c.company_name,
           r.role_name
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    JOIN users u ON u.id = wp.user_id
    LEFT JOIN roles r ON r.id = u.role_id
    LEFT JOIN companies c ON c.id = wpe.client_id
    WHERE wpe.plan_date = ?
      AND (wpe.notified_today IS NULL OR wpe.notified_today = 0)
");
$stmtToday->execute([$today]);
$todayPlans = $stmtToday->fetchAll(PDO::FETCH_ASSOC);

// ── TOMORROW reminders ───────────────────────────────────────
$stmtTomorrow = $db->prepare("
    SELECT wpe.*,
           wp.user_id, wp.id AS plan_id,
           u.email, u.full_name,
           c.company_name,
           r.role_name
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    JOIN users u ON u.id = wp.user_id
    LEFT JOIN roles r ON r.id = u.role_id
    LEFT JOIN companies c ON c.id = wpe.client_id
    WHERE wpe.plan_date = ?
      AND (wpe.notified_tomorrow IS NULL OR wpe.notified_tomorrow = 0)
");
$stmtTomorrow->execute([$tomorrow]);
$tomorrowPlans = $stmtTomorrow->fetchAll(PDO::FETCH_ASSOC);

$processed = 0;

function notifyPlan(PDO $db, array $p, string $label, string $notifiedCol): void
{
    $message = "{$label} you have a plan with {$p['company_name']} "
        . "at " . date('h:i A', strtotime($p['planned_time_in']));

    $role = $p['role_name'] ?? '';

    switch ($role) {
        case 'executive':
            $link = APP_URL . "/executive/consulting/plan_view.php?id=" . $p['plan_id'];
            break;
        case 'admin':
            $link = APP_URL . "/admin/planning/myplan_view.php?id=" . $p['plan_id'];
            break;
        case 'manager':
            $link = APP_URL . "/manager/consulting/plan_view.php?id=" . $p['plan_id'];
            break;
        default:
            $link = APP_URL . "/staff/planning/plan_view.php?id=" . $p['plan_id'];
            break;
    }
    try {
        // In-app notification
        $db->prepare("
            INSERT INTO notifications
            (user_id, type, title, message, link, is_read, created_at)
            VALUES (?, 'reminder', ?, ?, ?, 0, NOW())
        ")->execute([
                    $p['user_id'],
                    "Plan Reminder ({$label})",
                    $message,
                    $link,
                ]);

        // Email — via SMTP/PHPMailer, not raw mail()
        if (!empty($p['email'])) {
            $plan = [
                'message' => $message,
                'week' => $p['week_number'] ?? '',
                'month' => date('F Y', strtotime($p['plan_date'])),
                'url' => $link,
            ];
            emailWorkPlan(
                ['email' => $p['email'], 'full_name' => $p['full_name']],
                $plan
            );
        }

        // Mark THIS specific reminder type as sent
        $db->prepare("UPDATE work_plan_entries SET {$notifiedCol} = 1 WHERE id = ?")
            ->execute([$p['id']]);

    } catch (Exception $e) {
        error_log('Plan reminder failed for entry #' . $p['id'] . ': ' . $e->getMessage());
    }
}

foreach ($todayPlans as $p) {
    notifyPlan($db, $p, 'Today', 'notified_today');
    $processed++;
}

foreach ($tomorrowPlans as $p) {
    notifyPlan($db, $p, 'Tomorrow', 'notified_tomorrow');
    $processed++;
}

echo json_encode(['ok' => true, 'processed' => $processed]);