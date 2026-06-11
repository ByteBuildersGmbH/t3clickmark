<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Middleware;

use ByteBuilders\T3ClickMark\Configuration\AccessControl;
use ByteBuilders\T3ClickMark\Service\CrossDomainTokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * PSR-15 middleware that injects the T3ClickMark widget script and configuration
 * into the frontend HTML.
 *
 * Supports three authentication paths:
 * 1. Token activation: ?t3cm_activate=TOKEN → validates, sets session cookie, redirects
 * 2. Same-domain: authenticated BE_USER with module access
 * 3. Cross-domain: valid t3cm_session cookie (set by token activation)
 */
class InjectWidgetMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // Token activation: validate and set cookie, redirect to clean URL
        $queryParams = $request->getQueryParams();
        if (isset($queryParams['t3cm_activate'])) {
            return $this->handleTokenActivation($request);
        }

        $response = $handler->handle($request);

        // Resolve backend user (same-domain or cross-domain cookie)
        $backendUser = $this->resolveBackendUser($request);
        if ($backendUser === null) {
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

        $widgetHtml = $this->buildWidgetHtml($backendUser, $request);
        $html = str_replace('</body>', $widgetHtml . "\n</body>", $html);

        $newBody = new Stream('php://temp', 'rw');
        $newBody->write($html);
        $newBody->rewind();

        return $response->withBody($newBody);
    }

    /**
     * Handle ?t3cm_activate token: validate, set session cookie, redirect to clean URL.
     */
    private function handleTokenActivation(ServerRequestInterface $request): ResponseInterface
    {
        $token = $request->getQueryParams()['t3cm_activate'] ?? '';
        $currentDomain = $request->getUri()->getHost();

        // Build clean URL (without t3cm_activate parameter)
        $uri = $request->getUri();
        $queryParams = $request->getQueryParams();
        unset($queryParams['t3cm_activate']);
        $cleanQuery = http_build_query($queryParams);
        $cleanUri = (string)$uri->withQuery($cleanQuery);

        $tokenService = GeneralUtility::makeInstance(CrossDomainTokenService::class);
        $userId = $tokenService->validateToken($token, $currentDomain);

        if ($userId === null) {
            // Invalid token — redirect to clean URL without cookie
            return new RedirectResponse($cleanUri, 302);
        }

        // Valid token — set session cookie and redirect
        $cookieValue = $tokenService->generateSessionCookie($userId);
        $isHttps = $request->getUri()->getScheme() === 'https';
        $cookie = sprintf(
            't3cm_session=%s; Path=/; HttpOnly; SameSite=Lax; Max-Age=%d%s',
            $cookieValue,
            8 * 3600,
            $isHttps ? '; Secure' : ''
        );

        $response = new RedirectResponse($cleanUri, 302);
        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    /**
     * Resolve the backend user from same-domain BE_USER, cross-domain session cookie,
     * or public mode (no login required).
     *
     * @return array{uid: int, username: string}|null
     */
    private function resolveBackendUser(ServerRequestInterface $request): ?array
    {
        // Public mode (Pro feature): inject widget for everyone, no backend user needed
        if (AccessControl::isPublicWidgetEnabled()) {
            return ['uid' => 0, 'username' => ''];
        }

        // Same-domain: authenticated BE_USER with ClickMark access
        $beUserUid = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);

        if ($beUserUid > 0) {
            if (!AccessControl::hasBackendUserAccess($GLOBALS['BE_USER'])) {
                return null;
            }
            return [
                'uid' => $beUserUid,
                'username' => (string)($GLOBALS['BE_USER']->user['username'] ?? ''),
            ];
        }

        // Cross-domain: check session cookie
        $cookies = $request->getCookieParams();
        if (!isset($cookies['t3cm_session'])) {
            return null;
        }

        $tokenService = GeneralUtility::makeInstance(CrossDomainTokenService::class);
        $userId = $tokenService->validateSessionCookie($cookies['t3cm_session']);
        if ($userId === null) {
            return null;
        }

        return $this->lookupBackendUser($userId);
    }

    /**
     * Look up a backend user's uid and username from the database.
     */
    private function lookupBackendUser(int $userId): ?array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('be_users');

        $row = $queryBuilder
            ->select('uid', 'username')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($userId, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$row) {
            return null;
        }

        return [
            'uid' => (int)$row['uid'],
            'username' => (string)$row['username'],
        ];
    }

    private function buildWidgetHtml(array $backendUser, ServerRequestInterface $request): string
    {
        $config = $this->buildWidgetConfig($backendUser);
        $scriptPath = PathUtility::getAbsoluteWebPath(
            ExtensionManagementUtility::extPath('t3clickmark', 'Resources/Public/JavaScript/t3clickmark-widget.min.js')
        );

        // Cache buster for test mode — ensures browser loads the latest widget build
        $queryParams = $request->getQueryParams();
        if (isset($queryParams['clickmark-test'])) {
            $cacheBuster = $queryParams['t'] ?? time();
            $scriptPath .= '?t=' . $cacheBuster;
        }

        $configJson = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<script type="application/json" id="t3clickmark-config">' . $configJson . '</script>'
            . "\n"
            . '<script src="' . htmlspecialchars($scriptPath, ENT_QUOTES, 'UTF-8') . '" defer></script>';
    }

    private function buildWidgetConfig(array $backendUser): array
    {
        // Read widget display settings from TypoScript (via TSFE if available)
        $tsfe = $GLOBALS['TSFE'] ?? null;
        $settings = $tsfe?->tmpl?->setup['plugin.']['tx_t3clickmark.']['settings.'] ?? [];

        $backendUserId = $backendUser['uid'];

        // Build backend base URL for fallback edit links (video/non-element captures)
        $backendBaseUrl = GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . 'typo3';

        return [
            'widgetPosition' => trim($settings['widgetPosition'] ?? 'right-center'),
            'backendUser' => $backendUser['username'],
            'backendUserId' => $backendUserId,
            'submissionToken' => $this->generateSubmissionToken($backendUserId),
            'backendBaseUrl' => $backendBaseUrl,
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
