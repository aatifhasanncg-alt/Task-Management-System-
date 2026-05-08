<?php
/**
 * consulting/executive/plan_create.php — Executive: Create Plan for a Staff Member
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
require_once '../../config/notify.php';

requireAnyRole();

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];

$branchId = (int)$user['branch_id'];
$deptId   = (int)$user['department_id'];

$now   = new DateTime();
$today = $now->format('Y-m-d');
$month = $_GET['month'] ?? $now->format('Y-m');

// ── Staff list ────────────────────────────────────────────────
$staffList = $db->query("
    SELECT DISTINCT u.id, u.full_name, u.employee_id
    FROM users u
    WHERE u.is_active = 1
      AND (
          u.id = {$uid}
          OR u.id IN (
              SELECT u2.id FROM users u2
              JOIN departments d ON d.id = u2.department_id AND d.dept_code = 'CON'
              WHERE u2.is_active = 1
              UNION
              SELECT uda.user_id FROM user_department_assignments uda
              JOIN departments d ON d.id = uda.department_id AND d.dept_code = 'CON'
          )
      )
    ORDER BY u.full_name
")->fetchAll();

// ── Companies ─────────────────────────────────────────────────
$companiesStmt = $db->prepare(
    "SELECT id, company_name, company_code, pan_number FROM companies WHERE is_active=1 ORDER BY company_name"
);
$companiesStmt->execute();
$companies = $companiesStmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $targetUid  = (int)($_POST['target_user_id']  ?? 0);
    $weekNumber = (int)($_POST['week_number']      ?? 0);
    $weekStart  = trim($_POST['week_start_date']   ?? '');
    $weekEnd    = trim($_POST['week_end_date']     ?? '');

    // BUG FIX 1: plan_month came in as "YYYY-MM"; we must NOT append '-01' twice.
    // Store the raw "YYYY-MM" value and build the DATE string separately.
    $planMonthRaw = trim($_POST['plan_month'] ?? '');          // e.g. "2025-05"
    $planMonthDate = $planMonthRaw ? $planMonthRaw . '-01' : ''; // e.g. "2025-05-01"

    $autoSubmit = isset($_POST['auto_submit']);
    $entries    = $_POST['entries'] ?? [];

    // ── Validation ────────────────────────────────────────────
    if (!$targetUid)
        $errors[] = 'Please select a staff member.';
    if (!$weekNumber || $weekNumber < 1 || $weekNumber > 5)
        $errors[] = 'Week number must be between 1 and 5.';
    if (!$weekStart)
        $errors[] = 'Week start date is required.';
    if (!$weekEnd)
        $errors[] = 'Week end date is required.';
    if (!$planMonthRaw)
        $errors[] = 'Plan month is required.';
    if ($weekStart && $weekEnd && $weekStart > $weekEnd)
        $errors[] = 'Week start date cannot be after week end date.';
    if (empty($entries))
        $errors[] = 'At least one plan entry is required.';

    // Validate each entry
    if (!empty($entries)) {
        foreach ($entries as $i => $e) {
            $num = $i + 1;
            if (empty($e['client_id']))
                $errors[] = "Entry #{$num}: Client is required.";
            if (empty($e['plan_date']))
                $errors[] = "Entry #{$num}: Date is required.";
            // BUG FIX 2: Time-out must be after time-in if both are provided
            if (!empty($e['planned_time_in']) && !empty($e['planned_time_out'])) {
                if ($e['planned_time_out'] <= $e['planned_time_in'])
                    $errors[] = "Entry #{$num}: Time Out must be after Time In.";
            }
            // BUG FIX 3: Plan date must fall within the selected week range
            if (!empty($e['plan_date']) && $weekStart && $weekEnd) {
                if ($e['plan_date'] < $weekStart || $e['plan_date'] > $weekEnd)
                    $errors[] = "Entry #{$num}: Plan date must be within the selected week ({$weekStart} – {$weekEnd}).";
            }
        }
    }

    // BUG FIX 4: Duplicate check used "$planMonth . '-01'" but $planMonth was
    // already the raw "YYYY-MM" — so it was checking against "YYYY-MM-01" which
    // is correct, but only if the DB column stores full DATEs. Use $planMonthDate.
    if (!$errors) {
        $dup = $db->prepare(
            "SELECT id FROM work_plans WHERE user_id=? AND week_number=? AND plan_month=?"
        );
        $dup->execute([$targetUid, $weekNumber, $planMonthDate]);
        if ($dup->fetch())
            $errors[] = 'A plan for Week ' . $weekNumber . ' already exists for this staff member.';
    }

    if (!$errors) {
        // BUG FIX 5: Get target user's department_id from user_department_assignments first,
        // fall back to users.department_id.  Previously only checked users table.
        $staffDeptQ = $db->prepare("
            SELECT COALESCE(
                (SELECT uda.department_id
                 FROM user_department_assignments uda
                 JOIN departments d ON d.id = uda.department_id AND d.dept_code = 'CON'
                 WHERE uda.user_id = ?
                 LIMIT 1),
                u.department_id
            ) AS dept_id
            FROM users u WHERE u.id = ?
        ");
        $staffDeptQ->execute([$targetUid, $targetUid]);
        $staffDeptId = (int)$staffDeptQ->fetchColumn();

        // BUG FIX 6: status should respect auto_submit flag
        $planStatus = $autoSubmit ? 'approved' : 'draft';

        $ins = $db->prepare("
            INSERT INTO work_plans
                (user_id, supervisor_id, department_id, branch_id, week_number,
                 week_start_date, week_end_date, plan_month, status, approved_by, approved_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $targetUid,
            $uid,                                           // BUG FIX 7: store creator as supervisor
            $staffDeptId,
            $branchId,
            $weekNumber,
            $weekStart,
            $weekEnd,
            $planMonthDate,
            $planStatus,
            $autoSubmit ? $uid : null,                     // approved_by
            $autoSubmit ? date('Y-m-d H:i:s') : null,      // approved_at
        ]);
        $planId = (int)$db->lastInsertId();

        // BUG FIX 8: day_of_week was never set; calculate it from plan_date
        foreach ($entries as $e) {
            if (empty($e['client_id'])) continue;

            $planDate = $e['plan_date'] ?? '';
            $clientId = (int)$e['client_id'];
            $timeIn   = !empty($e['planned_time_in'])  ? $e['planned_time_in']  : null;
            $timeOut  = !empty($e['planned_time_out']) ? $e['planned_time_out'] : null;
            $notes    = trim($e['notes'] ?? '');

            $hours = 0;
            if ($timeIn && $timeOut) {
                $diff  = strtotime($timeOut) - strtotime($timeIn);
                $hours = $diff > 0 ? round($diff / 3600, 2) : 0;
            }

            // Calculate day_of_week from plan_date
            $dow = $planDate ? date('l', strtotime($planDate)) : 'Monday';

            // BUG FIX 9: also store client_code for denormalised lookup
            $clientCodeQ = $db->prepare("SELECT company_code FROM companies WHERE id=?");
            $clientCodeQ->execute([$clientId]);
            $clientCode = $clientCodeQ->fetchColumn() ?: null;

            $db->prepare("
                INSERT INTO work_plan_entries
                    (plan_id, assigned_to, client_id, client_code, plan_date, day_of_week,
                     planned_time_in, planned_time_out, planned_hours, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $planId, $targetUid, $clientId, $clientCode,
                $planDate, $dow, $timeIn, $timeOut, $hours, $notes
            ]);
        }

        // ── Notifications ────────────────────────────────────
        // Notify the staff member
        $staffNotifLink = APP_URL . '/staff/planning/plan_view.php?id=' . $planId;
        notify(
            $targetUid,
            'New Work Plan Created',
            $user['full_name'] . ' created a Week ' . $weekNumber . ' plan for you.',
            'task',
            $staffNotifLink,
            true,   // BUG FIX 10: was false — staff should receive the email
            [
                'template' => 'generic',
            ]
        );

        // Notify supervisor (managed_by) if different from creator
        try {
            $supQ = $db->prepare("SELECT managed_by, full_name FROM users WHERE id=?");
            $supQ->execute([$targetUid]);
            $staffRow = $supQ->fetch(PDO::FETCH_ASSOC);

            if (!empty($staffRow['managed_by'])) {
                $supervisorId = (int)$staffRow['managed_by'];
                if ($supervisorId !== $uid) {
                    $supNotifLink = APP_URL . '/admin/planning/plan_view.php?id=' . $planId;
                    notify(
                        $supervisorId,
                        'Work Plan Created for Staff',
                        $user['full_name'] . ' created a work plan for '
                            . htmlspecialchars($staffRow['full_name'] ?? 'a staff member')
                            . ' — Week ' . $weekNumber,
                        'task',
                        $supNotifLink,
                        false,
                        []
                    );
                }
            }
        } catch (Exception $ex) {
            error_log('Supervisor notification error: ' . $ex->getMessage());
        }

        logActivity(
            'Executive created plan for user #' . $targetUid,
            'consulting',
            'plan_id=' . $planId
        );

        setFlash('success', 'Work plan created and assigned successfully!');
        header('Location: plan_view.php?id=' . $planId);
        exit;
    }
}

$pageTitle = 'Create Plan for Staff';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="../../staff/planning/consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
.entry-row {
    background:#f9fafb;border-radius:8px;padding:12px;margin-bottom:8px;
    border:1.5px solid #f1f5f9;position:relative;transition:border-color .15s;
}
.entry-row:hover { border-color:#e5e7eb; }
.remove-entry {
    position:absolute;top:8px;right:8px;background:none;border:none;
    color:#9ca3af;cursor:pointer;font-size:.9rem;padding:2px 6px;border-radius:4px;
}
.remove-entry:hover { color:#ef4444;background:#fef2f2; }
.entry-num {
    position:absolute;top:10px;left:12px;font-size:.7rem;font-weight:700;
    color:#9ca3af;font-family:monospace;
}
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <!-- PAGE HERO -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-briefcase"></i> Executive · Consulting</div>
                        <h4>Create Work Plan</h4>
                        <p>Assign a work plan to a staff member · <?= date('d M Y') ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="plans.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-list me-1"></i> All Plans
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
                    <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST" id="planForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

                    <!-- ══ LEFT ══ -->
                    <div>

                        <!-- Staff + Week -->
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-user-tie me-2" style="color:var(--gold)"></i>Assign To
                                </span>
                            </div>
                            <div style="padding:16px 18px;">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="cn-label">Staff Member <span class="required-star">*</span></label>
                                        <select name="target_user_id" id="staffSelect" class="cn-input" required>
                                            <option value="">— Select Staff Member —</option>
                                            <?php foreach ($staffList as $sl): ?>
                                            <option value="<?= $sl['id'] ?>"
                                                <?= ($_POST['target_user_id'] ?? '') == $sl['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($sl['full_name']) ?>
                                                <?= $sl['employee_id'] ? ' — ' . $sl['employee_id'] : '' ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="staffBadge" style="display:none;margin-top:8px;padding:6px 10px;
                                             background:#eff6ff;border-radius:6px;font-size:.78rem;color:#1d4ed8;">
                                            <i class="fas fa-user-check me-1"></i>
                                            <span id="staffBadgeName"></span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="cn-label">Plan Month <span class="required-star">*</span></label>
                                        <!-- BUG FIX: name is "plan_month", value stays as YYYY-MM (no -01) -->
                                        <input type="month" name="plan_month" class="cn-input" required
                                            value="<?= htmlspecialchars($_POST['plan_month'] ?? $month) ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="cn-label">Week Number <span class="required-star">*</span></label>
                                        <select name="week_number" id="weekNumber" class="cn-input" required>
                                            <option value="">—</option>
                                            <?php for ($w = 1; $w <= 5; $w++): ?>
                                            <option value="<?= $w ?>"
                                                <?= ($_POST['week_number'] ?? '') == $w ? 'selected' : '' ?>>
                                                Week <?= $w ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="cn-label">Week Start <span class="required-star">*</span></label>
                                        <input type="date" name="week_start_date" id="weekStart" class="cn-input" required
                                            value="<?= htmlspecialchars($_POST['week_start_date'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="cn-label">Week End <span class="required-star">*</span></label>
                                        <input type="date" name="week_end_date" id="weekEnd" class="cn-input" required
                                            value="<?= htmlspecialchars($_POST['week_end_date'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Plan Entries -->
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd" style="justify-content:space-between;">
                                <span class="cn-panel-title">
                                    <i class="fas fa-clipboard-list me-2" style="color:var(--gold)"></i>Plan Entries
                                </span>
                                <button type="button" class="cn-btn cn-btn-blue cn-btn-sm" onclick="addEntry()">
                                    <i class="fas fa-plus"></i> Add Entry
                                </button>
                            </div>
                            <div style="padding:16px 18px;">
                                <div id="entriesContainer"></div>
                                <div id="noEntries" style="text-align:center;color:#9ca3af;font-size:.8rem;padding:20px 0;">
                                    <i class="fas fa-calendar-plus" style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                                    Click "Add Entry" to add client visits to this plan.
                                </div>
                            </div>
                        </div>

                    </div><!-- /LEFT -->

                    <!-- ══ RIGHT ══ -->
                    <div>
                        <!-- Summary -->
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-info-circle me-2" style="color:var(--gold)"></i>Summary
                                </span>
                            </div>
                            <div style="padding:14px 16px;">
                                <div style="display:flex;flex-direction:column;gap:10px;">
                                    <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                        <span style="color:#9ca3af;">Entries</span>
                                        <strong id="entryCount">0</strong>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                        <span style="color:#9ca3af;">Total Hours</span>
                                        <strong style="color:#c9a84c;" id="totalHoursDisp">0.0h</strong>
                                    </div>
                                </div>
                                <div style="margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;font-size:.75rem;color:#6b7280;">
                                    <i class="fas fa-info-circle me-1 text-warning"></i>
                                    Plan will be saved as <strong>Draft</strong>. The staff member will be notified.
                                </div>
                            </div>
                        </div>

                        <!-- Save -->
                        <div class="cn-panel">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-save me-2" style="color:var(--gold)"></i>Save
                                </span>
                            </div>
                            <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px;">
                                <button type="submit" class="cn-btn cn-btn-gold" style="justify-content:center;">
                                    <i class="fas fa-save"></i> Create Plan
                                </button>
                                <!-- BUG FIX: button type="submit" with name triggers POST — correct -->
                                <button type="submit" name="auto_submit" value="1"
                                        class="cn-btn cn-btn-blue" style="justify-content:center;">
                                    <i class="fas fa-paper-plane"></i> Create &amp; Auto-Approve
                                </button>
                                <a href="plans.php" class="cn-btn cn-btn-out" style="justify-content:center;">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </div>
                    </div><!-- /RIGHT -->

                </div>
            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
const companies = <?= json_encode(array_map(fn($c) => [
    'id'   => $c['id'],
    'name' => $c['company_name'],
    'code' => $c['company_code'] ?? '',
    'pan'  => $c['pan_number']   ?? '',
], $companies), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

let entryIndex = 0;   // BUG FIX: renamed from entryCount to avoid collision with DOM id

// ── Staff TomSelect ───────────────────────────────────────────
const staffTS = new TomSelect('#staffSelect', {
    placeholder: 'Search staff member...',
    maxOptions: 100,
    allowEmptyOption: true,
    onChange(val) {
        const opt  = staffTS.options[val];
        const badge = document.getElementById('staffBadge');
        const name  = document.getElementById('staffBadgeName');
        if (opt && val) {
            name.textContent = 'Creating plan for: ' + opt.text;
            badge.style.display = 'block';
        } else {
            badge.style.display = 'none';
        }
    }
});

// ── Week-number → auto fill week dates ───────────────────────
document.getElementById('weekNumber').addEventListener('change', function () {
    const planMonth = document.querySelector('[name="plan_month"]').value;
    if (!planMonth || !this.value) return;
    const wn  = parseInt(this.value);
    // First day of month
    const d   = new Date(planMonth + '-01');
    // Monday of the week that contains the 1st
    const dow = d.getDay() || 7;         // 1=Mon … 7=Sun
    const mondayOfFirst = new Date(d);
    mondayOfFirst.setDate(d.getDate() - (dow - 1));
    // Start of selected week
    const start = new Date(mondayOfFirst);
    start.setDate(mondayOfFirst.getDate() + (wn - 1) * 7);
    const end = new Date(start);
    end.setDate(start.getDate() + 6);
    const fmt = d => d.toISOString().split('T')[0];
    document.getElementById('weekStart').value = fmt(start);
    document.getElementById('weekEnd').value   = fmt(end);
});

// ── Add entry ─────────────────────────────────────────────────
function addEntry() {
    const idx       = entryIndex++;
    const container = document.getElementById('entriesContainer');
    document.getElementById('noEntries').style.display = 'none';

    const opts = companies.map(c =>
        `<option value="${c.id}">${c.name}${c.code?' — '+c.code:''}${c.pan?' | PAN: '+c.pan:''}</option>`
    ).join('');

    const div = document.createElement('div');
    div.className = 'entry-row';
    div.id        = 'entry_' + idx;
    div.innerHTML = `
        <span class="entry-num">#${idx + 1}</span>
        <button type="button" class="remove-entry" onclick="removeEntry(${idx})" title="Remove entry">
            <i class="fas fa-times"></i>
        </button>
        <div class="row g-2" style="padding-top:8px;">
            <div class="col-md-5">
                <label class="cn-label" style="font-size:.72rem;">Client <span class="required-star">*</span></label>
                <select name="entries[${idx}][client_id]" id="clientSelect_${idx}" class="cn-input" style="font-size:.8rem;" required>
                    <option value="">— Search by name or PAN —</option>${opts}
                </select>
                <div id="clientInfo_${idx}" style="display:none;margin-top:5px;padding:4px 8px;
                     background:#f0fdf4;border-radius:5px;font-size:.72rem;color:#166534;">
                    <i class="fas fa-building me-1"></i><span id="clientInfoText_${idx}"></span>
                </div>
            </div>
            <div class="col-md-3">
                <label class="cn-label" style="font-size:.72rem;">Date <span class="required-star">*</span></label>
                <input type="date" name="entries[${idx}][plan_date]" class="cn-input entry-date"
                       style="font-size:.8rem;" required>
            </div>
            <div class="col-md-2">
                <label class="cn-label" style="font-size:.72rem;">Time In</label>
                <input type="time" name="entries[${idx}][planned_time_in]" class="cn-input"
                       style="font-size:.8rem;" onchange="calcEntry(${idx})">
            </div>
            <div class="col-md-2">
                <label class="cn-label" style="font-size:.72rem;">Time Out</label>
                <input type="time" name="entries[${idx}][planned_time_out]" class="cn-input"
                       style="font-size:.8rem;" onchange="calcEntry(${idx})">
            </div>
            <div class="col-12">
                <label class="cn-label" style="font-size:.72rem;">Notes</label>
                <input type="text" name="entries[${idx}][notes]" class="cn-input"
                       style="font-size:.8rem;" placeholder="Optional notes...">
            </div>
            <div class="col-12" style="display:flex;align-items:center;gap:12px;">
                <span style="font-size:.72rem;color:#9ca3af;">
                    Duration: <strong id="dur_${idx}" style="color:#c9a84c;">—</strong>
                </span>
                <span id="dateWarn_${idx}" style="font-size:.72rem;color:#ef4444;display:none;">
                    <i class="fas fa-exclamation-triangle me-1"></i>Date outside selected week
                </span>
            </div>
        </div>`;
    container.appendChild(div);

    // Auto-fill date from week start if available
    const ws = document.getElementById('weekStart').value;
    if (ws) div.querySelector('.entry-date').value = ws;

    // TomSelect for client
    new TomSelect(`#clientSelect_${idx}`, {
        placeholder: 'Search by name or PAN...',
        maxOptions: 200,
        allowEmptyOption: true,
        searchField: ['text'],
        render: {
            option(data, escape) {
                const c = companies.find(x => x.id == data.value);
                if (!c) return `<div>${escape(data.text)}</div>`;
                return `<div style="padding:6px 10px;line-height:1.4;">
                    <div style="font-weight:600;font-size:.82rem;">${escape(c.name)}</div>
                    <div style="font-size:.7rem;color:#6b7280;">
                        ${c.code ? '<span style="margin-right:8px;">Code: '+escape(c.code)+'</span>' : ''}
                        ${c.pan  ? '<span>PAN: '+escape(c.pan)+'</span>' : ''}
                    </div>
                </div>`;
            },
            item(data, escape) {
                const c = companies.find(x => x.id == data.value);
                if (!c) return `<div>${escape(data.text)}</div>`;
                return `<div>${escape(c.name)}${c.pan ? ' — '+escape(c.pan) : ''}</div>`;
            }
        },
        onChange(val) {
            const box  = document.getElementById(`clientInfo_${idx}`);
            const txt  = document.getElementById(`clientInfoText_${idx}`);
            const c    = companies.find(x => x.id == val);
            if (c && val) {
                const parts = [c.name];
                if (c.code) parts.push('Code: ' + c.code);
                if (c.pan)  parts.push('PAN: '  + c.pan);
                txt.textContent = parts.join(' · ');
                box.style.display = 'block';
            } else {
                box.style.display = 'none';
            }
        }
    });

    // BUG FIX: validate date against week range on change
    div.querySelector('.entry-date').addEventListener('change', function () {
        const ws  = document.getElementById('weekStart').value;
        const we  = document.getElementById('weekEnd').value;
        const warn = document.getElementById(`dateWarn_${idx}`);
        if (ws && we && this.value) {
            warn.style.display = (this.value < ws || this.value > we) ? 'inline' : 'none';
        }
    });

    updateSummary();
}

function removeEntry(idx) {
    document.getElementById('entry_' + idx)?.remove();
    if (!document.querySelector('.entry-row'))
        document.getElementById('noEntries').style.display = 'block';
    updateSummary();
}

function calcEntry(idx) {
    const tin  = document.querySelector(`[name="entries[${idx}][planned_time_in]"]`)?.value;
    const tout = document.querySelector(`[name="entries[${idx}][planned_time_out]"]`)?.value;
    const disp = document.getElementById('dur_' + idx);
    if (!disp) return;
    if (tin && tout) {
        // BUG FIX: use Date arithmetic, not string comparison
        const diff = (new Date('1970-01-01T' + tout + ':00') - new Date('1970-01-01T' + tin + ':00')) / 3600000;
        disp.textContent      = diff > 0 ? diff.toFixed(2) + 'h' : '— (invalid)';
        disp.style.color      = diff > 0 ? '#c9a84c' : '#ef4444';
    } else {
        disp.textContent = '—';
        disp.style.color = '#c9a84c';
    }
    updateSummary();
}

function updateSummary() {
    const rows = document.querySelectorAll('.entry-row');
    document.getElementById('entryCount').textContent = rows.length;
    let total = 0;
    rows.forEach(r => {
        const durEl = r.querySelector('[id^="dur_"]');
        if (durEl) {
            const v = parseFloat(durEl.textContent);
            if (!isNaN(v)) total += v;
        }
    });
    document.getElementById('totalHoursDisp').textContent = total.toFixed(1) + 'h';
}

// BUG FIX: prevent form submit if no entries or client missing
document.getElementById('planForm').addEventListener('submit', function (e) {
    const rows = document.querySelectorAll('.entry-row');
    if (rows.length === 0) {
        e.preventDefault();
        alert('Please add at least one plan entry.');
        return;
    }
    let missing = false;
    rows.forEach(r => {
        const sel = r.querySelector('select[name*="client_id"]');
        // TomSelect stores value on the original <select>
        if (!sel || !sel.value) missing = true;
    });
    if (missing) {
        e.preventDefault();
        alert('Please select a client for every entry.');
    }
});

// Add first entry by default
addEntry();
</script>

<?php include '../../includes/footer.php'; ?>