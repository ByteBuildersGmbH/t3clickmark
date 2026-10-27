<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Domain\Repository;

use TYPO3\CMS\Extbase\Persistence\Generic\Qom\ConstraintInterface;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

class FeedbackRepository extends Repository
{
    protected $defaultOrderings = [
        'crdate' => QueryInterface::ORDER_DESCENDING,
    ];

    /**
     * Ignore storagePid — feedback records are stored globally (pid=0).
     */
    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Returns per-status counts for the status summary bar.
     *
     * @param int[]|null $pageUids Restrict to feedback on these pages
     *                             (null = no restriction, [] = nothing)
     * @return array<string, int>
     */
    public function countByStatus(?array $pageUids = null): array
    {
        $counts = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
        foreach ($counts as $status => $val) {
            $query = $this->createQuery();
            $constraints = [$query->equals('status', $status)];
            if ($pageUids !== null) {
                $constraints[] = $this->pageScopeConstraint($query, $pageUids);
            }
            $query->matching($query->logicalAnd(...$constraints));
            $counts[$status] = $query->count();
        }
        return $counts;
    }

    /**
     * @param int[]|null $pageUids Restrict to feedback on these pages
     *                             (null = no restriction, [] = nothing)
     */
    public function findFiltered(string $status = '', string $priority = '', ?array $pageUids = null): QueryResultInterface
    {
        $query = $this->createQuery();
        $constraints = [];

        if ($pageUids !== null) {
            $constraints[] = $this->pageScopeConstraint($query, $pageUids);
        }

        if ($status !== '') {
            $constraints[] = $query->equals('status', $status);
        }

        if ($priority !== '') {
            $constraints[] = $query->equals('priority', $priority);
        }

        if (!empty($constraints)) {
            $query->matching($query->logicalAnd(...$constraints));
        }

        return $query->execute();
    }

    /**
     * Extbase rejects IN () with an empty list, so an empty scope is expressed
     * as a constraint no record can satisfy (page uids are never negative).
     *
     * @param int[] $pageUids
     */
    private function pageScopeConstraint(QueryInterface $query, array $pageUids): ConstraintInterface
    {
        if ($pageUids === []) {
            return $query->equals('pageUid', -1);
        }
        return $query->in('pageUid', $pageUids);
    }
}
