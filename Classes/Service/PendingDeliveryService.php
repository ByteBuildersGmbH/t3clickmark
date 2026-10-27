<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Delivers feedback records that have not (yet) reached the ClickMark platform.
 * Shared by the console commands `clickmark:sync` and `clickmark:deliver-pending`.
 *
 * "Not delivered" always means: no external_id (the platform never confirmed the
 * record) AND one of
 *  - forward_status = 'failed'  — a delivery attempt failed (platform down, HTTP
 *    error); retried until forward_attempts reaches the attempt limit.
 *  - forward_status = 'pending' — never attempted, e.g. submitted while no API key
 *    was configured. Only records older than PENDING_GRACE_SECONDS are picked up,
 *    so a widget submission whose synchronous delivery is still in flight is not
 *    sent twice. (Records pulled in from the platform carry an external_id and are
 *    therefore never re-pushed.)
 */
class PendingDeliveryService
{
    public const DEFAULT_MAX_ATTEMPTS = 20;
    private const PENDING_GRACE_SECONDS = 300;
    private const TABLE = 'tx_t3clickmark_domain_model_feedback';

    /**
     * @return int[] uids of feedback that still has to be delivered
     */
    public function findUndeliveredUids(int $maxAttempts, bool $includeNeverSent): array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);

        $statusConstraint = $qb->expr()->and(
            $qb->expr()->eq('forward_status', $qb->createNamedParameter('failed')),
            $qb->expr()->lt('forward_attempts', $qb->createNamedParameter($maxAttempts, Connection::PARAM_INT))
        );
        if ($includeNeverSent) {
            $statusConstraint = $qb->expr()->or(
                $statusConstraint,
                $qb->expr()->and(
                    $qb->expr()->eq('forward_status', $qb->createNamedParameter('pending')),
                    $qb->expr()->lt(
                        'crdate',
                        $qb->createNamedParameter(time() - self::PENDING_GRACE_SECONDS, Connection::PARAM_INT)
                    )
                )
            );
        }

        return array_map('intval', $qb->select('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('external_id', $qb->createNamedParameter('')),
                $statusConstraint
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * Deliver all undelivered feedback.
     *
     * @return array{configured: bool, total: int, delivered: int, failed: int, errors: array<int, string>}
     *         configured=false means no API key is set — nothing was attempted.
     */
    public function deliverPending(int $maxAttempts, bool $includeNeverSent): array
    {
        $result = ['configured' => true, 'total' => 0, 'delivered' => 0, 'failed' => 0, 'errors' => []];

        $uids = $this->findUndeliveredUids($maxAttempts, $includeNeverSent);
        $result['total'] = count($uids);
        if ($uids === []) {
            return $result;
        }

        $forwarding = GeneralUtility::makeInstance(AgencyDeskForwardingService::class);
        foreach ($uids as $uid) {
            $forwarded = $forwarding->forwardFeedback($uid);
            if ($forwarded === null) {
                $result['configured'] = false;
                return $result;
            }
            if (!empty($forwarded['forwarded'])) {
                $result['delivered']++;
            } else {
                $result['failed']++;
                $result['errors'][$uid] = (string)($forwarded['error'] ?? ('HTTP ' . ($forwarded['statusCode'] ?? '?')));
            }
        }

        return $result;
    }
}
