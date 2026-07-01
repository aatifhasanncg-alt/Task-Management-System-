<?php
// config/mailer.php — PHPMailer helper
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

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
        $mail->Subject    = $subject;
        $mail->Body       = $htmlBody;
        $mail->AltBody    = strip_tags($htmlBody);
        $mail->SMTPDebug  = 2;
        $mail->Debugoutput = 'error_log';
        $mail->send();
        error_log("Mailer Success — sent to {$toEmail}");

        try {
            $db = getDB();
            $db->prepare("INSERT INTO email_logs(sent_to,subject,body,status) VALUES(?,?,?,?)")
               ->execute([$toEmail, $subject, $htmlBody, 'sent']);
        } catch (Exception $e) {}

        return true;

    } catch (Exception $e) {
        error_log("Mailer Error — {$toEmail}: " . $e->getMessage());
        try {
            $db = getDB();
            $db->prepare("INSERT INTO email_logs(sent_to,subject,body,status) VALUES(?,?,?,?)")
               ->execute([$toEmail, $subject, $e->getMessage(), 'failed']);
        } catch (Exception $e2) {}

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
    $subject = "[TAMS] New Task Assigned: {$task['task_number']}";
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
        <p>Please log in to TAMS to view and update this task.</p>
        <a href='" . ($task['url'] ?? APP_URL . '/staff/tasks/view.php?id=' . $task['id']) . "' style='display:inline-block;background:#c9a84c;color:#0a0f1e;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;'>View Task</a>
    ");
    return sendMail($user['email'], $user['full_name'], $subject, $html);
}

