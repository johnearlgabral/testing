<?php
/**
 * Shared photo-upload helpers for venue and utility images.
 *
 * Extracted from actions/manage_venue.php so every entity that stores a photo
 * under assets/uploads/<subdir>/ follows exactly one implementation: the same
 * 3MB cap, the same JPG/PNG/WebP whitelist, the same
 * "<prefix>_<id>_<random>.<ext>" filename shape, and the same
 * delete-old-file-on-replace behaviour. The noun parameter keeps the original
 * per-entity error messages ("The venue image could not be uploaded...", ...).
 */

/** Returns an error message for the uploaded file, or null when it is fine (or absent). */
function imageUploadError($file, $noun = 'image') {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'The ' . $noun . ' could not be uploaded. Please try again.';
    }
    if ($file['size'] <= 0 || $file['size'] > 3 * 1024 * 1024) {
        return ucfirst($noun) . 's must be smaller than 3MB.';
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || @getimagesize($file['tmp_name']) === false) {
        return 'Please upload a valid JPG, PNG, or WebP image.';
    }
    return null;
}

/** Moves an uploaded photo into assets/uploads/<subdir>/ and returns the stored filename. */
function saveUploadedImage($file, $subdir, $prefix, $entityId, $noun = 'image') {
    $mimeToExtension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $filename = $prefix . '_' . (int) $entityId . '_' . bin2hex(random_bytes(12)) . '.' . $mimeToExtension[$mime];
    $uploadDir = __DIR__ . '/../assets/uploads/' . $subdir . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('The ' . $noun . ' folder is not available.');
    }
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        throw new RuntimeException('The ' . $noun . ' could not be saved.');
    }
    return $filename;
}

/** Deletes a previously stored upload (basename() defends against path traversal). */
function deleteUploadedImage($subdir, $filename) {
    if (!$filename) return;
    $path = __DIR__ . '/../assets/uploads/' . $subdir . '/' . basename($filename);
    if (is_file($path)) @unlink($path);
}
