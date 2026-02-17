<?php

declare(strict_types=1);

use ByteBuilders\T3Pinpoint\Controller\FeedbackController;

return [
    't3pinpoint' => [
        'parent' => 'web',
        'position' => ['after' => 'list'],
        'access' => 'user',
        'labels' => 'LLL:EXT:t3pinpoint/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 't3pinpoint-module-feedback',
        'extensionName' => 'T3Pinpoint',
        'controllerActions' => [
            FeedbackController::class => [
                'list',
                'show',
                'updateStatus',
                'addComment',
            ],
        ],
    ],
];
