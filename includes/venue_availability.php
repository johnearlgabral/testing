<?php
/** Shared real-time, schedule-based availability panel for venue pages. */
function renderVenueAvailabilityPanel() {
    $today = date('Y-m-d');
    ?>
    <section class="card availability-card" aria-labelledby="availability-heading">
        <div class="availability-header">
            <div>
                <h2 id="availability-heading">Schedule Availability</h2>
                <p class="page-subtitle">Approved activities determine whether a venue is available or occupied for each period.</p>
            </div>
            <div class="availability-controls">
                <label for="availabilityDate">Date</label>
                <input type="date" id="availabilityDate" value="<?= e($today) ?>">
                <button type="button" class="btn btn-sm" id="availabilityRefresh">Refresh</button>
            </div>
        </div>
        <div class="pill-status-legend availability-legend" aria-label="Availability legend">
            <span class="badge availability-badge availability-available">Available</span>
            <span class="badge availability-badge availability-occupied">Occupied</span>
            <span class="badge availability-badge availability-unavailable">Unavailable / maintenance</span>
            <span class="badge badge-rejected">Double-booked</span>
            <span class="availability-updated" id="availabilityUpdated" aria-live="polite">Loading availability…</span>
        </div>
        <div class="table-wrap">
            <table class="data-table availability-table" id="availabilityTable">
                <thead>
                    <tr>
                        <th>Venue</th>
                        <th>Location</th>
                        <th>Capacity</th>
                        <th>Morning <span class="availability-period">12:00 AM–11:59 AM</span></th>
                        <th>Afternoon <span class="availability-period">12:00 PM–11:59 PM</span></th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="5" class="empty-state">Loading availability…</td></tr></tbody>
            </table>
        </div>
    </section>
    <?php
}

function venueAvailabilityScripts() {
    return <<<'JS'
<script>
(function () {
    const table = document.getElementById('availabilityTable');
    const dateInput = document.getElementById('availabilityDate');
    const refreshButton = document.getElementById('availabilityRefresh');
    const updated = document.getElementById('availabilityUpdated');
    if (!table || !dateInput || !refreshButton || !updated) return;

    function slotHtml(slot) {
        const details = slot.activities && slot.activities.length
            ? '<div class="availability-note">' + slot.activities.map(escapeHtml).join('<br>') + '</div>'
            : '';
        // Confirmed double booking (moderator item 11): the same venue is
        // Approved for more than one activity at an overlapping time.
        const conflict = slot.double_booked
            ? '<div class="availability-conflict">'
                + '<span class="badge badge-rejected">Double-booked'
                + (slot.double_booked > 1 ? ' &times;' + Number(slot.double_booked) : '')
                + '</span>'
                + (slot.conflict_note ? '<div class="availability-note">' + escapeHtml(slot.conflict_note) + '</div>' : '')
                + '</div>'
            : '';
        return '<span class="badge availability-badge availability-' + escapeHtml(slot.state) + '">'
            + escapeHtml(slot.label) + '</span>' + details + conflict;
    }

    function formatCheckedAt(iso) {
        const date = new Date(iso);
        return isNaN(date.getTime()) ? 'Updated just now' : 'Updated ' + date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    function loadAvailability() {
        const selectedDate = dateInput.value;
        if (!selectedDate) return;
        refreshButton.disabled = true;
        refreshButton.textContent = 'Refreshing…';

        fetch('/UBMSConnect/api/venue_availability.php?date=' + encodeURIComponent(selectedDate))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                const body = table.querySelector('tbody');
                if (data.auth_error) {
                    body.innerHTML = '<tr><td colspan="5" class="empty-state">' + escapeHtml(data.message) + '</td></tr>';
                    setTimeout(function () { window.location.href = '/UBMSConnect/login.php'; }, 1200);
                    return;
                }
                if (!data.success) throw new Error(data.message || 'Unable to load availability.');
                if (!data.venues.length) {
                    body.innerHTML = '<tr><td colspan="5" class="empty-state">No active venues are available to display.</td></tr>';
                } else {
                    body.innerHTML = data.venues.map(function (venue) {
                        const location = [venue.building, venue.location].filter(Boolean).map(escapeHtml).join('<br>');
                        return '<tr>'
                            + '<td><strong>' + escapeHtml(venue.venue_name) + '</strong></td>'
                            + '<td>' + location + '</td>'
                            + '<td>' + escapeHtml(String(venue.capacity)) + '</td>'
                            + '<td>' + slotHtml(venue.morning) + '</td>'
                            + '<td>' + slotHtml(venue.afternoon) + '</td>'
                            + '</tr>';
                    }).join('');
                }
                updated.textContent = formatCheckedAt(data.generated_at) + ' · Refreshes every 30 seconds';
            })
            .catch(function (error) {
                table.querySelector('tbody').innerHTML = '<tr><td colspan="5" class="empty-state">'
                    + escapeHtml(error.message || 'Unable to load availability. Please try again.') + '</td></tr>';
                updated.textContent = 'Unable to refresh availability';
            })
            .then(function () {
                refreshButton.disabled = false;
                refreshButton.textContent = 'Refresh';
            });
    }

    refreshButton.addEventListener('click', loadAvailability);
    dateInput.addEventListener('change', loadAvailability);
    loadAvailability();
    setInterval(loadAvailability, 30000);
})();
</script>
JS;
}
