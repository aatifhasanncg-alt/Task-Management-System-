<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/session.php';

$db = getDB();

$stmt = $db->query("
    SELECT tf.*,
           t.task_number,
           t.title        AS task_title,
           t.assigned_to,
           t.id           AS task_id,
           ua.full_name   AS assigned_name,
           ra.role_name   AS assigned_role,
           uc.full_name   AS creator_name,
           rc.role_name   AS creator_role
    FROM task_followups tf
    JOIN tasks  t  ON t.id  = tf.task_id
    JOIN users  ua ON ua.id = t.assigned_to
    JOIN roles  ra ON ra.id = ua.role_id
    JOIN users  uc ON uc.id = tf.created_by
    JOIN roles  rc ON rc.id = uc.role_id
    WHERE tf.followup_date <= CURDATE()
      AND tf.is_notified  =  0
      AND t.is_active      =  1
      AND t.assigned_to IS NOT NULL
");
$followups = $stmt->fetchAll(PDO::FETCH_ASSOC);

function roleFolder(string $role): string {
    return match($role) {
        'admin', 'superadmin' => 'admin',
        'executive'           => 'executive',
        default               => 'staff'
    };
}

$processed = 0;

$insertStmt = $db->prepare("
    INSERT IGNORE INTO notifications
        (user_id, type, title, message, link, is_read, created_at)
    VALUES
        (?, 'reminder', ?, ?, ?, 0, NOW())
");

foreach ($followups as $fu) {
    $isToday     = ($fu['followup_date'] === date('Y-m-d'));
    $label       = $isToday
                    ? 'Today'
                    : 'Overdue (' . date('d M Y', strtotime($fu['followup_date'])) . ')';
    $taskTitle   = $fu['task_title'] ?: $fu['task_number'];
    $notesSuffix = $fu['notes'] ? ' — ' . $fu['notes'] : '';
    $title       = 'Follow-up: ' . $fu['task_number'];
    $message     = 'Follow-up due ' . $label . ' for: ' . $taskTitle . $notesSuffix;

    // assigned_to always gets notified; created_by only if different person
    $recipients = [
        (int) $fu['assigned_to'] => roleFolder($fu['assigned_role']),
    ];
    $createdBy = (int) $fu['created_by'];
    if ($createdBy && $createdBy !== (int) $fu['assigned_to']) {
        $recipients[$createdBy] = roleFolder($fu['creator_role']);
    }

    $allOk = true;
    foreach ($recipients as $uid => $folder) {
        $taskUrl = APP_URL . '/' . $folder . '/tasks/view.php?id=' . $fu['task_id'];
        try {
            $insertStmt->execute([$uid, $title, $message, $taskUrl]);
        } catch (Exception $e) {
            $allOk = false;
        }
    }

    if ($allOk) {
        $db->prepare("UPDATE task_followups SET is_notified = 1 WHERE id = ?")
           ->execute([$fu['id']]);
        $processed++;
    }
}

echo json_encode(['ok' => true, 'processed' => $processed]);