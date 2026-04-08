<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

defined('TYPO3') or die();

// Include TypoScript setup
ExtensionManagementUtility::addTypoScriptSetup(
    '@import "EXT:t3clickmark/Configuration/TypoScript/setup.typoscript"'
);

ExtensionManagementUtility::addTypoScriptConstants(
    '@import "EXT:t3clickmark/Configuration/TypoScript/constants.typoscript"'
);

// Exclude cross-domain activation token from cHash validation
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 't3cm_activate';

// Exclude test mode URL parameters from cHash validation
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'clickmark-test';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'browser';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'version';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'scrollY';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'elements';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'selectors';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'globalElements';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'captureTypes';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'contentSelector';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 't';

// Register eID handler for feedback submission from the widget
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['t3clickmark_feedback']
    = \ByteBuilders\T3ClickMark\Controller\FeedbackApiController::class . '::submitAction';

// Register eID handler for OAuth callback from ClickMark AgencyDesk
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['t3clickmark_oauth_callback']
    = \ByteBuilders\T3ClickMark\Controller\OAuthCallbackController::class . '::handleCallback';

// Allow feedback records on standard pages (needed for DocHeader button creating records on page context)
// allowTableOnStandardPages was removed in v13 — use direct PAGES_TYPES config instead
$GLOBALS['PAGES_TYPES']['default']['allowedTables'] ??= '';
if (!str_contains($GLOBALS['PAGES_TYPES']['default']['allowedTables'], 'tx_t3clickmark_domain_model_feedback')) {
    $GLOBALS['PAGES_TYPES']['default']['allowedTables'] .= ',tx_t3clickmark_domain_model_feedback';
}

// DataHandler hook: forward BE-created feedback to AgencyDesk
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['t3clickmark']
    = \ByteBuilders\T3ClickMark\Hook\DataHandlerHook::class;

// Register icons
$iconRegistry = GeneralUtility::makeInstance(IconRegistry::class);
$iconRegistry->registerIcon(
    't3clickmark-module-feedback',
    SvgIconProvider::class,
    ['source' => 'EXT:t3clickmark/Resources/Public/Icons/Extension.svg']
);
