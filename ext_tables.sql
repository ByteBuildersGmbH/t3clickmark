CREATE TABLE tx_t3clickmark_domain_model_feedback (
    title varchar(255) DEFAULT '' NOT NULL,
    description text,
    status varchar(20) DEFAULT 'open' NOT NULL,
    priority varchar(10) DEFAULT 'medium' NOT NULL,
    category varchar(20) DEFAULT 'change_request' NOT NULL,

    -- TYPO3 content context
    page_uid int(11) DEFAULT '0' NOT NULL,
    content_uid int(11) DEFAULT '0' NOT NULL,
    content_type varchar(100) DEFAULT '' NOT NULL,
    page_url varchar(2048) DEFAULT '' NOT NULL,
    backend_edit_link varchar(2048) DEFAULT '' NOT NULL,

    -- Browser/client metadata
    browser_info text,
    viewport varchar(50) DEFAULT '' NOT NULL,
    css_selector varchar(1024) DEFAULT '' NOT NULL,

    -- Diagnostics (console & network)
    console_errors int(11) DEFAULT '0' NOT NULL,
    console_warnings int(11) DEFAULT '0' NOT NULL,
    failed_requests int(11) DEFAULT '0' NOT NULL,

    -- Screenshots (FAL references)
    annotated_screenshot int(11) unsigned DEFAULT '0',

    -- TYPO3 backend user who submitted
    backend_user int(11) DEFAULT '0' NOT NULL,
    backend_username varchar(255) DEFAULT '' NOT NULL,

    -- External sync (Jira, etc.)
    external_id varchar(255) DEFAULT '' NOT NULL,
    external_url varchar(2048) DEFAULT '' NOT NULL,
    synced_at int(11) DEFAULT '0' NOT NULL,

    -- Comment count (Extbase relation)
    comments int(11) unsigned DEFAULT '0'
);

CREATE TABLE tx_t3clickmark_domain_model_feedbackcomment (
    feedback int(11) DEFAULT '0' NOT NULL,
    comment text,
    author_name varchar(255) DEFAULT '' NOT NULL,
    author_type varchar(20) DEFAULT 'editor' NOT NULL
);
