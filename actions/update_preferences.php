<?php
/**
 * actions/update_preferences.php
 * Self-service dashboard-display and notification-category preferences.
 * Like update_profile.php, this ALWAYS operates on currentUserId() only.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE since a user may not have a
 * preferences row yet (defaults are all-on until they first save here).
 */

require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLoginApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$userId = currentUserId();
$type = $_POST['type'] ?? '';

function preferenceCheckboxValue($key) {
    return isset($_POST[$key]) && $_POST[$key] === '1' ? 1 : 0;
}

try {
    if ($type === 'dashboard') {
        $view = in_array($_POST['calendar_default_view'] ?? '', ['month', 'list'], true) ? $_POST['calendar_default_view'] : 'month';

        $stmt = $pdo->prepare(
            "INSERT INTO dashboard_preferences (user_id, show_stats, show_venues, show_utilities, show_maintenance, show_recent, calendar_default_view)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE show_stats=VALUES(show_stats), show_venues=VALUES(show_venues),
                 show_utilities=VALUES(show_utilities), show_maintenance=VALUES(show_maintenance),
                 show_recent=VALUES(show_recent), calendar_default_view=VALUES(calendar_default_view)"
        );
        $stmt->execute([
            $userId, preferenceCheckboxValue('show_stats'), preferenceCheckboxValue('show_venues'), preferenceCheckboxValue('show_utilities'),
            preferenceCheckboxValue('show_maintenance'), preferenceCheckboxValue('show_recent'), $view,
        ]);

        echo json_encode(['success' => true, 'message' => 'Dashboard preferences saved.']);

    } elseif ($type === 'notifications') {
        $stmt = $pdo->prepare(
            "INSERT INTO notification_preferences (user_id, activity_updates, maintenance_updates, account_updates, admin_updates)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE activity_updates=VALUES(activity_updates), maintenance_updates=VALUES(maintenance_updates),
                 account_updates=VALUES(account_updates), admin_updates=VALUES(admin_updates)"
        );
        $stmt->execute([
            $userId, preferenceCheckboxValue('activity_updates'), preferenceCheckboxValue('maintenance_updates'),
            preferenceCheckboxValue('account_updates'), preferenceCheckboxValue('admin_updates'),
        ]);

        echo json_encode(['success' => true, 'message' => 'Notification preferences saved.']);

    } else {
        echo json_encode(['success' => false, 'message' => 'Unknown preference type.']);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log('[UBMSConnect] update_preferences.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save preferences. Please try again.']);
}
