<?php

declare(strict_types=1);

namespace ByteBuilders\T3Pinpoint\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * eID controller that receives feedback submissions from the T3Pinpoint widget.
 * Stores feedback in the local TYPO3 database and screenshots via FAL.
 */
class FeedbackApiController
{
    public function submitAction(ServerRequestInterface $request): ResponseInterface
    {
        // Verify backend user is authenticated
        if (!isset($GLOBALS['BE_USER']) || !$GLOBALS['BE_USER']->user) {
            return new JsonResponse(['error' => 'Not authenticated'], 403);
        }

        $parsedBody = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();

        // Validate required fields
        $title = trim($parsedBody['title'] ?? '');
        if ($title === '') {
            return new JsonResponse(['error' => 'Title is required'], 400);
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_t3pinpoint_domain_model_feedback');

        // Build the feedback record
        $record = [
            'pid' => $this->getStoragePid(),
            'tstamp' => time(),
            'crdate' => time(),
            'title' => $title,
            'description' => trim($parsedBody['description'] ?? ''),
            'status' => 'open',
            'priority' => $parsedBody['priority'] ?? 'medium',
            'category' => $parsedBody['category'] ?? 'change_request',
            'page_uid' => (int)($parsedBody['pageId'] ?? 0),
            'content_uid' => (int)($parsedBody['contentElementUid'] ?? 0),
            'content_type' => $parsedBody['contentType'] ?? '',
            'page_url' => $parsedBody['pageUrl'] ?? '',
            'backend_edit_link' => $parsedBody['backendEditLink'] ?? '',
            'browser_info' => $parsedBody['browser'] ?? '',
            'viewport' => $parsedBody['viewport'] ?? '',
            'css_selector' => $parsedBody['cssSelector'] ?? '',
            'backend_user' => (int)($GLOBALS['BE_USER']->user['uid'] ?? 0),
            'backend_username' => $GLOBALS['BE_USER']->user['username'] ?? '',
            'screenshot' => 0,
            'annotated_screenshot' => 0,
        ];

        $connection->insert('tx_t3pinpoint_domain_model_feedback', $record);
        $feedbackUid = (int)$connection->lastInsertId();

        // Handle file uploads (screenshots)
        if (!empty($uploadedFiles['screenshot'])) {
            $this->storeScreenshot($feedbackUid, $uploadedFiles['screenshot'], 'screenshot');
        }
        if (!empty($uploadedFiles['annotatedScreenshot'])) {
            $this->storeScreenshot($feedbackUid, $uploadedFiles['annotatedScreenshot'], 'annotated_screenshot');
        }

        return new JsonResponse([
            'success' => true,
            'feedbackId' => $feedbackUid,
            'message' => 'Feedback submitted successfully',
        ], 201);
    }

    /**
     * Store an uploaded screenshot file via FAL and link it to the feedback record.
     */
    private function storeScreenshot(int $feedbackUid, $uploadedFile, string $fieldName): void
    {
        try {
            $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
            $storage = $resourceFactory->getDefaultStorage();
            if ($storage === null) {
                return;
            }

            // Create target folder if it doesn't exist
            $targetFolderPath = 'user_upload/t3pinpoint/';
            if (!$storage->hasFolder($targetFolderPath)) {
                $storage->createFolder('t3pinpoint', $storage->getFolder('user_upload'));
            }
            $targetFolder = $storage->getFolder($targetFolderPath);

            // Generate a unique filename
            $extension = 'png';
            $filename = sprintf('feedback_%d_%s_%s.%s', $feedbackUid, $fieldName, uniqid(), $extension);

            // Move uploaded file to FAL storage
            $tempPath = $uploadedFile->getStream()->getMetadata('uri');
            $file = $storage->addFile($tempPath, $targetFolder, $filename);

            // Create sys_file_reference
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('sys_file_reference');

            $connection->insert('sys_file_reference', [
                'pid' => $this->getStoragePid(),
                'tstamp' => time(),
                'crdate' => time(),
                'uid_local' => $file->getUid(),
                'uid_foreign' => $feedbackUid,
                'tablenames' => 'tx_t3pinpoint_domain_model_feedback',
                'fieldname' => $fieldName,
                'table_local' => 'sys_file',
            ]);

            // Update the feedback record's file count
            $feedbackConnection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tx_t3pinpoint_domain_model_feedback');
            $feedbackConnection->update(
                'tx_t3pinpoint_domain_model_feedback',
                [$fieldName => 1],
                ['uid' => $feedbackUid]
            );
        } catch (\Exception $e) {
            // Log error but don't fail the whole submission
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)
                ->getLogger(self::class)
                ->error('Failed to store screenshot: ' . $e->getMessage());
        }
    }

    /**
     * Get the storage page ID for feedback records.
     * Defaults to 0 (root page) — can be made configurable later.
     */
    private function getStoragePid(): int
    {
        return 0;
    }
}
