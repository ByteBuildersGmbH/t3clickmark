<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Controller;

use ByteBuilders\T3ClickMark\Configuration\AccessControl;
use ByteBuilders\T3ClickMark\Configuration\PlatformEndpoint;
use TYPO3\CMS\Core\Http\RequestFactory;
use ByteBuilders\T3ClickMark\Domain\Model\Feedback;
use ByteBuilders\T3ClickMark\Domain\Model\FeedbackComment;
use ByteBuilders\T3ClickMark\Domain\Repository\FeedbackRepository;
use ByteBuilders\T3ClickMark\Service\AgencyDeskForwardingService;
use ByteBuilders\T3ClickMark\Service\AgencyDeskSyncService;
use ByteBuilders\T3ClickMark\Service\ClickMarkConnectionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

#[AsController]
class FeedbackController extends ActionController
{
    public function __construct(
        protected readonly FeedbackRepository $feedbackRepository,
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly PersistenceManager $persistenceManager,
    ) {
    }

    /**
     * Authorize every backend module request: deny access when the
     * current backend user is not on the allowedBackendUsers list (Pro)
     * or lacks the t3clickmark module permission (free default).
     *
     * Without this check the module would be reachable for every
     * authenticated backend user, because TYPO3's "access => user"
     * setting in Configuration/Backend/Modules.php cannot read our
     * custom Pro-gated configuration.
     */
    protected function initializeAction(): void
    {
        parent::initializeAction();

        if (!AccessControl::hasBackendUserAccess($GLOBALS['BE_USER'] ?? null)) {
            throw new \TYPO3\CMS\Core\Exception(
                'Access denied: this backend user is not allowed to use ClickMark.',
                1749635000
            );
        }
    }

