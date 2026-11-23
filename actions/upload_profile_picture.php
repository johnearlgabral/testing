<?php
/**
 * actions/upload_profile_picture.php
 * Self-service only - always operates on currentUserId(), never accepts
 * a target user id, so a logged-in user can only ever change THEIR OWN
 * profile picture.
 *
 * Security layers (defense in depth, not relying on any single check):
 *   1. Upload error code checked.
 *   2. File size limit enforced.
 *   3. MIME type checked via finfo (actual file content, not the
 *      browser-supplied Content-Type, which a client can lie about).
 *   4. getimagesize() must succeed - this parses the file as an image
 *      and fails on non-image content even if the MIME/extension were
 *      spoofed, catching the classic "renamed .php file" attack.
 *   5. The stored filename is entirely server-generated
 *      (random_bytes-based) - the original filename is never used,
 *      extension included, so there is no path/extension injection
 *      vector from the client.
 *   6. The uploads/profile directory has its own .htaccess disabling
 *      script execution entirely, as a second independent layer.
 */

require_once __DIR__ . '/../includes/auth.php';
$__auditHelper = __DIR__ . '/../includes/audit.php';
if (file_exists($__auditHelper)) {
    require_once $__auditHelper;
} elseif (!function_exists('logAudit')) {
    function logAudit($action, $targetType = null, $targetId = null, $targetLabel = null, $previousValue = null, $newValue = null) {
        error_log('[UBMSConnect] logAudit() called but includes/audit.php is missing - action not recorded: ' . $action);
    }
}
header('Content-Type: application/json');
requireLoginApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (empty($_FILES['picture'])) {
    echo json_encode(['success' => false, 'message' => 'No file was uploaded.']);
    exit;
}

$file = $_FILES['picture'];

// 1. Upload error check
if ($file['error'] !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE   => 'The file is larger than this server allows.',
        UPLOAD_ERR_FORM_SIZE  => 'The file is larger than allowed.',
        UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
    ];
    echo json_encode(['success' => false, 'message' => $messages[$file['error']] ?? 'Upload failed. Please try again.']);
    exit;
}

// 2. Size limit (2MB)
$maxBytes = 2 * 1024 * 1024;
if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
    echo json_encode(['success' => false, 'message' => 'Image must be smaller than 2MB.']);
    exit;
}

// 3. MIME type check via the actual file content (not the client-supplied type)
$allowedMimes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedMime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowedMimes[$detectedMime])) {
    echo json_encode(['success' => false, 'message' => 'Unsupported file type. Please upload a JPG, PNG, or WebP image.']);
    exit;
}

// 4. Confirm it is genuinely a valid, decodable image (catches disguised non-image files)
$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    echo json_encode(['success' => false, 'message' => 'That file is not a valid image.']);
    exit;
}

// 5. Server-generated filename - never derived from the client-supplied name
$extension = $allowedMimes[$detectedMime];
$userId = currentUserId();
$newFilename = 'user_' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $extension;

$uploadDir = __DIR__ . '/../assets/uploads/profile/';
$destination = $uploadDir . $newFilename;

if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server upload directory is not available. Please contact a Super Admin.']);
    exit;
}

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded image. Please try again.']);
    exit;
}

// Look up the previous picture BEFORE updating the DB, so we can safely
// remove the old file only after the new one is confirmed stored.
$stmt = $pdo->prepare('SELECT profile_picture, username FROM users WHERE id = ?');
$stmt->execute([$userId]);
$existing = $stmt->fetch();

try {
    $upd = $pdo->prepare('UPDATE users SET profile_picture = ? WHERE id = ?');
    $upd->execute([$newFilename, $userId]);
} catch (Exception $e) {
    // DB update failed - remove the file we just wrote so we don't leave an orphan.
    @unlink($destination);
    http_response_code(500);
    error_log('[UBMSConnect] upload_profile_picture.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save your profile picture. Please try again.']);
    exit;
}

// Only now, after the new file is confirmed stored AND the DB is updated,
// remove the old file (if any) to avoid ever deleting it prematurely.
if (!empty($existing['profile_picture'])) {
    $oldPath = $uploadDir . basename($existing['profile_picture']);
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

logAudit('Changed Profile Picture', 'user', $userId, $existing['username']);

echo json_encode([
    'success' => true,
    'message' => 'Profile picture updated.',
    'url' => '/UBMSConnect/assets/uploads/profile/' . $newFilename,
]);
