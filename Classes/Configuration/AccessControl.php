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
     *   - admin → always allowed
     *   - allowedBackendUsers list configured → only users in that list
     *   - list empty → users with t3clickmark module permission
     */
    public static function hasBackendUserAccess(?BackendUserAuthentication $backendUser): bool
    {
        if ($backendUser === null) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        $allowedUsers = self::getAllowedBackendUsers();

        if ($allowedUsers === '') {
            return (bool) $backendUser->check('modules', 't3clickmark');
        }

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
