<?php

declare(strict_types=1);

namespace ByteBuilders\T3ClickMark\Backend;

use ByteBuilders\T3ClickMark\Configuration\AccessControl;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Extends TYPO3's standard backend ModuleProvider with the ClickMark
 * Pro allow-list. When the Pro allowedBackendUsers list is configured,
 * the t3clickmark module is hidden from the backend menu for any user
 * who is not on the list (admins included).
 *
 * Without this override the strict whitelist (variant C) still works
 * server-side via FeedbackController::initializeAction(), but the menu
 * item stays visible — clicking it produces a 503 access denied page.
 * That is the situation users hit before this provider takes over.
 *
 * Registered as the default ModuleProvider via Configuration/Services.yaml.
 */
class AccessControlledModuleProvider extends ModuleProvider
{
    public function accessGranted(
        string $identifier,
        BackendUserAuthentication $user,
        bool $respectWorkspaceRestrictions = true
    ): bool {
        // First the standard checks (workspace, access setting, module permissions).
        if (!parent::accessGranted($identifier, $user, $respectWorkspaceRestrictions)) {
            return false;
        }

        // Then layer the Pro allow-list on top, but only for our own module.
        if ($identifier === 't3clickmark') {
            return AccessControl::hasBackendUserAccess($user);
        }

        return true;
    }
}
