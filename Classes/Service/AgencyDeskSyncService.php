<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Syncs feedback statuses and comments from AgencyDesk back into TYPO3.
 * Called on backend module load to keep local records up-to-date.
 */
class AgencyDeskSyncService
{
    private const API_ENDPOINT = 'https://clickmark.bytebuilders.de/api/v1';
    private const REGISTRY_NAMESPACE = 'tx_t3clickmark';
    private const REGISTRY_KEY = 'last_sync_timestamp';
    private const MIN_SYNC_INTERVAL = 60; // seconds — don't sync more than once per minute

    /**
     * Sync statuses from AgencyDesk. Returns number of updated records.
     * Silently returns 0 if not configured or sync interval hasn't elapsed.
     */
    public function syncFromAgencyDesk(): int
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return 0;
        }

        // Rate limit: don't sync more than once per minute
        $registry = GeneralUtility::makeInstance(Registry::class);
        $lastSync = (int)$registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, 0);
        if (time() - $lastSync < self::MIN_SYNC_INTERVAL) {
            return 0;
        }

        $lastSyncIso = $lastSync > 0
            ? date('c', $lastSync)
            : date('c', strtotime('-1 day'));

        try {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $response = $requestFactory->request(
                rtrim(self::API_ENDPOINT, '/') . '/feedback?updated_since=' . urlencode($lastSyncIso),
                'GET',
                [
                    'headers' => ['X-API-Key' => $apiKey],
                ]
            );

            if ($response->getStatusCode() !== 200) {
                return 0;
            }

            $remoteFeedbacks = json_decode((string)$response->getBody(), true);
            if (!is_array($remoteFeedbacks)) {
                return 0;
            }

            $count = $this->applyUpdates($remoteFeedbacks);

            // Update last sync timestamp
            $registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, time());

            return $count;
        } catch (\Throwable $e) {
            // Don't break the module if sync fails
            return 0;
        }
    }

    private function applyUpdates(array $remoteFeedbacks): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_t3clickmark_domain_model_feedback');

        $count = 0;

        foreach ($remoteFeedbacks as $remote) {
            $remoteId = (int)($remote['id'] ?? 0);
            $remoteStatus = $remote['status'] ?? '';

            if ($remoteId <= 0 || $remoteStatus === '') {
                continue;
            }

            // Find local record by external_id (AgencyDesk feedback ID)
            // The external_id is stored when the record is first forwarded
            $localRow = $connection->select(
                ['uid', 'status', 'external_id'],
                'tx_t3clickmark_domain_model_feedback',
                ['external_id' => (string)$remoteId]
            )->fetchAssociative();

            if ($localRow === false) {
                continue;
            }

            // Update status if different
            if ($localRow['status'] !== $remoteStatus) {
                $connection->update(
                    'tx_t3clickmark_domain_model_feedback',
                    [
                        'status' => $remoteStatus,
                        'synced_at' => time(),
                        'tstamp' => time(),
                    ],
                    ['uid' => (int)$localRow['uid']]
                );
                $count++;
            }
        }

        return $count;
    }

    private function getApiKey(): ?string
    {
        try {
            $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class);
            $apiKey = trim((string)$extConf->get('t3clickmark', 'apiKey'));
        } catch (\Exception $e) {
            return null;
        }

        return $apiKey !== '' ? $apiKey : null;
    }
}
