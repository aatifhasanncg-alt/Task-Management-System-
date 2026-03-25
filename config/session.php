<?php
declare(strict_types=1);

// ── SESSION CONFIG — 5-day lifetime ──────────────────────────
$sessionLifetime = 5 * 24 * 60 * 60; // 5 days in seconds

ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
ini_set('session.cookie_lifetime', (string)$sessionLifetime);

session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── AUTO-LOGOUT after 5 days of inactivity ────────────────────
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $sessionLifetime) {
        session_unset();
        session_destroy();
        header('Location: /auth/login.php?reason=session_expired');
        exit;
    }
}
$_SESSION['last_activity'] = time();

// ── LOGIN & ROLE CHECKS ──────────────────────────────────────

function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /auth/login.php');
        exit;
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        header('Location: /auth/login.php?error=unauthorized');
        exit;
    }
}

function requireExecutive(): void { requireRole('executive'); }
function requireAdmin(): void     { requireRole('admin', 'executive'); }
function requireAnyRole(): void   { requireRole('executive', 'admin', 'staff'); }

// ── CURRENT USER ─────────────────────────────────────────────

function currentUser(): array {
    $user = $_SESSION['user'] ?? [];
    return [
        'id'        => $user['id']        ?? 0,
        'username'  => $user['username']  ?? 'admin',
        'full_name' => $user['full_name'] ?? 'Admin User',
        'role'      => $user['role']      ?? 'admin',
        'role_id'   => $user['role_id']   ?? 1,
    ];
}

function isExecutive(): bool { return ($_SESSION['role'] ?? '') === 'executive'; }
function isAdmin(): bool     { return ($_SESSION['role'] ?? '') === 'admin'; }
function isStaff(): bool     { return ($_SESSION['role'] ?? '') === 'staff'; }

function isCoreAdmin(): bool {
    $user = currentUser();
    if (!$user['id']) return false;
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT d.dept_code FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.id = ?
        ");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        return ($row['dept_code'] ?? '') === 'CORE';
    } catch (Exception $e) {
        return false;
    }
}

// ── PASSWORD CHANGE REMINDER ──────────────────────────────────
// Returns true if user hasn't changed password in 30+ days

function shouldPromptPasswordChange(): bool {
    $user = currentUser();
    if (!$user['id']) return false;

    // Only check once per session per day to avoid repeated DB hits
    $cacheKey = 'pw_check_' . date('Y-m-d');
    if (isset($_SESSION[$cacheKey])) return $_SESSION[$cacheKey];

    try {
        $db   = getDB();
        // Last password change for this user
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
            $result = $daysSince >= 30;
        } else {
            // No change log at all — check account creation date
            $stmt2 = $db->prepare("SELECT created_at FROM users WHERE id = ?");
            $stmt2->execute([$user['id']]);
            $created = $stmt2->fetchColumn();
            $daysSince = $created ? (time() - strtotime($created)) / 86400 : 0;
            $result = $daysSince >= 30;
        }

        $_SESSION[$cacheKey] = $result;
        return $result;
    } catch (Exception $e) {
        return false;
    }
}

// ── ADMIN SCOPE CHECKS ────────────────────────────────────────

function adminCanAccessDept(int $deptId): bool {
    if (isExecutive()) return true;
    return in_array($deptId, $_SESSION['allowed_depts'] ?? [], true);
}

function adminCanAccessBranch(int $branchId): bool {
    if (isExecutive()) return true;
    return in_array($branchId, $_SESSION['allowed_branches'] ?? [], true);
}

function adminOwnsStaff(int $staffId): bool {
    if (isExecutive()) return true;
    require_once __DIR__ . '/db.php';
    $db  = getDB();
    $uid = currentUser()['id'];
    $st  = $db->prepare("SELECT id FROM users WHERE id = ? AND managed_by = ? LIMIT 1");
    $st->execute([$staffId, $uid]);
    return (bool) $st->fetch();
}

// ── CSRF ─────────────────────────────────────────────────────

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('CSRF validation failed.');
    }
}

// ── FLASH MESSAGES ───────────────────────────────────────────

function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function flashHtml(): string {
    $f = getFlash();
    if (!$f) return '';
    $map = [
        'success' => 'alert-success',
        'error'   => 'alert-danger',
        'info'    => 'alert-info',
        'warning' => 'alert-warning',
    ];
    $cls = $map[$f['type']] ?? 'alert-info';
    $msg = htmlspecialchars($f['msg']);
    return "<div class='alert {$cls} alert-dismissible fade show' role='alert'>
        {$msg}
        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
    </div>";
}

// ── ACTIVITY LOG ─────────────────────────────────────────────

function logActivity(string $action, string $module = '', string $details = ''): void {
    try {
        require_once __DIR__ . '/db.php';
        $db  = getDB();
        $uid = currentUser()['id'];
        if (!$uid) return;
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $db->prepare(
            "INSERT INTO activity_logs (user_id, action, module, details, ip_address)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$uid, $action, $module, $details, $ip]);
    } catch (Exception $e) {
        // silent
    }
}