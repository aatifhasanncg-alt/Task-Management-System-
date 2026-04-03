<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
requireLogin();

$__u = currentUser();
$db = getDB();
$pageTitle = 'Notifications';

$search = $_GET['search'] ?? '';
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';

$where = "WHERE user_id = ?";
$params = [$__u['id']];

// Search filter
if (!empty($search)) {
    $where .= " AND (title LIKE ? OR message LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Date filters
if (!empty($fromDate)) {
    $where .= " AND DATE(created_at) >= ?";
    $params[] = $fromDate;
}

if (!empty($toDate)) {
    $where .= " AND DATE(created_at) <= ?";
    $params[] = $toDate;
}

try {
    $stmt = $db->prepare("
        SELECT * FROM notifications
        $where
        ORDER BY created_at DESC
    ");
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $notifications = [];
}

// Extract task_id from link field and batch-fetch task meta
$taskIds = [];
foreach ($notifications as $n) {
    if (!empty($n['link'])) {
        parse_str(parse_url($n['link'], PHP_URL_QUERY), $qs);
        $tid = (int) ($qs['id'] ?? 0);
        if ($tid)
            $taskIds[$n['id']] = $tid;
    }
}

$taskMeta = [];
if (!empty($taskIds)) {
    $unique = array_unique(array_values($taskIds));
    $placeholders = implode(',', array_fill(0, count($unique), '?'));
    try {
        $rows = $db->prepare("
            SELECT
                t.id, t.task_number, t.due_date, t.priority,
                ts.status_name,
                assigned.full_name AS assigned_to_name,
                creator.full_name  AS created_by_name
            FROM tasks t
            LEFT JOIN task_status ts ON ts.id = t.status_id
            LEFT JOIN users assigned ON assigned.id = t.assigned_to
            LEFT JOIN users creator  ON creator.id  = t.created_by
            WHERE t.id IN ($placeholders)
        ");
        $rows->execute($unique);
        $taskRows = [];
        foreach ($rows->fetchAll() as $r)
            $taskRows[$r['id']] = $r;
        foreach ($taskIds as $nid => $tid) {
            if (isset($taskRows[$tid]))
                $taskMeta[$nid] = $taskRows[$tid];
        }
    } catch (Exception $e) {
    }
}

// Type → icon, colour, bg, label
$typeMap = [
    'task' => ['fa-list-check', '#3b82f6', '#dbeafe', 'Task'],
    'transfer' => ['fa-exchange-alt', '#8b5cf6', '#ede9fe', 'Transfer'],
    'status' => ['fa-circle-dot', '#f59e0b', '#fef3c7', 'Status'],
    'system' => ['fa-gear', '#6b7280', '#f3f4f6', 'System'],
    'reminder' => ['fa-bell', '#ef4444', '#fee2e2', 'Reminder'],
];

$priorityColor = [
    'urgent' => ['#fef2f2', '#ef4444'],
    'high' => ['#fffbeb', '#f59e0b'],
    'medium' => ['#eff6ff', '#3b82f6'],
    'low' => ['#f9fafb', '#9ca3af'],
];

$unreadCount = count(array_filter($notifications, fn($n) => !$n['is_read']));

// Rewrite link based on viewer's role so admin stays in /admin/tasks/view.php
// and staff stays in /staff/tasks/view.php
$viewerRole = $__u['role'] ?? 'staff';
function rewriteNotifLink(string $link, string $role): string
{
    if (empty($link))
        return $link;
    // Replace any role path segment with the correct one for this viewer
    $corrected = preg_replace(
        '#/(staff|admin|executive)/tasks/(view|index)\.php#',
        '/' . $role . '/tasks/$2.php',
        $link
    );
    return $corrected ?: $link;
}

include __DIR__ . '/header.php';

$role = $__u['role'] ?? 'staff';
switch ($role) {
    case 'admin':
        include __DIR__ . '/sidebar_admin.php';
        break;
    case 'executive':
        include __DIR__ . '/sidebar_executive.php';
        break;
    default:
        include __DIR__ . '/sidebar_staff.php';
        break;
}
?>

<div class="main-content">
    <?php include __DIR__ . '/topbar.php'; ?>
    <div style="padding:1.5rem 0;">

        <!-- Page Hero -->
        <div class="page-hero">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <div class="page-hero-badge"><i class="fas fa-bell"></i> Notifications</div>
                    <h4 class="mb-1">Notifications</h4>
                    <p class="mb-0">
                        <?php if ($unreadCount): ?>
                            <span style="color:#f59e0b;font-weight:700;"><?= $unreadCount ?> unread</span>
                            <span style="color:#9ca3af;"> · </span>
                        <?php endif; ?>
                        <span style="color:#6b7280;"><?= count($notifications) ?> total</span>
                    </p>
                </div>

                <?php if ($unreadCount): ?>
                    <button onclick="markAllRead(this)" class="btn btn-gold btn-sm">
                        <i class="fas fa-check-double me-1"></i>Mark all read
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="filter-bar mb-4 w-100">
            <form method="GET" class="row g-2 align-items-end w-100">

                <!-- Search -->
                <div class="col-md-3">
                    <label class="form-label-mis">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                        placeholder="Search notifications..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>

                <!-- From Date -->
                <div class="col-md-3">
                    <label class="form-label-mis">From Date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                        value="<?= htmlspecialchars($_GET['from_date'] ?? '') ?>">
                </div>
                <!-- To Date -->
                <div class="col-md-3">
                    <label class="form-label-mis">To Date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                        value="<?= htmlspecialchars($_GET['to_date'] ?? '') ?>">
                </div>
                <!-- Filter Button -->
                <div class="col-md-1 gap-1">
                    <button type="submit"
                        class="btn btn-gold btn-sm w-100 d-flex align-items-center justify-content-center gap-1">
                        <i class="fas fa-filter"></i>
                        <span>Filter</span>
                    </button>
                </div>

                <!-- Reset -->
                <div class="col-md-1 gap-1">
                    <a href="<?= $_SERVER['PHP_SELF'] ?>"
                        class="btn btn-outline-secondary btn-sm w-100 d-flex align-items-center justify-content-center gap-1">
                        <i class="fa-solid fa-rotate-left"></i>
                        <span>Reset</span>
                    </a>
                </div>

            </form>
        </div>
        <!-- Card -->
        <div class="card-mis">
            <div class="card-mis-header">
                <h5><i class="fas fa-bell text-warning me-2"></i>All Notifications</h5>
            </div>

            <?php if (empty($notifications)): ?>
                <div style="padding:4rem 2rem;text-align:center;">
                    <div style="width:64px;height:64px;background:#f3f4f6;border-radius:50%;
                    display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                        <i class="fas fa-bell-slash" style="font-size:1.4rem;color:#d1d5db;"></i>
                    </div>
                    <p style="font-size:.9rem;color:#6b7280;font-weight:500;margin:0;">
                        You're all caught up — no notifications yet.
                    </p>
                </div>

            <?php else: ?>
                <?php foreach ($notifications as $n):
                    [$ic, $col, $bg, $typeLabel] = $typeMap[$n['type']] ?? $typeMap['system'];
                    $unread = !$n['is_read'];
                    $meta = $taskMeta[$n['id']] ?? null;
                    $overdue = $meta && !empty($meta['due_date'])
                        && strtotime($meta['due_date']) < time()
                        && $meta['status_name'] !== 'Done';
                    $dueToday = $meta && !empty($meta['due_date'])
                        && date('Y-m-d', strtotime($meta['due_date'])) === date('Y-m-d');
                    ?>
                    <div class="notif-item" id="notif-<?= $n['id'] ?>" style="padding:1.1rem 1.4rem;
                border-bottom:1px solid #f3f4f6;
                border-left:4px solid <?= $unread ? '#f59e0b' : 'transparent' ?>;
                background:<?= $unread ? '#fffdf5' : 'transparent' ?>;
                transition:background .25s, border-left-color .25s;">

                        <div style="display:flex;gap:1rem;align-items:flex-start;">

                            <!-- Icon bubble -->
                            <div style="width:42px;height:42px;flex-shrink:0;border-radius:12px;
                        background:<?= $bg ?>;
                        display:flex;align-items:center;justify-content:center;">
                                <i class="fas <?= $ic ?>" style="color:<?= $col ?>;font-size:.95rem;"></i>
                            </div>

                            <!-- Content -->
                            <div style="flex:1;min-width:0;">

                                <!-- Title + type chip + unread dot -->
                                <div style="display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;margin-bottom:.25rem;">
                                    <?php if (!empty($n['title'])): ?>
                                        <span style="font-size:.88rem;font-weight:700;color:#111827;">
                                            <?= htmlspecialchars($n['title']) ?>
                                        </span>
                                    <?php endif; ?>

                                    <span style="font-size:.65rem;font-weight:700;text-transform:uppercase;
                                 letter-spacing:.05em;padding:.12rem .5rem;border-radius:99px;
                                 background:<?= $bg ?>;color:<?= $col ?>;">
                                        <?= $typeLabel ?>
                                    </span>

                                    <?php if ($unread): ?>
                                        <span class="notif-dot" style="width:7px;height:7px;border-radius:50%;
                                 background:#f59e0b;display:inline-block;flex-shrink:0;"></span>
                                    <?php endif; ?>
                                </div>

                                <!-- Message -->
                                <div style="font-size:.875rem;color:#374151;line-height:1.55;
                            font-weight:<?= $unread ? '500' : '400' ?>;margin-bottom:.45rem;">
                                    <?php
                                    $notifLink = rewriteNotifLink($n['link'] ?? '', $viewerRole);
                                    ?>
                                    <?php if (!empty($notifLink)): ?>
                                        <a href="<?= htmlspecialchars($notifLink) ?>" style="color:inherit;text-decoration:none;"
                                            onmouseover="this.style.color='#c9a84c'" onmouseout="this.style.color='inherit'">
                                            <?= htmlspecialchars($n['message']) ?>
                                            <i class="fas fa-arrow-up-right-from-square"
                                                style="font-size:.6rem;margin-left:.3rem;opacity:.4;vertical-align:middle;"></i>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($n['message']) ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Task meta chips -->
                                <?php if ($meta): ?>
                                    <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-bottom:.4rem;">

                                        <span style="font-size:.7rem;font-weight:600;
                                 background:#f3f4f6;color:#6b7280;
                                 padding:.15rem .55rem;border-radius:5px;">
                                            <i class="fas fa-hashtag" style="font-size:.6rem;opacity:.7;"></i>
                                            <?= htmlspecialchars($meta['task_number']) ?>
                                        </span>

                                        <?php if (!empty($meta['assigned_to_name'])): ?>
                                            <span style="font-size:.7rem;background:#eff6ff;color:#2563eb;
                                 padding:.15rem .55rem;border-radius:5px;">
                                                <i class="fas fa-user" style="font-size:.6rem;margin-right:.25rem;"></i>
                                                <?= htmlspecialchars($meta['assigned_to_name']) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($meta['due_date'])): ?>
                                            <span style="font-size:.7rem;font-weight:600;padding:.15rem .55rem;border-radius:5px;
                                 <?php if ($overdue): ?>background:#fef2f2;color:#dc2626;
                                 <?php elseif ($dueToday): ?>background:#fffbeb;color:#d97706;
                                 <?php else: ?>background:#f0fdf4;color:#16a34a;<?php endif; ?>">
                                                <i class="fas fa-calendar-day" style="font-size:.6rem;margin-right:.25rem;"></i>
                                                <?php if ($overdue): ?>Overdue · <?= date('M j', strtotime($meta['due_date'])) ?>
                                                <?php elseif ($dueToday): ?>Due today
                                                <?php else: ?>Due <?= date('M j, Y', strtotime($meta['due_date'])) ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($meta['priority'])):
                                            [$pbg, $pcol] = $priorityColor[$meta['priority']] ?? ['#f3f4f6', '#6b7280'];
                                            ?>
                                            <span style="font-size:.7rem;font-weight:700;text-transform:capitalize;
                                 padding:.15rem .55rem;border-radius:5px;
                                 background:<?= $pbg ?>;color:<?= $pcol ?>;">
                                                <?= htmlspecialchars($meta['priority']) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($meta['status_name'])): ?>
                                            <span style="font-size:.7rem;background:#f9fafb;color:#6b7280;
                                 padding:.15rem .55rem;border-radius:5px;border:1px solid #e5e7eb;">
                                                <?= htmlspecialchars($meta['status_name']) ?>
                                            </span>
                                        <?php endif; ?>

                                    </div>
                                <?php endif; ?>

                                <!-- Timestamp -->
                                <div style="font-size:.72rem;color:#9ca3af;">
                                    <i class="fas fa-clock me-1"></i>
                                    <?= date('M j, Y · g:i A', strtotime($n['created_at'])) ?>
                                </div>

                            </div><!-- content -->
                        </div><!-- flex -->
                    </div><!-- notif-item -->
                <?php endforeach; ?>
            <?php endif; ?>

        </div><!-- card-mis -->
    </div><!-- padding -->
</div><!-- main-content -->

<script>
    function markAllRead(btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Marking...';

        fetch('<?= APP_URL ?>/ajax/mark_notification_read.php', { method: 'POST' })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-double me-1"></i>Mark all read';
                    return;
                }
                // Reset row styles
                document.querySelectorAll('.notif-item').forEach(el => {
                    el.style.background = 'transparent';
                    el.style.borderLeftColor = 'transparent';
                });
                document.querySelectorAll('.notif-dot').forEach(el => el.remove());

                // Sync topbar badge immediately — don't wait for next poll
                if (typeof setBadge === 'function') setBadge(0);

                btn.innerHTML = '<i class="fas fa-check me-1"></i>All read';
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-double me-1"></i>Mark all read';
            });
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>