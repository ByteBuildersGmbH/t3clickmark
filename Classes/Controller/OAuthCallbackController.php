<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Controller;

use ByteBuilders\T3ClickMark\Configuration\PlatformEndpoint;
use ByteBuilders\T3ClickMark\Service\ClickMarkConnectionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * eID handler for the OAuth callback from ClickMark AgencyDesk.
 * Exchanges the authorization code for an API key, stores connection
 * data in the TYPO3 Registry, and closes the popup via postMessage.
 */
class OAuthCallbackController
{
    public function handleCallback(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $code = trim($queryParams['code'] ?? '');
        $state = trim($queryParams['state'] ?? '');

        if ($code === '') {
            return $this->buildPopupResponse(false, 'Missing authorization code.');
        }

        // The redirect_uri must match exactly the one used when the code was
        // issued (the platform validates it in ConsumeCode). It is the same
        // callback URL the "Connect" button was rendered with.
        $redirectUri = GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . '?eID=t3clickmark_oauth_callback';

        // Exchange authorization code for API key
        try {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $response = $requestFactory->request(
                PlatformEndpoint::getPlatformUrl() . '/oauth/token',
                'POST',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => json_encode([
                        'code' => $code,
                        'state' => $state,
                        'client_id' => 't3clickmark',
                        'redirect_uri' => $redirectUri,
                    ]),
                ]
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                return $this->buildPopupResponse(false, 'Token exchange failed (HTTP ' . $statusCode . ').');
            }

            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data) || empty($data['api_key'])) {
                return $this->buildPopupResponse(false, 'Invalid response from platform.');
            }

            $apiKey = (string)$data['api_key'];
            $projectId = (int)($data['project_id'] ?? 0);
            $dashboardUrl = (string)($data['dashboard_url'] ?? '');

            $connectionService = GeneralUtility::makeInstance(ClickMarkConnectionService::class);
            $connectionService->storeConnection($apiKey, $projectId, $dashboardUrl);

            return $this->buildPopupResponse(true, '', $dashboardUrl);
        } catch (\Throwable $e) {
            return $this->buildPopupResponse(false, 'Connection error: ' . $e->getMessage());
        }
    }

    /**
     * Build an HTML response that notifies the opener (postMessage) and tries to
     * close. A popup opened via window.open auto-closes; a standalone tab (e.g.
     * the magic-link opened from an email) cannot self-close, so it shows a clear
     * success/error message instead of hanging on "Processing…".
     */
    private function buildPopupResponse(bool $success, string $error = '', string $dashboardUrl = ''): ResponseInterface
    {
        $payload = json_encode([
            'type' => 'clickmark-connected',
            'success' => $success,
            'error' => $error,
        ]);

        if ($success) {
            $body = '<h2 style="margin:0 0 8px;color:#0f172a">Verbunden &#10003;</h2>'
                . '<p style="margin:0 0 16px;color:#475569">ClickMark ist mit TYPO3 verbunden. Du kannst dieses Fenster schließen und ins TYPO3-Backend zurückkehren.</p>';
            if ($dashboardUrl !== '') {
                $safeDash = htmlspecialchars($dashboardUrl, ENT_QUOTES);
                $body .= '<p style="margin:0 0 16px"><a href="' . $safeDash . '" style="color:#1b7894">Zum ClickMark-Dashboard</a></p>';
            }
        } else {
            $safeError = htmlspecialchars($error !== '' ? $error : 'Bitte erneut versuchen.', ENT_QUOTES);
            $body = '<h2 style="margin:0 0 8px;color:#dc2626">Verbindung fehlgeschlagen</h2>'
                . '<p style="margin:0 0 16px;color:#475569">' . $safeError . '</p>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><title>ClickMark</title></head>
<body style="font-family:system-ui,-apple-system,sans-serif;max-width:520px;margin:64px auto;padding:0 24px;text-align:center">
{$body}
<button onclick="window.close()" style="border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:8px 16px;font-size:14px;cursor:pointer;color:#0f172a">Fenster schließen</button>
<script>
(function() {
    try { if (window.opener) { window.opener.postMessage({$payload}, '*'); } } catch (e) {}
    // Popups (window.open) close automatically; a standalone tab keeps the
    // message above visible.
    window.close();
})();
</script>
</body>
</html>
HTML;

        return new HtmlResponse($html);
    }
}
