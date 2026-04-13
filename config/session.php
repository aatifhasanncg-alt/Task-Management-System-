<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_token.php';

// ── SESSION CONFIG ────────────────────────────────────────────
ini_set('session.gc_maxlifetime', '86400'); // 1 day server-side
session_set_cookie_params([
    'lifetime' => 0,          // browser session cookie is fine —
    'path'     => '/',        // remember_token handles persistence
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── STEP 1: Auto-login via remember token FIRST ───────────────
tryAutoLogin();

// ── LOGIN & ROLE CHECKS ───────────────────────────────────────

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
    // ── Update active_at on every authenticated page load ──
    try {
        updateActiveAt(getDB(), (int)$_SESSION['user_id']);
    } catch (Exception $e) {}
}

function requireExecutive(): void { requireRole('executive'); }
function requireAdmin(): void     { requireRole('admin', 'executive'); }
function requireAnyRole(): void   { requireRole('executive', 'admin', 'staff'); }

function getClientIp(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
          ?? $_SERVER['HTTP_CLIENT_IP']
          ?? $_SERVER['REMOTE_ADDR']
          ?? '0.0.0.0';
    if (str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function updateActiveAt(PDO $db, int $userId): void {
    try {
        $db->prepare("UPDATE users SET active_at = NOW() WHERE id = ?")
           ->execute([$userId]);
    } catch (Exception $e) {}
}
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
// Add this helper at the top of both view files, after the require_once lines
function formatLastSeen(?string $activeAt, ?string $lastLogin): array {
    // Prefer active_at (real-time), fall back to last_login
    $time = $activeAt ?? $lastLogin;
    if (!$time) return ['label' => 'Never seen', 'color' => '#9ca3af', 'dot' => '#9ca3af', 'online' => false];

    $diff = time() - strtotime($time);

    if ($diff < 300)       return ['label' => 'Online now',         'color' => '#10b981', 'dot' => '#10b981', 'online' => true];
    if ($diff < 3600)      return ['label' => round($diff/60) . 'm ago',  'color' => '#f59e0b', 'dot' => '#f59e0b', 'online' => false];
    if ($diff < 86400)     return ['label' => round($diff/3600) . 'h ago', 'color' => '#6b7280', 'dot' => '#9ca3af', 'online' => false];
    if ($diff < 604800)    return ['label' => round($diff/86400) . 'd ago', 'color' => '#9ca3af', 'dot' => '#9ca3af', 'online' => false];
    return ['label' => date('d M Y', strtotime($time)), 'color' => '#9ca3af', 'dot' => '#d1d5db', 'online' => false];
}