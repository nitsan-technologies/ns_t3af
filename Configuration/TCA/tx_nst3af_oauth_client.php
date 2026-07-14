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
        'title' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_db.xlf:tx_nst3af_oauth_client',
        'label' => 'client_name',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'searchFields' => 'client_id,client_name',
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => ['type' => 'check', 'renderType' => 'checkboxToggle'],
        ],
        'client_id' => [
            'label' => 'Client ID',
            'config' => ['type' => 'input', 'readOnly' => true, 'size' => 40],
        ],
        'client_name' => [
            'label' => 'Client name',
            'config' => ['type' => 'input', 'size' => 40, 'required' => true],
        ],
        'redirect_uris' => [
            'label' => 'Redirect URIs (JSON array)',
            'config' => ['type' => 'text', 'cols' => 40, 'rows' => 4],
        ],
        'be_user' => [
            'label' => 'Linked backend user',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'be_users',
                'default' => 0,
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'hidden, client_name, client_id, redirect_uris, be_user'],
    ],
];
