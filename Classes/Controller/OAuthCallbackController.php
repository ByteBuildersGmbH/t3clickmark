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

            return $this->buildPopupResponse(true);
        } catch (\Throwable $e) {
            return $this->buildPopupResponse(false, 'Connection error: ' . $e->getMessage());
        }
    }

    /**
     * Build an HTML response that sends a postMessage to the opener window and closes the popup.
     */
    private function buildPopupResponse(bool $success, string $error = ''): ResponseInterface
    {
        $payload = json_encode([
            'type' => 'clickmark-connected',
            'success' => $success,
            'error' => $error,
        ]);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><title>ClickMark Connection</title></head>
<body>
<p>Processing connection&hellip;</p>
<script>
(function() {
    if (window.opener) {
        window.opener.postMessage({$payload}, '*');
    }
    window.close();
})();
</script>
</body>
</html>
HTML;

        return new HtmlResponse($html);
    }
}
