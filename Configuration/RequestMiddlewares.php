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
    'frontend' => [
        'nitsan/nst3af-mcp-security' => [
            'target' => \NITSAN\NsT3AF\Mcp\Middleware\McpSecurityMiddleware::class,
            'before' => [
                'nitsan/nst3af-mcp-oauth',
            ],
            'after' => [
                'typo3/cms-frontend/normalize-params',
            ],
        ],
        'nitsan/nst3af-mcp-oauth' => [
            'target' => \NITSAN\NsT3AF\Mcp\Middleware\OAuthMiddleware::class,
            'before' => [
                'nitsan/nst3af-mcp-server',
            ],
            'after' => [
                'nitsan/nst3af-mcp-security',
            ],
        ],
        'nitsan/nst3af-mcp-server' => [
            'target' => \NITSAN\NsT3AF\Mcp\Middleware\McpServerMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'after' => [
                'nitsan/nst3af-mcp-oauth',
            ],
        ],
    ],
];
