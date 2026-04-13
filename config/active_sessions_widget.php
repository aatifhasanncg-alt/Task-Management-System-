<?php
// config/active_sessions_widget.php
// Include this anywhere in any role's dashboard/profile

function getActiveSessions(): array
{
    $db = getDB();
    $uid = currentUser()['id'];

    $stmt = $db->prepare("
        SELECT id, device_name, browser, os, ip_address,
               last_used, created_at, token
        FROM   remember_tokens
        WHERE  user_id = ?
        ORDER  BY last_used DESC
    ");
    $stmt->execute([$uid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function handleSessionRevoke(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        return;
    if (empty($_POST['session_action']))
        return;

    verifyCsrf();

    $db = getDB();
    $uid = currentUser()['id'];

    if ($_POST['session_action'] === 'revoke_one') {
        $db->prepare("
            DELETE FROM remember_tokens WHERE id = ? AND user_id = ?
        ")->execute([(int) $_POST['token_id'], $uid]);
        setFlash('success', 'Device logged out successfully.');
    }

     if ($_POST['session_action'] === 'revoke_all') {
            $currentHash = hash('sha256', $_COOKIE[REMEMBER_COOKIE] ?? '');
            $db->prepare("
                DELETE FROM remember_tokens WHERE user_id = ? AND token != ?
            ")->execute([$uid, $currentHash]);
            setFlash('success', 'All other devices have been logged out.');
        }
    // Logout current device = full logout
    if ($_POST['session_action'] === 'logout_current') {
        clearRememberToken();   // delete token from DB + clear cookie
        session_unset();
        session_destroy();
        header('Location: /auth/login.php');
        exit;
    }

    // Redirect back to same page
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

function renderSessionsWidget(): void
{
    $sessions = getActiveSessions();
    $currentHash = hash('sha256', $_COOKIE[REMEMBER_COOKIE] ?? '');
    $count = count($sessions);
    $otherCount = 0;

    foreach ($sessions as $s) {
        if (hash('sha256', $_COOKIE[REMEMBER_COOKIE] ?? '') !== $s['token']) {
            $otherCount++;
        }
    }
    ?>
    <div class="sessions-widget">

        <!-- Header -->
        <div class="sw-header">
            <div class="sw-title">
                <i class="fas fa-shield-alt"></i>
                Active Sessions
                <span class="sw-badge"><?= $count ?></span>
            </div>
            <?php if ($otherCount > 0): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="session_action" value="revoke_all">
                    <button class="sw-revoke-all" onclick="return confirm('Log out all other devices?')">
                        <i class="fas fa-sign-out-alt"></i> Logout all others
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Session list -->
        <div class="sw-list">
            <?php if (empty($sessions)): ?>
                <div class="sw-empty">No active sessions found.</div>
            <?php else: ?>
                <?php foreach ($sessions as $s):
                    $isCurrent = (hash('sha256', $_COOKIE[REMEMBER_COOKIE] ?? '') === $s['token']);
                    $isPhone = in_array($s['os'] ?? '', ['iPhone', 'Android']);
                    $icon = $isPhone ? 'fa-mobile-alt' : 'fa-desktop';

                    // Time ago helper
                    $diff = time() - strtotime($s['last_used']);
                    $timeAgo = match (true) {
                        $diff < 60 => 'Just now',
                        $diff < 3600 => floor($diff / 60) . 'm ago',
                        $diff < 86400 => floor($diff / 3600) . 'h ago',
                        default => floor($diff / 86400) . 'd ago',
                    };
                    ?>
                    <div class="sw-item <?= $isCurrent ? 'sw-current' : '' ?>">

                        <div class="sw-icon <?= $isCurrent ? 'sw-icon-active' : '' ?>">
                            <i class="fas <?= $icon ?>"></i>
                        </div>

                        <div class="sw-info">
                            <div class="sw-device">
                                <?= htmlspecialchars($s['device_name'] ?? 'Unknown Device') ?>
                                <?php if ($isCurrent): ?>
                                    <span class="sw-current-badge">Current</span>
                                <?php endif; ?>
                            </div>
                            <div class="sw-meta">
                                <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($s['ip_address']) ?></span>
                                <span><i class="fas fa-clock"></i> <?= $timeAgo ?></span>
                                <span><i class="fas fa-calendar"></i> Since
                                    <?= date('M d, Y', strtotime($s['created_at'])) ?></span>
                            </div>
                        </div>

                        <?php if (!$isCurrent): ?>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="session_action" value="revoke_one">
                                <input type="hidden" name="token_id" value="<?= $s['id'] ?>">
                                <button class="sw-revoke" onclick="return confirm('Log out this device?')" title="Logout this device">
                                    <i class="fas fa-times"></i>
                                </button>
                            </form>
                        <?php else: ?>
                        <div class="d-flex align-items-center gap-2">
                            <span class="sw-this">This device</span>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="csrf_token"     value="<?= csrfToken() ?>">
                                <input type="hidden" name="session_action" value="logout_current">
                                <button class="sw-revoke sw-revoke-current"
                                        onclick="return confirm('This will log you out completely. Continue?')"
                                        title="Logout from this device">
                                    <i class="fas fa-sign-out-alt"></i>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

    <style>
        .sessions-widget {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
        }

        .card-mis-body .sessions-widget {
            border: none;
            border-radius: 0;
        }

        .sw-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: .9rem 1.25rem;
            border-bottom: 1px solid #f3f4f6;
            background: #fafafa;
        }

        .sw-title {
            font-weight: 600;
            font-size: .9rem;
            color: #111827;
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .sw-title i {
            color: #c9a84c;
        }

        .sw-badge {
            background: #f3f4f6;
            color: #6b7280;
            border-radius: 99px;
            padding: 1px 8px;
            font-size: .75rem;
            font-weight: 600;
        }

        .sw-revoke-all {
            background: none;
            border: 1px solid #fca5a5;
            color: #dc2626;
            border-radius: 7px;
            padding: .3rem .75rem;
            font-size: .78rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: .35rem;
            transition: all .2s;
        }

        .sw-revoke-all:hover {
            background: #fef2f2;
        }

        .sw-list {
            padding: .5rem 0;
        }

        .sw-empty {
            text-align: center;
            color: #9ca3af;
            padding: 1.5rem;
            font-size: .85rem;
        }

        .sw-item {
            display: flex;
            align-items: center;
            gap: .85rem;
            padding: .7rem 1.25rem;
            transition: background .15s;
        }

        .sw-item:hover {
            background: #f9fafb;
        }

        .sw-item.sw-current {
            background: #fffdf5;
        }

        .sw-icon {
            width: 38px;
            height: 38px;
            border-radius: 9px;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            font-size: .95rem;
            flex-shrink: 0;
        }

        .sw-icon-active {
            background: #fef3c7;
            color: #c9a84c;
        }

        .sw-info {
            flex: 1;
            min-width: 0;
        }

        .sw-device {
            font-size: .85rem;
            font-weight: 600;
            color: #111827;
            display: flex;
            align-items: center;
            gap: .4rem;
        }

        .sw-current-badge {
            background: #c9a84c;
            color: #0a0f1e;
            font-size: .65rem;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 99px;
        }

        .sw-meta {
            display: flex;
            gap: .75rem;
            margin-top: 2px;
            font-size: .74rem;
            color: #9ca3af;
            flex-wrap: wrap;
        }

        .sw-meta i {
            margin-right: 2px;
        }

        .sw-revoke {
            background: none;
            border: 1px solid #e5e7eb;
            color: #9ca3af;
            border-radius: 7px;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            transition: all .2s;
        }

        .sw-revoke:hover {
            border-color: #fca5a5;
            color: #dc2626;
            background: #fef2f2;
        }
        .sw-revoke-current { border-color: #fde68a; color: #c9a84c; }
        .sw-revoke-current:hover { border-color: #c9a84c; color: #92400e; background: #fffbeb; }

        .sw-this {
            font-size: .72rem;
            color: #c9a84c;
            font-weight: 600;
            flex-shrink: 0;
        }
    </style>
    <?php
}