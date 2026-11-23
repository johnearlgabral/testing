<?php
/**
 * Shared complete-utility-information modal.
 * Mirrors includes/venue_details.php but shows utility-appropriate facts only:
 * no capacity/fee rows (venue concepts) and the Quantity row appears only when
 * the utility actually has one.
 */
function renderUtilityDetailsModal() {
    ?>
    <div class="modal-overlay" id="utilityDetailsModal">
        <div class="modal-box venue-details-modal-box">
            <div class="modal-header">
                <h3 id="utilityDetailsTitle">Utility Details</h3>
                <button class="modal-close" data-close-modal="utilityDetailsModal">&times;</button>
            </div>
            <div class="modal-body" id="utilityDetailsContent"></div>
            <div class="modal-footer"><button class="btn" data-close-modal="utilityDetailsModal">Close</button></div>
        </div>
    </div>
    <?php
}

function utilityDetailsScripts() {
    return <<<'JS'
<script>
function utilityStatusBadgeClass(status) {
    // Same badge classes the table and the venues page use, so a utility status
    // always looks identical everywhere (and after an inline status save).
    return {'Operational': 'badge-approved', 'Maintenance': 'badge-maintenance', 'Unavailable': 'badge-rejected'}[status] || 'badge-default';
}

function openUtilityDetails(u) {
    const image = u.image_path
        ? '<img class="venue-detail-photo" src="/UBMSConnect/assets/uploads/utilities/' + encodeURIComponent(u.image_path) + '" alt="Photo of ' + escapeHtml(u.utility_name) + '">'
        : '<div class="venue-detail-photo venue-thumbnail-placeholder" role="img" aria-label="No photo yet"><span>No photo yet</span></div>';
    // Quantity is optional: the row is skipped entirely when not applicable.
    const quantity = (u.quantity === null || u.quantity === undefined || u.quantity === '')
        ? ''
        : '<div class="detail-row"><div class="dr-label">Quantity</div><div class="dr-value">' + escapeHtml(String(u.quantity)) + '</div></div>';
    document.getElementById('utilityDetailsTitle').textContent = u.utility_name;
    document.getElementById('utilityDetailsContent').innerHTML = '<div class="venue-details-layout">'
        + '<div>' + image + '</div>'
        + '<div class="venue-details-summary">'
        + '<div class="detail-row"><div class="dr-label">Category</div><div class="dr-value">' + (u.category ? escapeHtml(u.category) : 'Not set') + '</div></div>'
        + '<div class="detail-row"><div class="dr-label">Location</div><div class="dr-value">' + escapeHtml(u.location) + '</div></div>'
        + quantity
        + '<div class="detail-row"><div class="dr-label">Status</div><div class="dr-value"><span class="badge ' + utilityStatusBadgeClass(u.status) + '">' + escapeHtml(u.status) + '</span></div></div>'
        + '<div class="detail-row"><div class="dr-label">Last Updated</div><div class="dr-value">' + escapeHtml(u.updated_at_fmt || '-') + '</div></div>'
        + '</div></div>'
        + '<div class="venue-description"><div class="dr-label">Description</div><p>'
        + (u.description ? escapeHtml(u.description).replace(/\n/g, '<br>') : 'No description has been added yet.')
        + '</p></div>';
    openModal('utilityDetailsModal');
}
</script>
JS;
}
