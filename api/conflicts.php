<?php
/**
 * api/conflicts.php
 * JSON endpoint for venue schedule conflict detection (moderator item 11).
 *
 *   action=check&id=N[&include_completed=1]
 *       Activities that overlap activity N at the same venue on the same date.
 *       Any logged-in user may call this, because the data it reads is already
 *       visible to them: the Schedule Availability panel lists the same
 *       activity names for every venue. An Event Host may only check THEIR OWN
 *       records - the check is enforced here, not in the UI.
 *
 *   action=count
 *       How many conflicts are currently detected (excluding Completed
 *       records). UBMS Staff and Super Admin only, used for the live badge.
 *
 * Read-only: this endpoint never writes. The only write path is
 * actions/update_status.php, which applies the approval guard.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/conflicts.php';

header('Content-Type: application/json');
requireLoginApi();

$action           = $_GET['action'] ?? 'check';
$includeCompleted = !empty($_GET['include_completed']);

/* ---------------------------------------------------------
 * action=count
 * --------------------------------------------------------- */
if ($action === 'count') {
    requireRoleApi(['staff', 'super_admin']);

    try {
        $conflicts = findVenueConflicts(['include_completed' => $includeCompleted]);
        $summary   = summarizeConflicts($conflicts);

        echo json_encode([
            'success'           => true,
            'total'             => (int) $summary['total'],
            'confirmed'         => (int) $summary['confirmed'],
            'potential'         => (int) $summary['potential'],
            'historical'        => (int) $summary['historical'],
            'venues_affected'   => (int) $summary['venue_count'],
            'generated_at'      => date('c'),
        ]);
    } catch (Exception $e) {
        error_log('[UBMSConnect] api/conflicts.php count error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not count schedule conflicts.']);
    }
    exit;
}

/* ---------------------------------------------------------
 * action=check (default)
 * --------------------------------------------------------- */
if ($action !== 'check') {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

$activityId = (int) ($_GET['id'] ?? 0);
if ($activityId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid activity id.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.activity_name, a.status, a.host_id, a.activity_date,
                a.venue_id, v.venue_name, v.building
           FROM activities a
           JOIN venues v ON v.id = a.venue_id
          WHERE a.id = ?"
    );
    $stmt->execute([$activityId]);
    $activity = $stmt->fetch();

    if (!$activity) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Activity not found.']);
        exit;
    }

    /* An Event Host may only inspect their own records. Staff and Super Admin
       monitor the whole campus. */
    if (isHost() && (int) $activity['host_id'] !== (int) currentUserId()) {
        respondAuthError(403, 'You may only check schedule conflicts for your own activities.');
    }

    $conflicts = activityConflicts($activityId, $includeCompleted, true);
    $summary   = summarizeConflicts($conflicts);

    /* Add display-ready fields so the front end stays a dumb renderer. */
    foreach ($conflicts as &$conflict) {
        $conflict['severity_label']   = conflictSeverityLabel($conflict['severity']);
        $conflict['start_fmt']        = formatTime($conflict['start']);
        $conflict['end_fmt']          = formatTime($conflict['end']);
        $conflict['overlap_start_fmt']= formatTime($conflict['overlap_start']);
        $conflict['overlap_end_fmt']  = formatTime($conflict['overlap_end']);
        $conflict['overlap_fmt']      = formatOverlapMinutes($conflict['overlap_minutes']);
    }
    unset($conflict);

    echo json_encode([
        'success'      => true,
        'activity'     => [
            'id'           => (int) $activity['id'],
            'name'         => $activity['activity_name'],
            'status'       => $activity['status'],
            'activity_date'=> $activity['activity_date'],
            'date_fmt'     => formatDate($activity['activity_date']),
            'venue_name'   => $activity['venue_name'],
            'building'     => $activity['building'],
        ],
        'conflicts'    => $conflicts,
        'total'        => (int) $summary['total'],
        'confirmed'    => (int) $summary['confirmed'],
        'potential'    => (int) $summary['potential'],
        'historical'   => (int) $summary['historical'],
        'blocking'     => count(blockingConflictsFor($activityId)),
        'generated_at' => date('c'),
    ]);
} catch (Exception $e) {
    error_log('[UBMSConnect] api/conflicts.php check error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not check schedule conflicts.']);
}
