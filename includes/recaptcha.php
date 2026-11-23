<?php
/**
 * includes/recaptcha.php
 * Google reCAPTCHA v2 ("I'm not a robot" checkbox) - rendering + server-side
 * verification.
 *
 * A widget on its own proves nothing. A script can download the form, skip the
 * JavaScript entirely and POST the fields directly, never touching the checkbox.
 * The checkbox only becomes real protection because of the server-side round
 * trip in recaptchaVerify() below: Google is asked whether the single-use token
 * in g-recaptcha-response was genuinely issued for this site, and that answer is
 * what decides whether the login/registration proceeds.
 *
 * Usage from login.php / register.php:
 *   require_once __DIR__ . '/includes/recaptcha.php';
 *   $captcha = recaptchaVerify($_POST['g-recaptcha-response'] ?? '', $ip);
 *   if (!$captcha['ok']) { ... reject with $captcha['message'] ... }
 *
 * Include this file AFTER includes/functions.php (auth.php already does that;
 * the require below keeps the file usable on its own too).
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/recaptcha.php';

/** True when the captcha is configured and switched on. */
function recaptchaEnabled() {
    return RECAPTCHA_ENABLED;
}

/**
 * User-facing sentence for one reCAPTCHA result code.
 * Error codes per https://developers.google.com/recaptcha/docs/verify
 */
function recaptchaErrorMessage($code) {
    switch ($code) {
        // The visitor can fix these themselves by retrying.
        case 'missing-input-response':
            return "Please tick the \"I'm not a robot\" box to continue.";
        case 'invalid-input-response':
        case 'timeout-or-duplicate':
            return 'The reCAPTCHA check expired or was already used. Please tick the box again.';
        case 'bad-request':
            return 'The reCAPTCHA check could not be read by Google. Please try again.';

        // Our own codes, for failures that are not the visitor's fault.
        case 'network-error':
        case 'unexpected-response':
            return 'We could not reach the reCAPTCHA service. Please check your connection and try again.';

        default:
            // Covers missing-input-secret / invalid-input-secret - a server
            // configuration problem, so never phrase it as visitor error.
            return 'reCAPTCHA verification is temporarily unavailable. Please try again later.';
    }
}

/** Codes that mean "our keys are wrong", not "the visitor failed the check". */
function recaptchaIsConfigError(array $codes) {
    foreach (['missing-input-secret', 'invalid-input-secret'] as $fatal) {
        if (in_array($fatal, $codes, true)) return true;
    }
    return false;
}

/**
 * Ask Google whether $token is a valid, unused reCAPTCHA solution for this site.
 *
 * Always returns an array so the caller can log the detail while showing the
 * visitor only one friendly sentence:
 *   ok       bool    true only when Google confirmed the token
 *   message  string  user-facing text ('' when ok)
 *   codes    array   raw error-codes from Google (empty when ok)
 *   score    ?float  null for v2; populated only by a score-based rollout
 *   action   ?string as above
 *   fatal    bool    true when the failure is our own misconfiguration
 */
function recaptchaVerify($token, $remoteIp = '') {
    $result = [
        'ok'      => false,
        'message' => '',
        'codes'   => [],
        'score'   => null,
        'action'  => null,
        'fatal'   => false,
    ];

    // Feature switched off (or keys missing): never block anybody.
    if (!recaptchaEnabled()) {
        $result['ok'] = true;
        return $result;
    }

    $token = is_string($token) ? trim($token) : '';

    // Cheap local short-circuit: an empty box means the token is definitely
    // missing, so there is no reason to spend a network round trip on it.
    if ($token === '') {
        $result['codes']   = ['missing-input-response'];
        $result['message'] = recaptchaErrorMessage('missing-input-response');
        return $result;
    }

    $post = [
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $token,
    ];
    if ($remoteIp !== '') $post['remoteip'] = $remoteIp;

    $body = recaptchaPost(RECAPTCHA_VERIFY_URL, $post);
    if ($body === false) {
        $result['codes']   = ['network-error'];
        $result['message'] = recaptchaErrorMessage('network-error');
        return $result;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['success'])) {
        // Something that is not a reCAPTCHA response (proxy error page, HTML
        // captive portal, empty body). Log it - this is a deployment problem.
        error_log('reCAPTCHA: unreadable verification response: ' . substr((string) $body, 0, 300));
        $result['codes']   = ['unexpected-response'];
        $result['message'] = recaptchaErrorMessage('unexpected-response');
        return $result;
    }

    if (!empty($decoded['success'])) {
        $result['ok']     = true;
        $result['score']  = isset($decoded['score']) ? (float) $decoded['score'] : null;
        $result['action'] = $decoded['action'] ?? null;
        return $result;
    }

    $codes = array_values(array_filter((array) ($decoded['error-codes'] ?? [])));
    if (!$codes) $codes = ['invalid-input-response'];

    $result['codes']   = $codes;
    $result['fatal']   = recaptchaIsConfigError($codes);
    $result['message'] = recaptchaErrorMessage($codes[0]);

    // A wrong or missing secret is a deployment bug, not user input - make it
    // loud in the log instead of showing a confusing message to visitors.
    if ($result['fatal']) {
        error_log('reCAPTCHA: configuration error (check RECAPTCHA_SECRET_KEY): ' . implode(', ', $codes));
    }

    return $result;
}

