<?php
// config/mailer.php — PHPMailer helper
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/config.php';

function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        $mail->send();

        // Log email
        try {
            $db = getDB();
            $db->prepare("INSERT INTO email_logs(sent_to,subject,body,status) VALUES(?,?,?,?)")
               ->execute([$toEmail, $subject, $htmlBody, 'sent']);
        } catch(Exception $e) {}

        return true;
    } catch(Exception $e) {
        try {
            $db = getDB();
            $db->prepare("INSERT INTO email_logs(sent_to,subject,body,status) VALUES(?,?,?,?)")
               ->execute([$toEmail, $subject, $e->getMessage(), 'failed']);
        } catch(Exception $e2) {}
        return false;
    }
}
function sendGenericNotificationEmail(
    string $toEmail,
    string $toName,
    string $title,
    string $message,
    string $link = '',
    string $type = 'task'
): bool {
    $typeColors = [
        'task'     => '#f59e0b',
        'transfer' => '#8b5cf6',
        'status'   => '#3b82f6',
        'system'   => '#6b7280',
        'reminder' => '#ef4444',
    ];
    $color = $typeColors[$type] ?? '#f59e0b';

    $actionBtn = $link
        ? "<a href='{$link}' style='display:inline-block;background:{$color};color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:16px;'>View Details</a>"
        : '';

    $html = emailWrapper("
        <h2 style='color:#0a0f1e;'>" . htmlspecialchars($title) . "</h2>
        <p style='color:#4b5563;'>" . nl2br(htmlspecialchars($message)) . "</p>
        {$actionBtn}
    ");

    return sendMail($toEmail, $toName, $title, $html);
}
// ── Email templates ───────────────────────────────────────────

function emailTaskAssigned(array $user, array $task): bool {
    $subject = "[MISPro] New Task Assigned: {$task['task_number']}";
    $html = emailWrapper("
        <h2 style='color:#0a0f1e;'>New Task Assigned</h2>
        <p>Dear <strong>{$user['full_name']}</strong>,</p>
        <p>A new task has been assigned to you:</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:8px;background:#f9fafb;font-weight:600;width:140px;'>Task #</td><td style='padding:8px;border-bottom:1px solid #e5e7eb;'>{$task['task_number']}</td></tr>
            <tr><td style='padding:8px;background:#f9fafb;font-weight:600;'>Title</td><td style='padding:8px;border-bottom:1px solid #e5e7eb;'>{$task['title']}</td></tr>
            <tr><td style='padding:8px;background:#f9fafb;font-weight:600;'>Department</td><td style='padding:8px;border-bottom:1px solid #e5e7eb;'>{$task['department']}</td></tr>
            <tr><td style='padding:8px;background:#f9fafb;font-weight:600;'>Status</td><td style='padding:8px;border-bottom:1px solid #e5e7eb;'>{$task['status']}</td></tr>
            <tr><td style='padding:8px;background:#f9fafb;font-weight:600;'>Due Date</td><td style='padding:8px;'>" . ($task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : 'Not set') . "</td></tr>
        </table>
        <p>Please log in to MISPro to view and update this task.</p>
        <a href='" . APP_URL . "/staff/tasks/view.php?id={$task['id']}' style='display:inline-block;background:#c9a84c;color:#0a0f1e;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;'>View Task</a>
    ");
    return sendMail($user['email'], $user['full_name'], $subject, $html);
}

function emailTaskTransferred(array $user, array $task, string $remarks): bool {
    $subject = "[MISPro] Task Transferred to You: {$task['task_number']}";
    $html = emailWrapper("
        <h2 style='color:#0a0f1e;'>Task Transferred</h2>
        <p>Dear <strong>{$user['full_name']}</strong>,</p>
        <p>Task <strong>{$task['task_number']}</strong> — <em>{$task['title']}</em> has been transferred to you.</p>
        " . ($remarks ? "<p><strong>Remarks:</strong> " . htmlspecialchars($remarks) . "</p>" : "") . "
        <a href='" . APP_URL . "/staff/tasks/view.php?id={$task['id']}' style='display:inline-block;background:#c9a84c;color:#0a0f1e;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;'>View Task</a>
    ");
    return sendMail($user['email'], $user['full_name'], $subject, $html);
}

function emailPasswordReset(array $user, string $newPassword): bool {
    $subject = "[MISPro] Your Password Has Been Reset";
    $html = emailWrapper("
        <h2 style='color:#0a0f1e;'>Password Reset</h2>
        <p>Dear <strong>{$user['full_name']}</strong>,</p>
        <p>Your MISPro password has been reset by an administrator.</p>
        <p><strong>New Password:</strong> <code style='background:#f3f4f6;padding:4px 8px;border-radius:4px;font-size:16px;'>{$newPassword}</code></p>
        <p>Please log in and change your password immediately.</p>
        <a href='" . APP_URL . "/auth/login.php' style='display:inline-block;background:#c9a84c;color:#0a0f1e;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;'>Login Now</a>
    ");
    return sendMail($user['email'], $user['full_name'], $subject, $html);
}

function emailWrapper(string $content): string {
    return "<!DOCTYPE html><html><head><meta charset='utf-8'></head>
    <body style='margin:0;padding:0;background:#f0f4f8;font-family:Segoe UI,sans-serif;'>
    <div style='max-width:600px;margin:32px auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1);'>
        <div style='background:#0a0f1e;padding:24px 32px;display:flex;align-items:center;gap:12px;'>
            <div style='width:40px;height:40px;background:linear-gradient(135deg,#c9a84c,#e8c96a);border-radius:8px;display:flex;align-items:center;justify-content:center;'>
                <span style='color:#0a0f1e;font-weight:900;font-size:14px;'>ASK</span>
            </div>
            <div>
                <div style='color:white;font-weight:700;font-size:16px;'>MISPro</div>
                <div style='color:#8899aa;font-size:12px;'>ASK Global Advisory Pvt. Ltd.</div>
            </div>
        </div>
        <div style='padding:32px;'>{$content}</div>
        <div style='background:#f9fafb;padding:16px 32px;text-align:center;border-top:1px solid #e5e7eb;'>
            <p style='color:#9ca3af;font-size:12px;margin:0;'>© " . date('Y') . " ASK Global Advisory Pvt. Ltd. · \"At ASK business problems end, solutions begin\"</p>
        </div>
    </div></body></html>";
}