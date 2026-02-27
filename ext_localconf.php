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

// Register eID handler for feedback submission from the widget
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['t3clickmark_feedback']
    = \ByteBuilders\T3ClickMark\Controller\FeedbackApiController::class . '::submitAction';

// Register icons
$iconRegistry = GeneralUtility::makeInstance(IconRegistry::class);
$iconRegistry->registerIcon(
    't3clickmark-module-feedback',
    SvgIconProvider::class,
    ['source' => 'EXT:t3clickmark/Resources/Public/Icons/Extension.svg']
);
