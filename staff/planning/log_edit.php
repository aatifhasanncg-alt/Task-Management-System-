<?php
/**
 * staff/planning/log_edit.php — Staff: Edit Visit Log
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

$logId = (int) ($_GET['id'] ?? 0);
if (!$logId) {
    header('Location: log_list.php');
    exit;
}

$logStmt = $db->prepare("
    SELECT wl.*, c.company_name FROM work_logs wl
    LEFT JOIN companies c ON c.id=wl.client_id
    WHERE wl.id=? AND wl.user_id=?
");
$logStmt->execute([$logId, $uid]);
$log = $logStmt->fetch();
if (!$log) {
    header('Location: log_list.php');
    exit;
}

$branchId = (int) $user['branch_id'];
$companies = $db->prepare("SELECT id, company_name, company_code, pan_number FROM companies WHERE is_active=1 ORDER BY company_name");
$companies->execute([]);
$companies = $companies->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $clientId = (int) ($_POST['client_id'] ?? 0);
    $logDate = $_POST['log_date'] ?? '';
    $timeIn = $_POST['time_in'] ?: null;
    $timeOut = $_POST['time_out'] ?: null;
    $visitStatus = $_POST['visit_status'] ?? 'visited';
    $workDesc = trim($_POST['work_description'] ?? '');

    if (!$clientId)
        $errors[] = 'Please select a client.';
    if (!$logDate)
        $errors[] = 'Log date is required.';

    $durHours = 0;
    if ($timeIn && $timeOut) {
        $diff = strtotime($timeOut) - strtotime($timeIn);
        $durHours = round($diff / 3600, 2);
        if ($durHours < 0)
            $errors[] = 'Time Out must be after Time In.';
    }

    $dateObj = new DateTime($logDate);
    $weekNum = (int) ceil((int) $dateObj->format('j') / 7);
    $monthYear = $dateObj->format('Y-m');
    $dow = $dateObj->format('l');

    if (!$errors) {
        $upd = $db->prepare("
            UPDATE work_logs SET
              client_id=?, log_date=?, day_of_week=?, week_number=?, month_year=?,
              time_in=?, time_out=?, duration_hours=?,
              work_description=?, visit_status=?, updated_at=NOW()
            WHERE id=? AND user_id=?
        ");
        $upd->execute([
            $clientId,
            $logDate,
            $dow,
            $weekNum,
            $monthYear,
            $timeIn,
            $timeOut,
            $durHours,
            $workDesc,
            $visitStatus,
            $logId,
            $uid
        ]);

        // ── Fetch updated client name ──────────────────────────────────────
        $clientStmt = $db->prepare("SELECT company_name FROM companies WHERE id=?");
        $clientStmt->execute([$clientId]);
        $clientName = $clientStmt->fetchColumn() ?: '—';

        // ── Find supervisors for this branch ───────────────────────────────
        // ── Find supervisors for this branch ───────────────────────────────
        $supStmt = $db->prepare("
            SELECT u.id, u.full_name, u.email
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.branch_id = ?
            AND r.role_name = 'admin'
            AND u.is_active = 1
        ");
        $supStmt->execute([$branchId]);
        $supervisors = $supStmt->fetchAll();

        $staffName = $user['full_name'] ?? ('User #' . $uid);
        $logDateFmt = date('d M Y', strtotime($logDate));
        $logUrl = APP_URL . '/admin/planning/log_list.php?month=' . $monthYear;

        $statusLabels = [
            'visited' => 'Visited',
            'missed' => 'Missed',
            'rescheduled' => 'Rescheduled',
        ];
        $statusLabel = $statusLabels[$visitStatus] ?? $visitStatus;
        $durationText = $durHours > 0 ? $durHours . ' hrs' : '—';

        foreach ($supervisors as $sup) {

            // ── 1. In-app notification ─────────────────────────────────────
            $notifMsg = "{$staffName} edited a visit log for {$clientName} on {$logDateFmt} ({$statusLabel}).";
            $notifStmt = $db->prepare("
                INSERT INTO notifications
                    (user_id, type, title, message, link, is_read, created_at)
                VALUES
                    (?, 'system', 'Visit Log Edited', ?, ?, 0, NOW())
            ");
            $notifStmt->execute([$sup['id'], $notifMsg, $logUrl]);

            // ── 2. Email notification ──────────────────────────────────────
            if (!empty($sup['email'])) {
                sendLogEditEmail($sup, [
                    'staff_name' => $staffName,
                    'client_name' => $clientName,
                    'log_date' => $logDateFmt,
                    'time_in' => $timeIn ? date('h:i A', strtotime($timeIn)) : '—',
                    'time_out' => $timeOut ? date('h:i A', strtotime($timeOut)) : '—',
                    'duration' => $durationText,
                    'status' => $statusLabel,
                    'description' => $workDesc ?: '—',
                    'log_url' => $logUrl,
                ]);
            }
        }

        setFlash('success', 'Log updated successfully!');
        header('Location: log_list.php?month=' . $monthYear);
        exit;
    }
}

// ── Email helper ───────────────────────────────────────────────────────────────
function sendLogEditEmail(array $supervisor, array $data): void
{
    $to = $supervisor['email'];
    $supName = htmlspecialchars($supervisor['full_name'] ?? 'Supervisor');
    $subject = "Visit Log Edited — {$data['client_name']} ({$data['log_date']})";

    $body = '
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Visit Log Edited</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:\'Segoe UI\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:32px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;
           overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);max-width:600px;width:100%;">

      <!-- Header -->
      <tr>
        <td style="background:linear-gradient(135deg,#1e2a3a 0%,#2d3f55 100%);
                   padding:28px 32px;text-align:center;">
          <div style="font-size:22px;font-weight:700;color:#c9a84c;letter-spacing:.5px;">
            📋 Visit Log Edited
          </div>
          <div style="font-size:13px;color:#94a3b8;margin-top:4px;">Staff Activity Notification</div>
        </td>
      </tr>

      <!-- Greeting -->
      <tr>
        <td style="padding:28px 32px 12px;">
          <p style="margin:0;font-size:15px;color:#374151;">
            Hi <strong>' . $supName . '</strong>,
          </p>
          <p style="margin:10px 0 0;font-size:14px;color:#6b7280;line-height:1.6;">
            A staff member has <strong style="color:#c9a84c;">edited a client visit log</strong>.
            Here are the updated details:
          </p>
        </td>
      </tr>

      <!-- Details card -->
      <tr>
        <td style="padding:12px 32px 24px;">
          <table width="100%" cellpadding="0" cellspacing="0"
                 style="background:#f9fafb;border-radius:10px;border:1px solid #e5e7eb;overflow:hidden;">
            <tr style="background:#f1f5f9;">
              <td colspan="2" style="padding:10px 16px;font-size:12px;font-weight:700;
                  color:#64748b;letter-spacing:.6px;text-transform:uppercase;">
                Log Details
              </td>
            </tr>
            ' . buildEmailRow('👤 Staff', $data['staff_name'])
        . buildEmailRow('🏢 Client', $data['client_name'])
        . buildEmailRow('📅 Date', $data['log_date'])
        . buildEmailRow('🕐 Time In', $data['time_in'])
        . buildEmailRow('🕔 Time Out', $data['time_out'])
        . buildEmailRow('⏱ Duration', $data['duration'])
        . buildEmailRow('📌 Status', $data['status'])
        . buildEmailRow('📝 Description', $data['description'], true) . '
          </table>
        </td>
      </tr>

      <!-- CTA -->
      <tr>
        <td style="padding:0 32px 32px;text-align:center;">
          <a href="' . htmlspecialchars($data['log_url']) . '"
             style="display:inline-block;background:linear-gradient(135deg,#c9a84c,#e0bc6a);
                    color:#1e2a3a;font-weight:700;font-size:14px;padding:12px 32px;
                    border-radius:8px;text-decoration:none;letter-spacing:.3px;">
            View All Logs →
          </a>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f8fafc;border-top:1px solid #e5e7eb;
                   padding:16px 32px;text-align:center;">
          <p style="margin:0;font-size:11px;color:#9ca3af;">
            This is an automated notification. Please do not reply to this email.
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>';

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . APP_NAME . " <no-reply@" . parse_url(APP_URL, PHP_URL_HOST) . ">\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

    mail($to, $subject, $body, $headers);
}

function buildEmailRow(string $label, string $value, bool $wrap = false): string
{
    static $odd = true;
    $bg = $odd ? '#ffffff' : '#f9fafb';
    $odd = !$odd;
    $vStyle = $wrap
        ? 'font-size:13px;color:#374151;line-height:1.5;white-space:pre-wrap;word-break:break-word;'
        : 'font-size:13px;color:#374151;font-weight:600;';

    return '
    <tr style="background:' . $bg . ';">
      <td style="padding:10px 16px;font-size:12px;color:#6b7280;white-space:nowrap;width:130px;">
        ' . htmlspecialchars($label) . '
      </td>
      <td style="padding:10px 16px;border-left:1px solid #e5e7eb;' . $vStyle . '">
        ' . nl2br(htmlspecialchars($value)) . '
      </td>
    </tr>';
}

// ── Pre-fill from existing log or POST ────────────────────────────────────────
$f = [
    'client_id' => $_POST['client_id'] ?? $log['client_id'],
    'log_date' => $_POST['log_date'] ?? $log['log_date'],
    'time_in' => $_POST['time_in'] ?? ($log['time_in'] ? substr($log['time_in'], 0, 5) : ''),
    'time_out' => $_POST['time_out'] ?? ($log['time_out'] ? substr($log['time_out'], 0, 5) : ''),
    'visit_status' => $_POST['visit_status'] ?? $log['visit_status'],
    'work_description' => $_POST['work_description'] ?? $log['work_description'],
];

$pageTitle = 'Edit Log';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<div class="app-wrapper">
    <?php include '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-briefcase"></i> Consulting</div>
                        <h4>Edit Client Visit</h4>
                        <p><?= htmlspecialchars($log['company_name'] ?? '—') ?> ·
                            <?= date('d M Y', strtotime($log['log_date'])) ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="log_list.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-history me-1"></i> My Logs
                        </a>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="cn-alert cn-alert-danger" style="margin-bottom:16px;">
                    <div style="font-weight:700;font-size:.84rem;margin-bottom:5px;">
                        <i class="fas fa-exclamation-circle me-1"></i>Please fix the following:
                    </div>
                    <ul style="margin:0;padding-left:1.2rem;font-size:.8rem;">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="logForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

                    <!-- LEFT -->
                    <div>
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-clipboard-list me-2" style="color:var(--gold)"></i>Visit Details
                                </span>
                            </div>
                            <div style="padding:16px 18px;">
                                <div class="row g-3">

                                    <div class="col-md-8">
                                        <label class="cn-label">Client <span class="required-star">*</span></label>
                                        <select name="client_id" id="clientSelect" class="cn-input" required>
                                            <option value="">— Select Client —</option>
                                            <?php foreach ($companies as $c): ?>
                                                <option value="<?= $c['id'] ?>" <?= $f['client_id'] == $c['id'] ? 'selected' : '' ?>
                                                    data-pan="<?= htmlspecialchars($c['pan_number'] ?? '') ?>">
                                                    <?= htmlspecialchars($c['company_name']) ?>
                                                    <?= $c['company_code'] ? ' — ' . $c['company_code'] : '' ?>
                                                    <?= !empty($c['pan_number']) ? ' · PAN: ' . $c['pan_number'] : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="cn-label">Log Date <span class="required-star">*</span></label>
                                        <input type="date" name="log_date" class="cn-input" required
                                            value="<?= htmlspecialchars($f['log_date']) ?>" max="<?= date('Y-m-d') ?>">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="cn-label">Time In</label>
                                        <input type="time" name="time_in" id="timeIn" class="cn-input"
                                            value="<?= htmlspecialchars($f['time_in']) ?>" onchange="calcDuration()">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="cn-label">Time Out</label>
                                        <input type="time" name="time_out" id="timeOut" class="cn-input"
                                            value="<?= htmlspecialchars($f['time_out']) ?>" onchange="calcDuration()">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="cn-label">Duration</label>
                                        <div
                                            style="background:#f9fafb;border-radius:8px;padding:10px;text-align:center;border:1.5px solid #f1f5f9;">
                                            <div style="font-size:1.3rem;font-weight:800;color:#c9a84c;"
                                                id="durationDisp">—</div>
                                            <div style="font-size:.68rem;color:#9ca3af;">hours</div>
                                        </div>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="cn-label">Visit Status</label>
                                        <select name="visit_status" class="cn-input">
                                            <option value="visited" <?= $f['visit_status'] === 'visited' ? 'selected' : '' ?>>✅ Visited</option>
                                            <option value="missed" <?= $f['visit_status'] === 'missed' ? 'selected' : '' ?>>❌ Missed</option>
                                            <option value="rescheduled" <?= $f['visit_status'] === 'rescheduled' ? 'selected' : '' ?>>🔄 Rescheduled</option>
                                        </select>
                                    </div>

                                    <div class="col-12">
                                        <label class="cn-label">Work Description</label>
                                        <textarea name="work_description" class="cn-input" rows="3"
                                            placeholder="What work was done during this visit…"><?= htmlspecialchars($f['work_description']) ?></textarea>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT -->
                    <div>
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-clock me-2" style="color:var(--gold)"></i>Session
                                </span>
                            </div>
                            <div style="padding:14px 16px;">
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                                    <div
                                        style="text-align:center;background:#f9fafb;border-radius:8px;padding:12px 6px;">
                                        <div style="font-size:1.4rem;font-weight:800;color:#c9a84c;"
                                            id="durationDispSide">—</div>
                                        <div style="font-size:.7rem;color:#9ca3af;margin-top:2px;">Hours</div>
                                    </div>
                                    <div
                                        style="text-align:center;background:#f9fafb;border-radius:8px;padding:12px 6px;">
                                        <div style="font-size:.9rem;font-weight:700;color:#374151;"><?= date('d M') ?>
                                        </div>
                                        <div style="font-size:.7rem;color:#9ca3af;margin-top:2px;">Today</div>
                                    </div>
                                </div>
                                <div style="font-size:.75rem;color:#6b7280;display:flex;flex-direction:column;gap:6px;
                        background:#f9fafb;border-radius:8px;padding:10px 12px;">
                                    <div style="font-weight:600;color:#374151;font-size:.75rem;margin-bottom:2px;">
                                        <i class="fas fa-info-circle me-1 text-warning"></i>Status Guide
                                    </div>
                                    <span>✅ <strong>Visited</strong> — Visit completed</span>
                                    <span>❌ <strong>Missed</strong> — Client unavailable</span>
                                    <span>🔄 <strong>Rescheduled</strong> — New date agreed</span>
                                </div>
                            </div>
                        </div>

                        <div class="cn-panel">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-save me-2" style="color:var(--gold)"></i>Save
                                </span>
                            </div>
                            <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px;">
                                <button type="submit" class="cn-btn cn-btn-gold" style="justify-content:center;">
                                    <i class="fas fa-save"></i> Update Log
                                </button>
                                <a href="log_list.php" class="cn-btn cn-btn-out" style="justify-content:center;">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                </div>
            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
    new TomSelect('#clientSelect', {
        placeholder: 'Search by name, code or PAN...',
        maxOptions: 500,
        allowEmptyOption: true,
        searchField: ['text']   // searches full option text including PAN
    });

    function calcDuration() {
        const tin = document.getElementById('timeIn').value;
        const tout = document.getElementById('timeOut').value;
        const disp = document.getElementById('durationDisp');
        const side = document.getElementById('durationDispSide');
        if (tin && tout) {
            const diff = (new Date('1970-01-01T' + tout) - new Date('1970-01-01T' + tin)) / 3600000;
            const val = diff > 0 ? diff.toFixed(2) + 'h' : '—';
            const col = diff > 0 ? '#c9a84c' : '#ef4444';
            disp.textContent = val; disp.style.color = col;
            side.textContent = val; side.style.color = col;
        } else {
            disp.textContent = '—'; disp.style.color = '#9ca3af';
            side.textContent = '—'; side.style.color = '#9ca3af';
        }
    }
    calcDuration();
</script>
<?php include '../../includes/footer.php'; ?>