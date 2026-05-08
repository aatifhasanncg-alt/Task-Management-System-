<?php
/**
 * consulting/staff/log_create.php — Staff: Log a Client Visit
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
$month = $_GET['month'] ?? $now->format('Y-m');
$today = $now->format('Y-m-d');

// Companies
$companies = $db->prepare("
    SELECT id, company_name, company_code, pan_number FROM companies
    WHERE is_active=1
    ORDER BY company_name
");
$companies->execute([]);
$companies = $companies->fetchAll();
// Supervisors: admin role OR assigned to CON dept via primary dept or UDA
// First try UDA managed_by for CON dept
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

// Fallback to users.managed_by if UDA has none
if (!$defaultSupervisor) {
    $mbStmt = $db->prepare("SELECT managed_by FROM users WHERE id = ?");
    $mbStmt->execute([$uid]);
    $defaultSupervisor = $mbStmt->fetchColumn();
}

// Supervisors: only admin OR staff linked to CON dept
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
    $workDesc = trim($_POST['work_description'] ?? '');
    $planEntryId = (int) ($_POST['plan_entry_id'] ?? 0) ?: null;
    $supervisorId = (int) ($_POST['supervisor_id'] ?? 0) ?: null;
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

    // Week number
    $dateObj = new DateTime($logDate);
    $weekNum = (int) ceil((int) $dateObj->format('j') / 7);
    $monthYear = $dateObj->format('Y-m');
    $dow = $dateObj->format('l');

    if (!$errors) {
        $cnRow = $db->prepare("SELECT company_name FROM companies WHERE id = ?");
        $cnRow->execute([$clientId]);
        $clientName = $cnRow->fetchColumn() ?: 'Client #' . $clientId;
        $ins = $db->prepare("
            INSERT INTO work_logs
            (user_id, client_id, supervisor_id, plan_entry_id, department_id, branch_id,
            log_date, day_of_week, week_number, month_year,
            time_in, time_out, duration_hours, work_description, visit_status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $uid,
            $clientId,
            $supervisorId,
            $planEntryId,
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

        // Notify only the manager (managed_by)
        if (!empty($user['managed_by'])) {
            notify(
                (int) $user['managed_by'],
                'Visit Logged',
                $user['full_name'] . ' logged a ' . $visitStatus . ' visit to ' . $clientName . ' on ' . date('d M Y', strtotime($logDate)),
                'task',
                APP_URL . '/admin/planning/log_list.php?month=' . $monthYear,
                false,
                []
            );
        }

        logActivity('Logged visit to client #' . $clientId, 'consulting', 'log_id=' . $logId);
        setFlash('success', 'Visit logged successfully!');
        header('Location: log_list.php?month=' . $monthYear);
        exit;
    }
}

$pageTitle = 'Log Visit';
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
                        <h4>Log Client Visit</h4>
                        <p><?= htmlspecialchars($user['full_name']) ?> · <?= date('d M Y') ?></p>
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

            <!-- Today's planned visits quick-link -->
            <?php if (!empty($todayEntries)): ?>
                <div class="cn-alert cn-alert-info mb-3">
                    <i class="fas fa-calendar-check me-2"></i>
                    <strong>Today's Planned Visits:</strong>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;">
                        <?php foreach ($todayEntries as $te): ?>
                            <button type="button" class="cn-btn cn-btn-blue cn-btn-sm"
                                onclick="fillFromPlan(<?= $te['client_id'] ?>, <?= $te['id'] ?>, '<?= $te['planned_time_in'] ?>', '<?= $te['planned_time_out'] ?>')">
                                <i class="fas fa-building me-1"></i>
                                <?= htmlspecialchars(mb_strimwidth($te['company_name'], 0, 20, '…')) ?>
                                <?= $te['planned_time_in'] ? ' · ' . date('H:i', strtotime($te['planned_time_in'])) : '' ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" id="logForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="plan_entry_id" id="planEntryId" value="">

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
                                                <option value="<?= $c['id'] ?>" <?= ($_POST['client_id'] ?? '') == $c['id'] ? 'selected' : '' ?>
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
                                            value="<?= htmlspecialchars($_POST['log_date'] ?? $today) ?>"
                                            max="<?= $today ?>">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="cn-label">Time In</label>
                                        <input type="time" name="time_in" id="timeIn" class="cn-input"
                                            value="<?= htmlspecialchars($_POST['time_in'] ?? '') ?>"
                                            onchange="calcDuration()">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="cn-label">Time Out</label>
                                        <input type="time" name="time_out" id="timeOut" class="cn-input"
                                            value="<?= htmlspecialchars($_POST['time_out'] ?? '') ?>"
                                            onchange="calcDuration()">
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
                                            <option value="visited" <?= ($_POST['visit_status'] ?? 'visited') === 'visited' ? 'selected' : '' ?>>✅ Visited</option>
                                            <option value="missed" <?= ($_POST['visit_status'] ?? '') === 'missed' ? 'selected' : '' ?>>❌ Missed</option>
                                            <option value="rescheduled" <?= ($_POST['visit_status'] ?? '') === 'rescheduled' ? 'selected' : '' ?>>🔄 Rescheduled</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
    <label class="cn-label">Supervisor <span
            style="font-size:.7rem;color:#9ca3af;">(Consulting dept only)</span></label>
    <select name="supervisor_id" id="supervisorSelect" class="cn-input">
        <option value="">— None —</option>
        <?php foreach ($supervisors as $sv):
            $selected = '';
            if (isset($_POST['supervisor_id'])) {
                $selected = ($_POST['supervisor_id'] == $sv['id']) ? 'selected' : '';
            } else {
                $selected = ($defaultSupervisor && $defaultSupervisor == $sv['id']) ? 'selected' : '';
            }
            $label = trim(($sv['employee_id'] ? '[' . $sv['employee_id'] . '] ' : '') . $sv['full_name']);
        ?>
            <option value="<?= $sv['id'] ?>" <?= $selected ?>
                data-empid="<?= htmlspecialchars($sv['employee_id'] ?? '') ?>">
                <?= htmlspecialchars($label) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
                                    <div class="col-12">
                                        <label class="cn-label">Work Description</label>
                                        <textarea name="work_description" class="cn-input" rows="3"
                                            placeholder="What work was done during this visit…"><?= htmlspecialchars($_POST['work_description'] ?? '') ?></textarea>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT -->
                    <div>

                        <!-- Duration display -->
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
                                <!-- Status guide -->
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

                        <!-- Actions -->
                        <div class="cn-panel">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-save me-2" style="color:var(--gold)"></i>Save
                                </span>
                            </div>
                            <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px;">
                                <button type="submit" class="cn-btn cn-btn-gold" style="justify-content:center;">
                                    <i class="fas fa-save"></i> Save Log
                                </button>
                                <a href="log_list.php" class="cn-btn cn-btn-out" style="justify-content:center;">
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
    new TomSelect('#clientSelect', {
        placeholder: 'Search by name, code or PAN...',
        maxOptions: 500,
        allowEmptyOption: true,
        searchField: ['text']   // searches full option text including PAN
    });
new TomSelect('#supervisorSelect', {
    placeholder: 'Search by name or employee ID...',
    allowEmptyOption: true,
    searchField: ['text'],
    render: {
        option: function(data, escape) {
            return '<div>' + escape(data.text) + '</div>';
        },
        item: function(data, escape) {
            return '<div>' + escape(data.text) + '</div>';
        }
    }
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
</script>
<?php include '../../includes/footer.php'; ?>