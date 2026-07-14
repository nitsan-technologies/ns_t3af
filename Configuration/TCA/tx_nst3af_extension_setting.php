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
        'title' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:extension_settings.table.title',
        'label' => 'extension_key',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'rootLevel' => -1,
        'adminOnly' => true,
        'hideTable' => true,
        'security' => ['ignorePageTypeRestriction' => true],
    ],
    'types' => [
        '0' => ['showitem' => 'extension_key, settings_json'],
    ],
    'columns' => [
        'extension_key' => [
            'label' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:extension_settings.extension_key',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
                'size' => 30,
            ],
        ],
        'settings_json' => [
            'label' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:extension_settings.settings_json',
            'config' => [
                'type' => 'text',
                'rows' => 5,
                'readOnly' => true,
            ],
        ],
    ],
];
