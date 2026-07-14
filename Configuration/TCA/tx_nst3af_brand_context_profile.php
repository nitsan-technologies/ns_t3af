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
        'title' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:brand_context.table.title',
        'label' => 'brand_name',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'rootLevel' => -1,
        'adminOnly' => true,
        'hideTable' => true,
        'security' => ['ignorePageTypeRestriction' => true],
    ],
    'types' => [
        '0' => [
            'showitem' => 'brand_name, industry, website_url, tagline, description, is_default',
        ],
    ],
    'columns' => [
        'brand_name' => [
            'label' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:brand_context.brand_name',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 60,
                'eval' => 'trim,required',
            ],
        ],
        'industry' => [
            'label' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:brand_context.industry',
            'config' => [
                'type' => 'input',
                'size' => 30,
            ],
        ],
        'website_url' => [
            'label' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:brand_context.website_url',
            'config' => [
                'type' => 'input',
                'size' => 50,
            ],
        ],
        'tagline' => [
            'label' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:brand_context.tagline',
            'config' => [
                'type' => 'input',
                'size' => 50,
            ],
        ],
        'description' => [
            'label' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:brand_context.description',
            'config' => [
                'type' => 'text',
                'rows' => 3,
            ],
        ],
        'is_default' => [
            'label' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:brand_context.is_default',
            'config' => [
                'type' => 'check',
            ],
        ],
    ],
];
