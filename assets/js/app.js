/**
 * UBMSConnect - Shared front-end behavior
 * - Retractable sidebar (desktop hover-expand rail + mobile/touch drawer)
 * - Generic modal open/close helpers
 * - Activity detail modal loader (staff + host)
 * - Lightweight AJAX polling for "real-time" dashboard/calendar refresh
 * - Filter/search helpers
 */

document.addEventListener('DOMContentLoaded', function () {
    initSidebarToggle();
    initModalClosers();
    initLiveClock();
});

/* ---------------- Live clock (header) ---------------- */
var _liveClockTimer = null;

function initLiveClock() {
    var el = document.getElementById('liveClock');
    if (!el) return; // page doesn't include the shared header (e.g. login page)
    if (_liveClockTimer) return; // guard against a duplicate timer if this ever runs twice

    function render() {
        // Uses the browser's own local time/timezone - never assumes a
        // fixed region, so this is correct wherever the app is accessed from.
        var now = new Date();
        var hours = now.getHours();
        var minutes = now.getMinutes();
        var ampm = hours >= 12 ? 'PM' : 'AM';
        var displayHours = hours % 12;
        if (displayHours === 0) displayHours = 12;
        var displayMinutes = minutes < 10 ? '0' + minutes : minutes;
        el.textContent = displayHours + ':' + displayMinutes + ' ' + ampm;
    }

    render();
    _liveClockTimer = setInterval(render, 1000);
}

