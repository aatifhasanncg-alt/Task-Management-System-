<?php
// config/notify.php
function notify(
    int    $userId,
    string $title,
    string $message,
    string $type      = 'task',
    string $link      = '',
    bool   $sendEmail = true,
    array  $emailData = []
): void {
    error_log("notify() called — userId={$userId} title={$title} type={$type}");

    try {
        $db = getDB();
    } catch (Exception $e) {
        error_log("notify() getDB() failed: " . $e->getMessage());
        return;
    }
    if (!$db) {
        error_log("notify() getDB() returned null");
        return;
    }

    // ── 1. Insert into notifications table ───────────────────────
    try {
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, title, message, type, link)
            VALUES (?, ?, ?, ?, ?)
        ");
        $result   = $stmt->execute([$userId, $title, $message, $type, $link]);
        $insertId = $db->lastInsertId();
        error_log("notify() DB insert — result=" . ($result ? 'OK' : 'FAIL') . " insertId={$insertId}");
    } catch (Exception $e) {
        error_log("notify() DB insert error: " . $e->getMessage());
        return;
    }

    // ── 2. Send email if enabled ──────────────────────────────────
    if (!$sendEmail) {
        error_log("notify() email skipped (sendEmail=false)");
        return;
    }

    try {
        $userStmt = $db->prepare("
            SELECT id, full_name, email
            FROM users
            WHERE id = ? AND is_active = 1
        ");
        $userStmt->execute([$userId]);
        $recipient = $userStmt->fetch();

        if (!$recipient) {
            error_log("notify() no recipient found for userId={$userId}");
            return;
        }
        if (empty($recipient['email'])) {
            error_log("notify() recipient userId={$userId} has no email");
            return;
        }

        error_log("notify() sending email to {$recipient['email']}");
        require_once __DIR__ . '/mailer.php';

        $template = $emailData['template'] ?? 'generic';
        $task     = $emailData['task']     ?? [];

        error_log("notify() email template={$template}");

        switch ($template) {

            case 'task_assigned':
                emailTaskAssigned($recipient, $task);
                break;

            case 'task_transferred':
                emailTaskTransferred(
                    $recipient,
                    $task,
                    $emailData['remarks'] ?? ($task['remarks'] ?? '')
                );
                break;

            case 'task_status_changed':
                sendGenericNotificationEmail(
                    $recipient['email'],
                    $recipient['full_name'],
                    $title,
                    $message,
                    $link,
                    $type
                );
                break;

            default:
                sendGenericNotificationEmail(
                    $recipient['email'],
                    $recipient['full_name'],
                    $title,
                    $message,
                    $link,
                    $type
                );
        }

        error_log("notify() email sent OK to {$recipient['email']}");

    } catch (Exception $e) {
        error_log("notify() email error: " . $e->getMessage());
    }
}