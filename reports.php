<?php
/**
 * reports.php - Automated report generation (moderator item 7) with the
 * report filtering options (item 9): organization name, month, school year
 * - plus optional venue/status filters and a period grouping (week/month/
 * school year), all driven by the school years defined in
 * Super Admin -> School Years (item 8).
 *
 * Access: UBMS Staff and Super Admin only (Event Hosts get live monitoring
 * pages instead). Reports are read-only and never modify monitoring data.
 *
 * Everything is GET-driven, so a generated report can be bookmarked, shared,
 * re-opened from browser history, printed, or saved as a PDF.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_year.php';
requireRole(['staff', 'super_admin']);

$pageTitle = 'Reports';
$activeNav = 'reports';

/* ---------------------------------------------------------
 * Filter inputs
 * --------------------------------------------------------- */
$schoolYears   = getSchoolYears();
$organizations = $pdo->query('SELECT id, organization_name FROM organizations ORDER BY organization_name')->fetchAll();
$venues        = $pdo->query('SELECT id, venue_name, building FROM venues ORDER BY venue_name')->fetchAll();
$activeYear    = getActiveSchoolYear();

$fSchoolYear = $_GET['school_year'] ?? ($activeYear ? (string) $activeYear['id'] : 'all');
$fMonth      = (int) ($_GET['month'] ?? 0);
$fOrg        = (int) ($_GET['organization'] ?? 0);
$fVenue      = (int) ($_GET['venue'] ?? 0);
$fStatus     = trim((string) ($_GET['status'] ?? ''));
$fGroup      = (string) ($_GET['group'] ?? 'month');

if (!in_array($fGroup, ['week', 'month', 'school_year'], true)) $fGroup = 'month';
if ($fStatus !== '' && !in_array($fStatus, validActivityStatuses(), true)) $fStatus = '';

/* Only an existing organization/venue/school year can be filtered on. */
$selectedOrg = null;
foreach ($organizations as $org) {
    if ((int) $org['id'] === $fOrg) $selectedOrg = $org;
}
if (!$selectedOrg) $fOrg = 0;

$selectedVenue = null;
foreach ($venues as $venue) {
    if ((int) $venue['id'] === $fVenue) $selectedVenue = $venue;
}
if (!$selectedVenue) $fVenue = 0;

$selectedSchoolYear = null;
if ($fSchoolYear !== 'all') {
    foreach ($schoolYears as $sy) {
        if ((string) $sy['id'] === (string) $fSchoolYear) $selectedSchoolYear = $sy;
    }
    if (!$selectedSchoolYear) $fSchoolYear = 'all';
}

/* ---------------------------------------------------------
 * Build the WHERE clause once and reuse it for every query below.
 * --------------------------------------------------------- */
$where  = [];
$params = [];

