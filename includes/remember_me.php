<?php
/**
 * includes/remember_me.php
 * "Remember this device for 30 days" - secure, database-backed
 * persistent login. Include AFTER config/database.php is available
 * (auth.php already requires it before this file).
 *
 * Design:
 *   - The cookie holds ONLY a cryptographically random raw token
 *     (bin2hex(random_bytes(32)) = 64 hex chars). It never contains
 *     a user id, username, or anything password-derived.
 *   - The DATABASE stores only SHA-256(raw token). If the database
 *     were ever exposed, the stored hashes cannot be used to forge
 *     a valid cookie (one-way function), same principle as password
 *     hashing.
 *   - Expires 30 days from issuance; checked with MySQL's own NOW()
 *     for the same reason password-reset tokens do (see
 *     forgot_password.php) - never compare a PHP-computed timestamp
 *     against MySQL's clock.
 */

define('REMEMBER_COOKIE_NAME', 'ubms_remember');
define('REMEMBER_DAYS', 30);

/** True only when the current request is actually HTTPS - never assume in local XAMPP/HTTP dev. */
function isSecureRequest() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/**
 * Issue a new remember-me token for a user: store its hash in the DB
 * and set the cookie holding the raw value. Called right after a
 * successful login when the "remember this device" box was checked.
 */
function issueRememberToken($pdo, $userId) {
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare(
        'INSERT INTO remember_tokens (user_id, token_hash, expires_at, created_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . REMEMBER_DAYS . ' DAY), NOW())'
    );
    $stmt->execute([$userId, $tokenHash]);

    setcookie(REMEMBER_COOKIE_NAME, $rawToken, [
        'expires'  => time() + (REMEMBER_DAYS * 86400),
        'path'     => '/UBMSConnect/',
        'domain'   => '',
        'secure'   => isSecureRequest(), // only forces HTTPS-only cookies when actually on HTTPS - never breaks local XAMPP/HTTP
        'httponly' => true,              // never accessible to JavaScript
        'samesite' => 'Lax',
    ]);
}

/**
 * If there is no active session but a valid remember-me cookie exists,
 * transparently re-authenticate the user and start a normal session.
 * Called once, early, from includes/auth.php - after this, the rest
 * of the app behaves exactly as if the user had just logged in
 * normally (same $_SESSION keys populated the same way).
 */
function attemptRememberMeLogin($pdo) {
    if (!empty($_SESSION['user_id'])) return; // already logged in, nothing to do
    if (empty($_COOKIE[REMEMBER_COOKIE_NAME])) return;

    $rawToken = $_COOKIE[REMEMBER_COOKIE_NAME];
    // Defensive: a cookie value should only ever be 64 hex chars; anything
    // else could not possibly be a token we issued, so bail out cheaply
    // without touching the database.
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        clearRememberCookie();
        return;
    }
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare(
        'SELECT rt.id AS token_id, u.id, u.username, u.full_name, u.role, u.status
         FROM remember_tokens rt
         JOIN users u ON u.id = rt.user_id
         WHERE rt.token_hash = ? AND rt.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    if (!$row || $row['status'] !== 'active') {
        // Invalid, expired, or the account is no longer active - clear
        // the stale cookie so we don't keep re-checking a dead token.
        clearRememberCookie();
        return;
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = $row['id'];
    $_SESSION['username']  = $row['username'];
    $_SESSION['full_name'] = $row['full_name'];
    $_SESSION['role']      = $row['role'];

    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$row['id']]);
}

/** Remove the remember-me cookie from the browser (does not touch the DB). */
function clearRememberCookie() {
    setcookie(REMEMBER_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/UBMSConnect/',
        'domain'   => '',
        'secure'   => isSecureRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[REMEMBER_COOKIE_NAME]);
}

/**
 * Revoke the current remember-me token entirely (DB row deleted AND
 * cookie cleared). Called on explicit logout, so "Remember this
 * device" can never bypass an intentional logout.
 */
function revokeRememberToken($pdo) {
    if (!empty($_COOKIE[REMEMBER_COOKIE_NAME])) {
        $tokenHash = hash('sha256', $_COOKIE[REMEMBER_COOKIE_NAME]);
        $pdo->prepare('DELETE FROM remember_tokens WHERE token_hash = ?')->execute([$tokenHash]);
    }
    clearRememberCookie();
}
