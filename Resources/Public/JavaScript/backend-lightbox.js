/**
 * T3Pinpoint Backend Lightbox
 *
 * Creates a full-viewport overlay appended to <body> to bypass
 * TYPO3 backend module stacking context constraints.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-t3pin-lightbox]');
        if (!trigger) return;
        e.preventDefault();

        var src = trigger.getAttribute('data-t3pin-lightbox');
        if (!src) return;

        // Build overlay as direct child of <body>
        var overlay = document.createElement('div');
        overlay.className = 't3pin-lightbox-overlay';

        var img = document.createElement('img');
        img.src = src;
        img.alt = 'Screenshot';
        overlay.appendChild(img);

        // Close on click anywhere
        overlay.addEventListener('click', function () {
            overlay.remove();
        });

        // Close on Escape key
        var onKey = function (ev) {
            if (ev.key === 'Escape') {
                overlay.remove();
                document.removeEventListener('keydown', onKey);
            }
        };
        document.addEventListener('keydown', onKey);

        document.body.appendChild(overlay);
    });
})();
