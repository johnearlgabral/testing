<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/recaptcha.php';

// Already logged in? Send to dashboard.
if (!empty($_SESSION['user_id'])) {
    redirect('/UBMSConnect/index.php');
}

$error = '';
$notice = '';

if (isset($_GET['deactivated'])) {
    $notice = 'Your session ended because your account is no longer active. Please contact a Super Admin.';
}
if (isset($_GET['registered'])) {
    // 'host' = Event Host account, usable immediately; 'staff' = pending review.
    // The plain 'registered=1' form is still honored for older links.
    $notice = $_GET['registered'] === 'staff'
        ? 'Registration received. UBMS Staff accounts are verified by a Super Admin before the first sign-in - you will be notified once approved.'
        : 'Account created. Sign in below with your school email and password.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Simplification: one identifier field is enough. The role is always read
    // from the database record, so the user never has to know or choose it,
    // and they can sign in with either their school email or their username.
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';
    $remember   = isset($_POST['remember']);

    // Google reCAPTCHA v2 - verified with Google BEFORE the credential lookup,
    // so a script that posts straight to this endpoint without ever solving the
    // widget is rejected before it can even probe for a valid account. The check
    // is a no-op (always "ok") when RECAPTCHA_ENABLED is false.
    $captcha = recaptchaVerify($_POST['g-recaptcha-response'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '');

    if ($identifier === '' || $password === '') {
        $error = 'Please enter your email (or username) and your password.';
    } elseif (!$captcha['ok']) {
        // The captcha result takes precedence over the credential result, so a
        // failed check can never be used to probe whether an account exists.
        $error = $captcha['message'];
    } else {
        // An exact username match wins; the email lookup is only a fallback for
        // users who registered with their school email and never memorized the
        // auto-generated username.
        $stmt = $pdo->prepare(
            'SELECT id, username, password, full_name, role, status FROM users
             WHERE username = ? OR email = ?
             ORDER BY (username = ?) DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$identifier, $identifier, $identifier]);
        $user = $stmt->fetch();

        // Generic message used for anything that should not reveal WHY
        // login failed (unknown account vs. wrong password), to avoid
        // leaking account existence information.
        $genericInvalid = 'Invalid email/username or password.';

        if (!$user || !password_verify($password, $user['password'])) {
            $error = $genericInvalid;
        } elseif ($user['status'] === 'pending') {
            $error = 'Your UBMS Staff account is pending Super Admin approval. Please check back later.';
        } elseif ($user['status'] === 'rejected') {
            $error = 'Your staff registration was not approved. Please contact a Super Admin for details.';
        } elseif ($user['status'] === 'inactive') {
            $error = 'This account has been deactivated. Please contact a Super Admin.';
        } else {
            // Regenerate session ID on login to prevent session fixation.
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            $upd = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $upd->execute([$user['id']]);

            if ($remember) {
                issueRememberToken($pdo, $user['id']);
            }

            redirect('/UBMSConnect/index.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | UBMSConnect</title>
<?php // ?v= makes the browser fetch the current stylesheet after a CSS change instead of a stale cached copy. ?>
<link rel="stylesheet" href="/UBMSConnect/assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
<?php // reCAPTCHA widget library. defer (not async) so it never blocks first paint
      // and always runs after this page's own inline script. Loaded in EXPLICIT
      // render mode so nothing at all is drawn on initial page load - the box
      // starts hidden and login.php's script reveals + renders it on the
      // visitor's first attempt to sign in, when the check is actually needed.
      // Server-side verification is unaffected. Outputs nothing at all when the
      // feature is switched off. ?>
<?php recaptchaScriptTag('render=explicit', 'ubmsLoginRecaptchaReady'); ?>
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
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 3L1 8L12 13L21 9.18V17H23V8L12 3Z" fill="white"/>
                        <path d="M5 10.18V15.18C5 17.6 8.13 19.6 12 19.6C15.87 19.6 19 17.6 19 15.18V10.18L12 13.5L5 10.18Z" fill="white"/>
                    </svg>
                </div>
                <h1>UBMSConnect</h1>
                <p>REAL-TIME ACTIVITY MONITORING SYSTEM</p>
            </div>

            <?php if ($notice): ?>
                <div class="alert alert-info"><?= e($notice) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="login-form" autocomplete="off">
                <label for="identifier">Username or Email</label>
                <div class="input-icon-wrap">
                    <span class="input-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="7" r="4" stroke="#5c6779" stroke-width="2"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" stroke="#5c6779" stroke-width="2"/></svg>
                    </span>
                    <input type="text" id="identifier" name="identifier" class="input-with-icon" placeholder="Enter your username or email" required autofocus value="<?= e($_POST['identifier'] ?? '') ?>">
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label for="password" style="margin-bottom:0;">Password</label>
                    <a href="/UBMSConnect/forgot_password.php" class="small-note" style="text-transform:none; font-weight:600;">Forgot password?</a>
                </div>
                <div class="input-icon-wrap">
                    <span class="input-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="5" y="11" width="14" height="9" rx="2" stroke="#5c6779" stroke-width="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4" stroke="#5c6779" stroke-width="2"/></svg>
                    </span>
                    <input type="password" id="password" name="password" class="input-with-icon" placeholder="Enter your password" required>
                    <button type="button" class="password-toggle" id="passwordToggle" aria-label="Show password">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z" stroke="#5c6779" stroke-width="2"/><circle cx="12" cy="12" r="3" stroke="#5c6779" stroke-width="2"/></svg>
                    </button>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="remember" id="remember">
                    <span>Remember this device for 30 days</span>
                </label>

                <?php recaptchaWidget(); ?>

                <button type="submit" class="btn btn-primary btn-block">Sign In &rarr;</button>
            </form>

            <div class="login-demo">
                <p><strong>Demo Accounts</strong></p>
                <ul>
                    <li>Super Admin &mdash; <code>superadmin</code> / <code>SuperAdmin123</code></li>
                    <li>UBMS Staff &mdash; <code>staff</code> / <code>staff123</code></li>
                    <li>Event Host &mdash; <code>host</code> / <code>host123</code></li>
                </ul>
                <p style="margin-top:10px;">No account yet? <a href="/UBMSConnect/register.php">Create one in 2 steps</a></p>
            </div>
        </div><!-- /.login-card -->
    </div><!-- /.login-wrapper -->
    </div><!-- /.auth-layout -->
    <div class="login-footer-links">
        <span>Privacy Policy</span> | <span>Terms of Service</span> | <span>System Status</span>
    </div>

<script>
document.getElementById('passwordToggle').addEventListener('click', function () {
    const pw = document.getElementById('password');
    pw.type = pw.type === 'password' ? 'text' : 'password';
});

/* reCAPTCHA UI (presentation only): the field is hidden while the visitor
   fills the form and is revealed + rendered on their FIRST attempt to sign in
   - the point where the check becomes required. A blocked attempt only stops
   the POST client-side so the visitor can tick the freshly drawn box; every
   submission is still verified server-side on the server (recaptchaVerify()
   in login.php), which cannot be bypassed by skipping this script. With the
   feature switched off in .env nothing here runs (the field is not rendered). */
(function () {
    var field = document.getElementById('recaptchaField');
    if (!field) return; // reCAPTCHA disabled - leave the form exactly as before
    var box = document.getElementById('recaptchaBox');
    var hint = document.getElementById('recaptchaHint');
    var widgetId = null;
    var wanted = false; // set once the visitor has tried to submit

    function renderCaptcha() {
        if (widgetId !== null) return true;          // already drawn - never twice
        if (!box || !window.grecaptcha || typeof grecaptcha.render !== 'function') return false;
        field.hidden = false;                        // reveal BEFORE rendering so the iframe is measured while visible
        widgetId = grecaptcha.render(box, {
            sitekey: box.getAttribute('data-sitekey'),
            callback: function () { if (hint) hint.hidden = true; }
        });
        if (field.scrollIntoView) field.scrollIntoView({ block: 'center' });
        return true;
    }

    // api.js may finish loading before or after the first submit attempt; in
    // the "after" case this callback draws the widget that attempt asked for.
    // Global because Google's onload= lookup expects it on window.
    window.ubmsLoginRecaptchaReady = function () {
        if (wanted && renderCaptcha() && hint) hint.hidden = false;
    };

    document.querySelector('.login-form').addEventListener('submit', function (e) {
        wanted = true;
        if (!renderCaptcha()) {
            e.preventDefault(); // library still loading - onload will draw it
            return;
        }
        if (!grecaptcha.getResponse(widgetId)) {
            e.preventDefault(); // revealed but not ticked yet
            if (hint) hint.hidden = false;
        }
    });
})();
</script>
<script src="/UBMSConnect/assets/js/auth-bg.js"></script>
</body>
</html>
