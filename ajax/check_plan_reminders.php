<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

$db = getDB();

// Get today's and tomorrow's date
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// Fetch plan entries for today & tomorrow
$stmt = $db->query("
    SELECT wpe.*, 
           wp.user_id,
           u.email, u.full_name,
           c.company_name,
           r.role_name
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id = wpe.plan_id
    JOIN users u ON u.id = wp.user_id
    LEFT JOIN roles r ON r.id = u.role_id
    LEFT JOIN companies c ON c.id = wpe.client_id
    WHERE wpe.plan_date IN ('$today', '$tomorrow')
      AND (wpe.is_notified IS NULL OR wpe.is_notified = 0)
");

$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($plans as $p) {

    $isToday = ($p['plan_date'] === $today);
    $label   = $isToday ? 'Today' : 'Tomorrow';

    $message = "{$label} you have a plan with {$p['company_name']} "
             . "at " . date('h:i A', strtotime($p['planned_time_in']));

    $isAdminRole = in_array($p['role_name'] ?? '', ['admin', 'executive']);
    $link = APP_URL . ($isAdminRole ? "/admin/planning/plan_view.php" : "/staff/planning/plan_view.php") . "?id=" . $p['plan_id'];

    try {
        // ✅ Insert notification (same system as yours)
        $db->prepare("
            INSERT INTO notifications 
            (user_id, type, title, message, link, is_read, created_at)
            VALUES (?, 'reminder', ?, ?, ?, 0, NOW())
        ")->execute([
            $p['user_id'],
            "Plan Reminder ({$label})",
            $message,
            $link
        ]);

        // ✅ Send Email
        if (!empty($p['email'])) {
            $subject = "Work Plan Reminder ({$label})";
            $body = "
                Hello {$p['full_name']},<br><br>
                You have a <b>{$label}</b> plan:<br>
                <b>Client:</b> {$p['company_name']}<br>
                <b>Time:</b> " . date('h:i A', strtotime($p['planned_time_in'])) . "<br><br>
                <a href='{$link}'>View Plan</a><br><br>
                MIS System
            ";

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8\r\n";

            @mail($p['email'], $subject, $body, $headers);
        }

        // ✅ Mark as notified
        $db->prepare("UPDATE work_plan_entries SET is_notified = 1 WHERE id=?")
           ->execute([$p['id']]);

    } catch (Exception $e) {
        // silently fail
    }
}

echo json_encode(['ok' => true, 'processed' => count($plans)]);