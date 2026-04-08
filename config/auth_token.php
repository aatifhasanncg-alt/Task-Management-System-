<?php
declare(strict_types=1);

define('REMEMBER_COOKIE', 'remember_token');
define('REMEMBER_DAYS',   0);   // 0 = never expires (forever)
                                // Change to e.g. 365 for 1-year limit

/**
 * Called right after successful login.
 * Pass $forever=true to never expire.
 */
function setRememberToken(int $userId, bool $forever = true): void {
    $db    = getDB();
    $token = bin2hex(random_bytes(32)); // 64-char secure token

    $expiresAt = null; // NULL = forever
    $cookieExp = 0;    // 0 = browser-session cookie... but we want forever:
    $cookieExp = $forever ? (time() + (10 * 365 * 24 * 3600)) : 0; // 10 years

    // Store in DB
    $db->prepare("
        INSERT INTO remember_tokens (user_id, token, expires_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $userId,
        hash('sha256', $token),   // store HASH, not raw token
        $expiresAt,
        $_SERVER['REMOTE_ADDR']  ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);

    // Set cookie for 10 years
    setcookie(REMEMBER_COOKIE, $token, [
        'expires'  => $cookieExp,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Check remember token cookie → restore session if valid.
 * Call this at the top of session.php BEFORE requireLogin().
 */
function tryAutoLogin(): bool {
    if (!empty($_SESSION['user_id'])) return true; // already logged in

    $token = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!$token) return false;

    $db        = getDB();
    $tokenHash = hash('sha256', $token);

    $stmt = $db->prepare("
        SELECT rt.id, rt.user_id, rt.expires_at,
               u.username, u.full_name, u.role, u.department_id
        FROM   remember_tokens rt
        JOIN   users u ON u.id = rt.user_id
        WHERE  rt.token = ?
          AND  (rt.expires_at IS NULL OR rt.expires_at > NOW())
        LIMIT  1
    ");
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        clearRememberToken(); // invalid cookie → clear it
        return false;
    }

    // ── Restore session ──────────────────────────────────────
    $_SESSION['user_id']       = $row['user_id'];
    $_SESSION['role']          = $row['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['user']          = [
        'id'            => $row['user_id'],
        'username'      => $row['username'],
        'full_name'     => $row['full_name'],
        'role'          => $row['role'],
        'department_id' => $row['department_id'],
    ];

    // ── Update last_used timestamp ───────────────────────────
    $db->prepare("
        UPDATE remember_tokens SET last_used = NOW() WHERE id = ?
    ")->execute([$row['id']]);

    return true;
}

/**
 * Call on logout — removes token from DB and clears cookie.
 */
function clearRememberToken(): void {
    $token = $_COOKIE[REMEMBER_COOKIE] ?? '';

    if ($token) {
        try {
            $db = getDB();
            $db->prepare("
                DELETE FROM remember_tokens WHERE token = ?
            ")->execute([hash('sha256', $token)]);
        } catch (Exception $e) {}
    }

    // Expire the cookie
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Logout all devices for a user (e.g. password change, security alert).
 */
function clearAllTokensForUser(int $userId): void {
    $db = getDB();
    $db->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([$userId]);
    clearRememberToken();
}