<?php
/**
 * forgot_password.php
 * Super Admin-assisted password recovery request page.
 *
 * SECURITY MODEL (replaces the former Development-Mode flow):
 * The previous version of this page generated a real, expiring, single-use
 * reset token and printed the reset link directly in the browser inside a
 * "Development Mode" box - so anyone who knew another user's username or
 * email could silently take over that account without proving anything.
 * That behavior is removed entirely: this page now creates a PENDING
 * password recovery request for Super Admin review instead, and displays a
 * single generic confirmation.
 *
 * The confirmation is identical whether the submitted identifier matches an
 * active account or not (and rate limiting is keyed on the raw submitted
 * string plus the client IP BEFORE any account lookup, so it cannot be used
 * as an existence oracle either). No reset token, link, or other secret is
 * generated or displayed anywhere in this flow - a password can only be
 * reset after a Super Admin verifies the requester's identity through the
 * established university verification process (see superadmin/users.php).
 *
 * Abuse controls (duplicate suppression + per-identifier / per-IP rate
 * limits) live in includes/password_recovery.php.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password_recovery.php';

if (!empty($_SESSION['user_id'])) {
    redirect('/UBMSConnect/index.php');
}

$submitted   = false;
$rateLimited = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = true;
    $identifier = trim($_POST['identifier'] ?? '');

    if ($identifier !== '') {
        // Never generates a token/link; records a pending admin-review
        // request (or a rate-limit ledger row for unknown identifiers).
        // The page shows the same generic confirmation either way.
        $rateLimited = submitPasswordRecoveryRequest($identifier) === 'rate_limited';
    }
}

$pageTitle = 'Forgot Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | UBMSConnect</title>
<?php // ?v= makes the browser fetch the current stylesheet after a CSS change instead of a stale cached copy. ?>
<link rel="stylesheet" href="/UBMSConnect/assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="login-body login-body--rotate">
    <!-- Rotating campus background (presentation only, shared by all four auth
         pages): full-screen layers stacked behind the card and crossfaded by
         assets/js/auth-bg.js. The first slide carries .is-active, so the page
         still looks correct even if that script never runs. -->
    <div class="auth-bg-slides" aria-hidden="true">
        <div class="auth-bg-slide is-active" style="background-image:url('assets/img/campus-bg-1.jpg')"></div>
        <div class="auth-bg-slide" style="background-image:url('assets/img/campus-bg-2.jpg')"></div>
        <div class="auth-bg-slide" style="background-image:url('assets/img/campus-bg-3.jpg')"></div>
        <div class="auth-bg-slide" style="background-image:url('assets/img/campus-bg-4.jpg')"></div>
        <div class="auth-bg-slide" style="background-image:url('assets/img/campus-bg-5.jpg')"></div>
    </div>
    <div class="auth-layout">
        <!-- University branding panel (presentation only, shared by all four
             auth pages): official LCUP seal + wordmark, product tagline and the
             campus tagline strip. Collapsed by CSS below 1080px, where the card
             takes over the full width. -->
        <aside class="auth-side">
            <div class="auth-side-brand">
                <img class="auth-seal" src="/UBMSConnect/assets/img/lcup-logo.png" alt="La Consolacion University Philippines seal">
                <div class="auth-wordmark">
                    <span class="aw-name">La Consolacion</span>
                    <span class="aw-sub">University, Philippines</span>
                    <span class="aw-strip">The Catholic University of Bulacan</span>
                </div>
            </div>
            <div class="auth-side-body">
                <h2>UBMSConnect</h2>
                <p class="auth-side-role">Real-Time Activity Monitoring System</p>
                <hr class="auth-side-rule">
                <p class="auth-side-desc">Efficient event, facility, and activity management for a safer and more connected campus.</p>
            </div>
            <div class="auth-side-tags">
                <span>Better Facilities</span><i aria-hidden="true"></i><span>Safer Events</span><i aria-hidden="true"></i><span>Stronger Community</span>
            </div>
        </aside>
        <div class="login-wrapper">
        <div class="login-card">
            <div class="login-brand">
                <div class="login-icon">
                    <?php // Same graduation-cap mark login.php uses - the project's existing logo/icon. ?>
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 3L1 8L12 13L21 9.18V17H23V8L12 3Z" fill="white"/>
                        <path d="M5 10.18V15.18C5 17.6 8.13 19.6 12 19.6C15.87 19.6 19 17.6 19 15.18V10.18L12 13.5L5 10.18Z" fill="white"/>
                    </svg>
                </div>
                <?php // Page title folded into the brand block so the card header matches the reference design. ?>
                <h1>Forgot Password</h1>
                <p>UBMSCONNECT ACCOUNT RECOVERY</p>
            </div>

            <?php if ($submitted): ?>
                <?php if ($rateLimited): ?>
                    <div class="alert alert-error">Too many recovery requests have been received. Please wait a few minutes and try again.</div>
                <?php else: ?>
                    <?php // One identical message for every outcome - it must never confirm or deny that an account exists. ?>
                    <div class="alert alert-success">Password recovery request received. If the details match an active account, a Super Admin will review the request and contact you through official channels.</div>
                <?php endif; ?>
                <p style="margin-top:14px;"><a href="/UBMSConnect/login.php">&larr; Back to login</a></p>
            <?php else: ?>
                <form method="POST" action="forgot_password.php" class="login-form">
                    <label for="identifier">Username or Email</label>
                    <div class="input-icon-wrap">
                        <span class="input-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="7" r="4" stroke="#5c6779" stroke-width="2"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" stroke="#5c6779" stroke-width="2"/></svg>
                        </span>
                        <input type="text" id="identifier" name="identifier" class="input-with-icon" placeholder="Enter your username or email" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Submit Recovery Request</button>
                </form>
                <div class="login-demo">
                    <p><a href="/UBMSConnect/login.php">&larr; Back to login</a></p>
                </div>
            <?php endif; ?>
        </div>
    </div><!-- /.login-wrapper -->
    </div><!-- /.auth-layout -->
    <div class="login-footer-links">
        <span>Privacy Policy</span> | <span>Terms of Service</span> | <span>System Status</span>
    </div>
<script src="/UBMSConnect/assets/js/auth-bg.js"></script>
</body>
</html>
