<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handles /clickmark-test-api/* requests for the visual regression test harness.
 * Receives captures from the widget's test mode JS, serves status/downloads/manifest
 * to the Node.js test runner.
 *
 * Only active when testModeEnabled is set in extension configuration.
 */
class TestApiController
{
    private const CAPTURES_BASE_DIR = '/var/www/html/var/clickmark-test-captures';

    /**
     * POST /clickmark-test-api/capture
     * Receives a captured PNG from the widget JS.
     *
     * Body fields: browser, version, path, element, capture (file)
     * Optional: total (int) — total expected captures for this page (for auto-discover mode)
     */
    public function captureAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $files = $request->getUploadedFiles();

        $browser = $this->sanitizePathSegment($body['browser'] ?? '');
        $version = $this->sanitizePathSegment($body['version'] ?? '');
        $path = $this->slugifyPath($body['path'] ?? '/');
        $element = $this->sanitizePathSegment($body['element'] ?? '');
        $total = (int)($body['total'] ?? 0);

        if ($browser === '' || $version === '') {
            return new JsonResponse(['error' => 'Missing required fields: browser, version'], 400);
        }

        $dir = self::CAPTURES_BASE_DIR . '/' . $version . '/' . $browser . '/' . $path;
        GeneralUtility::mkdir_deep($dir);

        // Write total count marker if provided (auto-discover mode)
        if (isset($body['total'])) {
            file_put_contents($dir . '/_total', (string)$total);
        }

        // Allow total-only POST (no capture file) for pages with 0 elements
        if (empty($files['capture'])) {
            if (isset($body['total']) && $total === 0) {
                return new JsonResponse(['success' => true, 'empty' => true], 201);
            }
            return new JsonResponse(['error' => 'Missing capture file'], 400);
        }

        if ($element === '') {
            return new JsonResponse(['error' => 'Missing required field: element'], 400);
        }

        $targetFile = $dir . '/' . $element . '.png';
        $files['capture']->moveTo($targetFile);

        return new JsonResponse([
            'success' => true,
            'file' => $version . '/' . $browser . '/' . $path . '/' . $element . '.png',
        ], 201);
    }

    /**
     * GET /clickmark-test-api/status
     * Returns capture completion status for a specific page/browser/version.
     *
     * Supports two modes:
     * - explicit: ?expected=N (original mode)
     * - auto-discover: reads _total marker written by capture POST
     */
    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $browser = $this->sanitizePathSegment($params['browser'] ?? '');
        $version = $this->sanitizePathSegment($params['version'] ?? '');
        $path = $this->slugifyPath($params['path'] ?? '/');
        $expectedParam = (int)($params['expected'] ?? 0);

        $dir = self::CAPTURES_BASE_DIR . '/' . $version . '/' . $browser . '/' . $path;

        $captures = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.png') as $file) {
                $captures[] = basename($file, '.png');
            }
        }

        $received = count($captures);

        // Determine expected count: explicit param takes priority, then _total marker
        $expected = $expectedParam;
        $totalKnown = $expectedParam > 0;
        if (!$totalKnown) {
            $totalFile = $dir . '/_total';
            if (file_exists($totalFile)) {
                $expected = (int)file_get_contents($totalFile);
                $totalKnown = true;
            }
        }

        return new JsonResponse([
            'complete' => $totalKnown && $received >= $expected,
            'expected' => $expected,
            'received' => $received,
            'captures' => $captures,
        ]);
    }

    /**
     * GET /clickmark-test-api/manifest
     * Returns list of captured element names for a given browser/version/path.
     * Used by the test runner to know what to download when elements are auto-discovered.
     */
    public function manifestAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $browser = $this->sanitizePathSegment($params['browser'] ?? '');
        $version = $this->sanitizePathSegment($params['version'] ?? '');
        $path = $this->slugifyPath($params['path'] ?? '/');

        $dir = self::CAPTURES_BASE_DIR . '/' . $version . '/' . $browser . '/' . $path;

        $elements = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.png') as $file) {
                $elements[] = basename($file, '.png');
            }
            sort($elements);
        }

        $total = 0;
        $totalFile = $dir . '/_total';
        if (file_exists($totalFile)) {
            $total = (int)file_get_contents($totalFile);
        }

        return new JsonResponse([
            'elements' => $elements,
            'total' => $total,
        ]);
    }

    /**
     * GET /clickmark-test-api/capture
     * Downloads a specific capture PNG.
     */
    public function downloadAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $browser = $this->sanitizePathSegment($params['browser'] ?? '');
        $version = $this->sanitizePathSegment($params['version'] ?? '');
        $path = $this->slugifyPath($params['path'] ?? '/');
        $element = $this->sanitizePathSegment($params['element'] ?? '');

        $file = self::CAPTURES_BASE_DIR . '/' . $version . '/' . $browser . '/' . $path . '/' . $element . '.png';

        if (!file_exists($file)) {
            return new JsonResponse(['error' => 'Capture not found'], 404);
        }

        $body = new Stream($file, 'r');
        return (new Response())
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Content-Disposition', 'inline; filename="' . $element . '.png"')
            ->withBody($body);
    }

    /**
     * GET /clickmark-test-api/pages
     * Returns all visible frontend pages from the TYPO3 page tree.
     * Used by the test runner for automatic page discovery.
     */
    public function pagesAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()->removeAll()->add(
            GeneralUtility::makeInstance(DeletedRestriction::class)
        );

        $rows = $queryBuilder
            ->select('uid', 'slug', 'title', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('doktype', 1),
                $queryBuilder->expr()->eq('hidden', 0),
                $queryBuilder->expr()->eq('sys_language_uid', 0)
            )
            ->orderBy('slug')
            ->executeQuery()
            ->fetchAllAssociative();

        $pages = [];
        foreach ($rows as $row) {
            $pages[] = [
                'uid' => (int)$row['uid'],
                'path' => $row['slug'] ?: '/',
                'title' => $row['title'],
            ];
        }

        return new JsonResponse(['pages' => $pages]);
    }

    /**
     * GET /clickmark-test-api/reset
     * Clears all captures for a given version.
     */
    public function resetAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $version = $this->sanitizePathSegment($params['version'] ?? '');

        if ($version === '') {
            return new JsonResponse(['error' => 'Missing version parameter'], 400);
        }

        $dir = self::CAPTURES_BASE_DIR . '/' . $version;

        if (is_dir($dir)) {
            $this->removeDirectory($dir);
        }

        return new JsonResponse([
            'success' => true,
            'deleted' => $version,
        ]);
    }

    /**
     * Sanitize a path segment — only allow safe characters.
     */
    private function sanitizePathSegment(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9._\-]/', '', $value);
    }

    /**
     * Convert a URL path to a filesystem-safe directory name.
     * "/" → "_root", "/about/" → "about", "/some/deep/path/" → "some-deep-path"
     */
    private function slugifyPath(string $path): string
    {
        $slug = trim($path, '/');
        if ($slug === '') {
            return '_root';
        }
        return preg_replace('/[^a-zA-Z0-9_\-]/', '-', $slug);
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private function removeDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
