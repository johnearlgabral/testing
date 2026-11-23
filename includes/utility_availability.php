<?php
/**
 * Shared real-time availability panel for utility pages.
 *
 * Utilities are NOT booked directly - activities belong to venues. A utility
 * joins the existing schedule when its `location` matches a venue by name or
 * building (e.g. "Conference Room A", "Main Building"); approved activities at
 * the matched venues decide whether the utility is occupied for the morning /
 * afternoon periods, while the utility's OWN status (Maintenance / Unavailable)
 * decides whether it is blocked at all. Utilities without a matched venue
 * honestly report "No linked schedule" instead of a fake availability.
 */
function renderUtilityAvailabilityPanel() {
    $today = date('Y-m-d');
    ?>
    <section class="card availability-card" aria-labelledby="utility-availability-heading">
        <div class="availability-header">
            <div>
                <h2 id="utility-availability-heading">Schedule Availability</h2>
                <p class="page-subtitle">Approved activities at the utility's location decide whether it is usable in the morning or afternoon. The utility's own condition (Maintenance / Unavailable) always wins.</p>
            </div>
            <div class="availability-controls">
                <label for="utilityAvailabilityDate">Date</label>
                <input type="date" id="utilityAvailabilityDate" value="<?= e($today) ?>">
                <button type="button" class="btn btn-sm" id="utilityAvailabilityRefresh">Refresh</button>
            </div>
        </div>
        <div class="pill-status-legend availability-legend" aria-label="Availability legend">
            <span class="badge availability-badge availability-available">Available</span>
            <span class="badge availability-badge availability-occupied">Occupied</span>
            <span class="badge availability-badge availability-unavailable">Unavailable / maintenance</span>
            <span class="badge badge-rejected">Double-booked</span>
            <span class="availability-updated" id="utilityAvailabilityUpdated" aria-live="polite">Loading availability…</span>
        </div>
        <div class="table-wrap">
            <table class="data-table availability-table" id="utilityAvailabilityTable">
                <thead>
                    <tr>
                        <th>Utility</th>
                        <th>Location</th>
                        <th>Morning <span class="availability-period">12:00 AM–11:59 AM</span></th>
                        <th>Afternoon <span class="availability-period">12:00 PM–11:59 PM</span></th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="4" class="empty-state">Loading availability…</td></tr></tbody>
            </table>
        </div>
    </section>
    <?php
}

function utilityAvailabilityScripts() {
    return <<<'JS'
<script>
(function () {
    const table = document.getElementById('utilityAvailabilityTable');
    const dateInput = document.getElementById('utilityAvailabilityDate');
    const refreshButton = document.getElementById('utilityAvailabilityRefresh');
    const updated = document.getElementById('utilityAvailabilityUpdated');
    if (!table || !dateInput || !refreshButton || !updated) return;

    function slotHtml(slot) {
        const details = slot.activities && slot.activities.length
            ? '<div class="availability-note">' + slot.activities.map(escapeHtml).join('<br>') + '</div>'
            : '';
        // Confirmed double booking in the matched venue(s): the same room is
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

        fetch('/UBMSConnect/api/utility_availability.php?date=' + encodeURIComponent(selectedDate))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                const body = table.querySelector('tbody');
                if (data.auth_error) {
                    body.innerHTML = '<tr><td colspan="4" class="empty-state">' + escapeHtml(data.message) + '</td></tr>';
                    setTimeout(function () { window.location.href = '/UBMSConnect/login.php'; }, 1200);
                    return;
                }
                if (!data.success) throw new Error(data.message || 'Unable to load availability.');
                if (!data.utilities.length) {
                    body.innerHTML = '<tr><td colspan="4" class="empty-state">No active utilities are available to display.</td></tr>';
                } else {
                    body.innerHTML = data.utilities.map(function (utility) {
                        const name = '<strong>' + escapeHtml(utility.utility_name) + '</strong>'
                            + (utility.category ? '<br><span class="text-muted">' + escapeHtml(utility.category) + '</span>' : '');
                        return '<tr>'
                            + '<td>' + name + '</td>'
                            + '<td>' + escapeHtml(utility.location) + '</td>'
                            + '<td>' + slotHtml(utility.morning) + '</td>'
                            + '<td>' + slotHtml(utility.afternoon) + '</td>'
                            + '</tr>';
                    }).join('');
                }
                updated.textContent = formatCheckedAt(data.generated_at) + ' · Refreshes every 30 seconds';
            })
            .catch(function (error) {
                table.querySelector('tbody').innerHTML = '<tr><td colspan="4" class="empty-state">'
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
