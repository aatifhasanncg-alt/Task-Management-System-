<?php
/**
 * consulting/staff/plan_create.php — Staff: Create Weekly Work Plan
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
require_once '../../config/plan_notify.php';
requireAnyRole();

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];

$deptId   = (int)$user['department_id'];
$branchId = (int)$user['branch_id'];

// Month
$now       = new DateTime();
$month     = $_GET['month'] ?? $now->format('Y-m');
$monthDate  = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthLabel = $monthDate->format('F Y');

$weeks = [];
$first = clone $monthDate;
$last  = clone $monthDate;
$last->modify('last day of this month');

// Rewind to the Sunday that starts the week containing the 1st
$dowFirst = (int)$first->format('w');
if ($dowFirst !== 0) {
    $first->modify('-' . $dowFirst . ' days');
}

$cur = clone $first;
$wn  = 1;
while ($cur <= $last && $wn <= 5) {
    $ws = clone $cur;
    $we = clone $cur;
    $we->modify('+6 days'); // Sun + 6 = Sat
    if ($we > $last) $we = clone $last;
    $weeks[] = [
        'week_number'     => $wn,
        'week_start_date' => $ws->format('Y-m-d'),
        'week_end_date'   => $we->format('Y-m-d'),
        'label'           => 'Week ' . $wn . ' (' . $ws->format('d M') . ' – ' . $we->format('d M') . ')',
    ];
    $cur = clone $we;
    $cur->modify('+1 day');
    $wn++;
}

// Companies for this branch
$companies = $db->prepare("
    SELECT id, company_name, company_code, pan_number FROM companies
    WHERE is_active=1
    ORDER BY company_name
");
$companies->execute([]);
$companies = $companies->fetchAll();
// Supervisors from CON dept (primary or UDA)
$supervisors = $db->prepare("
    SELECT DISTINCT u.id, u.full_name, u.employee_id
    FROM users u
    INNER JOIN (
        SELECT u2.id
        FROM users u2
        LEFT JOIN departments dp ON dp.id = u2.department_id
        WHERE dp.dept_code = 'CON'
          AND u2.is_active = 1

        UNION

        SELECT uda2.user_id
        FROM user_department_assignments uda2
        JOIN departments du ON du.id = uda2.department_id
        WHERE du.dept_code = 'CON'
    ) AS con_users ON con_users.id = u.id
    WHERE u.is_active = 1
    ORDER BY u.full_name
");
$supervisors->execute();
$supervisors = $supervisors->fetchAll();

// Default supervisor: UDA managed_by first, fallback to users.managed_by
$managedByStmt = $db->prepare("
    SELECT uda.managed_by
    FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ?
      AND d.dept_code = 'CON'
      AND uda.managed_by IS NOT NULL
    LIMIT 1
");
$managedByStmt->execute([$uid]);
$defaultSupervisor = $managedByStmt->fetchColumn();

if (!$defaultSupervisor) {
    $mbStmt = $db->prepare("SELECT managed_by FROM users WHERE id = ?");
    $mbStmt->execute([$uid]);
    $defaultSupervisor = $mbStmt->fetchColumn();
}
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $weekNum   = (int)($_POST['week_number'] ?? 0);
    $weekStart = $_POST['week_start_date'] ?? '';
    $weekEnd   = $_POST['week_end_date']   ?? '';
    $remarks   = trim($_POST['remarks']    ?? '');
    $entries   = $_POST['entries']         ?? [];

    if (!$weekNum)   $errors[] = 'Please select a week.';
    if (!$weekStart) $errors[] = 'Week start date missing.';
    if (empty($entries) || !is_array($entries)) $errors[] = 'Add at least one plan entry.';

    // Check duplicate plan
    if (!$errors) {
        $dup = $db->prepare("
            SELECT id FROM work_plans
            WHERE user_id=? AND plan_month=? AND week_number=? AND department_id=?
        ");
        $dup->execute([$uid, $monthStart, $weekNum, $deptId]);
        if ($dup->fetch()) $errors[] = 'You already have a plan for this week.';
    }

    if (!$errors) {
        $db->beginTransaction();
        try {
            $insP = $db->prepare("
                INSERT INTO work_plans
                (user_id, department_id, branch_id, plan_month, week_number,
                 week_start_date, week_end_date, status, remarks)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            $insP->execute([
                $uid, $deptId, $branchId, $monthStart, $weekNum,
                $weekStart, $weekEnd, 'draft', $remarks
            ]);
            $planId = $db->lastInsertId();

            $insE = $db->prepare("
                INSERT INTO work_plan_entries
                (plan_id, client_id, client_code, assigned_to, supervisor_id, plan_date,
                day_of_week, planned_time_in, planned_time_out, planned_hours, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ");

            foreach ($entries as $e) {
                $cid = (int)($e['client_id'] ?? 0);
                if (!$cid) continue;
                $pdate = $e['plan_date'] ?? '';
                if (!$pdate) continue;
                $dow   = date('l', strtotime($pdate));
                $tin   = $e['time_in']  ?: null;
                $tout  = $e['time_out'] ?: null;
                $hrs   = 0;
                if ($tin && $tout) {
                    $diff = strtotime($tout) - strtotime($tin);
                    $hrs  = round($diff / 3600, 2);
                }
                // client_code
                $cc = '';
                foreach ($companies as $c) { if ($c['id'] == $cid) { $cc = $c['company_code']; break; } }

                $supId = (int)($e['supervisor_id'] ?? 0) ?: null;
                $insE->execute([
                    $planId, $cid, $cc, $uid, $supId, $pdate,
                    $dow, $tin, $tout, $hrs, trim($e['notes'] ?? '')
                ]);
            }

            // ── Auto-submit (staff plans for self always go straight to submitted) ──
            $db->prepare("UPDATE work_plans SET status='submitted', updated_at=NOW() WHERE id=?")
               ->execute([$planId]);

            $db->commit();
            logActivity('Created plan #' . $planId . ' Week ' . $weekNum, 'consulting');

            notifyPlanApprovers(
                $db, $planId, $uid, $uid,
                $user['full_name'] ?? ('User #' . $uid),
                $weekNum, $monthLabel, $month, 'created_for_self'
            );

            setFlash('success', 'Work plan created successfully!');
            header('Location: plan_list.php?month=' . $month);
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to save plan: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Create Work Plan';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
.entry-row { border-bottom:1px solid #f1f5f9; padding:14px 18px; }
.entry-row:last-child { border-bottom:none; }
.required-star { color:#ef4444; }
.hrs-pill { background:#f9fafb; border-radius:6px; padding:5px 10px; font-size:.77rem; color:#9ca3af; }

/* Fix TomSelect dropdown clipping inside panels/cards */
.cn-panel,
.card-mis,
#entriesContainer {
    overflow: visible !important;
}
.ts-dropdown {
    z-index: 9999 !important;
    position: absolute !important;
}
</style>
<div class="app-wrapper">
    <?php include '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-briefcase"></i> Consulting</div>
                        <h4>Create Work Plan</h4>
                        <p><?= htmlspecialchars($user['full_name']) ?> · <?= $monthLabel ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <input type="month" class="form-control form-control-sm" style="width:150px;"
                            value="<?= $month ?>" onchange="location='?month='+this.value">
                        <a href="plan_list.php?month=<?= $month ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-list me-1"></i> My Plans
                        </a>
                        <a href="index.php?month=<?= $month ?>" class="btn btn-sm btn-outline-secondary">
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

                <form method="POST" id="planForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

                    <!-- LEFT -->
                    <div>

                        <!-- Plan Details -->
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-calendar-week me-2" style="color:var(--gold)"></i>Plan Details
                                </span>
                            </div>
                            <div style="padding:16px 18px;">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="cn-label">Month</label>
                                        <input type="month" class="cn-input" value="<?= $month ?>"
                                            onchange="location='?month='+this.value">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="cn-label">Week <span class="required-star">*</span></label>
                                        <select name="week_number" id="weekSelect" class="cn-input" required
                                                onchange="onWeekChange(this)">
                                            <option value="">— Select Week —</option>
                                            <?php foreach ($weeks as $w): ?>
                                            <option value="<?= $w['week_number'] ?>"
                                                    data-start="<?= $w['week_start_date'] ?>"
                                                    data-end="<?= $w['week_end_date'] ?>"
                                                    <?= ($_POST['week_number'] ?? '') == $w['week_number'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($w['label']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="week_start_date" id="weekStart"
                                            value="<?= htmlspecialchars($_POST['week_start_date'] ?? '') ?>">
                                        <input type="hidden" name="week_end_date" id="weekEnd"
                                            value="<?= htmlspecialchars($_POST['week_end_date'] ?? '') ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="cn-label">Remarks / Notes</label>
                                        <textarea name="remarks" class="cn-input" rows="2"
                                                placeholder="Any notes for this week's plan…"><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
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
                            <div id="entriesContainer"></div>
                            <div id="emptyEntries" class="text-center text-muted p-4">
                                <i class="fas fa-calendar-plus fa-2x mb-2 opacity-25"></i><br>
                                Click "Add Entry" to start planning
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
                                    <div style="text-align:center;background:#f9fafb;border-radius:8px;padding:12px 6px;">
                                        <div style="font-size:1.5rem;font-weight:800;color:#3b82f6;" id="totHrs">0.0h</div>
                                        <div style="font-size:.7rem;color:#9ca3af;margin-top:2px;">Total Hours</div>
                                    </div>
                                    <div style="text-align:center;background:#f9fafb;border-radius:8px;padding:12px 6px;">
                                        <div style="font-size:1.5rem;font-weight:800;color:#c9a84c;" id="entCnt">0</div>
                                        <div style="font-size:.7rem;color:#9ca3af;margin-top:2px;">Entries</div>
                                    </div>
                                </div>
                                <div id="wkInfo" style="background:rgba(16,185,129,.1);border-radius:7px;
                                    padding:9px 12px;font-size:.78rem;color:#10b981;font-weight:600;">
                                    <i class="fas fa-calendar me-1"></i>Select a week above
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-save me-2" style="color:var(--gold)"></i>Save
                                </span>
                            </div>
                            <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px;">
                                <button type="submit" id="savePlanBtn" class="cn-btn cn-btn-gold" style="justify-content:center;">
                                    <span id="savePlanBtnIcon"><i class="fas fa-save"></i> Save as Draft</span>
                                    <span id="savePlanBtnLoading" style="display:none;align-items:center;justify-content:center;gap:.4rem;">
                                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="width:.85rem;height:.85rem;"></span>
                                        Saving...
                                    </span>
                                </button>
                                <a href="plan_list.php?month=<?= $month ?>"
                                class="cn-btn cn-btn-out" style="justify-content:center;">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </div>

                        <!-- Tips -->
                        <div class="cn-panel">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-info-circle me-2" style="color:var(--gold)"></i>Tips
                                </span>
                            </div>
                            <div style="padding:12px 16px;">
                                <ul style="font-size:.77rem;color:#6b7280;padding-left:1.1rem;margin:0;line-height:1.8;">
                                    <li>Set time in/out to auto-calculate hours</li>
                                    <li>Plans start as Draft — submit for approval</li>
                                    <li>One plan per week per month</li>
                                    <li>Date must fall within selected week</li>
                                </ul>
                            </div>
                        </div>

                    </div>
                </div><!-- /grid -->
                </form>

        </div>
    </div>
</div>

<!-- Entry Template (hidden) -->
<div id="entryTemplate" style="display:none;">
<div class="entry-row" data-index="__IDX__">
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
            <select name="entries[__IDX__][client_id]" class="cn-input client-select" required>
                <option value="">— Select Client —</option>
                <?php foreach ($companies as $c): ?>
                <option value="<?= $c['id'] ?>" data-pan="<?= htmlspecialchars($c['pan_number'] ?? '') ?>">
                    <?= htmlspecialchars($c['company_name']) ?>
                    <?= $c['company_code'] ? ' — ' . $c['company_code'] : '' ?>
                    <?= !empty($c['pan_number']) ? ' · PAN: ' . $c['pan_number'] : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="cn-label">Date <span class="required-star">*</span></label>
            <input type="date" name="entries[__IDX__][plan_date]" class="cn-input entry-date" required>
        </div>
        <div>
            <label class="cn-label">Time In</label>
            <input type="time" name="entries[__IDX__][time_in]" class="cn-input time-in">
        </div>
        <div>
            <label class="cn-label">Time Out</label>
            <input type="time" name="entries[__IDX__][time_out]" class="cn-input time-out">
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;">
        <div>
            <label class="cn-label">Notes</label>
            <input type="text" name="entries[__IDX__][notes]" class="cn-input"
                   placeholder="Purpose of visit…">
        </div>
        <div>
            <label class="cn-label">Supervisor</label>
            <select name="entries[__IDX__][supervisor_id]" class="cn-input supervisor-select">
                <option value="">— None —</option>
                <?php foreach ($supervisors as $sv):
                    $label = trim(($sv['employee_id'] ? '[' . $sv['employee_id'] . '] ' : '') . $sv['full_name']);
                ?>
                <option value="<?= $sv['id'] ?>"
                    data-default="<?= $defaultSupervisor == $sv['id'] ? '1' : '0' ?>">
                    <?= htmlspecialchars($label) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="hrs-pill">
            <i class="fas fa-clock me-1" style="color:#c9a84c;"></i>
            <span class="planned-hrs">0.00h</span>
        </div>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
let entryIdx = 0;
const weekStartEl = document.getElementById('weekStart');
const weekEndEl   = document.getElementById('weekEnd');

function onWeekChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const ws  = opt.dataset.start || '';
    const we  = opt.dataset.end   || '';
    weekStartEl.value = ws;
    weekEndEl.value   = we;
    document.querySelectorAll('.entry-date').forEach(d => { d.min = ws; d.max = we; });
    document.getElementById('wkInfo').innerHTML = ws
        ? '<i class="fas fa-calendar me-1"></i>' + fmtDate(ws) + ' – ' + fmtDate(we)
        : '<i class="fas fa-calendar me-1"></i>Select a week above';
}

function fmtDate(d) {
    if (!d) return '—';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', {day:'2-digit', month:'short'});
}

function addEntry() {
    const tpl = document.getElementById('entryTemplate').innerHTML;
    const html = tpl.replaceAll('__IDX__', entryIdx);
    const wrap = document.createElement('div');
    wrap.innerHTML = html;
    const row = wrap.querySelector('.entry-row');

    // Date constraints
    const ws = weekStartEl.value, we = weekEndEl.value;
    const dateEl = row.querySelector('.entry-date');
    if (ws) dateEl.min = ws;
    if (we) dateEl.max = we;

    // TomSelect — client
    new TomSelect(row.querySelector('.client-select'), {
        placeholder: 'Search by name, code or PAN…',
        maxOptions: 500,
        searchField: ['text']
    });

    // TomSelect — supervisor with default
    const supSelect = row.querySelector('.supervisor-select');
    const supTs = new TomSelect(supSelect, {
        placeholder: 'Search supervisor…',
        allowEmptyOption: true,
        searchField: ['text']
    });
    // Set default to managed_by
    const defaultOpt = supSelect.querySelector('option[data-default="1"]');
    if (defaultOpt) supTs.setValue(defaultOpt.value);

    // Time calc
    row.querySelectorAll('.time-in,.time-out').forEach(t =>
        t.addEventListener('change', () => calcHours(row))
    );

    document.getElementById('entriesContainer').appendChild(row);
    document.getElementById('emptyEntries').style.display = 'none';
    entryIdx++;
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
document.getElementById('planForm').addEventListener('submit', function () {
    const btn = document.getElementById('savePlanBtn');
    btn.disabled = true;
    btn.style.opacity = '0.7';
    document.getElementById('savePlanBtnIcon').style.display = 'none';
    document.getElementById('savePlanBtnLoading').style.display = 'inline-flex';
});
function renumber() {
    document.querySelectorAll('.entry-num').forEach((el, i) => el.textContent = i + 1);
}

function calcHours(row) {
    const tin  = row.querySelector('.time-in').value;
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

// Restore on POST error
<?php if (!empty($_POST['entries'])): ?>
<?php foreach ($_POST['entries'] as $e): ?>
addEntry();
<?php endforeach; ?>
<?php endif; ?>

// Restore week info on POST error
const ws = document.getElementById('weekSelect');
if (ws && ws.value) onWeekChange(ws);
</script>
<?php include '../../includes/footer.php'; ?>