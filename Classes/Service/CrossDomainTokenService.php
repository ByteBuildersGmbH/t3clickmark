<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Service;

/**
 * Stateless HMAC token utility for cross-domain widget activation.
 *
 * Token format: base64(userId:timestamp).hmac
 * Cookie format: userId.hmac
 */
class CrossDomainTokenService
{
    /**
     * Generate a short-lived activation token for cross-domain widget access.
     * Token is valid for 60 seconds and bound to the target domain.
     */
    public function generateToken(int $userId, string $targetDomain): string
    {
        $timestamp = time();
        $encryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        $data = $userId . ':' . $timestamp;
        $normalizedDomain = $this->normalizeDomain($targetDomain);
        $payload = $userId . '|' . $timestamp . '|' . $normalizedDomain;
        $hmac = hash_hmac('sha256', $payload, $encryptionKey);

        return base64_encode($data) . '.' . $hmac;
    }

    /**
     * Validate an activation token. Returns the user ID if valid, null otherwise.
     */
    public function validateToken(string $token, string $currentDomain): ?int
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $decoded = base64_decode($parts[0], true);
        if ($decoded === false) {
            return null;
        }

        $dataParts = explode(':', $decoded, 2);
        if (count($dataParts) !== 2) {
            return null;
        }

        $userId = (int)$dataParts[0];
        $timestamp = (int)$dataParts[1];
        $providedHmac = $parts[1];

        // Token must be less than 60 seconds old
        if (abs(time() - $timestamp) > 60) {
            return null;
        }

        $encryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        $normalizedDomain = $this->normalizeDomain($currentDomain);
        $payload = $userId . '|' . $timestamp . '|' . $normalizedDomain;
        $expectedHmac = hash_hmac('sha256', $payload, $encryptionKey);

        if (!hash_equals($expectedHmac, $providedHmac)) {
            return null;
        }

        return $userId;
    }

    /**
     * Generate a session cookie value tied to the user ID and current date.
     */
    public function generateSessionCookie(int $userId): string
    {
        $encryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        $payload = $userId . '|' . date('Y-m-d');
        $hmac = hash_hmac('sha256', $payload, $encryptionKey);

        return $userId . '.' . $hmac;
    }

    /**
     * Validate a session cookie. Returns the user ID if valid, null otherwise.
     * Accepts today's and yesterday's date to handle sessions spanning midnight.
     */
    public function validateSessionCookie(string $cookie): ?int
    {
        $parts = explode('.', $cookie, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $userId = (int)$parts[0];
        $providedHmac = $parts[1];

        if ($userId <= 0) {
            return null;
        }

        $encryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';

        // Check today's date
        $payload = $userId . '|' . date('Y-m-d');
        $expectedHmac = hash_hmac('sha256', $payload, $encryptionKey);
        if (hash_equals($expectedHmac, $providedHmac)) {
            return $userId;
        }

        // Check yesterday's date (session spanning midnight)
        $payload = $userId . '|' . date('Y-m-d', strtotime('-1 day'));
        $expectedHmac = hash_hmac('sha256', $payload, $encryptionKey);
        if (hash_equals($expectedHmac, $providedHmac)) {
            return $userId;
        }

        return null;
    }

    /**
     * Normalize domain for consistent HMAC comparison.
     * Strips www. prefix and lowercases to avoid mismatches from redirects.
     */
    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }
        return $domain;
    }
}
