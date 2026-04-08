# T3ClickMark — Visual Website Feedback for TYPO3

A feedback widget for TYPO3 agencies. Editors browse their site, click a content element, write feedback, and the system captures a screenshot with the TYPO3 content element UID and a direct backend edit link. The agency receives this as a structured ticket.

## Features

- **Visual feedback widget** — floating button on the frontend for authenticated backend users
- **Screenshot capture** — automatic screenshot of the selected content element with annotation tools (freehand drawing, arrows, rectangles, text)
- **TYPO3 context** — captures content element UID, page ID, content type, and generates a direct backend edit link
- **Backend module** — feedback list with filters (status, priority), detail view with screenshot lightbox, comments, status management
- **AgencyDesk integration** — optional sync to [ClickMark AgencyDesk](https://clickmark.it) for Jira, Slack, Teams, and email notifications
- **Access control** — restrict the widget to specific backend users via extension configuration
- **Dark mode** — full dark mode support in the backend module

## Requirements

- TYPO3 v12 LTS or v13 LTS
- PHP 8.1+

## Installation

### Via Composer (recommended)

```bash
composer require bytebuilders/t3clickmark
```

### Via TYPO3 Extension Manager

Download `t3clickmark.zip` and upload it in Admin Tools > Extensions.

### After installation

1. Activate the extension
2. Run Database Analyzer (Maintenance > Analyze Database)
3. Clear all caches
4. Optionally configure AgencyDesk API key in Settings > Extension Configuration > T3ClickMark

## Usage

1. Log in to the TYPO3 backend
2. Open a frontend page — the feedback widget appears as a floating button
3. Click the button, then click any content element on the page
4. A screenshot is captured automatically — annotate it with the drawing tools
5. Fill in the feedback form (title, description, priority, category) and submit
6. View submitted feedback in the backend module under Web > Feedback

## Configuration

In Admin Tools > Settings > Extension Configuration > T3ClickMark:

| Setting | Description |
|---|---|
| **API Key** | AgencyDesk API key for sync (leave empty for local-only mode) |
| **Allowed Backend Users** | Comma-separated usernames to restrict access (empty = all users) |
| **Public Widget** | Enable widget for all visitors without backend login |

## Links

- **Website**: [clickmark.it](https://clickmark.it)
- **Documentation**: [clickmark.it/docs](https://clickmark.it/docs)
- **Issues**: [GitHub Issues](https://github.com/ByteBuildersGmbH/t3clickmark/issues)

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) for details.
