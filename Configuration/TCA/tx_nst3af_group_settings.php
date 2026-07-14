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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:access.group_settings.title',
        'label' => 'be_group',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'hideTable' => true,
        'adminOnly' => true,
        'rootLevel' => 1,
        'security' => ['ignorePageTypeRestriction' => true],
    ],
    'types' => [
        '0' => ['showitem' => 'be_group, configured, limits_json'],
    ],
    'columns' => [
        'be_group' => [
            'label' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:access.group_settings.be_group',
            'config' => [
                'type' => 'number',
                'size' => 10,
            ],
        ],
        'limits_json' => [
            'label' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:access.group_settings.limits_json',
            'config' => [
                'type' => 'text',
                'cols' => 80,
                'rows' => 5,
            ],
        ],
        'configured' => [
            'label' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:access.group_settings.configured',
            'config' => [
                'type' => 'check',
            ],
        ],
    ],
];
