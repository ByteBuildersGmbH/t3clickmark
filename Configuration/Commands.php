<?php

declare(strict_types=1);

use ByteBuilders\T3ClickMark\Command\DeliverPendingFeedbackCommand;
use ByteBuilders\T3ClickMark\Command\SyncCommand;

/**
 * Console commands. `schedulable` exposes the command to the TYPO3 Scheduler
 * ("Execute console commands"), so it can also run without a raw crontab entry.
 */
return [
    'clickmark:sync' => [
        'class' => SyncCommand::class,
        'schedulable' => true,
    ],
    // Push retry only — kept for existing scheduler tasks; clickmark:sync covers it.
    'clickmark:deliver-pending' => [
        'class' => DeliverPendingFeedbackCommand::class,
        'schedulable' => true,
    ],
];