/**
 * POST to Google and return the raw response body, or false on any failure.
 *
 * Uses cURL when the extension is available and falls back to an fopen stream
 * context (allow_url_fopen is On in a stock XAMPP install, and curl.cainfo /
 * openssl.cafile already point at XAMPP's CA bundle). TLS certificates are
 * always verified - a man-in-the-middle able to return {"success":true} would
 * defeat the entire control.
 */
function recaptchaPost($url, array $post) {
    $payload = http_build_query($post);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => RECAPTCHA_VERIFY_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => RECAPTCHA_VERIFY_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'UBMSConnect/1.0',
        ]);
        $body   = curl_exec($ch);
        $errNo  = curl_errno($ch);
        $errTxt = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errNo !== 0 || $status < 200 || $status >= 300) {
            error_log('reCAPTCHA: cURL failure (HTTP ' . $status . ', errno ' . $errNo . '): ' . $errTxt);
            return false;
        }
        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                             . "User-Agent: UBMSConnect/1.0\r\n",
            'content'       => $payload,
            'timeout'       => RECAPTCHA_VERIFY_TIMEOUT,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        error_log('reCAPTCHA: stream request to ' . $url . ' failed');
        return false;
    }
    return $body;
}

/**
 * Render the checkbox FIELD, or nothing at all when the feature is switched
 * off - so setting RECAPTCHA_ENABLED=false leaves the pages visually
 * identical to the pre-reCAPTCHA version.
 *
 * The field starts hidden and deliberately carries NO .g-recaptcha class, so
 * Google's auto-render never draws it. The calling page reveals the container
 * and calls grecaptcha.render() only at the moment the check is actually
 * needed (login does that on the visitor's first attempt to sign in), which
 * keeps the widget out of the way until it is required. This is purely
 * presentation: server-side verification in recaptchaVerify() still runs on
 * every POST, so nothing is weakened by rendering late.
 *
 * login.php and register.php do not include includes/header.php and never set a
 * data-theme, so they are always light and the widget default (light) matches
 * their design without any extra configuration.
 */
function recaptchaWidget() {
    if (!recaptchaEnabled()) return;
    ?>
                <div class="recaptcha-field" id="recaptchaField" hidden>
                    <div id="recaptchaBox" data-sitekey="<?= e(RECAPTCHA_SITE_KEY) ?>"></div>
                    <p class="small-note" id="recaptchaHint" hidden>Please tick the "I'm not a robot" box, then click Sign In again.</p>
                </div>
    <?php
}

/**
 * The <script> tag for the widget library, or nothing when the feature is off.
 *
 * Uses defer rather than async so the library always executes AFTER the page's
 * own inline scripts. The registration wizard depends on that ordering: it
 * defines the global onload callback name before Google calls it back.
 */
function recaptchaScriptTag($renderMode = '', $onloadCallback = '') {
    if (!recaptchaEnabled()) return;

    $query = [];
    if ($renderMode !== '')     $query[] = $renderMode;
    if ($onloadCallback !== '') $query[] = 'onload=' . rawurlencode($onloadCallback);

    $src = RECAPTCHA_SCRIPT_URL . ($query ? '?' . implode('&', $query) : '');
    echo '<script src="' . e($src) . '" defer></script>' . "\n";
}
