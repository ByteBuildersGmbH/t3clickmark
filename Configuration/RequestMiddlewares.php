<?php

declare(strict_types=1);

return [
    'frontend' => [
        'bytebuilders/t3clickmark/inject-data-attributes' => [
            'target' => \ByteBuilders\T3ClickMark\Middleware\InjectDataAttributesMiddleware::class,
            'after' => [
                'typo3/cms-frontend/content-length-headers',
            ],
        ],
        'bytebuilders/t3clickmark/inject-widget' => [
            'target' => \ByteBuilders\T3ClickMark\Middleware\InjectWidgetMiddleware::class,
            'after' => [
                'bytebuilders/t3clickmark/inject-data-attributes',
            ],
        ],
    ],
];
