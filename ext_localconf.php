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

defined('TYPO3_MODE') || defined('TYPO3') || die();

// Response + provider-models caches consumed by AiService.
// Configuration/Caches.php is the canonical declaration; the loop below keeps
// the entries available even on hosts that load ext_localconf before the
// Caches.php file (older v13 patch levels).
foreach ([
    'nst3af_responses' => 3600,
    'nst3af_provider_models' => 86400,
    'nst3af_credits_token' => 3600,
    'nst3af_credits_api' => 3600,
    'nst3af_mcp_oauth' => 300,
    'nst3af_api_alert' => 3600,
    'nst3af_dashboard_analytics' => 900,
] as $cacheId => $defaultLifetime) {
    if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$cacheId] ?? null)) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$cacheId] = [
            'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
            'options' => ['defaultLifetime' => $defaultLifetime],
            'groups' => ['system', 'nst3af'],
        ];
    }
}

// Governance: per-capability backend permissions. Assign to be_groups under
// "Access Lists → Custom module options" to gate which AI capabilities a group
// may invoke. When no nst3af:* option is granted to any group, the
// AccessControlListener stays permissive (no capability gating).
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['nst3af'] = [
    'header' => 'AI Foundation capabilities',
    'items' => [
        'capability_chat' => ['Chat / completion', 'actions-message'],
        'capability_streaming' => ['Streaming responses', 'actions-system-extension-import'],
        'capability_embeddings' => ['Embeddings', 'actions-database'],
        'capability_vision' => ['Vision', 'actions-image'],
        'capability_tool_use' => ['Tool / function calling', 'actions-cog'],
        'capability_completion' => ['Raw completion', 'actions-bolt'],
        'capability_tts' => ['Text-to-speech', 'actions-volume-up'],
        'capability_image_generation' => ['Image generation', 'actions-image'],
    ],
];

$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['nst3af_tab'] = [
    'header' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:access.tab.header',
    'items' => [
        'providers' => ['AI Providers', 'actions-system-extension-configure'],
        'ai_context' => ['AI Context', 'actions-lightbulb'],
        'mcp_server' => ['MCP Server', 'actions-link'],
        'mcp_tools' => ['MCP Tools', 'actions-system-extension-install'],
        'ai_features' => ['AI Features', 'actions-star'],
        'ai_usage' => ['AI Usage', 'actions-document-info'],
        'ai_prompts' => ['AI Prompts', 'actions-message'],
        'ai_logs' => ['AI Logs', 'actions-notebook'],
        'scheduler_cli' => ['Scheduler & CLI', 'actions-refresh'],
    ],
];

$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['T3Ai'] = [
    'header' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:access.t3ai.header',
    // Items are filled from AiAccessCatalogProviderInterface via AiAccessCustomOptionsBootstrap
    // on BootCompletedEvent (child extensions own their feature bits).
    'items' => [],
];

// Classic-mode: load the bundled t3af.phar so Symfony AI bridges become
// discoverable. No-op in Composer mode.
\NITSAN\NsT3AF\Bootstrap\T3afPharBootstrap::register();
