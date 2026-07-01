<?php
/**
 * consulting/staff/log_create.php — Staff: Log a Client Visit
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireExecutive();

$db = getDB();
$user = currentUser();
$uid = (int) $user['id'];


$deptId = (int) $user['department_id'];


$branchId = (int) $user['branch_id'];
// ── UDA consulting dept detection ─────────────────────────────
$__deptMetaQ = $db->prepare("SELECT dept_code, dept_name FROM departments WHERE id = ?");
$__deptMetaQ->execute([$user['department_id']]);
$__deptMeta = $__deptMetaQ->fetch(PDO::FETCH_ASSOC);
$__primaryCode = $__deptMeta['dept_code'] ?? '';
$__isConsPrimary = ($__primaryCode === 'CON' || stripos($__deptMeta['dept_name'] ?? '', 'consult') !== false);
$__isCoreAdmin = ($__primaryCode === 'CORE');

$__udaQ = $db->prepare("
    SELECT d.id, d.dept_code FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ? AND (d.dept_code = 'CON' OR d.dept_name LIKE '%consult%')
    LIMIT 1
");
$__udaQ->execute([$uid]);
$__udaCons = $__udaQ->fetch(PDO::FETCH_ASSOC);

if ($__isConsPrimary) {
    $deptId = (int) $user['department_id'];
} elseif ($__isCoreAdmin && $__udaCons) {
    $deptId = (int) $__udaCons['id'];
} elseif ($__udaCons) {
    $deptId = (int) $__udaCons['id'];
}
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
$now = new DateTime();
$month = $_GET['month'] ?? $now->format('Y-m');
$today = $now->format('Y-m-d');

// Companies
$companies = $db->query("
    SELECT id, company_name, company_code, pan_number FROM companies
    WHERE is_active=1 ORDER BY company_name
")->fetchAll(PDO::FETCH_ASSOC);

// Plan entries for today (for quick link)
$todayEntries = $db->prepare("
    SELECT wpe.*, c.company_name, c.company_code, wp.week_number
    FROM work_plan_entries wpe
    JOIN work_plans wp ON wp.id=wpe.plan_id
    JOIN companies c ON c.id=wpe.client_id
    WHERE wpe.assigned_to=? AND wpe.plan_date=?
    ORDER BY wpe.planned_time_in ASC
");
$todayEntries->execute([$uid, $today]);
$todayEntries = $todayEntries->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $rows = $_POST['entries'] ?? [];
    $savedCount = 0;
    $rescheduledCount = 0;
    $rowErrors = []; // [rowIndex => [error, error...]]

    foreach ($rows as $idx => $row) {
        $clientId = (int) ($row['client_id'] ?? 0);
        $logDate = $row['log_date'] ?? $today;
        $timeIn = $row['time_in'] ?: null;
        $timeOut = $row['time_out'] ?: null;
        $visitStatus = $row['visit_status'] ?? 'visited';
        $rescheduleDate = $row['reschedule_date'] ?: null;
        $rescheduleTimeIn = $row['reschedule_time_in'] ?: null;
        $rescheduleTimeOut = $row['reschedule_time_out'] ?: null;
        $rescheduleNotes = trim($row['reschedule_notes'] ?? '');
        $workDesc = trim($row['work_description'] ?? '');
        $supervisorId = (isset($row['supervisor_id']) && $row['supervisor_id'] !== '')
            ? (int) $row['supervisor_id']
            : null;

        if (!$supervisorId) {
            $mb = $db->prepare("SELECT managed_by FROM users WHERE id = ?");
            $mb->execute([$uid]);
            $supervisorId = (int) $mb->fetchColumn();
        }

        $rowErr = [];
        if (!$clientId)
            $rowErr[] = 'Please select a client.';
        if (!$logDate)
            $rowErr[] = 'Log date is required.';
        if ($visitStatus === 'rescheduled') {
            if (!$rescheduleDate) {
                $rowErr[] = 'Please select a new date for the rescheduled visit.';
            } elseif ($rescheduleDate <= $logDate) {
                $rowErr[] = 'Rescheduled date must be after the log date.';
            }
        }

        $durHours = 0;
        if ($timeIn && $timeOut) {
            $diff = strtotime($timeOut) - strtotime($timeIn);
            $durHours = round($diff / 3600, 2);
            if ($durHours < 0)
                $rowErr[] = 'Time Out must be after Time In.';
        }

        if ($rowErr) {
            $rowErrors[$idx] = $rowErr;
            continue; // skip this row, keep processing the rest
        }

        // ── This row is valid — save it in its own transaction ──
        $db->beginTransaction();
        try {
            $dateObj = new DateTime($logDate);
            $weekNum = (int) ceil((int) $dateObj->format('j') / 7);
            $monthYear = $dateObj->format('Y-m');
            $dow = $dateObj->format('l');

            $ins = $db->prepare("
                INSERT INTO work_logs
                (user_id, client_id, supervisor_id, department_id, branch_id,
                 log_date, day_of_week, week_number, month_year,
                 time_in, time_out, duration_hours, work_description, visit_status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $ins->execute([
                $uid,
                $clientId,
                $supervisorId,
                $deptId,
                $branchId,
                $logDate,
                $dow,
                $weekNum,
                $monthYear,
                $timeIn,
                $timeOut,
                $durHours,
                $workDesc,
                $visitStatus
            ]);
            $logId = $db->lastInsertId();
            logActivity('Logged visit to client #' . $clientId, 'consulting', 'log_id=' . $logId);

            if ($visitStatus === 'rescheduled' && $rescheduleDate) {
                $rdObj = new DateTime($rescheduleDate);
                $rPlanMonth = $rdObj->format('Y-m-01');
                $rWeekNum = (int) ceil((int) $rdObj->format('j') / 7);
                $rDow = $rdObj->format('l');

                $monthStart = new DateTime($rPlanMonth);
                $monthEnd = (clone $monthStart)->modify('last day of this month');
                $wStart = (clone $monthStart)->modify('+' . (($rWeekNum - 1) * 7) . ' days');
                $wEnd = (clone $wStart)->modify('+6 days');
                if ($wEnd > $monthEnd)
                    $wEnd = clone $monthEnd;

                $planStmt = $db->prepare("
                    SELECT id FROM work_plans
                    WHERE user_id = ? AND department_id = ? AND branch_id = ?
                      AND plan_month = ? AND week_number = ?
                    LIMIT 1
                ");
                $planStmt->execute([$uid, $deptId, $branchId, $rPlanMonth, $rWeekNum]);
                $planId = $planStmt->fetchColumn();

                if (!$planId) {
                    $createPlan = $db->prepare("
                        INSERT INTO work_plans
                        (user_id, supervisor_id, department_id, branch_id, plan_month,
                         week_number, week_start_date, week_end_date, status)
                        VALUES (?,?,?,?,?,?,?,?, 'draft')
                    ");
                    $createPlan->execute([
                        $uid,
                        $supervisorId,
                        $deptId,
                        $branchId,
                        $rPlanMonth,
                        $rWeekNum,
                        $wStart->format('Y-m-d'),
                        $wEnd->format('Y-m-d'),
                    ]);
                    $planId = (int) $db->lastInsertId();
                }

                $ccStmt = $db->prepare("SELECT company_code FROM companies WHERE id = ?");
                $ccStmt->execute([$clientId]);
                $clientCode = $ccStmt->fetchColumn() ?: null;

                $plannedHours = 0;
                if ($rescheduleTimeIn && $rescheduleTimeOut) {
                    $diffR = strtotime($rescheduleTimeOut) - strtotime($rescheduleTimeIn);
                    $plannedHours = $diffR > 0 ? round($diffR / 3600, 2) : 0;
                }

                $insEntry = $db->prepare("
                    INSERT INTO work_plan_entries
                    (plan_id, client_id, client_code, assigned_to, plan_date, day_of_week,
                     planned_time_in, planned_time_out, planned_hours, notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ");
                $insEntry->execute([
                    $planId,
                    $clientId,
                    $clientCode,
                    $uid,
                    $rescheduleDate,
                    $rDow,
                    $rescheduleTimeIn,
                    $rescheduleTimeOut,
                    $plannedHours,
                    $rescheduleNotes !== '' ? $rescheduleNotes : $workDesc,
                ]);
                $rescheduledEntryId = (int) $db->lastInsertId();
                $db->prepare("UPDATE work_logs SET rescheduled_to_entry_id = ? WHERE id = ?")
                    ->execute([$rescheduledEntryId, $logId]);

                logActivity(
                    'Rescheduled visit for client #' . $clientId . ' to ' . $rescheduleDate,
                    'consulting',
                    'plan_entry_id=' . $rescheduledEntryId . ', from_log_id=' . $logId
                );
                $rescheduledCount++;
            }

            $db->commit();
            $savedCount++;

        } catch (Exception $e) {
            $db->rollBack();
            error_log('[log_create] row ' . $idx . ': ' . $e->getMessage());
            $rowErrors[$idx] = ['Failed to save this entry. Please try again or contact support.'];
        }
    }

    if ($savedCount > 0 && empty($rowErrors)) {
        // Everything saved clean
        setFlash('success', $savedCount . ' visit(s) logged successfully'
            . ($rescheduledCount ? " ({$rescheduledCount} rescheduled)" : '') . '!');
        header('Location: log_list.php');
        exit;
    } elseif ($savedCount > 0) {
        // Partial success — some rows failed, redisplay form with only failed rows + errors
        setFlash('warning', $savedCount . ' visit(s) saved. ' . count($rowErrors) . ' row(s) had errors — please review below.');
        $_POST['entries'] = array_intersect_key($rows, $rowErrors); // keep only failed rows on screen
        $errors = $rowErrors;
    } else {
        // Nothing saved
        $errors = $rowErrors;
    }
}
$pageTitle = 'Log Visit';
include '../../includes/header.php';

function vstBadge(string $s): string
{
    $map = [
        'visited' => ['#ecfdf5', '#10b981', 'fa-check-circle', 'Visited'],
        'missed' => ['#fef2f2', '#ef4444', 'fa-times-circle', 'Missed'],
        'rescheduled' => ['#fffbeb', '#f59e0b', 'fa-redo', 'Rescheduled'],
    ];
    [$bg, $col, $ico, $lbl] = $map[$s] ?? ['#f9fafb', '#9ca3af', 'fa-circle', '—'];
    return "<span style='background:{$bg};color:{$col};padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;'><i class='fas {$ico}' style='font-size:.6rem;'></i>{$lbl}</span>";
}
?>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<div class="app-wrapper">
    <?php include '../../includes/sidebar_executive.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div style="padding:1.5rem 0;">

            <?= flashHtml() ?>

            <!-- ── Hero ──────────────────────────────────────────────────── -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-clock"></i> Log Visit</div>
                        <h4>Log Client Visit</h4>
                        <p>
                            <?= htmlspecialchars($user['full_name']) ?> ·
                            <?= date('d M Y') ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="log_list.php" class="btn btn-outline-secondary btn-sm"><i
                                class="fas fa-history me-1"></i>My Logs</a>
                        <a href="../index.php" class="btn btn-outline-secondary btn-sm"><i
                                class="fas fa-home me-1"></i>Dashboard</a>
                    </div>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div
                    style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:.8rem 1rem;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.75rem;">
                    <i class="fas fa-exclamation-circle"
                        style="color:#ef4444;font-size:1.1rem;margin-top:.1rem;flex-shrink:0;"></i>
                    <div>
                        <div style="font-size:.8rem;font-weight:700;color:#991b1b;margin-bottom:.35rem;">
                            Please fix the following:
                        </div>
                        <ul style="margin:0;padding-left:1.25rem;font-size:.78rem;color:#991b1b;">
                            <?php foreach ($errors as $rowIdx => $rowMsgs):
                                foreach ((array) $rowMsgs as $e): ?>
                                    <li>Entry #<?= (int) $rowIdx + 1 ?>: <?= htmlspecialchars($e) ?></li>
                                <?php endforeach;
                            endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Today's planned visits quick-link -->
            <?php if (!empty($todayEntries)): ?>
                <div
                    style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:.8rem 1rem;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.75rem;">
                    <i class="fas fa-calendar-check"
                        style="color:#10b981;font-size:1.1rem;margin-top:.1rem;flex-shrink:0;"></i>
                    <div>
                        <div style="font-size:.8rem;font-weight:700;color:#065f46;margin-bottom:.35rem;">
                            Today's Planned Visits — <?= date('d M Y') ?>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                            <?php foreach ($todayEntries as $te): ?>
                                <button type="button" class="btn btn-sm quick-fill-btn"
                                    style="background:#d1fae5;color:#047857;border:none;border-radius:99px;padding:.2rem .7rem;font-size:.73rem;font-weight:600;cursor:pointer;transition:.2s;"
                                    data-client-id="<?= (int) $te['client_id'] ?>" data-entry-id="<?= (int) $te['id'] ?>"
                                    data-time-in="<?= htmlspecialchars($te['planned_time_in'] ?? '') ?>"
                                    data-time-out="<?= htmlspecialchars($te['planned_time_out'] ?? '') ?>"
                                    data-plan-date="<?= htmlspecialchars($te['plan_date'] ?? '') ?>"
                                    data-notes="<?= htmlspecialchars($te['notes'] ?? '') ?>"
                                    onmouseover="this.style.background='#a7f3d0'" onmouseout="this.style.background='#d1fae5'">
                                    <i class="fas fa-building me-1"></i>
                                    <?= htmlspecialchars(mb_strimwidth($te['company_name'], 0, 20, '…')) ?>
                                    <?= $te['planned_time_in'] ? ' · ' . date('g:i A', strtotime($te['planned_time_in'])) : '' ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" id="logForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div class="row g-4">
                    <div class="col-lg-8">

                        <div id="entriesContainer"></div>

                        <button type="button" class="btn btn-outline-secondary w-100 mb-4" id="addEntryBtn">
                            <i class="fas fa-plus me-2"></i>Add Entry
                        </button>

                    </div>
                    <div class="col-lg-4">
                        <div class="card-mis mb-3">
                            <div class="card-mis-header">
                                <h5>Actions</h5>
                            </div>
                            <div class="card-mis-body">
                                <button type="submit" id="saveLogBtn" class="btn-gold btn w-100 mb-2">
                                    <span id="saveLogBtnIcon"><i class="fas fa-save me-2"></i>Save All Logs</span>
                                    <span id="saveLogBtnLoading"
                                        style="display:none;align-items:center;justify-content:center;">
                                        <span class="spinner-border spinner-border-sm me-2" role="status"
                                            aria-hidden="true"></span>
                                        Saving...
                                    </span>
                                </button>
                                <a href="log_list.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                        <div class="card-mis p-3" style="border-left:3px solid var(--gold);">
                            <p style="font-size:.8rem;font-weight:600;margin-bottom:.5rem;"><i
                                    class="fas fa-lightbulb me-1 text-warning"></i>Status Guide</p>
                            <div style="font-size:.77rem;color:#6b7280;display:flex;flex-direction:column;gap:5px;">
                                <span>✅ <strong>Visited</strong> — Visit completed</span>
                                <span>❌ <strong>Missed</strong> — Client unavailable</span>
                                <span>🔄 <strong>Rescheduled</strong> — New date agreed</span>
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
    let entryCount = 0;
    const companiesData = <?= json_encode($companies) ?>;
    const supervisorsData = <?= json_encode($supervisors) ?>;
    const defaultSupervisorId = <?= (int) $defaultSupervisorId ?>;

    function rowTemplate(idx, prefill = {}) {
        const clientId = prefill.client_id || '';
        const planEntryId = prefill.plan_entry_id || '';
        const logDate = prefill.log_date || '<?= $today ?>';
        const timeIn = prefill.time_in || '';
        const timeOut = prefill.time_out || '';
        const visitStatus = prefill.visit_status || 'visited';
        const workDesc = prefill.work_description || '';
        const supervisorId = prefill.supervisor_id || defaultSupervisorId || '';
        const errMsgs = prefill.errors || [];

        const errHtml = errMsgs.length
            ? `<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.6rem .8rem;margin-bottom:.75rem;font-size:.76rem;color:#991b1b;">
             ${errMsgs.map(e => `<div><i class="fas fa-exclamation-circle me-1"></i>${e}</div>`).join('')}
           </div>`
            : '';

        return `
    <div class="card-mis mb-3 entry-row" data-idx="${idx}">
        <div class="card-mis-header d-flex justify-content-between align-items-center">
            <h5><i class="fas fa-clipboard-list text-warning me-2"></i>Visit Entry #${idx + 1}</h5>
            <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn" title="Remove this entry">
                <i class="fas fa-trash"></i>
            </button>
        </div>
        <div class="card-mis-body">
            ${errHtml}
            <input type="hidden" name="entries[${idx}][plan_entry_id]" class="plan-entry-id-input" value="${planEntryId}">
            <div class="row g-3">
                <div class="col-md-6">
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
                <div class="col-md-3">
                    <label class="form-label-mis">Supervisor <span class="required-star">*</span></label>
                    <select name="entries[${idx}][supervisor_id]" class="form-select supervisor-select" required>
                        <option value="">-- Select Supervisor --</option>
                        ${supervisorsData.map(s => `<option value="${s.id}"
                            ${String(supervisorId) === String(s.id) ? 'selected' : ''}>
                            ${s.full_name}${s.employee_id ? ' (' + s.employee_id + ')' : ''}
                        </option>`).join('')}
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label-mis">Log Date <span class="required-star">*</span></label>
                    <input type="date" name="entries[${idx}][log_date]" class="form-control" required
                        value="${logDate}" max="<?= $today ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label-mis">Time In</label>
                    <input type="time" name="entries[${idx}][time_in]" class="form-control time-in" value="${timeIn}">
                </div>
                <div class="col-md-3">
                    <label class="form-label-mis">Time Out</label>
                    <input type="time" name="entries[${idx}][time_out]" class="form-control time-out" value="${timeOut}">
                </div>
                <div class="col-md-3">
                    <div style="background:#f9fafb;border-radius:8px;padding:10px;margin-top:1.6rem;text-align:center;">
                        <div style="font-size:.7rem;color:#9ca3af;">Duration</div>
                        <div style="font-size:1.3rem;font-weight:800;color:#c9a84c;" class="duration-disp">—</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label-mis">Visit Status</label>
                    <select name="entries[${idx}][visit_status]" class="form-select visit-status-select">
                        <option value="visited" ${visitStatus === 'visited' ? 'selected' : ''}>✅ Visited</option>
                        <option value="missed" ${visitStatus === 'missed' ? 'selected' : ''}>❌ Missed</option>
                        <option value="rescheduled" ${visitStatus === 'rescheduled' ? 'selected' : ''}>🔄 Rescheduled</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label-mis">Work Description</label>
                    <textarea name="entries[${idx}][work_description]" class="form-control" rows="2"
                        placeholder="What work was done during this visit...">${workDesc}</textarea>
                </div>
                <div class="col-12 reschedule-section" style="display:${visitStatus === 'rescheduled' ? 'block' : 'none'};">
                    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:1rem;margin-top:.5rem;">
                        <div style="font-size:.8rem;font-weight:700;color:#92400e;margin-bottom:.75rem;">
                            <i class="fas fa-redo me-1"></i>Reschedule To — New Plan Entry
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label-mis">New Visit Date <span class="required-star">*</span></label>
                                <input type="date" name="entries[${idx}][reschedule_date]" class="form-control reschedule-date"
                                    min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label-mis">Planned Time In</label>
                                <input type="time" name="entries[${idx}][reschedule_time_in]" class="form-control reschedule-time-in">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label-mis">Planned Time Out</label>
                                <input type="time" name="entries[${idx}][reschedule_time_out]" class="form-control reschedule-time-out">
                            </div>
                            <div class="col-md-3">
                                <div style="background:#fff;border-radius:8px;padding:8px;text-align:center;">
                                    <div style="font-size:.7rem;color:#9ca3af;">Planned Duration</div>
                                    <div style="font-size:1.1rem;font-weight:800;color:#c9a84c;" class="reschedule-duration-disp">—</div>
                                </div>
                            </div>
                            <div class="col-md-9">
                                <label class="form-label-mis">Reschedule Notes</label>
                                <input type="text" name="entries[${idx}][reschedule_notes]" class="form-control"
                                    placeholder="Reason / new arrangement notes...">
                            </div>
                        </div>
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
        // TomSelect for client
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

        // Reschedule toggle
        const statusSel = rowEl.querySelector('.visit-status-select');
        const rSection = rowEl.querySelector('.reschedule-section');
        const rDateField = rowEl.querySelector('.reschedule-date');
        const toggleReschedule = () => {
            if (statusSel.value === 'rescheduled') {
                rSection.style.display = 'block';
                rDateField.setAttribute('required', 'required');
            } else {
                rSection.style.display = 'none';
                rDateField.removeAttribute('required');
            }
        };
        statusSel.addEventListener('change', toggleReschedule);
        toggleReschedule();

        // Reschedule duration calc
        const calcRDur = () => {
            const tin = rowEl.querySelector('.reschedule-time-in').value;
            const tout = rowEl.querySelector('.reschedule-time-out').value;
            const disp = rowEl.querySelector('.reschedule-duration-disp');
            if (tin && tout) {
                const diff = (new Date('1970-01-01T' + tout) - new Date('1970-01-01T' + tin)) / 3600000;
                disp.textContent = diff > 0 ? diff.toFixed(2) + 'h' : '—';
                disp.style.color = diff > 0 ? '#c9a84c' : '#ef4444';
            } else {
                disp.textContent = '—';
                disp.style.color = '#9ca3af';
            }
        };
        rowEl.querySelector('.reschedule-time-in').addEventListener('change', calcRDur);
        rowEl.querySelector('.reschedule-time-out').addEventListener('change', calcRDur);

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

    // Quick-fill from today's planned visits — always appends a new row
    function fillFromPlan(clientId, entryId, timeIn, timeOut, planDate, notes) {
        const rowEl = addRow({
            client_id: clientId,
            plan_entry_id: entryId,
            log_date: planDate || '<?= $today ?>',
            time_in: timeIn ? timeIn.substring(0, 5) : '',
            time_out: timeOut ? timeOut.substring(0, 5) : '',
            work_description: notes || ''
        });
        rowEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    document.querySelectorAll('.quick-fill-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const rowEl = addRow({
                client_id: btn.dataset.clientId,
                plan_entry_id: btn.dataset.entryId,
                log_date: btn.dataset.planDate || '<?= $today ?>',
                time_in: btn.dataset.timeIn ? btn.dataset.timeIn.substring(0, 5) : '',
                time_out: btn.dataset.timeOut ? btn.dataset.timeOut.substring(0, 5) : '',
                work_description: btn.dataset.notes || ''
            });
            rowEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });
    // Initial row(s) on page load — either re-populate failed rows after partial save, or start with one blank row
    const initialEntries = <?= json_encode($_POST['entries'] ?? []) ?>;
    const initialErrors = <?= json_encode($errors ?? []) ?>;

    if (Object.keys(initialEntries).length > 0) {
        Object.keys(initialEntries).forEach(key => {
            addRow({ ...initialEntries[key], errors: initialErrors[key] || [] });
        });
    } else {
        addRow();
    }

    document.getElementById('logForm').addEventListener('submit', function () {
        const btn = document.getElementById('saveLogBtn');
        btn.disabled = true;
        btn.style.opacity = '0.7';
        document.getElementById('saveLogBtnIcon').style.display = 'none';
        document.getElementById('saveLogBtnLoading').style.display = 'inline-flex';
    });
</script>
<?php include '../../includes/footer.php'; ?>