if ($selectedSchoolYear) {
    $where[]  = 'a.school_year_id = ?';
    $params[] = (int) $selectedSchoolYear['id'];
}
if ($fMonth) {
    // With a school year selected this pins that month inside that year;
    // with "All school years" it means "that month in every year".
    $where[]  = 'MONTH(a.activity_date) = ?';
    $params[] = $fMonth;
}
if ($fOrg) {
    $where[]  = 'a.organization_id = ?';
    $params[] = $fOrg;
}
if ($fVenue) {
    $where[]  = 'a.venue_id = ?';
    $params[] = $fVenue;
}
if ($fStatus !== '') {
    $where[]  = 'a.status = ?';
    $params[] = $fStatus;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/** Small query helper so every report query shares the same prepared filters. */
function reportQuery($sql, $params) {
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/* Minutes spent per activity - clamped at 0 so an end time earlier than a
   start time can never subtract from the total. */
$secondsExpr = 'SUM(GREATEST(TIMESTAMPDIFF(SECOND, a.start_time, a.end_time), 0))';
$statusSums  = "SUM(a.status = 'Pending') AS pending, SUM(a.status = 'Approved') AS approved, "
             . "SUM(a.status = 'Maintenance') AS maintenance, SUM(a.status = 'Completed') AS completed, "
             . "SUM(a.status = 'Rejected') AS rejected";

/* ---------------------------------------------------------
 * 1. Summary of the filtered set
 * --------------------------------------------------------- */
$summary = reportQuery(
    "SELECT COUNT(*) AS activities,
            COUNT(DISTINCT a.venue_id) AS venues_used,
            COUNT(DISTINCT a.organization_id) AS organizations_used,
            COUNT(DISTINCT a.host_id) AS hosts,
            COALESCE($secondsExpr, 0) AS seconds,
            MIN(a.activity_date) AS first_date,
            MAX(a.activity_date) AS last_date,
            $statusSums
       FROM activities a
       $whereSql",
    $params
)[0];

$totalActivities = (int) $summary['activities'];
$totalSeconds    = (int) $summary['seconds'];

/* ---------------------------------------------------------
 * 2. Breakdown by period (the "automated report" itself)
 * --------------------------------------------------------- */
if ($fGroup === 'week') {
    $bucketExpr = 'DATE_SUB(a.activity_date, INTERVAL WEEKDAY(a.activity_date) DAY)';
} elseif ($fGroup === 'month') {
    $bucketExpr = "DATE_FORMAT(a.activity_date, '%Y-%m-01')";
} else {
    $bucketExpr = "COALESCE(sy.name, 'Not assigned to a school year')";
}

$periodRows = reportQuery(
    "SELECT $bucketExpr AS bucket,
            COUNT(*) AS activities,
            COALESCE($secondsExpr, 0) AS seconds,
            COUNT(DISTINCT a.venue_id) AS venues_used,
            COUNT(DISTINCT a.organization_id) AS organizations_used,
            $statusSums
       FROM activities a
       LEFT JOIN school_years sy ON sy.id = a.school_year_id
       $whereSql
      GROUP BY bucket
      ORDER BY MIN(a.activity_date)",
    $params
);

/* ---------------------------------------------------------
 * 3. Breakdown by organization (item 9's primary filter)
 * --------------------------------------------------------- */
$orgRows = reportQuery(
    "SELECT COALESCE(o.organization_name, 'Not assigned') AS org_name,
            COUNT(*) AS activities,
            COALESCE($secondsExpr, 0) AS seconds,
            COUNT(DISTINCT a.venue_id) AS venues_used,
            COUNT(DISTINCT a.host_id) AS hosts,
            $statusSums
       FROM activities a
       LEFT JOIN organizations o ON o.id = a.organization_id
       $whereSql
      GROUP BY o.organization_name
      ORDER BY activities DESC, org_name ASC",
    $params
);

/* ---------------------------------------------------------
 * 4. Venue utilization
 * --------------------------------------------------------- */
$venueRows = reportQuery(
    "SELECT v.venue_name, v.building, v.capacity,
            COUNT(*) AS activities,
            COALESCE($secondsExpr, 0) AS seconds,
            COUNT(DISTINCT a.activity_date) AS days_used,
            COUNT(DISTINCT a.organization_id) AS organizations_used,
            MAX(a.activity_date) AS last_used
       FROM activities a
       JOIN venues v ON v.id = a.venue_id
       $whereSql
      GROUP BY v.id, v.venue_name, v.building, v.capacity
      ORDER BY activities DESC, v.venue_name ASC",
    $params
);

/* ---------------------------------------------------------
 * 5. Status breakdown (kept in the workflow's own order)
 * --------------------------------------------------------- */
$statusByKey = [];
foreach (reportQuery(
    "SELECT a.status, COUNT(*) AS activities, COALESCE($secondsExpr, 0) AS seconds
       FROM activities a
       $whereSql
      GROUP BY a.status",
    $params
) as $row) {
    $statusByKey[$row['status']] = $row;
}

/* ---------------------------------------------------------
 * 6. Maintenance notes recorded during the reported span
 * --------------------------------------------------------- */
$maintenanceRows  = [];
$maintenanceTotal = 0;
if ($totalActivities > 0) {
    $spanParams = [$summary['first_date'], $summary['last_date']];

    $maintenanceTotal = (int) reportQuery(
        'SELECT COUNT(*) AS c FROM maintenance_logs WHERE DATE(created_at) BETWEEN ? AND ?',
        $spanParams
    )[0]['c'];

    $maintenanceRows = reportQuery(
        "SELECT m.created_at, m.note, v.venue_name, u.utility_name, us.full_name AS logged_by
           FROM maintenance_logs m
           LEFT JOIN venues v ON v.id = m.venue_id
           LEFT JOIN utilities u ON u.id = m.utility_id
           LEFT JOIN users us ON us.id = m.created_by
          WHERE DATE(m.created_at) BETWEEN ? AND ?
          ORDER BY m.created_at DESC
          LIMIT 15",
        $spanParams
    );
}

/* ---------------------------------------------------------
 * 7. Event hosts contributing in the reported span
 * --------------------------------------------------------- */
$hostRows = reportQuery(
    "SELECT u.full_name, COALESCE(o.organization_name, 'Not assigned') AS org_name,
            COUNT(*) AS activities,
            COALESCE($secondsExpr, 0) AS seconds
       FROM activities a
       JOIN users u ON u.id = a.host_id
       LEFT JOIN organizations o ON o.id = a.organization_id
       $whereSql
      GROUP BY u.id, u.full_name, o.organization_name
      ORDER BY activities DESC, u.full_name ASC
      LIMIT 10",
    $params
);

/* ---------------------------------------------------------
 * Readable labels for the applied filters - shown on screen, in the
 * printed/PDF header, and written into the CSV export.
 * --------------------------------------------------------- */
$filterSummary = [
    'School year'  => $selectedSchoolYear ? $selectedSchoolYear['name'] : 'All school years',
    'Month'        => $fMonth ? monthLabel($fMonth) : 'All months',
    'Organization' => $selectedOrg ? $selectedOrg['organization_name'] : 'All organizations',
    'Venue'        => $selectedVenue ? $selectedVenue['venue_name'] . ' (' . $selectedVenue['building'] . ')' : 'All venues',
    'Status'       => $fStatus !== '' ? $fStatus : 'All statuses',
    'Grouped by'   => $fGroup === 'week' ? 'Week' : ($fGroup === 'month' ? 'Month' : 'School year'),
];

$coverageFrom = $totalActivities ? formatDate($summary['first_date']) : null;
$coverageTo   = $totalActivities ? formatDate($summary['last_date']) : null;
$generatedAt  = date('F j, Y g:i A');

/** "3 h 30 m" / "45 m" / "-" for a number of seconds. */
function formatHoursMinutes($seconds) {
    $seconds = (int) $seconds;
    if ($seconds <= 0) return '-';
    $hours = intdiv($seconds, 3600);
    $mins  = intdiv($seconds % 3600, 60);
    if ($hours && $mins) return $hours . ' h ' . $mins . ' m';
    if ($hours) return $hours . ' h';
    if ($mins) return $mins . ' m';
    return intdiv($seconds, 60) . ' m';
}

/** Row label for the period breakdown. */
function periodRowLabel($row, $group) {
    $bucket = (string) $row['bucket'];
    if ($group === 'school_year') return $bucket;
    $start = strtotime($bucket);
    if (!$start) return $bucket;
    if ($group === 'month') return date('F Y', $start);
    return 'Week of ' . date('M j', $start) . ' - ' . date('M j, Y', strtotime('+6 days', $start));
}

/** Percent share of a total, guarded against division by zero. */
function percentOf($part, $total) {
    if (!$total) return '0%';
    return round(((int) $part / (int) $total) * 100) . '%';
}

/** Appends one CSV row (header row or data row) to the response stream. */
function csvRow($handle, $cells) {
    fputcsv($handle, $cells);
}

/* ---------------------------------------------------------
 * CSV export (?export=csv) - runs before any HTML is sent, so the response
 * is a clean downloadable file that opens in Excel / Google Sheets.
 * --------------------------------------------------------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ubms-report-' . date('Y-m-d-His') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads characters correctly

    csvRow($out, ['UBMSConnect - Activity & Venue Utilization Report']);
    csvRow($out, ['Generated', $generatedAt]);
    csvRow($out, ['Generated by', currentUserName() . ' (' . roleLabel(currentUserRole()) . ')']);
    csvRow($out, ['Period covered', ($coverageFrom && $coverageTo) ? ($coverageFrom . ' to ' . $coverageTo) : 'No matching records']);
    foreach ($filterSummary as $label => $value) csvRow($out, [$label, $value]);
    csvRow($out, []);

    csvRow($out, ['SUMMARY']);
    csvRow($out, ['Activities', 'Time logged', 'Venues used', 'Organizations represented', 'Event hosts']);
    csvRow($out, [
        $totalActivities, formatHoursMinutes($totalSeconds),
        (int) $summary['venues_used'], (int) $summary['organizations_used'], (int) $summary['hosts'],
    ]);
    csvRow($out, []);

    csvRow($out, ['BREAKDOWN BY ' . strtoupper($filterSummary['Grouped by'])]);
    csvRow($out, ['Period', 'Activities', 'Time', 'Venues', 'Organizations', 'Pending', 'Approved', 'Maintenance', 'Completed', 'Rejected']);
    foreach ($periodRows as $row) {
        csvRow($out, [
            periodRowLabel($row, $fGroup), (int) $row['activities'], formatHoursMinutes($row['seconds']),
            (int) $row['venues_used'], (int) $row['organizations_used'],
            (int) $row['pending'], (int) $row['approved'], (int) $row['maintenance'],
            (int) $row['completed'], (int) $row['rejected'],
        ]);
    }
    csvRow($out, [
        'TOTAL', $totalActivities, formatHoursMinutes($totalSeconds),
        (int) $summary['venues_used'], (int) $summary['organizations_used'],
        (int) $summary['pending'], (int) $summary['approved'], (int) $summary['maintenance'],
        (int) $summary['completed'], (int) $summary['rejected'],
    ]);
    csvRow($out, []);

    csvRow($out, ['BY ORGANIZATION']);
    csvRow($out, ['Organization', 'Activities', 'Time', 'Venues used', 'Hosts', 'Pending', 'Approved', 'Maintenance', 'Completed', 'Rejected']);
    foreach ($orgRows as $row) {
        csvRow($out, [
            $row['org_name'], (int) $row['activities'], formatHoursMinutes($row['seconds']),
            (int) $row['venues_used'], (int) $row['hosts'],
            (int) $row['pending'], (int) $row['approved'], (int) $row['maintenance'],
            (int) $row['completed'], (int) $row['rejected'],
        ]);
    }
    csvRow($out, []);

    csvRow($out, ['VENUE UTILIZATION']);
    csvRow($out, ['Venue', 'Building', 'Capacity', 'Activities', 'Time', 'Days used', 'Organizations', 'Last used']);
    foreach ($venueRows as $row) {
        csvRow($out, [
            $row['venue_name'], $row['building'], (int) $row['capacity'], (int) $row['activities'],
            formatHoursMinutes($row['seconds']), (int) $row['days_used'],
            (int) $row['organizations_used'], formatDate($row['last_used']),
        ]);
    }
    csvRow($out, []);

    csvRow($out, ['STATUS BREAKDOWN']);
    csvRow($out, ['Status', 'Activities', 'Share', 'Time']);
    foreach (validActivityStatuses() as $status) {
        $row = $statusByKey[$status] ?? null;
        csvRow($out, [
            $status,
            $row ? (int) $row['activities'] : 0,
            percentOf($row ? $row['activities'] : 0, $totalActivities),
            formatHoursMinutes($row ? $row['seconds'] : 0),
        ]);
    }
    csvRow($out, []);

    csvRow($out, ['MAINTENANCE NOTES IN PERIOD']);
    csvRow($out, ['Logged', 'Venue', 'Utility', 'Note', 'By']);
    foreach ($maintenanceRows as $row) {
        csvRow($out, [
            formatDateTime($row['created_at']),
            $row['venue_name'] ?: 'All venues',
            $row['utility_name'] ?: '-',
            $row['note'],
            $row['logged_by'] ?: 'Unknown',
        ]);
    }
    csvRow($out, []);

    csvRow($out, ['EVENT HOSTS']);
    csvRow($out, ['Host', 'Organization', 'Activities', 'Time']);
    foreach ($hostRows as $row) {
        csvRow($out, [$row['full_name'], $row['org_name'], (int) $row['activities'], formatHoursMinutes($row['seconds'])]);
    }

    fclose($out);
    exit;
}

$exportUrl = '/UBMSConnect/reports.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']));

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Reports</h1>
            <p class="page-subtitle">Weekly, monthly, or school-year activity and venue utilization reports for monitoring and administrative decisions.</p>
        </div>
        <div class="report-actions no-print">
            <button type="button" class="btn btn-primary" onclick="window.print()">Save as PDF / Print</button>
            <a class="btn" href="<?= e($exportUrl) ?>">Export CSV</a>
        </div>
    </div>

    <form method="GET" class="card report-filters no-print">
        <div class="card-title">Report filters</div>
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
                <label for="organization">Organization</label>
                <select id="organization" name="organization">
                    <option value="0">All organizations</option>
                    <?php foreach ($organizations as $org): ?>
                        <option value="<?= (int) $org['id'] ?>" <?= $fOrg === (int) $org['id'] ? 'selected' : '' ?>><?= e($org['organization_name']) ?></option>
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
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (validActivityStatuses() as $status): ?>
                        <option value="<?= e($status) ?>" <?= $fStatus === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="group">Group by</label>
                <select id="group" name="group">
                    <option value="week" <?= $fGroup === 'week' ? 'selected' : '' ?>>Week</option>
                    <option value="month" <?= $fGroup === 'month' ? 'selected' : '' ?>>Month</option>
                    <option value="school_year" <?= $fGroup === 'school_year' ? 'selected' : '' ?>>School year</option>
                </select>
            </div>
            <button class="btn btn-primary">Generate report</button>
            <a class="btn" href="/UBMSConnect/reports.php">Reset</a>
        </div>
        <p class="small-note" style="margin:10px 0 0;">
            With a specific school year selected, the Month filter pins that month inside that school year.
            With "All school years", a month means that month in every year.
        </p>
    </form>

    <?php $unassignedActivities = isSuperAdmin() ? activitiesWithoutSchoolYear() : 0; ?>
    <?php if ($unassignedActivities > 0): ?>
        <div class="alert alert-info no-print">
            <?= (int) $unassignedActivities ?> activity record(s) are not assigned to any school year, so they are counted
            under "All school years" but not inside a specific year.
            <a href="/UBMSConnect/superadmin/school_years.php">Manage school years / re-sync</a>.
        </div>
    <?php endif; ?>

    <div class="report-doc">
        <header class="report-print-header">
            <div>
                <h2>UBMSConnect &middot; Activity &amp; Venue Utilization Report</h2>
                <p class="small-note">University Building and Maintenance Service (UBMS) &mdash; activity monitoring records</p>
            </div>
            <div class="report-meta">
                <span><strong>Generated:</strong> <?= e($generatedAt) ?></span>
                <span><strong>Prepared by:</strong> <?= e(currentUserName()) ?> (<?= e(roleLabel(currentUserRole())) ?>)</span>
                <span><strong>Period covered:</strong> <?= $coverageFrom ? e($coverageFrom) . ' &ndash; ' . e($coverageTo) : 'No matching records' ?></span>
            </div>
            <div class="report-tags">
                <?php foreach ($filterSummary as $label => $value): ?>
                    <span class="report-tag"><strong><?= e($label) ?>:</strong> <?= e($value) ?></span>
                <?php endforeach; ?>
            </div>
        </header>

        <?php if ($totalActivities === 0): ?>
            <div class="card report-section">
                <div class="empty-state">
                    No activity records match these filters.
                    <?php if ($fMonth && $selectedSchoolYear): ?>
                        <br>
                        <span class="small-note">
                            <?= e(monthLabel($fMonth)) ?> may fall outside <?= e($selectedSchoolYear['name']) ?>
                            (<?= e(formatDate($selectedSchoolYear['start_date'])) ?> &ndash; <?= e(formatDate($selectedSchoolYear['end_date'])) ?>).
                            Try "All months", or choose a different school year.
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>

        <section class="stat-strip cols-5 report-section" aria-label="Report summary">
            <div class="stat-item total"><span class="stat-num"><?= $totalActivities ?></span><span class="stat-label">Activities</span></div>
            <div class="stat-item approved"><span class="stat-num stat-num-text"><?= e(formatHoursMinutes($totalSeconds)) ?></span><span class="stat-label">Time logged</span></div>
            <div class="stat-item pending"><span class="stat-num"><?= (int) $summary['venues_used'] ?></span><span class="stat-label">Venues used</span></div>
            <div class="stat-item maintenance"><span class="stat-num"><?= (int) $summary['organizations_used'] ?></span><span class="stat-label">Organizations</span></div>
            <div class="stat-item completed"><span class="stat-num"><?= (int) $summary['hosts'] ?></span><span class="stat-label">Event hosts</span></div>
        </section>

        <div class="card report-section">
            <div class="card-title">
                Breakdown by <?= e(strtolower($filterSummary['Grouped by'])) ?>
                <span class="text-muted"><?= count($periodRows) ?> period(s)</span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?= e($filterSummary['Grouped by']) ?></th><th>Activities</th><th>Time</th><th>Venues</th><th>Orgs</th>
                            <th>Pending</th><th>Approved</th><th>Maintenance</th><th>Completed</th><th>Rejected</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($periodRows as $row): ?>
                        <tr>
                            <td><strong><?= e(periodRowLabel($row, $fGroup)) ?></strong></td>
                            <td><?= (int) $row['activities'] ?></td>
                            <td><?= e(formatHoursMinutes($row['seconds'])) ?></td>
                            <td><?= (int) $row['venues_used'] ?></td>
                            <td><?= (int) $row['organizations_used'] ?></td>
                            <td><?= (int) $row['pending'] ?></td>
                            <td><?= (int) $row['approved'] ?></td>
                            <td><?= (int) $row['maintenance'] ?></td>
                            <td><?= (int) $row['completed'] ?></td>
                            <td><?= (int) $row['rejected'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td><?= $totalActivities ?></td>
                            <td><?= e(formatHoursMinutes($totalSeconds)) ?></td>
                            <td><?= (int) $summary['venues_used'] ?></td>
                            <td><?= (int) $summary['organizations_used'] ?></td>
                            <td><?= (int) $summary['pending'] ?></td>
                            <td><?= (int) $summary['approved'] ?></td>
                            <td><?= (int) $summary['maintenance'] ?></td>
                            <td><?= (int) $summary['completed'] ?></td>
                            <td><?= (int) $summary['rejected'] ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="card report-section">
            <div class="card-title">
                By organization
                <span class="text-muted"><?= count($orgRows) ?> organization(s)</span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Organization</th><th>Activities</th><th>Time</th><th>Venues used</th><th>Hosts</th>
                            <th>Pending</th><th>Approved</th><th>Maintenance</th><th>Completed</th><th>Rejected</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orgRows as $row): ?>
                        <tr>
                            <td><strong><?= e($row['org_name']) ?></strong></td>
                            <td><?= (int) $row['activities'] ?></td>
                            <td><?= e(formatHoursMinutes($row['seconds'])) ?></td>
                            <td><?= (int) $row['venues_used'] ?></td>
                            <td><?= (int) $row['hosts'] ?></td>
                            <td><?= (int) $row['pending'] ?></td>
                            <td><?= (int) $row['approved'] ?></td>
                            <td><?= (int) $row['maintenance'] ?></td>
                            <td><?= (int) $row['completed'] ?></td>
                            <td><?= (int) $row['rejected'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small-note">
                Activities are counted under the organization recorded on the event at the time it was monitored.
                Set an account's organization in Super Admin &rarr; User Management, and use
                Super Admin &rarr; School Years &rarr; Re-sync to fill in older records.
            </p>
        </div>

        <div class="card report-section">
            <div class="card-title">
                Venue utilization
                <span class="text-muted"><?= count($venueRows) ?> venue(s)</span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Venue</th><th>Building</th><th>Capacity</th><th>Activities</th><th>Time</th>
                            <th>Days used</th><th>Organizations</th><th>Last used</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($venueRows as $row): ?>
                        <tr>
                            <td><strong><?= e($row['venue_name']) ?></strong></td>
                            <td><?= e($row['building']) ?></td>
                            <td><?= (int) $row['capacity'] ?></td>
                            <td><?= (int) $row['activities'] ?></td>
                            <td><?= e(formatHoursMinutes($row['seconds'])) ?></td>
                            <td><?= (int) $row['days_used'] ?></td>
                            <td><?= (int) $row['organizations_used'] ?></td>
                            <td><?= e(formatDate($row['last_used'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid-2 report-section">
            <div class="card">
                <div class="card-title">Status breakdown</div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Status</th><th>Activities</th><th>Share</th><th>Time</th></tr></thead>
                        <tbody>
                        <?php foreach (validActivityStatuses() as $status): $row = $statusByKey[$status] ?? null; ?>
                            <tr>
                                <td><span class="badge <?= statusBadgeClass($status) ?>"><?= e($status) ?></span></td>
                                <td><?= $row ? (int) $row['activities'] : 0 ?></td>
                                <td><?= percentOf($row ? $row['activities'] : 0, $totalActivities) ?></td>
                                <td><?= e(formatHoursMinutes($row ? $row['seconds'] : 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-title">
                    Event hosts in period
                    <span class="text-muted">top <?= count($hostRows) ?></span>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Host</th><th>Organization</th><th>Activities</th><th>Time</th></tr></thead>
                        <tbody>
                        <?php foreach ($hostRows as $row): ?>
                            <tr>
                                <td><?= e($row['full_name']) ?></td>
                                <td class="text-muted"><?= e($row['org_name']) ?></td>
                                <td><?= (int) $row['activities'] ?></td>
                                <td><?= e(formatHoursMinutes($row['seconds'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card report-section">
            <div class="card-title">
                Maintenance notes recorded in the period
                <span class="text-muted"><?= (int) $maintenanceTotal ?> total</span>
            </div>
            <?php if (!$maintenanceRows): ?>
                <div class="empty-state">No maintenance notes were logged between <?= e($coverageFrom) ?> and <?= e($coverageTo) ?>.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Logged</th><th>Venue</th><th>Utility</th><th>Note</th><th>By</th></tr></thead>
                        <tbody>
                        <?php foreach ($maintenanceRows as $row): ?>
                            <tr>
                                <td><?= e(formatDateTime($row['created_at'])) ?></td>
                                <td><?= e($row['venue_name'] ?: 'All venues') ?></td>
                                <td><?= e($row['utility_name'] ?: '-') ?></td>
                                <td><?= e($row['note']) ?></td>
                                <td><?= e($row['logged_by'] ?: 'Unknown') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($maintenanceTotal > count($maintenanceRows)): ?>
                    <p class="small-note">
                        Showing the <?= count($maintenanceRows) ?> most recent of <?= (int) $maintenanceTotal ?> notes logged in this period.
                        Use Export CSV for the complete list.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <p class="small-note report-footer-note">
            Generated by UBMSConnect on <?= e($generatedAt) ?> from stored monitoring records.
            Scope &mdash; <?= e(implode('  |  ', array_map(function ($label, $value) {
                return $label . ': ' . $value;
            }, array_keys($filterSummary), $filterSummary))) ?>.
        </p>

        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
