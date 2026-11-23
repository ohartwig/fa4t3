<?php

defined('TYPO3') or die();

$GLOBALS['SiteConfiguration']['site']['columns']['fa4t3SiteId'] = [
    'label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3SiteId',
    'config' => [
        'type' => 'input',
        'size' => 30,
        'placeholder' => 'ABCDEFG',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['fa4t3ApiKeyOverride'] = [
    'label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3ApiKeyOverride',
    'config' => [
        'type' => 'input',
        'size' => 50,
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['fa4t3TrackingEnabled'] = [
    'label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3TrackingEnabled',
    'config' => [
        'type' => 'check',
        'default' => 0,
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['fa4t3ExcludedPages'] = [
    'label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3ExcludedPages',
    'config' => [
        'type' => 'input',
        'size' => 50,
        'placeholder' => '1,5,23',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['fa4t3ConsentCategory'] = [
    'label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3ConsentCategory',
    'config' => [
        'type' => 'input',
        'size' => 30,
    ],
];

$spaModeItems = [
    ['label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3SpaMode.none', 'value' => ''],
    ['label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3SpaMode.auto', 'value' => 'auto'],
    ['label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3SpaMode.history', 'value' => 'history'],
    ['label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3SpaMode.hash', 'value' => 'hash'],
];

$GLOBALS['SiteConfiguration']['site']['columns']['fa4t3SpaMode'] = [
    'label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3SpaMode',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'items' => $spaModeItems,
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['fa4t3HonorDnt'] = [
    'label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3HonorDnt',
    'config' => [
        'type' => 'check',
        'default' => 0,
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['fa4t3ShareUrl'] = [
    'label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3ShareUrl',
    'description' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3ShareUrl.description',
    'config' => [
        'type' => 'input',
        'size' => 80,
        'placeholder' => 'https://app.usefathom.com/share/xxxxxxxx/site-name',
    ],
];

$GLOBALS['SiteConfiguration']['site']['columns']['fa4t3SharePassword'] = [
    'label' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3SharePassword',
    'description' => 'LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3SharePassword.description',
    'config' => [
        'type' => 'input',
        'size' => 50,
    ],
];

$GLOBALS['SiteConfiguration']['site']['types']['0']['showitem'] = ($GLOBALS['SiteConfiguration']['site']['types']['0']['showitem'] ?? '') . ',
    --div--;LLL:EXT:fa4t3/Resources/Private/Language/locallang_be.xlf:siteconfig.fa4t3.tab,
    fa4t3SiteId,
    fa4t3ApiKeyOverride,
    fa4t3ShareUrl,
    fa4t3SharePassword,
    --palette--;;fa4t3Tracking';

$GLOBALS['SiteConfiguration']['site']['palettes']['fa4t3Tracking'] = [
    'label' => 'Frontend Tracking',
    'showitem' => 'fa4t3TrackingEnabled, fa4t3ExcludedPages, fa4t3ConsentCategory, fa4t3SpaMode, fa4t3HonorDnt',
];
