<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:t3pinpoint/Resources/Private/Language/locallang_db.xlf:tx_t3pinpoint_domain_model_feedback',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'searchFields' => 'title,description,backend_username,page_url',
        'iconIdentifier' => 't3pinpoint-module-feedback',
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    title, description, status, priority, category,
                --div--;Feedback Context,
                    page_uid, content_uid, content_type, page_url, backend_edit_link,
                --div--;Browser Info,
                    browser_info, viewport, css_selector,
                --div--;Screenshots,
                    screenshot, annotated_screenshot,
                --div--;User,
                    backend_user, backend_username,
                --div--;External Sync,
                    external_id, external_url, synced_at,
                --div--;Comments,
                    comments,
            ',
        ],
    ],
    'columns' => [
        'hidden' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.visible',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'description' => [
            'label' => 'Description',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 5,
            ],
        ],
        'status' => [
            'label' => 'Status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Open', 'value' => 'open'],
                    ['label' => 'In Progress', 'value' => 'in_progress'],
                    ['label' => 'Resolved', 'value' => 'resolved'],
                    ['label' => 'Closed', 'value' => 'closed'],
                ],
                'default' => 'open',
            ],
        ],
        'priority' => [
            'label' => 'Priority',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Low', 'value' => 'low'],
                    ['label' => 'Medium', 'value' => 'medium'],
                    ['label' => 'High', 'value' => 'high'],
                ],
                'default' => 'medium',
            ],
        ],
        'category' => [
            'label' => 'Category',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Change Request', 'value' => 'change_request'],
                    ['label' => 'Bug', 'value' => 'bug'],
                    ['label' => 'Question', 'value' => 'question'],
                ],
                'default' => 'change_request',
            ],
        ],
        'page_uid' => [
            'label' => 'Page UID',
            'config' => [
                'type' => 'number',
                'size' => 10,
            ],
        ],
        'content_uid' => [
            'label' => 'Content Element UID',
            'config' => [
                'type' => 'number',
                'size' => 10,
            ],
        ],
        'content_type' => [
            'label' => 'Content Type',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 100,
                'readOnly' => true,
            ],
        ],
        'page_url' => [
            'label' => 'Page URL',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'readOnly' => true,
            ],
        ],
        'backend_edit_link' => [
            'label' => 'Backend Edit Link',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'readOnly' => true,
            ],
        ],
        'browser_info' => [
            'label' => 'Browser Info',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'readOnly' => true,
            ],
        ],
        'viewport' => [
            'label' => 'Viewport',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 50,
                'readOnly' => true,
            ],
        ],
        'css_selector' => [
            'label' => 'CSS Selector',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 1024,
                'readOnly' => true,
            ],
        ],
        'screenshot' => [
            'label' => 'Screenshot',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
                'allowed' => 'png,jpg,jpeg,webp',
            ],
        ],
        'annotated_screenshot' => [
            'label' => 'Annotated Screenshot',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
                'allowed' => 'png,jpg,jpeg,webp',
            ],
        ],
        'backend_user' => [
            'label' => 'Backend User ID',
            'config' => [
                'type' => 'number',
                'size' => 10,
                'readOnly' => true,
            ],
        ],
        'backend_username' => [
            'label' => 'Backend Username',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'readOnly' => true,
            ],
        ],
        'external_id' => [
            'label' => 'External ID (e.g. Jira Key)',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
            ],
        ],
        'external_url' => [
            'label' => 'External URL',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
            ],
        ],
        'synced_at' => [
            'label' => 'Last Synced',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
            ],
        ],
        'comments' => [
            'label' => 'Comments',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_t3pinpoint_domain_model_feedbackcomment',
                'foreign_field' => 'feedback',
                'maxitems' => 100,
                'appearance' => [
                    'collapseAll' => true,
                    'expandSingle' => true,
                    'useSortable' => false,
                ],
            ],
        ],
    ],
];
