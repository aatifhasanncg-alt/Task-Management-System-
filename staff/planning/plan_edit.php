<?php
/**
 * consulting/staff/plan_edit.php — Staff: Edit Weekly Work Plan
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();
function planEmailRow(string $label, string $value): string
{
    return str_pad($label, 16) . ': ' . $value . "\n";
}

function sendPlanEditEmail(array $supervisor, array $data): void
{
    $to = $supervisor['email'];
    $supName = $supervisor['full_name'] ?? 'Supervisor';
    $subject = 'Work Plan Edited - ' . $data['staff_name']
        . ' Week ' . $data['week_number']
        . ' (' . $data['month_label'] . ')';

    $rows = planEmailRow('Staff', $data['staff_name']);
    $rows .= planEmailRow('Month', $data['month_label']);
    $rows .= planEmailRow('Week', 'Week ' . $data['week_number']);
    $rows .= planEmailRow('Date Range', $data['week_range']);
    $rows .= planEmailRow('Visit Entries', $data['entry_count'] . ' clients');
    $rows .= planEmailRow('Total Hours', $data['total_hours'] . ' hrs');
    $rows .= planEmailRow('Remarks', $data['remarks']);

    $body = "Hi {$supName},\n\n";
    $body .= "A staff member has edited their weekly work plan. Here are the updated details:\n\n";
    $body .= str_repeat('-', 40) . "\n";
    $body .= $rows;
    $body .= str_repeat('-', 40) . "\n\n";
    $body .= "View the plan here:\n" . $data['plan_url'] . "\n\n";
    $body .= "This is an automated notification. Please do not reply to this email.\n";

    $host = parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "From: " . APP_NAME . " <no-reply@" . $host . ">\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

    mail($to, $subject, $body, $headers);
}
$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];

$planId = (int) ($_GET['id'] ?? 0);
if (!$planId) {
    header('Location: plan_list.php');
    exit;
}

/* ── FETCH PLAN (SELF ONLY) ───────────────── */
$planSt = $db->prepare("
    SELECT * FROM work_plans
    WHERE id=? AND user_id=?
");
$planSt->execute([$planId, $uid]);
$plan = $planSt->fetch();

if (!$plan) {
    die('Plan not found or access denied.');
}

/* ── FETCH ENTRIES ───────────────── */
$entrySt = $db->prepare("
    SELECT * FROM work_plan_entries WHERE plan_id=?
");
$entrySt->execute([$planId]);
$entriesData = $entrySt->fetchAll();

/* ── MONTH ───────────────── */
$monthStart = $plan['plan_month'];
$monthDate = new DateTime($monthStart);
$month = $monthDate->format('Y-m');
$monthLabel = $monthDate->format('F Y');

/* ── COMPANIES ───────────────── */
$companies = $db->query("
    SELECT id, company_name, company_code, pan_number
    FROM companies WHERE is_active=1
    ORDER BY company_name
")->fetchAll();

/* ── UPDATE LOGIC ───────────────── */
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $remarks = trim($_POST['remarks'] ?? '');
    $entries = $_POST['entries'] ?? [];

    if (empty($entries)) {
        $errors[] = 'Add at least one entry.';
    }

    if (!$errors) {
        $db->beginTransaction();
        try {
            // Update plan
            $up = $db->prepare("
                UPDATE work_plans SET remarks=? WHERE id=?
            ");
            $up->execute([$remarks, $planId]);

            // Delete old entries
            $db->prepare("DELETE FROM work_plan_entries WHERE plan_id=?")
                ->execute([$planId]);

            // Insert new entries
            $ins = $db->prepare("
                INSERT INTO work_plan_entries
                (plan_id, client_id, client_code, assigned_to, plan_date,
                 day_of_week, planned_time_in, planned_time_out, planned_hours, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");

            foreach ($entries as $e) {
                $cid = (int) $e['client_id'];
                if (!$cid)
                    continue;

                $date = $e['plan_date'];
                $tin = $e['time_in'] ?: null;
                $tout = $e['time_out'] ?: null;

                $hrs = 0;
                if ($tin && $tout) {
                    $hrs = round((strtotime($tout) - strtotime($tin)) / 3600, 2);
                }

                // get client code
                $cc = '';
                $pan = '';
                foreach ($companies as $c) {
                    if ($c['id'] == $cid) {
                        $cc = $c['company_code'];
                        break;
                    }
                }

                $ins->execute([
                    $planId,
                    $cid,
                    $cc,
                    $uid,
                    $date,
                    date('l', strtotime($date)),
                    $tin,
                    $tout,
                    $hrs,
                    trim($e['notes'] ?? '')
                ]);
            }

            $db->commit();

            // ── Supervisor Notifications ───────────────────────────────────────
            try {
                require_once '../../config/notify.php';

                $branchId  = (int) $user['branch_id'];
                $staffName = $user['full_name'] ?? ('User #' . $uid);
                $weekRange = date('d M', strtotime($plan['week_start_date']))
                        . ' – '
                        . date('d M Y', strtotime($plan['week_end_date']));

                $entryCount = count(array_filter($entries, fn($e) => !empty($e['client_id'])));
                $totalHours = 0;
                foreach ($entries as $e) {
                    if (!empty($e['time_in']) && !empty($e['time_out'])) {
                        $diff = (strtotime($e['time_out']) - strtotime($e['time_in'])) / 3600;
                        if ($diff > 0) $totalHours += $diff;
                    }
                }
                $totalHours = round($totalHours, 2);

                $planUrl = APP_URL . '/admin/planning/plan_view.php?id=' . $planId;

                $supStmt = $db->prepare("
                    SELECT u.id FROM users u
                    JOIN roles r ON r.id = u.role_id
                    WHERE u.branch_id = ? AND r.role_name = 'admin' AND u.is_active = 1
                ");
                $supStmt->execute([$branchId]);
                $supervisors = $supStmt->fetchAll();

                foreach ($supervisors as $sup) {
                    $notifMsg  = "{$staffName} edited their work plan for Week {$plan['week_number']} ({$weekRange}).\n";
                    $notifMsg .= "Entries: {$entryCount} clients · Total planned: {$totalHours} hrs";
                    if ($remarks) $notifMsg .= "\nRemarks: {$remarks}";

                    notify(
                        (int) $sup['id'],
                        'Work Plan Edited',
                        $notifMsg,
                        'system',
                        $planUrl,
                        true,
                        ['template' => 'generic']
                    );
                }
            } catch (Exception $notifEx) {
                error_log('Plan edit notification error: ' . $notifEx->getMessage());
            }
            // ── End Notifications ──────────────────────────────────────────────

            setFlash('success', 'Plan updated successfully!');
            header('Location: plan_list.php?month=' . $month);
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Work Plan';
include '../../includes/header.php';
?>

<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
    .entry-row {
        border-bottom: 1px solid #f1f5f9;
        padding: 14px 18px;
    }

    .entry-row:last-child {
        border-bottom: none;
    }

    .required-star {
        color: #ef4444;
    }

    .hrs-pill {
        background: #f9fafb;
        border-radius: 6px;
        padding: 5px 10px;
        font-size: .77rem;
        color: #9ca3af;
    }
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_staff.php'; ?>

    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>

        <div class="cn-wrap">

            <!-- TOP -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-briefcase"></i> Consulting</div>
                        <h4>Edit Work Plan</h4>
                        <p><?= htmlspecialchars($user['full_name']) ?> · <?= $monthLabel ?> · Week
                            <?= $plan['week_number'] ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="plan_list.php?month=<?= $month ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-list me-1"></i> My Plans
                        </a>
                        <a href="plan_view.php?id=<?= $planId ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eye me-1"></i> View Plan
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($errors): ?>
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

            <form method="POST" id="editForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

                    <!-- LEFT -->
                    <div>

                        <!-- Plan info -->
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-calendar-week me-2" style="color:var(--gold)"></i>Plan Details
                                </span>
                            </div>
                            <div style="padding:16px 18px;">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="cn-label">Month</label>
                                        <input type="text" class="cn-input" value="<?= $monthLabel ?>" disabled>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="cn-label">Week</label>
                                        <input type="text" class="cn-input" value="Week <?= $plan['week_number'] ?>"
                                            disabled>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="cn-label">Date Range</label>
                                        <input type="text" class="cn-input"
                                            value="<?= date('d M', strtotime($plan['week_start_date'])) ?> – <?= date('d M', strtotime($plan['week_end_date'])) ?>"
                                            disabled>
                                    </div>
                                    <div class="col-12">
                                        <label class="cn-label">Remarks / Notes</label>
                                        <textarea name="remarks" class="cn-input" rows="2"
                                            placeholder="Any notes for this week's plan…"><?= htmlspecialchars($plan['remarks'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Entries -->
                        <div class="card-mis mb-4">
                            <div class="card-mis-header d-flex justify-content-between align-items-center">
                                <h5><i class="fas fa-list-check text-warning me-2"></i>Client Visit Entries</h5>
                                <button type="button" class="btn btn-gold btn-sm" onclick="addEntry()">
                                    <i class="fas fa-plus me-1"></i> Add Entry
                                </button>
                            </div>
                            <div id="entries"></div>
                            <div id="emptyEntries" style="display:none;" class="text-center text-muted p-4">
                                <i class="fas fa-calendar-plus fa-2x mb-2 opacity-25"></i><br>
                                Click "Add Entry" to add visits
                            </div>
                        </div>

                    </div>

                    <!-- RIGHT -->
                    <div>

                        <!-- Summary -->
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-chart-pie me-2" style="color:var(--gold)"></i>Summary
                                </span>
                            </div>
                            <div style="padding:14px 16px;">
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
                                    <div
                                        style="text-align:center;background:#f9fafb;border-radius:8px;padding:12px 6px;">
                                        <div style="font-size:1.5rem;font-weight:800;color:#3b82f6;" id="totHrs">0.0h
                                        </div>
                                        <div style="font-size:.7rem;color:#9ca3af;margin-top:2px;">Total Hours</div>
                                    </div>
                                    <div
                                        style="text-align:center;background:#f9fafb;border-radius:8px;padding:12px 6px;">
                                        <div style="font-size:1.5rem;font-weight:800;color:#c9a84c;" id="entCnt">0</div>
                                        <div style="font-size:.7rem;color:#9ca3af;margin-top:2px;">Entries</div>
                                    </div>
                                </div>
                                <div
                                    style="background:rgba(16,185,129,.1);border-radius:7px;padding:9px 12px;font-size:.78rem;color:#10b981;font-weight:600;">
                                    <i class="fas fa-calendar me-1"></i>
                                    <?= date('d M', strtotime($plan['week_start_date'])) ?> –
                                    <?= date('d M', strtotime($plan['week_end_date'])) ?>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="cn-panel">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-save me-2" style="color:var(--gold)"></i>Save
                                </span>
                            </div>
                            <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px;">
                                <button type="submit" class="cn-btn cn-btn-gold" style="justify-content:center;">
                                    <i class="fas fa-save"></i> Update Plan
                                </button>
                                <a href="plan_list.php?month=<?= $month ?>" class="cn-btn cn-btn-out"
                                    style="justify-content:center;">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </div>

                    </div>
                </div><!-- /grid -->

            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
    let idx = 0;
    const weekStart = '<?= $plan['week_start_date'] ?>';
    const weekEnd = '<?= $plan['week_end_date'] ?>';

    // Build options HTML once
    const clientOptions = `
    <option value="">— Select Client —</option>
    <?php foreach ($companies as $c): ?>
    <option value="<?= $c['id'] ?>" data-pan="<?= htmlspecialchars($c['pan_number'] ?? '') ?>">
        <?= addslashes(htmlspecialchars($c['company_name'])) ?>
        <?= $c['company_code'] ? ' — ' . $c['company_code'] : '' ?>
        <?= !empty($c['pan_number']) ? ' · PAN: ' . addslashes($c['pan_number']) : '' ?>
    </option>
    <?php endforeach; ?>
`;

    function addEntry(data = {}) {
        const container = document.getElementById('entries');
        document.getElementById('emptyEntries').style.display = 'none';

        const div = document.createElement('div');
        div.className = 'entry-row';
        div.dataset.index = idx;

        div.innerHTML = `
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <span style="font-size:.82rem;font-weight:600;color:#c9a84c;">
            <i class="fas fa-building me-1"></i>Visit #<span class="entry-num">1</span>
        </span>
        <button type="button" onclick="removeEntry(this)"
                style="background:#fef2f2;border:none;color:#ef4444;border-radius:6px;padding:3px 9px;font-size:.78rem;cursor:pointer;">
            <i class="fas fa-trash"></i>
        </button>
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:10px;margin-bottom:8px;">
        <div>
            <label class="cn-label">Client <span class="required-star">*</span></label>
            <select name="entries[${idx}][client_id]" class="cn-input entry-client" required>
                ${clientOptions}
            </select>
        </div>
        <div>
            <label class="cn-label">Date <span class="required-star">*</span></label>
            <input type="date" name="entries[${idx}][plan_date]" class="cn-input entry-date"
                   min="${weekStart}" max="${weekEnd}"
                   value="${data.plan_date || ''}" required>
        </div>
        <div>
            <label class="cn-label">Time In</label>
            <input type="time" name="entries[${idx}][time_in]" class="cn-input time-in"
                   value="${data.planned_time_in || ''}">
        </div>
        <div>
            <label class="cn-label">Time Out</label>
            <input type="time" name="entries[${idx}][time_out]" class="cn-input time-out"
                   value="${data.planned_time_out || ''}">
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end;">
        <div>
            <label class="cn-label">Notes</label>
            <input type="text" name="entries[${idx}][notes]" class="cn-input"
                   placeholder="Purpose of visit…" value="${(data.notes || '').replace(/"/g, '&quot;')}">
        </div>
        <div class="hrs-pill">
            <i class="fas fa-clock me-1" style="color:#c9a84c;"></i>
            <span class="planned-hrs">0.00h</span>
        </div>
    </div>`;

        container.appendChild(div);

        // TomSelect on client dropdown
        const sel = div.querySelector('.entry-client');
        const ts = new TomSelect(sel, {
            placeholder: 'Search by name, code or PAN…',
            maxOptions: 500,
            searchField: ['text']
        });

        // Pre-select existing client
        if (data.client_id) ts.setValue(String(data.client_id));

        // Time calc
        div.querySelectorAll('.time-in,.time-out').forEach(t =>
            t.addEventListener('change', () => calcHours(div))
        );

        // Calc immediately if preloaded
        if (data.planned_time_in && data.planned_time_out) calcHours(div);

        idx++;
        renumber();
        updateSummary();
    }


    function removeEntry(btn) {
        btn.closest('.entry-row').remove();
        renumber();
        updateSummary();
        if (!document.querySelectorAll('.entry-row').length)
            document.getElementById('emptyEntries').style.display = '';
    }

    function renumber() {
        document.querySelectorAll('.entry-num').forEach((el, i) => el.textContent = i + 1);
    }

    function calcHours(row) {
        const tin = row.querySelector('.time-in').value;
        const tout = row.querySelector('.time-out').value;
        if (tin && tout) {
            const diff = (new Date('1970-01-01T' + tout) - new Date('1970-01-01T' + tin)) / 3600000;
            row.querySelector('.planned-hrs').textContent = (diff > 0 ? diff : 0).toFixed(2) + 'h';
        } else {
            row.querySelector('.planned-hrs').textContent = '0.00h';
        }
        updateSummary();
    }

    function updateSummary() {
        let total = 0, cnt = 0;
        document.querySelectorAll('.entry-row').forEach(row => {
            cnt++;
            total += parseFloat(row.querySelector('.planned-hrs').textContent) || 0;
        });
        document.getElementById('totHrs').textContent = total.toFixed(1) + 'h';
        document.getElementById('entCnt').textContent = cnt;
    }

    // Preload existing entries
    <?php foreach ($entriesData as $e): ?>
    addEntry(<?= json_encode($e) ?>);
    <?php endforeach; ?>

    // Show empty state if no entries
    if (!document.querySelectorAll('.entry-row').length)
        document.getElementById('emptyEntries').style.display = '';
</script>

<?php include '../../includes/footer.php'; ?>