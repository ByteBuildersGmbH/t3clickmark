<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Middleware;

use ByteBuilders\T3ClickMark\Controller\TestApiController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PSR-15 middleware that intercepts /clickmark-test-api/* requests and routes
 * them to TestApiController. Only active when testModeEnabled is set in
 * extension configuration.
 *
 * Runs before TYPO3's site resolution so the test API paths don't trigger
 * a page-not-found response.
 */
class TestApiMiddleware implements MiddlewareInterface
{
    private const PATH_PREFIX = '/clickmark-test-api/';

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $path = $request->getUri()->getPath();

        // Only intercept our API paths
        if (strpos($path, self::PATH_PREFIX) !== 0) {
            return $handler->handle($request);
        }

        // Check if test mode is enabled
        try {
            $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class);
            $enabled = (bool)$extConf->get('t3clickmark', 'testModeEnabled');
        } catch (\Exception $e) {
            $enabled = false;
        }

        if (!$enabled) {
            return new JsonResponse(['error' => 'Test mode is not enabled'], 404);
        }

        // Parse the sub-path after /clickmark-test-api/
        $subPath = substr($path, strlen(self::PATH_PREFIX));
        $subPath = rtrim($subPath, '/');
        $method = $request->getMethod();

        $controller = GeneralUtility::makeInstance(TestApiController::class);

        switch ($subPath) {
            case 'capture':
                if ($method === 'POST') {
                    return $controller->captureAction($request);
                }
                return $controller->downloadAction($request);

            case 'status':
                return $controller->statusAction($request);

            case 'manifest':
                return $controller->manifestAction($request);

            case 'reset':
                return $controller->resetAction($request);

            case 'pages':
                return $controller->pagesAction($request);

            default:
                return new JsonResponse(['error' => 'Unknown endpoint: ' . $subPath], 404);
        }
    }
}
