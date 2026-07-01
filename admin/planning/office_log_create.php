<?php
/**
 * consulting/staff/office_log_create.php — Staff: Log Office Work (multi-entry)
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];
$deptId = (int) $user['department_id'];
$branchId = (int) $user['branch_id'];

$now = new DateTime();
$today = $now->format('Y-m-d');
$month = $_GET['month'] ?? $now->format('Y-m');

// Companies
$companies = $db->query("
    SELECT id, company_name, company_code, pan_number
    FROM companies WHERE is_active=1 ORDER BY company_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Supervisor options: anyone in CON dept (primary or UDA), active ──────────
$supervisors = $db->query("
    SELECT DISTINCT u.id, u.full_name, u.employee_id
    FROM users u
    LEFT JOIN departments d  ON d.id = u.department_id
    LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
    LEFT JOIN departments d2 ON d2.id = uda.department_id
    WHERE u.is_active = 1
      AND (d.dept_code = 'CON' OR d2.dept_code = 'CON')
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Default supervisor = this user's managed_by ──────────────────────────────
$mbStmt = $db->prepare("SELECT managed_by FROM users WHERE id = ?");
$mbStmt->execute([$uid]);
$defaultSupervisorId = (int) ($mbStmt->fetchColumn() ?: 0);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $rows = $_POST['entries'] ?? [];
    $savedCount = 0;
    $rowErrors = []; // [rowIndex => [error, error...]]

    foreach ($rows as $idx => $row) {
        $clientId = (int) ($row['client_id'] ?? 0);
        $logDate = $row['log_date'] ?? $today;
        $timeIn = $row['time_in'] ?: null;
        $timeOut = $row['time_out'] ?: null;
        $description = trim($row['description'] ?? '');
        $notes = trim($row['notes'] ?? '') ?: null;
        $status = in_array($row['status'] ?? '', ['not_started', 'wip', 'holding', 'completed'])
            ? $row['status'] : 'wip';
        $supervisorId = (isset($row['supervisor_id']) && $row['supervisor_id'] !== '')
            ? (int) $row['supervisor_id']
            : null;

        if (!$supervisorId) {
            $mb = $db->prepare("SELECT managed_by FROM users WHERE id = ?");
            $mb->execute([$uid]);
            $supervisorId = (int) $mb->fetchColumn() ?: null;
        }

        $rowErr = [];
        if (!$clientId)
            $rowErr[] = 'Please select a client.';
        if (!$logDate)
            $rowErr[] = 'Log date is required.';
        if (!$description)
            $rowErr[] = 'Description is required.';
        if (!$timeIn)
            $rowErr[] = 'Start Time is required.';
        if (!$timeOut)
            $rowErr[] = 'End Time is required.';

        if ($timeIn && $timeOut) {
            $diff = strtotime($timeOut) - strtotime($timeIn);
            if ($diff <= 0)
                $rowErr[] = 'End Time must be after Start Time.';
        }

        if ($rowErr) {
            $rowErrors[$idx] = $rowErr;
            continue; // skip this row, keep processing the rest
        }

        try {
            $db->prepare("
                INSERT INTO office_work_logs
                (user_id, supervisor_id, department_id, branch_id, client_id,
                 log_date, time_in, time_out, description, notes, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                        $uid,
                        $supervisorId,
                        $deptId,
                        $branchId,
                        $clientId,
                        $logDate,
                        $timeIn,
                        $timeOut,
                        $description,
                        $notes,
                        $status
                    ]);
            $logId = $db->lastInsertId();
            logActivity('Office work logged for client #' . $clientId, 'consulting', 'office_log_id=' . $logId);
            $savedCount++;
        } catch (Exception $e) {
            error_log('[office_log_create] row ' . $idx . ': ' . $e->getMessage());
            $rowErrors[$idx] = ['Failed to save this entry. Please try again or contact support.'];
        }
    }

    if ($savedCount > 0 && empty($rowErrors)) {
        setFlash('success', $savedCount . ' office work log(s) saved successfully!');
        header('Location: office_log_list.php?month=' . substr($_POST['entries'][array_key_first($_POST['entries'])]['log_date'] ?? $today, 0, 7));
        exit;
    } elseif ($savedCount > 0) {
        setFlash('warning', $savedCount . ' log(s) saved. ' . count($rowErrors) . ' row(s) had errors — please review below.');
        $_POST['entries'] = array_intersect_key($rows, $rowErrors); // keep only failed rows on screen
        $errors = $rowErrors;
    } else {
        $errors = $rowErrors;
    }
}

$pageTitle = 'Log Office Work';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <?= function_exists('flashHtml') ? flashHtml() : '' ?>

            <!-- Hero -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-building"></i> Office Work</div>
                        <h4>Log Office Work</h4>
                        <p><?= htmlspecialchars($user['full_name']) ?> · <?= date('d M Y') ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="office_log_list.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-history me-1"></i> My Logs
                        </a>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <form method="POST" id="officeLogForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div id="entriesContainer"></div>

                <button type="button" class="btn btn-outline-secondary w-100 mb-4" id="addEntryBtn">
                    <i class="fas fa-plus me-2"></i>Add Entry
                </button>

                <div class="cn-panel">
                    <div class="cn-panel-hd">
                        <span class="cn-panel-title">
                            <i class="fas fa-save me-2" style="color:var(--gold)"></i>Save
                        </span>
                    </div>
                    <div style="padding:14px 16px;display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="submit" id="saveOfficeLogBtn" class="cn-btn cn-btn-gold"
                            style="justify-content:center;">
                            <span id="saveOfficeLogBtnIcon"><i class="fas fa-save"></i> Save All Logs</span>
                            <span id="saveOfficeLogBtnLoading"
                                style="display:none;align-items:center;justify-content:center;gap:.4rem;">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"
                                    style="width:.85rem;height:.85rem;"></span>
                                Saving...
                            </span>
                        </button>
                        <a href="office_log_list.php" class="cn-btn cn-btn-out" style="justify-content:center;">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </div>
            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
    let entryCount = 0;
    const companiesData = <?= json_encode($companies) ?>;
    const supervisorsData = <?= json_encode($supervisors) ?>;
    const defaultSupervisorId = <?= (int) $defaultSupervisorId ?>;
    const todayStr = '<?= $today ?>';

    function rowTemplate(idx, prefill = {}) {
        const clientId = prefill.client_id || '';
        const logDate = prefill.log_date || todayStr;
        const timeIn = prefill.time_in || '';
        const timeOut = prefill.time_out || '';
        const description = prefill.description || '';
        const notes = prefill.notes || '';
        const status = prefill.status || 'wip';
        const supervisorId = prefill.supervisor_id || defaultSupervisorId || '';
        const errMsgs = prefill.errors || [];

        const errHtml = errMsgs.length
            ? `<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.6rem .8rem;margin-bottom:.75rem;font-size:.76rem;color:#991b1b;">
             ${errMsgs.map(e => `<div><i class="fas fa-exclamation-circle me-1"></i>${e}</div>`).join('')}
           </div>`
            : '';

        const statuses = [
            ['not_started', 'Not Started', 'Work not started', '#fee2e2', '#dc2626', 'fa-clock'],
            ['wip', 'WIP', 'Work in progress', '#eff6ff', '#3b82f6', 'fa-spinner'],
            ['holding', 'Holding', 'Work on hold', '#ede9fe', '#6d28d9', 'fa-pause-circle'],
            ['completed', 'Completed', 'Work fully done', '#f0fdf4', '#10b981', 'fa-check-circle'],
        ];

        const statusHtml = statuses.map(([val, label, sub, bg, col, icon]) => `
        <label class="status-opt" data-bg="${bg}" data-col="${col}"
               style="display:flex;align-items:center;gap:10px;cursor:pointer;
                      border:2px solid ${status === val ? col : '#e5e7eb'};border-radius:10px;padding:10px 12px;
                      transition:.15s;background:${status === val ? bg : '#fff'};">
            <input type="radio" name="entries[${idx}][status]" value="${val}"
                   ${status === val ? 'checked' : ''} class="status-radio" style="display:none;">
            <div style="width:34px;height:34px;border-radius:8px;background:${bg};
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas ${icon}" style="color:${col};font-size:.85rem;"></i>
            </div>
            <div>
                <div style="font-size:.82rem;font-weight:700;color:#374151;">${label}</div>
                <div style="font-size:.68rem;color:#9ca3af;">${sub}</div>
            </div>
        </label>`).join('');

        return `
    <div class="card-mis mb-3 entry-row" data-idx="${idx}">
        <div class="card-mis-header d-flex justify-content-between align-items-center">
            <h5><i class="fas fa-desktop text-warning me-2"></i>Office Work Entry #${idx + 1}</h5>
            <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn" title="Remove this entry">
                <i class="fas fa-trash"></i>
            </button>
        </div>
        <div class="card-mis-body">
            ${errHtml}
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label-mis">Client <span class="required-star">*</span></label>
                    <select name="entries[${idx}][client_id]" class="form-select client-select" required>
                        <option value="">-- Select Client --</option>
                        ${companiesData.map(c => `<option value="${c.id}"
                            data-code="${c.company_code || ''}" data-pan="${c.pan_number || ''}"
                            ${String(clientId) === String(c.id) ? 'selected' : ''}>
                            ${c.company_name}${c.company_code ? ' — ' + c.company_code : ''}${c.pan_number ? ' — ' + c.pan_number : ''}
                        </option>`).join('')}
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label-mis">Supervisor <span class="required-star">*</span></label>
                    <select name="entries[${idx}][supervisor_id]" class="form-select supervisor-select" required>
                        <option value="">-- Select Supervisor --</option>
                        ${supervisorsData.map(s => `<option value="${s.id}"
                            ${String(supervisorId) === String(s.id) ? 'selected' : ''}>
                            ${s.full_name}${s.employee_id ? ' (' + s.employee_id + ')' : ''}
                        </option>`).join('')}
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label-mis">Log Date <span class="required-star">*</span></label>
                    <input type="date" name="entries[${idx}][log_date]" class="form-control" required
                        value="${logDate}" max="${todayStr}">
                </div>
                <div class="col-md-4">
                    <label class="form-label-mis">Start Time <span class="required-star">*</span></label>
                    <input type="time" name="entries[${idx}][time_in]" class="form-control time-in" value="${timeIn}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label-mis">End Time <span class="required-star">*</span></label>
                    <input type="time" name="entries[${idx}][time_out]" class="form-control time-out" value="${timeOut}" required>
                </div>
                <div class="col-md-4">
                    <div style="background:#f9fafb;border-radius:8px;padding:10px;margin-top:1.6rem;text-align:center;border:1.5px solid #f1f5f9;">
                        <div style="font-size:.68rem;color:#9ca3af;">Duration</div>
                        <div style="font-size:1.3rem;font-weight:800;color:#c9a84c;" class="duration-disp">—</div>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label-mis">Description <span class="required-star">*</span></label>
                    <textarea name="entries[${idx}][description]" class="form-control" rows="2" required
                        placeholder="Describe the work done for this client in the office…">${description}</textarea>
                </div>
                <div class="col-12">
                    <label class="form-label-mis">Notes <span style="font-size:.72rem;color:#9ca3af;">(optional)</span></label>
                    <input type="text" name="entries[${idx}][notes]" class="form-control"
                        value="${notes}" placeholder="Any additional notes…">
                </div>
                <div class="col-12">
                    <label class="form-label-mis">Status</label>
                    <div class="status-group" style="display:flex;flex-wrap:wrap;gap:8px;">
                        ${statusHtml}
                    </div>
                </div>
            </div>
        </div>
    </div>`;
    }

    function addRow(prefill = {}) {
        const idx = entryCount++;
        const container = document.getElementById('entriesContainer');
        container.insertAdjacentHTML('beforeend', rowTemplate(idx, prefill));

        const rowEl = container.querySelector(`.entry-row[data-idx="${idx}"]`);
        initRow(rowEl);
        updateRemoveButtons();
        return rowEl;
    }

    function initRow(rowEl) {
        const sel = rowEl.querySelector('.client-select');
        new TomSelect(sel, {
            placeholder: 'Search by name, code or PAN…',
            maxOptions: 500,
            allowEmptyOption: true,
            searchField: ['text'],
            score: function (search) {
                const s = search.toLowerCase();
                return function (item) {
                    const text = (item.text || '').toLowerCase();
                    const code = (item.$option?.dataset?.code || '').toLowerCase();
                    const pan = (item.$option?.dataset?.pan || '').toLowerCase();
                    if (text.includes(s) || code.includes(s) || pan.includes(s)) return 1;
                    return 0;
                };
            },
            render: {
                option: function (data, escape) {
                    const code = data.$option?.dataset?.code || '';
                    const pan = data.$option?.dataset?.pan || '';
                    const name = escape(data.text.split(' — ')[0]);
                    return `<div style="padding:4px 2px;">
                    <div style="font-weight:600;font-size:.83rem;">${name}</div>
                    <div style="font-size:.7rem;color:#9ca3af;display:flex;gap:10px;margin-top:1px;">
                        ${code ? `<span><i class="fas fa-tag" style="font-size:.6rem;"></i> ${escape(code)}</span>` : ''}
                        ${pan ? `<span><i class="fas fa-id-card" style="font-size:.6rem;"></i> PAN: ${escape(pan)}</span>` : ''}
                    </div>
                </div>`;
                },
                item: function (data, escape) {
                    const pan = data.$option?.dataset?.pan || '';
                    const name = escape(data.text.split(' — ')[0]);
                    return pan
                        ? `<div>${name} <span style="font-size:.72rem;color:#9ca3af;">(PAN: ${escape(pan)})</span></div>`
                        : `<div>${name}</div>`;
                }
            }
        });
        const supSel = rowEl.querySelector('.supervisor-select');
        if (supSel) {
            new TomSelect(supSel, {
                placeholder: 'Search supervisor…',
                allowEmptyOption: true,
                maxOptions: 500,
            });
        }

        // Duration calc
        const calcDur = () => {
            const tin = rowEl.querySelector('.time-in').value;
            const tout = rowEl.querySelector('.time-out').value;
            const disp = rowEl.querySelector('.duration-disp');
            if (tin && tout) {
                const diff = (new Date('1970-01-01T' + tout) - new Date('1970-01-01T' + tin)) / 3600000;
                disp.textContent = diff > 0 ? diff.toFixed(2) + 'h' : '—';
                disp.style.color = diff > 0 ? '#c9a84c' : '#ef4444';
            } else {
                disp.textContent = '—';
                disp.style.color = '#9ca3af';
            }
        };
        rowEl.querySelector('.time-in').addEventListener('change', calcDur);
        rowEl.querySelector('.time-out').addEventListener('change', calcDur);
        calcDur();

        // Status card selection
        rowEl.querySelectorAll('.status-radio').forEach(radio => {
            radio.addEventListener('change', () => {
                rowEl.querySelectorAll('.status-opt').forEach(opt => {
                    const r = opt.querySelector('.status-radio');
                    opt.style.borderColor = r.checked ? opt.dataset.col : '#e5e7eb';
                    opt.style.background = r.checked ? opt.dataset.bg : '#fff';
                });
            });
        });

        // Remove button
        rowEl.querySelector('.remove-row-btn').addEventListener('click', () => {
            if (document.querySelectorAll('.entry-row').length <= 1) return; // keep at least 1
            rowEl.remove();
            updateRemoveButtons();
        });
    }

    function updateRemoveButtons() {
        const rows = document.querySelectorAll('.entry-row');
        rows.forEach(r => {
            const btn = r.querySelector('.remove-row-btn');
            btn.style.display = rows.length <= 1 ? 'none' : 'inline-block';
        });
    }

    document.getElementById('addEntryBtn').addEventListener('click', () => addRow());

    // Re-populate failed rows after partial save, or start with one blank row
    const initialEntries = <?= json_encode($_POST['entries'] ?? []) ?>;
    const initialErrors = <?= json_encode($errors ?? []) ?>;

    if (Object.keys(initialEntries).length > 0) {
        Object.keys(initialEntries).forEach(key => {
            addRow({ ...initialEntries[key], errors: initialErrors[key] || [] });
        });
    } else {
        addRow();
    }

    document.getElementById('officeLogForm').addEventListener('submit', function () {
        const btn = document.getElementById('saveOfficeLogBtn');
        btn.disabled = true;
        btn.style.opacity = '0.7';
        document.getElementById('saveOfficeLogBtnIcon').style.display = 'none';
        document.getElementById('saveOfficeLogBtnLoading').style.display = 'inline-flex';
    });
</script>
<?php include '../../includes/footer.php'; ?>