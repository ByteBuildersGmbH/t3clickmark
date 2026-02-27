<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:t3clickmark/Resources/Private/Language/locallang_db.xlf:tx_t3clickmark_domain_model_feedbackcomment',
        'label' => 'author_name',
        'label_alt' => 'comment',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'hideTable' => true,
        'iconIdentifier' => 't3clickmark-module-feedback',
    ],
    'types' => [
        '1' => [
            'showitem' => 'comment, author_name, author_type',
        ],
    ],
    'columns' => [
        'feedback' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'comment' => [
            'label' => 'Comment',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 4,
                'required' => true,
            ],
        ],
        'author_name' => [
            'label' => 'Author',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
            ],
        ],
        'author_type' => [
            'label' => 'Author Type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Editor', 'value' => 'editor'],
                    ['label' => 'Agency', 'value' => 'agency'],
                ],
                'default' => 'editor',
            ],
        ],
    ],
];
