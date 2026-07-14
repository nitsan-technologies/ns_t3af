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

namespace NITSAN\NsT3AF\Access;

use NITSAN\NsT3AF\Access\Dto\GroupConfig;
use NITSAN\NsT3AF\Registry\AiAccessCatalogProviderRegistry;

/**
 * Module keys for wizard Step 1 and groupMods / custom_options mapping.
 */
final class ModuleAccessCatalog
{
    /** TYPO3 backend module keys required to open the AI Foundation shell (admin tabs). */
    public const SHELL_GROUP_MODS = [
        't3af',
        't3af_dashboard',
    ];

    public function __construct(
        private readonly ExtensionAvailability $extensionAvailability = new ExtensionAvailability(),
        private readonly ?AiAccessCatalogProviderRegistry $accessProviderRegistry = null,
    ) {}

    /** AI Foundation admin sub-tabs — written to custom_options nst3af_tab:{key} */
    public const ADMIN_MODULES = [
        'providers' => [
            'label' => 'AI Providers',
            'permKey' => 'providers',
            'adminOnly' => true,
        ],
        'mcpServer' => [
            'label' => 'MCP Server',
            'permKey' => 'mcp_server',
            'adminOnly' => true,
        ],
        'mcpTools' => [
            'label' => 'MCP Tools',
            'permKey' => 'mcp_tools',
            'adminOnly' => false,
        ],
        'aiFeatures' => [
            'label' => 'AI Features',
            'permKey' => 'ai_features',
            'adminOnly' => true,
            'description' => 'Shows the AI Features tab. Saving extension settings still requires Step 3 “AI Feature Settings” (Read & Write).',
        ],
        'aiUsage' => [
            'label' => 'AI Usage',
            'permKey' => 'ai_usage',
            'adminOnly' => false,
        ],
        'aiPrompts' => [
            'label' => 'AI Prompts',
            'permKey' => 'ai_prompts',
            'adminOnly' => false,
        ],
        'schedulerCli' => [
            'label' => 'Scheduler & CLI',
            'permKey' => 'scheduler_cli',
            'adminOnly' => true,
        ],
        'aiContext' => [
            'label' => 'AI Context',
            'permKey' => 'ai_context',
            'adminOnly' => true,
        ],
        'aiLogs' => [
            'label' => 'AI Logs',
            'permKey' => 'ai_logs',
            'adminOnly' => false,
        ],
    ];

    public const PERM_PREFIX_TAB = 'nst3af_tab';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function childModules(): array
    {
        if ($this->accessProviderRegistry === null) {
            return [];
        }

        $modules = [];
        foreach ($this->accessProviderRegistry->getModuleAccessByKey() as $key => $descriptor) {
            $modules[$key] = $descriptor->toArray();
        }

        return $modules;
    }

    /**
     * @return list<string>
     */
    public function childGroupMods(): array
    {
        $mods = [];
        foreach ($this->childModules() as $meta) {
            $mods[] = $meta['groupMod'];
        }

        return array_values(array_unique($mods));
    }

    /**
     * @return list<string>
     */
    public function allModuleKeys(): array
    {
        return array_merge(array_keys($this->childModules()), array_keys(self::ADMIN_MODULES));
    }

    /**
     * @return list<string>
     */
    public function enabledModuleKeys(GroupConfig $config): array
    {
        $enabled = [];
        foreach ($this->allModuleKeys() as $key) {
            if (!empty($config->modules[$key])) {
                $enabled[] = $key;
            }
        }
        return $enabled;
    }

    public function isExtensionLoaded(string $moduleKey): bool
    {
        $childModules = $this->childModules();
        if (isset($childModules[$moduleKey])) {
            return $this->extensionAvailability->isLoaded($childModules[$moduleKey]['extension']);
        }
        return true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function visibleChildModules(): array
    {
        $out = [];
        foreach ($this->childModules() as $key => $meta) {
            if ($this->extensionAvailability->isLoaded($meta['extension'])) {
                $out[$key] = $meta;
            }
        }
        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allForBootstrap(): array
    {
        $labels = [];
        foreach ($this->childModules() as $key => $meta) {
            $labels[$key] = MatrixScopeCatalog::moduleDisplayLabel($meta, $key);
        }
        foreach (self::ADMIN_MODULES as $key => $meta) {
            $labels[$key] = $meta['label'];
        }

        return [
            'child' => $this->visibleChildModules(),
            'admin' => self::ADMIN_MODULES,
            'labels' => $labels,
        ];
    }

    /**
     * @param array<string, bool> $modules
     */
    public function hasEnabledAdminModule(array $modules): bool
    {
        foreach (array_keys(self::ADMIN_MODULES) as $key) {
            if (!empty($modules[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, bool> $modules
     */
    public function hasAnyEnabledModule(array $modules): bool
    {
        $childModules = $this->childModules();
        foreach ($this->allModuleKeys() as $key) {
            if (!empty($modules[$key])) {
                if (isset($childModules[$key]) && !$this->isExtensionLoaded($key)) {
                    continue;
                }
                return true;
            }
        }

        return false;
    }
}
