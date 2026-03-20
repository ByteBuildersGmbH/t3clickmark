<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Forwards feedback records to the AgencyDesk API (server-to-server).
 * Used by FeedbackApiController (widget submissions), FeedbackController (BE form),
 * and DataHandler hook (record_edit).
 */
class AgencyDeskForwardingService
{
    private const API_ENDPOINT = 'https://clickmark.bytebuilders.de/api/v1';

    /**
     * Forward a feedback record (by UID) to AgencyDesk.
     * Reads the record from the database and sends it as multipart/form-data.
     * Returns null if not configured, or an array with the result.
     */
    public function forwardFeedback(int $feedbackUid): ?array
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return null;
        }

        // Load the feedback record
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_t3clickmark_domain_model_feedback');
        $record = $connection->select(['*'], 'tx_t3clickmark_domain_model_feedback', ['uid' => $feedbackUid])->fetchAssociative();

        if ($record === false) {
            return ['forwarded' => false, 'error' => 'Feedback record not found'];
        }

        // Build the API payload from the DB record
        $fields = [
            'title' => $record['title'] ?? '',
            'description' => $record['description'] ?? '',
            'priority' => $record['priority'] ?? 'medium',
            'category' => $record['category'] ?? 'change_request',
            'contentElementUid' => (string)($record['content_uid'] ?? ''),
            'pageId' => (string)($record['page_uid'] ?? ''),
            'contentType' => $record['content_type'] ?? '',
            'backendEditLink' => $record['backend_edit_link'] ?? '',
            'pageUrl' => $record['page_url'] ?? '',
            'browser' => '',
            'os' => '',
            'viewport' => '',
            'cssSelector' => $record['css_selector'] ?? '',
            'consoleErrors' => (string)($record['console_errors'] ?? '0'),
            'consoleWarnings' => (string)($record['console_warnings'] ?? '0'),
            'failedRequests' => (string)($record['failed_requests'] ?? '0'),
            'backendUsername' => $record['backend_username'] ?? '',
            'backendUserId' => (string)($record['backend_user'] ?? '0'),
        ];

        // Parse browser_info back into browser/os if available
        $browserInfo = $record['browser_info'] ?? '';
        if ($browserInfo !== '' && str_contains($browserInfo, ' on ')) {
            [$fields['browser'], $fields['os']] = explode(' on ', $browserInfo, 2);
        }

        $result = $this->sendToApi($apiKey, $fields);

        // Store the AgencyDesk feedback ID for sync-back matching
        if (isset($result['forwarded']) && $result['forwarded'] && isset($result['response']['id'])) {
            $connection->update(
                'tx_t3clickmark_domain_model_feedback',
                ['external_id' => (string)$result['response']['id']],
                ['uid' => $feedbackUid]
            );
        }

        return $result;
    }

    /**
     * Forward raw parsed body data to AgencyDesk (used by widget eID handler).
     * Supports optional screenshot and attachment file paths.
     */
    public function forwardFromParsedBody(array $parsedBody, ?string $screenshotPath = null, array $attachmentPaths = []): ?array
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return null;
        }

        $fields = [
            'title' => $parsedBody['title'] ?? '',
            'description' => $parsedBody['description'] ?? '',
            'priority' => $parsedBody['priority'] ?? 'medium',
            'category' => $parsedBody['category'] ?? 'change_request',
            'contentElementUid' => $parsedBody['contentElementUid'] ?? '',
            'pageId' => $parsedBody['pageId'] ?? '',
            'contentType' => $parsedBody['contentType'] ?? '',
            'backendEditLink' => $parsedBody['backendEditLink'] ?? '',
            'pageUrl' => $parsedBody['pageUrl'] ?? '',
            'browser' => $parsedBody['browser'] ?? '',
            'os' => $parsedBody['os'] ?? '',
            'viewport' => $parsedBody['viewport'] ?? '',
            'cssSelector' => $parsedBody['cssSelector'] ?? '',
            'consoleErrors' => (string)($parsedBody['consoleErrors'] ?? '0'),
            'consoleWarnings' => (string)($parsedBody['consoleWarnings'] ?? '0'),
            'failedRequests' => (string)($parsedBody['failedRequests'] ?? '0'),
            'backendUsername' => $parsedBody['backendUsername'] ?? '',
            'backendUserId' => (string)($parsedBody['backendUserId'] ?? '0'),
        ];

        return $this->sendToApi($apiKey, $fields, $screenshotPath, $attachmentPaths);
    }

    /**
     * Forward a comment to AgencyDesk.
     */
    public function forwardComment(int $feedbackUid, string $comment, string $authorName): void
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return;
        }

        // Look up the AgencyDesk feedback ID (external_id)
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_t3clickmark_domain_model_feedback');
        $row = $connection->select(['external_id'], 'tx_t3clickmark_domain_model_feedback', ['uid' => $feedbackUid])->fetchAssociative();

        $externalId = $row['external_id'] ?? '';
        if ($externalId === '') {
            return;
        }

        try {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $requestFactory->request(
                rtrim(self::API_ENDPOINT, '/') . '/feedback/' . $externalId . '/comments',
                'POST',
                [
                    'headers' => [
                        'X-API-Key' => $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode([
                        'comment' => $comment,
                        'author_name' => $authorName,
                        'source' => 'typo3',
                    ]),
                ]
            );
        } catch (\Throwable $e) {
            // Don't break the comment flow if forwarding fails
        }
    }

    /**
     * Forward a status change to AgencyDesk.
     */
    public function forwardStatusChange(int $feedbackUid, string $status): void
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_t3clickmark_domain_model_feedback');
        $row = $connection->select(['external_id'], 'tx_t3clickmark_domain_model_feedback', ['uid' => $feedbackUid])->fetchAssociative();

        $externalId = $row['external_id'] ?? '';
        if ($externalId === '') {
            return;
        }

        try {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $requestFactory->request(
                rtrim(self::API_ENDPOINT, '/') . '/feedback/' . $externalId . '/status',
                'PATCH',
                [
                    'headers' => [
                        'X-API-Key' => $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode(['status' => $status]),
                ]
            );
        } catch (\Throwable $e) {
            // Don't break the status flow if forwarding fails
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

    private function sendToApi(string $apiKey, array $fields, ?string $screenshotPath = null, array $attachmentPaths = []): array
    {
        $boundary = 'T3ClickMark' . uniqid();
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
            $body .= $value . "\r\n";
        }

        if ($screenshotPath !== null && file_exists($screenshotPath)) {
            $fileContent = file_get_contents($screenshotPath);
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="annotatedScreenshot"; filename="annotated.jpg"' . "\r\n";
            $body .= 'Content-Type: image/jpeg' . "\r\n\r\n";
            $body .= $fileContent . "\r\n";
        }

        foreach ($attachmentPaths as $i => $att) {
            if (file_exists($att['path'])) {
                $fileContent = file_get_contents($att['path']);
                $mimeType = $att['ext'] === 'pdf' ? 'application/pdf' : 'image/' . ($att['ext'] === 'jpg' ? 'jpeg' : $att['ext']);
                $body .= '--' . $boundary . "\r\n";
                $body .= 'Content-Disposition: form-data; name="attachments[' . $i . ']"; filename="' . $att['name'] . '"' . "\r\n";
                $body .= 'Content-Type: ' . $mimeType . "\r\n\r\n";
                $body .= $fileContent . "\r\n";
            }
        }

        $body .= '--' . $boundary . "--\r\n";

        try {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $response = $requestFactory->request(
                rtrim(self::API_ENDPOINT, '/') . '/feedback',
                'POST',
                [
                    'headers' => [
                        'X-API-Key' => $apiKey,
                        'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                    ],
                    'body' => $body,
                ]
            );

            $statusCode = $response->getStatusCode();
            $responseBody = json_decode((string)$response->getBody(), true) ?? [];

            return [
                'forwarded' => $statusCode >= 200 && $statusCode < 300,
                'statusCode' => $statusCode,
                'response' => $responseBody,
            ];
        } catch (\Exception $e) {
            return [
                'forwarded' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
