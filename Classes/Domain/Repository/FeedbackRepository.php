<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Domain\Repository;

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
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $counts = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
        foreach ($counts as $status => $val) {
            $query = $this->createQuery();
            $query->matching($query->equals('status', $status));
            $counts[$status] = $query->count();
        }
        return $counts;
    }

    public function findFiltered(string $status = '', string $priority = ''): QueryResultInterface
    {
        $query = $this->createQuery();
        $constraints = [];

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
}
