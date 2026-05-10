<?php
/**
 * consulting/admin/plan_edit.php — Admin: Edit Existing Work Plan
 * Admin can edit any plan (own or staff's) unless it is approved/rejected.
 */
require_once '../../config/db.php';
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/helpers.php';
requireAdmin();

$db          = getDB();
$user        = currentUser();
$uid         = (int)$user['id'];
$currentRole = $_SESSION['role'] ?? ($user['role'] ?? '');
$isAdmin     = in_array($currentRole, ['admin', 'executive']);

// ── Load plan ──────────────────────────────────────────────────
$planId = (int)($_GET['id'] ?? 0);
if (!$planId) { setFlash('error', 'Invalid plan.'); header('Location: plan_list.php'); exit; }

$planStmt = $db->prepare("
    SELECT wp.*, u.full_name AS planner_name, u.employee_id AS planner_empid,
           u.branch_id AS planner_branch_id
    FROM work_plans wp
    JOIN users u ON u.id = wp.user_id
    WHERE wp.id = ?
");
$planStmt->execute([$planId]);
$plan = $planStmt->fetch(PDO::FETCH_ASSOC);
if (!$plan) { setFlash('error', 'Plan not found.'); header('Location: plan_list.php'); exit; }
// ── Can the logged-in user edit this plan? ─────────────────────
// ── Can the logged-in user edit this plan? ─────────────────────
// managed_by = full edit; supervisor_id on entries = view only
$canEditStmt = $db->prepare("
    SELECT 1 FROM users
    WHERE id = ? AND managed_by = ?
    UNION
    SELECT 1 FROM user_department_assignments
    WHERE user_id = ? AND managed_by = ?
    UNION
    SELECT 1 FROM work_plans
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$canEditStmt->execute([$plan['user_id'], $uid, $plan['user_id'], $uid, $planId, $uid]);
$canEdit = ($plan['user_id'] === $uid) || (bool)$canEditStmt->fetch();

// ── Is login user a supervisor on any entry of this plan? ──────
$isSupervisorStmt = $db->prepare("
    SELECT 1 FROM work_plan_entries
    WHERE plan_id = ? AND supervisor_id = ?
    LIMIT 1
");
$isSupervisorStmt->execute([$planId, $uid]);
$isSupervisor = (bool)$isSupervisorStmt->fetch();

// ── UDA consulting dept detection ─────────────────────────────
$deptId = (int)$user['department_id'];
$branchId = (int)$user['branch_id'];

$__deptMetaQ = $db->prepare("SELECT dept_code, dept_name FROM departments WHERE id = ?");
$__deptMetaQ->execute([$user['department_id']]);
$__deptMeta      = $__deptMetaQ->fetch(PDO::FETCH_ASSOC);
$__primaryCode   = $__deptMeta['dept_code'] ?? '';
$__isConsPrimary = ($__primaryCode === 'CON' || stripos($__deptMeta['dept_name'] ?? '', 'consult') !== false);
$__isCoreAdmin   = ($__primaryCode === 'CORE');

$__udaQ = $db->prepare("
    SELECT d.id, d.dept_code FROM user_department_assignments uda
    JOIN departments d ON d.id = uda.department_id
    WHERE uda.user_id = ? AND (d.dept_code = 'CON' OR d.dept_name LIKE '%consult%')
    LIMIT 1
");
$__udaQ->execute([$uid]);
$__udaCons = $__udaQ->fetch(PDO::FETCH_ASSOC);

if ($__isConsPrimary) {
    $deptId = (int)$user['department_id'];
} elseif ($__isCoreAdmin && $__udaCons) {
    $deptId = (int)$__udaCons['id'];
} elseif ($__udaCons) {
    $deptId = (int)$__udaCons['id'];
}

// ── Month / Week context ───────────────────────────────────────
$now        = new DateTime();
$month      = substr($plan['plan_month'], 0, 7); // 'Y-m'
$monthDate  = DateTime::createFromFormat('Y-m', $month) ?: $now;
$monthStart = $monthDate->format('Y-m-01');
$monthLabel = $monthDate->format('F Y');

// Build week blocks for the plan month
$weeks = [];
$first = (clone $monthDate)->modify('first day of this month');
$last  = (clone $monthDate)->modify('last day of this month');
$cur   = clone $first;
$wn    = 1;
while ($cur <= $last && $wn <= 5) {
    $ws       = clone $cur;
    $dow      = (int)$cur->format('w');
    $daysToSat = (6 - $dow + 7) % 7 ?: 6;
    $we       = (clone $cur)->modify("+{$daysToSat} days");
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

// ── Existing entries ───────────────────────────────────────────
$existingEntries = $db->prepare("
    SELECT wpe.*, c.company_name, c.company_code, c.pan_number,
           sv.full_name AS supervisor_name
    FROM work_plan_entries wpe
    LEFT JOIN companies c ON c.id = wpe.client_id
    LEFT JOIN users sv ON sv.id = wpe.supervisor_id
    WHERE wpe.plan_id = ?
    ORDER BY wpe.plan_date ASC, wpe.id ASC
");
$existingEntries->execute([$planId]);
$existingEntries = $existingEntries->fetchAll(PDO::FETCH_ASSOC);

// Show the plan owner's managed_by user who is in CON dept
$supervisorName = '—';
$svNameStmt = $db->prepare("
    SELECT u2.full_name
    FROM users u1
    JOIN users u2 ON u2.id = u1.managed_by
    WHERE u1.id = ?
      AND (
          EXISTS (
              SELECT 1 FROM departments d
              WHERE d.id = u2.department_id
          )
          OR EXISTS (
              SELECT 1 FROM user_department_assignments uda
              JOIN departments d ON d.id = uda.department_id
              WHERE uda.user_id = u2.id
          )
      )
    LIMIT 1
");
$svNameStmt->execute([$plan['user_id']]);
$supervisorName = $svNameStmt->fetchColumn() ?: '—';

// ── Companies ──────────────────────────────────────────────────
$companies = $db->query("
    SELECT id, company_name, company_code, pan_number FROM companies
    WHERE is_active = 1 ORDER BY company_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Staff list for admin ───────────────────────────────────────
$deptStaff = [];
if ($isAdmin) {
    $st1 = $db->prepare("
        SELECT DISTINCT u.id, u.full_name, u.employee_id
        FROM users u
        LEFT JOIN user_department_assignments uda ON uda.user_id = u.id
        WHERE u.is_active = 1
          AND u.id != ?
          AND (
              u.managed_by = ?
              OR uda.managed_by = ?
          )
        ORDER BY u.full_name
    ");
    $st1->execute([$uid, $uid, $uid]);
    $deptStaff = $st1->fetchAll(PDO::FETCH_ASSOC);
}

// ── Supervisor list (all admins/executives the login user manages or peers) ──
$supervisorList = $db->prepare("
    SELECT DISTINCT u.id, u.full_name, u.employee_id
    FROM users u
    WHERE u.is_active = 1
      AND u.role_id IN (SELECT id FROM roles WHERE role_name IN ('admin','executive'))
    ORDER BY u.full_name
");
$supervisorList->execute();
$supervisorList = $supervisorList->fetchAll(PDO::FETCH_ASSOC);

// ── POST ───────────────────────────────────────────────────────
$errors  = [];
$postData = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (!$canEdit) {
        // Non-full-editor: can only update their own supervised entries + add new entries
        $db->beginTransaction();
        try {
            // Update existing entries where supervisor_id = login user
            $svEntries = $_POST['sv_entries'] ?? [];
            if (!empty($svEntries)) {
                $svUpd = $db->prepare("
                    UPDATE work_plan_entries
                    SET plan_date=?, day_of_week=?, planned_time_in=?,
                        planned_time_out=?, planned_hours=?, notes=?
                    WHERE id=? AND plan_id=? AND supervisor_id=?
                ");
                foreach ($svEntries as $entryId => $ev) {
                    $entryId = (int)$entryId;
                    $pdate   = trim($ev['plan_date'] ?? '') ?: null;
                    $tin     = trim($ev['time_in']   ?? '') ?: null;
                    $tout    = trim($ev['time_out']  ?? '') ?: null;
                    $hrs     = 0.0;
                    if ($tin && $tout) {
                        $diff = strtotime($tout) - strtotime($tin);
                        if ($diff > 0) $hrs = round($diff / 3600, 2);
                    }
                    $svUpd->execute([
                        $pdate, $pdate ? date('l', strtotime($pdate)) : null,
                        $tin, $tout, $hrs,
                        trim($ev['notes'] ?? ''), $entryId, $planId, $uid
                    ]);
                }
            }

            // Insert new entries — supervisor defaults to login user if not chosen
            $newEntries = $_POST['entries'] ?? [];
            if (!empty($newEntries)) {
                $ccMap = array_column($companies, 'company_code', 'id');
                $insE  = $db->prepare("
                    INSERT INTO work_plan_entries
                      (plan_id, client_id, client_code, assigned_to, supervisor_id,
                       plan_date, day_of_week, planned_time_in, planned_time_out,
                       planned_hours, notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ");
                foreach ($newEntries as $e) {
                    $cid   = (int)($e['client_id'] ?? 0);
                    $pdate = trim($e['plan_date']  ?? '');
                    if (!$cid || !$pdate) continue;
                    $tin   = trim($e['time_in']  ?? '') ?: null;
                    $tout  = trim($e['time_out'] ?? '') ?: null;
                    $supId = ($e['supervisor_id'] ?? '') !== '' ? (int)$e['supervisor_id'] : $uid;
                    $hrs   = 0.0;
                    if ($tin && $tout) {
                        $diff = strtotime($tout) - strtotime($tin);
                        if ($diff > 0) $hrs = round($diff / 3600, 2);
                    }
                    $insE->execute([
                        $planId, $cid, $ccMap[$cid] ?? '',
                        $plan['user_id'], $supId,
                        $pdate, date('l', strtotime($pdate)),
                        $tin, $tout, $hrs, trim($e['notes'] ?? ''),
                    ]);
                }
            }

            $db->commit();
            logActivity('Added/updated entries on plan #' . $planId, 'consulting');
            setFlash('success', 'Entries saved successfully.');
            header('Location: plan_edit.php?id=' . $planId);
            exit;

        } catch (Exception $ex) {
            $db->rollBack();
            $errors[] = 'Failed to save: ' . $ex->getMessage();
        }
    }

    // ── Full edit (canEdit) ────────────────────────────────────
    $planUserId = $isAdmin ? (int)($_POST['assigned_user_id'] ?? $plan['user_id']) : $plan['user_id'];
    $weekNum    = (int)($_POST['week_number']    ?? 0);
    $weekStart  = trim($_POST['week_start_date'] ?? '');
    $weekEnd    = trim($_POST['week_end_date']   ?? '');
    $remarks    = trim($_POST['remarks']         ?? '');
    $entries    = $_POST['entries']              ?? [];

    if (!$weekNum)   $errors[] = 'Please select a week.';
    if (!$weekStart) $errors[] = 'Week start date missing.';
    if (empty($entries) || !is_array($entries)) $errors[] = 'Add at least one plan entry.';

    if (!$errors) {
        $dup = $db->prepare("
            SELECT id FROM work_plans
            WHERE user_id=? AND plan_month=? AND week_number=? AND department_id=? AND id!=?
        ");
        $dup->execute([$planUserId, $monthStart, $weekNum, $plan['department_id'], $planId]);
        if ($dup->fetch()) $errors[] = 'Another plan already exists for this staff for Week ' . $weekNum . '.';
    }

    if (!$errors) {
        $db->beginTransaction();
        try {
            $newStatus = ($plan['status'] === 'approved') ? 'approved' : 'draft';
            $db->prepare("
                UPDATE work_plans SET user_id=?, week_number=?, week_start_date=?,
                    week_end_date=?, remarks=?, status=?, updated_at=NOW()
                WHERE id=?
            ")->execute([$planUserId, $weekNum, $weekStart, $weekEnd, $remarks, $newStatus, $planId]);

            $db->prepare("DELETE FROM work_plan_entries WHERE plan_id=?")->execute([$planId]);
            $ccMap = array_column($companies, 'company_code', 'id');
            $insE  = $db->prepare("
                INSERT INTO work_plan_entries
                  (plan_id, client_id, client_code, assigned_to, supervisor_id,
                   plan_date, day_of_week, planned_time_in, planned_time_out, planned_hours, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ");
            foreach ($entries as $e) {
                $cid   = (int)($e['client_id'] ?? 0);
                $pdate = trim($e['plan_date'] ?? '');
                if (!$cid || !$pdate) continue;
                $tin   = trim($e['time_in']  ?? '') ?: null;
                $tout  = trim($e['time_out'] ?? '') ?: null;
                $supId = ($e['supervisor_id'] ?? '') !== '' ? (int)$e['supervisor_id'] : null;
                $hrs   = 0.0;
                if ($tin && $tout) {
                    $diff = strtotime($tout) - strtotime($tin);
                    if ($diff > 0) $hrs = round($diff / 3600, 2);
                }
                $insE->execute([
                    $planId, $cid, $ccMap[$cid] ?? '',
                    $planUserId, $supId, $pdate, date('l', strtotime($pdate)),
                    $tin, $tout, $hrs, trim($e['notes'] ?? ''),
                ]);
            }

            if ($planUserId !== $uid) {
                try {
                    $db->prepare("
                        INSERT INTO notifications (user_id,type,title,message,link,is_read,created_at)
                        VALUES (?,'task','Work Plan Updated',?,?,0,NOW())
                    ")->execute([
                        $planUserId,
                        $user['full_name'] . ' updated your work plan — Week ' . $weekNum . ', ' . $monthLabel,
                        APP_URL . '/staff/planning/plan_view.php?id=' . $planId,
                    ]);
                } catch (Exception $ne) {}
            }

            logActivity('Edited plan #' . $planId . ' Week ' . $weekNum, 'consulting');
            $db->commit();
            setFlash('success', 'Work plan updated successfully!');
            header('Location: ' . APP_URL . '/admin/planning/plan_list.php?month=' . $month);
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to save: ' . $e->getMessage();
        }
    }
}

// ── Status badge helper ────────────────────────────────────────
$statusColors = [
    'draft'     => ['bg' => '#f3f4f6', 'color' => '#6b7280', 'icon' => 'fa-file-alt'],
    'submitted' => ['bg' => '#eff6ff', 'color' => '#3b82f6', 'icon' => 'fa-paper-plane'],
    'approved'  => ['bg' => '#ecfdf5', 'color' => '#10b981', 'icon' => 'fa-check-circle'],
    'rejected'  => ['bg' => '#fef2f2', 'color' => '#ef4444', 'icon' => 'fa-times-circle'],
];
$sc = $statusColors[$plan['status']] ?? $statusColors['draft'];

$deptStmt = $db->prepare("SELECT dept_name FROM departments WHERE id = ?");
$deptStmt->execute([$plan['department_id']]);
$deptName = $deptStmt->fetchColumn() ?: 'Consulting';

$pageTitle = 'Edit Work Plan';
include '../../includes/header.php';
?>
<link rel="stylesheet" href="consulting.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/datatables.custom.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css">
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
.entry-row         { border-bottom:1px solid var(--cn4); padding:14px 18px; }
.entry-row:last-child { border-bottom:none; }
.required-star     { color:#ef4444; }
.hrs-pill          { background:var(--cn3);border-radius:6px;padding:5px 10px;font-size:.77rem;color:var(--muted); }
.edit-warning      { background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;
                     font-size:.8rem;color:#92400e;display:flex;align-items:center;gap:10px;margin-bottom:16px; }
.status-chip       { display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;
                     font-size:.75rem;font-weight:700; }
</style>

<div class="app-wrapper">
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content">
        <?php include '../../includes/topbar.php'; ?>
        <div class="cn-wrap">

            <?= flashHtml() ?>

            <!-- Page hero -->
            <div class="page-hero mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="page-hero-badge"><i class="fas fa-pencil-alt"></i> Consulting · Edit</div>
                        <h4>Edit Work Plan
                            <span class="status-chip ms-2"
                                style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;">
                                <i class="fas <?= $sc['icon'] ?>"></i>
                                <?= ucfirst($plan['status']) ?>
                            </span>
                        </h4>
                        <p><?= htmlspecialchars($plan['planner_name']) ?>
                           <?= $plan['planner_empid'] ? '· ' . htmlspecialchars($plan['planner_empid']) : '' ?>
                           · Week <?= $plan['week_number'] ?> · <?= $monthLabel ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <a href="plan_view.php?id=<?= $planId ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-eye me-1"></i> View
                        </a>
                        <a href="plan_list.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-list me-1"></i> All Plans
                        </a>
                        <a href="index.php?month=<?= $month ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-home me-1"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- Status warning -->
            <?php if ($plan['status'] === 'submitted'): ?>
            <div class="edit-warning">
                <i class="fas fa-exclamation-triangle fa-lg" style="color:#f59e0b;flex-shrink:0;"></i>
                <div>This plan has been <strong>submitted for approval</strong>. Editing it will reset it back to <strong>Draft</strong> status and require re-submission.</div>
            </div>
            <?php elseif ($plan['status'] === 'rejected'): ?>
            <div class="edit-warning" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b;">
                <i class="fas fa-times-circle fa-lg" style="color:#ef4444;flex-shrink:0;"></i>
                <div>This plan was <strong>rejected</strong>. Make the necessary changes and re-submit for approval.</div>
            </div>
            <?php endif; ?>

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

            <form method="POST" id="planForm" <?= (!$canEdit && !$isSupervisor) ? 'onsubmit="return false;"' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div style="display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start;">

                    <!-- ── LEFT ── -->
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

                                    <?php if ($isAdmin): ?>
                                    <div class="cn-form-group">
                                        <label class="cn-label">Assigned To <span class="required-star">*</span></label>
                                        <select name="assigned_user_id" id="assignedUser" class="cn-input">
                                            <option value="<?= $uid ?>"
                                                <?= $plan['user_id'] == $uid ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($user['full_name']) ?>
                                                <?= !empty($user['employee_id']) ? ' · ' . htmlspecialchars($user['employee_id']) : '' ?>
                                                (Me)
                                            </option>
                                            <?php foreach ($deptStaff as $s): ?>
                                            <option value="<?= $s['id'] ?>"
                                                <?= $plan['user_id'] == $s['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($s['full_name']) ?>
                                                <?= $s['employee_id'] ? ' · ' . htmlspecialchars($s['employee_id']) : '' ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php else: ?>
                                    <input type="hidden" name="assigned_user_id" value="<?= $plan['user_id'] ?>">
                                    <?php endif; ?>

                                    <!-- Month (readonly display) -->
                                    <div class="cn-form-group">
                                        <label class="cn-label">Month</label>
                                        <input type="text" class="cn-input" value="<?= $monthLabel ?>" readonly
                                               style="background:var(--cn3);cursor:not-allowed;color:var(--muted);">
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
                                                    <?= ($postData['week_number'] ?? $plan['week_number']) == $w['week_number'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($w['label']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="week_start_date" id="weekStart"
                                               value="<?= htmlspecialchars($postData['week_start_date'] ?? $plan['week_start_date']) ?>">
                                        <input type="hidden" name="week_end_date" id="weekEnd"
                                               value="<?= htmlspecialchars($postData['week_end_date'] ?? $plan['week_end_date']) ?>">
                                    </div>

                                </div>

                                <div class="cn-form-group">
                                    <label class="cn-label">Remarks / Notes</label>
                                    <textarea name="remarks" class="cn-input" rows="2"
                                              placeholder="Any notes for this week's plan…"><?= htmlspecialchars($postData['remarks'] ?? $plan['remarks'] ?? '') ?></textarea>
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

                            <div id="emptyEntries" class="text-center text-muted p-4"
                                 style="display:<?= empty($existingEntries) && empty($postData['entries']) ? '' : 'none' ?>;">
                                <i class="fas fa-calendar-plus fa-2x mb-2 opacity-25"></i><br>
                                Click "Add Entry" to start planning
                            </div>
                        </div>

                    </div>

                    <!-- ── RIGHT ── -->
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
                                <div id="wkInfo"
                                     style="background:rgba(16,185,129,.1);border-radius:7px;padding:9px 12px;font-size:.78rem;color:#10b981;font-weight:600;">
                                    <i class="fas fa-calendar me-1"></i>Select a week above
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="cn-panel" style="margin-bottom:14px;">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-save me-2" style="color:var(--gold)"></i><?= $canEdit ? 'Save Changes' : 'View Only' ?>
                                </span>
                            </div>
                            <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px;">
                                <?php if ($canEdit): ?>
                                <button type="submit" class="cn-btn cn-btn-gold" style="justify-content:center;">
                                    <i class="fas fa-save"></i> Update Plan
                                </button>
                                <?php else: ?>
                                <button type="submit" class="cn-btn cn-btn-gold" style="justify-content:center;">
                                    <i class="fas fa-save"></i> Save My Entries
                                </button>
                                <?php endif; ?>
                                <a href="plan_list.php?month=<?= $month ?>"
                                class="cn-btn cn-btn-out" style="justify-content:center;">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </div>

                        <!-- Plan info -->
                        <div class="cn-panel">
                            <div class="cn-panel-hd">
                                <span class="cn-panel-title">
                                    <i class="fas fa-info-circle me-2" style="color:var(--gold)"></i>Plan Info
                                </span>
                            </div>
                            <div style="padding:12px 16px;">
                                <table style="width:100%;font-size:.77rem;border-collapse:collapse;">
                                    <tr>
                                        <td style="padding:4px 0;color:var(--muted);width:40%;">Plan #</td>
                                        <td style="padding:4px 0;font-weight:600;"><?= $planId ?></td>
                                    </tr>
                                    <tr>
                                        <td style="padding:4px 0;color:var(--muted);">Department</td>
                                        <td style="padding:4px 0;font-weight:600;"><?= htmlspecialchars($deptName) ?></td>
                                    </tr>
                                    <tr>
                                        <td style="padding:4px 0;color:var(--muted);">Supervisor</td>
                                        <td style="padding:4px 0;font-weight:600;"><?= htmlspecialchars($supervisorName) ?></td>
                                    </tr>
                                    <?php if ($isSupervisor && !$canEdit): ?>
                                    <tr>
                                        <td colspan="2" style="padding:6px 0;">
                                            <span style="font-size:.72rem;background:#eff6ff;color:#3b82f6;padding:3px 8px;border-radius:6px;font-weight:600;">
                                                <i class="fas fa-eye me-1"></i>You are supervisor — view only
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td style="padding:4px 0;color:var(--muted);">Status</td>
                                        <td style="padding:4px 0;">
                                            <span class="status-chip"
                                                style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;font-size:.7rem;">
                                                <i class="fas <?= $sc['icon'] ?>"></i>
                                                <?= ucfirst($plan['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:4px 0;color:var(--muted);">Created</td>
                                        <td style="padding:4px 0;"><?= date('d M Y', strtotime($plan['created_at'])) ?></td>
                                    </tr>
                                    <?php if ($plan['approved_by']): ?>
                                    <tr>
                                        <td style="padding:4px 0;color:var(--muted);">Approved</td>
                                        <td style="padding:4px 0;"><?= date('d M Y', strtotime($plan['approved_at'])) ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>

                    </div>
                </div><!-- /grid -->

            </form>

        </div>
    </div>
</div>

<!-- ✅ Full edit template (was missing entirely) -->
<template id="entryTemplate">
<div class="entry-row" data-index="__IDX__">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
        <span style="font-size:.75rem;font-weight:700;color:var(--gold);">
            <i class="fas fa-building me-1"></i>Visit #<span class="entry-num">1</span>
        </span>
        <button type="button" class="btn btn-sm btn-outline-danger"
                onclick="removeEntry(this)" style="font-size:.7rem;padding:2px 8px;">
            <i class="fas fa-trash"></i>
        </button>
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;margin-bottom:8px;">
        <div>
            <label class="cn-label">Client <span class="required-star">*</span></label>
            <select name="entries[__IDX__][client_id]" class="cn-input entry-client">
                <option value="">— Select Client —</option>
                <?php foreach ($companies as $c): ?>
                <option value="<?= $c['id'] ?>"
                        data-code="<?= htmlspecialchars($c['company_code']) ?>"
                        data-pan="<?= htmlspecialchars($c['pan_number']) ?>">
                    <?= htmlspecialchars($c['company_name']) ?>
                    <?= $c['company_code'] ? ' — ' . htmlspecialchars($c['company_code']) : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="cn-label">Date <span class="required-star">*</span></label>
            <input type="date" name="entries[__IDX__][plan_date]"
                   class="cn-input entry-date" required>
        </div>
        <div>
            <label class="cn-label">Supervisor</label>
            <select name="entries[__IDX__][supervisor_id]" class="cn-input entry-supervisor">
                <option value="">— None —</option>
                <?php foreach ($supervisorList as $sv): ?>
                <option value="<?= $sv['id'] ?>">
                    <?= htmlspecialchars($sv['full_name']) ?>
                    <?= $sv['employee_id'] ? ' · ' . htmlspecialchars($sv['employee_id']) : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:end;margin-bottom:8px;">
        <div>
            <label class="cn-label">Time In</label>
            <input type="time" name="entries[__IDX__][time_in]" class="cn-input time-in">
        </div>
        <div>
            <label class="cn-label">Time Out</label>
            <input type="time" name="entries[__IDX__][time_out]" class="cn-input time-out">
        </div>
        <div class="hrs-pill">
            <i class="fas fa-clock me-1" style="color:var(--gold);"></i>
            <span class="planned-hrs">0.00h</span>
        </div>
    </div>
    <div>
        <label class="cn-label">Notes</label>
        <input type="text" name="entries[__IDX__][notes]" class="cn-input"
               placeholder="Visit notes…">
    </div>
</div>
</template>

<!-- ✅ Supervisor template (deduplicated — keep only this one) -->
<template id="entryTemplateSupervisor">
<div class="entry-row entry-row-supervisor" data-index="__IDX__"
     style="background:#f8fafc;border-left:3px solid #3b82f6;">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
        <span style="font-size:.75rem;font-weight:700;color:#3b82f6;">
            <i class="fas fa-building me-1"></i>Visit #<span class="entry-num">1</span>
        </span>
        <span class="sv-role-badge"
              style="font-size:.7rem;background:#eff6ff;color:#3b82f6;border-radius:4px;padding:2px 7px;font-weight:600;">
            <i class="fas fa-user-shield me-1"></i>Supervisor View
        </span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px;">
        <div>
            <div style="font-size:.68rem;color:#6b7280;margin-bottom:2px;">Staff</div>
            <div class="cn-input" style="background:#f3f4f6;cursor:default;color:#374151;font-weight:600;"
                 data-field="assigned_name">—</div>
        </div>
        <div>
            <div style="font-size:.68rem;color:#6b7280;margin-bottom:2px;">Date</div>
            <div class="cn-input" style="background:#f3f4f6;cursor:default;color:#374151;font-weight:600;"
                 data-field="plan_date">—</div>
        </div>
    </div>
    <div data-field="schedule-slot" style="margin-bottom:8px;">
        <div class="cn-input" style="background:#fef3c7;cursor:default;color:#92400e;font-weight:700;"
             data-field="schedule">—</div>
    </div>
    <div data-field="notes-slot" style="margin-bottom:4px;">
        <div data-field="notes" style="font-size:.75rem;color:#6b7280;">—</div>
    </div>
    <div data-field="client-slot" style="display:none;margin-bottom:4px;">
        <div style="font-size:.68rem;color:#6b7280;margin-bottom:2px;">Client</div>
        <div data-field="client_name"
             style="font-size:.82rem;font-weight:600;color:#1f2937;background:#f0fdf4;border-radius:6px;padding:5px 10px;"></div>
    </div>
    <div class="sv-lock-note" style="font-size:.7rem;color:#9ca3af;font-style:italic;margin-top:4px;">
        <i class="fas fa-lock me-1"></i>Client details are visible to the assigned staff only.
    </div>
</div>
</template>



<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
let entIdx = 0;
const wsEl = document.getElementById('weekStart');
const weEl = document.getElementById('weekEnd');

const existingEntries = <?= json_encode(array_map(function($e) {
    return [
        'entry_id'      => $e['id'],
        'client_id'     => $e['client_id'],
        'company_name'  => $e['company_name'],
        'plan_date'     => $e['plan_date'],
        'time_in'       => $e['planned_time_in'],
        'time_out'      => $e['planned_time_out'],
        'notes'         => $e['notes'],
        'supervisor_id' => $e['supervisor_id'],
        'assigned_name' => $e['assigned_name'] ?? '',
    ];
}, $existingEntries)) ?>;

const isSupervisorOnly = <?= (!$canEdit) ? 'true' : 'false' ?>;
const canEdit          = <?= $canEdit ? 'true' : 'false' ?>;

// ── TomSelect: staff dropdown ──────────────────────────────────
const assignedUserEl = document.getElementById('assignedUser');
if (assignedUserEl) {
    new TomSelect(assignedUserEl, {
        placeholder: 'Search staff…',
        searchField: ['text'],
        maxOptions: 200,
        render: {
            option: (data, escape) => {
                const parts = data.text.split(' · ');
                const empId = parts[1] ? `<span style="color:var(--muted);font-size:.75rem;margin-left:6px;">${escape(parts[1].trim())}</span>` : '';
                return `<div>${escape(parts[0].trim())}${empId}</div>`;
            },
            item: (data, escape) => {
                const parts = data.text.split(' · ');
                const empId = parts[1] ? ` <span style="color:var(--muted);font-size:.75rem;">(${escape(parts[1].trim())})</span>` : '';
                return `<div>${escape(parts[0].trim())}${empId}</div>`;
            }
        }
    });
}

function onWeekChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const ws  = opt.dataset.start || '';
    const we  = opt.dataset.end   || '';
    wsEl.value = ws;
    weEl.value = we;
    document.querySelectorAll('.entry-date').forEach(d => { d.min = ws; d.max = we; });
    // ✅ also constrain supervisor's editable date inputs
    document.querySelectorAll('.sv-plan-date').forEach(d => { d.min = ws; d.max = we; });
    document.getElementById('wkInfo').innerHTML =
        ws ? '<i class="fas fa-calendar me-1"></i>' + fmtDate(ws) + ' – ' + fmtDate(we)
           : '<i class="fas fa-calendar me-1"></i>Select a week above';
}

function fmtDate(d) {
    if (!d) return '—';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', {day:'2-digit', month:'short'});
}

function fmtTime(t) {
    if (!t) return '—';
    // t is "HH:MM:SS" or "HH:MM"
    const [h, m] = t.split(':');
    const hr = parseInt(h), ampm = hr >= 12 ? 'PM' : 'AM';
    return ((hr % 12) || 12) + ':' + m + ' ' + ampm;
}

// ── Add entry: supervisor sees schedule-only card ──────────────
function addEntry(prefill) {
    if (isSupervisorOnly && prefill) {
        addSupervisorEntry(prefill);
        return;
    }
    addFullEntry(prefill);
}

function addSupervisorEntry(prefill) {
    const tpl  = document.getElementById('entryTemplateSupervisor').innerHTML;
    const html = tpl.replaceAll('__IDX__', entIdx);
    const wrap = document.createElement('div');
    wrap.innerHTML = html;
    const row = wrap.firstElementChild;

    const isMySupervisedEntry = prefill?.supervisor_id == <?= $uid ?>;

    // Always fill static fields
    // Always fill staff name (readonly for all)
const nameDiv = row.querySelector('[data-field="assigned_name"]');
if (nameDiv) nameDiv.textContent = prefill?.assigned_name || '—';

if (isMySupervisedEntry) {
    // ── Replace date div with editable date input ──────────────
    const dateDiv = row.querySelector('[data-field="plan_date"]');
    if (dateDiv) {
        const dateInput = document.createElement('input');
        dateInput.type  = 'date';
        dateInput.name  = `sv_entries[${prefill.entry_id}][plan_date]`;
        dateInput.value = prefill?.plan_date || '';
        dateInput.className = 'cn-input sv-plan-date';
        dateInput.style.cssText = 'font-size:.8rem;padding:5px 8px;';
        // Constrain to the plan's week range
        if (wsEl?.value) dateInput.min = wsEl.value;
        if (weEl?.value) dateInput.max = weEl.value;
        dateDiv.replaceWith(dateInput);
    }
        // ── My entry: show client name + editable time/notes ───
        row.setAttribute('data-entry-id', prefill.entry_id ?? '');
        row.setAttribute('data-supervisor-editable', '1');

        // Show client name
        const clientSlot = row.querySelector('[data-field="client-slot"]');
        if (clientSlot) {
            clientSlot.style.display = '';
            row.querySelector('[data-field="client_name"]').textContent = prefill.company_name || '—';
        }

        // Remove lock note
        const lockNote = row.querySelector('.sv-lock-note');
        if (lockNote) lockNote.remove();

        // Replace schedule slot with time inputs
        const scheduleSlot = row.querySelector('[data-field="schedule-slot"]');
        if (scheduleSlot) {
            scheduleSlot.innerHTML = `
                <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:end;">
                    <div>
                        <div style="font-size:.68rem;color:#6b7280;margin-bottom:2px;">Time In</div>
                        <input type="time" name="sv_entries[${prefill.entry_id}][time_in]"
                               class="cn-input sv-time-in" value="${prefill.time_in || ''}"
                               style="font-size:.8rem;padding:5px 8px;">
                    </div>
                    <div>
                        <div style="font-size:.68rem;color:#6b7280;margin-bottom:2px;">Time Out</div>
                        <input type="time" name="sv_entries[${prefill.entry_id}][time_out]"
                               class="cn-input sv-time-out" value="${prefill.time_out || ''}"
                               style="font-size:.8rem;padding:5px 8px;">
                    </div>
                    <div class="hrs-pill">
                        <i class="fas fa-clock me-1" style="color:var(--gold);"></i>
                        <span class="planned-hrs">0.00h</span>
                    </div>
                </div>`;
        }

        // Replace notes slot with input
        const notesSlot = row.querySelector('[data-field="notes-slot"]');
        if (notesSlot) {
            notesSlot.innerHTML = `
                <div style="font-size:.68rem;color:#6b7280;margin-bottom:2px;">Notes</div>
                <input type="text" name="sv_entries[${prefill.entry_id}][notes]"
                       class="cn-input sv-notes" value="${(prefill.notes || '').replace(/"/g, '&quot;')}"
                       placeholder="Visit notes…" style="font-size:.8rem;padding:5px 8px;">`;
        }

        // Update badge
        const badge = row.querySelector('.sv-role-badge');
        if (badge) {
            badge.innerHTML = '<i class="fas fa-pencil-alt me-1"></i>Your Entry — Editable';
            badge.style.background = '#ecfdf5';
            badge.style.color = '#10b981';
        }

        // Wire hours calculation — must happen after DOM insertion
        // so we use a flag and do it after appendChild below
        row._initHours = () => {
            const tin  = row.querySelector('.sv-time-in');
            const tout = row.querySelector('.sv-time-out');
            if (tin && tout) {
                [tin, tout].forEach(t => t.addEventListener('change', () => calcHours(row)));
                if (tin.value && tout.value) calcHours(row);
            }
        };

    } else {
        // ── Other supervisor's entry: fully readonly ───────────
        const schedule = prefill?.time_in && prefill?.time_out
            ? fmtTime(prefill.time_in) + ' – ' + fmtTime(prefill.time_out)
            : '— No time set —';

        const scheduleEl = row.querySelector('[data-field="schedule"]');
        if (scheduleEl) scheduleEl.textContent = schedule;

        const notesEl = row.querySelector('[data-field="notes"]');
        if (notesEl) notesEl.textContent = prefill?.notes || '—';
    }

    document.getElementById('entriesContainer').appendChild(row);
    document.getElementById('emptyEntries').style.display = 'none';
    if (row._initHours) row._initHours();
    // Init hours after append so inputs exist in DOM
    if (row._initHours) row._initHours();

    entIdx++;
    renumber();
    updateSummary();
}
function addFullEntry(prefill) {
    const tpl  = document.getElementById('entryTemplate').innerHTML;
    const html = tpl.replaceAll('__IDX__', entIdx);
    const wrap = document.createElement('div');
    wrap.innerHTML = html;
    const row = wrap.firstElementChild;

    if (wsEl.value) row.querySelector('.entry-date').min = wsEl.value;
    if (weEl.value) row.querySelector('.entry-date').max = weEl.value;

    if (prefill) {
        if (prefill.plan_date) row.querySelector('.entry-date').value = prefill.plan_date;
        if (prefill.time_in)   row.querySelector('.time-in').value    = prefill.time_in;
        if (prefill.time_out)  row.querySelector('.time-out').value   = prefill.time_out;
        if (prefill.notes)     row.querySelector('input[name$="[notes]"]').value = prefill.notes;
    }

    // ── TomSelect: client dropdown ─────────────────────────────
    const clientSel = row.querySelector('.entry-client');
    const ts = new TomSelect(clientSel, {
        placeholder: 'Search by name, code or PAN…',
        maxOptions: 500,
        searchField: ['text'],
        render: {
            option: (data, escape) => {
                const code = data.$option?.dataset?.code || '';
                const pan  = data.$option?.dataset?.pan  || '';
                return `<div style="padding:4px 2px;">
                    <div style="font-weight:600;font-size:.83rem;">${escape(data.text.split(' — ')[0])}</div>
                    <div style="font-size:.7rem;color:#9ca3af;display:flex;gap:10px;margin-top:1px;">
                        ${code ? `<span><i class="fas fa-tag" style="font-size:.6rem;"></i> ${escape(code)}</span>` : ''}
                        ${pan  ? `<span><i class="fas fa-id-card" style="font-size:.6rem;"></i> PAN: ${escape(pan)}</span>` : ''}
                    </div>
                </div>`;
            },
            item: (data, escape) => {
                const pan  = data.$option?.dataset?.pan || '';
                const name = escape(data.text.split(' — ')[0]);
                return pan
                    ? `<div>${name} <span style="font-size:.72rem;color:#9ca3af;">(PAN: ${escape(pan)})</span></div>`
                    : `<div>${name}</div>`;
            }
        }
    });
    if (prefill?.client_id) ts.setValue(String(prefill.client_id), true);

    // ── TomSelect: supervisor dropdown ─────────────────────────
    const supSel = row.querySelector('.entry-supervisor');
    const tsSup = new TomSelect(supSel, {
        placeholder: 'Search supervisor…',
        searchField: ['text'],
        maxOptions: 200,
        allowEmptyOption: true,
        render: {
            option: (data, escape) => {
                const parts = data.text.split(' · ');
                const empId = parts[1] ? `<span style="color:var(--muted);font-size:.75rem;margin-left:6px;">${escape(parts[1].trim())}</span>` : '';
                return `<div>${escape(parts[0].trim())}${empId}</div>`;
            },
            item: (data, escape) => {
                const parts = data.text.split(' · ');
                return `<div>${escape(parts[0].trim())}</div>`;
            }
        }
    });
    if (!prefill) {
        tsSup.setValue('<?= $uid ?>', true);
    }
    if (prefill?.supervisor_id) tsSup.setValue(String(prefill.supervisor_id), true);

    // ── Time calculation ────────────────────────────────────────
    row.querySelectorAll('.time-in,.time-out').forEach(t =>
        t.addEventListener('change', () => calcHours(row))
    );

    document.getElementById('entriesContainer').appendChild(row);
    document.getElementById('emptyEntries').style.display = 'none';
    entIdx++;
    renumber();

    if (prefill?.time_in && prefill?.time_out) calcHours(row);
    else updateSummary();
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
    // works for both full entries (.time-in/.time-out)
    // and supervisor entries (.sv-time-in/.sv-time-out)
    const tinEl  = row.querySelector('.time-in, .sv-time-in');
    const toutEl = row.querySelector('.time-out, .sv-time-out');
    const tin    = tinEl?.value;
    const tout   = toutEl?.value;
    const pill   = row.querySelector('.planned-hrs');
    if (tin && tout) {
        const diff = (new Date('1970-01-01T' + tout) - new Date('1970-01-01T' + tin)) / 3600000;
        if (pill) pill.textContent = (diff > 0 ? diff : 0).toFixed(2) + 'h';
    } else {
        if (pill) pill.textContent = '0.00h';
    }
    updateSummary();
}

function updateSummary() {
    let total = 0, cnt = 0;
    document.querySelectorAll('.entry-row').forEach(row => {
        cnt++;
        const pill = row.querySelector('.planned-hrs');
        if (pill) total += parseFloat(pill.textContent) || 0;
    });
    document.getElementById('totHrs').textContent = total.toFixed(1) + 'h';
    document.getElementById('entCnt').textContent = cnt;
}

// ── On load: populate entries ──────────────────────────────────
<?php if (!empty($postData['entries'])): ?>
const postEntries = <?= json_encode(array_values($postData['entries'])) ?>;
postEntries.forEach(e => addEntry(e));
<?php else: ?>
existingEntries.forEach(e => addEntry(e));
<?php endif; ?>

// Restore week info on load
const wkSel = document.getElementById('weekSelect');
if (wkSel && wkSel.value) onWeekChange(wkSel);

</script>
<?php include '../../includes/footer.php'; ?>