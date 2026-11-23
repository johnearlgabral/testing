<?php
/**
 * conflicts.php - Venue schedule conflict detection (moderator item 11:
 * "Implement Venue Schedule Conflict Detection").
 *
 * Auto-detects overlapping venue occupancy from the stored activity records:
 * two activities at the same venue, on the same date, whose time ranges
 * overlap. Nothing is written here - detection is computed live from the
 * records that are already in the database, so it can never be stale.
 *
 * Access: UBMS Staff and Super Admin only (the same people who manage
 * activity status). Event Hosts see a read-only conflict warning on their own
 * activity pages instead.
 *
 * Everything is GET-driven, so a view can be bookmarked, shared, printed or
 * saved as a PDF. CSV export mirrors the reports page.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_year.php';
require_once __DIR__ . '/includes/conflicts.php';
requireRole(['staff', 'super_admin']);

$pageTitle = 'Schedule Conflicts';
$activeNav = 'conflicts';

/* ---------------------------------------------------------
 * Filter inputs
 * --------------------------------------------------------- */
$schoolYears   = getSchoolYears();
$venues        = $pdo->query('SELECT id, venue_name, building FROM venues ORDER BY venue_name')->fetchAll();
$organizations = $pdo->query('SELECT id, organization_name FROM organizations ORDER BY organization_name')->fetchAll();
$activeYear    = getActiveSchoolYear();

$fSchoolYear = $_GET['school_year'] ?? ($activeYear ? (string) $activeYear['id'] : 'all');
$fMonth      = (int) ($_GET['month'] ?? 0);
$fVenue      = (int) ($_GET['venue'] ?? 0);
$fOrg        = (int) ($_GET['organization'] ?? 0);
$fSeverity   = (string) ($_GET['severity'] ?? 'all');
$fCompleted  = !empty($_GET['include_completed']);

if (!in_array($fSeverity, array_merge(['all'], conflictSeverities()), true)) $fSeverity = 'all';
if ($fMonth < 0 || $fMonth > 12) $fMonth = 0;

/* Only an existing school year / venue / organization can be filtered on. */
$selectedSchoolYear = null;
if ($fSchoolYear !== 'all') {
    foreach ($schoolYears as $sy) {
        if ((string) $sy['id'] === (string) $fSchoolYear) $selectedSchoolYear = $sy;
    }
    if (!$selectedSchoolYear) $fSchoolYear = 'all';
}

$selectedVenue = null;
foreach ($venues as $venue) {
    if ((int) $venue['id'] === $fVenue) $selectedVenue = $venue;
}
if (!$selectedVenue) $fVenue = 0;

$selectedOrg = null;
foreach ($organizations as $org) {
    if ((int) $org['id'] === $fOrg) $selectedOrg = $org;
}
if (!$selectedOrg) $fOrg = 0;

/* ---------------------------------------------------------
 * Detect
 * --------------------------------------------------------- */
$conflicts = findVenueConflicts([
    'school_year_id'    => $selectedSchoolYear ? (int) $selectedSchoolYear['id'] : 0,
    'month'             => $fMonth,
    'venue_id'          => $fVenue,
    'organization_id'   => $fOrg,
    'severity'          => $fSeverity,
    'include_completed' => $fCompleted,
]);

$summary  = summarizeConflicts($conflicts);
$hotspots = conflictHotspots($conflicts);

/* Deliberately-approved double bookings, narrowed to whatever is on screen. */
$acknowledged = acknowledgedConflicts();
$acknowledgedRows = [];
foreach ($conflicts as $conflict) {
    if ($conflict['acknowledged']) {
        $acknowledgedRows[$conflict['pair_key']] = $conflict;
    }
}

$filterSummary = [
    'School year'         => $selectedSchoolYear ? $selectedSchoolYear['name'] : 'All school years',
    'Month'               => $fMonth ? monthLabel($fMonth) : 'All months',
    'Venue'               => $selectedVenue ? $selectedVenue['venue_name'] . ' (' . $selectedVenue['building'] . ')' : 'All venues',
    'Organization'        => $selectedOrg ? $selectedOrg['organization_name'] : 'All organizations',
    'Severity'            => $fSeverity === 'all' ? 'All severities' : conflictSeverityLabel($fSeverity),
    'Completed records'   => $fCompleted || $fSeverity === 'historical' ? 'Included' : 'Excluded',
];

$generatedAt = date('F j, Y g:i A');

