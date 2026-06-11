<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\ContextMenu;

use ByteBuilders\T3ClickMark\Configuration\AccessControl;
use ByteBuilders\T3ClickMark\Service\CrossDomainTokenService;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Context menu item provider that adds "ClickMark Review" to the page tree.
 * Only visible for backend users with access to the t3clickmark module.
 *
 * For cross-domain pages, appends a signed activation token to the URL.
 * For same-domain pages, opens the normal page URL.
 */
class ClickMarkItemProvider extends AbstractProvider
{
    /**
     * @var array<string, array<string, string>>
     */
    protected $itemsConfiguration = [
        'clickmarkReview' => [
            'type' => 'item',
            'label' => 'LLL:EXT:t3clickmark/Resources/Private/Language/locallang_db.xlf:contextmenu.review',
            'iconIdentifier' => 't3clickmark-module-feedback',
            'callbackAction' => 'viewRecord',
        ],
    ];

    public function canHandle(): bool
    {
        return $this->table === 'pages';
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function addItems(array $items): array
    {
        // Only show for users with ClickMark access
        if (!AccessControl::hasBackendUserAccess($GLOBALS['BE_USER'])) {
            return $items;
        }

        $this->initDisabledItems();
        $localItems = $this->prepareItems($this->itemsConfiguration);

        if (empty($localItems)) {
            return $items;
        }

        // Insert after 'view' if it exists
        $keys = array_keys($items);
        $viewPosition = array_search('view', $keys, true);

        if ($viewPosition !== false) {
            $beginning = array_slice($items, 0, $viewPosition + 1, true);
            $end = array_slice($items, $viewPosition + 1, null, true);
            return $beginning + $localItems + $end;
        }

        return $items + $localItems;
    }

    protected function getAdditionalAttributes(string $itemName): array
    {
        if ($itemName !== 'clickmarkReview') {
            return [];
        }

        $pageUid = (int)$this->identifier;
        $reviewUrl = $this->buildReviewUrl($pageUid);

        return [
            'data-preview-url' => $reviewUrl,
        ];
    }

    private function buildReviewUrl(int $pageUid): string
    {
        try {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $site = $siteFinder->getSiteByPageId($pageUid);

            // Generate proper speaking URL via the site router
            $uri = $site->getRouter()->generateUri((string)$pageUid);
            $pageUrl = (string)$uri;

            // Determine target host from the generated URI or site base
            $targetHost = $uri->getHost();
            if (!$targetHost) {
                $targetHost = parse_url((string)$site->getBase(), PHP_URL_HOST);
            }
            $backendHost = GeneralUtility::getIndpEnv('HTTP_HOST');

            if ($targetHost && $targetHost !== $backendHost) {
                // Cross-domain: append signed activation token
                $tokenService = GeneralUtility::makeInstance(CrossDomainTokenService::class);
                $userId = (int)$GLOBALS['BE_USER']->user['uid'];
                $token = $tokenService->generateToken($userId, $targetHost);

                $separator = (strpos($pageUrl, '?') !== false) ? '&' : '?';
                return $pageUrl . $separator . 't3cm_activate=' . urlencode($token);
            }

            return $pageUrl;
        } catch (\Exception $e) {
            return '/?id=' . $pageUid;
        }
    }

}