function emailTaskTransferred(array $user, array $task, string $remarks): bool {
    $subject = "[TAMS] Task Transferred to You: {$task['task_number']}";

    $remarksHtml = $remarks
        ? "<div style='background:#f5f3ff;border-left:4px solid #8b5cf6;padding:12px 16px;border-radius:6px;margin:16px 0;'>
               <strong style='color:#6d28d9;'>Transfer Note / Instructions:</strong>
               <p style='margin:6px 0 0;color:#374151;'>" . nl2br(htmlspecialchars($remarks)) . "</p>
           </div>"
        : '';

    $viewUrl = $task['url'] ?? (APP_URL . '/staff/tasks/view.php?id=' . $task['id']);

    $html = emailWrapper("
        <h2 style='color:#0a0f1e;'>Task Transferred to You</h2>
        <p>Dear <strong>{$user['full_name']}</strong>,</p>
        <p>Task <strong>{$task['task_number']}</strong> — <em>" . htmlspecialchars($task['title']) . "</em>
           has been transferred to the <strong>" . htmlspecialchars($task['department']) . "</strong> department and assigned to you.</p>
        {$remarksHtml}
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:8px;background:#f9fafb;font-weight:600;width:140px;'>Task #</td><td style='padding:8px;border-bottom:1px solid #e5e7eb;'>{$task['task_number']}</td></tr>
            <tr><td style='padding:8px;background:#f9fafb;font-weight:600;'>Title</td><td style='padding:8px;border-bottom:1px solid #e5e7eb;'>" . htmlspecialchars($task['title']) . "</td></tr>
            <tr><td style='padding:8px;background:#f9fafb;font-weight:600;'>Department</td><td style='padding:8px;border-bottom:1px solid #e5e7eb;'>" . htmlspecialchars($task['department']) . "</td></tr>
            <tr><td style='padding:8px;background:#f9fafb;font-weight:600;'>Status</td><td style='padding:8px;border-bottom:1px solid #e5e7eb;'>" . htmlspecialchars($task['status']) . "</td></tr>
            <tr><td style='padding:8px;background:#f9fafb;font-weight:600;'>Due Date</td><td style='padding:8px;border-bottom:1px solid #e5e7eb;'>" . ($task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : 'Not set') . "</td></tr>
            <tr><td style='padding:8px;background:#f9fafb;font-weight:600;'>Company</td><td style='padding:8px;'>" . htmlspecialchars($task['company'] ?? '—') . "</td></tr>
        </table>
        <a href='{$viewUrl}' style='display:inline-block;background:#8b5cf6;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;'>View Task</a>
    ");
    return sendMail($user['email'], $user['full_name'], $subject, $html);
}

function emailPasswordReset(array $user, string $newPassword): bool {
    $subject = "[TAMS] Your Password Has Been Reset";
    $html = emailWrapper("
        <h2 style='color:#0a0f1e;'>Password Reset</h2>
        <p>Dear <strong>{$user['full_name']}</strong>,</p>
        <p>Your TAMS password has been reset by an administrator.</p>
        <p><strong>New Password:</strong> <code style='background:#f3f4f6;padding:4px 8px;border-radius:4px;font-size:16px;'>{$newPassword}</code></p>
        <p>Please log in and change your password immediately.</p>
        <a href='" . APP_URL . "/auth/login.php' style='display:inline-block;background:#c9a84c;color:#0a0f1e;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;'>Login Now</a>
    ");
    return sendMail($user['email'], $user['full_name'], $subject, $html);
}

// ══════════════════════════════════════════════════════════════
// emailWorkPlanStatus() — sent when a plan is approved/rejected
//
// $user   : ['email', 'full_name']
// $plan   : [
//     'week'         => int,
//     'week_start'   => 'Y-m-d',
//     'week_end'     => 'Y-m-d',
//     'entry_count'  => int,
//     'total_hours'  => float,
//     'status'       => 'approved'|'rejected',
//     'remarks'      => string,
//     'reviewer'     => string,   (full_name of admin who acted)
// ]
// $title   : notification title (used as email subject)
// $message : plain-text summary line
// $link    : URL to the plan view page
// ══════════════════════════════════════════════════════════════
function emailWorkPlanStatus(
    array  $user,
    array  $plan,
    string $title,
    string $message,
    string $link = ''
): bool {
    $status   = $plan['status']       ?? 'approved';
    $week     = (int)($plan['week']   ?? 0);
    $remarks  = trim($plan['remarks'] ?? '');
    $reviewer = htmlspecialchars($plan['reviewer']    ?? 'Admin');
    $entries  = (int)($plan['entry_count']            ?? 0);
    $hours    = number_format((float)($plan['total_hours'] ?? 0), 1);

    $weekRange = '';
    if (!empty($plan['week_start']) && !empty($plan['week_end'])) {
        $weekRange = date('d M', strtotime($plan['week_start']))
                   . ' – '
                   . date('d M Y', strtotime($plan['week_end']));
    }

    // Colour scheme per status
    if ($status === 'approved') {
        $subject      = "[TAMS] Work Plan Approved - Week {$week}";
        $accentColor  = '#1D9E75';
        $statusBg     = '#E1F5EE';
        $statusTxt    = '#0F6E56';
        $statusLabel  = 'Approved ✅';
        $actionLabel  = 'View Approved Plan';
        $remarksLabel = 'Approval Note';
    } else {
        $subject      = "[TAMS] Work Plan Rejected - Week {$week}";
        $accentColor  = '#E24B4A';
        $statusBg     = '#FCEBEB';
        $statusTxt    = '#A32D2D';
        $statusLabel  = 'Rejected ❌';
        $actionLabel  = 'Revise &amp; Resubmit';
        $remarksLabel = 'Rejection Reason';
    }

    $remarksHtml = $remarks
        ? "<div style='background:#f9fafb;border-left:4px solid {$accentColor};
                       padding:12px 16px;border-radius:0 6px 6px 0;margin:16px 0;'>
               <strong style='color:#374151;font-size:13px;'>{$remarksLabel}:</strong>
               <p style='margin:6px 0 0;color:#6b7280;font-size:13px;'>
                   " . nl2br(htmlspecialchars($remarks)) . "
               </p>
           </div>"
        : '';

    $actionBtn = $link
        ? "<a href='{$link}'
               style='display:inline-block;background:{$accentColor};color:#fff;
                      padding:10px 24px;border-radius:8px;text-decoration:none;
                      font-weight:700;margin-top:4px;font-size:13px;'>
               {$actionLabel}
           </a>"
        : '';

    $weekRow = $weekRange
        ? "<tr>
               <td style='padding:8px 12px;background:#f9fafb;font-weight:600;font-size:12px;
                           color:#6b7280;border-bottom:1px solid #e5e7eb;'>Period</td>
               <td style='padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;'>
                   {$weekRange}
               </td>
           </tr>"
        : '';

    $html = emailWrapper("
        <h2 style='color:#0a0f1e;margin-top:0;'>Work Plan {$statusLabel}</h2>
        <p style='color:#4b5563;'>
            Dear <strong>" . htmlspecialchars($user['full_name']) . "</strong>,
        </p>
        <p style='color:#4b5563;margin-bottom:20px;'>
            Your submitted work plan has been reviewed by <strong>{$reviewer}</strong>.
        </p>

        <!-- Status banner -->
        <div style='background:{$statusBg};border:1px solid {$accentColor}33;
                    border-radius:10px;padding:16px 20px;margin-bottom:20px;
                    display:flex;align-items:center;gap:14px;'>
            <div style='width:44px;height:44px;border-radius:50%;background:{$accentColor};
                        display:flex;align-items:center;justify-content:center;
                        flex-shrink:0;font-size:20px;'>
                " . ($status === 'approved' ? '✅' : '❌') . "
            </div>
            <div>
                <div style='font-size:16px;font-weight:700;color:{$statusTxt};'>
                    Plan {$statusLabel}
                </div>
                <div style='font-size:12px;color:{$statusTxt};opacity:.8;margin-top:2px;'>
                    Reviewed on " . date('d M Y, h:i A') . "
                </div>
            </div>
        </div>

        <!-- Plan details -->
        <table style='width:100%;border-collapse:collapse;margin-bottom:16px;'>
            <tr>
                <td style='padding:8px 12px;background:#f9fafb;font-weight:600;font-size:12px;
                            color:#6b7280;width:140px;border-bottom:1px solid #e5e7eb;'>Week</td>
                <td style='padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;'>
                    Week {$week}
                </td>
            </tr>
            {$weekRow}
            <tr>
                <td style='padding:8px 12px;background:#f9fafb;font-weight:600;font-size:12px;
                            color:#6b7280;border-bottom:1px solid #e5e7eb;'>Visits</td>
                <td style='padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;'>
                    {$entries} client visit(s)
                </td>
            </tr>
            <tr>
                <td style='padding:8px 12px;background:#f9fafb;font-weight:600;font-size:12px;
                            color:#6b7280;'>Hours</td>
                <td style='padding:8px 12px;font-size:13px;font-weight:700;color:#c9a84c;'>
                    {$hours}h planned
                </td>
            </tr>
        </table>

        {$remarksHtml}
        {$actionBtn}

        <p style='color:#9ca3af;font-size:11px;margin-top:24px;'>
            This is an automated notification from TAMS. Please do not reply to this email.
        </p>
    ");

    return sendMail($user['email'], $user['full_name'], $subject, $html);
}

// ── Work plan created / assigned notification email ───────────
// $plan must contain: week, month, message, url
function emailWorkPlan(array $user, array $plan): bool {
    $subject = "[TAMS] Work Plan Notification";

    $html = emailWrapper("
        <h2 style='color:#0a0f1e;'>Work Plan Notification</h2>
        <p>Dear <strong>{$user['full_name']}</strong>,</p>

        <p>" . nl2br(htmlspecialchars($plan['message'] ?? '')) . "</p>

        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr>
                <td style='padding:8px;background:#f9fafb;font-weight:600;'>Week</td>
                <td style='padding:8px;border-bottom:1px solid #e5e7eb;'>Week " . htmlspecialchars((string)($plan['week'] ?? '')) . "</td>
            </tr>
            <tr>
                <td style='padding:8px;background:#f9fafb;font-weight:600;'>Month</td>
                <td style='padding:8px;border-bottom:1px solid #e5e7eb;'>" . htmlspecialchars($plan['month'] ?? '') . "</td>
            </tr>
        </table>

        <a href='" . htmlspecialchars($plan['url'] ?? '') . "'
           style='display:inline-block;background:#c9a84c;color:#0a0f1e;
                  padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;'>
           View Plan
        </a>
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
                <div style='color:white;font-weight:700;font-size:16px;'>TAMS</div>
                <div style='color:#8899aa;font-size:12px;'>ASK Global Advisory Pvt. Ltd.</div>
            </div>
        </div>
        <div style='padding:32px;'>{$content}</div>
        <div style='background:#f9fafb;padding:16px 32px;text-align:center;border-top:1px solid #e5e7eb;'>
            <p style='color:#9ca3af;font-size:12px;margin:0;'>© " . date('Y') . " ASK Global Advisory Pvt. Ltd. · \"You and Us working Together\"</p>
        </div>
    </div></body></html>";
}