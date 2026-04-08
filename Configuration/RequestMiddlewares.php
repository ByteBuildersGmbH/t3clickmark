<?php

declare(strict_types=1);

$_middlewares = [
    'frontend' => [
        'bytebuilders/t3clickmark/inject-data-attributes' => [
            'target' => \ByteBuilders\T3ClickMark\Middleware\InjectDataAttributesMiddleware::class,
            'after' => [
                'typo3/cms-frontend/content-length-headers',
                'typo3/cms-frontend/backend-user-authentication',
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
            'before' => [
                'typo3/cms-core/response-propagation',
            ],
        ],
        'bytebuilders/t3clickmark/inject-widget' => [
            'target' => \ByteBuilders\T3ClickMark\Middleware\InjectWidgetMiddleware::class,
            'after' => [
                'bytebuilders/t3clickmark/inject-data-attributes',
            ],
            'before' => [
                'typo3/cms-core/response-propagation',
            ],
        ],
    ],
];

return $_middlewares;
