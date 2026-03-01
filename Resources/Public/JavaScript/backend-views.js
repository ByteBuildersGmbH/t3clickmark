/**
 * T3ClickMark — View switch (Feed / Compact / Board)
 * Also handles full-width layout and board column toggling.
 */
(function() {
    'use strict';

    // --- Full-width layout ---
    var el = document.querySelector('.t3cm-list, .t3cm-detail');
    if (el) {
        var parent = el.parentElement;
        while (parent && parent !== document.body && parent !== document.documentElement) {
            parent.style.maxWidth = 'none';
            parent.style.width = '100%';
            parent.style.boxSizing = 'border-box';
            parent = parent.parentElement;
        }
        document.body.style.margin = '0';
        document.body.style.padding = '0';
        document.body.style.width = '100%';
    }

    // --- View switch (only on list view) ---
    if (!document.getElementById('t3cm-view-feed')) return;

    function switchView(view, btn) {
        var views = document.querySelectorAll('.t3cm-view');
        for (var i = 0; i < views.length; i++) {
            views[i].classList.remove('t3cm-view--active');
        }

        var target = document.getElementById('t3cm-view-' + view);
        if (target) target.classList.add('t3cm-view--active');

        if (btn) {
            var btns = document.querySelectorAll('.t3cm-view-btn');
            for (var i = 0; i < btns.length; i++) {
                btns[i].classList.remove('t3cm-view-btn--active');
            }
            btn.classList.add('t3cm-view-btn--active');
        }

        var statusEl = document.getElementById('t3cm-header-status');
        var filterEl = document.getElementById('t3cm-filter-bar');

        if (view === 'board') {
            if (statusEl) statusEl.classList.add('is-hidden');
        } else {
            if (statusEl) statusEl.classList.remove('is-hidden');
        }

        try { localStorage.setItem('t3cm-view', view); } catch(e) {}
    }

    function toggleColumn(id) {
        var col = document.getElementById(id);
        if (!col) return;
        col.classList.toggle('t3cm-column--collapsed');
        var toggle = col.querySelector('.t3cm-column-toggle');
        if (toggle) {
            if (col.classList.contains('t3cm-column--collapsed')) {
                toggle.innerHTML = '&#9654;';
                toggle.title = 'Expand';
            } else {
                toggle.innerHTML = '&#9660;';
                toggle.title = 'Collapse';
            }
        }
    }

    // Attach view toggle handlers
    var viewBtns = document.querySelectorAll('.t3cm-view-btn');
    for (var i = 0; i < viewBtns.length; i++) {
        (function(btn) {
            btn.addEventListener('click', function() {
                switchView(btn.getAttribute('data-view'), btn);
            });
        })(viewBtns[i]);
    }

    // Attach column toggle/expand handlers
    var colTriggers = document.querySelectorAll('[data-t3cm-toggle-col]');
    for (var i = 0; i < colTriggers.length; i++) {
        (function(trigger) {
            trigger.addEventListener('click', function() {
                toggleColumn(trigger.getAttribute('data-t3cm-toggle-col'));
            });
        })(colTriggers[i]);
    }

    // --- Smart board column collapse: hide empty, show populated ---
    var columns = document.querySelectorAll('.t3cm-column');
    for (var i = 0; i < columns.length; i++) {
        var cards = columns[i].querySelector('.t3cm-column-cards');
        var hasCards = cards && cards.querySelector('.t3cm-board-card');
        var toggle = columns[i].querySelector('.t3cm-column-toggle');
        if (!hasCards) {
            columns[i].classList.add('t3cm-column--collapsed');
            if (toggle) { toggle.innerHTML = '&#9654;'; toggle.title = 'Expand'; }
        } else {
            columns[i].classList.remove('t3cm-column--collapsed');
            if (toggle) { toggle.innerHTML = '&#9660;'; toggle.title = 'Collapse'; }
        }
    }

    // Restore last view from localStorage
    try {
        var saved = localStorage.getItem('t3cm-view');
        if (saved && saved !== 'feed') {
            var btn = document.querySelector('.t3cm-view-btn[data-view="' + saved + '"]');
            if (btn) switchView(saved, btn);
        }
    } catch(e) {}
})();
