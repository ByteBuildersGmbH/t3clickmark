<?php

declare(strict_types=1);

namespace ByteBuilders\T3Pinpoint\Controller;

use ByteBuilders\T3Pinpoint\Domain\Model\Feedback;
use ByteBuilders\T3Pinpoint\Domain\Model\FeedbackComment;
use ByteBuilders\T3Pinpoint\Domain\Repository\FeedbackRepository;
use Psr\Http\Message\ResponseInterface;
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

    public function listAction(
        string $status = '',
        string $priority = ''
    ): ResponseInterface {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $feedbacks = $this->feedbackRepository->findFiltered($status, $priority);
        $moduleTemplate->assignMultiple([
            'feedbacks' => $feedbacks,
            'currentStatus' => $status,
            'currentPriority' => $priority,
            'statusOptions' => ['', 'open', 'in_progress', 'resolved', 'closed'],
            'priorityOptions' => ['', 'low', 'medium', 'high'],
        ]);

        return $moduleTemplate->renderResponse('Feedback/List');
    }

    public function showAction(int $feedback): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $feedbackObj = $this->feedbackRepository->findByUid($feedback);

        // Resolve FAL file references manually — Extbase's DataMapper
        // doesn't reliably resolve type=file TCA fields to ?FileReference.
        $annotatedFile = $this->resolveFileReference($feedback, 'annotated_screenshot');

        $moduleTemplate->assignMultiple([
            'feedback' => $feedbackObj,
            'annotatedFile' => $annotatedFile,
        ]);

        return $moduleTemplate->renderResponse('Feedback/Show');
    }

    public function updateStatusAction(int $feedback, string $status): ResponseInterface
    {
        $feedbackObj = $this->feedbackRepository->findByUid($feedback);
        if ($feedbackObj !== null) {
            $feedbackObj->setStatus($status);
            $this->feedbackRepository->update($feedbackObj);
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
        }

        return $this->redirect('show', null, null, ['feedback' => $feedback]);
    }

    /**
     * Resolve a FAL file reference for a feedback record by querying
     * sys_file_reference directly. Returns the Core FileReference or null.
     */
    private function resolveFileReference(int $feedbackUid, string $fieldName): ?\TYPO3\CMS\Core\Resource\FileReference
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');

        // Remove all automatic restrictions (workspace, hidden, etc.)
        // and only keep the deleted restriction to avoid conflicts
        $queryBuilder->getRestrictions()->removeAll()->add(
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction::class)
        );

        $row = $queryBuilder
            ->select('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($feedbackUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tx_t3pinpoint_domain_model_feedback')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($fieldName))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        try {
            $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
            return $resourceFactory->getFileReferenceObject((int)$row['uid']);
        } catch (\Exception $e) {
            return null;
        }
    }
}
