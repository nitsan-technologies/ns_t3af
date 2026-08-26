<?php

declare(strict_types=1);

/*
 * This file is part of the "AI Foundation for TYPO3" (ns_t3af) extension.
 *
 * (c) T3Planet / NITSAN Technologies <support@t3planet.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, either version 2 of the
 * License, or (at your option) any later version.
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code.
 */

defined('TYPO3') or die();

$ll = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:';

$involvementItems = [
    ['label' => $ll . 'ailabel.involvement.not_reviewed', 'value' => 'not_reviewed'],
    ['label' => $ll . 'ailabel.involvement.no_ai', 'value' => 'no_ai'],
    ['label' => $ll . 'ailabel.involvement.ai_generated', 'value' => 'ai_generated'],
    ['label' => $ll . 'ailabel.involvement.ai_modified', 'value' => 'ai_modified'],
    ['label' => $ll . 'ailabel.involvement.origin_unknown', 'value' => 'origin_unknown'],
    ['label' => $ll . 'ailabel.involvement.suggestion', 'value' => 'suggestion'],
];

$columns = [
    'tx_nst3af_ailabel_involvement' => [
        'exclude' => true,
        'label' => $ll . 'ailabel.involvement',
        'config' => ['type' => 'select', 'renderType' => 'selectSingle', 'items' => $involvementItems, 'default' => 'not_reviewed'],
    ],
    'tx_nst3af_ailabel_labelling_mode' => [
        'exclude' => true,
        'label' => $ll . 'ailabel.labelling_mode',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => $ll . 'ailabel.mode.automatic', 'value' => 'automatic'],
                ['label' => $ll . 'ailabel.mode.always', 'value' => 'always'],
                ['label' => $ll . 'ailabel.mode.never', 'value' => 'never'],
            ],
            'default' => 'automatic',
        ],
    ],
    'tx_nst3af_ailabel_public_interest' => [
        'exclude' => true,
        'label' => $ll . 'ailabel.public_interest',
        'config' => ['type' => 'check'],
    ],
    'tx_nst3af_ailabel_human_review' => [
        'exclude' => true,
        'label' => $ll . 'ailabel.human_review',
        'config' => ['type' => 'check'],
    ],
    'tx_nst3af_ailabel_responsible_person' => [
        'exclude' => true,
        'label' => $ll . 'ailabel.responsible_person',
        'config' => ['type' => 'input', 'size' => 40, 'max' => 255],
    ],
    'tx_nst3af_ailabel_internal_note' => [
        'exclude' => true,
        'label' => $ll . 'ailabel.internal_note',
        'config' => ['type' => 'text', 'cols' => 40, 'rows' => 3],
    ],
];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('tt_content', $columns);
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    '--div--;' . $ll . 'ailabel.tab,tx_nst3af_ailabel_involvement,tx_nst3af_ailabel_labelling_mode,tx_nst3af_ailabel_public_interest,tx_nst3af_ailabel_human_review,tx_nst3af_ailabel_responsible_person,tx_nst3af_ailabel_internal_note',
);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', $columns);
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'pages',
    '--div--;' . $ll . 'ailabel.tab,tx_nst3af_ailabel_involvement,tx_nst3af_ailabel_labelling_mode,tx_nst3af_ailabel_public_interest,tx_nst3af_ailabel_human_review,tx_nst3af_ailabel_responsible_person,tx_nst3af_ailabel_internal_note',
);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('sys_file_metadata', $columns);
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'sys_file_metadata',
    '--div--;' . $ll . 'ailabel.tab,tx_nst3af_ailabel_involvement,tx_nst3af_ailabel_labelling_mode,tx_nst3af_ailabel_public_interest,tx_nst3af_ailabel_human_review,tx_nst3af_ailabel_responsible_person,tx_nst3af_ailabel_internal_note',
);
