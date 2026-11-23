<?php
/**
 * UBMSConnect - Registration
 *
 * SIMPLIFIED 2-STEP REGISTRATION (requirement: "Simplify the Login and
 * Registration Process"):
 *   1. Fewer fields - the username is chosen by the user (it is never
 *      auto-generated or silently changed) and the "confirm password" box was
 *      removed, so the user types a handful of values instead of 11.
 *   2. Two short steps ("About you", then "Details & password") instead of
 *      one long wall of inputs.
 *   3. Nothing academic is typed by hand - department, course and
 *      organization are chosen from the official lists a Super Admin
 *      pre-registers at superadmin/reference_data.php, which keeps the
 *      reference data accurate and easy to update.
 *
 * The wizard is only a presentation convenience: every submitted value is
 * re-validated server-side below before anything is written to the database,
 * exactly as strictly as the previous single-page form did.
 *
 * ROLE vs STUDENT STATUS are two independent questions. Step 1 asks the system
 * role (Event Host / UBMS Staff - stored in users.role) and, separately,
 * whether the registrant is a student. The student answer is persisted in the
 * existing users.affiliation column ('student' = student, 'ubms_staff' =
 * non-student - a legacy enum value, kept so no schema change or data
 * migration is needed) and only decides which Step-2 fields appear. The role
 * and its approval behaviour (host = active, staff = pending) never depend on
 * the student answer, and the student answer never depends on the role.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/recaptcha.php';

if (!empty($_SESSION['user_id'])) redirect('/UBMSConnect/index.php');

/* Official reference data (only active rows are offered). */
$departments   = $pdo->query("SELECT id, department_name FROM departments WHERE is_active=1 ORDER BY department_name")->fetchAll();
$courses       = $pdo->query("SELECT id, department_id, course_name FROM courses WHERE is_active=1 ORDER BY course_name")->fetchAll();
$organizations = $pdo->query("SELECT id, organization_name FROM organizations WHERE is_active=1 ORDER BY organization_name")->fetchAll();

/**
 * Username rules for user-chosen usernames: 3-30 chars, letters / numbers /
 * underscore / hyphen only. The column is VARCHAR(50) UNIQUE, so 30 fits with
 * room to spare, and the login lookup (WHERE username = ? OR email = ?) is an
 * exact match that accepts every character this pattern allows. Comparisons
 * follow the database collation (ci), exactly like the UNIQUE index and the
 * login/recovery lookups do.
 */
function validUsernameFormat($username) {
    return (bool) preg_match('/^[A-Za-z0-9_-]{3,30}$/', $username);
}

