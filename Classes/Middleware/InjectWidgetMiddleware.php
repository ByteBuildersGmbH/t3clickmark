<?php

declare(strict_types=1);

namespace ByteBuilders\T3Pinpoint\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * PSR-15 middleware that injects the T3Pinpoint widget script and configuration
 * into the frontend HTML for authenticated backend users.
 */
class InjectWidgetMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $response = $handler->handle($request);

        // Only inject widget for backend-authenticated users
        if (!isset($GLOBALS['BE_USER']) || !$GLOBALS['BE_USER']->user) {
            return $response;
        }

        // Only process HTML responses
        $contentType = $response->getHeaderLine('Content-Type');
        if (strpos($contentType, 'text/html') === false) {
            return $response;
        }

        $body = $response->getBody();
        $body->rewind();
        $html = $body->getContents();

        // Only inject if there's a </body> tag
        if (strpos($html, '</body>') === false) {
            return $response;
        }

        $widgetHtml = $this->buildWidgetHtml();
        $html = str_replace('</body>', $widgetHtml . "\n</body>", $html);

        $newBody = new Stream('php://temp', 'rw');
        $newBody->write($html);

        return $response->withBody($newBody);
    }

    private function buildWidgetHtml(): string
    {
        $config = $this->buildWidgetConfig();
        $scriptPath = PathUtility::getAbsoluteWebPath(
            ExtensionManagementUtility::extPath('t3pinpoint', 'Resources/Public/JavaScript/t3pinpoint-widget.min.js')
        );

        $configJson = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<script type="application/json" id="t3pinpoint-config">' . $configJson . '</script>'
            . "\n"
            . '<script src="' . htmlspecialchars($scriptPath, ENT_QUOTES, 'UTF-8') . '" defer></script>';
    }

    private function buildWidgetConfig(): array
    {
        // Read widget display settings from TypoScript (via TSFE if available)
        $tsfe = $GLOBALS['TSFE'] ?? null;
        $settings = $tsfe?->tmpl?->setup['plugin.']['tx_t3pinpoint.']['settings.'] ?? [];

        $backendUserId = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);

        return [
            'widgetPosition' => trim($settings['widgetPosition'] ?? 'bottom-right'),
            'backendUser' => $GLOBALS['BE_USER']->user['username'] ?? '',
            'backendUserId' => $backendUserId,
            'submissionToken' => $this->generateSubmissionToken($backendUserId),
        ];
    }

    /**
     * Generate an HMAC token for authenticating widget submissions.
     * Valid for the current day, tied to the backend user ID.
     */
    private function generateSubmissionToken(int $backendUserId): string
    {
        $encryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        $payload = $backendUserId . '|' . date('Y-m-d');
        return hash_hmac('sha256', $payload, $encryptionKey);
    }
}
