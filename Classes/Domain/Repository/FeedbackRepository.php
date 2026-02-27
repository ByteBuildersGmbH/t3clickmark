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