    public function listAction(
        string $status = '',
        string $priority = ''
    ): ResponseInterface {
        // Sync statuses from AgencyDesk (rate-limited to once per minute)
        try {
            GeneralUtility::makeInstance(AgencyDeskSyncService::class)->syncFromAgencyDesk();
        } catch (\Throwable $e) {
            // Don't break module if sync fails
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $feedbacks = $this->feedbackRepository->findFiltered($status, $priority);

        // Connection state for the ConnectionBanner partial
        $connectionService = GeneralUtility::makeInstance(ClickMarkConnectionService::class);
        $isConnected = $connectionService->isConnected();
        $callbackUrl = GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . '?eID=t3clickmark_oauth_callback';

        // Setup status from the platform (only when connected): drives the
        // "finish setting up your account" nudge. Fail-soft: null = unknown.
        $setupStatus = $isConnected ? $this->fetchPlatformStatus($connectionService->getApiKey()) : null;

        // Outbox: how many feedback items failed to reach the platform?
        $pendingDeliveryCount = $this->countFailedDeliveries();

        $moduleTemplate->assignMultiple([
            'pendingDeliveryCount' => $pendingDeliveryCount,
            'feedbacks' => $feedbacks,
            'totalCount' => $feedbacks->count(),
            'statusCounts' => $this->feedbackRepository->countByStatus(),
            'currentStatus' => $status,
            'currentPriority' => $priority,
            'statusOptions' => ['' => 'All Statuses', 'open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'],
            'priorityOptions' => ['' => 'All Priorities', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],
            'isConnected' => $isConnected,
            'dashboardUrl' => $connectionService->getDashboardUrl(),
            'callbackUrl' => $callbackUrl,
            'platformUrl' => PlatformEndpoint::getPlatformUrl(),
            // Setup-status nudge (null when unknown / not connected)
            'statusKnown' => is_array($setupStatus),
            'setupStatus' => $setupStatus,
            'setupComplete' => is_array($setupStatus) ? (bool)($setupStatus['setup_complete'] ?? false) : null,
            'hasConnector' => is_array($setupStatus) ? (bool)($setupStatus['has_connector'] ?? false) : null,
            'statusPlan' => is_array($setupStatus) ? (string)($setupStatus['plan'] ?? '') : '',
            'trialDaysRemaining' => is_array($setupStatus) ? ($setupStatus['trial_days_remaining'] ?? null) : null,
        ]);

        return $moduleTemplate->renderResponse('Feedback/List');
    }

    /**
     * Manually re-deliver feedback that failed to reach the platform.
     * Backstop for installations without a working scheduler/cron.
     */
    public function retryDeliveryAction(): ResponseInterface
    {
        $forwarding = GeneralUtility::makeInstance(AgencyDeskForwardingService::class);
        $uids = $this->findFailedDeliveryUids();

        $delivered = 0;
        foreach ($uids as $uid) {
            $result = $forwarding->forwardFeedback((int)$uid);
            if (is_array($result) && !empty($result['forwarded'])) {
                $delivered++;
            }
        }

        $total = count($uids);
        $this->addFlashMessage(
            sprintf('%d of %d pending feedback item(s) delivered to ClickMark.', $delivered, $total),
            'ClickMark delivery',
            $delivered === $total
                ? \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::OK
                : \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::WARNING
        );

        return $this->redirect('list');
    }

    /**
     * Fetch the account setup status from the platform (API-key auth). Fail-soft:
     * returns null on any error so the backend module never breaks.
     */
    protected function fetchPlatformStatus(string $apiKey): ?array
    {
        if ($apiKey === '') {
            return null;
        }
        try {
            $response = GeneralUtility::makeInstance(RequestFactory::class)->request(
                PlatformEndpoint::getApiEndpoint() . '/status',
                'GET',
                [
                    'headers' => ['X-API-Key' => $apiKey, 'Accept' => 'application/json'],
                    'timeout' => 4,
                ]
            );
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $data = json_decode((string)$response->getBody(), true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Count feedback that failed to reach the platform (outbox).
     */
    protected function countFailedDeliveries(): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_t3clickmark_domain_model_feedback');

        return (int)$qb->count('uid')
            ->from('tx_t3clickmark_domain_model_feedback')
            ->where(
                $qb->expr()->eq('forward_status', $qb->createNamedParameter('failed')),
                $qb->expr()->eq('external_id', $qb->createNamedParameter(''))
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return int[] uids of feedback that failed to reach the platform
     */
    protected function findFailedDeliveryUids(): array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_t3clickmark_domain_model_feedback');

        return array_map('intval', $qb->select('uid')
            ->from('tx_t3clickmark_domain_model_feedback')
            ->where(
                $qb->expr()->eq('forward_status', $qb->createNamedParameter('failed')),
                $qb->expr()->eq('external_id', $qb->createNamedParameter(''))
            )
            ->executeQuery()
            ->fetchFirstColumn());
    }

    public function showAction(int $feedback, string $highlight = ''): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $feedbackObj = $this->feedbackRepository->findByUid($feedback);

        // Resolve FAL file references manually — Extbase's DataMapper
        // doesn't reliably resolve type=file TCA fields to ?FileReference.
        $annotatedFile = $this->resolveFileReference($feedback, 'annotated_screenshot');
        $attachmentFiles = $this->resolveFileReferences($feedback, 'attachments');
        $videoFile = $this->resolveFileReference($feedback, 'video_recording');

        $moduleTemplate->assignMultiple([
            'feedback' => $feedbackObj,
            'annotatedFile' => $annotatedFile,
            'attachmentFiles' => $attachmentFiles,
            'videoFile' => $videoFile,
            'highlightLatest' => ($highlight === 'latest'),
        ]);

        return $moduleTemplate->renderResponse('Feedback/Show');
    }

    /**
     * Show the "Create Feedback" form.
     * When called from the DocHeader button, element context is pre-filled via query params.
     */
    public function createAction(
        int $contentUid = 0,
        int $pageUid = 0,
        string $contentType = '',
        string $backendEditLink = ''
    ): ResponseInterface {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assignMultiple([
            'contentUid' => $contentUid,
            'pageUid' => $pageUid,
            'contentType' => $contentType,
            'backendEditLink' => $backendEditLink,
            'hasElementContext' => ($contentUid > 0),
            'statusOptions' => ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'],
            'priorityOptions' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],
            'categoryOptions' => ['change_request' => 'Change Request', 'bug' => 'Bug', 'question' => 'Question'],
        ]);

        return $moduleTemplate->renderResponse('Feedback/Create');
    }

    /**
     * Store a new feedback record created from the backend form.
     */
    public function storeAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $title = trim($body['title'] ?? '');
        if ($title === '') {
            return $this->redirect('create');
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_t3clickmark_domain_model_feedback');

        $record = [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'title' => $title,
            'description' => trim($body['description'] ?? ''),
            'status' => $body['status'] ?? 'open',
            'priority' => $body['priority'] ?? 'medium',
            'category' => $body['category'] ?? 'change_request',
            'page_uid' => (int)($body['pageUid'] ?? 0),
            'content_uid' => (int)($body['contentUid'] ?? 0),
            'content_type' => $body['contentType'] ?? '',
            'page_url' => $body['pageUrl'] ?? '',
            'backend_edit_link' => $body['backendEditLink'] ?? '',
            'browser_info' => '',
            'viewport' => '',
            'css_selector' => '',
            'console_errors' => 0,
            'console_warnings' => 0,
            'failed_requests' => 0,
            'backend_user' => (int)($GLOBALS['BE_USER']->user['uid'] ?? 0),
            'backend_username' => $GLOBALS['BE_USER']->user['username'] ?? '',
            'annotated_screenshot' => 0,
            'attachments' => 0,
        ];

        $connection->insert('tx_t3clickmark_domain_model_feedback', $record);
        $feedbackUid = (int)$connection->lastInsertId();

        // Handle screenshot upload
        $uploadedFiles = $this->request->getUploadedFiles();
        if (!empty($uploadedFiles['screenshot']) && $uploadedFiles['screenshot']->getSize() > 0) {
            $this->storeUploadedFile($feedbackUid, $uploadedFiles['screenshot'], 'annotated_screenshot');
        }

        // Handle attachment uploads
        $attachments = $uploadedFiles['attachments'] ?? [];
        $storedCount = 0;
        foreach ($attachments as $file) {
            if ($file instanceof UploadedFileInterface && $file->getSize() > 0) {
                if ($storedCount >= 5) {
                    break;
                }
                $this->storeUploadedFile($feedbackUid, $file, 'attachments', $storedCount + 1);
                $storedCount++;
            }
        }

        // Forward to AgencyDesk API if configured
        GeneralUtility::makeInstance(AgencyDeskForwardingService::class)->forwardFeedback($feedbackUid);

        return $this->redirect('show', null, null, ['feedback' => $feedbackUid]);
    }

    /**
     * Show the "Edit Feedback" form pre-populated with existing data.
     */
    public function editAction(int $feedback): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $feedbackObj = $this->feedbackRepository->findByUid($feedback);

        if ($feedbackObj === null) {
            return $this->redirect('list');
        }

        // Resolve existing attachments for display
        $attachmentFiles = $this->resolveFileReferences($feedback, 'attachments');

        $moduleTemplate->assignMultiple([
            'feedback' => $feedbackObj,
            'attachmentFiles' => $attachmentFiles,
            'statusOptions' => ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'],
            'priorityOptions' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],
            'categoryOptions' => ['change_request' => 'Change Request', 'bug' => 'Bug', 'question' => 'Question'],
        ]);

        return $moduleTemplate->renderResponse('Feedback/Edit');
    }

