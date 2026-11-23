<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/remember_me.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Explicit logout must also revoke any "remember this device" token, so
// the remembered cookie can never silently re-authenticate the user
// after they deliberately signed out.
revokeRememberToken($pdo);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
}

session_destroy();
header('Location: /UBMSConnect/login.php');
exit;
