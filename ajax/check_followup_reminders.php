<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/session.php';

$db = getDB();

// Find follow-ups due today or overdue that haven't been notified yet
$stmt = $db->query("
    SELECT tf.*, 
           t.task_number, t.title AS task_title, t.assigned_to, t.department_id,
           t.id AS task_id,
           u.full_name AS assigned_name
    FROM task_followups tf
    JOIN tasks t ON t.id = tf.task_id
    JOIN users u ON u.id = t.assigned_to
    WHERE tf.followup_date <= CURDATE()
      AND tf.is_notified = 0
      AND t.is_active = 1
      AND t.assigned_to IS NOT NULL
");
$followups = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($followups as $fu) {
    $roleStmt = $db->prepare("SELECT r.role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?");
    $roleStmt->execute([$fu['assigned_to']]);
    $assignedRole = $roleStmt->fetchColumn() ?: 'staff';
    $roleFolder = match($assignedRole) {
        'admin', 'superadmin' => 'admin',
        'executive' => 'executive',
        default => 'staff'
    };
    $taskUrl = APP_URL . '/' . $roleFolder . '/tasks/view.php?id=' . $fu['task_id'];
    $isToday = ($fu['followup_date'] === date('Y-m-d'));
    $label = $isToday ? 'Today' : 'Overdue (' . date('d M Y', strtotime($fu['followup_date'])) . ')';

    // Insert notification for assigned user
    try {
        $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
            VALUES (?, 'reminder', ?, ?, ?, 0, NOW())
        ")->execute([
            $fu['assigned_to'],
            'Follow-up: ' . $fu['task_number'],
            'Follow-up due ' . $label . ' for task: ' . ($fu['task_title'] ?: $fu['task_number']) . ($fu['notes'] ? ' — ' . $fu['notes'] : ''),
            $taskUrl
        ]);

        // Mark as notified so we don't spam
        $db->prepare("UPDATE task_followups SET is_notified = 1 WHERE id = ?")
           ->execute([$fu['id']]);

    } catch (Exception $e) {
        // column may not exist yet — see migration below
    }
}

echo json_encode(['ok' => true, 'processed' => count($followups)]);