    /**
     * Update an existing feedback record from the backend edit form.
     */
    public function updateAction(int $feedback): ResponseInterface
    {
        $feedbackObj = $this->feedbackRepository->findByUid($feedback);
        if ($feedbackObj === null) {
            return $this->redirect('list');
        }

        $body = $this->request->getParsedBody();

        $feedbackObj->setTitle(trim($body['title'] ?? $feedbackObj->getTitle()));
        $feedbackObj->setDescription(trim($body['description'] ?? ''));
        $feedbackObj->setStatus($body['status'] ?? $feedbackObj->getStatus());
        $feedbackObj->setPriority($body['priority'] ?? $feedbackObj->getPriority());
        $feedbackObj->setCategory($body['category'] ?? $feedbackObj->getCategory());
        $feedbackObj->setPageUrl($body['pageUrl'] ?? $feedbackObj->getPageUrl());

        $this->feedbackRepository->update($feedbackObj);
        $this->persistenceManager->persistAll();

        // Handle new attachment uploads
        $uploadedFiles = $this->request->getUploadedFiles();
        $attachments = $uploadedFiles['attachments'] ?? [];
        $existingCount = $this->countFileReferences($feedback, 'attachments');
        $storedCount = 0;
        foreach ($attachments as $file) {
            if ($file instanceof UploadedFileInterface && $file->getSize() > 0) {
                if ($existingCount + $storedCount >= 5) {
                    break;
                }
                $this->storeUploadedFile($feedback, $file, 'attachments', $existingCount + $storedCount + 1);
                $storedCount++;
            }
        }

        return $this->redirect('show', null, null, ['feedback' => $feedback]);
    }

    public function updateStatusAction(int $feedback, string $status, string $priority = ''): ResponseInterface
    {
        $feedbackObj = $this->feedbackRepository->findByUid($feedback);
        if ($feedbackObj !== null) {
            $feedbackObj->setStatus($status);
            if ($priority !== '') {
                $feedbackObj->setPriority($priority);
            }
            $this->feedbackRepository->update($feedbackObj);

            // Forward status change to AgencyDesk → Jira
            try {
                GeneralUtility::makeInstance(AgencyDeskForwardingService::class)
                    ->forwardStatusChange($feedback, $status);
            } catch (\Throwable $e) {
                // Don't break the UI
            }
        }

        return $this->redirect('show', null, null, ['feedback' => $feedback]);
    }

