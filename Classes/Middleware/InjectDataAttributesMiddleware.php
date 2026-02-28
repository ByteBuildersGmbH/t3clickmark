<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Middleware;

use ByteBuilders\T3ClickMark\Service\CrossDomainTokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PSR-15 middleware that injects data-t3cm-* HTML attributes onto content elements.
 *
 * Two strategies (tried in order):
 * 1. TypoScript markers: If setup.typoscript injected <!--T3CM:begin:UID:PID:CTYPE--> markers,
 *    convert those into data attributes and remove the markers.
 * 2. Fallback: Find standard TYPO3 content element anchors (id="c123") and inject attributes
 *    by looking up the content element in the database. This handles cases where TypoScript
 *    markers are not generated (e.g., page cache, Bootstrap Package overrides).
 *
 * Active for:
 * - Same-domain: authenticated BE_USER with t3clickmark module access
 * - Cross-domain: valid t3cm_session cookie
 */
class InjectDataAttributesMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $response = $handler->handle($request);

        // Check for ClickMark access (same-domain BE_USER or cross-domain cookie)
        if (!$this->hasClickMarkAccess($request)) {
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

        $modified = false;

        // Resolve site base URL for absolute backend edit links
        $site = $request->getAttribute('site');
        $siteBase = $site ? rtrim((string)$site->getBase(), '/') : '';

        // Strategy 1: TypoScript markers
        if (strpos($html, '<!--T3CM:') !== false) {
            $html = $this->processMarkers($html, $siteBase);
            $html = $this->removeMarkers($html);
            $modified = true;
        }

        // Strategy 2: Fallback — find id="c{uid}" elements that don't already have data-t3cm-uid
        if (strpos($html, 'data-t3cm-uid') === false) {
            $result = $this->processContentIds($html, $siteBase);
            if ($result !== null) {
                $html = $result;
                $modified = true;
            }
        }

        if (!$modified) {
            return $response;
        }

        $newBody = new Stream('php://temp', 'rw');
        $newBody->write($html);

        return $response->withBody($newBody);
    }

    /**
     * Check if the current request has ClickMark access.
     */
    private function hasClickMarkAccess(ServerRequestInterface $request): bool
    {
        // Same-domain: authenticated BE_USER with module access
        // Check for a real authenticated user (positive UID) to avoid false positives
        $beUserUid = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        if ($beUserUid > 0) {
            return $GLOBALS['BE_USER']->check('modules', 't3clickmark');
        }

        // Cross-domain: valid session cookie
        $cookies = $request->getCookieParams();
        if (isset($cookies['t3cm_session'])) {
            $tokenService = GeneralUtility::makeInstance(CrossDomainTokenService::class);
            return $tokenService->validateSessionCookie($cookies['t3cm_session']) !== null;
        }

        return false;
    }

    /**
     * Find T3CM begin markers and inject data attributes onto the next HTML opening tag.
     */
    private function processMarkers(string $html, string $siteBase): string
    {
        $pattern = '/<!--T3CM:begin:(\d+):(\d+):([a-zA-Z0-9_]+)-->\s*(<[a-zA-Z][a-zA-Z0-9]*)/';

        return preg_replace_callback($pattern, function (array $matches) use ($siteBase): string {
            $uid = $matches[1];
            $pid = $matches[2];
            $ctype = $matches[3];
            $tag = $matches[4];

            $backendLink = $siteBase . '/typo3/record/edit?edit[tt_content][' . $uid . ']=edit';

            return $tag
                . ' data-t3cm-uid="' . $uid . '"'
                . ' data-t3cm-pid="' . $pid . '"'
                . ' data-t3cm-type="' . htmlspecialchars($ctype, ENT_QUOTES, 'UTF-8') . '"'
                . ' data-t3cm-backend="' . htmlspecialchars($backendLink, ENT_QUOTES, 'UTF-8') . '"';
        }, $html);
    }

    /**
     * Remove all T3CM marker comments from the HTML.
     */
    private function removeMarkers(string $html): string
    {
        return preg_replace('/<!--T3CM:(begin|end):[^>]*-->/', '', $html);
    }

    /**
     * Fallback: Find elements with id="c{uid}" (standard TYPO3 content element IDs)
     * and inject data-t3cm-* attributes by looking up the records in the database.
     */
    private function processContentIds(string $html, string $siteBase): ?string
    {
        // Find all id="c{number}" patterns — TYPO3 adds these to each content element wrapper
        if (!preg_match_all('/id="c(\d+)"/', $html, $matches)) {
            return null;
        }

        $uids = array_map('intval', $matches[1]);
        if (empty($uids)) {
            return null;
        }

        // Fetch content element metadata in a single query
        $contentData = $this->fetchContentElements($uids);
        if (empty($contentData)) {
            return null;
        }

        // For each found content element, inject data attributes onto its opening tag
        foreach ($contentData as $uid => $data) {
            $backendLink = $siteBase . '/typo3/record/edit?edit[tt_content][' . $uid . ']=edit';

            $attrs = ' data-t3cm-uid="' . $uid . '"'
                . ' data-t3cm-pid="' . (int)$data['pid'] . '"'
                . ' data-t3cm-type="' . htmlspecialchars($data['CType'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
                . ' data-t3cm-backend="' . htmlspecialchars($backendLink, ENT_QUOTES, 'UTF-8') . '"';

            // Replace id="c{uid}" with id="c{uid}" + data attributes
            $html = preg_replace(
                '/(<[a-zA-Z][^>]*?)(\s+id="c' . $uid . '")/',
                '$1$2' . $attrs,
                $html,
                1
            );
        }

        return $html;
    }

    /**
     * Fetch pid and CType for the given tt_content UIDs.
     *
     * @param int[] $uids
     * @return array<int, array{pid: int, CType: string}>
     */
    private function fetchContentElements(array $uids): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        $result = $queryBuilder
            ->select('uid', 'pid', 'CType')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery();

        $data = [];
        while ($row = $result->fetchAssociative()) {
            $data[(int)$row['uid']] = $row;
        }

        return $data;
    }
}