/* ---------------- Sidebar (retractable rail + mobile drawer) ---------------- */
function initSidebarToggle() {
    const toggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return; // page doesn't include the shared sidebar
    const logoutModal = document.getElementById('logoutConfirmModal');
    const confirmLogout = document.getElementById('confirmLogoutBtn');
    const cancelLogout = logoutModal && logoutModal.querySelector('[data-close-modal="logoutConfirmModal"]');
    let logoutHref = '';
    let logoutTrigger = null;
    const pinnedKey = 'ubms_sidebar_pinned';
    const navigationKey = 'ubms_sidebar_navigation';
    const persistPinnedState = function (pinned) {
        try {
            if (pinned) {
                sessionStorage.setItem(pinnedKey, '1');
            } else {
                sessionStorage.removeItem(pinnedKey);
            }
        } catch (err) { /* storage blocked */ }
    };

    // Last known pointer position, rebuilt across full-page navigations.
    // Chrome never fires hover/mouse events for a cursor resting in place on
    // a freshly loaded document, so this - saved once on pagehide - is the
    // only way to know after navigating whether the pointer is still over
    // the rail (see restoreHoverFromPointer at the bottom of this function).
    const pointerKey = 'ubms_pointer_pos';
    let lastPointerX = null;
    let lastPointerY = null;
    try {
        const raw = sessionStorage.getItem(pointerKey);
        if (raw) {
            const parts = raw.split(',');
            const px = parseFloat(parts[0]);
            const py = parseFloat(parts[1]);
            if (isFinite(px) && isFinite(py)) {
                lastPointerX = px;
                lastPointerY = py;
            }
        }
    } catch (err) { /* storage blocked */ }

    // Keep aria-expanded on the two controls in sync with the click states.
    function syncAria() {
        const open = sidebar.classList.contains('open') || sidebar.classList.contains('is-expanded');
        if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    // Topbar hamburger: slides the drawer in/out on mobile and pins the rail
    // open on touch-only devices at any width (they can't hover-expand).
    if (toggle) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
            persistPinnedState(sidebar.classList.contains('open'));
            syncAria();
        });
    }

    // Desktop: hovering the rail expands it after a short intent delay, so
    // the collapsed-state tooltip gets a moment on screen first; leaving the
    // rail retracts it immediately. Gate the capability check at event time
    // (not just at bind time): touch browsers synthesize mouseenter on tap,
    // and a tap must navigate, not reflow the layout under the finger.
    const canHoverExpand = function () {
        return !!(window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches)
            && window.innerWidth >= 861;
    };
    let hoverTimer = null;
    sidebar.addEventListener('mouseenter', function () {
        if (!canHoverExpand()) return;
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(function () {
            if (canHoverExpand()) {
                sidebar.classList.add('is-expanded');
                syncAria();
            }
        }, 250);
    });
    sidebar.addEventListener('mouseleave', function () {
        clearTimeout(hoverTimer);
        if (sidebar.classList.contains('is-expanded')) {
            sidebar.classList.remove('is-expanded');
            syncAria();
        }
    });

    // Track the pointer on every move: its position is the only input that
    // survives a full-page navigation (persisted once per navigation on
    // pagehide below), letting the next document rebuild its hover state
    // before any event arrives. The same listener doubles as a safety sweep:
    // if the rail carries .is-expanded but the browser's real :hover chain
    // no longer contains it (e.g. the cursor first moved off in a single
    // jump after a load-time restore, so no mouseleave is ever dispatched),
    // retract immediately - smooth, no outside click needed. Only the hover
    // class is touched; .open pins are never affected.
    document.addEventListener('mousemove', function (e) {
        lastPointerX = e.clientX;
        lastPointerY = e.clientY;
        if (sidebar.classList.contains('is-expanded') && !sidebar.matches(':hover')) {
            sidebar.classList.remove('is-expanded');
            syncAria();
        }
    });

    // One sessionStorage write per navigation, at unload time - never on
    // every mousemove.
    window.addEventListener('pagehide', function () {
        if (lastPointerX == null || lastPointerY == null) return;
        try {
            sessionStorage.setItem(pointerKey, lastPointerX + ',' + lastPointerY);
        } catch (err) { /* storage blocked */ }
    });

    // Escape retracts everything (and blurs focus parked inside the sidebar
    // so :focus-within doesn't hold it open).
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        clearTimeout(hoverTimer);
        sidebar.classList.remove('is-expanded');
        sidebar.classList.remove('open');
        persistPinnedState(false);
        if (sidebar.contains(document.activeElement)) document.activeElement.blur();
        syncAria();
    });

    // Clicking the page (outside the sidebar / hamburger) closes a pinned sidebar.
    document.addEventListener('click', function (e) {
        if (!sidebar.classList.contains('open')) return;
        if (sidebar.contains(e.target)) return;
        if (toggle && toggle.contains(e.target)) return;
        sidebar.classList.remove('open');
        persistPinnedState(false);
        syncAria();
    });

    // Leaving the desktop breakpoint clears the hover state so the rail
    // can't come back "stuck open" after a window resize.
    window.addEventListener('resize', function () {
        if (window.innerWidth < 861 && sidebar.classList.contains('is-expanded')) {
            sidebar.classList.remove('is-expanded');
            syncAria();
        }
    });

    // On hover-capable desktops, let hover (rather than a persistent pin)
    // control the expanded state across navigation. Touch devices keep the
    // drawer pinned because they have no cursor to hover over the sidebar.
    sidebar.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a') : null;
        if (!a || !sidebar.contains(a)) return;
        if (a.matches('.nav-item-logout')) {
            e.preventDefault();
            logoutHref = a.href;
            logoutTrigger = a;
            openModal('logoutConfirmModal');
            if (cancelLogout) cancelLogout.focus();
            return;
        }
        if (a.matches('.nav-item')) {
            if (canHoverExpand()) {
                clearTimeout(hoverTimer);
                sidebar.classList.add('is-expanded');
                sidebar.classList.remove('open');
                persistPinnedState(false);
                try {
                    sessionStorage.setItem(navigationKey, '1');
                } catch (err) { /* storage blocked */ }
            } else {
                sidebar.classList.add('open');
                persistPinnedState(true);
            }
            syncAria();
        }
    }, true);

    if (confirmLogout) {
        confirmLogout.addEventListener('click', function () {
            if (logoutHref) window.location.href = logoutHref;
        });
    }
    if (cancelLogout) {
        cancelLogout.addEventListener('click', function () {
            if (logoutTrigger) logoutTrigger.focus();
        });
    }
    if (logoutModal) {
        logoutModal.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            e.preventDefault();
            closeModal('logoutConfirmModal');
            if (logoutTrigger) logoutTrigger.focus();
        });
    }

    function restorePinnedState() {
        // Keep an explicitly pinned sidebar open after a full-page navigation
        // at both desktop and mobile widths.
        var pinned = false;
        try {
            pinned = sessionStorage.getItem(pinnedKey) === '1';
        } catch (err) { /* storage blocked */ }
        if (pinned) {
            sidebar.classList.add('open');
            syncAria();
        }
    }
    function restoreNavigationState() {
        var root = document.documentElement;
        var navigatingFromSidebar = root.getAttribute('data-sidebar-navigation') === 'open';
        if (navigatingFromSidebar && canHoverExpand()) {
            sidebar.classList.add('is-expanded');
            syncAria();
        }
        root.removeAttribute('data-sidebar-navigation');
    }
    function restoreHoverFromPointer() {
        // Rebuild the true hover state on a fresh load. Clicking a nav tab
        // must never force-collapse the rail: if the saved pointer position
        // lands on the sidebar, expand immediately so it stays open across
        // the navigation; if the pointer is elsewhere, remain in the default
        // collapsed state. This only ever applies the real pointer state -
        // it never pins (.open untouched) and never retracts anything.
        // Gated to hover-capable desktops so the mobile drawer is untouched;
        // subsequent mouseleave / the mousemove sweep retract normally.
        if (!canHoverExpand()) return;
        if (lastPointerX == null || lastPointerY == null) return;
        if (typeof document.elementFromPoint !== 'function') return;
        var hit = document.elementFromPoint(lastPointerX, lastPointerY);
        if (hit && sidebar.contains(hit)) {
            sidebar.classList.add('is-expanded');
            syncAria();
        }
    }
    restoreNavigationState();
    restorePinnedState();
    restoreHoverFromPointer();

    syncAria();
}

