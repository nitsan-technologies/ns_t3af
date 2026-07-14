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

$javascriptModules = [
    'dependencies' => ['core', 'backend', 'dashboard'],
    'imports' => [
        'chart.js' => 'EXT:dashboard/Resources/Public/JavaScript/Contrib/chartjs.js',
        '@nitsan/nst3af/disable-browser-autocomplete.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/disable-browser-autocomplete.js',
        '@nitsan/nst3af/provider-drawer.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/provider-drawer.js',
        '@nitsan/nst3af/provider-import.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/provider-import.js',
        '@nitsan/nst3af/setup-wizard.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/setup-wizard.js',
        '@nitsan/nst3af/setup-checklist.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/setup-checklist.js',
        '@nitsan/nst3af/credits-mode.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/credits-mode.js',
        '@nitsan/nst3af/module-navigation.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/module-navigation.js',
        '@nitsan/nst3af/module-page-context.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/module-page-context.js',
        '@nitsan/nst3af/period-filter.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/period-filter.js',
        '@nitsan/nst3af/dashboard-charts.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/dashboard-charts.js',
        '@nitsan/nst3af/mcp-server.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/mcp-server.js',
        '@nitsan/nst3af/mcp-tools.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/mcp-tools.js',
        '@nitsan/nst3af/ai-features.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/ai-features.js',
        '@nitsan/nst3af/ai-usage.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/ai-usage.js',
        '@nitsan/nst3af/ai-prompts.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/ai-prompts.js',
        '@nitsan/nst3af/scheduler-cli.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/scheduler-cli.js',
        '@nitsan/nst3af/ai-logs.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/ai-logs.js',
        '@nitsan/nst3af/ai-context.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/ai-context.js',
        '@nitsan/nst3af/access-roles.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/access-roles.js',
        '@nitsan/nst3af/for-developers.js' => 'EXT:ns_t3af/Resources/Public/JavaScript/for-developers.js',
    ],
];

return $javascriptModules;
