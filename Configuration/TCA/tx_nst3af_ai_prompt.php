<?php

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
        'title' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod.xlf:module.aiPrompts.storageTable',
        'label' => 'prompt_title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'hideTable' => true,
        'searchFields' => 'extension_key,category_id,scope,prompt_type,prompt_title,prompt_text',
        'iconfile' => 'EXT:ns_t3af/Resources/Public/Icons/Extension.svg',
    ],
    'types' => [
        '1' => [
            'showitem' => 'extension_key,category_id,prompt_kind,scope,prompt_type,prompt_title,is_default,prompt_text',
        ],
    ],
    'columns' => [
        'extension_key' => [
            'label' => 'Extension key',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'category_id' => [
            'label' => 'Category',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'prompt_kind' => [
            'label' => 'Kind',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'scope' => [
            'label' => 'Scope',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
            ],
        ],
        'prompt_type' => [
            'label' => 'Prompt type',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
            ],
        ],
        'is_default' => [
            'label' => 'Default',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'prompt_title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'prompt_text' => [
            'label' => 'Prompt text',
            'config' => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 5,
                'eval' => 'trim',
            ],
        ],
    ],
];