/* ---------------- Generic modal helpers ---------------- */
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('open');
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
}
function initModalClosers() {
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.classList.remove('open');
        });
    });
    document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(btn.getAttribute('data-close-modal'));
        });
    });
}

/* ---------------- Activity detail modal ---------------- */
/**
 * Loads activity details via the api/activities.php endpoint and
 * renders them into the shared #activityModal on the page.
 * canEdit controls whether status-change controls are rendered
 * (the server also independently enforces this on submit).
 */
function loadActivityDetail(activityId, canEdit) {
    const body = document.getElementById('activityModalBody');
    if (!body) return;
    body.innerHTML = '<p class="text-muted">Loading activity details...</p>';
    openModal('activityModal');

    fetch('/UBMSConnect/api/activities.php?action=detail&id=' + encodeURIComponent(activityId))
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.auth_error) {
                body.innerHTML = '<p class="text-muted">' + escapeHtml(data.message) + ' Redirecting to login...</p>';
                setTimeout(function () { window.location.href = '/UBMSConnect/login.php'; }, 1800);
                return;
            }
            if (!data.success) {
                body.innerHTML = '<p class="text-muted">' + escapeHtml(data.message || 'Unable to load activity.') + '</p>';
                return;
            }
            renderActivityDetail(data.activity, data.history, canEdit);
        })
        .catch(function () {
            body.innerHTML = '<p class="text-muted">Unable to reach the server. Please check your connection and try again.</p>';
        });
}

