<?php
/**
 * consulting/staff/plan_create.php — Create Weekly Work Plan
 * Admin : can create for self OR any staff in same dept+branch
 * Staff : self only
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
require_once '../../config/plan_notify.php';
requireAnyRole();

$db      = getDB();
$user    = currentUser();
$uid     = (int)$user['id'];
$currentRole = $_SESSION['role'] ?? ($user['role'] ?? '');
$isAdmin = in_array($currentRole, ['admin', 'executive', 'manager'], true);


$deptId   = (int)$user['department_id'];

$branchId = (int)$user['branch_id'];
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
// ── Month ──────────────────────────────────────────────────────
$now        = new DateTime();
$month      = $_GET['month'] ?? $now->format('Y-m');
$monthDate  = DateTime::createFromFormat('Y-m-d', $month . '-01') ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthLabel = $monthDate->format('F Y');

// ── Build week blocks for month ───────────────────────────────
$weeks = [];
$last  = (clone $monthDate)->modify('last day of this month');

// Rewind to the Sunday that starts the week containing the 1st
$first = clone $monthDate;
$dowFirst = (int)$first->format('w'); // 0=Sun
if ($dowFirst !== 0) {
    $first->modify('-' . $dowFirst . ' days');
}

$cur = clone $first;
$wn  = 1;
while ($cur <= $last && $wn <= 6) {
    $ws = clone $cur;
    $we = (clone $cur)->modify('+6 days'); // Sun → Sat
    if ($we > $last) $we = clone $last;
    $weeks[] = [
        'week_number'     => $wn,
        'week_start_date' => $ws->format('Y-m-d'),
        'week_end_date'   => $we->format('Y-m-d'),
        'label'           => 'Week ' . $wn . ' (' . $ws->format('d M') . ' – ' . $we->format('d M') . ')',
    ];
    $cur = (clone $we)->modify('+1 day');
    $wn++;
}

// ── Companies for this branch ─────────────────────────────────
$companies = $db->query("
    SELECT id, company_name, company_code, pan_number FROM companies
    WHERE is_active=1 ORDER BY company_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Staff list (admin only) ───────────────────────────────────
// Admin can assign to: dept staff in same branch + managed staff
$deptStaff = [];
if ($isAdmin) {
    $st1 = $db->prepare("
    SELECT DISTINCT 
           u.id,
           u.full_name,
           u.employee_id,
           u.department_id,
           b.branch_name
    FROM users u

    LEFT JOIN branches b 
           ON b.id = u.branch_id

    LEFT JOIN user_department_assignments uda
           ON uda.user_id = u.id

    LEFT JOIN departments d1
           ON d1.id = u.department_id

    LEFT JOIN departments d2
           ON d2.id = uda.department_id

    WHERE u.is_active = 1
      AND (
            u.id = :uid1
            OR u.managed_by = :uid2
            OR uda.managed_by = :uid3
          )

      AND (
            d1.dept_code = 'CON'
            OR d2.dept_code = 'CON'
            OR d1.dept_name LIKE '%consult%'
            OR d2.dept_name LIKE '%consult%'
          )

    ORDER BY u.full_name ASC
");

$st1->execute([
    ':uid1' => $uid,
    ':uid2' => $uid,
    ':uid3' => $uid
]);

$deptStaff = $st1->fetchAll(PDO::FETCH_ASSOC);
}
// ── POST ──────────────────────────────────────────────────────
$errors  = [];
$success = false;
$postData = $_POST; // preserve for repopulation

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Who is this plan for?
    $planUserId = $isAdmin ? (int)($_POST['assigned_user_id'] ?? $uid) : $uid;

    // Staff can only plan for themselves
    if (!$isAdmin && $planUserId !== $uid) {
        $errors[] = 'You can only create plans for yourself.';
    }

    // Admin: validate the chosen user belongs to their branch
    // Admin: validate the chosen user — allow self always, managed staff otherwise
    if ($isAdmin && $planUserId !== $uid) {
        $chk = $db->prepare("
            SELECT id FROM users u
            LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
            WHERE u.id = ?
              AND u.is_active = 1
              AND (u.managed_by = ? OR uda.managed_by = ?)
            LIMIT 1
        ");
        $chk->execute([$planUserId, $uid, $uid]);
        if (!$chk->fetch()) $errors[] = 'You can only create plans for staff you manage.';
    }

    $weekNum   = (int)($_POST['week_number']    ?? 0);
    $weekStart = trim($_POST['week_start_date'] ?? '');
    $weekEnd   = trim($_POST['week_end_date']   ?? '');
    $remarks   = trim($_POST['remarks']         ?? '');
    $entries   = $_POST['entries']              ?? [];

    if (!$weekNum)                          $errors[] = 'Please select a week.';
    if (!$weekStart)                        $errors[] = 'Week start date missing.';
    if (empty($entries) || !is_array($entries)) $errors[] = 'Add at least one plan entry.';

    // Check duplicate plan header — but only store the existing plan id for later
    // We only block if there are genuinely NEW entries (not injections into existing plans)
    $existingPlanId = null;
    if (!$errors) {
        $dup = $db->prepare("
            SELECT id FROM work_plans
            WHERE user_id=? AND plan_month=? AND week_number=? AND department_id=?
        ");
        $dup->execute([$planUserId, $monthStart, $weekNum, $deptId]);
        $existingPlanRow = $dup->fetch();
        if ($existingPlanRow) {
            $existingPlanId = (int)$existingPlanRow['id'];
        }
    }

   if (!$errors) {

        // ── Supervisor-aware duplicate entry check ─────────────────────────
        // Categories:
        //   $newEntries        → no duplicate at all, go into the NEW plan
        //   $injectEntries     → duplicate exists but different supervisor
        //                        → inject into existing plan + update supervisor
        //   $blockedMsgs       → duplicate exists with SAME supervisor → block
        $newEntries    = [];
        $injectEntries = []; // ['entry'=>$e, 'plan_id'=>X]
        $blockedMsgs   = [];

        $ccMap = array_column($companies, 'company_code', 'id'); // needed later too

        foreach ($entries as $e) {
            $cid   = (int)($e['client_id'] ?? 0);
            $pdate = trim($e['plan_date'] ?? '');
            if (!$cid || !$pdate) continue;

            // When creating for self, skip supervisor-duplicate check —
            // just add all entries to the new/existing plan directly
            if ($planUserId === $uid) {
                $newEntries[] = $e;
                continue;
            }

            $dupE = $db->prepare("
                SELECT wp.id        AS plan_id,
                       wp.week_number,
                       wp.supervisor_id,
                       c.company_name
                FROM work_plan_entries wpe
                JOIN work_plans wp ON wp.id = wpe.plan_id
                JOIN companies  c  ON c.id  = wpe.client_id
                WHERE wpe.client_id = ?
                  AND wpe.plan_date = ?
                  AND wp.user_id    = ?
                  AND wp.plan_month = ?
                LIMIT 1
            ");
            $dupE->execute([$cid, $pdate, $planUserId, $monthStart]);
            $found = $dupE->fetch(PDO::FETCH_ASSOC);

            if (!$found) {
                $newEntries[] = $e;
                continue;
            }

            $existingSupervisor = (int)($found['supervisor_id'] ?? 0);
            $label = htmlspecialchars($found['company_name'])
                   . ' on ' . date('d M Y', strtotime($pdate))
                   . ' (Week ' . $found['week_number'] . ')';

            if ($existingSupervisor === $uid) {
                $blockedMsgs[] = $label . ' — already assigned by you, skipped';
            } else {
                $injectEntries[] = [
                    'entry'   => $e,
                    'plan_id' => (int)$found['plan_id'],
                    'label'   => $label,
                ];
            }
        }

        // ── If ALL entries are blocked and nothing else to do → stop ──────
        if (!empty($blockedMsgs) && empty($newEntries) && empty($injectEntries)) {
            $_SESSION['flash'] = [
                'type' => 'danger',
                'msg'  => '<strong><i class="fas fa-exclamation-circle me-1"></i>'
                          . 'Nothing saved — all entries already exist under your supervision:</strong><br>• '
                          . implode('<br>• ', $blockedMsgs),
                'raw'  => true,
            ];
            header('Location: ' . APP_URL . '/admin/planning/plan_list.php?month=' . $month);
            exit;
        }

        // ── Warn about blocked entries if some others will still save ─────
        if (!empty($blockedMsgs)) {
            $_SESSION['flash_extra'] = [
                'type' => 'warning',
                'msg'  => '<strong><i class="fas fa-exclamation-triangle me-1"></i>'
                          . 'Some entries skipped (already exist under your supervision):</strong><br>• '
                          . implode('<br>• ', $blockedMsgs),
                'raw'  => true,
            ];
        }

        $db->beginTransaction();
        try {

            // ── INJECT entries into existing plans (different supervisor) ──
            if (!empty($injectEntries)) {
                $insInject = $db->prepare("
                    INSERT INTO work_plan_entries
                      (plan_id, client_id, client_code, assigned_to,
                       plan_date, day_of_week, planned_time_in, planned_time_out,
                       planned_hours, notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ");

                $injectedPlanIds = [];
                foreach ($injectEntries as $ie) {
                    $e     = $ie['entry'];
                    $pid   = $ie['plan_id'];
                    $cid   = (int)($e['client_id'] ?? 0);
                    $pdate = trim($e['plan_date'] ?? '');
                    $tin   = trim($e['time_in']  ?? '') ?: null;
                    $tout  = trim($e['time_out'] ?? '') ?: null;
                    $hrs   = 0.0;
                    if ($tin && $tout) {
                        $diff = strtotime($tout) - strtotime($tin);
                        if ($diff > 0) $hrs = round($diff / 3600, 2);
                    }
                    $insInject->execute([
                        $pid, $cid, $ccMap[$cid] ?? '',
                        $planUserId, $pdate, date('l', strtotime($pdate)),
                        $tin, $tout, $hrs, trim($e['notes'] ?? ''),
                    ]);
                    $injectedPlanIds[] = $pid;
                }

                // Update supervisor_id on those existing plans to current user
                foreach (array_unique($injectedPlanIds) as $pid) {
                    $db->prepare("
                        UPDATE work_plans
                        SET supervisor_id = ?
                        WHERE id = ?
                    ")->execute([$uid, $pid]);
                }
            }

            // ── Only create a new plan header if there are new entries ─────
            // ── Create new plan header OR reuse existing one ───────────────
            if (!empty($newEntries)) {
                if ($existingPlanId) {
                    // Reuse existing plan for this week — just update supervisor
                    $planId = $existingPlanId;
                    $db->prepare("
                        UPDATE work_plans
                        SET supervisor_id = ?, remarks = COALESCE(NULLIF(?, ''), remarks)
                        WHERE id = ?
                    ")->execute([$uid, $remarks, $planId]);
                } else {
                    // No existing plan → create new header
                    $insP = $db->prepare("
                        INSERT INTO work_plans
                          (user_id, supervisor_id, department_id, branch_id,
                           plan_month, week_number, week_start_date, week_end_date,
                           status, remarks)
                        VALUES (?,?,?,?,?,?,?,?,?,?)
                    ");
                    $insP->execute([
                        $planUserId,
                        $uid,
                        $deptId, $branchId,
                        $monthStart, $weekNum, $weekStart, $weekEnd,
                        'draft', $remarks,
                    ]);
                    $planId = (int)$db->lastInsertId();
                }

                // Insert new entries into the new plan
                $insE = $db->prepare("
                    INSERT INTO work_plan_entries
                    (plan_id, client_id, client_code, assigned_to,
                    plan_date, day_of_week, planned_time_in, planned_time_out,
                    planned_hours, notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ");

                foreach ($newEntries as $e) {
                    $cid   = (int)($e['client_id'] ?? 0);
                    $pdate = trim($e['plan_date'] ?? '');
                    if (!$cid || !$pdate) continue;

                    $tin  = trim($e['time_in']  ?? '') ?: null;
                    $tout = trim($e['time_out'] ?? '') ?: null;
                    $hrs  = 0.0;
                    if ($tin && $tout) {
                        $diff = strtotime($tout) - strtotime($tin);
                        if ($diff > 0) $hrs = round($diff / 3600, 2);
                    }

                    $insE->execute([
                        $planId, $cid, $ccMap[$cid] ?? '',
                        $planUserId, $pdate, date('l', strtotime($pdate)),
                        $tin, $tout, $hrs, trim($e['notes'] ?? ''),
                    ]);
                }

            } // ── end if (!empty($newEntries)) ──────────────────────────
            // REPLACE WITH:
            $planId = $planId ?? 0;
            
            // ── Auto-submit when creating for self ──────────────────────────
            if ($planUserId === $uid) {
                $db->prepare("UPDATE work_plans SET status='submitted', updated_at=NOW() WHERE id=?")
                   ->execute([$planId]);
            }

            $db->commit();
            logActivity('Created plan #' . $planId . ' Week ' . $weekNum, 'consulting');

            // Notify approvers
            $context = ($planUserId === $uid) ? 'created_for_self' : 'created_for_staff';
            notifyPlanApprovers(
                $db, $planId, $planUserId, $uid,
                $user['full_name'] ?? ('User #' . $uid),
                $weekNum, $monthLabel, $month, $context
            );

            setFlash('success', 'Work plan created successfully!');
            header('Location: ' . APP_URL . '/admin/planning/plan_list.php?month=' . $month);
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to save: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Create Work Plan';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
.entry-row { border-bottom:1px solid var(--cn4);padding:14px 18px; }
.entry-row:last-child { border-bottom:none; }
.required-star { color:#ef4444; }
.hrs-pill { background:var(--cn3);border-radius:6px;padding:5px 10px;font-size:.77rem;color:var(--muted); }
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php' ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <?= flashHtml() ?>

            <!-- Top bar -->
            <div class="page-hero mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="page-hero-badge"><i class="fas fa-briefcase"></i> Consulting</div>
            <h4>Create Work Plan</h4>
            <p>
                <?= htmlspecialchars($user['full_name']) ?> · <?= $monthLabel ?>
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap align-items-center">
            <input type="month" class="form-control form-control-sm" style="width:150px;"
                   value="<?= htmlspecialchars($month) ?>" onchange="location='?month='+this.value">

            <a href="<?= APP_URL ?>/admin/planning/plan_list.php?month=<?= $month ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-list me-1"></i> Plans
            </a>

            <a href="../index.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-chart-pie me-1"></i> Dashboard
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

                <div style="display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start;">

                    <!-- ── LEFT: main form ── -->
                    <div>

                        <!-- Plan header card -->
                        <div class="cn-panel" style="margin-bottom:16px;">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-calendar-week me-2" style="color:var(--gold)"></i>Plan Details
                                </span>
                            </div>
                            <div style="padding:16px 18px;">
                                <div class="cn-form-row">

                                    <!-- Admin: assign to dropdown -->
                                    <?php if ($isAdmin): ?>
                                    <div class="cn-form-group">
                                        <label class="cn-label">Assign Plan To <span class="required-star">*</span></label>
                                        <select name="assigned_user_id" id="assignedUser" class="cn-input">
                                            <option value="<?= $uid ?>"
                                                data-empid="<?= htmlspecialchars($user['employee_id'] ?? '') ?>">
                                                <?= htmlspecialchars($user['full_name']) ?>
                                                <?= !empty($user['employee_id']) ? ' · ' . htmlspecialchars($user['employee_id']) : '' ?>
                                                (Me)
                                            </option>
                                            <?php foreach ($deptStaff as $s): ?>
                                                <option value="<?= $s['id'] ?>"
                                                    data-empid="<?= htmlspecialchars($s['employee_id'] ?? '') ?>"
                                                    <?= ($postData['assigned_user_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($s['full_name']) ?>
                                                    <?= $s['employee_id'] ? '(' . $s['employee_id'] . ')' : '' ?>
                                                </option>
                                                <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Month -->
                                    <div class="cn-form-group">
                                        <label class="cn-label">Month</label>
                                        <input type="month" class="cn-input" value="<?= $month ?>"
                                               onchange="location='?month='+this.value" style="cursor:pointer;">
                                    </div>

                                    <!-- Week -->
                                    <div class="cn-form-group">
                                        <label class="cn-label">Week <span class="required-star">*</span></label>
                                        <select name="week_number" id="weekSelect" class="cn-input" required
                                                onchange="onWeekChange(this)">
                                            <option value="">— Select Week —</option>
                                            <?php foreach ($weeks as $w): ?>
                                            <option value="<?= $w['week_number'] ?>"
                                                    data-start="<?= $w['week_start_date'] ?>"
                                                    data-end="<?= $w['week_end_date'] ?>"
                                                    <?= ($postData['week_number'] ?? '') == $w['week_number'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($w['label']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="week_start_date" id="weekStart"
                                               value="<?= htmlspecialchars($postData['week_start_date'] ?? '') ?>">
                                        <input type="hidden" name="week_end_date" id="weekEnd"
                                               value="<?= htmlspecialchars($postData['week_end_date'] ?? '') ?>">
                                    </div>

                                </div>

                                <div class="cn-form-group">
                                    <label class="cn-label">Remarks / Notes</label>
                                    <textarea name="remarks" class="cn-input" rows="2"
                                              placeholder="Any notes for this week's plan…"><?= htmlspecialchars($postData['remarks'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Entries card -->
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

                    <!-- ── RIGHT: summary + actions ── -->
                    <div>

                        <!-- Summary -->
                        <div class="cn-panel" style="margin-bottom:14px;">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-chart-pie me-2" style="color:var(--gold)"></i>Summary
                                </span>
                            </div>
                            <div style="padding:14px 16px;">
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
                                    <div style="text-align:center;background:var(--cn3);border-radius:8px;padding:12px 6px;">
                                        <div style="font-size:1.5rem;font-weight:800;color:var(--blue);" id="totHrs">0.0h</div>
                                        <div style="font-size:.7rem;color:var(--muted);margin-top:2px;">Total Hours</div>
                                    </div>
                                    <div style="text-align:center;background:var(--cn3);border-radius:8px;padding:12px 6px;">
                                        <div style="font-size:1.5rem;font-weight:800;color:var(--gold);" id="entCnt">0</div>
                                        <div style="font-size:.7rem;color:var(--muted);margin-top:2px;">Entries</div>
                                    </div>
                                </div>
                                <div id="wkInfo" style="background:rgba(16,185,129,.1);border-radius:7px;
                                     padding:9px 12px;font-size:.78rem;color:#10b981;font-weight:600;">
                                    <i class="fas fa-calendar me-1"></i>Select a week above
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="cn-panel" style="margin-bottom:14px;">
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

                        <!-- Existing plans this month -->
                        <div class="cn-panel">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-list me-2" style="color:var(--gold)"></i>This Month
                                </span>
                            </div>
                            <div id="existingPlans" style="padding:10px 14px;font-size:.78rem;color:var(--muted);">
                                <i class="fas fa-spinner fa-spin"></i> Loading…
                            </div>
                        </div>

                    </div>
                </div><!-- /grid -->

            </form>

        </div>
    </div>
</div>

<!-- Entry template (hidden) -->
<template id="entryTemplate">
<div class="entry-row" data-index="__IDX__">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <span style="font-size:.82rem;font-weight:600;color:var(--gold);">
            <i class="fas fa-building me-1"></i>Visit #<span class="entry-num">1</span>
        </span>
        <button type="button" onclick="removeEntry(this)"
                class="cn-btn cn-btn-danger cn-btn-sm" style="padding:3px 9px;">
            <i class="fas fa-trash"></i>
        </button>
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:10px;margin-bottom:8px;">
        <div>
            <label class="cn-label">Client <span class="required-star">*</span></label>
            <select name="entries[__IDX__][client_id]" class="cn-input entry-client" required>
                <option value="">— Select Client —</option>
                <?php foreach ($companies as $c): ?>
                    <option value="<?= $c['id'] ?>"
                        data-code="<?= htmlspecialchars($c['company_code'] ?? '') ?>"
                        data-pan="<?= htmlspecialchars($c['pan_number'] ?? '') ?>">
                        <?= htmlspecialchars($c['company_name']) ?>
                        <?= $c['company_code'] ? ' — ' . htmlspecialchars($c['company_code']) : '' ?>
                        <?= $c['pan_number']   ? ' — ' . htmlspecialchars($c['pan_number'])   : '' ?>
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
    <div style="display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end;">
        <div>
            <label class="cn-label">Notes</label>
            <input type="text" name="entries[__IDX__][notes]" class="cn-input"
                   placeholder="Purpose of visit…">
        </div>
        <div class="hrs-pill">
            <i class="fas fa-clock me-1" style="color:var(--gold);"></i>
            <span class="planned-hrs">0.00h</span>
        </div>
    </div>
</div>
</template>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
let entIdx = 0;
const wsEl = document.getElementById('weekStart');
const weEl = document.getElementById('weekEnd');

// TomSelect on staff dropdown (admin only)
const assignedUserEl = document.getElementById('assignedUser');
if (assignedUserEl) {
    new TomSelect(assignedUserEl, {
        placeholder: 'Search staff by name or ID…',
        searchField: ['text'],   // searches the full option text which includes employee ID
        maxOptions: 200,
        render: {
            option: function(data, escape) {
                // Split name and employee ID from option text (format: "Full Name · STF-001")
                const parts = data.text.split(' · ');
                const name  = escape(parts[0].trim());
                const empId = parts[1] ? `<span style="color:var(--muted);font-size:.75rem;margin-left:6px;">${escape(parts[1].trim())}</span>` : '';
                return `<div>${name}${empId}</div>`;
            },
            item: function(data, escape) {
                const parts = data.text.split(' · ');
                const name  = escape(parts[0].trim());
                const empId = parts[1] ? ` <span style="color:var(--muted);font-size:.75rem;">(${escape(parts[1].trim())})</span>` : '';
                return `<div>${name}${empId}</div>`;
            }
        },
        onChange: function(value) {
            // Re-trigger the existing fetch for existing plans panel
            fetch(`<?= APP_URL ?>/ajax/plan_ajax.php?month=<?= $month ?>&user_id=` + value)
                .then(r => r.json())
                .then(data => {
                    const el = document.getElementById('existingPlans');
                    if (!data.length) { el.innerHTML = '<span style="color:var(--muted)">No plans yet this month.</span>'; return; }
                    el.innerHTML = data.map(p => `
                        <div style="display:flex;align-items:center;justify-content:space-between;
                                    padding:5px 0;border-bottom:1px solid var(--cn4);">
                            <span style="font-weight:600;color:var(--gold);">Wk ${p.week_number}</span>
                            <span style="font-size:.72rem;color:var(--muted);">${p.entry_count} entries</span>
                            <a href="../admin/plan_view.php?id=${p.id}"
                               style="font-size:.72rem;color:var(--blue);">View</a>
                        </div>
                    `).join('');
                });
        }
    });
}
// ── Week change ───────────────────────────────────────────────
function onWeekChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const ws  = opt.dataset.start || '';
    const we  = opt.dataset.end   || '';
    wsEl.value = ws;
    weEl.value = we;
    document.querySelectorAll('.entry-date').forEach(d => {
        d.min = ws; d.max = we;
    });
    document.getElementById('wkInfo').innerHTML =
        ws ? '<i class="fas fa-calendar me-1"></i>' + fmtDate(ws) + ' – ' + fmtDate(we) : 'Select a week above';
}

function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d + 'T00:00:00');
    return dt.toLocaleDateString('en-GB', {day:'2-digit',month:'short'});
}

// ── Add entry ─────────────────────────────────────────────────
function addEntry() {
    const tpl = document.getElementById('entryTemplate').innerHTML;
    const html = tpl.replaceAll('__IDX__', entIdx);
    const wrap = document.createElement('div');
    wrap.innerHTML = html;
    const row = wrap.firstElementChild;

    // Date constraints
    if (wsEl.value) row.querySelector('.entry-date').min = wsEl.value;
    if (weEl.value) row.querySelector('.entry-date').max = weEl.value;

    // TomSelect on client dropdown
   new TomSelect(row.querySelector('.entry-client'), {
    placeholder: 'Search by name, code or PAN…',
    maxOptions: 500,
    searchField: ['text'],
    score: function(search) {
        const s = search.toLowerCase();
        return function(item) {
            const text  = (item.text  || '').toLowerCase();
            const code  = (item.$option?.dataset?.code || '').toLowerCase();
            const pan   = (item.$option?.dataset?.pan  || '').toLowerCase();
            if (text.includes(s) || code.includes(s) || pan.includes(s)) return 1;
            return 0;
        };
    },
    render: {
        option: function(data, escape) {
            const code = data.$option?.dataset?.code || '';
            const pan  = data.$option?.dataset?.pan  || '';
            return `<div style="padding:4px 2px;">
                <div style="font-weight:600;font-size:.83rem;">${escape(data.text.split(' — ')[0])}</div>
                <div style="font-size:.7rem;color:#9ca3af;display:flex;gap:10px;margin-top:1px;">
                    ${code ? `<span><i class="fas fa-tag" style="font-size:.6rem;"></i> ${escape(code)}</span>` : ''}
                    ${pan  ? `<span><i class="fas fa-id-card" style="font-size:.6rem;"></i> PAN: ${escape(pan)}</span>`  : ''}
                </div>
            </div>`;
        },
        item: function(data, escape) {
            const pan = data.$option?.dataset?.pan || '';
            const name = escape(data.text.split(' — ')[0]);
            return pan
                ? `<div>${name} <span style="font-size:.72rem;color:#9ca3af;">(PAN: ${escape(pan)})</span></div>`
                : `<div>${name}</div>`;
        }
    }
});

    // Time calc
    row.querySelectorAll('.time-in,.time-out').forEach(t =>
        t.addEventListener('change', () => calcHours(row))
    );

    document.getElementById('entriesContainer').appendChild(row);
    document.getElementById('emptyEntries').style.display = 'none';
    entIdx++;
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
    document.querySelectorAll('.entry-num').forEach((el, i) => { el.textContent = i + 1; });
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
        const t = parseFloat(row.querySelector('.planned-hrs').textContent) || 0;
        total += t;
    });
    document.getElementById('totHrs').textContent = total.toFixed(1) + 'h';
    document.getElementById('entCnt').textContent = cnt;
}

// ── Load existing plans this month ────────────────────────────
(function loadExisting() {
    const userId = <?php echo $isAdmin ? "document.getElementById('assignedUser')?.value || {$uid}" : $uid; ?>;
    fetch('<?= APP_URL ?>/consulting/admin/plan_ajax.php?month=<?= $month ?>&user_id=' + userId)
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('existingPlans');
            if (!data.length) { el.innerHTML = '<span style="color:var(--muted)">No plans yet this month.</span>'; return; }
            el.innerHTML = data.map(p => `
                <div style="display:flex;align-items:center;justify-content:space-between;
                            padding:5px 0;border-bottom:1px solid var(--cn4);">
                    <span style="font-weight:600;color:var(--gold);">Wk ${p.week_number}</span>
                    <span style="font-size:.72rem;color:var(--muted);">${p.entry_count} entries</span>
                    <a href="../admin/plan_view.php?id=${p.id}"
                       style="font-size:.72rem;color:var(--blue);">View</a>
                </div>
            `).join('');
        })
        .catch(() => {
            document.getElementById('existingPlans').innerHTML = '<span style="color:var(--muted)">—</span>';
        });
})();

<?php if ($isAdmin): ?>
document.getElementById('assignedUser')?.addEventListener('change', function() {
    const userId = this.value;
    fetch('<?= APP_URL ?>/consulting/admin/plan_ajax.php?month=<?= $month ?>&user_id=' + userId)
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('existingPlans');
            if (!data.length) { el.innerHTML = '<span style="color:var(--muted)">No plans yet this month.</span>'; return; }
            el.innerHTML = data.map(p => `
                <div style="display:flex;align-items:center;justify-content:space-between;
                            padding:5px 0;border-bottom:1px solid var(--cn4);">
                    <span style="font-weight:600;color:var(--gold);">Wk ${p.week_number}</span>
                    <span style="font-size:.72rem;color:var(--muted);">${p.entry_count} entries</span>
                    <a href="../admin/plan_view.php?id=${p.id}"
                       style="font-size:.72rem;color:var(--blue);">View</a>
                </div>
            `).join('');
        });
});
<?php endif; ?>

// Trigger week restore on page load (POST error case)
const ws = document.getElementById('weekSelect');
if (ws && ws.value) onWeekChange(ws);
document.getElementById('planForm').addEventListener('submit', function () {
    const btn = document.getElementById('savePlanBtn');
    btn.disabled = true;
    btn.style.opacity = '0.7';
    document.getElementById('savePlanBtnIcon').style.display = 'none';
    document.getElementById('savePlanBtnLoading').style.display = 'inline-flex';
});
</script>
<?php include '../../includes/footer.php'; ?>