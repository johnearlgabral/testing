<?php
/**
 * includes/password_recovery.php
 * Super Admin-assisted password recovery workflow. This replaces the removed
 * Development-Mode behavior of forgot_password.php, which used to display a
 * raw password-reset link to ANY unauthenticated visitor who knew a username
 * or email (account takeover with zero proof of identity).
 *
 * Public side (forgot_password.php):
 *   submitPasswordRecoveryRequest() records the submitted identifier in
 *   password_recovery_requests with per-identifier / per-IP rate limiting and
 *   duplicate suppression, and - only when the identifier resolves to an
 *   ACTIVE account that has no open request yet - notifies every Super Admin
 *   through the existing in-app notification system. It never creates a reset
 *   token, never returns a link, and never reveals to the caller whether the
 *   account exists (rate limiting runs on the raw submitted string BEFORE any
 *   account lookup, so the limiter itself is not an existence oracle either).
 *
 * Admin side (actions/manage_user.php case 'recovery_review'):
 *   recoveryCsrfToken()/verifyRecoveryCsrf() protect the three NEW
 *   state-changing admin actions (approve / reject / mark handled) with a
 *   per-session token. The project has no CSRF framework (documented in the
 *   README as a deliberate codebase-wide decision), so this is the smallest
 *   scoped implementation: applied ONLY to these new actions, leaving every
 *   existing endpoint untouched.
 *
 * Include this AFTER includes/auth.php (needs $pdo + a started session).
 */

/* ---------------- Rate limiting constants ---------------- */
// Maximum submissions counted per submitted identifier / per client IP
// within the sliding window below. Once the limit is hit, further requests
// are rejected (and NOT recorded) until old rows age out of the window.
const RECOVERY_RATE_LIMIT_IDENTIFIER = 5;
const RECOVERY_RATE_LIMIT_IP         = 10;
const RECOVERY_RATE_LIMIT_WINDOW_MIN = 15;

/* ---------------- Shared helpers (fail-soft if pieces are missing) -------- */
$__recoveryNotifHelper = __DIR__ . '/notifications.php';
if (file_exists($__recoveryNotifHelper)) {
    require_once $__recoveryNotifHelper;
} elseif (!function_exists('notifyRole')) {
    function notifyRole($role, $category, $message, $link = null, $excludeUserId = null) {}
}
$__recoveryAuditHelper = __DIR__ . '/audit.php';
if (file_exists($__recoveryAuditHelper)) {
    require_once $__recoveryAuditHelper;
} elseif (!function_exists('logAudit')) {
    function logAudit($action, $targetType = null, $targetId = null, $targetLabel = null, $previousValue = null, $newValue = null) {}
}

/**
 * Client IP for rate limiting. REMOTE_ADDR only - forwarded headers are
 * attacker-controllable and this app sits behind plain local Apache.
 */
function recoveryClientIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return substr((string) $ip, 0, 45); // fits IPv6 in VARCHAR(45)
}

/**
 * Per-session CSRF token for the new Super Admin recovery-review actions.
 * One token per session is enough; it is embedded only in the
 * superadmin/users.php page (Super Admin sessions) and verified with
 * hash_equals() server-side in actions/manage_user.php.
 */
function recoveryCsrfToken() {
    if (empty($_SESSION['recovery_csrf'])) {
        $_SESSION['recovery_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['recovery_csrf'];
}

function verifyRecoveryCsrf($token) {
    if (empty($_SESSION['recovery_csrf']) || !is_string($token) || $token === '') {
        return false;
    }
    return hash_equals($_SESSION['recovery_csrf'], $token);
}

/**
 * True when this identifier or client IP has submitted too many recovery
 * requests inside the rate-limit window. Runs BEFORE any account lookup so
 * blocked and unblocked submissions of any string behave identically with
 * respect to account existence.
 */
function recoveryRateLimitExceeded($storedIdentifier, $ip) {
    global $pdo;
    $window = (int) RECOVERY_RATE_LIMIT_WINDOW_MIN; // interpolated constant, never user input

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM password_recovery_requests
          WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL {$window} MINUTE)"
    );
    $stmt->execute([$ip]);
    if ((int) $stmt->fetchColumn() >= RECOVERY_RATE_LIMIT_IP) {
        return true;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM password_recovery_requests
          WHERE identifier_submitted = ? AND created_at > DATE_SUB(NOW(), INTERVAL {$window} MINUTE)"
    );
    $stmt->execute([$storedIdentifier]);
    if ((int) $stmt->fetchColumn() >= RECOVERY_RATE_LIMIT_IDENTIFIER) {
        return true;
    }
    return false;
}

