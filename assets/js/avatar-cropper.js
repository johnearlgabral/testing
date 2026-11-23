/**
 * assets/js/avatar-cropper.js
 * Lightweight, dependency-free profile-picture cropper.
 *
 * Expects this markup to already exist on the page (identical block
 * added to staff/host/superadmin profile.php):
 *   #avatarCropperModal (.modal-overlay)
 *     #cropperViewport (.cropper-viewport)
 *       #cropperImg (<img>)
 *       .cropper-circle-guide
 *     #cropperZoomRange (<input type="range">)
 *     #cropperZoomOut / #cropperZoomIn (buttons)
 *     #cropperSaveBtn / cancel button (data-close-modal)
 *     #cropperMsg
 *
 * Usage from a page's own inline script:
 *   openAvatarCropper(file, '/UBMSConnect/actions/upload_profile_picture.php', function () {
 *       location.reload();
 *   });
 */

(function () {
    var VIEWPORT_SIZE = 260;
    var OUTPUT_SIZE = 500; // exported square image resolution, in pixels

    var state = {
        img: null,
        naturalWidth: 0,
        naturalHeight: 0,
        minScale: 1,
        scale: 1,
        offsetX: 0,
        offsetY: 0,
        dragging: false,
        dragStartX: 0,
        dragStartY: 0,
        dragStartOffsetX: 0,
        dragStartOffsetY: 0,
        uploadUrl: null,
        onSuccess: null,
    };

    function clampOffsets() {
        var w = state.naturalWidth * state.scale;
        var h = state.naturalHeight * state.scale;
        var minX = Math.min(0, VIEWPORT_SIZE - w);
        var minY = Math.min(0, VIEWPORT_SIZE - h);
        state.offsetX = Math.max(minX, Math.min(0, state.offsetX));
        state.offsetY = Math.max(minY, Math.min(0, state.offsetY));
    }

    function applyTransform() {
        if (!state.img) return;
        state.img.style.transform = 'translate(' + state.offsetX + 'px,' + state.offsetY + 'px) scale(' + state.scale + ')';
    }

    function setScale(newScale) {
        var maxScale = state.minScale * 4;
        state.scale = Math.max(state.minScale, Math.min(maxScale, newScale));
        clampOffsets();
        applyTransform();
        var range = document.getElementById('cropperZoomRange');
        if (range) range.value = Math.round((state.scale / state.minScale) * 100);
    }

    function onPointerDown(e) {
        state.dragging = true;
        var point = e.touches ? e.touches[0] : e;
        state.dragStartX = point.clientX;
        state.dragStartY = point.clientY;
        state.dragStartOffsetX = state.offsetX;
        state.dragStartOffsetY = state.offsetY;
        e.preventDefault();
    }

    function onPointerMove(e) {
        if (!state.dragging) return;
        var point = e.touches ? e.touches[0] : e;
        state.offsetX = state.dragStartOffsetX + (point.clientX - state.dragStartX);
        state.offsetY = state.dragStartOffsetY + (point.clientY - state.dragStartY);
        clampOffsets();
        applyTransform();
        e.preventDefault();
    }

    function onPointerUp() {
        state.dragging = false;
    }

    /**
     * @param {File} file - the raw file the user selected
     * @param {string} uploadUrl - endpoint to POST the cropped result to (field name "picture")
     * @param {function} onSuccess - called with the parsed JSON response on a successful save
     */
    window.openAvatarCropper = function (file, uploadUrl, onSuccess) {
        if (!file) return;

        var validTypes = ['image/jpeg', 'image/png', 'image/webp'];
        var msg = document.getElementById('cropperMsg');
        if (msg) msg.innerHTML = '';

        if (validTypes.indexOf(file.type) === -1) {
            alert('Please choose a JPG, PNG, or WebP image.');
            return;
        }
        if (file.size > 15 * 1024 * 1024) {
            alert('That image is too large. Please choose a file under 15MB.');
            return;
        }

        state.uploadUrl = uploadUrl;
        state.onSuccess = onSuccess;

        var reader = new FileReader();
        reader.onload = function (e) {
            var img = document.getElementById('cropperImg');
            img.onload = function () {
                state.img = img;
                state.naturalWidth = img.naturalWidth;
                state.naturalHeight = img.naturalHeight;
                state.minScale = Math.max(VIEWPORT_SIZE / img.naturalWidth, VIEWPORT_SIZE / img.naturalHeight);
                state.scale = state.minScale;
                state.offsetX = (VIEWPORT_SIZE - img.naturalWidth * state.scale) / 2;
                state.offsetY = (VIEWPORT_SIZE - img.naturalHeight * state.scale) / 2;

                var range = document.getElementById('cropperZoomRange');
                if (range) range.value = 100;

                applyTransform();
                openModal('avatarCropperModal');
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    };

    function saveCrop() {
        if (!state.img) return;
        var btn = document.getElementById('cropperSaveBtn');
        var msg = document.getElementById('cropperMsg');
        if (btn.disabled) return;
        btn.disabled = true;
        btn.textContent = 'Saving...';
        if (msg) msg.innerHTML = '<span class="text-muted">Processing image...</span>';

        // Map the visible viewport back to a source rectangle in the
        // ORIGINAL image's own pixel coordinates, then draw just that
        // rectangle onto a fixed-size output canvas. This is what
        // actually "bakes in" the user's chosen pan/zoom into a real
        // file - the server always receives a final flat image, never
        // a crop instruction it has to trust from the client.
        var sourceX = -state.offsetX / state.scale;
        var sourceY = -state.offsetY / state.scale;
        var sourceSize = VIEWPORT_SIZE / state.scale;

        var canvas = document.createElement('canvas');
        canvas.width = OUTPUT_SIZE;
        canvas.height = OUTPUT_SIZE;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(state.img, sourceX, sourceY, sourceSize, sourceSize, 0, 0, OUTPUT_SIZE, OUTPUT_SIZE);

        canvas.toBlob(function (blob) {
            if (!blob) {
                if (msg) msg.innerHTML = '<div class="alert alert-error">Could not process the image. Please try a different file.</div>';
                btn.disabled = false;
                btn.textContent = 'Save Photo';
                return;
            }

            var formData = new FormData();
            formData.append('picture', blob, 'avatar.jpg');

            fetch(state.uploadUrl, { method: 'POST', body: formData })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        if (msg) msg.innerHTML = '<div class="alert alert-success">' + (data.message || 'Saved.') + '</div>';
                        closeModal('avatarCropperModal');
                        if (typeof state.onSuccess === 'function') state.onSuccess(data);
                    } else {
                        if (msg) msg.innerHTML = '<div class="alert alert-error">' + (data.message || 'Upload failed.') + '</div>';
                    }
                })
                .catch(function () {
                    if (msg) msg.innerHTML = '<div class="alert alert-error">Unable to reach the server. Please try again.</div>';
                })
                .then(function () {
                    btn.disabled = false;
                    btn.textContent = 'Save Photo';
                });
        }, 'image/jpeg', 0.92);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var viewport = document.getElementById('cropperViewport');
        var img = document.getElementById('cropperImg');
        var range = document.getElementById('cropperZoomRange');
        var zoomIn = document.getElementById('cropperZoomIn');
        var zoomOut = document.getElementById('cropperZoomOut');
        var saveBtn = document.getElementById('cropperSaveBtn');

        if (!viewport || !img) return; // this page doesn't include the cropper markup

        img.addEventListener('mousedown', onPointerDown);
        window.addEventListener('mousemove', onPointerMove);
        window.addEventListener('mouseup', onPointerUp);
        img.addEventListener('touchstart', onPointerDown, { passive: false });
        window.addEventListener('touchmove', onPointerMove, { passive: false });
        window.addEventListener('touchend', onPointerUp);

        if (range) {
            range.addEventListener('input', function () {
                setScale(state.minScale * (range.value / 100));
            });
        }
        if (zoomIn) zoomIn.addEventListener('click', function () { setScale(state.scale * 1.15); });
        if (zoomOut) zoomOut.addEventListener('click', function () { setScale(state.scale / 1.15); });
        if (saveBtn) saveBtn.addEventListener('click', saveCrop);
    });
})();
