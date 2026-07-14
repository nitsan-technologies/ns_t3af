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

namespace NITSAN\NsT3AF\Utility;

use NITSAN\NsT3AF\Access\ModuleTabAccessService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class ModuleTabUtility
{
    /** Tabs rendered separately on the right, before Quick Setup. */
    private const UTILITY_TAB_KEYS = ['aiUsage', 'aiLogs'];

    /**
     * Tabs whose content is stored per site root page and therefore require a
     * selected page id to render. These render {@see PageSelectionRequired}
     * when no page is resolved from the request.
     */
    private const SITE_SCOPED_TAB_KEYS = ['providers', 'aiContext', 'aiFeatures', 'aiPrompts'];

    private const TABS = [
        'dashboard' => [
            'labelKey' => 'module.menu.dashboard',
            'route' => 't3af_dashboard.overview',
            'path' => '/module/t3af/dashboard/overview',
            'headingKey' => 'module.dashboard.heading',
            'introKey' => 'module.dashboard.intro',
            'icon' => 'home',
        ],
        'providers' => [
            'labelKey' => 'module.menu.providers',
            'route' => 't3af_dashboard.providers',
            'path' => '/module/t3af/dashboard/providers',
            'headingKey' => 'module.providers.heading',
            'introKey' => 'module.providers.intro',
            'icon' => 'brain',
        ],
        'aiContext' => [
            'labelKey' => 'module.menu.aiContext',
            'route' => 't3af_dashboard.ai_context',
            'path' => '/module/t3af/dashboard/ai-context',
            'headingKey' => 'module.aiContext.heading',
            'introKey' => 'module.aiContext.intro',
            'icon' => 'context',
        ],
        'mcpServer' => [
            'labelKey' => 'module.menu.mcpServer',
            'route' => 't3af_dashboard.mcp_server',
            'path' => '/module/t3af/dashboard/mcp-server',
            'headingKey' => 'module.mcpServer.heading',
            'introKey' => 'module.mcpServer.intro',
            'icon' => 'server',
        ],
        'mcpTools' => [
            'labelKey' => 'module.menu.mcpTools',
            'route' => 't3af_dashboard.mcp_tools',
            'path' => '/module/t3af/dashboard/mcp-tools',
            'headingKey' => 'module.mcpTools.heading',
            'introKey' => 'module.mcpTools.intro',
            'icon' => 'tools',
        ],
        // 'mcpConnectors' => [
        //     'labelKey' => 'module.menu.mcpConnectors',
        //     'route' => 't3af_dashboard.mcp_connectors',
        //     'path' => '/module/t3af/dashboard/mcp-connectors',
        //     'headingKey' => 'module.mcpConnectors.heading',
        //     'introKey' => 'module.mcpConnectors.intro',
        //     'icon' => 'plug',
        // ],
        'aiFeatures' => [
            'labelKey' => 'module.menu.aiFeatures',
            'route' => 't3af_dashboard.ai_features',
            'path' => '/module/t3af/dashboard/ai-features',
            'headingKey' => 'module.aiFeatures.heading',
            'introKey' => 'module.aiFeatures.intro',
            'icon' => 'sparkles',
        ],
        'aiPrompts' => [
            'labelKey' => 'module.menu.aiPrompts',
            'route' => 't3af_dashboard.ai_prompts',
            'path' => '/module/t3af/dashboard/ai-prompts',
            'headingKey' => 'module.aiPrompts.heading',
            'introKey' => 'module.aiPrompts.intro',
            'icon' => 'prompt',
        ],
        'schedulerCli' => [
            'labelKey' => 'module.menu.schedulerCli',
            'route' => 't3af_dashboard.scheduler_cli',
            'path' => '/module/t3af/dashboard/scheduler-cli',
            'headingKey' => 'module.schedulerCli.heading',
            'introKey' => 'module.schedulerCli.intro',
            'icon' => 'terminal',
        ],
        'aiAccessRoles' => [
            'labelKey' => 'module.menu.aiAccessRoles',
            'route' => 't3af_dashboard.ai_access_roles',
            'path' => '/module/t3af/dashboard/ai-access-roles',
            'headingKey' => 'module.aiAccessRoles.heading',
            'introKey' => 'module.aiAccessRoles.intro',
            'icon' => 'users',
        ],
        'forDevelopers' => [
            'labelKey' => 'module.menu.forDevelopers',
            'route' => 't3af_dashboard.for_developers',
            'path' => '/module/t3af/dashboard/for-developers',
            'headingKey' => 'module.forDevelopers.heading',
            'introKey' => 'module.forDevelopers.intro',
            'icon' => 'code',
        ],
        'aiUsage' => [
            'labelKey' => 'module.menu.aiUsage',
            'route' => 't3af_dashboard.ai_usage',
            'path' => '/module/t3af/dashboard/ai-usage',
            'headingKey' => 'module.aiUsage.heading',
            'introKey' => 'module.aiUsage.intro',
            'icon' => 'chart',
        ],
        'aiLogs' => [
            'labelKey' => 'module.menu.aiLogs',
            'route' => 't3af_dashboard.ai_logs',
            'path' => '/module/t3af/dashboard/ai-logs',
            'headingKey' => 'module.aiLogs.heading',
            'introKey' => 'module.aiLogs.intro',
            'icon' => 'log',
        ],
    ];

    public function __construct(
        private readonly ModuleTabAccessService $tabAccessService = new ModuleTabAccessService(),
    ) {}

    /**
     * @param callable(string $route): string $buildUri
     * @return array<string, array{title: string, route: string, path: string, href: string, active: bool, iconIdentifier: string}>
     */
    public function buildVisibleTabs(
        string $active,
        callable $translate,
        callable $buildUri,
        ?BackendUserAuthentication $user = null,
    ): array {
        $items = [];

        foreach (self::TABS as $key => $tab) {
            if (!$this->tabAccessService->isTabVisible($key, $user)) {
                continue;
            }
            $items[$key] = [
                'title' => (string) $translate($tab['labelKey']),
                'route' => $tab['route'],
                'path' => $tab['path'],
                'href' => (string) $buildUri($tab['route']),
                'active' => $key === $active,
                'iconIdentifier' => $this->resolveIconIdentifier($tab['icon']),
            ];
        }

        return $items;
    }

    public function isTabVisible(string $tabKey, ?BackendUserAuthentication $user): bool
    {
        return $this->tabAccessService->isTabVisible($tabKey, $user);
    }

    /**
     * Whether the tab stores its data per site root page and needs a selected
     * page id to render its content.
     */
    public function isSiteScopedTab(string $tabKey): bool
    {
        return in_array($tabKey, self::SITE_SCOPED_TAB_KEYS, true);
    }

    /**
     * @param callable(string): string $translate
     * @param callable(string $route): string $buildUri
     * @return array{
     *     primary: array<string, array{title: string, route: string, path: string, href: string, active: bool, iconIdentifier: string}>,
     *     utility: array<string, array{title: string, route: string, path: string, href: string, active: bool, iconIdentifier: string}>
     * }
     */
    public function buildNavigationTabGroups(
        string $active,
        callable $translate,
        callable $buildUri,
        ?BackendUserAuthentication $user = null,
    ): array {
        $tabs = $this->buildVisibleTabs($active, $translate, $buildUri, $user);
        $utilityKeys = array_flip(self::UTILITY_TAB_KEYS);

        return [
            'primary' => array_diff_key($tabs, $utilityKeys),
            'utility' => array_intersect_key($tabs, $utilityKeys),
        ];
    }

    /**
     * Returns the backend route identifier for a known tab key, or null when
     * the key does not correspond to a top-level navigation tab.
     */
    public function routeFor(string $tabKey): ?string
    {
        return self::TABS[$tabKey]['route'] ?? null;
    }

    public function tabKeyForRoute(string $route): ?string
    {
        foreach (self::TABS as $key => $tab) {
            if ($tab['route'] === $route) {
                return $key;
            }
        }

        return null;
    }

    public function firstVisibleNonDashboardTabRoute(?BackendUserAuthentication $user): ?string
    {
        foreach (self::TABS as $key => $tab) {
            if ($key === 'dashboard') {
                continue;
            }
            if ($this->tabAccessService->isTabVisible($key, $user)) {
                return $tab['route'];
            }
        }

        return null;
    }

    /**
     * @param callable(string): string $translate
     * @param callable(string $route): string $buildUri
     * @return list<array{key: string, title: string, intro: string, href: string, iconIdentifier: string}>
     */
    public function listVisibleNonDashboardTabs(
        callable $translate,
        callable $buildUri,
        ?BackendUserAuthentication $user = null,
    ): array {
        $items = [];

        foreach (self::TABS as $key => $tab) {
            if ($key === 'dashboard' || !$this->tabAccessService->isTabVisible($key, $user)) {
                continue;
            }
            $content = $this->buildTabContent($key, $translate);
            $items[] = [
                'key' => $key,
                'title' => $content['tabHeading'],
                'intro' => $content['tabIntro'],
                'href' => (string) $buildUri($tab['route']),
                'iconIdentifier' => $this->resolveIconIdentifier($tab['icon']),
            ];
        }

        return $items;
    }

    /**
     * Whether {@see ModuleStateService} should record this key as the
     * user's "last tab". Auxiliary routes (provider edit, buy credits,
     * credits pricing, …) intentionally return false so re-entry restores
     * the surrounding top-level tab instead of the sub-flow.
     */
    public function isPersistableTab(string $tabKey): bool
    {
        return isset(self::TABS[$tabKey]);
    }

    /**
     * Menu label for the active tab (for document titles), or empty if unknown.
     */
    public function navigationLabelFor(string $active, callable $translate): string
    {
        $tab = self::TABS[$active] ?? null;
        if ($tab === null) {
            return '';
        }

        return (string) $translate($tab['labelKey']);
    }

    /**
     * @return array{tabHeading: string, tabIntro: string}
     */
    public function buildTabContent(string $active, callable $translate): array
    {
        $tab = self::TABS[$active] ?? null;
        if ($tab === null) {
            return [
                'tabHeading' => (string) $translate('module.title'),
                'tabIntro' => '',
            ];
        }

        return [
            'tabHeading' => (string) $translate($tab['headingKey']),
            'tabIntro' => (string) $translate($tab['introKey']),
        ];
    }

    public function resolveActivePath(string $active): string
    {
        $tab = self::TABS[$active] ?? null;
        if ($tab === null) {
            return '/module/t3af/dashboard';
        }

        return $tab['path'];
    }

    private function resolveIconIdentifier(string $icon): string
    {
        return match ($icon) {
            'home' => 'actions-check-circle',
            'brain' => 'actions-system-extension-configure',
            'context' => 'actions-lightbulb',
            'server' => 'actions-link',
            'tools' => 'actions-system-extension-install',
            'plug' => 'actions-link',
            'sparkles' => 'actions-star',
            'chart' => 'actions-document-info',
            'log' => 'actions-notebook',
            'prompt' => 'actions-message',
            'terminal' => 'actions-refresh',
            'users' => 'actions-user',
            'code' => 'actions-code',
            default => 'actions-circle',
        };
    }
}
