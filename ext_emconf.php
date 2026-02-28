<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'T3ClickMark - Visual Website Feedback',
    'description' => 'Visual feedback widget for TYPO3 agencies. Captures screenshots, annotates content elements, and submits structured feedback tickets with full TYPO3 context.',
    'category' => 'module',
    'author' => 'ByteBuilders',
    'author_email' => 'info@bytebuilders.de',
    'state' => 'beta',
    'version' => '0.3.1',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
