<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\EventListener;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adds a "ClickMark Feedback" button to the DocHeader when editing a tt_content record.
 * Creates a new feedback record via TYPO3's record_edit route, pre-filled with element context.
 */
class DocHeaderFeedbackButton
{
    public function __construct(
        private readonly IconFactory $iconFactory,
    ) {
    }

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        try {
            $this->addButton($event);
        } catch (\Throwable $e) {
            // Silently skip — never crash the content element editor
        }
    }

    private function addButton(ModifyButtonBarEvent $event): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return;
        }

        // Only show button if the user has ClickMark access
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null || !$this->hasT3cmAccess($backendUser)) {
            return;
        }

        $queryParams = $request->getQueryParams();

        // Only activate when editing tt_content records
        $editConfig = $queryParams['edit']['tt_content'] ?? null;
        if ($editConfig === null || !is_array($editConfig)) {
            return;
        }

        // Extract the content element UID being edited
        $contentUid = 0;
        foreach ($editConfig as $uid => $action) {
            if ($action === 'edit' && (int)$uid > 0) {
                $contentUid = (int)$uid;
                break;
            }
        }

        if ($contentUid === 0) {
            return;
        }

        // Query tt_content for CType and pid
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $row = $connection->select(['pid', 'CType'], 'tt_content', ['uid' => $contentUid])->fetchAssociative();

        if ($row === false) {
            return;
        }

        $pageUid = (int)$row['pid'];

        // Build URL to create a new feedback record via TYPO3's native record_edit,
        // pre-filled with the content element context via defVals
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $normalizedParams = $request->getAttribute('normalizedParams');
        $returnUrl = $normalizedParams ? $normalizedParams->getRequestUri() : '';

        // Build absolute backend edit link for this content element
        $backendEditLink = (string)$uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['tt_content' => [$contentUid => 'edit']],
        ]);
        // Make absolute by prepending the backend base URL
        if ($normalizedParams && !str_starts_with($backendEditLink, 'http')) {
            $backendEditLink = rtrim($normalizedParams->getSiteUrl(), '/') . $backendEditLink;
        }

        // Resolve the frontend page URL as absolute URL from the site configuration
        $pageUrl = '';
        try {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageUid);
            $siteBase = rtrim((string)$site->getBase(), '/');
            $generatedUri = (string)$site->getRouter()->generateUri((string)$pageUid);

            if (str_starts_with($generatedUri, 'http')) {
                $pageUrl = $generatedUri;
            } elseif (str_starts_with($siteBase, 'http')) {
                $pageUrl = $siteBase . '/' . ltrim($generatedUri, '/');
            } elseif ($normalizedParams) {
                // Site base is relative (e.g. "/") — use the request's site URL as domain
                $pageUrl = rtrim($normalizedParams->getSiteUrl(), '/') . '/' . ltrim($generatedUri, '/');
            }
        } catch (\Throwable $e) {
            // Site config not available — leave empty
        }

        // Store feedback on root page (pid=0) for consistency with widget submissions
        $feedbackUrl = (string)$uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [
                'tx_t3clickmark_domain_model_feedback' => [
                    0 => 'new',
                ],
            ],
            'defVals' => [
                'tx_t3clickmark_domain_model_feedback' => [
                    'content_uid' => $contentUid,
                    'page_uid' => $pageUid,
                    'content_type' => (string)$row['CType'],
                    'page_url' => $pageUrl,
                    'backend_edit_link' => $backendEditLink,
                    'backend_user' => (int)($GLOBALS['BE_USER']->user['uid'] ?? 0),
                    'backend_username' => $GLOBALS['BE_USER']->user['username'] ?? '',
                ],
            ],
            'returnUrl' => $returnUrl,
        ]);

        $buttons = $event->getButtons();
        $buttons[ButtonBar::BUTTON_POSITION_LEFT][5][] = $event->getButtonBar()
            ->makeLinkButton()
            ->setTitle('ClickMark Feedback')
            ->setHref($feedbackUrl)
            ->setIcon($this->iconFactory->getIcon('t3clickmark-module-feedback', Icon::SIZE_SMALL))
            ->setShowLabelText(true);

        $event->setButtons($buttons);
    }

    private function hasT3cmAccess(\TYPO3\CMS\Core\Authentication\BackendUserAuthentication $backendUser): bool
    {
        $config = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('t3clickmark');
        $allowedUsers = trim($config['allowedBackendUsers'] ?? '');

        if ($allowedUsers === '') {
            return $backendUser->check('modules', 't3clickmark') || $backendUser->isAdmin();
        }

        $allowed = GeneralUtility::trimExplode(',', $allowedUsers, true);
        return in_array($backendUser->user['username'] ?? '', $allowed, true);
    }
}
