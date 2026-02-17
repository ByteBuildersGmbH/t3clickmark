<?php

declare(strict_types=1);

namespace ByteBuilders\T3Pinpoint\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Stream;

/**
 * PSR-15 middleware that converts T3PIN marker comments into data-t3pin-* HTML attributes.
 *
 * The TypoScript setup wraps each tt_content rendering with:
 *   <!--T3PIN:begin:UID:PID:CTYPE-->...content...<!--T3PIN:end:UID-->
 *
 * This middleware finds those markers, injects data attributes onto the content element's
 * outermost HTML tag, and removes all marker comments from the output.
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

        // Skip if no T3PIN markers found
        if (strpos($html, '<!--T3PIN:') === false) {
            return $response;
        }

        $html = $this->processMarkers($html);
        $html = $this->removeMarkers($html);

        $newBody = new Stream('php://temp', 'rw');
        $newBody->write($html);

        return $response->withBody($newBody);
    }

    /**
     * Find T3PIN begin markers and inject data attributes onto the next HTML opening tag.
     */
    private function processMarkers(string $html): string
    {
        // Pattern: <!--T3PIN:begin:UID:PID:CTYPE--> followed by optional whitespace and an opening HTML tag
        $pattern = '/<!--T3PIN:begin:(\d+):(\d+):([a-zA-Z0-9_]+)-->\s*(<[a-zA-Z][a-zA-Z0-9]*)/';

        return preg_replace_callback($pattern, function (array $matches): string {
            $uid = $matches[1];
            $pid = $matches[2];
            $ctype = $matches[3];
            $tag = $matches[4];

            $backendLink = '/typo3/record/edit?edit[tt_content][' . $uid . ']=edit';

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
}
