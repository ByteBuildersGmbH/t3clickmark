<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Service;

use ByteBuilders\T3ClickMark\Configuration\PlatformEndpoint;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves which paid platform features this instance's organisation is
 * entitled to (GET /status → premium_t3_ext).
 *
 * The platform plan is the paywall — the server enforces video uploads; the
 * extension only mirrors the entitlement in the UI (hide the video option,
 * gate the public widget). The flag is cached in the TYPO3 Registry so
 * frontend page views never wait on a platform request: it refreshes at most
 * every 6 hours (5-minute backoff after a failed attempt) and is additionally
 * warmed by every backend-module status fetch.
 */
class PlatformFeatureService
{
    private const REGISTRY_NAMESPACE = 'tx_t3clickmark';
    private const KEY_PREMIUM = 'premium_t3_ext';
    private const KEY_CHECKED_AT = 'premium_checked_at';
    private const KEY_RETRY_AFTER = 'premium_retry_after';
    private const CACHE_TTL = 21600;
    private const RETRY_INTERVAL = 300;

    public function isPremiumUnlocked(): bool
    {
        $registry = $this->getRegistry();
        $now = time();
        $checkedAt = (int)$registry->get(self::REGISTRY_NAMESPACE, self::KEY_CHECKED_AT, 0);
        if (
            $now - $checkedAt >= self::CACHE_TTL
            && $now >= (int)$registry->get(self::REGISTRY_NAMESPACE, self::KEY_RETRY_AFTER, 0)
        ) {
            $this->refreshFromPlatform();
        }

        return (bool)$registry->get(self::REGISTRY_NAMESPACE, self::KEY_PREMIUM, false);
    }

    /**
     * Store the flag from an already-fetched /status response — keeps the
     * cache warm from the backend module without an extra request.
     */
    public function updateFromStatusResponse(array $status): void
    {
        if (!array_key_exists('premium_t3_ext', $status)) {
            return;
        }
        $registry = $this->getRegistry();
        $registry->set(self::REGISTRY_NAMESPACE, self::KEY_PREMIUM, (bool)$status['premium_t3_ext']);
        $registry->set(self::REGISTRY_NAMESPACE, self::KEY_CHECKED_AT, time());
    }

    private function refreshFromPlatform(): void
    {
        $apiKey = GeneralUtility::makeInstance(ClickMarkConnectionService::class)->getApiKey();
        if ($apiKey === '') {
            return; // not connected — the flag stays at its default (false)
        }

        try {
            $response = GeneralUtility::makeInstance(RequestFactory::class)->request(
                PlatformEndpoint::getApiEndpoint() . '/status',
                'GET',
                [
                    'headers' => ['X-API-Key' => $apiKey, 'Accept' => 'application/json'],
                    'timeout' => 3,
                ]
            );
            if ($response->getStatusCode() === 200) {
                $status = json_decode((string)$response->getBody(), true);
                if (is_array($status)) {
                    $this->updateFromStatusResponse($status);
                    return;
                }
            }
        } catch (\Throwable $e) {
            // fall through to the retry backoff
        }

        $this->getRegistry()->set(self::REGISTRY_NAMESPACE, self::KEY_RETRY_AFTER, time() + self::RETRY_INTERVAL);
    }

    private function getRegistry(): Registry
    {
        return GeneralUtility::makeInstance(Registry::class);
    }
}
