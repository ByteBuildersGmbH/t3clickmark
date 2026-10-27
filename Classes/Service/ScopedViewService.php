<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Determines which feedback pages a backend user may see in the ClickMark
 * backend module (scoped view).
 *
 * Admins see all feedback. Non-admins only see feedback whose page lies
 * inside their web mounts. Feedback without a page reference (page_uid = 0,
 * e.g. video recordings or tickets pulled from the platform without page
 * context) is not visible to non-admins.
 */
class ScopedViewService
{
    private const FEEDBACK_TABLE = 'tx_t3clickmark_domain_model_feedback';

    /**
     * @return int[]|null Page uids the user may see; null means "no
     *                    restriction" (admin). An empty array means "nothing".
     */
    public function getAccessiblePageIds(BackendUserAuthentication $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }

        // Only the pages that actually carry feedback are checked against the
        // web mounts — not the whole page tree.
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::FEEDBACK_TABLE);

        $pageUids = $queryBuilder
            ->select('page_uid')
            ->from(self::FEEDBACK_TABLE)
            ->where(
                $queryBuilder->expr()->gt('page_uid', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->groupBy('page_uid')
            ->executeQuery()
            ->fetchFirstColumn();

        $accessibleIds = [];
        foreach ($pageUids as $pageUid) {
            $pageUid = (int)$pageUid;
            if ($user->isInWebMount($pageUid) !== null) {
                $accessibleIds[] = $pageUid;
            }
        }

        return $accessibleIds;
    }
}
