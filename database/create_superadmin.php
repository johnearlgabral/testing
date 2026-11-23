<?php
/**
 * database/create_superadmin.php
 *
 * SECURE, COMMAND-LINE-ONLY way to create (or reset) a Super Admin account.
 * This script refuses to run if it is ever requested through a web browser -
 * it will only execute via the PHP CLI (php.exe on XAMPP, or `php` on
 * macOS/Linux). This is the safe alternative to a public "Super Admin"
 * option on the registration form, which the system must never offer.
 *
 * USAGE (run from a terminal, inside the UBMSConnect folder):
 *
 *   Windows (XAMPP):
 *     C:\xampp\php\php.exe database\create_superadmin.php
 *
 *   macOS/Linux:
 *     php database/create_superadmin.php
 *
 * The script will interactively prompt for full name, email, username,
 * and password (input is hidden where the terminal supports it). It will
 * not accept a plaintext password as a command-line argument, so it is
 * never left sitting in shell history.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line (php database/create_superadmin.php), not through a web browser.');
}

require_once __DIR__ . '/../config/database.php';

function prompt($label) {
    echo $label . ': ';
    return trim(fgets(STDIN));
}

function promptHidden($label) {
    echo $label . ': ';
    // Attempt to hide input on systems that support `stty` (macOS/Linux).
    if (stripos(PHP_OS, 'WIN') !== 0) {
        system('stty -echo');
        $value = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
        return $value;
    }
    // Windows terminals (XAMPP's default shell) will show the typed password;
    // this is a known limitation of plain PHP CLI on Windows.
    return trim(fgets(STDIN));
}

echo "=== UBMSConnect - Create Super Admin Account ===\n\n";

$fullName = prompt('Full Name');
$email    = prompt('Email');
$username = prompt('Username');
$password = promptHidden('Password (min 6 characters)');
$confirm  = promptHidden('Confirm Password');

$errors = [];
if ($fullName === '') $errors[] = 'Full name is required.';
if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
    $errors[] = 'Username must be 3-50 characters (letters, numbers, dot, dash, underscore only).';
}
if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
if ($password !== $confirm) $errors[] = 'Passwords do not match.';

if (!empty($errors)) {
    echo "\nCould not create account:\n";
    foreach ($errors as $e) echo " - $e\n";
    exit(1);
}

$check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$check->execute([$username]);
if ($check->fetch()) {
    echo "\nA user with that username already exists. Aborting.\n";
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare(
    "INSERT INTO users (username, password, full_name, email, role, status, created_at)
     VALUES (?, ?, ?, ?, 'super_admin', 'active', NOW())"
);
$stmt->execute([$username, $hash, $fullName, $email ?: null]);
$newId = (int) $pdo->lastInsertId();

// Best-effort audit entry. Uses NULL actor_id since this was done outside
// any web session (there is no logged-in user at this point).
try {
    $log = $pdo->prepare(
        "INSERT INTO audit_logs (actor_id, actor_name, action, target_type, target_id, target_label, created_at)
         VALUES (NULL, 'CLI Setup Script', 'Created Super Admin (CLI)', 'user', ?, ?, NOW())"
    );
    $log->execute([$newId, "$username ($fullName)"]);
} catch (Exception $e) {
    // Non-fatal if audit_logs doesn't exist yet.
}

echo "\nSuper Admin account created successfully.\n";
echo "Username: $username\n";
echo "You can now log in at http://localhost/UBMSConnect/login.php\n";
