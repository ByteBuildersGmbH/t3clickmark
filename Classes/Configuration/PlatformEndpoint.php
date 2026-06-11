<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Central reader for the ClickMark platform endpoint configuration.
 *
 * Default: the public ClickMark AgencyDesk URL (https://app.clickmark.it/api/v1).
 *
 * Pro feature: if the t3clickmark_pro extension is installed AND has an
 * "apiEndpoint" override configured in its Extension Configuration, that URL
 * is used instead. This allows Pro users to point at self-hosted or staging
 * instances; the free tier always uses the public endpoint.
 *
 * Used by FeedbackApiController (forwarding new feedback),
 * AgencyDeskForwardingService (comments, status), AgencyDeskSyncService
 * (pulling updates) and OAuthCallbackController.
 */
class PlatformEndpoint
{
    private const DEFAULT_API_ENDPOINT = 'https://app.clickmark.it/api/v1';

    /**
     * Returns the full API endpoint URL including the /api/v1 path,
     * e.g. "https://app.clickmark.it/api/v1".
     *
     * Always returned without a trailing slash.
     */
    public static function getApiEndpoint(): string
    {
        if (ExtensionManagementUtility::isLoaded('t3clickmark_pro')) {
            try {
                $endpoint = trim((string)GeneralUtility::makeInstance(ExtensionConfiguration::class)
                    ->get('t3clickmark_pro', 'apiEndpoint'));
                if ($endpoint !== '') {
                    return rtrim($endpoint, '/');
                }
            } catch (\Throwable $e) {
                // fall through to default
            }
        }

        return self::DEFAULT_API_ENDPOINT;
    }

    /**
     * Returns the platform base URL without the /api/v1 path,
     * e.g. "https://app.clickmark.it" — used for OAuth flows.
     */
    public static function getPlatformUrl(): string
    {
        $apiEndpoint = self::getApiEndpoint();
        $parsed = parse_url($apiEndpoint);

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? 'app.clickmark.it';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    /**
     * Returns the host portion of the configured endpoint
     * (used by the Pro widget middleware to patch the page source).
     */
    public static function getApiHost(): string
    {
        $parsed = parse_url(self::getApiEndpoint());
        return $parsed['host'] ?? 'app.clickmark.it';
    }
}
