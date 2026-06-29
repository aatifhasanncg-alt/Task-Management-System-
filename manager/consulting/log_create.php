<?php
/**
 * consulting/staff/log_create.php — Staff: Log a Client Visit
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireManager();

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

    $clientId = (int) ($_POST['client_id'] ?? 0);
    $logDate = $_POST['log_date'] ?? $today;
    $timeIn = $_POST['time_in'] ?: null;
    $timeOut = $_POST['time_out'] ?: null;
    $visitStatus = $_POST['visit_status'] ?? 'visited';
    $rescheduleDate = $_POST['reschedule_date'] ?: null;
    $rescheduleTimeIn = $_POST['reschedule_time_in'] ?: null;
    $rescheduleTimeOut = $_POST['reschedule_time_out'] ?: null;
    $rescheduleNotes = trim($_POST['reschedule_notes'] ?? '');
    $workDesc = trim($_POST['work_description'] ?? '');
    $supervisorId = (isset($_POST['supervisor_id']) && $_POST['supervisor_id'] !== '')
        ? (int) $_POST['supervisor_id']
        : null;

    // fallback to managed_by from DB (NOT session)
    if (!$supervisorId) {
        $mb = $db->prepare("SELECT managed_by FROM users WHERE id = ?");
        $mb->execute([$uid]);
        $supervisorId = (int) $mb->fetchColumn();
    }
    if (!$clientId)
        $errors[] = 'Please select a client.';
    if (!$logDate)
        $errors[] = 'Log date is required.';
    if ($visitStatus === 'rescheduled') {
        if (!$rescheduleDate) {
            $errors[] = 'Please select a new date for the rescheduled visit.';
        } elseif ($rescheduleDate <= $logDate) {
            $errors[] = 'Rescheduled date must be after the log date.';
        }
    }

    $durHours = 0;
    if ($timeIn && $timeOut) {
        $diff = strtotime($timeOut) - strtotime($timeIn);
        $durHours = round($diff / 3600, 2);
        if ($durHours < 0)
            $errors[] = 'Time Out must be after Time In.';
    }

    // Week number
    $dateObj = new DateTime($logDate);
    $weekNum = (int) ceil((int) $dateObj->format('j') / 7);
    $monthYear = $dateObj->format('Y-m');
    $dow = $dateObj->format('l');

    if (!$errors) {
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

        $rescheduledEntryId = null; // we'll need this — see schema note below

        if ($visitStatus === 'rescheduled' && $rescheduleDate) {
            $rdObj = new DateTime($rescheduleDate);
            $rPlanMonth = $rdObj->format('Y-m-01');
            $rWeekNum = (int) ceil((int) $rdObj->format('j') / 7);
            $rDow = $rdObj->format('l');

            // Week range, clipped to the month (matches your existing week_number logic)
            $monthStart = new DateTime($rPlanMonth);
            $monthEnd = (clone $monthStart)->modify('last day of this month');
            $wStart = (clone $monthStart)->modify('+' . (($rWeekNum - 1) * 7) . ' days');
            $wEnd = (clone $wStart)->modify('+6 days');
            if ($wEnd > $monthEnd) {
                $wEnd = clone $monthEnd;
            }

            // Find existing plan for this user/dept/branch/month/week, else create
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

            // Client code for the plan entry
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
        }

        setFlash(
            'success',
            $visitStatus === 'rescheduled'
            ? 'Visit logged and rescheduled — new plan entry created!'
            : 'Visit logged successfully!'
        );
        header('Location: log_list.php?month=' . $monthYear);
        exit;
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
    <?php include '../../includes/sidebar_manager.php'; ?>
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
                            <?php foreach ($errors as $e): ?>
                                <li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
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
                                <button type="button" class="btn btn-sm"
                                    style="background:#d1fae5;color:#047857;border:none;border-radius:99px;padding:.2rem .7rem;font-size:.73rem;font-weight:600;cursor:pointer;transition:.2s;"
                                    onclick="fillFromPlan(<?= $te['client_id'] ?>, <?= $te['id'] ?>, '<?= $te['planned_time_in'] ?>', '<?= $te['planned_time_out'] ?>')"
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
                <input type="hidden" name="plan_entry_id" id="planEntryId" value="">

                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="card-mis mb-4">
                            <div class="card-mis-header">
                                <h5><i class="fas fa-clipboard-list text-warning me-2"></i>Visit Details</h5>
                            </div>
                            <div class="card-mis-body">
                                <div class="row g-3">

                                    <div class="col-md-8">
                                        <label class="form-label-mis">Client <span
                                                class="required-star">*</span></label>
                                        <select name="client_id" id="clientSelect" class="form-select" required>
                                            <option value="">-- Select Client --</option>
                                            <?php foreach ($companies as $c): ?>
                                                <option value="<?= $c['id'] ?>"
                                                    data-code="<?= htmlspecialchars($c['company_code'] ?? '') ?>"
                                                    data-pan="<?= htmlspecialchars($c['pan_number'] ?? '') ?>"
                                                    <?= ($_POST['client_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($c['company_name']) ?>
                                                    <?= $c['company_code'] ? ' — ' . htmlspecialchars($c['company_code']) : '' ?>
                                                    <?= $c['pan_number'] ? ' — ' . htmlspecialchars($c['pan_number']) : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label-mis">Log Date <span
                                                class="required-star">*</span></label>
                                        <input type="date" name="log_date" class="form-control" required
                                            value="<?= htmlspecialchars($_POST['log_date'] ?? $today) ?>"
                                            max="<?= $today ?>">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label-mis">Time In</label>
                                        <input type="time" name="time_in" id="timeIn" class="form-control"
                                            value="<?= htmlspecialchars($_POST['time_in'] ?? '') ?>"
                                            onchange="calcDuration()">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label-mis">Time Out</label>
                                        <input type="time" name="time_out" id="timeOut" class="form-control"
                                            value="<?= htmlspecialchars($_POST['time_out'] ?? '') ?>"
                                            onchange="calcDuration()">
                                    </div>

                                    <div class="col-md-3">
                                        <div
                                            style="background:#f9fafb;border-radius:8px;padding:10px;margin-top:1.6rem;text-align:center;">
                                            <div style="font-size:.7rem;color:#9ca3af;">Duration</div>
                                            <div style="font-size:1.3rem;font-weight:800;color:#c9a84c;"
                                                id="durationDisp">—</div>
                                        </div>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label-mis">Visit Status</label>
                                        <select name="visit_status" id="visitStatus" class="form-select"
                                            onchange="toggleReschedule()">
                                            <option value="visited" <?= ($_POST['visit_status'] ?? 'visited') === 'visited' ? 'selected' : '' ?>>✅ Visited</option>
                                            <option value="missed" <?= ($_POST['visit_status'] ?? '') === 'missed' ? 'selected' : '' ?>>❌ Missed</option>
                                            <option value="rescheduled" <?= ($_POST['visit_status'] ?? '') === 'rescheduled' ? 'selected' : '' ?>>🔄 Rescheduled</option>
                                        </select>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label-mis">Work Description</label>
                                        <textarea name="work_description" class="form-control" rows="3"
                                            placeholder="What work was done during this visit..."><?= htmlspecialchars($_POST['work_description'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-12" id="rescheduleSection" style="display:none;">
                                        <div
                                            style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:1rem;margin-top:.5rem;">
                                            <div
                                                style="font-size:.8rem;font-weight:700;color:#92400e;margin-bottom:.75rem;">
                                                <i class="fas fa-redo me-1"></i>Reschedule To — New Plan Entry
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label-mis">New Visit Date <span
                                                            class="required-star">*</span></label>
                                                    <input type="date" name="reschedule_date" id="rescheduleDate"
                                                        class="form-control"
                                                        min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                                        value="<?= htmlspecialchars($_POST['reschedule_date'] ?? '') ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label-mis">Planned Time In</label>
                                                    <input type="time" name="reschedule_time_in" id="rescheduleTimeIn"
                                                        class="form-control"
                                                        value="<?= htmlspecialchars($_POST['reschedule_time_in'] ?? '') ?>"
                                                        onchange="calcRescheduleDuration()">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label-mis">Planned Time Out</label>
                                                    <input type="time" name="reschedule_time_out" id="rescheduleTimeOut"
                                                        class="form-control"
                                                        value="<?= htmlspecialchars($_POST['reschedule_time_out'] ?? '') ?>"
                                                        onchange="calcRescheduleDuration()">
                                                </div>
                                                <div class="col-md-3">
                                                    <div
                                                        style="background:#fff;border-radius:8px;padding:8px;text-align:center;">
                                                        <div style="font-size:.7rem;color:#9ca3af;">Planned Duration
                                                        </div>
                                                        <div style="font-size:1.1rem;font-weight:800;color:#c9a84c;"
                                                            id="rescheduleDurationDisp">—</div>
                                                    </div>
                                                </div>
                                                <div class="col-md-9">
                                                    <label class="form-label-mis">Reschedule Notes</label>
                                                    <input type="text" name="reschedule_notes" class="form-control"
                                                        placeholder="Reason / new arrangement notes..."
                                                        value="<?= htmlspecialchars($_POST['reschedule_notes'] ?? '') ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card-mis mb-3">
                            <div class="card-mis-header">
                                <h5>Actions</h5>
                            </div>
                            <div class="card-mis-body">
                                <button type="submit" id="saveLogBtn" class="btn-gold btn w-100 mb-2">
                                    <span id="saveLogBtnIcon"><i class="fas fa-save me-2"></i>Save Log</span>
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
    new TomSelect('#clientSelect', {
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

    function calcDuration() {
        const tin = document.getElementById('timeIn').value;
        const tout = document.getElementById('timeOut').value;
        const disp = document.getElementById('durationDisp');
        if (tin && tout) {
            const diff = (new Date('1970-01-01T' + tout) - new Date('1970-01-01T' + tin)) / 3600000;
            disp.textContent = diff > 0 ? diff.toFixed(2) + 'h' : '—';
            disp.style.color = diff > 0 ? '#c9a84c' : '#ef4444';
        } else {
            disp.textContent = '—';
            disp.style.color = '#9ca3af';
        }
    }
    function toggleReschedule() {
        const status = document.getElementById('visitStatus').value;
        const section = document.getElementById('rescheduleSection');
        const dateField = document.getElementById('rescheduleDate');
        if (status === 'rescheduled') {
            section.style.display = 'block';
            dateField.setAttribute('required', 'required');
        } else {
            section.style.display = 'none';
            dateField.removeAttribute('required');
        }
    }
    function calcRescheduleDuration() {
        const tin = document.getElementById('rescheduleTimeIn').value;
        const tout = document.getElementById('rescheduleTimeOut').value;
        const disp = document.getElementById('rescheduleDurationDisp');
        if (tin && tout) {
            const diff = (new Date('1970-01-01T' + tout) - new Date('1970-01-01T' + tin)) / 3600000;
            disp.textContent = diff > 0 ? diff.toFixed(2) + 'h' : '—';
            disp.style.color = diff > 0 ? '#c9a84c' : '#ef4444';
        } else {
            disp.textContent = '—';
            disp.style.color = '#9ca3af';
        }
    }

    function fillFromPlan(clientId, entryId, timeIn, timeOut) {
        // Set client in TomSelect
        const ts = document.getElementById('clientSelect').tomselect;
        if (ts) ts.setValue(clientId);
        document.getElementById('planEntryId').value = entryId;
        if (timeIn) document.getElementById('timeIn').value = timeIn.substring(0, 5);
        if (timeOut) document.getElementById('timeOut').value = timeOut.substring(0, 5);
        calcDuration();
    }

    // Init duration on load
    calcDuration();
    toggleReschedule();
    calcRescheduleDuration();
    document.getElementById('logForm').addEventListener('submit', function () {
        const btn = document.getElementById('saveLogBtn');
        btn.disabled = true;
        btn.style.opacity = '0.7';
        document.getElementById('saveLogBtnIcon').style.display = 'none';
        document.getElementById('saveLogBtnLoading').style.display = 'inline-flex';
    });
</script>
<?php include '../../includes/footer.php'; ?>