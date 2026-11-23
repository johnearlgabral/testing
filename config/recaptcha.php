<?php
/**
 * UBMSConnect - Google reCAPTCHA v2 ("I'm not a robot") configuration
 *
 * Every value comes from the environment (see config/env.php and .env.example):
 *
 *   RECAPTCHA_ENABLED     "true" / "false" - master switch for the feature.
 *                         When false, no widget is rendered and no token is
 *                         verified, so login and registration behave exactly
 *                         as they did before reCAPTCHA existed.
 *   RECAPTCHA_SITE_KEY    Public key. Safe to expose in HTML.
 *   RECAPTCHA_SECRET_KEY  Private key. Used only by the server-side verifier.
 *   RECAPTCHA_MIN_SCORE   Only meaningful for a score-based (v3) rollout; the
 *                         checkbox flow ignores it. Kept so the setting exists
 *                         in one place if the project ever switches.
 *
 * The secret is never written into this file: put it in .env (blocked from HTTP
 * access by .htaccess) or in a real environment variable / Apache SetEnv.
 */

require_once __DIR__ . '/env.php';

define('RECAPTCHA_SITE_KEY',   (string) env('RECAPTCHA_SITE_KEY', ''));
define('RECAPTCHA_SECRET_KEY', (string) env('RECAPTCHA_SECRET_KEY', ''));
define('RECAPTCHA_MIN_SCORE',  (float)  env('RECAPTCHA_MIN_SCORE', 0.5));

// Google's verification endpoint. Still the correct and supported endpoint now
// that reCAPTCHA sits inside Google Cloud Fraud Defense ("existing site keys and
// integrations continue to work without any code or configuration changes").
define('RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify');

// Client library for the checkbox widget.
define('RECAPTCHA_SCRIPT_URL', 'https://www.google.com/recaptcha/api.js');

// Network timeout (seconds) for the verification call.
define('RECAPTCHA_VERIFY_TIMEOUT', 5);

/**
 * The feature is active only when it is switched on AND both keys are present.
 *
 * A half-configured deployment therefore fails safe into "no captcha" instead of
 * showing every visitor an unsolvable widget and locking everyone out of login.
 */
define('RECAPTCHA_ENABLED',
    envBool('RECAPTCHA_ENABLED', false)
    && RECAPTCHA_SITE_KEY !== ''
    && RECAPTCHA_SECRET_KEY !== ''
);