function renderActivityDetail(a, history, canEdit) {
    const body = document.getElementById('activityModalBody');

    let html = '';
    html += row('Activity ID', '#' + a.id);
    html += row('Activity Name', escapeHtml(a.activity_name));
    html += row('Description', escapeHtml(a.description || 'N/A'));
    html += row('Event Host', escapeHtml(a.host_name));
    html += row('Venue', escapeHtml(a.venue_name) + ' (' + escapeHtml(a.building) + ')');
    html += row('Date', escapeHtml(a.activity_date_fmt));
    html += row('Time', escapeHtml(a.start_time_fmt) + ' - ' + escapeHtml(a.end_time_fmt));
    html += row('Current Status', badge(a.status));
    html += row('Venue Condition', badge(a.venue_status));
    html += row('Last Updated', escapeHtml(a.updated_at_fmt));

    html += '<h4 style="margin-top:16px;">Status History</h4>';
    if (history && history.length) {
        html += '<div class="table-wrap"><table class="data-table"><thead><tr>'
            + '<th>Previous</th><th>New</th><th>Changed By</th><th>Date/Time</th>'
            + '</tr></thead><tbody>';
        history.forEach(function (h) {
            html += '<tr><td>' + badge(h.previous_status) + '</td><td>' + badge(h.new_status) + '</td>'
                + '<td>' + escapeHtml(h.changed_by_name) + '</td><td>' + escapeHtml(h.changed_at_fmt) + '</td></tr>';
        });
        html += '</tbody></table></div>';
    } else {
        html += '<p class="text-muted">No status changes recorded yet.</p>';
    }

    if (canEdit) {
        html += '<h4 style="margin-top:16px;">Change Status</h4>';
        html += '<form id="statusChangeForm" class="form-group" style="display:flex; gap:8px; align-items:flex-end;">';
        html += '<div style="flex:1;"><select id="newStatusSelect">';
        ['Pending', 'Approved', 'Rejected', 'Maintenance', 'Completed'].forEach(function (s) {
            html += '<option value="' + s + '"' + (s === a.status ? ' selected' : '') + '>' + s + '</option>';
        });
        html += '</select></div>';
        html += '<button type="button" class="btn btn-primary" id="statusChangeBtn" onclick="submitStatusChange(' + a.id + ')">Update Status</button>';
        html += '</form>';
        html += '<div id="statusChangeMsg" style="margin-top:8px;"></div>';
    } else {
        html += '<div class="readonly-note">You are viewing this activity in read-only mode. Event Hosts cannot modify activity status.</div>';
    }

    body.innerHTML = html;

    function row(label, value) {
        return '<div class="detail-row"><div class="dr-label">' + label + '</div><div class="dr-value">' + value + '</div></div>';
    }
    function badge(status) {
        const cls = {
            'Pending': 'badge-pending', 'Approved': 'badge-approved', 'Rejected': 'badge-rejected',
            'Maintenance': 'badge-maintenance', 'Completed': 'badge-completed',
            'Available': 'badge-approved', 'In Use': 'badge-pending', 'Under Maintenance': 'badge-maintenance'
        }[status] || 'badge-default';
        return '<span class="badge ' + cls + '">' + escapeHtml(status) + '</span>';
    }
}

function submitStatusChange(activityId, forceConfirm) {
    const select = document.getElementById('newStatusSelect');
    const btn = document.getElementById('statusChangeBtn');
    const msg = document.getElementById('statusChangeMsg');
    const newStatus = select.value;

    if (btn && btn.disabled) return; // already saving - ignore duplicate click
    if (btn) { btn.disabled = true; btn.textContent = 'Updating...'; }
    msg.innerHTML = '<span class="text-muted">Updating...</span>';

    let body = 'activity_id=' + encodeURIComponent(activityId) + '&new_status=' + encodeURIComponent(newStatus);
    // Only sent on the second attempt, after the user has been shown what the
    // activity would clash with and has chosen to approve anyway.
    if (forceConfirm) body += '&confirm_conflict=1';

    postJSON('/UBMSConnect/actions/update_status.php', body)
        .then(function (data) {
            if (data.success) {
                msg.innerHTML = '<div class="alert alert-success">' + escapeHtml(data.message || 'Status updated successfully.') + '</div>';
                loadActivityDetail(activityId, true);
                if (typeof refreshCurrentView === 'function') refreshCurrentView();
                return;
            }

            if (data.conflict && !forceConfirm) {
                // Venue schedule conflict: the change was refused. Show what it
                // clashes with, then let the user deliberately override it.
                if (btn) { btn.disabled = false; btn.textContent = 'Update Status'; }
                renderConflictConfirm(msg, data, function () { submitStatusChange(activityId, true); });
                return;
            }

            msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(data.message || 'Update failed.') + '</div>';
            if (btn) { btn.disabled = false; btn.textContent = 'Update Status'; }
        })
        .catch(function (err) {
            msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message || 'Something went wrong. Please try again.') + '</div>';
            if (btn) { btn.disabled = false; btn.textContent = 'Update Status'; }
        });
}