/**
 * Record one forgot-password submission as a pending recovery request.
 *
 * Returns 'rate_limited' when the submission was refused by the limiter,
 * 'ok' otherwise - ALWAYS regardless of whether the identifier matched an
 * active account (the caller shows the same generic confirmation either way).
 *
 * Side effects when (and only when) the identifier resolves to an active
 * account with no open request:
 *   - a 'pending' row is stored (the admin review queue item),
 *   - every active Super Admin is notified via the existing notification
 *     system (category 'admin', linking to the review section),
 *   - an audit-log entry records the submission (actor: System).
 * No reset token is created anywhere in this flow.
 */
function submitPasswordRecoveryRequest($identifier) {
    global $pdo;

    $identifier = trim((string) $identifier);
    if ($identifier === '') return 'ok';

    $ip               = recoveryClientIp();
    $storedIdentifier = mb_substr($identifier, 0, 190);

    try {
        if (recoveryRateLimitExceeded($storedIdentifier, $ip)) {
            return 'rate_limited';
        }

        // Resolve ONLY active accounts - the same eligibility the old token
        // flow required. Username match wins, mirroring login.php.
        $stmt = $pdo->prepare(
            "SELECT id, username, full_name FROM users
              WHERE (username = ? OR email = ?) AND status = 'active'
              ORDER BY (username = ?) DESC, id ASC LIMIT 1"
        );
        $stmt->execute([$identifier, $identifier, $identifier]);
        $user = $stmt->fetch();

        $insert = $pdo->prepare(
            'INSERT INTO password_recovery_requests
                (user_id, identifier_submitted, status, ip_address, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );

        if (!$user) {
            // Ledger row for rate limiting / abuse forensics only. Never
            // surfaced as an actionable request, never notifies anyone.
            $insert->execute([null, $storedIdentifier, 'ignored', $ip]);
            return 'ok';
        }

        // Duplicate suppression: while a request for this account is already
        // pending, record the attempt but do not queue a second review item
        // and do not re-notify the Super Admins (prevents notification spam).
        $open = $pdo->prepare(
            "SELECT id FROM password_recovery_requests
              WHERE user_id = ? AND status = 'pending' LIMIT 1"
        );
        $open->execute([$user['id']]);
        if ($open->fetch()) {
            $insert->execute([$user['id'], $storedIdentifier, 'duplicate', $ip]);
            return 'ok';
        }

        $insert->execute([$user['id'], $storedIdentifier, 'pending', $ip]);

        notifyRole(
            'super_admin',
            'admin',
            'A password recovery request is awaiting review: ' . $user['full_name'] . ' (' . $user['username'] . ').',
            '/UBMSConnect/superadmin/users.php#recovery-requests'
        );

        // Actor is intentionally unset (public, pre-authentication page) -
        // logAudit() records it as "System", exactly like registration does.
        logAudit(
            'Submitted Password Recovery Request',
            'user', $user['id'],
            $user['username'] . ' (' . $user['full_name'] . ')'
        );

        return 'ok';
    } catch (Exception $e) {
        // A missing/un-migrated table (or any transient DB failure) must
        // never take the public page down or change its response - the
        // caller shows the same generic confirmation either way.
        error_log('[UBMSConnect] submitPasswordRecoveryRequest() failed: ' . $e->getMessage());
        return 'ok';
    }
}

