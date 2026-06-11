<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Configuration;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Central reader for the ClickMark access-control configuration.
 *
 * Free tier defaults:
 *   - publicWidget = false → widget is only available to authenticated
 *     backend users that have the ClickMark module permission
 *   - allowedBackendUsers = "" → all backend users with ClickMark module
 *     access can use the widget
 *
 * Pro feature: if the t3clickmark_pro extension is installed, its Extension
 * Configuration may override both values. Free users cannot change these
 * settings; the configuration fields only exist in the Pro extension.
 */
class AccessControl
{
    /**
     * Returns true when the widget should be injected for every frontend
     * visitor, regardless of backend authentication.
     *
     * Only Pro installs can enable this. Free installs always return false.
     */
    public static function isPublicWidgetEnabled(): bool
    {
        return (bool) self::readProConfig('publicWidget', false);
    }

    /**
     * Returns the comma-separated list of backend usernames that may use
     * ClickMark, exactly as configured. An empty string means "all backend
     * users with ClickMark module access".
     *
     * Only Pro installs can override this. Free installs always return "".
     */
    public static function getAllowedBackendUsers(): string
    {
        return trim((string) self::readProConfig('allowedBackendUsers', ''));
    }

    /**
     * Returns true when the given backend user may use ClickMark features
     * (backend module, widget, context menu, DocHeader button).
     *
     * Rules:
     *   - allowedBackendUsers list empty:
     *       admins → always allowed
     *       others → allowed when they have the t3clickmark module permission
     *   - allowedBackendUsers list set (Pro):
     *       strict whitelist — only users on the list, admin status is ignored
     *
     * When the whitelist is configured, admins who are not on the list lose
     * access too. This is intentional (variant "C"): once you turn on the
     * whitelist, you opt into full control. Make sure to include the admins
     * that should keep access; otherwise they can recover by clearing the
     * value in the t3clickmark_pro extension configuration via CLI or DB.
     */
    public static function hasBackendUserAccess(?BackendUserAuthentication $backendUser): bool
    {
        if ($backendUser === null) {
            return false;
        }

        $allowedUsers = self::getAllowedBackendUsers();

        if ($allowedUsers === '') {
            // No Pro whitelist — fall back to standard TYPO3 module permissions.
            // Admins keep their normal blanket access here.
            if ($backendUser->isAdmin()) {
                return true;
            }
            return (bool) $backendUser->check('modules', 't3clickmark');
        }

        // Strict whitelist — admin status is intentionally ignored.
        $allowed = GeneralUtility::trimExplode(',', $allowedUsers, true);
        return in_array($backendUser->user['username'] ?? '', $allowed, true);
    }

    /**
     * Reads a Pro extension configuration key with a fallback default.
     * Returns the default if the Pro extension is not installed or the key
     * is missing.
     *
     * @return mixed
     */
    private static function readProConfig(string $key, $default)
    {
        if (!ExtensionManagementUtility::isLoaded('t3clickmark_pro')) {
            return $default;
        }

        try {
            $config = GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('t3clickmark_pro');
            return $config[$key] ?? $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
