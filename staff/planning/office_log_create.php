<?php
/**
 * consulting/staff/office_log_create.php — Staff: Log Office Work
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAnyRole();

$db     = getDB();
$user   = currentUser();
$uid    = (int)$user['id'];
$deptId   = (int)$user['department_id'];
$branchId = (int)$user['branch_id'];

$now   = new DateTime();
$today = $now->format('Y-m-d');
$month = $_GET['month'] ?? $now->format('Y-m');

// Companies
$companies = $db->query("
    SELECT id, company_name, company_code, pan_number
    FROM companies WHERE is_active=1 ORDER BY company_name
")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $clientId   = (int)($_POST['client_id'] ?? 0);
    $logDate    = $_POST['log_date']    ?? $today;
    $timeIn     = $_POST['time_in']     ?: null;
    $timeOut    = $_POST['time_out']    ?: null;
    $description = trim($_POST['description'] ?? '');
    $notes      = trim($_POST['notes']  ?? '') ?: null;
    $status     = in_array($_POST['status'] ?? '', ['not_started', 'wip', 'holding', 'completed']) ? $_POST['status'] : 'wip';

    if (!$clientId)   $errors[] = 'Please select a client.';
    if (!$logDate)    $errors[] = 'Log date is required.';
    if (!$description) $errors[] = 'Description is required.';
    if (!$timeIn)     $errors[] = 'Start Time is required.';
    if (!$timeOut)    $errors[] = 'End Time is required.';

    if ($timeIn && $timeOut) {
        $diff = strtotime($timeOut) - strtotime($timeIn);
        if ($diff <= 0) $errors[] = 'End Time must be after Start Time.';
    }

    if (!$errors) {
        $db->prepare("
            INSERT INTO office_work_logs
            (user_id, department_id, branch_id, client_id,
             log_date, time_in, time_out, description, notes, status)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $uid, $deptId, $branchId, $clientId,
            $logDate, $timeIn, $timeOut, $description, $notes, $status
        ]);
        $logId = $db->lastInsertId();
        // Notify only the manager (managed_by)
        if (!empty($user['managed_by'])) {
            require_once '../../config/notify.php';
            notify(
                (int)$user['managed_by'],
                'Office Work Logged',
                $user['full_name'] . ' logged office work for client #' . $clientId . ' on ' . date('d M Y', strtotime($logDate)),
                'system',
                APP_URL . '/admin/planning/office_log_view.php?id=' . $db->lastInsertId(),
                false,
                []
            );
        }
        logActivity('Office work logged for client #' . $clientId, 'consulting', 'office_log_id=' . $logId);
        setFlash('success', 'Office work log saved successfully!');
        header('Location: office_log_list.php?month=' . substr($logDate, 0, 7));
        exit;
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
    <?php include '../../includes/sidebar_staff.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

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

            <!-- Errors -->
            <?php if (!empty($errors)): ?>
                <div class="cn-alert cn-alert-danger mb-3">
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

            <form method="POST" id="officeLogForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

                    <!-- LEFT -->
                    <div>
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-desktop me-2" style="color:var(--gold)"></i>Work Details
                                </span>
                            </div>
                            <div style="padding:16px 18px;">
                                <div class="row g-3">

                                    <div class="col-md-8">
                                        <label class="cn-label">Client <span class="required-star">*</span></label>
                                        <select name="client_id" id="clientSelect" class="cn-input" required>
                                            <option value="">— Select Client —</option>
                                            <?php foreach ($companies as $c): ?>
                                                <option value="<?= $c['id'] ?>"
                                                    <?= ($_POST['client_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
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

                                    <div class="col-md-4">
                                        <label class="cn-label">Start Time <span class="required-star">*</span></label>
                                        <input type="time" name="time_in" id="timeIn" class="cn-input"
                                               value="<?= htmlspecialchars($_POST['time_in'] ?? '') ?>"
                                               onchange="calcDuration()" required>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="cn-label">End Time <span class="required-star">*</span></label>
                                        <input type="time" name="time_out" id="timeOut" class="cn-input"
                                               value="<?= htmlspecialchars($_POST['time_out'] ?? '') ?>"
                                               onchange="calcDuration()" required>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="cn-label">Duration</label>
                                        <div style="background:#f9fafb;border-radius:8px;padding:10px;
                                                    text-align:center;border:1.5px solid #f1f5f9;">
                                            <div style="font-size:1.3rem;font-weight:800;color:#c9a84c;"
                                                 id="durationDisp">—</div>
                                            <div style="font-size:.68rem;color:#9ca3af;">hours</div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label class="cn-label">Description <span class="required-star">*</span></label>
                                        <textarea name="description" class="cn-input" rows="3" required
                                            placeholder="Describe the work done for this client in the office…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                    </div>

                                    <div class="col-12">
                                        <label class="cn-label">Notes <span style="font-size:.72rem;color:#9ca3af;">(optional)</span></label>
                                        <input type="text" name="notes" class="cn-input"
                                               value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>"
                                               placeholder="Any additional notes…">
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT -->
                    <div>
                        <!-- Session display -->
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-clock me-2" style="color:var(--gold)"></i>Session
                                </span>
                            </div>
                            <div style="padding:14px 16px;">
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                                    <div style="text-align:center;background:#f9fafb;border-radius:8px;padding:12px 6px;">
                                        <div style="font-size:1.4rem;font-weight:800;color:#c9a84c;" id="durationDispSide">—</div>
                                        <div style="font-size:.7rem;color:#9ca3af;margin-top:2px;">Hours</div>
                                    </div>
                                    <div style="text-align:center;background:#f9fafb;border-radius:8px;padding:12px 6px;">
                                        <div style="font-size:.9rem;font-weight:700;color:#374151;"><?= date('d M') ?></div>
                                        <div style="font-size:.7rem;color:#9ca3af;margin-top:2px;">Today</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="cn-panel mb-3">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-flag me-2" style="color:var(--gold)"></i>Status
                                </span>
                            </div>
                            <div style="padding:14px 16px;">
                                <div style="display:flex;flex-direction:column;gap:8px;">

                                    <!-- Not Started -->
                                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;
                                                border:2px solid #e5e7eb;border-radius:10px;padding:10px 12px;
                                                transition:.15s;" id="statusNotStarted">
                                        <input type="radio" name="status" value="not_started"
                                            <?= ($_POST['status'] ?? 'not_started') === 'not_started' ? 'checked' : '' ?>
                                            onchange="updateStatusCards()" style="display:none;">
                                        <div style="width:34px;height:34px;border-radius:8px;background:#fee2e2;
                                                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                            <i class="fas fa-clock" style="color:#dc2626;font-size:.85rem;"></i>
                                        </div>
                                        <div>
                                            <div style="font-size:.82rem;font-weight:700;color:#374151;">Not Started</div>
                                            <div style="font-size:.68rem;color:#9ca3af;">Work not started</div>
                                        </div>
                                    </label>

                                    <!-- WIP -->
                                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;
                                                border:2px solid #e5e7eb;border-radius:10px;padding:10px 12px;
                                                transition:.15s;" id="statusWip">
                                        <input type="radio" name="status" value="wip"
                                            <?= ($_POST['status'] ?? 'not_started') === 'wip' ? 'checked' : '' ?>
                                            onchange="updateStatusCards()" style="display:none;">
                                        <div style="width:34px;height:34px;border-radius:8px;background:#eff6ff;
                                                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                            <i class="fas fa-spinner" style="color:#3b82f6;font-size:.85rem;"></i>
                                        </div>
                                        <div>
                                            <div style="font-size:.82rem;font-weight:700;color:#374151;">WIP</div>
                                            <div style="font-size:.68rem;color:#9ca3af;">Work in progress</div>
                                        </div>
                                    </label>

                                    <!-- Holding -->
                                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;
                                                border:2px solid #e5e7eb;border-radius:10px;padding:10px 12px;
                                                transition:.15s;" id="statusHolding">
                                        <input type="radio" name="status" value="holding"
                                            <?= ($_POST['status'] ?? 'not_started') === 'holding' ? 'checked' : '' ?>
                                            onchange="updateStatusCards()" style="display:none;">
                                        <div style="width:34px;height:34px;border-radius:8px;background:#ede9fe;
                                                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                            <i class="fas fa-pause-circle" style="color:#6d28d9;font-size:.85rem;"></i>
                                        </div>
                                        <div>
                                            <div style="font-size:.82rem;font-weight:700;color:#374151;">Holding</div>
                                            <div style="font-size:.68rem;color:#9ca3af;">Work on hold</div>
                                        </div>
                                    </label>

                                    <!-- Completed -->
                                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;
                                                border:2px solid #e5e7eb;border-radius:10px;padding:10px 12px;
                                                transition:.15s;" id="statusCompleted">
                                        <input type="radio" name="status" value="completed"
                                            <?= ($_POST['status'] ?? 'not_started') === 'completed' ? 'checked' : '' ?>
                                            onchange="updateStatusCards()" style="display:none;">
                                        <div style="width:34px;height:34px;border-radius:8px;background:#f0fdf4;
                                                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                            <i class="fas fa-check-circle" style="color:#10b981;font-size:.85rem;"></i>
                                        </div>
                                        <div>
                                            <div style="font-size:.82rem;font-weight:700;color:#374151;">Completed</div>
                                            <div style="font-size:.68rem;color:#9ca3af;">Work fully done</div>
                                        </div>
                                    </label>

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
                                <a href="office_log_list.php" class="cn-btn cn-btn-out" style="justify-content:center;">
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
    dropdownParent: 'body',
    searchField: ['text']
});

function calcDuration() {
    const tin  = document.getElementById('timeIn').value;
    const tout = document.getElementById('timeOut').value;
    const disp = document.getElementById('durationDisp');
    const side = document.getElementById('durationDispSide');
    if (tin && tout) {
        const diff = (new Date('1970-01-01T' + tout) - new Date('1970-01-01T' + tin)) / 3600000;
        const val  = diff > 0 ? diff.toFixed(2) + 'h' : '—';
        const col  = diff > 0 ? '#c9a84c' : '#ef4444';
        disp.textContent = val; disp.style.color = col;
        side.textContent = val; side.style.color = col;
    } else {
        disp.textContent = '—'; disp.style.color = '#9ca3af';
        side.textContent = '—'; side.style.color = '#9ca3af';
    }
}

function updateStatusCards() {
    const notStarted = document.querySelector('[name="status"][value="not_started"]');
    const wip        = document.querySelector('[name="status"][value="wip"]');
    const holding    = document.querySelector('[name="status"][value="holding"]');
    const comp       = document.querySelector('[name="status"][value="completed"]');

    // border colors per status
    document.getElementById('statusNotStarted').style.borderColor = notStarted.checked ? '#dc2626' : '#e5e7eb';
    document.getElementById('statusWip').style.borderColor        = wip.checked        ? '#3b82f6' : '#e5e7eb';
    document.getElementById('statusHolding').style.borderColor    = holding.checked    ? '#6d28d9' : '#e5e7eb';
    document.getElementById('statusCompleted').style.borderColor  = comp.checked       ? '#10b981' : '#e5e7eb';

    // background colors per status
    document.getElementById('statusNotStarted').style.background  = notStarted.checked ? '#fee2e2' : '#fff';
    document.getElementById('statusWip').style.background         = wip.checked        ? '#eff6ff' : '#fff';
    document.getElementById('statusHolding').style.background     = holding.checked    ? '#ede9fe' : '#fff';
    document.getElementById('statusCompleted').style.background   = comp.checked       ? '#f0fdf4' : '#fff';
}

calcDuration();
updateStatusCards();
</script>
<?php include '../../includes/footer.php'; ?>