/**
 * T3ClickMark — OAuth popup handler for "Connect to ClickMark" button.
 * Opens the AgencyDesk onboarding flow in a popup window and listens
 * for the postMessage callback when the connection is established.
 */
(function () {
    'use strict';

    var connectBtn = document.getElementById('t3cm-btn-connect');
    if (!connectBtn) {
        return;
    }

    connectBtn.addEventListener('click', function () {
        var platformUrl = connectBtn.getAttribute('data-t3cm-platform-url') || 'https://clickmark.bytebuilders.de';
        var callbackUrl = connectBtn.getAttribute('data-t3cm-callback-url') || '';
        var csrfToken = Math.random().toString(36).substring(2, 15);

        var onboardUrl = platformUrl + '/onboard'
            + '?client_id=t3clickmark'
            + '&redirect_uri=' + encodeURIComponent(callbackUrl)
            + '&state=' + encodeURIComponent(csrfToken);

        var width = 600;
        var height = 700;
        var left = Math.round((screen.width - width) / 2);
        var top = Math.round((screen.height - height) / 2);

        window.open(
            onboardUrl,
            'clickmark_connect',
            'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top + ',scrollbars=yes,resizable=yes'
        );
    });

    // Listen for postMessage from the OAuth callback popup
    window.addEventListener('message', function (event) {
        if (event.data && event.data.type === 'clickmark-connected') {
            if (event.data.success) {
                // Reload the module page to reflect the connected state
                window.location.reload();
            }
        }
    });
})();
