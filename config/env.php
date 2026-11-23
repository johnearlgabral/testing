<?php
/**
 * UBMSConnect - Environment configuration loader
 *
 * Secrets (the reCAPTCHA keys, and anything else added later) live in a `.env`
 * file in the project root instead of being written into PHP source, so the
 * same code can run against a development key set and a production key set
 * without editing any file that is part of the application.
 *
 * `.env` is blocked from HTTP access by .htaccess, and `.env.example` documents
 * every variable the application understands.
 *
 * Deliberately dependency-free (no Composer, no vlucas/phpdotenv) because the
 * rest of this project has no third-party dependencies at all.
 */

/** Parsed .env values, keyed by variable name. */
$GLOBALS['UBMS_ENV'] = $GLOBALS['UBMS_ENV'] ?? [];

$ubmsEnvFile = dirname(__DIR__) . '/.env';
if (is_readable($ubmsEnvFile)) {
    $ubmsEnvLines = file($ubmsEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($ubmsEnvLines as $ubmsEnvLine) {
        $ubmsEnvLine = trim($ubmsEnvLine);

        // Blank lines and #comment / ;comment lines carry no value.
        if ($ubmsEnvLine === '' || $ubmsEnvLine[0] === '#' || $ubmsEnvLine[0] === ';') continue;
        if (strpos($ubmsEnvLine, '=') === false) continue;

        $ubmsEnvPair  = explode('=', $ubmsEnvLine, 2);
        $ubmsEnvKey   = trim($ubmsEnvPair[0]);
        $ubmsEnvValue = trim($ubmsEnvPair[1]);

        // Strip one layer of matching quotes, so a value may itself contain
        // '=' or '#' without being mis-parsed.
        $ubmsEnvLen = strlen($ubmsEnvValue);
        if ($ubmsEnvLen >= 2) {
            $ubmsEnvQuote = $ubmsEnvValue[0];
            if (($ubmsEnvQuote === '"' || $ubmsEnvQuote === "'")
                && $ubmsEnvValue[$ubmsEnvLen - 1] === $ubmsEnvQuote) {
                $ubmsEnvValue = substr($ubmsEnvValue, 1, $ubmsEnvLen - 2);
            }
        }

        if ($ubmsEnvKey !== '') $GLOBALS['UBMS_ENV'][$ubmsEnvKey] = $ubmsEnvValue;
    }
}
unset($ubmsEnvFile, $ubmsEnvLines, $ubmsEnvLine, $ubmsEnvPair, $ubmsEnvKey, $ubmsEnvValue, $ubmsEnvLen, $ubmsEnvQuote);

/**
 * Read a configuration value, falling back to $default when it is not set.
 *
 * Priority: real environment variable (OS environment / Apache SetEnv) first,
 * then the .env file, then $default. Never throws and never fatals, so a
 * missing or unreadable .env can never take the site down.
 */
function env($key, $default = null) {
    $value = getenv($key);
    if ($value !== false && $value !== '') return $value;

    if (isset($GLOBALS['UBMS_ENV'][$key]) && $GLOBALS['UBMS_ENV'][$key] !== '') {
        return $GLOBALS['UBMS_ENV'][$key];
    }

    // Apache's mod_env SetEnv values land in $_SERVER on some configurations
    // where getenv() is not populated, so check there before giving up.
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];

    return $default;
}

/** Interpret the common .env boolean spellings ("true", "1", "yes", "on"). */
function envBool($key, $default = false) {
    $value = env($key, null);
    if ($value === null) return $default;
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}
