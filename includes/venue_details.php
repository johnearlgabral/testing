<?php
/** Shared complete-venue-information modal. */
function renderVenueDetailsModal() {
    ?>
    <div class="modal-overlay" id="venueDetailsModal">
        <div class="modal-box venue-details-modal-box">
            <div class="modal-header">
                <h3 id="venueDetailsTitle">Venue Details</h3>
                <button class="modal-close" data-close-modal="venueDetailsModal">&times;</button>
            </div>
            <div class="modal-body" id="venueDetailsContent"></div>
            <div class="modal-footer"><button class="btn" data-close-modal="venueDetailsModal">Close</button></div>
        </div>
    </div>
    <?php
}

function venueDetailsScripts() {
    return <<<'JS'
<script>
function openVenueDetails(venue) {
    const image = venue.image_path
        ? '<img class="venue-detail-photo" src="/UBMSConnect/assets/uploads/venues/' + encodeURIComponent(venue.image_path) + '" alt="Photo of ' + escapeHtml(venue.venue_name) + '">'
        : '<div class="venue-detail-photo venue-thumbnail-placeholder" role="img" aria-label="No photo yet"><span>No photo yet</span></div>';
    const location = [venue.building, venue.location].filter(Boolean).map(escapeHtml).join('<br>');
    const isFree = String(venue.is_free) === '1';
    // usage_fee is DECIMAL(10,2) NULL - it arrives as "500.00" or null. A paid
    // venue with no usable amount (null, empty, zero, negative or non-numeric)
    // must read "Fee not specified" - never a fabricated zero price.
    const feeValue = Number(venue.usage_fee);
    const hasFee = isFinite(feeValue) && feeValue > 0;
    const fee = hasFee ? feeValue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : null;
    const cost = isFree
        ? '<span class="badge venue-cost-free">Free of charge</span>'
        : '<span class="badge venue-cost-paid">Requires payment</span><div class="venue-fee-amount">'
            + (fee !== null ? '&mdash; &#8369;' + fee : '&mdash; Fee not specified') + '</div>';
    document.getElementById('venueDetailsTitle').textContent = venue.venue_name;
    document.getElementById('venueDetailsContent').innerHTML = '<div class="venue-details-layout">'
        + '<div>' + image + '</div>'
        + '<div class="venue-details-summary">'
        + '<div class="detail-row"><div class="dr-label">Location</div><div class="dr-value">' + location + '</div></div>'
        + '<div class="detail-row"><div class="dr-label">Maximum Student Capacity</div><div class="dr-value">' + escapeHtml(String(venue.capacity)) + ' students</div></div>'
        + '<div class="detail-row"><div class="dr-label">Venue Use</div><div class="dr-value">' + cost + '</div></div>'
        + '</div></div>'
        + '<div class="venue-description"><div class="dr-label">Description</div><p>'
        + (venue.description ? escapeHtml(venue.description).replace(/\n/g, '<br>') : 'No description has been added yet.')
        + '</p></div>';
    openModal('venueDetailsModal');
}
</script>
JS;
}
