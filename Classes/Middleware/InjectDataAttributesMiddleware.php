<?php

declare(strict_types=1);

namespace ByteBuilders\T3Pinpoint\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PSR-15 middleware that injects data-t3pin-* HTML attributes onto content elements.
 *
 * Two strategies (tried in order):
 * 1. TypoScript markers: If setup.typoscript injected <!--T3PIN:begin:UID:PID:CTYPE--> markers,
 *    convert those into data attributes and remove the markers.
 * 2. Fallback: Find standard TYPO3 content element anchors (id="c123") and inject attributes
 *    by looking up the content element in the database. This handles cases where TypoScript
 *    markers are not generated (e.g., page cache, Bootstrap Package overrides).
 *
 * Only active for authenticated TYPO3 backend users.
 */
class InjectDataAttributesMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $response = $handler->handle($request);

        // Only process for backend-authenticated users
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

        $modified = false;

        // Resolve site base URL for absolute backend edit links
        $site = $request->getAttribute('site');
        $siteBase = $site ? rtrim((string)$site->getBase(), '/') : '';

        // Strategy 1: TypoScript markers
        if (strpos($html, '<!--T3PIN:') !== false) {
            $html = $this->processMarkers($html, $siteBase);
            $html = $this->removeMarkers($html);
            $modified = true;
        }

        // Strategy 2: Fallback — find id="c{uid}" elements that don't already have data-t3pin-uid
        if (strpos($html, 'data-t3pin-uid') === false) {
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
     * Find T3PIN begin markers and inject data attributes onto the next HTML opening tag.
     */
    private function processMarkers(string $html, string $siteBase): string
    {
        $pattern = '/<!--T3PIN:begin:(\d+):(\d+):([a-zA-Z0-9_]+)-->\s*(<[a-zA-Z][a-zA-Z0-9]*)/';

        return preg_replace_callback($pattern, function (array $matches) use ($siteBase): string {
            $uid = $matches[1];
            $pid = $matches[2];
            $ctype = $matches[3];
            $tag = $matches[4];

            $backendLink = $siteBase . '/typo3/record/edit?edit[tt_content][' . $uid . ']=edit';

            return $tag
                . ' data-t3pin-uid="' . $uid . '"'
                . ' data-t3pin-pid="' . $pid . '"'
                . ' data-t3pin-type="' . htmlspecialchars($ctype, ENT_QUOTES, 'UTF-8') . '"'
                . ' data-t3pin-backend="' . htmlspecialchars($backendLink, ENT_QUOTES, 'UTF-8') . '"';
        }, $html);
    }

    /**
     * Remove all T3PIN marker comments from the HTML.
     */
    private function removeMarkers(string $html): string
    {
        return preg_replace('/<!--T3PIN:(begin|end):[^>]*-->/', '', $html);
    }

    /**
     * Fallback: Find elements with id="c{uid}" (standard TYPO3 content element IDs)
     * and inject data-t3pin-* attributes by looking up the records in the database.
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

            $attrs = ' data-t3pin-uid="' . $uid . '"'
                . ' data-t3pin-pid="' . (int)$data['pid'] . '"'
                . ' data-t3pin-type="' . htmlspecialchars($data['CType'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
                . ' data-t3pin-backend="' . htmlspecialchars($backendLink, ENT_QUOTES, 'UTF-8') . '"';

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
