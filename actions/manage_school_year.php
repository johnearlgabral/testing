<?php
/**
 * actions/manage_school_year.php
 * Super-Admin-only. Create / edit / activate / delete school years, and
 * re-sync the snapshot columns on activities (moderator item 8).
 *
 * Validated server-side (not just in the UI):
 *   - name and both dates are required, start must be before end
 *   - a new/edited range may not overlap another school year, otherwise
 *     "which year does this activity belong to" becomes ambiguous
 *   - only one school year can be active at a time
 *   - the last remaining school year cannot be deleted
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/school_year.php';

header('Content-Type: application/json');
requireRoleApi('super_admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = $_POST['action'] ?? '';

function schoolYearFail($message) {
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/** Returns a valid Y-m-d string, or null when the input is not a real date. */
function cleanDate($value) {
    $value = trim((string) $value);
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : null;
}

/** Name of another school year whose range overlaps this one (null when none). */
function overlappingSchoolYear($start, $end, $exceptId = null) {
    global $pdo;
    $sql = 'SELECT name FROM school_years WHERE start_date <= ? AND end_date >= ?';
    $params = [$end, $start];
    if ($exceptId) {
        $sql .= ' AND id != ?';
        $params[] = $exceptId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() ?: null;
}

/** Reads + validates the shared add/update fields. */
function readSchoolYearFields($exceptId = null) {
    $name  = trim($_POST['name'] ?? '');
    $start = cleanDate($_POST['start_date'] ?? '');
    $end   = cleanDate($_POST['end_date'] ?? '');

    if ($name === '') schoolYearFail('Please enter a name for the school year (e.g. AY 2026-2027).');
    if (mb_strlen($name) > 60) schoolYearFail('That name is too long (60 characters maximum).');
    if (!$start || !$end) schoolYearFail('Please provide both dates in YYYY-MM-DD format.');
    if ($start >= $end) schoolYearFail('The start date must be before the end date.');

    $clash = overlappingSchoolYear($start, $end, $exceptId);
    if ($clash) schoolYearFail('That range overlaps "' . $clash . '". School year ranges must not overlap.');

    return [$name, $start, $end];
}

switch ($action) {

    case 'add': {
        [$name, $start, $end] = readSchoolYearFields();
        $makeActive = !empty($_POST['is_active']);

        try {
            if ($makeActive) $pdo->exec('UPDATE school_years SET is_active = 0');
            $stmt = $pdo->prepare('INSERT INTO school_years (name, start_date, end_date, is_active) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $start, $end, $makeActive ? 1 : 0]);
        } catch (Exception $e) {
            schoolYearFail('Could not save that school year - the name may already be used.');
        }

        $id = (int) $pdo->lastInsertId();
        logAudit('Created School Year', 'school_year', $id, $name, null,
            $start . ' to ' . $end . ($makeActive ? ' (active)' : ''));

        $sync = resyncActivitySnapshots();
        echo json_encode([
            'success' => true,
            'message' => 'School year added. ' . $sync['SchoolYear'] . ' existing activity record(s) were matched into a school year.',
        ]);
        break;
    }

    case 'update': {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) schoolYearFail('Invalid school year.');

        $stmt = $pdo->prepare('SELECT * FROM school_years WHERE id = ?');
        $stmt->execute([$id]);
        $previous = $stmt->fetch();
        if (!$previous) schoolYearFail('School year not found.');

        [$name, $start, $end] = readSchoolYearFields($id);

        try {
            $stmt = $pdo->prepare('UPDATE school_years SET name = ?, start_date = ?, end_date = ? WHERE id = ?');
            $stmt->execute([$name, $start, $end, $id]);
        } catch (Exception $e) {
            schoolYearFail('Could not save those changes - the name may already be used.');
        }

        logAudit('Updated School Year', 'school_year', $id, $name,
            $previous['start_date'] . ' to ' . $previous['end_date'],
            $start . ' to ' . $end);

        $sync = resyncActivitySnapshots();
        echo json_encode([
            'success' => true,
            'message' => 'School year updated. ' . $sync['SchoolYear'] . ' activity record(s) were re-matched to a school year.',
        ]);
        break;
    }

    case 'activate': {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT name FROM school_years WHERE id = ?');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if (!$name) schoolYearFail('School year not found.');

        $pdo->exec('UPDATE school_years SET is_active = 0');
        $pdo->prepare('UPDATE school_years SET is_active = 1 WHERE id = ?')->execute([$id]);

        logAudit('Activated School Year', 'school_year', $id, $name);
        echo json_encode(['success' => true, 'message' => $name . ' is now the active school year.']);
        break;
    }

    case 'delete': {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) schoolYearFail('Invalid school year.');

        $total = (int) $pdo->query('SELECT COUNT(*) FROM school_years')->fetchColumn();
        if ($total <= 1) schoolYearFail('At least one school year must exist. Add another one before deleting this.');

        $stmt = $pdo->prepare('SELECT name FROM school_years WHERE id = ?');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if (!$name) schoolYearFail('School year not found.');

        // activities.school_year_id is ON DELETE SET NULL, so no monitoring history
        // is lost - those records simply become "not assigned to a school year"
        // until you re-sync them against the remaining school years.
        $count = $pdo->prepare('SELECT COUNT(*) FROM activities WHERE school_year_id = ?');
        $count->execute([$id]);
        $affected = (int) $count->fetchColumn();

        $pdo->prepare('DELETE FROM school_years WHERE id = ?')->execute([$id]);
        logAudit('Deleted School Year', 'school_year', $id, $name, null, $affected . ' activity record(s) unassigned');

        echo json_encode([
            'success' => true,
            'message' => $name . ' deleted. ' . $affected . ' activity record(s) are now unassigned - use Re-sync to place them in another school year.',
        ]);
        break;
    }

    case 'resync': {
        $sync = resyncActivitySnapshots();
        $unassigned = activitiesWithoutSchoolYear();
        logAudit('Re-synced Activity School Years', 'activity', null, 'All activities', null,
            $sync['SchoolYear'] . ' record(s) updated, ' . $sync['Organization'] . ' organization(s) filled in');

        $message = 'Re-sync complete. ' . $sync['SchoolYear'] . ' activity record(s) were updated with a matching school year, and '
            . $sync['Organization'] . ' missing organization(s) were filled in from the host account.';
        if ($unassigned > 0) {
            $message .= ' ' . $unassigned . ' record(s) still fall outside every defined range - add the matching school year, then re-sync again.';
        }

        echo json_encode(['success' => true, 'message' => $message]);
        break;
    }

    default:
        schoolYearFail('Unknown action.');
}
