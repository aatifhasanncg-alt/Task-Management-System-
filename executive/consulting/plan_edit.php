<?php
/**
 * consulting/executive/plan_edit.php — Executive: Edit an existing Work Plan's entries
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
require_once '../../config/notify.php';

requireExecutive();

$db   = getDB();
$user = currentUser();
$uid  = (int)$user['id'];

$planId = (int)($_GET['id'] ?? 0);
if (!$planId) {
    setFlash('error', 'Invalid plan.');
    header('Location: plans.php');
    exit;
}

// ── Load plan + owner (must be CON dept or UDA-CON, and not the exec's own plan) ──
$planStmt = $db->prepare("
    SELECT wp.*, u.full_name AS staff_name, u.employee_id
    FROM work_plans wp
    JOIN users u ON u.id = wp.user_id
    LEFT JOIN departments d  ON d.id  = u.department_id
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    LEFT JOIN departments d2 ON d2.id = uda.department_id
    WHERE wp.id = ?
      AND (
          d.dept_code = 'CON' OR d.dept_name LIKE '%consult%'
          OR d2.dept_code = 'CON' OR d2.dept_name LIKE '%consult%'
      )
    LIMIT 1
");
$planStmt->execute([$planId]);
$plan = $planStmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    setFlash('error', 'Plan not found or not editable.');
    header('Location: plans.php');
    exit;
}

if ((int)$plan['user_id'] === $uid) {
    setFlash('error', 'Use "My Plans" to edit your own plans.');
    header('Location: plans.php');
    exit;
}

$month      = date('Y-m', strtotime($plan['plan_month']));
$monthLabel = date('F Y', strtotime($plan['plan_month']));

// ── Existing entries ────────────────────────────────────────────
$entriesStmt = $db->prepare("
    SELECT * FROM work_plan_entries WHERE plan_id = ? ORDER BY plan_date, planned_time_in
");
$entriesStmt->execute([$planId]);
$existingEntries = $entriesStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Companies ────────────────────────────────────────────────────
$companiesStmt = $db->prepare(
    "SELECT id, company_name, company_code, pan_number FROM companies WHERE is_active=1 ORDER BY company_name"
);
$companiesStmt->execute();
$companies = $companiesStmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $entries = $_POST['entries'] ?? [];
    $deletedIds = array_map('intval', $_POST['deleted_entry_ids'] ?? []);

    if (empty($entries)) {
        $errors[] = 'At least one plan entry is required.';
    }

    foreach ($entries as $i => $e) {
        $num = $i + 1;
        if (empty($e['client_id']))
            $errors[] = "Entry #{$num}: Client is required.";
        if (empty($e['plan_date']))
            $errors[] = "Entry #{$num}: Date is required.";
        if (!empty($e['planned_time_in']) && !empty($e['planned_time_out'])) {
            if ($e['planned_time_out'] <= $e['planned_time_in'])
                $errors[] = "Entry #{$num}: Time Out must be after Time In.";
        }
        if (!empty($e['plan_date']) && $plan['week_start_date'] && $plan['week_end_date']) {
            if ($e['plan_date'] < $plan['week_start_date'] || $e['plan_date'] > $plan['week_end_date'])
                $errors[] = "Entry #{$num}: Date must be within the plan week ({$plan['week_start_date']} – {$plan['week_end_date']}).";
        }
    }

    if (!$errors) {
        $db->beginTransaction();
        try {
            // Delete entries explicitly removed in the UI
            if (!empty($deletedIds)) {
                $ph = implode(',', array_fill(0, count($deletedIds), '?'));
                $db->prepare("DELETE FROM work_plan_entries WHERE id IN ({$ph}) AND plan_id = ?")
                   ->execute(array_merge($deletedIds, [$planId]));
            }

            foreach ($entries as $e) {
                if (empty($e['client_id'])) continue;

                $entryId  = (int)($e['entry_id'] ?? 0);
                $clientId = (int)$e['client_id'];
                $planDate = $e['plan_date'];
                $timeIn   = !empty($e['planned_time_in'])  ? $e['planned_time_in']  : null;
                $timeOut  = !empty($e['planned_time_out']) ? $e['planned_time_out'] : null;
                $notes    = trim($e['notes'] ?? '');
                $dow      = date('l', strtotime($planDate));

                $hours = 0;
                if ($timeIn && $timeOut) {
                    $diff  = strtotime($timeOut) - strtotime($timeIn);
                    $hours = $diff > 0 ? round($diff / 3600, 2) : 0;
                }

                $clientCodeQ = $db->prepare("SELECT company_code FROM companies WHERE id=?");
                $clientCodeQ->execute([$clientId]);
                $clientCode = $clientCodeQ->fetchColumn() ?: null;

                if ($entryId) {
                    // Update existing entry — must belong to this plan
                    $db->prepare("
                        UPDATE work_plan_entries SET
                            client_id = ?, client_code = ?, plan_date = ?, day_of_week = ?,
                            planned_time_in = ?, planned_time_out = ?, planned_hours = ?, notes = ?
                        WHERE id = ? AND plan_id = ?
                    ")->execute([
                        $clientId, $clientCode, $planDate, $dow,
                        $timeIn, $timeOut, $hours, $notes,
                        $entryId, $planId,
                    ]);
                } else {
                    // New entry added during this edit
                    $db->prepare("
                        INSERT INTO work_plan_entries
                            (plan_id, assigned_to, client_id, client_code, plan_date, day_of_week,
                             planned_time_in, planned_time_out, planned_hours, notes)
                        VALUES (?,?,?,?,?,?,?,?,?,?)
                    ")->execute([
                        $planId, $plan['user_id'], $clientId, $clientCode,
                        $planDate, $dow, $timeIn, $timeOut, $hours, $notes,
                    ]);
                }
            }

            $db->prepare("UPDATE work_plans SET updated_at = NOW() WHERE id = ?")->execute([$planId]);

            $db->commit();

            // Notify the plan owner — a notification failure should not undo the save
            try {
                notify(
                    (int)$plan['user_id'],
                    'Work Plan Updated',
                    $user['full_name'] . ' made changes to your Week ' . $plan['week_number'] . ' plan for ' . $monthLabel . '.',
                    'task',
                    APP_URL . '/staff/planning/plan_view.php?id=' . $planId,
                    true,
                    ['template' => 'generic']
                );
            } catch (Exception $notifEx) {
                error_log('[exec:plan_edit] notify failed for plan_id=' . $planId . ': ' . $notifEx->getMessage());
            }

            logActivity('Executive edited plan #' . $planId, 'consulting', 'plan_id=' . $planId);
            setFlash('success', 'Plan updated successfully!');
            header('Location: plan_view.php?id=' . $planId);
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            error_log('[exec:plan_edit] plan_id=' . $planId . ': ' . $e->getMessage());
            $errors[] = 'Failed to save changes. Please try again or contact support.';
        }
    }
}

$pageTitle = 'Edit Plan: ' . $plan['staff_name'];
include '../../includes/header.php';
?>
<link rel="stylesheet" href="<?= APP_URL ?>/staff/planning/consulting.css">
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

            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-pencil-alt"></i> Executive · Consulting</div>
                        <h4>Edit Work Plan</h4>
                        <p>
                            <?= htmlspecialchars($plan['staff_name']) ?>
                            · Week <?= $plan['week_number'] ?>
                            · <?= date('d M', strtotime($plan['week_start_date'])) ?> – <?= date('d M', strtotime($plan['week_end_date'])) ?>
                            · <?= $monthLabel ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="plan_view.php?id=<?= $planId ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eye me-1"></i> View Plan
                        </a>
                        <a href="plans.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-list me-1"></i> All Plans
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

            <div class="edit-warning" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;
                 padding:12px 16px;font-size:.8rem;color:#92400e;display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                <i class="fas fa-info-circle fa-lg" style="color:#f59e0b;flex-shrink:0;"></i>
                <div>
                    Editing <strong><?= htmlspecialchars($plan['staff_name']) ?>'s</strong> plan.
                    They will be notified of any changes. Week and month are locked — entries must stay within
                    <?= date('d M', strtotime($plan['week_start_date'])) ?> – <?= date('d M', strtotime($plan['week_end_date'])) ?>.
                </div>
            </div>

            <form method="POST" id="planForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div id="deletedIdsWrap"></div>

                <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

                    <!-- ══ LEFT ══ -->
                    <div>
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
                                <div id="noEntries" style="display:none;text-align:center;color:#9ca3af;font-size:.8rem;padding:20px 0;">
                                    <i class="fas fa-calendar-plus" style="font-size:1.5rem;display:block;margin-bottom:8px;"></i>
                                    Click "Add Entry" to add client visits to this plan.
                                </div>
                            </div>
                        </div>
                    </div><!-- /LEFT -->

                    <!-- ══ RIGHT ══ -->
                    <div>
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
                                    <div style="display:flex;justify-content:space-between;font-size:.83rem;">
                                        <span style="color:#9ca3af;">Plan Status</span>
                                        <strong style="text-transform:capitalize;"><?= htmlspecialchars($plan['status']) ?></strong>
                                    </div>
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
                                <button type="submit" id="saveBtn" class="cn-btn cn-btn-gold" style="justify-content:center;">
                                    <span id="saveBtnIcon"><i class="fas fa-save"></i> Save Changes</span>
                                    <span id="saveBtnLoading" style="display:none;align-items:center;justify-content:center;gap:.4rem;">
                                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"
                                              style="width:.85rem;height:.85rem;"></span>
                                        Saving...
                                    </span>
                                </button>
                                <a href="plan_view.php?id=<?= $planId ?>" class="cn-btn cn-btn-out" style="justify-content:center;">
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

const existingEntries = <?= json_encode(array_map(fn($e) => [
    'entry_id'         => $e['id'],
    'client_id'        => $e['client_id'],
    'plan_date'        => $e['plan_date'],
    'planned_time_in'  => $e['planned_time_in'],
    'planned_time_out' => $e['planned_time_out'],
    'notes'            => $e['notes'],
], $existingEntries), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

const weekStart = <?= json_encode($plan['week_start_date']) ?>;
const weekEnd   = <?= json_encode($plan['week_end_date']) ?>;

let entryIndex = 0;
const deletedIds = [];

function addEntry(prefill) {
    prefill = prefill || {};
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
        <input type="hidden" name="entries[${idx}][entry_id]" value="${prefill.entry_id || ''}">
        <button type="button" class="remove-entry" onclick="removeEntry(${idx}, ${prefill.entry_id || 0})" title="Remove entry">
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
                       style="font-size:.8rem;" min="${weekStart}" max="${weekEnd}"
                       value="${prefill.plan_date || weekStart}" required>
            </div>
            <div class="col-md-2">
                <label class="cn-label" style="font-size:.72rem;">Time In</label>
                <input type="time" name="entries[${idx}][planned_time_in]" class="cn-input"
                       style="font-size:.8rem;" value="${prefill.planned_time_in || ''}" onchange="calcEntry(${idx})">
            </div>
            <div class="col-md-2">
                <label class="cn-label" style="font-size:.72rem;">Time Out</label>
                <input type="time" name="entries[${idx}][planned_time_out]" class="cn-input"
                       style="font-size:.8rem;" value="${prefill.planned_time_out || ''}" onchange="calcEntry(${idx})">
            </div>
            <div class="col-12">
                <label class="cn-label" style="font-size:.72rem;">Notes</label>
                <input type="text" name="entries[${idx}][notes]" class="cn-input"
                       style="font-size:.8rem;" placeholder="Optional notes..." value="${(prefill.notes || '').replace(/"/g,'&quot;')}">
            </div>
            <div class="col-12" style="display:flex;align-items:center;gap:12px;">
                <span style="font-size:.72rem;color:#9ca3af;">
                    Duration: <strong id="dur_${idx}" style="color:#c9a84c;">—</strong>
                </span>
                <span id="dateWarn_${idx}" style="font-size:.72rem;color:#ef4444;display:none;">
                    <i class="fas fa-exclamation-triangle me-1"></i>Date outside plan week
                </span>
            </div>
        </div>`;
    container.appendChild(div);

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
            const box = document.getElementById(`clientInfo_${idx}`);
            const txt = document.getElementById(`clientInfoText_${idx}`);
            const c   = companies.find(x => x.id == val);
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
    if (prefill.client_id) {
        document.getElementById(`clientSelect_${idx}`).tomselect.setValue(prefill.client_id, true);
        document.getElementById(`clientSelect_${idx}`).dispatchEvent(new Event('change'));
        const c = companies.find(x => x.id == prefill.client_id);
        if (c) {
            const box = document.getElementById(`clientInfo_${idx}`);
            const txt = document.getElementById(`clientInfoText_${idx}`);
            const parts = [c.name];
            if (c.code) parts.push('Code: ' + c.code);
            if (c.pan)  parts.push('PAN: '  + c.pan);
            txt.textContent = parts.join(' · ');
            box.style.display = 'block';
        }
    }

    div.querySelector('.entry-date').addEventListener('change', function () {
        const warn = document.getElementById(`dateWarn_${idx}`);
        warn.style.display = (this.value < weekStart || this.value > weekEnd) ? 'inline' : 'none';
    });

    calcEntry(idx);
    updateSummary();
}

function removeEntry(idx, entryId) {
    if (entryId) deletedIds.push(entryId);
    document.getElementById('entry_' + idx)?.remove();
    if (!document.querySelector('.entry-row'))
        document.getElementById('noEntries').style.display = 'block';
    syncDeletedIds();
    updateSummary();
}

function syncDeletedIds() {
    const wrap = document.getElementById('deletedIdsWrap');
    wrap.innerHTML = deletedIds.map(id => `<input type="hidden" name="deleted_entry_ids[]" value="${id}">`).join('');
}

function calcEntry(idx) {
    const tin  = document.querySelector(`[name="entries[${idx}][planned_time_in]"]`)?.value;
    const tout = document.querySelector(`[name="entries[${idx}][planned_time_out]"]`)?.value;
    const disp = document.getElementById('dur_' + idx);
    if (!disp) return;
    if (tin && tout) {
        const diff = (new Date('1970-01-01T' + tout + ':00') - new Date('1970-01-01T' + tin + ':00')) / 3600000;
        disp.textContent = diff > 0 ? diff.toFixed(2) + 'h' : '— (invalid)';
        disp.style.color = diff > 0 ? '#c9a84c' : '#ef4444';
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
        if (!sel || !sel.value) missing = true;
    });
    if (missing) {
        e.preventDefault();
        alert('Please select a client for every entry.');
        return;
    }

    // Lock the button so a slow save can't be double-submitted
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.style.opacity = '0.75';
    btn.style.cursor = 'not-allowed';
    document.getElementById('saveBtnIcon').style.display = 'none';
    document.getElementById('saveBtnLoading').style.display = 'inline-flex';
});

// Load existing entries on page load, or one empty row if there are none
if (existingEntries.length) {
    existingEntries.forEach(e => addEntry(e));
} else {
    addEntry();
}
</script>

<?php include '../../includes/footer.php'; ?>