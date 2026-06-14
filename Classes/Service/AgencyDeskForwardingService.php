<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Service;

use ByteBuilders\T3ClickMark\Configuration\PlatformEndpoint;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Forwards feedback records to the AgencyDesk API (server-to-server).
 * Used by FeedbackApiController (widget submissions), FeedbackController (BE form),
 * and DataHandler hook (record_edit).
 *
 * The target URL is resolved via PlatformEndpoint::getApiEndpoint() and can
 * be overridden via Extension Configuration (t3clickmark > apiEndpoint).
 */
class AgencyDeskForwardingService
{

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

        // Re-attach the annotated screenshot (resolved from FAL) so a retried
        // delivery is not degraded to a text-only ticket.
        $screenshotPath = $this->resolveScreenshotPath($feedbackUid);

        $result = $this->sendToApi($apiKey, $fields, $screenshotPath);

        $now = time();
        $attempts = (int)($record['forward_attempts'] ?? 0) + 1;
        if (isset($result['forwarded']) && $result['forwarded'] && isset($result['response']['id'])) {
            // Delivered: store the AgencyDesk feedback ID and mark as sent.
            $connection->update(
                'tx_t3clickmark_domain_model_feedback',
                [
                    'external_id' => (string)$result['response']['id'],
                    'forward_status' => 'sent',
                    'forward_attempts' => $attempts,
                    'forward_last_error' => '',
                    'forward_last_attempt' => $now,
                ],
                ['uid' => $feedbackUid]
            );
        } else {
            // Delivery failed: mark for retry (scheduler/banner pick it up).
            $error = (string)($result['error'] ?? ('HTTP ' . ($result['statusCode'] ?? '?')));
            $connection->update(
                'tx_t3clickmark_domain_model_feedback',
                [
                    'forward_status' => 'failed',
                    'forward_attempts' => $attempts,
                    'forward_last_error' => substr($error, 0, 2000),
                    'forward_last_attempt' => $now,
                ],
                ['uid' => $feedbackUid]
            );
        }

        return $result;
    }

    /**
     * Resolve the annotated screenshot of a feedback record to a local file path,
     * or null if there is none. Used so re-deliveries keep the screenshot.
     */
    private function resolveScreenshotPath(int $feedbackUid): ?string
    {
        try {
            $fileRepository = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\FileRepository::class);
            $references = $fileRepository->findByRelation(
                'tx_t3clickmark_domain_model_feedback',
                'annotated_screenshot',
                $feedbackUid
            );
            if (empty($references)) {
                return null;
            }
            $path = $references[0]->getOriginalFile()->getForLocalProcessing(false);
            return $path !== '' && file_exists($path) ? $path : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Forward raw parsed body data to AgencyDesk (used by widget eID handler).
     * Supports optional screenshot and attachment file paths.
     */
    public function forwardFromParsedBody(array $parsedBody, ?string $screenshotPath = null, array $attachmentPaths = [], ?string $videoPath = null): ?array
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

        return $this->sendToApi($apiKey, $fields, $screenshotPath, $attachmentPaths, $videoPath);
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
                PlatformEndpoint::getApiEndpoint() . '/feedback/' . $externalId . '/comments',
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
                PlatformEndpoint::getApiEndpoint() . '/feedback/' . $externalId . '/status',
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

    private function sendToApi(string $apiKey, array $fields, ?string $screenshotPath = null, array $attachmentPaths = [], ?string $videoPath = null): array
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

        if ($videoPath !== null && file_exists($videoPath)) {
            $fileContent = file_get_contents($videoPath);
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="videoRecording"; filename="recording.webm"' . "\r\n";
            $body .= 'Content-Type: video/webm' . "\r\n\r\n";
            $body .= $fileContent . "\r\n";
        }

        $body .= '--' . $boundary . "--\r\n";

        try {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $response = $requestFactory->request(
                PlatformEndpoint::getApiEndpoint() . '/feedback',
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