/* ---------------- Venue schedule conflict confirmation ---------------- */
/**
 * Renders the "this would double-book a venue" warning returned by
 * actions/update_status.php (data.conflict === true) with an explicit
 * second-confirmation button. Used by the activity modal (here) and by the
 * inline status form on the activity detail pages, so both behave identically.
 *
 * @param {HTMLElement} container where to render
 * @param {object}      data      the server response
 * @param {Function}    onConfirm called when the user chooses to approve anyway
 */
function renderConflictConfirm(container, data, onConfirm) {
    if (!container) return;

    const rows = (data.conflicts || []).map(function (c) {
        return '<li><strong>' + escapeHtml(c.name) + '</strong> &mdash; '
            + escapeHtml(c.start_fmt || '') + ' to ' + escapeHtml(c.end_fmt || '')
            + ' <span class="badge badge-approved">' + escapeHtml(c.status || '') + '</span>'
            + '<div class="small-note">Host: ' + escapeHtml(c.host || 'Unknown')
            + ' &middot; Overlaps this activity by ' + escapeHtml(c.overlap_fmt || '')
            + ' (' + escapeHtml(c.overlap_start_fmt || '') + ' &ndash; ' + escapeHtml(c.overlap_end_fmt || '') + ')</div></li>';
    }).join('');

    container.innerHTML =
        '<div class="alert alert-error conflict-confirm">'
        + '<strong>Venue schedule conflict detected.</strong> '
        + escapeHtml(data.message || '')
        + (rows ? '<ul class="conflict-confirm-list">' + rows + '</ul>' : '')
        + '<p class="small-note">Approving anyway records the double booking, who allowed it, and notifies both '
        + 'Event Hosts plus every Staff and Super Admin account.</p>'
        + '<div class="conflict-confirm-actions">'
        + '<button type="button" class="btn btn-primary btn-sm" id="conflictConfirmBtn">Approve anyway</button>'
        + '<button type="button" class="btn btn-sm" id="conflictCancelBtn">Cancel</button>'
        + '</div></div>';

    const confirmBtn = document.getElementById('conflictConfirmBtn');
    const cancelBtn  = document.getElementById('conflictCancelBtn');

    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Approving...';
            container.innerHTML = '<span class="text-muted">Approving and recording the conflict...</span>';
            onConfirm();
        });
    }
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            container.innerHTML = '<div class="alert alert-info">Nothing was changed. The activity keeps its current status.</div>';
        });
    }
}


/* ---------------- Utility ---------------- */
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Shared POST helper for every AJAX action in the app. Distinguishes:
 *   - a genuine network failure (fetch() itself rejected - offline, DNS, etc.)
 *   - a non-JSON response (PHP fatal error / unexpected HTML instead of JSON)
 *   - an auth failure (session expired / insufficient role) - server sends
 *     { auth_error: true } with HTTP 401/403 instead of redirecting, so we
 *     can show a clear message and send the user back to login ourselves.
 *   - a normal validation/business-logic failure returned by the server.
 * This replaces the old pattern of a bare .catch() showing a generic
 * "Network error" for every failure type, which was masking real problems
 * (like an expired session) as fake connectivity issues.
 */
function postJSON(url, bodyString) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: bodyString
    })
        .catch(function () {
            // fetch() itself threw - this is the only case that's a genuine network problem.
            throw new Error('Unable to reach the server. Check your network connection and try again.');
        })
        .then(function (res) {
            return res.json()
                .catch(function () {
                    throw new Error('The server returned an unexpected response (not valid JSON). This usually means a server-side error occurred - check the PHP error log for details.');
                })
                .then(function (data) {
                    if (data && data.auth_error) {
                        setTimeout(function () { window.location.href = '/UBMSConnect/login.php'; }, 1800);
                    }
                    return data;
                });
        });
}

