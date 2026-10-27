<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Configuration;

/**
 * Central reader for the ClickMark platform endpoint.
 *
 * The endpoint is fixed to the public ClickMark AgencyDesk URL
 * (https://app.clickmark.it/api/v1) and is not configurable.
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
}
