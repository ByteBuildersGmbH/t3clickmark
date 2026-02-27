<?php

declare(strict_types=1);

use ByteBuilders\T3ClickMark\Controller\FeedbackController;

return [
    't3clickmark' => [
        'parent' => 'web',
        'position' => ['after' => 'list'],
        'access' => 'user',
        'labels' => 'LLL:EXT:t3clickmark/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 't3clickmark-module-feedback',
        'extensionName' => 'T3ClickMark',
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
