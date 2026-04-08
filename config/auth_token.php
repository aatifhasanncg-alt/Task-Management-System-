<?php
declare(strict_types=1);

define('REMEMBER_COOKIE', 'remember_token');

function setRememberToken(int $userId, bool $forever = true): void {
    $db    = getDB();
    $token = bin2hex(random_bytes(32));

    $expiresAt = null; // NULL = forever
    $cookieExp = $forever ? (time() + (10 * 365 * 24 * 3600)) : 0;

    // Detect device info
    $ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $browser = 'Unknown';
    $os      = 'Unknown';

    if (str_contains($ua, 'Edg'))         $browser = 'Edge';      // Edge BEFORE Chrome
    elseif (str_contains($ua, 'Chrome'))  $browser = 'Chrome';
    elseif (str_contains($ua, 'Firefox')) $browser = 'Firefox';
    elseif (str_contains($ua, 'Safari'))  $browser = 'Safari';

    if (str_contains($ua, 'iPhone'))      $os = 'iPhone';         // iPhone BEFORE Mac
    elseif (str_contains($ua, 'Android')) $os = 'Android';
    elseif (str_contains($ua, 'Windows')) $os = 'Windows';
    elseif (str_contains($ua, 'Mac'))     $os = 'macOS';
    elseif (str_contains($ua, 'Linux'))   $os = 'Linux';

    $deviceName = $browser . ' on ' . $os;

    $db->prepare("
        INSERT INTO remember_tokens 
            (user_id, token, expires_at, ip_address, user_agent, device_name, browser, os)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $userId,
        hash('sha256', $token),
        $expiresAt,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $ua,
        $deviceName,
        $browser,
        $os,
    ]);

    setcookie(REMEMBER_COOKIE, $token, [
        'expires'  => $cookieExp,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function tryAutoLogin(): bool {
    if (!empty($_SESSION['user_id'])) return true;

    $token = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!$token) return false;

    $db        = getDB();
    $tokenHash = hash('sha256', $token);

    $stmt = $db->prepare("
        SELECT rt.id, rt.user_id, rt.expires_at,
               u.username, u.full_name, u.department_id,
               u.branch_id, u.employee_id, u.email,
               r.role_name AS role
        FROM   remember_tokens rt
        JOIN   users u ON u.id = rt.user_id
        JOIN   roles r ON r.id = u.role_id
        WHERE  rt.token = ?
          AND  (rt.expires_at IS NULL OR rt.expires_at > NOW())
        LIMIT  1
    ");
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // ── BUG FIX 1: Check $row BEFORE rotating token ──────────
    if (!$row) {
        clearRememberToken();
        return false;
    }

    // ── Rotate token on every visit ───────────────────────────
    $newToken     = bin2hex(random_bytes(32));
    $newTokenHash = hash('sha256', $newToken);

    $db->prepare("
        UPDATE remember_tokens 
        SET token = ?, last_used = NOW()
        WHERE id = ?
    ")->execute([$newTokenHash, $row['id']]);

    setcookie(REMEMBER_COOKIE, $newToken, [
        'expires'  => time() + (10 * 365 * 24 * 3600),
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // ── BUG FIX 2: Restore FULL session (role was missing) ────
    $_SESSION['user_id']       = $row['user_id'];
    $_SESSION['role']          = $row['role'];
    $_SESSION['full_name']     = $row['full_name'];
    $_SESSION['branch_id']     = $row['branch_id'];
    $_SESSION['dept_id']       = $row['department_id'];
    $_SESSION['email']         = $row['email'];
    $_SESSION['employee_id']   = $row['employee_id'] ?? '';
    $_SESSION['last_activity'] = time();
    $_SESSION['user']          = [
        'id'            => $row['user_id'],
        'username'      => $row['username'],
        'full_name'     => $row['full_name'],
        'role'          => $row['role'],
        'department_id' => $row['department_id'],
    ];

    // ── BUG FIX 3: Restore admin scope if needed ──────────────
    if ($row['role'] === 'admin') {
        $dSt = $db->prepare("SELECT department_id FROM admin_department_access WHERE admin_id = ?");
        $dSt->execute([$row['user_id']]);
        $_SESSION['allowed_depts'] = array_column($dSt->fetchAll(PDO::FETCH_ASSOC), 'department_id');

        $bSt = $db->prepare("SELECT branch_id FROM admin_branch_access WHERE admin_id = ?");
        $bSt->execute([$row['user_id']]);
        $_SESSION['allowed_branches'] = array_column($bSt->fetchAll(PDO::FETCH_ASSOC), 'branch_id');
    }

    return true;
}

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

    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearAllTokensForUser(int $userId): void {
    $db = getDB();
    $db->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([$userId]);
    clearRememberToken();
}