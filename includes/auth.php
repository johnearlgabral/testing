<?php
/**
 * UBMSConnect - Authentication / Authorization guard
 *
 * Include this file at the top of ANY protected page or action.
 * It enforces session-based authentication and role-based access
 * on the SERVER SIDE (not just hiding buttons in the UI).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/remember_me.php';

// Transparently re-authenticate via a valid "remember this device" cookie
// if there's no active session yet. After this runs, requireLogin()/
// requireLoginApi() below see a normal, fully-populated session exactly
// as if the user had just logged in - no special-casing needed anywhere
// else in the app.
attemptRememberMeLogin($pdo);

/** Must be logged in at all, otherwise send to login page */
function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        redirect('/UBMSConnect/login.php');
    }
    // Re-verify the account is still active on every protected request.
    // This means a Super Admin deactivating/deleting a user takes effect
    // immediately, even if that user already has a live session.
    enforceLiveAccountStatus();
}

/**
 * Queries the DB for the logged-in user's current status/role and forces
 * a logout if the account has been deactivated, rejected, or deleted, or
 * if their role changed since the session was created.
 */
function enforceLiveAccountStatus() {
    global $pdo;
    static $checked = false;
    if ($checked) return; // only hit the DB once per request
    $checked = true;

    $stmt = $pdo->prepare("SELECT role, status FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        $_SESSION = [];
        session_destroy();
        redirect('/UBMSConnect/login.php?deactivated=1');
    }

    // Keep the session role in sync with the DB (covers role changes made by a Super Admin).
    $_SESSION['role'] = $user['role'];
}

/**
 * Must be logged in AND have a specific role (string) or one of several
 * roles (array). This is the real server-side enforcement of role-based
 * access control. Even if a Host manually requests a Staff-only URL, or a
 * Staff user requests a Super-Admin-only URL, this rejects the request.
 */
function requireRole($role) {
    requireLogin();
    $allowed = is_array($role) ? $role : [$role];
    if (!in_array($_SESSION['role'], $allowed, true)) {
        http_response_code(403);
        die('<!DOCTYPE html><html><head><title>Access Denied</title>'
            . '<link rel="stylesheet" href="/UBMSConnect/assets/css/style.css">'
            . '<script>(function(){var p=localStorage.getItem("ubms_theme")||"system";var r=p;if(p==="system"){r=(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches)?"dark":"light";}document.documentElement.setAttribute("data-theme",r);})();</script>'
            . '</head>'
            . '<body><div class="access-denied">'
            . '<h1>403 - Access Denied</h1>'
            . '<p>Your account role ("' . e(roleLabel($_SESSION['role'])) . '") is not authorized to view this resource.</p>'
            . '<a href="/UBMSConnect/index.php">Return to Dashboard</a>'
            . '</div></body></html>');
    }
}

/** Convenience shortcut for Super-Admin-only pages/actions */
function requireSuperAdmin() {
    requireRole('super_admin');
}

/** Convenience getters for the current user */
function currentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function currentUserRole() {
    return $_SESSION['role'] ?? null;
}

function currentUserName() {
    return $_SESSION['full_name'] ?? '';
}

function isStaff() {
    return currentUserRole() === 'staff';
}

function isHost() {
    return currentUserRole() === 'host';
}

function isSuperAdmin() {
    return currentUserRole() === 'super_admin';
}

/* =========================================================
 * JSON-API-safe authentication guards
 *
 * requireLogin()/requireRole() redirect via a "Location" header on
 * failure, which is correct for full page loads but WRONG for
 * actions/*.php and api/*.php endpoints: a fetch() call transparently
 * follows that redirect to login.php, receives an HTML page instead of
 * JSON, and response.json() throws a parse error - which surfaces to
 * the user as a misleading generic "Network Error" even though nothing
 * about the network failed. Every actions/*.php and api/*.php file
 * should use these instead.
 * ========================================================= */

/** Same as requireLogin(), but responds with JSON + HTTP 401 instead of a redirect. */
function requireLoginApi() {
    if (empty($_SESSION['user_id'])) {
        respondAuthError(401, 'Your session has expired or you are not logged in. Please log in again.');
    }
    enforceLiveAccountStatusApi();
}

/** Same as enforceLiveAccountStatus(), but responds with JSON + HTTP 401 instead of a redirect. */
function enforceLiveAccountStatusApi() {
    global $pdo;
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $stmt = $pdo->prepare("SELECT role, status FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        $_SESSION = [];
        session_destroy();
        respondAuthError(401, 'Your account is no longer active. Please contact a Super Admin, or log in again if this is a mistake.');
    }

    $_SESSION['role'] = $user['role'];
}

/** Same as requireRole(), but responds with JSON + HTTP 401/403 instead of a redirect/HTML page. */
function requireRoleApi($role) {
    requireLoginApi();
    $allowed = is_array($role) ? $role : [$role];
    if (!in_array($_SESSION['role'], $allowed, true)) {
        respondAuthError(403, 'You do not have permission to perform this action. Your role is "' . roleLabel($_SESSION['role']) . '".');
    }
}

/** Emits a clean JSON error response for an auth/authorization failure and exits. */
function respondAuthError($httpCode, $message) {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message, 'auth_error' => true]);
    exit;
}
