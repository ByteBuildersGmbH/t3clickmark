/**
 * T3ClickMark Backend Lightbox
 *
 * Creates a full-viewport overlay appended to <body> to bypass
 * TYPO3 backend module stacking context constraints.
 *
 * Entrance: overlay + image fade in with scale (300ms ease-out).
 * Exit: reverse animation (250ms ease-in), then remove from DOM.
 */
(function () {
    'use strict';

    var CLOSE_DURATION = 250;

    function closeLightbox(overlay) {
        overlay.classList.add('t3cm-lightbox-overlay--closing');
        setTimeout(function () {
            overlay.remove();
        }, CLOSE_DURATION);
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-t3cm-lightbox]');
        if (!trigger) return;
        e.preventDefault();

        var src = trigger.getAttribute('data-t3cm-lightbox');
        if (!src) return;

        // Build overlay as direct child of <body>
        var overlay = document.createElement('div');
        overlay.className = 't3cm-lightbox-overlay';

        var img = document.createElement('img');
        img.src = src;
        img.alt = 'Screenshot';
        overlay.appendChild(img);

        // Close on click anywhere
        overlay.addEventListener('click', function () {
            closeLightbox(overlay);
        });

        // Close on Escape key
        var onKey = function (ev) {
            if (ev.key === 'Escape') {
                closeLightbox(overlay);
                document.removeEventListener('keydown', onKey);
            }
        };
        document.addEventListener('keydown', onKey);

        document.body.appendChild(overlay);
    });
})();
