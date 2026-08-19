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
 * file that was distributed with this source code.
 */

$EM_CONF['ns_t3af'] = [
    'title' => 'MCP-first AI Foundation for TYPO3',
    'description' => 'MCP-first AI foundation for TYPO3. T3AF turns your TYPO3 installation into a fully AI-ready platform. Complete AI-infrastructure for the whole TYPO3 team, zero setup. Manage every AI provider, MCP servers & tools, brand context, prompts, users permissions, and budget in one native backend module. You stay in control, your every TYPO3 team (editors, integrators, developers & administrator) works with AI on one governed, self-hosted foundation. Use it standalone — or as the foundation that powers the complete AI Universe. Free and open source; a free licence key is required.',
    'category' => 'be',
    'author' => 'Team T3Planet',
    'author_email' => 'support@t3planet.de',
    'author_company' => 'T3Planet',
    'state' => 'stable',
    'clearCacheOnLoad' => 0,
    'version' => '1.1.3',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.99.99',
            'php' => '8.2.0-8.5.99',
            'workspaces' => '12.4.0-14.99.99',
            'scheduler' => '12.4.0-14.99.99',
            'ns_license' => '14.3.0-14.9.99',
        ],
        'conflicts' => [
            'ms_mcp_server' => '',
            'mcp_server' => '',
        ],
        'suggests' => [],
    ],
];
