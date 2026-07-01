<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_token.php';

// ── SESSION CONFIG ────────────────────────────────────────────
ini_set('session.gc_maxlifetime', '86400'); // 1 day server-side
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── STEP 1: Auto-login via remember token FIRST ───────────────
tryAutoLogin();

// ── LOGIN & ROLE CHECKS ───────────────────────────────────────

function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rtrim(APP_URL, '/') . '/auth/login.php');
        exit;
    }
}

function requireRole(string ...$roles): void
{
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        header('Location: ' . rtrim(APP_URL, '/') . '/auth/login.php?error=unauthorized');
        exit;
    }
    // ── Update active_at on every authenticated page load ──
    try {
        updateActiveAt(getDB(), (int) $_SESSION['user_id']);
    } catch (Exception $e) {
    }
}

function requireExecutive(): void
{
    requireRole('executive');
}
function requireAdmin(): void
{
    requireRole('admin');
}
function requireManager(): void
{
    requireRole('manager');
}
function requireAnyRole(): void
{
    requireRole('executive', 'admin', 'manager', 'staff');
}

function getClientIp(): string
{
    if (defined('TRUSTED_PROXY') && TRUSTED_PROXY) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_CLIENT_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    if (str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function updateActiveAt(PDO $db, int $userId): void
{
    try {
        $db->prepare("UPDATE users SET active_at = NOW() WHERE id = ?")
            ->execute([$userId]);
    } catch (Exception $e) {
        error_log('[auth:updateActiveAt] ' . $e->getMessage());
        return;
    }
}
// ── CURRENT USER ─────────────────────────────────────────────

function currentUser(): array
{
    $sessionUser = $_SESSION['user'] ?? [];
    $uid = $sessionUser['id'] ?? 0;

    if (!$uid)
        return [];

    try {
        $db = getDB();
        $st = $db->prepare("
            SELECT u.id, u.username, u.full_name,
                   u.role_id, r.role_name,
                   u.branch_id, u.department_id
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?
        ");
        $st->execute([$uid]);
        $user = $st->fetch(PDO::FETCH_ASSOC);

        return $user ?: [];
    } catch (Exception $e) {
        error_log('[auth:currentUser] ' . $e->getMessage());
        return [];
    }
}

function isExecutive(): bool
{
    return ($_SESSION['role'] ?? '') === 'executive';
}
function isAdmin(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}
function isManager(): bool
{
    return ($_SESSION['role'] ?? '') === 'manager';
}
function isStaff(): bool
{
    return ($_SESSION['role'] ?? '') === 'staff';
}

function isCoreAdmin(): bool
{
    $user = currentUser();
    if (empty($user['id']))
        return false;
    try {
        return hasAdminDeptAccess(getDB(), (int) $user['id']);
    } catch (Exception $e) {
        error_log('[auth:isCoreAdmin] ' . $e->getMessage());
        return false;
    }
}
/**
 * Determine if a user has branch-wide admin access (CORE or ADM department),
 * checking BOTH their primary department (users.department_id) and any
 * department assigned via user_department_assignments (UDA).
 *
 * @param PDO $db
 * @param int|null $userId Defaults to the logged-in user.
 * @return bool
 */
function hasAdminDeptAccess(PDO $db, ?int $userId = null): bool
{
    $userId = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    if (!$userId) {
        return false;
    }

    $stmt = $db->prepare("
        SELECT d.dept_code
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $primaryCode = $stmt->fetchColumn() ?: '';

    $udaStmt = $db->prepare("
        SELECT d.dept_code
        FROM user_department_assignments uda
        JOIN departments d ON d.id = uda.department_id
        WHERE uda.user_id = ?
    ");
    $udaStmt->execute([$userId]);
    $udaCodes = array_column($udaStmt->fetchAll(PDO::FETCH_ASSOC), 'dept_code');

    $allCodes = array_merge([$primaryCode], $udaCodes);

    return !empty(array_intersect(['CORE', 'ADM'], $allCodes));
}
// ── PASSWORD CHANGE REMINDER ──────────────────────────────────
// Returns true if user hasn't changed password in 30+ days

function shouldPromptPasswordChange(): bool
{
    $user = currentUser();
    if (empty($user['id']))
        return false;

    // ✅ 1. Snooze override (PUT THIS FIRST)
    $cacheKey = 'pw_check_' . date('Y-m-d');
    if (!empty($_SESSION[$cacheKey])) {
        return false; // user snoozed today
    }

    try {
        $db = getDB();

        // Last password change
        $stmt = $db->prepare("
            SELECT changed_at FROM password_change_logs
            WHERE changed_for = ?
            ORDER BY changed_at DESC
            LIMIT 1
        ");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetchColumn();

        if ($row) {
            $daysSince = (time() - strtotime($row)) / 86400;
            return $daysSince >= 30;
        }

        // No log → check user creation date
        $stmt2 = $db->prepare("SELECT created_at FROM users WHERE id = ?");
        $stmt2->execute([$user['id']]);
        $created = $stmt2->fetchColumn();

        $daysSince = $created ? (time() - strtotime($created)) / 86400 : 0;
        return $daysSince >= 30;

    } catch (Exception $e) {
        error_log('[auth:shouldPromptPasswordChange] ' . $e->getMessage());
        return false;
    }
}

// ── ADMIN SCOPE CHECKS ────────────────────────────────────────
function requireExecutiveOrBM(): void
{
    if (isExecutive())
        return;

    if (function_exists('isAdmin') && isAdmin()) {
        $db = getDB();
        $user = currentUser();
        $stmt = $db->prepare("
            SELECT d.dept_code
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.id = ?
        ");
        $stmt->execute([$user['id'] ?? 0]);
        $deptCode = $stmt->fetchColumn() ?: '';
        if ($deptCode === 'CORE')
            return;
    }

    // Not authorized
    http_response_code(403);
    // Try to show a nice error page if header.php exists
    if (file_exists(__DIR__ . '/../includes/header.php')) {
        $pageTitle = 'Access Denied';
        include __DIR__ . '/../includes/header.php';
        echo '<div class="app-wrapper"><div class="main-content" style="display:flex;align-items:center;justify-content:center;height:100vh;">
            <div style="text-align:center;color:#9ca3af;">
                <i class="fas fa-lock fa-3x mb-3 d-block" style="color:#ef4444;"></i>
                <h4 style="color:#1f2937;">Access Denied</h4>
                <p>You do not have permission to view this page.</p>
                <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">Go Back</a>
            </div>
        </div></div>';
        include __DIR__ . '/../includes/footer.php';
    } else {
        echo '<h1>403 Access Denied</h1><p>You do not have permission to view this page.</p>';
    }
    exit;
}
function adminCanAccessDept(int $deptId): bool
{
    if (isExecutive())
        return true;
    return in_array($deptId, $_SESSION['allowed_depts'] ?? [], true);
}

function adminCanAccessBranch(int $branchId): bool
{
    if (isExecutive())
        return true;
    return in_array($branchId, $_SESSION['allowed_branches'] ?? [], true);
}

function adminOwnsStaff(int $staffId): bool
{
    if (isExecutive())
        return true;
    require_once __DIR__ . '/db.php';
    $db = getDB();
    $uid = currentUser()['id'];
    $st = $db->prepare("SELECT id FROM users WHERE id = ? AND managed_by = ? LIMIT 1");
    $st->execute([$staffId, $uid]);
    return (bool) $st->fetch();
}

// ── CSRF ─────────────────────────────────────────────────────

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('CSRF validation failed.');
    }
}

// ── FLASH MESSAGES ───────────────────────────────────────────

function setFlash(string $type, string $msg, bool $raw = false): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg, 'raw' => $raw];
}

function getFlash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function flashHtml(): string
{
    $map = [
        'success' => 'alert-success',
        'danger' => 'alert-danger',
        'error' => 'alert-danger',
        'info' => 'alert-info',
        'warning' => 'alert-warning',
    ];

    $out = '';

    // ── Primary flash ─────────────────────────────────────────
    $f = getFlash();
    if ($f) {
        $cls = $map[$f['type']] ?? 'alert-info';
        $msg = !empty($f['raw']) ? $f['msg'] : htmlspecialchars($f['msg']);
        $out .= "<div class='alert {$cls} alert-dismissible fade show' role='alert'>
            {$msg}
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    }

    // ── Secondary flash (partial duplicate warnings) ──────────
    if (isset($_SESSION['flash_extra'])) {
        $fe = $_SESSION['flash_extra'];
        unset($_SESSION['flash_extra']);
        $cls = $map[$fe['type']] ?? 'alert-warning';
        $msg = !empty($fe['raw']) ? $fe['msg'] : htmlspecialchars($fe['msg']);
        $out .= "<div class='alert {$cls} alert-dismissible fade show' role='alert'>
            {$msg}
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    }

    return $out;
}

// ── ACTIVITY LOG ─────────────────────────────────────────────

function logActivity(string $action, string $module = '', string $details = ''): void
{
    try {
        require_once __DIR__ . '/db.php';
        $db = getDB();
        $uid = currentUser()['id'];
        if (!$uid)
            return;
        $ip = getClientIp();
        $db->prepare(
            "INSERT INTO activity_logs (user_id, action, module, details, ip_address)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$uid, $action, $module, $details, $ip]);
    } catch (Exception $e) {
        error_log('[auth:logActivity] ' . $e->getMessage());
        return;
    }
}
// Add this helper at the top of both view files, after the require_once lines
function formatLastSeen(?string $activeAt, ?string $lastLogin): array
{
    // Prefer active_at (real-time), fall back to last_login
    $time = $activeAt ?? $lastLogin;
    if (!$time)
        return ['label' => 'Never seen', 'color' => '#9ca3af', 'dot' => '#9ca3af', 'online' => false];

    $diff = time() - strtotime($time);

    if ($diff < 300)
        return ['label' => 'Online now', 'color' => '#10b981', 'dot' => '#10b981', 'online' => true];
    if ($diff < 3600)
        return ['label' => round($diff / 60) . 'm ago', 'color' => '#f59e0b', 'dot' => '#f59e0b', 'online' => false];
    if ($diff < 86400)
        return ['label' => round($diff / 3600) . 'h ago', 'color' => '#6b7280', 'dot' => '#9ca3af', 'online' => false];
    if ($diff < 604800)
        return ['label' => round($diff / 86400) . 'd ago', 'color' => '#9ca3af', 'dot' => '#9ca3af', 'online' => false];
    return ['label' => date('d M Y', strtotime($time)), 'color' => '#9ca3af', 'dot' => '#d1d5db', 'online' => false];
}