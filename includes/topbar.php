<?php
$__u = currentUser();
$db = getDB();

// Full user row
$stmt = $db->prepare("
    SELECT u.*, r.role_name, b.branch_name
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE u.id = ?
");
$stmt->execute([$__u['id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Unread count
$__unread = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $stmt->execute([$__u['id'] ?? 0]);
    $__unread = (int) $stmt->fetchColumn();
} catch (Exception $e) {
}

// Latest 8 notifications for dropdown
$__notifs = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $stmt->execute([$__u['id']]);
    $__notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Password change reminder
$__showPwReminder = function_exists('shouldPromptPasswordChange') && shouldPromptPasswordChange();

// Type → icon + colour
$__typeMap = [
    'task' => ['fa-list-check', '#3b82f6', '#dbeafe'],
    'transfer' => ['fa-exchange-alt', '#8b5cf6', '#ede9fe'],
    'status' => ['fa-circle-dot', '#f59e0b', '#fef3c7'],
    'system' => ['fa-gear', '#6b7280', '#f3f4f6'],
    'reminder' => ['fa-bell', '#ef4444', '#fee2e2'],
];
$reminders = $db->prepare("
    SELECT n.*, t.task_number
    FROM notifications n
    LEFT JOIN tasks t ON t.id = REPLACE(n.link, '" . APP_URL . "/admin/tasks/view.php?id=', '')
    WHERE n.user_id = ?
      AND n.type = 'reminder'
      AND n.is_read = 0
    ORDER BY n.created_at DESC
");
$reminders->execute([$user['id']]);
$reminders = $reminders->fetchAll(PDO::FETCH_ASSOC);
$__viewerRole = $__u['role'] ?? 'staff';
function __rewriteLink(string $link, string $role): string
{
    if (empty($link))
        return $link;
    return preg_replace(
        '#/(staff|admin|executive)/tasks/(view|index)\.php#',
        '/' . $role . '/tasks/$2.php',
        $link
    ) ?: $link;
}
?>

<!-- ── Password change reminder modal ── -->
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
                $baseUrl = rtrim(APP_URL, '/');
                $rolePath = $__u['role'] === 'admin' ? 'admin' : ($__u['role'] === 'staff' ? 'staff' : ($__u['role'] ?? 'staff'));
                ?>
                <a href="<?= $baseUrl ?>/<?= $rolePath ?>/profile/index.php" style="background:#c9a84c;color:#fff;border:none;border-radius:8px;
                      padding:.55rem 1.25rem;font-size:.85rem;font-weight:600;
                      text-decoration:none;display:inline-block;">
                    <i class="fas fa-key me-1"></i>Change Now
                </a>
                <button onclick="dismissPwReminder()" style="background:#f3f4f6;color:#6b7280;border:none;border-radius:8px;
                           padding:.55rem 1.25rem;font-size:.85rem;font-weight:600;cursor:pointer;">
                    Remind me later
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ── Topbar ── -->
<div class="topbar">
    <div id="notif-toast-container" style="position:fixed;top:70px;right:20px;z-index:9999;pointer-events:none;"></div>

    <div class="topbar-left">
        <button class="sidebar-toggle" onclick="toggleSidebar()" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <div class="topbar-title"><?= htmlspecialchars($pageTitle ?? '') ?></div>
    </div>

    <div class="topbar-right">

        <!-- Date -->
        <div class="topbar-date d-none d-md-flex">
            <i class="fas fa-calendar-alt text-warning me-1"></i>
            <span id="topbar-date"></span>
        </div>

        <!-- ── Notification Bell ── -->
        <div class="dropdown" id="notif-dropdown-wrap">
            <button class="topbar-icon-btn" data-bs-toggle="dropdown" data-bs-auto-close="outside" title="Notifications"
                style="position:relative;">
                <i class="fas fa-bell"></i>
                <span class="notif-badge" id="notif-count" style="display:<?= $__unread > 0 ? 'flex' : 'none' ?>;">
                    <?= $__unread > 9 ? '9+' : ($__unread ?: '') ?>
                </span>
            </button>

            <!-- Dropdown panel -->
            <div class="dropdown-menu dropdown-menu-end p-0" style="width:360px;max-height:480px;overflow:hidden;
                        border-radius:14px;border:1px solid #e5e7eb;
                        box-shadow:0 12px 40px rgba(0,0,0,.12);">

                <!-- Panel header -->
                <div style="padding:.75rem 1rem;border-bottom:1px solid #f3f4f6;
                            display:flex;align-items:center;justify-content:space-between;" id="notif-panel-header">
                    <span style="font-size:.87rem;font-weight:700;color:#111827;">
                        <i class="fas fa-bell text-warning me-2"></i>Notifications
                        <span id="notif-new-label" style="background:#fef3c7;color:#92400e;font-size:.68rem;
                                     padding:.1rem .45rem;border-radius:99px;margin-left:.3rem;font-weight:700;
                                     display:<?= $__unread > 0 ? 'inline' : 'none' ?>;">
                            <?= $__unread ?> new
                        </span>
                    </span>
                    <button id="mark-all-btn" onclick="markAllReadDropdown(this)" style="font-size:.72rem;background:none;border:none;
                                   color:#c9a84c;font-weight:600;cursor:pointer;padding:0;
                                   display:<?= $__unread > 0 ? 'inline' : 'none' ?>;">
                        <i class="fas fa-check-double me-1"></i>Mark all read
                    </button>
                </div>

                <!-- Notification list -->
                <div style="overflow-y:auto;max-height:370px;" id="notif-dropdown-list">
                    <?php if (empty($__notifs)): ?>
                        <div id="notif-empty-state" style="padding:2.5rem 1rem;text-align:center;color:#9ca3af;">
                            <i class="fas fa-bell-slash d-block mb-2" style="font-size:1.3rem;opacity:.4;"></i>
                            <span style="font-size:.82rem;">No notifications yet.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($__notifs as $__n):
                            [$__ic, $__col, $__bg] = $__typeMap[$__n['type']] ?? $__typeMap['system'];
                            $__unreadRow = !$__n['is_read'];
                            $__link = __rewriteLink($__n['link'] ?? '', $__viewerRole);
                            ?>
                            <div class="notif-drop-item" id="ndrop-<?= $__n['id'] ?>" style="padding:.75rem 1rem;
                                border-bottom:1px solid #f9fafb;
                                border-left:3px solid <?= $__unreadRow ? '#f59e0b' : 'transparent' ?>;
                                background:<?= $__unreadRow ? '#fffdf5' : '#fff' ?>;" <?php if (!empty($__link)): ?>
                                    onclick="window.location.href='<?= htmlspecialchars($__link) ?>'" <?php endif; ?>>
                                <div style="display:flex;gap:.7rem;align-items:flex-start;">
                                    <div style="width:34px;height:34px;flex-shrink:0;border-radius:9px;
                                        background:<?= $__bg ?>;
                                        display:flex;align-items:center;justify-content:center;">
                                        <i class="fas <?= $__ic ?>" style="color:<?= $__col ?>;font-size:.8rem;"></i>
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
                                                <span class="ndrop-dot" style="display:inline-block;width:6px;height:6px;
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

                <!-- Footer -->
                <div style="padding:.6rem 1rem;border-top:1px solid #f3f4f6;text-align:center;">
                    <a href="<?= rtrim(APP_URL, '/') ?>/includes/notifications.php"
                        style="font-size:.78rem;color:#c9a84c;font-weight:600;text-decoration:none;">
                        <i class="fas fa-list me-1"></i>View all notifications
                    </a>
                </div>
            </div>
        </div><!-- end notif dropdown -->

        <!-- User dropdown -->
        <div class="dropdown">
            <button class="topbar-user-btn" data-bs-toggle="dropdown">
                <div class="avatar-circle avatar-sm">
                    <?php
                    $name = $__u['full_name'] ?? $__u['username'] ?? 'User';
                    $parts = explode(' ', $name);
                    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                    ?>
                    <?= $initials ?>
                </div>
                <div class="d-none d-md-block text-start">
                    <?php $firstName = explode(' ', $name)[0]; ?>
                    <div style="font-size:.83rem;font-weight:600;color:#1f2937;line-height:1.1;">
                        <?= htmlspecialchars($firstName) ?>
                    </div>
                    <div style="font-size:.7rem;color:#9ca3af;text-transform:capitalize;">
                        <?= htmlspecialchars($__u['role'] ?? 'user') ?>
                    </div>
                </div>
                <i class="fas fa-chevron-down ms-1" style="font-size:.65rem;color:#9ca3af;"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width:200px;">
                <li>
                    <h6 class="dropdown-header"><?= htmlspecialchars($name) ?></h6>
                </li>
                <?php
                $baseUrl = rtrim(APP_URL, '/');
                $rolePath = $__u['role'] === 'admin' ? 'admin'
                    : ($__u['role'] === 'staff' ? 'staff' : ($__u['role'] ?? 'staff'));
                ?>
                <li>
                    <a class="dropdown-item" href="<?= $baseUrl ?>/<?= $rolePath ?>/profile/index.php">
                        <i class="fas fa-user me-2 text-warning"></i>My Profile
                    </a>
                </li>
                <?php if ($__u['role'] === 'staff'): ?>
                    <li>
                        <a class="dropdown-item" href="<?= $baseUrl ?>/staff/tasks/today.php">
                            <i class="fas fa-list-check me-2 text-warning"></i>Today's Tasks
                        </a>
                    </li>
                <?php endif; ?>
                <li>
                    <hr class="dropdown-divider">
                </li>
                <li>
                    <a class="dropdown-item text-danger" href="<?= APP_URL ?>/auth/logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </li>
            </ul>
        </div>

    </div><!-- topbar-right -->
</div><!-- topbar -->
<?php if (!empty($reminders)): ?>
<div id="followup-modal"
     style="position:fixed;inset:0;background:rgba(0,0,0,.6);
     z-index:999999;display:flex;align-items:center;justify-content:center;">

    <div style="width:420px;background:#fff;border-radius:14px;padding:1.5rem;">

        <h4 style="margin-bottom:1rem;">📌 Follow-up Reminder</h4>

        <?php foreach ($reminders as $r): ?>

            <?php $taskLink = $r['link']; ?>

            <div style="padding:.8rem;border:1px solid #eee;border-radius:10px;margin-bottom:10px;">

                <b><?= htmlspecialchars($r['title']) ?></b>

                <div style="font-size:.8rem;color:#555;margin-top:5px;">
                    <?= htmlspecialchars($r['message']) ?>
                </div>

                <div style="margin-top:10px;display:flex;gap:8px;">

                    <a href="<?= $taskLink ?>"
                       style="background:#c9a84c;color:#fff;
                       padding:6px 10px;border-radius:6px;text-decoration:none;">
                        Open Task
                    </a>

                    <button onclick="markReminderRead(<?= $r['id'] ?>)"
                            style="background:#f3f4f6;border:none;
                            padding:6px 10px;border-radius:6px;cursor:pointer;">
                        Mark as Read
                    </button>

                </div>
            </div>

        <?php endforeach; ?>

    </div>
</div>
<?php endif; ?>
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
        fetch('<?= APP_URL ?>/ajax/snooze_pw_reminder.php', { method: 'POST' }).catch(() => { });
    }
    function markReminderRead(id) {
        fetch("<?= APP_URL ?>/ajax/mark_notification_read.php", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "id=" + id
        }).then(() => {
            location.reload(); // modal updates automatically
        });
    }
    // ── Badge helper — single source of truth ────────────────────
    function setBadge(count) {
        const badge = document.getElementById('notif-count');
        const label = document.getElementById('notif-new-label');
        const btn = document.getElementById('mark-all-btn');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 9 ? '9+' : String(count);
            badge.style.display = 'flex';
            if (label) { label.textContent = count + ' new'; label.style.display = 'inline'; }
            if (btn) btn.style.display = 'inline';
        } else {
            badge.textContent = '';
            badge.style.display = 'none';
            if (label) label.style.display = 'none';
            if (btn) btn.style.display = 'none';
        }
    }

    // ── Toast helper ─────────────────────────────────────────────
    const shownNotifIds = new Set(
        // Pre-seed with IDs already rendered server-side so we don't re-toast them
        [...document.querySelectorAll('.notif-drop-item')].map(el => parseInt(el.id.replace('ndrop-', '')))
    );

    const typeMap = {
        task: { ic: 'fa-list-check', col: '#3b82f6', bg: '#dbeafe' },
        transfer: { ic: 'fa-exchange-alt', col: '#8b5cf6', bg: '#ede9fe' },
        status: { ic: 'fa-circle-dot', col: '#f59e0b', bg: '#fef3c7' },
        system: { ic: 'fa-gear', col: '#6b7280', bg: '#f3f4f6' },
        reminder: { ic: 'fa-bell', col: '#ef4444', bg: '#fee2e2' },
    };

    function showNotifToast(title, message, type) {
        const t = typeMap[type] || typeMap.system;
        const container = document.getElementById('notif-toast-container');
        if (!container) return;
        const toast = document.createElement('div');
        toast.style.cssText = `
        background:#fff;border-radius:12px;padding:.75rem 1rem;
        box-shadow:0 8px 30px rgba(0,0,0,.15);border-left:4px solid ${t.col};
        display:flex;gap:.75rem;align-items:flex-start;
        min-width:280px;max-width:340px;margin-bottom:.5rem;
        opacity:0;transform:translateX(20px);
        transition:opacity .3s,transform .3s;pointer-events:auto;
    `;
        toast.innerHTML = `
        <div style="width:32px;height:32px;border-radius:8px;background:${t.bg};
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas ${t.ic}" style="color:${t.col};font-size:.8rem;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            ${title ? `<div style="font-size:.8rem;font-weight:700;color:#111827;margin-bottom:.1rem;">${title}</div>` : ''}
            <div style="font-size:.78rem;color:#374151;line-height:1.4;">${message}</div>
        </div>
        <button onclick="this.closest('div').remove()"
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

    // ── Mark all read ─────────────────────────────────────────────
    function markAllReadDropdown(btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>';

        fetch('<?= APP_URL ?>/ajax/mark_notification_read.php', { method: 'POST' })
            .then(r => {
                // Guard against non-JSON response (redirect/HTML error page)
                const ct = r.headers.get('content-type') || '';
                if (!ct.includes('application/json')) {
                    throw new Error('Non-JSON response: ' + r.status);
                }
                return r.json();
            })
            .then(data => {
                if (!data.ok) {
                    console.error('mark-all-read error:', data.msg);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-double me-1"></i>Mark all read';
                    return;
                }
                // Reset dropdown row styles
                document.querySelectorAll('.notif-drop-item').forEach(el => {
                    el.style.background = '#fff';
                    el.style.borderLeftColor = 'transparent';
                });
                document.querySelectorAll('.ndrop-dot').forEach(el => el.remove());
                // Zero badge everywhere
                setBadge(0);
                btn.innerHTML = '<i class="fas fa-check me-1"></i>All read';
            })
            .catch(err => {
                console.error('mark-all-read fetch failed:', err);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-double me-1"></i>Mark all read';
            });
    }

    // ── Poll for new notifications ────────────────────────────────
    async function fetchNotifications() {
        try {
            const res = await fetch('<?= APP_URL ?>/includes/check_notifications.php');
            const data = await res.json();
            if (!data.ok) return;

            // Always sync badge to server count — this is the fix
            setBadge(data.unread ?? 0);

            if (!data.data?.length) return;

            data.data.forEach(n => {
                // Only toast truly new unread notifications we haven't seen yet
                if (n.is_read || shownNotifIds.has(n.id)) return;
                shownNotifIds.add(n.id);
                showNotifToast(n.title ?? '', n.message, n.type ?? 'system');

                // Prepend to dropdown list if not already there
                const list = document.getElementById('notif-dropdown-list');
                if (list && !document.getElementById('ndrop-' + n.id)) {
                    const t = typeMap[n.type] || typeMap.system;
                    const div = document.createElement('div');
                    div.className = 'notif-drop-item';
                    div.id = 'ndrop-' + n.id;
                    div.style.cssText = 'padding:.75rem 1rem;border-bottom:1px solid #f9fafb;border-left:3px solid #f59e0b;background:#fffdf5;';
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
                                <span class="ndrop-dot" style="display:inline-block;width:6px;height:6px;
                                    border-radius:50%;background:#f59e0b;margin-left:.4rem;vertical-align:middle;"></span>
                            </div>
                        </div>
                    </div>`;
                    // Remove empty state placeholder if present
                    const empty = document.getElementById('notif-empty-state');
                    if (empty) empty.remove();
                    list.prepend(div);
                }
            });

        } catch (err) {
            // silent — network error
        }
    }

    fetchNotifications();
    setInterval(fetchNotifications, 15000);
</script>