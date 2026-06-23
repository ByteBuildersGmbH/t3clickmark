<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Service;

use ByteBuilders\T3ClickMark\Configuration\PlatformEndpoint;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Syncs feedback statuses and comments from AgencyDesk back into TYPO3.
 * Called on backend module load to keep local records up-to-date.
 *
 * The source URL is resolved via PlatformEndpoint::getApiEndpoint() (default
 * https://app.clickmark.it/api/v1); only the Pro extension may override it
 * (t3clickmark_pro > apiEndpoint).
 */
class AgencyDeskSyncService
{
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
                PlatformEndpoint::getApiEndpoint() . '/feedback?updated_since=' . urlencode($lastSyncIso),
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

            $count = $this->applyUpdates($apiKey, $remoteFeedbacks);

            // Update last sync timestamp
            $registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, time());

            return $count;
        } catch (\Throwable $e) {
            // Don't break the module if sync fails
            return 0;
        }
    }

    private function applyUpdates(string $apiKey, array $remoteFeedbacks): int
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
            $localRow = $connection->select(
                ['uid', 'status', 'external_id'],
                'tx_t3clickmark_domain_model_feedback',
                ['external_id' => (string)$remoteId]
            )->fetchAssociative();

            if ($localRow === false) {
                // Not yet present on this TYPO3 install — pull it in so editors on
                // staging/live mirrors see the full project's tickets, not just
                // the ones submitted locally.
                $localUid = $this->createFromRemote($remote);
                if ($localUid === null) {
                    continue;
                }
                $this->syncComments($apiKey, $remoteId, $localUid);
                $count++;
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

            // Sync comments from AgencyDesk
            $this->syncComments($apiKey, $remoteId, (int)$localRow['uid']);
        }

        return $count;
    }

    /**
     * Insert a new local feedback record from a remote AgencyDesk payload.
     * Returns the new uid, or null if the payload was invalid.
     *
     * Screenshots and attachments are not pulled in — they remain accessible
     * via the linked Jira ticket and the AgencyDesk dashboard.
     */
    private function createFromRemote(array $remote): ?int
    {
        $title = trim((string)($remote['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_t3clickmark_domain_model_feedback');

        $now = time();
        $createdAt = isset($remote['created_at']) ? (int)strtotime((string)$remote['created_at']) : 0;
        if ($createdAt <= 0) {
            $createdAt = $now;
        }

        $jiraKey = trim((string)($remote['jira_issue_key'] ?? ''));
        $jiraUrl = trim((string)($remote['jira_issue_url'] ?? ''));

        $browser = trim((string)($remote['browser'] ?? ''));
        $os = trim((string)($remote['os'] ?? ''));
        $browserInfo = trim($browser . ($os !== '' ? ' / ' . $os : ''));

        $record = [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $createdAt,
            'title' => $title,
            'description' => (string)($remote['description'] ?? ''),
            'status' => (string)($remote['status'] ?? 'open'),
            'priority' => (string)($remote['priority'] ?? 'medium'),
            'category' => (string)($remote['category'] ?? 'change_request'),
            'page_uid' => (int)($remote['page_uid'] ?? 0),
            'content_uid' => (int)($remote['content_uid'] ?? 0),
            'content_type' => (string)($remote['content_type'] ?? ''),
            'page_url' => (string)($remote['page_url'] ?? ''),
            'backend_edit_link' => (string)($remote['backend_edit_link'] ?? ''),
            'browser_info' => $browserInfo,
            'viewport' => (string)($remote['viewport'] ?? ''),
            'css_selector' => (string)($remote['css_selector'] ?? ''),
            'console_errors' => (int)($remote['console_errors'] ?? 0),
            'console_warnings' => (int)($remote['console_warnings'] ?? 0),
            'failed_requests' => (int)($remote['failed_requests'] ?? 0),
            'backend_user' => (int)($remote['backend_user_id'] ?? 0),
            'backend_username' => (string)($remote['backend_username'] ?? ''),
            'external_id' => (string)($remote['id'] ?? ''),
            'external_url' => $jiraUrl !== '' ? $jiraUrl : $jiraKey,
            'synced_at' => $now,
        ];

        $connection->insert('tx_t3clickmark_domain_model_feedback', $record);
        return (int)$connection->lastInsertId();
    }

    /**
     * Pull comments from AgencyDesk and insert any new ones into the local TYPO3 table.
     */
    private function syncComments(string $apiKey, int $agencyDeskFeedbackId, int $localFeedbackUid): void
    {
        try {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $response = $requestFactory->request(
                PlatformEndpoint::getApiEndpoint() . '/feedback/' . $agencyDeskFeedbackId . '/comments',
                'GET',
                ['headers' => ['X-API-Key' => $apiKey]]
            );

            if ($response->getStatusCode() !== 200) {
                return;
            }

            $remoteComments = json_decode((string)$response->getBody(), true);
            if (!is_array($remoteComments)) {
                return;
            }

            $commentConnection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tx_t3clickmark_domain_model_feedbackcomment');

            foreach ($remoteComments as $remote) {
                $remoteSource = $remote['source'] ?? '';
                // Only sync comments NOT from typo3 (those were created locally)
                if ($remoteSource === 'typo3') {
                    continue;
                }

                // Dedup: check if we already have this comment (by external_id or matching text+author+timestamp)
                $commentText = $remote['comment'] ?? '';
                $authorName = $remote['author_name'] ?? 'Unknown';
                $externalId = 'ad_' . ($remote['id'] ?? '0'); // prefix to avoid collision with Jira external_ids

                // Check if already synced
                $existing = $commentConnection->select(
                    ['uid'],
                    'tx_t3clickmark_domain_model_feedbackcomment',
                    ['feedback' => $localFeedbackUid]
                )->fetchAllAssociative();

                // Simple dedup: check if exact comment text + author already exists
                $isDuplicate = false;
                foreach ($existing as $ex) {
                    $existingRow = $commentConnection->select(
                        ['comment', 'author_name'],
                        'tx_t3clickmark_domain_model_feedbackcomment',
                        ['uid' => (int)$ex['uid']]
                    )->fetchAssociative();
                    if ($existingRow && $existingRow['comment'] === $commentText && $existingRow['author_name'] === $authorName) {
                        $isDuplicate = true;
                        break;
                    }
                }

                if ($isDuplicate) {
                    continue;
                }

                // Insert new comment
                $commentConnection->insert('tx_t3clickmark_domain_model_feedbackcomment', [
                    'pid' => 0,
                    'feedback' => $localFeedbackUid,
                    'comment' => $commentText,
                    'author_name' => $authorName,
                    'author_type' => $remote['author_type'] ?? 'agency',
                    'crdate' => time(),
                    'tstamp' => time(),
                ]);

                // Update comment count on feedback record
                $feedbackConnection = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getConnectionForTable('tx_t3clickmark_domain_model_feedback');
                $feedbackConnection->executeStatement(
                    'UPDATE tx_t3clickmark_domain_model_feedback SET comments = comments + 1 WHERE uid = ?',
                    [$localFeedbackUid]
                );
            }
        } catch (\Throwable $e) {
            // Don't break sync if comment fetch fails
        }
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