$errors = [];
$old = ['full_name' => '', 'username' => '', 'email' => '', 'role' => 'host', 'student_status' => 'non_student',
        'department_id' => '', 'course_id' => '', 'organization_id' => '', 'office_unit' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $key => $value) $old[$key] = trim($_POST[$key] ?? $value);
    // SYSTEM ROLE: only the two cards on Step 1 exist. A tampered value falls
    // back to 'host', exactly as the old affiliation whitelist did.
    if (!in_array($old['role'], ['host', 'staff'], true)) $old['role'] = 'host';
    // STUDENT STATUS: an independent registration detail, never a role.
    if (!in_array($old['student_status'], ['student', 'non_student'], true)) $old['student_status'] = 'non_student';

    // Google reCAPTCHA v2, verified before any field validation or INSERT runs.
    // A failure only ADDS a key to $errors, so the pre-existing "if (!$errors)"
    // gate further down still blocks account creation exactly as before. The
    // check is a no-op (always "ok") when RECAPTCHA_ENABLED is false.
    $captcha = recaptchaVerify($_POST['g-recaptcha-response'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '');
    if (!$captcha['ok']) $errors['recaptcha'] = $captcha['message'];

    $isStudent      = $old['student_status'] === 'student'; // decides the Step-2 field set only - never the role
    $departmentId   = (int) $old['department_id'];
    $courseId       = (int) $old['course_id'];
    $organizationId = (int) $old['organization_id'];
    $password       = (string) ($_POST['password'] ?? '');

    /* ---- Step 1: identity (always required) ---- */
    if ($old['full_name'] === '') {
        $errors['full_name'] = 'Please enter your full name.';
    } elseif (mb_strlen($old['full_name']) > 100) {
        $errors['full_name'] = 'Your full name is too long (100 characters maximum).';
    }

    /* ---- Username: required, user-chosen, validated server-side ----
     * ($old was trimmed on assignment, so leading/trailing spaces are already
     * gone). The value is NEVER auto-generated or silently modified - if it
     * fails these checks the user simply picks another one. */
    if ($old['username'] === '') {
        $errors['username'] = 'Please choose a username.';
    } elseif (!validUsernameFormat($old['username'])) {
        $errors['username'] = 'Username must be 3-30 characters and may only use letters, numbers, underscores and hyphens.';
    }

    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } elseif (mb_strlen($old['email']) > 150) {
        $errors['email'] = 'That email address is too long (150 characters maximum).';
    }

    /* ---- Step 2: details, matching exactly the fields Step 2 shows -------
     * Students see Department (required), Course (required) and Organization
     * (optional); non-students see Department (optional) and UBMS office/unit
     * (required). A value posted from a hidden group is ignored, not required.
     */
    if ($departmentId) {
        $q = $pdo->prepare('SELECT id FROM departments WHERE id = ? AND is_active = 1');
        $q->execute([$departmentId]);
        if (!$q->fetch()) $errors['department_id'] = 'Please choose a department from the official list.';
    } elseif ($isStudent) {
        $errors['department_id'] = 'Please choose your department.';
    }

    if ($isStudent) {
        // Students must belong to a course that the chosen department actually offers.
        $q = $pdo->prepare('SELECT id FROM courses WHERE id = ? AND department_id = ? AND is_active = 1');
        $q->execute([$courseId, $departmentId]);
        if (!$q->fetch()) $errors['course_id'] = 'Please choose a course offered by your department.';

        if ($organizationId) {
            $q = $pdo->prepare('SELECT id FROM organizations WHERE id = ? AND is_active = 1');
            $q->execute([$organizationId]);
            if (!$q->fetch()) $errors['organization_id'] = 'Please choose an organization from the official list, or leave it blank.';
        }
    } else {
        // Non-students give an office/unit instead of a course; the department is optional.
        if ($old['office_unit'] === '') {
            $errors['office_unit'] = 'Please enter your UBMS office or unit.';
        } elseif (mb_strlen($old['office_unit']) > 150) {
            $errors['office_unit'] = 'That office/unit name is too long (150 characters maximum).';
        }
    }

    if (strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters.';

    /* ---- Duplicate accounts (username and email) ----
     * The username comparison uses the same column/collation as the UNIQUE
     * index and the login lookup, so it is consistent with both (ci). The
     * email check deliberately runs last so an existing account is only ever
     * revealed after every other field has already been validated. */
    if (!$errors) {
        $q = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $q->execute([$old['username']]);
        if ($q->fetch()) {
            $errors['username'] = 'That username is already taken. Please choose another one.';
        }
    }
    if (!$errors) {
        $q = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $q->execute([$old['email']]);
        if ($q->fetch()) {
            $errors['email'] = 'That email address is already registered. Please sign in instead.';
        }
    }

    /* ---- Create the account ---- */
    if (!$errors) {
        $role     = $old['role']; // straight from the "I am a..." card - never derived from student status
        $status   = $role === 'host' ? 'active' : 'pending'; // Event Hosts use the system right away; staff need Super Admin approval
        $username = $old['username']; // the user's own choice - stored exactly as validated, never regenerated

        try {
            $q = $pdo->prepare(
                'INSERT INTO users
                    (username, password, full_name, email, affiliation,
                     department_id, course_id, organization_id, office_unit, role, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $q->execute([
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $old['full_name'],
                $old['email'],
                $isStudent ? 'student' : 'ubms_staff', // legacy enum kept: 'ubms_staff' stores "non-student"
                $departmentId ?: null,
                $isStudent ? ($courseId ?: null) : null,
                $isStudent ? ($organizationId ?: null) : null,
                $isStudent ? null : $old['office_unit'],
                $role,
                $status,
            ]);
        } catch (PDOException $e) {
            // Race-condition duplicate (two identical submissions at once): the
            // UNIQUE index rejects the second one. Surface the same friendly
            // message as the pre-check instead of any database error.
            if ((string) $e->getCode() === '23000') {
                $errors['username'] = 'That username is already taken. Please choose another one.';
            } else {
                throw $e;
            }
        }

        // Only run the post-create steps when the INSERT actually succeeded.
        if (!$errors) {
            $id = (int) $pdo->lastInsertId();

            logAudit(
                'Registered New Account',
                'user',
                $id,
                $username . ' (' . $old['full_name'] . ')',
                null,
                roleLabel($role) . ' - ' . ($status === 'pending' ? 'pending verification' : 'active')
            );

            // Only Staff registrations need a Super Admin decision, so only those
            // are broadcast to the Super Admins.
            if ($status === 'pending') {
                notifyRole('super_admin', 'admin', 'A staff registration is waiting for review: ' . $old['full_name'] . '.', '/UBMSConnect/superadmin/pending.php');
            }

            redirect('/UBMSConnect/login.php?registered=' . $role); // 'host' | 'staff' - login.php reads both
        }
    }
}

// A fresh visit always starts at Step 1. Only after a failed submit is the form
// re-opened on the step that actually has a problem.
$startStep = 1;
if ($errors) {
    $stepOneErrors = array_intersect_key($errors, array_flip(['full_name', 'username', 'email']));
    $startStep = $stepOneErrors ? 1 : 2;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register | UBMSConnect</title>
<?php // ?v= makes the browser fetch the current stylesheet after a CSS change instead of a stale cached copy. ?>
<link rel="stylesheet" href="/UBMSConnect/assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/style.css') ?>">
<?php // reCAPTCHA library, loaded in EXPLICIT render mode because the widget sits
      // inside step 2, which starts hidden - an auto-rendered v2 widget would be
      // sized while invisible. The wizard calls ubmsRenderCaptcha() the first time
      // step 2 is displayed, and Google calls back into it via the onload name. ?>
<?php recaptchaScriptTag('render=explicit', 'ubmsRecaptchaReady'); ?>
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
<!-- University branding panel (presentation only, shared by all four auth
     pages): official LCUP seal + wordmark, product tagline and the campus
     tagline strip. Collapsed by CSS below 1080px, where the card takes over
     the full width. -->
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
<div class="login-wrapper login-wrapper-wide">
    <div class="login-card">
        <div class="login-brand">
            <div class="login-icon">
                <?php // Same graduation-cap mark login.php uses - the project's existing logo/icon. ?>
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 3L1 8L12 13L21 9.18V17H23V8L12 3Z" fill="white"/>
                    <path d="M5 10.18V15.18C5 17.6 8.13 19.6 12 19.6C15.87 19.6 19 17.6 19 15.18V10.18L12 13.5L5 10.18Z" fill="white"/>
                </svg>
            </div>
            <h1>Create Account</h1>
            <p>UBMSCONNECT REGISTRATION</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <strong>Please check the following:</strong>
                <?php foreach ($errors as $message): ?>
                    <div><?= e($message) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="login-form" id="registerForm" autocomplete="off" data-start-step="<?= (int)$startStep ?>">
            <div class="register-progress">
                <span class="step-dot" data-step-dot="1"><span>1</span> About you</span>
                <span class="step-dot" data-step-dot="2"><span>2</span> Details</span>
            </div>

            <!-- ===================== STEP 1 ===================== -->
            <div class="step-pane" data-step="1">
                <label>I am a&hellip;</label>
                <?php // SYSTEM ROLE (question 1 of 2): exactly two choices, and the value
                      // posted here is what becomes users.role ('host' or 'staff').
                      // "Super Admin" is never offered, and the server re-whitelists it. ?>
                <div class="affiliation-choices">
                    <label class="affiliation-choice">
                        <input type="radio" name="role" value="host" <?= $old['role'] === 'host' ? 'checked' : '' ?>>
                        <span class="ac-title">Event Host</span>
                        <span class="ac-note">Request venue activities &mdash; active right away</span>
                    </label>
                    <label class="affiliation-choice">
                        <input type="radio" name="role" value="staff" <?= $old['role'] === 'staff' ? 'checked' : '' ?>>
                        <span class="ac-title">UBMS Staff</span>
                        <span class="ac-note">Needs Super Admin approval</span>
                    </label>
                </div>

                <?php // STUDENT STATUS (question 2 of 2): a registration detail, NOT a
                      // third role - rendered as a compact toggle instead of two large
                      // cards. It only decides which Step-2 fields are shown and stored -
                      // the chosen role and its approval flow never change.
                      // The hidden field carries the default and the checkbox carries
                      // 'non_student'; PHP keeps the LAST value of a repeated field name,
                      // so checked => non_student and unchecked => student. That also
                      // means the answer still submits correctly without JavaScript. ?>
                <div class="student-toggle-row">
                    <span class="student-toggle-q">Are you a student?</span>
                    <label class="toggle-switch">
                        <input type="hidden" name="student_status" value="non_student">
                        <input type="checkbox" id="studentToggle" name="student_status" value="student"
                               aria-label="Are you a student"
                               <?= $old['student_status'] === 'student' ? 'checked' : '' ?>>
                        <span class="toggle-track" aria-hidden="true"><span class="toggle-thumb"></span></span>
                    </label>
                    <span class="student-toggle-state" id="studentToggleState"><?= $old['student_status'] === 'student' ? 'Yes' : 'No' ?></span>
                </div>
                <p class="small-note" id="studentHint" style="margin-top:4px;"><?= $old['student_status'] === 'student'
                    ? 'We&rsquo;ll ask for your course &amp; organization in Step 2 &mdash; it never changes the role you chose.'
                    : 'We&rsquo;ll ask for your UBMS office / unit in Step 2 &mdash; it never changes the role you chose.' ?></p>

                <label for="full_name">Full name</label>
                <div class="input-icon-wrap">
                    <span class="input-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="7" r="4" stroke="#5c6779" stroke-width="2"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" stroke="#5c6779" stroke-width="2"/></svg>
                    </span>
                    <input type="text" id="full_name" name="full_name" maxlength="100" placeholder="Juan Dela Cruz" required
                           class="input-with-icon <?= isset($errors['full_name']) ? 'has-error' : '' ?>" value="<?= e($old['full_name']) ?>">
                </div>

                <label for="username">Username</label>
                <div class="input-icon-wrap">
                    <span class="input-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="4" stroke="#5c6779" stroke-width="2"/><path d="M16 12v1.5a3 3 0 0 0 6 0V12a9 9 0 1 0-3.5 7.1" stroke="#5c6779" stroke-width="2"/></svg>
                    </span>
                    <input type="text" id="username" name="username" maxlength="30" placeholder="juan_delacruz" required
                           pattern="[A-Za-z0-9_-]{3,30}" title="3-30 characters: letters, numbers, underscores and hyphens only"
                           class="input-with-icon <?= isset($errors['username']) ? 'has-error' : '' ?>" value="<?= e($old['username']) ?>">
                </div>
                <p class="small-note">Choose a username you&rsquo;ll remember. You can also sign in with your school email.</p>

                <label for="email">School email</label>
                <div class="input-icon-wrap">
                    <span class="input-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="5" width="18" height="14" rx="2" stroke="#5c6779" stroke-width="2"/><path d="M3 7.5L12 13.5L21 7.5" stroke="#5c6779" stroke-width="2"/></svg>
                    </span>
                    <input type="email" id="email" name="email" maxlength="150" placeholder="juan.delacruz@university.edu.ph" required
                           class="input-with-icon <?= isset($errors['email']) ? 'has-error' : '' ?>" value="<?= e($old['email']) ?>">
                </div>

                <button type="button" class="btn btn-primary btn-block" id="nextBtn">Continue &rarr;</button>
                <p class="small-note step-note">Step 1 of 2 &middot; about 1 minute to finish.</p>
            </div>

            <!-- ===================== STEP 2 ===================== -->
            <div class="step-pane" data-step="2">
                <label for="department">Department <span class="text-muted" id="departmentHint">(required)</span></label>
                <select id="department" name="department_id">
                    <option value="">Select your department</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= (string)$d['id'] === $old['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <div data-when="student">
                    <label for="course">Course</label>
                    <select id="course" name="course_id" <?= $old['student_status'] === 'student' ? 'required' : '' ?>>
                        <option value="">Select your course</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" data-department="<?= (int)$c['department_id'] ?>"
                                <?= (string)$c['id'] === $old['course_id'] ? 'selected' : '' ?>><?= e($c['course_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="organization">Organization <span class="text-muted">(optional)</span></label>
                    <select id="organization" name="organization_id">
                        <option value="">None</option>
                        <?php foreach ($organizations as $o): ?>
                            <option value="<?= (int)$o['id'] ?>" <?= (string)$o['id'] === $old['organization_id'] ? 'selected' : '' ?>><?= e($o['organization_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                </div>

                <div data-when="non_student">
                    <label for="office_unit">UBMS office / unit</label>
                    <input type="text" id="office_unit" name="office_unit" maxlength="150" placeholder="e.g. UBMS Maintenance Office" <?= $old['student_status'] !== 'student' ? 'required' : '' ?>
                           class="<?= isset($errors['office_unit']) ? 'has-error' : '' ?>" value="<?= e($old['office_unit']) ?>">

                </div>

                <?php // Role notes stay visible for BOTH student answers - they explain
                      // the approval flow of the chosen role, not a field group. ?>
                <div data-role-when="host">
                    <div class="readonly-note">Event Host accounts are active right away &mdash; you can sign in as soon as you finish.</div>
                </div>
                <div data-role-when="staff">
                    <div class="readonly-note">UBMS Staff accounts are verified by a Super Admin before the first sign-in. You will be notified once approved.</div>
                </div>

                <label for="password">Password</label>
                <div class="input-icon-wrap">
                    <span class="input-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="5" y="11" width="14" height="9" rx="2" stroke="#5c6779" stroke-width="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4" stroke="#5c6779" stroke-width="2"/></svg>
                    </span>
                    <input type="password" id="password" name="password" class="input-with-icon <?= isset($errors['password']) ? 'has-error' : '' ?>"
                           minlength="6" placeholder="At least 6 characters" required>
                    <button type="button" class="password-toggle" id="passwordToggle" aria-label="Show password">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z" stroke="#5c6779" stroke-width="2"/><circle cx="12" cy="12" r="3" stroke="#5c6779" stroke-width="2"/></svg>
                    </button>
                </div>

                <?php if (recaptchaEnabled()): ?>
                    <div class="recaptcha-field">
                        <?php // Explicit render target: deliberately NOT class="g-recaptcha", so
                              // Google's auto-render leaves it alone and the wizard controls when
                              // it is drawn (see ubmsRenderCaptcha() in the script below). ?>
                        <div id="recaptchaBox" data-sitekey="<?= e(RECAPTCHA_SITE_KEY) ?>"></div>
                    </div>
                    <?php endif; ?>

                    <div class="step-actions">
                    <button type="button" class="btn" id="backBtn">&larr; Back</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Create Account</button>
                </div>
            </div>
        </form>

        <div class="login-demo">
            <p>Already have an account? <a href="/UBMSConnect/login.php">Sign in</a></p>
        </div>
    </div><!-- /.login-card -->
</div><!-- /.login-wrapper -->
</div><!-- /.auth-layout -->
<div class="login-footer-links">
    <span>Privacy Policy</span> | <span>Terms of Service</span> | <span>System Status</span>
</div>

<script>
/* Progressive enhancement only: without JavaScript both steps simply stay
   visible and the form still submits to the same server-side validation. */
(function () {
    var form = document.getElementById('registerForm');
    if (!form) return;

    var panes = form.querySelectorAll('.step-pane');
    var dots = document.querySelectorAll('.step-dot');
    var nextBtn = document.getElementById('nextBtn');
    var backBtn = document.getElementById('backBtn');
    var step = parseInt(form.getAttribute('data-start-step'), 10) || 1;

    form.className += ' js-stepper';

    /* ---- reCAPTCHA (step 2 only) ----------------------------------------
       The widget sits inside step 2, which starts hidden, and Google's
       auto-render would size it while it is invisible. So the library is loaded
       with render=explicit and drawn here instead - on demand, the first time
       step 2 becomes visible. Google may finish loading before or after that
       moment, so both entry points call this same function. It is a no-op until
       the library exists, the box exists, step 2 is showing, and no widget has
       been created yet, which makes it safe to call repeatedly. */
    var recaptchaWidgetId = null;

    function ubmsRenderCaptcha() {
        if (step !== 2 || recaptchaWidgetId !== null) return;
        var box = document.getElementById('recaptchaBox');
        if (!box || !window.grecaptcha || typeof grecaptcha.render !== 'function') return;
        recaptchaWidgetId = grecaptcha.render(box, { sitekey: box.getAttribute('data-sitekey') });
    }

    // Google calls this once api.js?onload fires (see recaptchaScriptTag() in
    // includes/recaptcha.php). It has to be global, hence attached to window.
    window.ubmsRecaptchaReady = ubmsRenderCaptcha;

    function showStep(number) {
        step = number;
        panes.forEach(function (pane) {
            pane.hidden = parseInt(pane.getAttribute('data-step'), 10) !== number;
        });
        dots.forEach(function (dot) {
            var dotStep = parseInt(dot.getAttribute('data-step-dot'), 10);
            dot.className = 'step-dot' + (dotStep === number ? ' active' : (dotStep < number ? ' done' : ''));
        });
        // Draw the captcha the first time step 2 is shown (safe no-op otherwise).
        if (number === 2) ubmsRenderCaptcha();

        window.scrollTo(0, 0);
    }

    function selectedValue(name) {
        var checked = form.querySelector('input[name="' + name + '"]:checked');
        return checked ? checked.value : null;
    }

    /* Two INDEPENDENT dimensions drive Step 2: the student status (data-when)
       chooses the field group, the system role (data-role-when) chooses the
       approval note. Neither ever forces the other - the server re-checks
       both, and only ever requires what is actually on screen. */
    function applyConditions() {
        var student = selectedValue('student_status') === 'student';
        var role = selectedValue('role') || 'host';

        form.querySelectorAll('[data-when="student"]').forEach(function (el) { el.hidden = !student; });
        form.querySelectorAll('[data-when="non_student"]').forEach(function (el) { el.hidden = student; });
        form.querySelectorAll('[data-role-when]').forEach(function (el) {
            el.hidden = el.getAttribute('data-role-when') !== role;
        });
        form.querySelectorAll('.affiliation-choice').forEach(function (card) {
            var radio = card.querySelector('input[type="radio"]');
            card.classList.toggle('selected', !!(radio && radio.checked));
        });
        document.getElementById('department').required = student;
        document.getElementById('course').required = student;
        document.getElementById('office_unit').required = !student;
        document.getElementById('departmentHint').textContent = student ? '(required)' : '(optional)';
        // Compact toggle: keep the Yes/No label and the Step-2 hint in sync with
        // the same `student` value that drives the field groups above.
        document.getElementById('studentToggleState').textContent = student ? 'Yes' : 'No';
        document.getElementById('studentHint').textContent = student
            ? "We'll ask for your course & organization in Step 2 \u2014 it never changes the role you chose."
            : "We'll ask for your UBMS office / unit in Step 2 \u2014 it never changes the role you chose.";
        if (!student) filterCourses();
    }

    /* Courses follow the chosen department (they come from the official list). */
    function filterCourses() {
        var department = document.getElementById('department').value;
        var course = document.getElementById('course');
        var matches = 0;
        course.querySelectorAll('option[data-department]').forEach(function (option) {
            var match = option.getAttribute('data-department') === department;
            option.hidden = !match;
            if (!match && option.selected) option.selected = false;
            if (match) matches++;
        });
        course.options[0].textContent = department && matches === 0
            ? 'No course listed for this department yet'
            : 'Select your course';
        if (!department) course.value = '';
    }

    function firstInvalid(selector) {
        var fields = form.querySelectorAll(selector);
        for (var i = 0; i < fields.length; i++) {
            if (fields[i].closest('[hidden]')) continue;
            if (!fields[i].checkValidity()) {
                fields[i].reportValidity();
                return true;
            }
        }
        return false;
    }

    nextBtn.addEventListener('click', function () {
        if (!firstInvalid('.step-pane[data-step="1"] input')) showStep(2);
    });
    backBtn.addEventListener('click', function () { showStep(1); });

    form.addEventListener('submit', function () {
        if (firstInvalid('.step-pane[data-step="2"] input, .step-pane[data-step="2"] select')) {
            showStep(2);
        }
    });

    form.querySelectorAll('input[name="role"], input[name="student_status"]').forEach(function (radio) {
        radio.addEventListener('change', applyConditions);
    });
    document.getElementById('department').addEventListener('change', filterCourses);
    document.getElementById('passwordToggle').addEventListener('click', function () {
        var password = document.getElementById('password');
        password.type = password.type === 'password' ? 'text' : 'password';
    });

    applyConditions();
    showStep(step);
})();
</script>
<script src="/UBMSConnect/assets/js/auth-bg.js"></script>
</body>
</html>