/**
 * Disables a button and swaps its label while an async action runs, then
 * restores it. Prevents duplicate/double-click submissions.
 */
function withButtonLoading(button, loadingText, fn) {
    if (!button || button.disabled) return; // already in-flight, ignore duplicate click
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = loadingText;
    const restore = function () {
        button.disabled = false;
        button.textContent = originalText;
    };
    fn().then(restore).catch(restore);
}

/* ---------------- Simple AJAX polling (simulated real-time) ---------------- */
/**
 * Pages can call startPolling(fn, ms) to periodically re-fetch data
 * without a full page reload. Used by dashboard.php and calendar.php.
 */
function startPolling(callback, intervalMs) {
    intervalMs = intervalMs || 15000;
    callback();
    return setInterval(callback, intervalMs);
}

/* ---------------- Notification bell (shared across all roles) ---------------- */
function refreshNotificationBell() {
    const badge = document.getElementById('notifBadge');
    if (!badge) return; // bell not present on this page (e.g. login page)

    fetch('/UBMSConnect/api/notifications.php?action=unread_count')
        .then(function (r) { return r.json(); })
        .catch(function () { return null; })
        .then(function (data) {
            if (!data || !data.success) return;
            if (data.unread_count > 0) {
                badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        });
}

function loadNotifPanel() {
    const list = document.getElementById('notifPanelList');
    if (!list) return;
    list.innerHTML = '<div class="empty-state" style="padding:16px;">Loading...</div>';

    fetch('/UBMSConnect/api/notifications.php?action=list')
        .then(function (r) { return r.json(); })
        .catch(function () { return null; })
        .then(function (data) {
            if (!data || !data.success) {
                list.innerHTML = '<div class="empty-state" style="padding:16px;">Unable to load notifications.</div>';
                return;
            }
            if (!data.notifications.length) {
                list.innerHTML = '<div class="empty-state" style="padding:16px;">No notifications yet.</div>';
                return;
            }
            // Render using the read/unread state AS FETCHED (so the user still
            // sees which ones were unread a moment ago), then separately tell
            // the backend to actually mark them read in the database and
            // update the badge - the panel view itself is what "read" means.
            const hadUnread = data.notifications.some(function (n) { return !n.is_read; });

            list.innerHTML = data.notifications.slice(0, 8).map(function (n) {
                const unreadStyle = n.is_read ? '' : 'font-weight:600; background:var(--notif-unread-bg);';
                return '<div class="notif-panel-item" style="' + unreadStyle + '" onclick="handleNotifClick(' + n.id + ', ' + (n.is_read ? 'true' : 'false') + ')">'
                    + '<div>' + escapeHtml(n.message) + '</div>'
                    + '<div class="small-note" style="text-transform:none;">' + escapeHtml(n.created_at_fmt) + '</div>'
                    + '</div>';
            }).join('');

            // Opening the panel is what "views" these notifications - mark
            // them read in the database now (not just visually), then
            // refresh the badge. This is a real DB write via postJSON, not
            // a client-side-only badge hide.
            if (hadUnread) {
                postJSON('/UBMSConnect/api/notifications.php', 'action=mark_all_read')
                    .then(function (result) {
                        if (result && result.success) refreshNotificationBell();
                    })
                    .catch(function () {});
            }
        });
}

function handleNotifClick(id, alreadyRead) {
    if (!alreadyRead) {
        postJSON('/UBMSConnect/api/notifications.php', 'action=mark_read&id=' + encodeURIComponent(id))
            .then(function () { refreshNotificationBell(); loadNotifPanel(); })
            .catch(function () {});
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const bellBtn = document.getElementById('notifBellBtn');
    const panel = document.getElementById('notifPanel');
    if (bellBtn && panel) {
        refreshNotificationBell();
        setInterval(refreshNotificationBell, 20000);

        bellBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            const isOpen = panel.classList.toggle('open');
            if (isOpen) loadNotifPanel();
        });
        document.addEventListener('click', function (e) {
            if (!panel.contains(e.target) && e.target !== bellBtn) panel.classList.remove('open');
        });
    }
});
