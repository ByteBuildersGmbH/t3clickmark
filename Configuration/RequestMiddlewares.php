<?php

declare(strict_types=1);

return [
    'frontend' => [
        'bytebuilders/t3pinpoint/inject-data-attributes' => [
            'target' => \ByteBuilders\T3Pinpoint\Middleware\InjectDataAttributesMiddleware::class,
            'after' => [
                'typo3/cms-frontend/content-length-headers',
            ],
        ],
        'bytebuilders/t3pinpoint/inject-widget' => [
            'target' => \ByteBuilders\T3Pinpoint\Middleware\InjectWidgetMiddleware::class,
            'after' => [
                'bytebuilders/t3pinpoint/inject-data-attributes',
            ],
        ],
    ],
];