/** "Today", "Tomorrow", "in 8 days", "3 days ago" for a conflict date. */
function conflictDateContext($date) {
    $today = strtotime(date('Y-m-d'));
    $when  = strtotime($date);
    if ($when === false) return '';
    $days = (int) round(($when - $today) / 86400);
    if ($days === 0)  return 'Today';
    if ($days === 1)  return 'Tomorrow';
    if ($days === -1) return 'Yesterday';
    if ($days > 0)    return 'in ' . $days . ' days';
    return abs($days) . ' days ago';
}

/** Appends one CSV row to the response stream. */
function csvRow($handle, $cells) {
    fputcsv($handle, $cells);
}

/* ---------------------------------------------------------
 * CSV export (?export=csv) - runs before any HTML is sent so the response is
 * a clean downloadable file that opens in Excel / Google Sheets.
 * --------------------------------------------------------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ubms-schedule-conflicts-' . date('Y-m-d-His') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads characters correctly

    csvRow($out, ['UBMSConnect - Venue Schedule Conflict Report']);
    csvRow($out, ['Meaning', 'Two activity records at the same venue, on the same date, whose time ranges overlap.']);
    csvRow($out, ['Generated', $generatedAt]);
    csvRow($out, ['Generated by', currentUserName() . ' (' . roleLabel(currentUserRole()) . ')']);
    foreach ($filterSummary as $label => $value) csvRow($out, [$label, $value]);
    csvRow($out, []);

    csvRow($out, ['SUMMARY']);
    csvRow($out, ['Conflicts', 'Confirmed double bookings', 'Potential double bookings', 'Historical', 'Venues affected', 'Days affected', 'Total overlap time']);
    csvRow($out, [
        (int) $summary['total'], (int) $summary['confirmed'], (int) $summary['potential'],
        (int) $summary['historical'], (int) $summary['venue_count'], (int) $summary['date_count'],
        formatOverlapMinutes($summary['minutes']),
    ]);
    csvRow($out, []);

    csvRow($out, ['CONFLICT LIST']);
    csvRow($out, [
        'Date', 'Venue', 'Building',
        'Overlap starts', 'Overlap ends', 'Overlap',
        'Severity', 'Activity A', 'A status', 'A host', 'A organization',
        'Activity B', 'B status', 'B host', 'B organization',
        'Already acknowledged',
    ]);
    foreach ($conflicts as $conflict) {
        csvRow($out, [
            $conflict['activity_date'],
            $conflict['venue_name'],
            $conflict['building'],
            formatTime($conflict['overlap_start']),
            formatTime($conflict['overlap_end']),
            formatOverlapMinutes($conflict['overlap_minutes']),
            conflictSeverityLabel($conflict['severity']),
            $conflict['a']['name'], $conflict['a']['status'], $conflict['a']['host'], $conflict['a']['organization'] ?: 'Not assigned',
            $conflict['b']['name'], $conflict['b']['status'], $conflict['b']['host'], $conflict['b']['organization'] ?: 'Not assigned',
            $conflict['acknowledged'] ? 'Yes' : 'No',
        ]);
    }
    csvRow($out, []);

    csvRow($out, ['CONFLICTS BY VENUE']);
    csvRow($out, ['Venue', 'Building', 'Conflicts', 'Confirmed', 'Potential', 'Historical', 'Days affected', 'Overlap time', 'Most recent conflict']);
    foreach ($hotspots as $hotspot) {
        csvRow($out, [
            $hotspot['venue_name'], $hotspot['building'], (int) $hotspot['total'],
            (int) $hotspot['confirmed'], (int) $hotspot['potential'], (int) $hotspot['historical'],
            (int) $hotspot['day_count'], formatOverlapMinutes($hotspot['minutes']),
            $hotspot['last_date'] ? formatDate($hotspot['last_date']) : '-',
        ]);
    }

    if ($acknowledgedRows) {
        csvRow($out, []);
        csvRow($out, ['ACKNOWLEDGED DOUBLE BOOKINGS (approved anyway)']);
        csvRow($out, ['Date', 'Venue', 'Activity', 'Conflicts with', 'Approved by', 'When', 'Times confirmed', 'Reason']);
        foreach ($acknowledgedRows as $conflict) {
            $ack = $conflict['acknowledged'];
            csvRow($out, [
                $conflict['activity_date'],
                $conflict['venue_name'],
                $conflict['a']['name'] . ' / ' . $conflict['b']['name'],
                conflictSeverityLabel($conflict['severity']),
                $ack['detected_by_name'] ?: 'Unknown',
                formatDateTime($ack['detected_at']),
                (int) $ack['override_count'],
                $ack['reason'] ?: '-',
            ]);
        }
    }

    fclose($out);
    exit;
}

$exportUrl = '/UBMSConnect/conflicts.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']));

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Venue Schedule Conflicts</h1>
            <p class="page-subtitle">Automatic detection of overlapping venue occupancy, so no venue is ever booked twice without somebody knowing.</p>
        </div>
        <div class="report-actions no-print">
            <button type="button" class="btn btn-primary" onclick="window.print()">Save as PDF / Print</button>
            <a class="btn" href="<?= e($exportUrl) ?>">Export CSV</a>
        </div>
    </div>

    <form method="GET" class="card report-filters no-print">
        <div class="card-title">Conflict filters</div>
        <div class="filter-bar" style="margin-bottom:0;">
            <div class="form-group">
                <label for="school_year">School year</label>
                <select id="school_year" name="school_year">
                    <option value="all" <?= $fSchoolYear === 'all' ? 'selected' : '' ?>>All school years</option>
                    <?php foreach ($schoolYears as $sy): ?>
                        <option value="<?= (int) $sy['id'] ?>" <?= (string) $fSchoolYear === (string) $sy['id'] ? 'selected' : '' ?>>
                            <?= e($sy['name']) ?><?= $sy['is_active'] ? ' (active)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="month">Month</label>
                <select id="month" name="month">
                    <option value="0">All months</option>
                    <?php foreach (monthOptions() as $num => $name): ?>
                        <option value="<?= (int) $num ?>" <?= $fMonth === (int) $num ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="venue">Venue</label>
                <select id="venue" name="venue">
                    <option value="0">All venues</option>
                    <?php foreach ($venues as $venue): ?>
                        <option value="<?= (int) $venue['id'] ?>" <?= $fVenue === (int) $venue['id'] ? 'selected' : '' ?>>
                            <?= e($venue['venue_name']) ?> (<?= e($venue['building']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="organization">Organization</label>
                <select id="organization" name="organization">
                    <option value="0">All organizations</option>
                    <?php foreach ($organizations as $org): ?>
                        <option value="<?= (int) $org['id'] ?>" <?= $fOrg === (int) $org['id'] ? 'selected' : '' ?>><?= e($org['organization_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="severity">Severity</label>
                <select id="severity" name="severity">
                    <option value="all" <?= $fSeverity === 'all' ? 'selected' : '' ?>>All severities</option>
                    <?php foreach (conflictSeverities() as $severity): ?>
                        <option value="<?= e($severity) ?>" <?= $fSeverity === $severity ? 'selected' : '' ?>><?= e(conflictSeverityLabel($severity)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="include_completed">Completed records</label>
                <select id="include_completed" name="include_completed">
                    <option value="" <?= !$fCompleted ? 'selected' : '' ?>>Exclude</option>
                    <option value="1" <?= $fCompleted ? 'selected' : '' ?>>Include</option>
                </select>
            </div>
            <button class="btn btn-primary">Apply filters</button>
            <a class="btn" href="/UBMSConnect/conflicts.php">Reset</a>
        </div>
        <p class="small-note" style="margin:10px 0 0;">
            Completed activities are excluded by default, because an event that already finished cannot be
            double-booked. Choose <strong>Include</strong> (or the <strong>Historical</strong> severity) to
            also surface past double bookings.
        </p>
    </form>

    <section class="card conflict-legend report-section" aria-labelledby="conflict-legend-heading">
        <div class="card-title" id="conflict-legend-heading">How a conflict is detected</div>
        <p class="small-note conflict-legend-intro">
            Two activity records are flagged when they share the <strong>same venue</strong>, fall on the
            <strong>same date</strong>, and their time ranges <strong>overlap</strong> &mdash; one starts before the
            other ends, and ends after the other starts. Records that merely touch (one ends at 11:00 AM and the
            next starts at 11:00 AM) are <em>not</em> a conflict, because back-to-back use of a venue is legitimate.
            Rejected and Maintenance records never occupy a venue, so they are never part of a conflict.
        </p>
        <div class="conflict-legend-list">
            <?php foreach (conflictSeverities() as $severity): ?>
                <div class="conflict-legend-row">
                    <span class="badge <?= conflictSeverityBadgeClass($severity) ?>"><?= e(conflictSeverityLabel($severity)) ?></span>
                    <span><?= e(conflictSeverityDescription($severity)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($summary['confirmed'] > 0): ?>
        <div class="alert alert-error no-print">
            <strong><?= (int) $summary['confirmed'] ?></strong>
            confirmed double booking<?= $summary['confirmed'] === 1 ? '' : 's' ?> in this view &mdash;
            the same venue is Approved for more than one activity at the same time.
            UBMS Staff can open a record below and change its status to resolve it.
        </div>
    <?php endif; ?>

    <?php if ($summary['total'] === 0): ?>
        <div class="card report-section">
            <div class="empty-state">
                No venue schedule conflicts match these filters.
                <br>
                <span class="small-note">
                    Every activity in this view has its venue to itself for its own time slot.
                    <?php if (!$fCompleted && $fSeverity !== 'historical'): ?>
                        Records that have already been Completed are excluded &mdash; include them if you want to
                        audit past double bookings.
                    <?php endif; ?>
                </span>
            </div>
        </div>
    <?php else: ?>

        <section class="stat-strip cols-5 report-section" aria-label="Conflict summary">
            <div class="stat-item total"><span class="stat-num"><?= (int) $summary['total'] ?></span><span class="stat-label">Conflicts detected</span></div>
            <div class="stat-item rejected"><span class="stat-num"><?= (int) $summary['confirmed'] ?></span><span class="stat-label">Confirmed double bookings</span></div>
            <div class="stat-item pending"><span class="stat-num"><?= (int) $summary['potential'] ?></span><span class="stat-label">Potential double bookings</span></div>
            <div class="stat-item approved"><span class="stat-num"><?= (int) $summary['venue_count'] ?></span><span class="stat-label">Venues affected</span></div>
            <div class="stat-item completed"><span class="stat-num stat-num-text"><?= e(formatOverlapMinutes($summary['minutes'])) ?></span><span class="stat-label">Total overlap</span></div>
        </section>

        <?php
        /* Where a conflict record can be opened. Staff open the full record page.
           Super Admin no longer has a per-activity page (Activity Overview was
           removed for that role), so record names are rendered as plain text for
           them instead of as a dead link - the table already carries every detail
           needed to judge a clash (status, window, host, organization). */
        $openRecord = !isSuperAdmin();
        $detailLink = '/UBMSConnect/staff/activity_view.php?id=';
        ?>

        <div class="card report-section">
            <div class="card-title">
                Detected conflicts
                <span class="text-muted"><?= (int) $summary['total'] ?> pair(s) across <?= (int) $summary['date_count'] ?> day(s)</span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Venue</th>
                            <th>Overlapping time</th>
                            <th>Activity</th>
                            <th>Conflicts with</th>
                            <th>Severity</th>
                            <th>Recorded</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($conflicts as $conflict): ?>
                        <tr>
                            <td>
                                <?= e(formatDate($conflict['activity_date'])) ?>
                                <div class="small-note"><?= e(conflictDateContext($conflict['activity_date'])) ?></div>
                            </td>
                            <td>
                                <strong><?= e($conflict['venue_name']) ?></strong>
                                <div class="small-note"><?= e($conflict['building']) ?> &middot; capacity <?= (int) $conflict['capacity'] ?></div>
                            </td>
                            <td>
                                <?= e(formatTime($conflict['overlap_start'])) ?> &ndash; <?= e(formatTime($conflict['overlap_end'])) ?>
                                <div class="small-note">overlap <?= e(formatOverlapMinutes($conflict['overlap_minutes'])) ?></div>
                            </td>
                            <?php foreach (['a', 'b'] as $side): $entry = $conflict[$side]; ?>
                                <td>
                                    <?php if ($openRecord): ?>
                                        <a href="<?= e($detailLink . $entry['id']) ?>"><?= e($entry['name']) ?></a>
                                    <?php else: ?>
                                        <?= e($entry['name']) ?>
                                    <?php endif; ?>
                                    <span class="badge <?= statusBadgeClass($entry['status']) ?>"><?= e($entry['status']) ?></span>
                                    <div class="small-note">
                                        <?= e(formatTime($entry['start'])) ?>&ndash;<?= e(formatTime($entry['end'])) ?>
                                        &middot; <?= e($entry['host']) ?>
                                        &middot; <?= e($entry['organization'] ?: 'Not assigned') ?>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                            <td>
                                <span class="badge <?= conflictSeverityBadgeClass($conflict['severity']) ?>"><?= e(conflictSeverityLabel($conflict['severity'])) ?></span>
                            </td>
                            <td>
                                <?php if ($conflict['acknowledged']): ?>
                                    <span class="badge badge-maintenance">Approved anyway</span>
                                    <div class="small-note">
                                        <?= e($conflict['acknowledged']['detected_by_name'] ?: 'Unknown') ?>,
                                        <?= e(formatDateTime($conflict['acknowledged']['detected_at'])) ?>
                                        (<?= (int) $conflict['acknowledged']['override_count'] ?>&times;)
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($hotspots): ?>
        <div class="card report-section">
            <div class="card-title">
                Venues by number of conflicts
                <span class="text-muted"><?= count($hotspots) ?> venue(s)</span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Venue</th>
                            <th>Building</th>
                            <th>Conflicts</th>
                            <th>Confirmed</th>
                            <th>Potential</th>
                            <th>Historical</th>
                            <th>Days affected</th>
                            <th>Overlap time</th>
                            <th>Most recent</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($hotspots as $hotspot): ?>
                        <tr>
                            <td><strong><?= e($hotspot['venue_name']) ?></strong></td>
                            <td><?= e($hotspot['building']) ?></td>
                            <td><?= (int) $hotspot['total'] ?></td>
                            <td><?= (int) $hotspot['confirmed'] ?></td>
                            <td><?= (int) $hotspot['potential'] ?></td>
                            <td><?= (int) $hotspot['historical'] ?></td>
                            <td><?= (int) $hotspot['day_count'] ?></td>
                            <td><?= e(formatOverlapMinutes($hotspot['minutes'])) ?></td>
                            <td><?= $hotspot['last_date'] ? e(formatDate($hotspot['last_date'])) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small-note">
                A venue with a high count here is the best candidate for a fixed schedule or a booking policy.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($acknowledgedRows): ?>
        <div class="card report-section">
            <div class="card-title">
                Approved anyway (audit trail)
                <span class="text-muted"><?= count($acknowledgedRows) ?> in this view</span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Date</th><th>Venue</th><th>Records</th><th>Approved by</th><th>When</th><th>Times confirmed</th><th>Reason given</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($acknowledgedRows as $conflict): $ack = $conflict['acknowledged']; ?>
                        <tr>
                            <td><?= e(formatDate($conflict['activity_date'])) ?></td>
                            <td><?= e($conflict['venue_name']) ?></td>
                            <td><?= e($conflict['a']['name']) ?> &amp; <?= e($conflict['b']['name']) ?></td>
                            <td><?= e($ack['detected_by_name'] ?: 'Unknown') ?></td>
                            <td><?= e(formatDateTime($ack['detected_at'])) ?></td>
                            <td><?= (int) $ack['override_count'] ?></td>
                            <td><?= e($ack['reason'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small-note">
                These double bookings were detected and then deliberately allowed by a Staff member. The reason
                they gave is stored with the record, so an allowed double booking is never silent.
            </p>
        </div>
    <?php endif; ?>

    <div class="card report-section">
        <div class="card-title">What the system does when a conflict is detected</div>
        <ul class="conflict-policy-list">
            <li>
                <strong>It prevents the double booking.</strong> When a Staff member tries to set an activity to
                <em>Approved</em> and that would overlap an already-Approved activity in the same venue, the change is
                refused first. The conflicting record is listed and the Staff member has to confirm a second time
                to proceed.
            </li>
            <li>
                <strong>It notifies the people involved.</strong> If the change is confirmed anyway, both Event Hosts
                and every Staff and Super Admin account receive a notification naming the venue, the date and the
                other activity.
            </li>
            <li>
                <strong>It keeps a record.</strong> A confirmed override is written to the
                <code>activity_conflicts</code> table with who allowed it, when, and the reason given &mdash; shown in
                the audit table above and in the Audit Logs page.
            </li>
            <li>
                <strong>Event Hosts see it too.</strong> An Event Host cannot change a status, but every activity page
                shows a read-only warning when that activity overlaps another one at the same venue, so the host knows
                before the day itself.
            </li>
        </ul>
    </div>

    <p class="small-note report-footer-note">
        Generated by UBMSConnect on <?= e($generatedAt) ?> by <?= e(currentUserName()) ?>
        (<?= e(roleLabel(currentUserRole())) ?>) from stored monitoring records.
        Scope &mdash; <?= e(implode('  |  ', array_map(function ($label, $value) {
            return $label . ': ' . $value;
        }, array_keys($filterSummary), $filterSummary))) ?>. Detection is computed live on every load.
    </p>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

