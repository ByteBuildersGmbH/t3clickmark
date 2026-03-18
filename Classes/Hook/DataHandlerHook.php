<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Hook;

use ByteBuilders\T3ClickMark\Service\AgencyDeskForwardingService;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DataHandler hook that forwards newly created feedback records to AgencyDesk.
 * This catches feedback created via TYPO3's native record_edit (DocHeader button).
 */
class DataHandlerHook
{
    /**
     * Called after a record has been inserted or updated in the database.
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        $id,
        array $fieldArray,
        DataHandler $dataHandler
    ): void {
        if ($table !== 'tx_t3clickmark_domain_model_feedback' || $status !== 'new') {
            return;
        }

        // Resolve the actual UID (DataHandler uses temporary IDs like "NEW64abc..." for new records)
        $feedbackUid = $dataHandler->substNEWwithIDs[$id] ?? 0;
        if ($feedbackUid <= 0) {
            return;
        }

        try {
            GeneralUtility::makeInstance(AgencyDeskForwardingService::class)->forwardFeedback((int)$feedbackUid);
        } catch (\Throwable $e) {
            // Don't break the save process if forwarding fails
        }
    }
}