    public function addCommentAction(int $feedback, string $comment): ResponseInterface
    {
        $feedbackObj = $this->feedbackRepository->findByUid($feedback);
        if ($feedbackObj !== null && trim($comment) !== '') {
            $commentObj = new FeedbackComment();
            $commentObj->setComment(trim($comment));
            $commentObj->setAuthorName($GLOBALS['BE_USER']->user['username'] ?? 'Unknown');
            $commentObj->setAuthorType('agency');
            $feedbackObj->addComment($commentObj);
            $this->feedbackRepository->update($feedbackObj);
            $this->persistenceManager->persistAll();

            // Forward comment to AgencyDesk → Jira
            try {
                GeneralUtility::makeInstance(AgencyDeskForwardingService::class)
                    ->forwardComment($feedback, trim($comment), $commentObj->getAuthorName());
            } catch (\Throwable $e) {
                // Don't break the UI
            }
        }

        return $this->redirect('show', null, null, ['feedback' => $feedback, 'highlight' => 'latest']);
    }

    /**
     * Resolve a single FAL file reference for a feedback record.
     */
    private function resolveFileReference(int $feedbackUid, string $fieldName): ?\TYPO3\CMS\Core\Resource\FileReference
    {
        $refs = $this->resolveFileReferences($feedbackUid, $fieldName, 1);
        return $refs[0] ?? null;
    }

    /**
     * Resolve all FAL file references for a feedback record field.
     *
     * @return \TYPO3\CMS\Core\Resource\FileReference[]
     */
    private function resolveFileReferences(int $feedbackUid, string $fieldName, int $limit = 0): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');

        $queryBuilder->getRestrictions()->removeAll()->add(
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction::class)
        );

        $query = $queryBuilder
            ->select('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($feedbackUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tx_t3clickmark_domain_model_feedback')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($fieldName))
            )
            ->orderBy('sorting_foreign', 'ASC');

        if ($limit > 0) {
            $query->setMaxResults($limit);
        }

        $rows = $query->executeQuery()->fetchAllAssociative();

        $references = [];
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        foreach ($rows as $row) {
            try {
                $references[] = $resourceFactory->getFileReferenceObject((int)$row['uid']);
            } catch (\Exception $e) {
                // Skip broken references
            }
        }

        return $references;
    }

    /**
     * Count existing FAL file references for a feedback record field.
     */
    private function countFileReferences(int $feedbackUid, string $fieldName): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');

        $queryBuilder->getRestrictions()->removeAll()->add(
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction::class)
        );

        return (int)$queryBuilder
            ->count('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($feedbackUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tx_t3clickmark_domain_model_feedback')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($fieldName))
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Store an uploaded file via FAL and link it to a feedback record.
     */
    private function storeUploadedFile(int $feedbackUid, UploadedFileInterface $uploadedFile, string $fieldName, int $sortingForeign = 1): void
    {
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $storage = $resourceFactory->getDefaultStorage();
        if ($storage === null) {
            return;
        }

        $targetFolderPath = 'user_upload/t3clickmark/';
        if (!$storage->hasFolder($targetFolderPath)) {
            $storage->createFolder('t3clickmark', $storage->getFolder('user_upload'));
        }
        $targetFolder = $storage->getFolder($targetFolderPath);

        // Determine extension from client filename
        $clientFilename = $uploadedFile->getClientFilename() ?? 'upload.bin';
        $ext = strtolower(pathinfo($clientFilename, PATHINFO_EXTENSION)) ?: 'bin';

        // Validate allowed extensions
        $allowed = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf'];
        if (!in_array($ext, $allowed, true)) {
            return;
        }

        $filename = sprintf('feedback_%d_%s_%s.%s', $feedbackUid, $fieldName, uniqid(), $ext);

        $tempFile = GeneralUtility::tempnam('t3cm_') . '.' . $ext;
        $uploadedFile->moveTo($tempFile);

        if (!file_exists($tempFile) || filesize($tempFile) === 0) {
            return;
        }

        // Enforce max 5 MB per file
        if (filesize($tempFile) > 5 * 1024 * 1024) {
            unlink($tempFile);
            return;
        }

        $file = $storage->addFile($tempFile, $targetFolder, $filename);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference');

        $connection->insert('sys_file_reference', [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'uid_local' => $file->getUid(),
            'uid_foreign' => $feedbackUid,
            'tablenames' => 'tx_t3clickmark_domain_model_feedback',
            'fieldname' => $fieldName,
            'sorting_foreign' => $sortingForeign,
        ]);

        // Update the feedback record's file count
        $feedbackConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_t3clickmark_domain_model_feedback');
        $currentCount = $this->countFileReferences($feedbackUid, $fieldName);
        $feedbackConnection->update(
            'tx_t3clickmark_domain_model_feedback',
            [$fieldName => $currentCount],
            ['uid' => $feedbackUid]
        );
    }
}
