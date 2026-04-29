<?php
$__u = currentUser();
$db  = getDB();

// ── Full user row ─────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT u.*, r.role_name, b.branch_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE u.id = ?
");
$stmt->execute([$__u['id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$__uid          = (int)($__u['id'] ?? 0);
$__viewerRole   = $__u['role_name'] ?? 'staff';

// ── Unread notification count (all types) ─────────────────────
$__unread = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$__uid]);
    $__unread = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// ── Latest 8 notifications for dropdown ──────────────────────
$__notifs = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $stmt->execute([$__uid]);
    $__notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Follow-up reminders (unread, type=reminder) ───────────────
// Scoped to login user only — today and tomorrow only
$reminders = [];
try {
    $stmt = $db->prepare("
        SELECT n.*, t.task_number, t.id AS task_id
        FROM notifications n
        LEFT JOIN tasks t ON t.id = CAST(
            SUBSTRING_INDEX(n.link, '?id=', -1) AS UNSIGNED
        )
        WHERE n.user_id = ?
          AND n.type    = 'reminder'
          AND n.is_read = 0
          AND DATE(n.created_at) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([$__uid]);
    $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Plan reminders (unread, type=plan) ───────────────────────
// Scoped to login user — today + tomorrow — show plan owner so
// a reviewer knows whose plan triggered the notification
$planReminders = [];
if (in_array($__viewerRole, ['staff', 'admin', 'executive', 'superadmin'])) {
    try {
        $stmt = $db->prepare("
            SELECT n.*,
                   wp.id            AS plan_id,
                   wp.week_number,
                   wp.week_start_date,
                   wp.week_end_date,
                   wp.status        AS plan_status,
                   u.full_name      AS plan_owner_name,
                   u.employee_id    AS plan_owner_emp_id
            FROM notifications n
            LEFT JOIN work_plans wp ON wp.id = CAST(
                SUBSTRING_INDEX(n.link, '?id=', -1) AS UNSIGNED
            )
            LEFT JOIN users u ON u.id = wp.user_id
            WHERE n.user_id = ?
              AND n.type    = 'plan'
              AND n.is_read = 0
              AND DATE(n.created_at) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            ORDER BY n.created_at DESC
        ");
        $stmt->execute([$__uid]);
        $planReminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── Password change reminder ──────────────────────────────────
$__showPwReminder = function_exists('shouldPromptPasswordChange') && shouldPromptPasswordChange();

// ── Type → icon + colour map ──────────────────────────────────
$__typeMap = [
    'task'     => ['fa-list-check',   '#3b82f6', '#dbeafe'],
    'transfer' => ['fa-exchange-alt', '#8b5cf6', '#ede9fe'],
    'status'   => ['fa-circle-dot',   '#f59e0b', '#fef3c7'],
    'system'   => ['fa-gear',         '#6b7280', '#f3f4f6'],
    'reminder' => ['fa-bell',         '#ef4444', '#fee2e2'],
    'plan'     => ['fa-calendar-check','#3b82f6','#dbeafe'],
];

// ── Link rewriter ─────────────────────────────────────────────
if (!function_exists('__rewriteLink')) {
    function __rewriteLink(string $link, string $role): string {
        if (empty($link)) return $link;
        return preg_replace(
            '#/(staff|admin|executive)/tasks/(view|index)\.php#',
            '/' . $role . '/tasks/$2.php',
            $link
        ) ?: $link;
    }
}

// ── Plan status badge helper ──────────────────────────────────
if (!function_exists('__planStatusBadge')) {
    function __planStatusBadge(string $status): string {
        $map = [
            'draft'     => ['#f3f4f6', '#6b7280', 'Draft'],
            'submitted' => ['#eff6ff', '#3b82f6', 'Submitted'],
            'approved'  => ['#ecfdf5', '#10b981', 'Approved'],
            'rejected'  => ['#fef2f2', '#ef4444', 'Rejected'],
        ];
        [$bg, $col, $lbl] = $map[$status] ?? ['#f3f4f6', '#9ca3af', ucfirst($status)];
        return "<span style='background:{$bg};color:{$col};font-size:.65rem;font-weight:700;
                             padding:.1rem .42rem;border-radius:99px;'>{$lbl}</span>";
    }
}
?>

<?php /* ══ Password change reminder modal ═══════════════════════════ */ ?>
<?php if ($__showPwReminder): ?>
<div id="pw-reminder-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99999;
        display:flex;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:2rem;max-width:420px;width:90%;
            box-shadow:0 20px 60px rgba(0,0,0,.25);text-align:center;">
        <div style="width:56px;height:56px;background:#fef3c7;border-radius:50%;
                display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <i class="fas fa-key" style="color:#f59e0b;font-size:1.3rem;"></i>
        </div>
        <h5 style="font-size:1rem;font-weight:700;color:#111827;margin-bottom:.4rem;">
            Time to update your password
        </h5>
        <p style="font-size:.85rem;color:#6b7280;margin-bottom:1.25rem;line-height:1.5;">
            Your password hasn't been changed in over 30 days.
            Keeping it fresh helps protect your account.
        </p>
        <div class="d-flex gap-2 justify-content-center">
            <?php
            $baseUrl  = rtrim(APP_URL, '/');
            $rolePath = match($__u['role_name'] ?? '') {
                'admin'     => 'admin',
                'executive' => 'executive',
                default     => 'staff',
            };
            ?>
            <a href="<?= $baseUrl ?>/<?= $rolePath ?>/profile/index.php"
               style="background:#c9a84c;color:#fff;border:none;border-radius:8px;
                      padding:.55rem 1.25rem;font-size:.85rem;font-weight:600;
                      text-decoration:none;display:inline-block;">
                <i class="fas fa-key me-1"></i>Change Now
            </a>
            <button onclick="dismissPwReminder()"
                    style="background:#f3f4f6;color:#6b7280;border:none;border-radius:8px;
                           padding:.55rem 1.25rem;font-size:.85rem;font-weight:600;cursor:pointer;">
                Remind me later
            </button>
        </div>
    </div>
</div>
<?php endif; ?>


<?php /* ══ Follow-up reminder modal (shown on page load) ══════════════
         Scoped: only login user's own reminders, today + tomorrow      */ ?>
<?php if (!empty($reminders)): ?>
<div id="followup-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.55);
        z-index:999999;display:flex;align-items:center;justify-content:center;padding:1rem;">
    <div style="width:100%;max-width:460px;background:#fff;border-radius:16px;
            box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;">

        <!-- Header -->
        <div style="background:#fffbeb;border-bottom:1px solid #fde68a;
                padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:.6rem;">
                <div style="width:36px;height:36px;background:#f59e0b;border-radius:50%;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-bell" style="color:#fff;font-size:.9rem;"></i>
                </div>
                <div>
                    <div style="font-size:.9rem;font-weight:700;color:#92400e;">Follow-up Reminders</div>
                    <div style="font-size:.72rem;color:#b45309;">
                        <?= count($reminders) ?> task<?= count($reminders) > 1 ? 's' : '' ?>
                        need<?= count($reminders) === 1 ? 's' : '' ?> attention today
                    </div>
                </div>
            </div>
            <button onclick="closeFollowupModal()"
                    style="background:none;border:none;cursor:pointer;color:#92400e;
                           font-size:1.1rem;padding:.25rem;line-height:1;opacity:.7;" title="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Body -->
        <div style="max-height:380px;overflow-y:auto;padding:1rem;">
            <?php foreach ($reminders as $r):
                $taskLink = __rewriteLink($r['link'] ?? '', $__viewerRole);
            ?>
            <div style="padding:.85rem;border:1px solid #fde68a;border-radius:10px;
                    margin-bottom:.75rem;background:#fffdf5;border-left:4px solid #f59e0b;">
                <div style="font-size:.85rem;font-weight:700;color:#111827;margin-bottom:.2rem;">
                    <?= htmlspecialchars($r['title']) ?>
                </div>
                <?php if (!empty($r['task_number'])): ?>
                <div style="font-size:.7rem;background:#fef3c7;color:#92400e;display:inline-block;
                        padding:.1rem .45rem;border-radius:99px;margin-bottom:.35rem;font-weight:600;">
                    <i class="fas fa-hashtag" style="font-size:.6rem;"></i>
                    <?= htmlspecialchars($r['task_number']) ?>
                </div>
                <?php endif; ?>
                <div style="font-size:.8rem;color:#6b7280;margin-bottom:.65rem;line-height:1.5;">
                    <?= htmlspecialchars($r['message']) ?>
                </div>
                <div style="display:flex;gap:.5rem;">
                    <?php if (!empty($taskLink)): ?>
                    <a href="<?= htmlspecialchars($taskLink) ?>"
                       style="background:#c9a84c;color:#fff;padding:.35rem .85rem;border-radius:6px;
                              text-decoration:none;font-size:.8rem;font-weight:600;">
                        <i class="fas fa-arrow-right me-1"></i>Open Task
                    </a>
                    <?php endif; ?>
                    <button onclick="markReminderRead(<?= (int)$r['id'] ?>, this)"
                            style="background:#f3f4f6;border:none;color:#6b7280;padding:.35rem .85rem;
                                   border-radius:6px;cursor:pointer;font-size:.8rem;font-weight:600;">
                        <i class="fas fa-check me-1"></i>Mark Read
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Footer -->
        <div style="padding:.75rem 1.25rem;border-top:1px solid #f3f4f6;
                display:flex;justify-content:flex-end;gap:.5rem;background:#fafafa;">
            <button onclick="markAllRemindersRead()"
                    style="background:#f59e0b;color:#fff;border:none;padding:.4rem 1rem;
                           border-radius:7px;cursor:pointer;font-size:.8rem;font-weight:600;">
                <i class="fas fa-check-double me-1"></i>Mark All Read
            </button>
            <button onclick="closeFollowupModal()"
                    style="background:#f3f4f6;color:#6b7280;border:none;padding:.4rem 1rem;
                           border-radius:7px;cursor:pointer;font-size:.8rem;font-weight:600;">
                Dismiss
            </button>
        </div>
    </div>
</div>
<?php endif; ?>


<?php /* ══ Plan reminder modal (shown on page load) ════════════════════
         Scoped: only login user's notifications, today + tomorrow
         Shows plan owner so a reviewer knows whose plan this is         */ ?>
<?php if (!empty($planReminders)): ?>
<div id="plan-reminder-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.55);
        z-index:999998;display:flex;align-items:center;justify-content:center;padding:1rem;">
    <div style="width:100%;max-width:460px;background:#fff;border-radius:16px;
            box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;">

        <!-- Header -->
        <div style="background:#eff6ff;border-bottom:1px solid #bfdbfe;
                padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:.6rem;">
                <div style="width:36px;height:36px;background:#3b82f6;border-radius:50%;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-calendar-check" style="color:#fff;font-size:.9rem;"></i>
                </div>
                <div>
                    <div style="font-size:.9rem;font-weight:700;color:#1e40af;">Plan Reminders</div>
                    <div style="font-size:.72rem;color:#1d4ed8;">
                        <?= count($planReminders) ?> plan<?= count($planReminders) > 1 ? 's' : '' ?>
                        need<?= count($planReminders) === 1 ? 's' : '' ?> attention today
                    </div>
                </div>
            </div>
            <button onclick="closePlanModal()"
                    style="background:none;border:none;cursor:pointer;color:#1e40af;
                           font-size:1.1rem;padding:.25rem;line-height:1;opacity:.7;" title="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Body -->
        <div style="max-height:380px;overflow-y:auto;padding:1rem;">
            <?php foreach ($planReminders as $pr):
                $planLink = __rewriteLink($pr['link'] ?? '', $__viewerRole);
                // Only show plan owner row when viewer is NOT the plan owner
                $showOwner = !empty($pr['plan_owner_name'])
                    && ($pr['plan_owner_emp_id'] ?? '') !== ($user['employee_id'] ?? '__self__');
            ?>
            <div style="padding:.85rem;border:1px solid #bfdbfe;border-radius:10px;
                    margin-bottom:.75rem;background:#eff6ff;border-left:4px solid #3b82f6;">

                <!-- Plan title -->
                <div style="font-size:.85rem;font-weight:700;color:#111827;margin-bottom:.3rem;">
                    <?= htmlspecialchars($pr['title']) ?>
                </div>

                <!-- Meta row: plan ref + status + owner -->
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;margin-bottom:.4rem;">
                    <?php if (!empty($pr['plan_id'])): ?>
                    <span style="font-size:.7rem;background:#dbeafe;color:#1e40af;
                                 padding:.1rem .45rem;border-radius:99px;font-weight:600;">
                        <i class="fas fa-hashtag" style="font-size:.6rem;"></i> Plan #<?= (int)$pr['plan_id'] ?>
                    </span>
                    <?php endif; ?>

                    <?php if (!empty($pr['week_number'])): ?>
                    <span style="font-size:.7rem;background:#dbeafe;color:#1e40af;
                                 padding:.1rem .45rem;border-radius:99px;font-weight:600;">
                        Week <?= (int)$pr['week_number'] ?>
                        <?php if (!empty($pr['week_start_date']) && !empty($pr['week_end_date'])): ?>
                        · <?= date('d M', strtotime($pr['week_start_date'])) ?>
                        – <?= date('d M', strtotime($pr['week_end_date'])) ?>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>

                    <?php if (!empty($pr['plan_status'])): ?>
                    <?= __planStatusBadge($pr['plan_status']) ?>
                    <?php endif; ?>
                </div>

                <?php if ($showOwner): ?>
                <!-- Plan owner — shown to reviewer so they know whose plan this is -->
                <div style="font-size:.75rem;color:#1e40af;background:#dbeafe;border-radius:7px;
                            padding:.3rem .6rem;margin-bottom:.5rem;display:flex;align-items:center;gap:.4rem;">
                    <i class="fas fa-user" style="font-size:.65rem;"></i>
                    <span>Plan by: <strong><?= htmlspecialchars($pr['plan_owner_name']) ?></strong>
                    <?php if (!empty($pr['plan_owner_emp_id'])): ?>
                        <span style="opacity:.7;">(<?= htmlspecialchars($pr['plan_owner_emp_id']) ?>)</span>
                    <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>

                <!-- Message -->
                <div style="font-size:.8rem;color:#6b7280;margin-bottom:.65rem;line-height:1.5;">
                    <?= htmlspecialchars($pr['message']) ?>
                </div>

                <!-- Actions -->
                <div style="display:flex;gap:.5rem;">
                    <?php if (!empty($planLink)): ?>
                    <a href="<?= htmlspecialchars($planLink) ?>"
                       style="background:#3b82f6;color:#fff;padding:.35rem .85rem;border-radius:6px;
                              text-decoration:none;font-size:.8rem;font-weight:600;">
                        <i class="fas fa-arrow-right me-1"></i>Open Plan
                    </a>
                    <?php endif; ?>
                    <button onclick="markPlanRead(<?= (int)$pr['id'] ?>, this)"
                            style="background:#f3f4f6;border:none;color:#6b7280;padding:.35rem .85rem;
                                   border-radius:6px;cursor:pointer;font-size:.8rem;font-weight:600;">
                        <i class="fas fa-check me-1"></i>Mark Read
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Footer -->
        <div style="padding:.75rem 1.25rem;border-top:1px solid #f3f4f6;
                display:flex;justify-content:flex-end;gap:.5rem;background:#fafafa;">
            <button onclick="markAllPlanRemindersRead()"
                    style="background:#3b82f6;color:#fff;border:none;padding:.4rem 1rem;
                           border-radius:7px;cursor:pointer;font-size:.8rem;font-weight:600;">
                <i class="fas fa-check-double me-1"></i>Mark All Read
            </button>
            <button onclick="closePlanModal()"
                    style="background:#f3f4f6;color:#6b7280;border:none;padding:.4rem 1rem;
                           border-radius:7px;cursor:pointer;font-size:.8rem;font-weight:600;">
                Dismiss
            </button>
        </div>
    </div>
</div>
<?php endif; ?>


<?php /* ══ Topbar ═════════════════════════════════════════════════════ */ ?>
<div class="topbar">
    <div id="notif-toast-container"
         style="position:fixed;top:70px;right:20px;z-index:9999;pointer-events:none;"></div>

    <!-- Left: toggle + page title -->
    <div class="topbar-left">
        <button class="sidebar-toggle" onclick="toggleSidebar()" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <div class="topbar-title"><?= htmlspecialchars($pageTitle ?? '') ?></div>
    </div>

    <!-- Right: date + bell + user -->
    <div class="topbar-right">

        <!-- Date -->
        <div class="topbar-date d-none d-md-flex">
            <i class="fas fa-calendar-alt text-warning me-1"></i>
            <span id="topbar-date"></span>
        </div>

        <!-- ── Notification bell ───────────────────────────────── -->
        <div class="dropdown" id="notif-dropdown-wrap">
            <button class="topbar-icon-btn" data-bs-toggle="dropdown" data-bs-auto-close="outside"
                    title="Notifications" style="position:relative;">
                <i class="fas fa-bell"></i>
                <span class="notif-badge" id="notif-count"
                      style="display:<?= $__unread > 0 ? 'flex' : 'none' ?>;">
                    <?= $__unread > 9 ? '9+' : ($__unread ?: '') ?>
                </span>
            </button>

            <!-- Dropdown panel -->
            <div class="dropdown-menu dropdown-menu-end p-0"
                 style="width:360px;max-height:480px;overflow:hidden;
                        border-radius:14px;border:1px solid #e5e7eb;
                        box-shadow:0 12px 40px rgba(0,0,0,.12);">

                <!-- Panel header -->
                <div style="padding:.75rem 1rem;border-bottom:1px solid #f3f4f6;
                            display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:.87rem;font-weight:700;color:#111827;">
                        <i class="fas fa-bell text-warning me-2"></i>Notifications
                        <span id="notif-new-label"
                              style="background:#fef3c7;color:#92400e;font-size:.68rem;
                                     padding:.1rem .45rem;border-radius:99px;margin-left:.3rem;
                                     font-weight:700;display:<?= $__unread > 0 ? 'inline' : 'none' ?>;">
                            <?= $__unread ?> new
                        </span>
                    </span>
                    <button id="mark-all-btn" onclick="markAllReadDropdown(this)"
                            style="font-size:.72rem;background:none;border:none;color:#c9a84c;
                                   font-weight:600;cursor:pointer;padding:0;
                                   display:<?= $__unread > 0 ? 'inline' : 'none' ?>;">
                        <i class="fas fa-check-double me-1"></i>Mark all read
                    </button>
                </div>

                <!-- Notification list -->
                <div style="overflow-y:auto;max-height:370px;" id="notif-dropdown-list">
                    <?php if (empty($__notifs)): ?>
                    <div id="notif-empty-state"
                         style="padding:2.5rem 1rem;text-align:center;color:#9ca3af;">
                        <i class="fas fa-bell-slash d-block mb-2"
                           style="font-size:1.3rem;opacity:.4;"></i>
                        <span style="font-size:.82rem;">No notifications yet.</span>
                    </div>
                    <?php else: ?>
                    <?php foreach ($__notifs as $__n):
                        [$__ic, $__col, $__bg] = $__typeMap[$__n['type']] ?? $__typeMap['system'];
                        $__unreadRow = !$__n['is_read'];
                        $__link = __rewriteLink($__n['link'] ?? '', $__viewerRole);
                    ?>
                    <div class="notif-drop-item" id="ndrop-<?= (int)$__n['id'] ?>"
                         style="padding:.75rem 1rem;border-bottom:1px solid #f9fafb;
                                border-left:3px solid <?= $__unreadRow ? '#f59e0b' : 'transparent' ?>;
                                background:<?= $__unreadRow ? '#fffdf5' : '#fff' ?>;cursor:<?= !empty($__link) ? 'pointer' : 'default' ?>;"
                         <?php if (!empty($__link)): ?>
                         onclick="window.location.href='<?= htmlspecialchars($__link) ?>'"
                         <?php endif; ?>>
                        <div style="display:flex;gap:.7rem;align-items:flex-start;">
                            <div style="width:34px;height:34px;flex-shrink:0;border-radius:9px;
                                        background:<?= $__bg ?>;
                                        display:flex;align-items:center;justify-content:center;">
                                <i class="fas <?= $__ic ?>"
                                   style="color:<?= $__col ?>;font-size:.8rem;"></i>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <?php if (!empty($__n['title'])): ?>
                                <div style="font-size:.8rem;font-weight:700;color:#111827;
                                            margin-bottom:.12rem;white-space:nowrap;
                                            overflow:hidden;text-overflow:ellipsis;">
                                    <?= htmlspecialchars($__n['title']) ?>
                                </div>
                                <?php endif; ?>
                                <div style="font-size:.78rem;color:#4b5563;line-height:1.4;
                                            font-weight:<?= $__unreadRow ? '500' : '400' ?>;
                                            display:-webkit-box;-webkit-line-clamp:2;
                                            -webkit-box-orient:vertical;overflow:hidden;">
                                    <?= htmlspecialchars($__n['message']) ?>
                                </div>
                                <div style="font-size:.68rem;color:#9ca3af;margin-top:.25rem;">
                                    <i class="fas fa-clock me-1"></i>
                                    <?= date('M j · g:i A', strtotime($__n['created_at'])) ?>
                                    <?php if ($__unreadRow): ?>
                                    <span style="display:inline-block;width:6px;height:6px;
                                                 border-radius:50%;background:#f59e0b;
                                                 margin-left:.4rem;vertical-align:middle;"></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Dropdown footer -->
                <div style="padding:.6rem 1rem;border-top:1px solid #f3f4f6;text-align:center;">
                    <a href="<?= rtrim(APP_URL, '/') ?>/includes/notifications.php"
                       style="font-size:.78rem;color:#c9a84c;font-weight:600;text-decoration:none;">
                        <i class="fas fa-list me-1"></i>View all notifications
                    </a>
                </div>
            </div>
        </div><!-- /notif dropdown -->

        <!-- ── User dropdown ──────────────────────────────────── -->
        <div class="dropdown">
            <button class="topbar-user-btn" data-bs-toggle="dropdown">
                <div class="avatar-circle avatar-sm">
                    <?php
                    $name   = $__u['full_name'] ?? $__u['username'] ?? 'User';
                    $parts  = explode(' ', $name);
                    $initials = strtoupper(
                        substr($parts[0], 0, 1) .
                        (isset($parts[1]) ? substr($parts[1], 0, 1) : '')
                    );
                    ?>
                    <?= $initials ?>
                </div>
                <div class="d-none d-md-block text-start">
                    <?php $firstName = explode(' ', $name)[0]; ?>
                    <div style="font-size:.83rem;font-weight:600;color:#1f2937;line-height:1.1;">
                        <?= htmlspecialchars($firstName) ?>
                    </div>
                    <div style="font-size:.7rem;color:#9ca3af;text-transform:capitalize;">
                        <?= htmlspecialchars($__u['role_name'] ?? 'user') ?>
                    </div>
                </div>
                <i class="fas fa-chevron-down ms-1" style="font-size:.65rem;color:#9ca3af;"></i>
            </button>

            <ul class="dropdown-menu dropdown-menu-end" style="min-width:200px;">
                <li>
                    <h6 class="dropdown-header"><?= htmlspecialchars($name) ?></h6>
                </li>
                <?php
                $role     = $__u['role_name'] ?? 'staff';
                $rolePath = match($role) {
                    'admin'     => 'admin',
                    'executive' => 'executive',
                    default     => 'staff',
                };
                $profileUrl = APP_URL . "/{$rolePath}/profile/index.php";
                ?>
                <li>
                    <a class="dropdown-item" href="<?= $profileUrl ?>">
                        <i class="fas fa-user me-2 text-warning"></i>My Profile
                    </a>
                </li>
                <?php if ($__u['role_name'] === 'staff'): ?>
                    <?php if (!empty($__u['dept_id']) && (int)$__u['dept_id'] === 9): ?>
                    <li>
                        <a class="dropdown-item" href="<?= APP_URL ?>/staff/planning/today_tomorrow.php">
                            <i class="fas fa-calendar-day me-2 text-warning"></i>Today's Plan
                        </a>
                    </li>
                    <?php endif; ?>
                <?php else: ?>
                <li>
                    <a class="dropdown-item" href="<?= APP_URL ?>/staff/tasks/today.php">
                        <i class="fas fa-list-check me-2 text-warning"></i>Today's Tasks
                    </a>
                </li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item text-danger" href="<?= APP_URL ?>/auth/logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </li>
            </ul>
        </div>

    </div><!-- /topbar-right -->
</div><!-- /topbar -->


<script>
// ── Topbar date ───────────────────────────────────────────────
(function () {
    const el = document.getElementById('topbar-date');
    if (el) el.textContent = new Date().toLocaleDateString('en-US', {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
    });
})();

// ── Password reminder dismiss ─────────────────────────────────
function dismissPwReminder() {
    const modal = document.getElementById('pw-reminder-modal');
    if (modal) modal.style.display = 'none';
    fetch("<?= APP_URL ?>/ajax/snooze_pw_reminder.php", {
        method: 'POST', credentials: 'same-origin'
    }).catch(() => {});
}

// ── Modal close helpers ───────────────────────────────────────
function closeFollowupModal() {
    const m = document.getElementById('followup-modal');
    if (m) m.style.display = 'none';
}
function closePlanModal() {
    const m = document.getElementById('plan-reminder-modal');
    if (m) m.style.display = 'none';
}

// ── Badge: single source of truth ────────────────────────────
function setBadge(count) {
    const badge = document.getElementById('notif-count');
    const label = document.getElementById('notif-new-label');
    const btn   = document.getElementById('mark-all-btn');
    if (!badge) return;
    if (count > 0) {
        badge.textContent  = count > 9 ? '9+' : String(count);
        badge.style.display = 'flex';
        if (label) { label.textContent = count + ' new'; label.style.display = 'inline'; }
        if (btn)   btn.style.display = 'inline';
    } else {
        badge.textContent  = '';
        badge.style.display = 'none';
        if (label) label.style.display = 'none';
        if (btn)   btn.style.display = 'none';
    }
}

// ── Follow-up reminder: mark one read ────────────────────────
function markReminderRead(id, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    fetch("<?= APP_URL ?>/ajax/mark_notification_read.php", {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id
    }).then(() => {
        const card = btn?.closest('div[style*="border-left:4px solid #f59e0b"]');
        if (card) card.remove();
        if (!document.querySelectorAll('#followup-modal div[style*="border-left:4px solid #f59e0b"]').length)
            closeFollowupModal();
        setBadge(Math.max(0, (parseInt(document.getElementById('notif-count')?.textContent) || 1) - 1));
    }).catch(() => {});
}

// ── Follow-up reminders: mark all read ───────────────────────
function markAllRemindersRead() {
    fetch("<?= APP_URL ?>/ajax/mark_notification_read.php", { method: 'POST' })
        .then(() => { closeFollowupModal(); setBadge(0); })
        .catch(() => {});
}

// ── Plan reminder: mark one read ─────────────────────────────
function markPlanRead(id, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    fetch("<?= APP_URL ?>/ajax/mark_notification_read.php", {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id
    }).then(() => {
        const card = btn?.closest('div[style*="border-left:4px solid #3b82f6"]');
        if (card) card.remove();
        if (!document.querySelectorAll('#plan-reminder-modal div[style*="border-left:4px solid #3b82f6"]').length)
            closePlanModal();
        setBadge(Math.max(0, (parseInt(document.getElementById('notif-count')?.textContent) || 1) - 1));
    }).catch(() => {});
}

// ── Plan reminders: mark all read ────────────────────────────
function markAllPlanRemindersRead() {
    fetch("<?= APP_URL ?>/ajax/mark_notification_read.php", { method: 'POST' })
        .then(() => { closePlanModal(); setBadge(0); })
        .catch(() => {});
}

// ── Notification dropdown: mark all read ─────────────────────
function markAllReadDropdown(btn) {
    if (btn) btn.style.opacity = '.5';
    fetch("<?= APP_URL ?>/ajax/mark_notification_read.php", { method: 'POST' })
        .then(() => {
            document.querySelectorAll('.notif-drop-item').forEach(el => {
                el.style.borderLeft = '3px solid transparent';
                el.style.background = '#fff';
                el.querySelector('.ndrop-dot')?.remove();
            });
            setBadge(0);
        }).catch(() => {});
}

// ── Type map for live-polled toasts ──────────────────────────
const typeMap = {
    task:     { ic: 'fa-list-check',    col: '#3b82f6', bg: '#dbeafe' },
    transfer: { ic: 'fa-exchange-alt',  col: '#8b5cf6', bg: '#ede9fe' },
    status:   { ic: 'fa-circle-dot',    col: '#f59e0b', bg: '#fef3c7' },
    system:   { ic: 'fa-gear',          col: '#6b7280', bg: '#f3f4f6' },
    reminder: { ic: 'fa-bell',          col: '#ef4444', bg: '#fee2e2' },
    plan:     { ic: 'fa-calendar-check',col: '#3b82f6', bg: '#dbeafe' },
};

// Pre-seed seen IDs from server-rendered rows
const shownNotifIds = new Set(
    [...document.querySelectorAll('.notif-drop-item')]
        .map(el => parseInt(el.id.replace('ndrop-', '')))
);

// ── Toast renderer ────────────────────────────────────────────
function showNotifToast(title, message, type) {
    const t = typeMap[type] || typeMap.system;
    const container = document.getElementById('notif-toast-container');
    if (!container) return;
    const toast = document.createElement('div');
    toast.style.cssText = [
        'background:#fff', 'border-radius:12px', 'padding:.75rem 1rem',
        'box-shadow:0 8px 30px rgba(0,0,0,.15)',
        'border-left:4px solid ' + t.col,
        'display:flex', 'gap:.75rem', 'align-items:flex-start',
        'min-width:280px', 'max-width:340px', 'margin-bottom:.5rem',
        'opacity:0', 'transform:translateX(20px)',
        'transition:opacity .3s,transform .3s', 'pointer-events:auto',
    ].join(';');
    toast.innerHTML = `
        <div style="width:32px;height:32px;border-radius:8px;background:${t.bg};
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas ${t.ic}" style="color:${t.col};font-size:.8rem;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            ${title ? `<div style="font-size:.8rem;font-weight:700;color:#111827;margin-bottom:.1rem;">${title}</div>` : ''}
            <div style="font-size:.78rem;color:#374151;line-height:1.4;">${message}</div>
        </div>
        <button onclick="this.closest('div[style]').remove()"
                style="background:none;border:none;color:#9ca3af;cursor:pointer;
                       font-size:.7rem;padding:0;flex-shrink:0;margin-top:.1rem;">✕</button>
    `;
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '1'; toast.style.transform = 'translateX(0)'; }, 30);
    setTimeout(() => {
        toast.style.opacity = '0'; toast.style.transform = 'translateX(20px)';
        setTimeout(() => toast.remove(), 350);
    }, 5000);
}

// ── Poll for new notifications every 15 s ────────────────────
async function fetchNotifications() {
    try {
        const res  = await fetch('<?= APP_URL ?>/includes/check_notifications.php');
        const data = await res.json();
        if (!data.ok) return;

        setBadge(data.unread ?? 0);
        if (!data.data?.length) return;

        data.data.forEach(n => {
            if (n.is_read || shownNotifIds.has(n.id)) return;
            shownNotifIds.add(n.id);
            showNotifToast(n.title ?? '', n.message, n.type ?? 'system');

            // Prepend to dropdown if not already present
            const list = document.getElementById('notif-dropdown-list');
            if (list && !document.getElementById('ndrop-' + n.id)) {
                const t   = typeMap[n.type] || typeMap.system;
                const div = document.createElement('div');
                div.className = 'notif-drop-item';
                div.id        = 'ndrop-' + n.id;
                div.style.cssText = [
                    'padding:.75rem 1rem',
                    'border-bottom:1px solid #f9fafb',
                    'border-left:3px solid #f59e0b',
                    'background:#fffdf5',
                ].join(';');
                div.innerHTML = `
                    <div style="display:flex;gap:.7rem;align-items:flex-start;">
                        <div style="width:34px;height:34px;flex-shrink:0;border-radius:9px;
                                    background:${t.bg};display:flex;align-items:center;justify-content:center;">
                            <i class="fas ${t.ic}" style="color:${t.col};font-size:.8rem;"></i>
                        </div>
                        <div style="flex:1;min-width:0;">
                            ${n.title ? `<div style="font-size:.8rem;font-weight:700;color:#111827;margin-bottom:.12rem;">${n.title}</div>` : ''}
                            <div style="font-size:.78rem;color:#4b5563;font-weight:500;line-height:1.4;
                                        display:-webkit-box;-webkit-line-clamp:2;
                                        -webkit-box-orient:vertical;overflow:hidden;">${n.message}</div>
                            <div style="font-size:.68rem;color:#9ca3af;margin-top:.25rem;">
                                <i class="fas fa-clock me-1"></i>Just now
                                <span style="display:inline-block;width:6px;height:6px;border-radius:50%;
                                             background:#f59e0b;margin-left:.4rem;vertical-align:middle;"></span>
                            </div>
                        </div>
                    </div>`;
                document.getElementById('notif-empty-state')?.remove();
                list.prepend(div);
            }
        });
    } catch (e) { /* silent */ }
}

fetchNotifications();
setInterval(fetchNotifications, 15000);

// Trigger background reminder checks silently
fetch('<?= APP_URL ?>/ajax/check_followup_reminders.php', { credentials: 'same-origin' }).catch(() => {});
fetch('<?= APP_URL ?>/ajax/check_plan_reminders.php',     { credentials: 'same-origin' }).catch(() => {});
</script>