<?php

declare(strict_types=1);

use ByteBuilders\T3ClickMark\Command\DeliverPendingFeedbackCommand;

/**
 * Console commands. `schedulable` exposes the command to the TYPO3 Scheduler
 * ("Execute console commands"), so it can also run without a raw crontab entry.
 */
return [
    'clickmark:deliver-pending' => [
        'class' => DeliverPendingFeedbackCommand::class,
        'schedulable' => true,
    ],
];
