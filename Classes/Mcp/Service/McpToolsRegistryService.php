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

namespace NITSAN\NsT3AF\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Repository\DiscoveredTableRepository;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpAnalyticsService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolMetadataService;
use NITSAN\NsT3AF\Registry\McpToolsExtensionCardProviderRegistry;
use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;

/**
 * Aggregates MCP tool catalog data for the backend MCP Tools module tab.
 */
readonly class McpToolsRegistryService
{
    public const TOOLS_PER_DYNAMIC_TABLE = 9;

    private const CORE_EXTENSION_ID = 'ns_t3af_core';

    public function __construct(
        private McpToolIntrospectorService $toolIntrospector,
        private ExtensionTableDiscoveryService $tableDiscoveryService,
        private DiscoveredTableRepository $discoveredTableRepository,
        private McpToolMetadataService $toolMetadataService,
        private McpAnalyticsService $analyticsService,
        private McpToolsExtensionCardProviderRegistry $cardProviderRegistry,
        private McpToolOwnershipResolver $toolOwnershipResolver,
    ) {}

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     icon: string,
     *     iconBg: string,
     *     color: string,
     *     tagline: string,
     *     skillName: string,
     *     skillTrigger: string,
     *     skillFile: string,
     *     skillDesc: string,
     *     toolCount: int,
     *     tools: list<array{name: string, description: string, params: list<array{name: string, type: string, required: bool, default: string|null}>}>
     * }>
     *
     * @param array<string, mixed>|string $periodQuery
     */
    public function getAiUniverseExtensions(array|string $periodQuery = '7d'): array
    {
        $allTools = $this->getAllTools();
        $extensionConfigs = $this->cardProviderRegistry->buildExtensionConfigs();
        $toolsByExtension = [];
        $coreTools = [];

        foreach ($allTools as $tool) {
            $ownerKey = $this->toolOwnershipResolver->resolve($tool, $extensionConfigs);
            if ($ownerKey === null) {
                $coreTools[] = $tool;
                continue;
            }

            $toolsByExtension[$ownerKey][] = $tool;
        }

        $extensions = [];
        foreach ($toolsByExtension as $extensionId => $tools) {
            $config = $extensionConfigs[$extensionId] ?? $this->buildAutoExtensionConfig($extensionId, $tools);
            if (!$this->isExtensionCatalogEntryVisible($extensionId, $config)) {
                $coreTools = array_merge($coreTools, $tools);
                continue;
            }

            $extensions[] = $this->buildExtensionCard($extensionId, $config, $tools, $periodQuery);
        }

        usort(
            $extensions,
            static fn(array $a, array $b): int => ($a['sortPriority'] ?? 50) <=> ($b['sortPriority'] ?? 50),
        );

        if ($coreTools !== []) {
            array_unshift($extensions, $this->buildCoreExtensionCard($coreTools, $periodQuery));
        }

        return $extensions;
    }

    /**
     * @return list<array{
     *     id: string|null,
     *     tableName: string,
     *     label: string,
     *     prefix: string,
     *     source: string,
     *     enabled: bool,
     *     toolCount: int,
     *     toolNames: list<string>
     * }>
     */
    public function getCustomTableRows(): array
    {
        $extconfTables = $this->getExtconfTables();
        $rows = [];

        foreach ($extconfTables as $tableName => $config) {
            if (!is_string($tableName) || !is_array($config)) {
                continue;
            }

            $prefix = (string) ($config['prefix'] ?? $tableName);
            $rows[] = [
                'id' => null,
                'tableName' => $tableName,
                'label' => (string) ($config['label'] ?? $tableName),
                'prefix' => $prefix,
                'source' => 'code',
                'enabled' => true,
                'toolCount' => self::TOOLS_PER_DYNAMIC_TABLE,
                'toolNames' => self::buildDynamicToolNames($prefix),
            ];
        }

        foreach ($this->discoveredTableRepository->findAll() as $row) {
            if (array_key_exists($row['table_name'], $extconfTables)) {
                continue;
            }

            $enabled = (int) $row['enabled'] === 1;
            $prefix = $row['prefix'];
            $rows[] = [
                'id' => (string) $row['uid'],
                'tableName' => $row['table_name'],
                'label' => $row['label'],
                'prefix' => $prefix,
                'source' => 'discovered',
                'enabled' => $enabled,
                'toolCount' => $enabled ? self::TOOLS_PER_DYNAMIC_TABLE : 0,
                'toolNames' => $enabled ? self::buildDynamicToolNames($prefix) : [],
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['tableName'], $b['tableName']));

        return $rows;
    }

    /**
     * @return list<string>
     */
    public static function buildDynamicToolNames(string $prefix): array
    {
        return [
            $prefix . '_list',
            $prefix . '_get',
            $prefix . '_create',
            $prefix . '_update',
            $prefix . '_delete',
            $prefix . '_move',
            $prefix . '_delete_batch',
            $prefix . '_update_batch',
            $prefix . '_move_batch',
        ];
    }

    public function discoverExtensionTables(): int
    {
        $candidates = $this->tableDiscoveryService->discoverTables();
        $newCount = 0;

        foreach ($candidates as $tableName => $config) {
            if ($this->discoveredTableRepository->insertIfNew($tableName, $config['label'], $config['prefix'])) {
                ++$newCount;
            }
        }

        return $newCount;
    }

    public function setTableEnabled(int $uid, bool $enabled): void
    {
        $this->discoveredTableRepository->setEnabled($uid, $enabled);
    }

    public function saveTableConfig(int $uid, string $label, string $prefix): void
    {
        $this->discoveredTableRepository->update($uid, $label, $prefix);
    }

    /**
     * @return array{
     *     period: string,
     *     totalTools: int,
     *     extensionTools: int,
     *     customTools: int,
     *     customTableCount: int,
     *     customTableTotal: int,
     *     extensionCount: int,
     *     toolCalls: int,
     *     avgSuccessRate: float
     * }
     *
     * @param array<string, mixed>|string $periodQuery
     */
    public function getStatistics(array|string $periodQuery = '7d'): array
    {
        $allTools = $this->getAllTools();
        return $this->buildStatisticsForTools($allTools, $periodQuery);
    }

    /**
     * @return list<array{name: string, className: class-string, ownerExtensionKey: string|null}>
     */
    public function getAllTools(): array
    {
        /** @var list<array{name: string, className: class-string, ownerExtensionKey: string|null}> $tools */
        $tools = $this->toolIntrospector->listTools();

        return $tools;
    }

    /**
     * @param list<array{name: string, className: class-string, ownerExtensionKey: string|null}> $allTools
     * @param array<string, mixed>|string $periodQuery
     *
     * @return array{
     *     period: string,
     *     totalTools: int,
     *     extensionTools: int,
     *     customTools: int,
     *     customTableCount: int,
     *     customTableTotal: int,
     *     extensionCount: int,
     *     toolCalls: int,
     *     avgSuccessRate: float,
     *     periodLabel: string
     * }
     */
    public function getStatisticsForTools(array $allTools, array|string $periodQuery = '7d'): array
    {
        return $this->buildStatisticsForTools($allTools, $periodQuery);
    }

    /**
     * @param list<array{name: string, className: class-string, ownerExtensionKey: string|null}> $allTools
     * @param array<string, mixed>|string $periodQuery
     *
     * @return array{
     *     period: string,
     *     totalTools: int,
     *     extensionTools: int,
     *     customTools: int,
     *     customTableCount: int,
     *     customTableTotal: int,
     *     extensionCount: int,
     *     toolCalls: int,
     *     avgSuccessRate: float,
     *     periodLabel: string
     * }
     */
    private function buildStatisticsForTools(array $allTools, array|string $periodQuery = '7d'): array
    {
        $staticTools = count($allTools);
        $customRows = $this->getCustomTableRows();
        $customTools = 0;
        $customTableCount = 0;

        foreach ($customRows as $row) {
            if ($row['enabled']) {
                $customTools += $row['toolCount'];
                ++$customTableCount;
            }
        }

        $extensionCount = $this->countVisibleExtensionGroups($allTools);
        // Core tools are not counted as "extension tools" in the KPI.
        $extensionTools = max(0, $staticTools - $this->countCoreTools($allTools));

        $analyticsSummary = $this->analyticsService->getSummary($periodQuery);
        $resolvedPeriod = $this->analyticsService->resolvePeriod($periodQuery);

        return [
            'period' => $resolvedPeriod['key'],
            'periodLabel' => '',
            'totalTools' => $staticTools + $customTools,
            'extensionTools' => $extensionTools,
            'customTools' => $customTools,
            'customTableCount' => $customTableCount,
            'customTableTotal' => count($customRows),
            'extensionCount' => $extensionCount,
            'toolCalls' => (int) ($analyticsSummary['toolCalls'] ?? 0),
            'avgSuccessRate' => (float) ($analyticsSummary['avgSuccessRate'] ?? 0.0),
        ];
    }

    public function buildExtConfSnippet(): string
    {
        return <<<'PHP'
// ext_localconf.php — Register custom tables for MCP
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['tables']['tx_blog_domain_model_post'] = [
    'label'  => 'Blog Post',
    'prefix' => 'blog_post',
    // Optional field overrides:
    'listFields'     => ['uid', 'pid', 'title', 'datetime'],
    'readFields'     => ['title', 'datetime', 'bodytext'],
    'writableFields' => ['title', 'datetime', 'bodytext'],
];

// This auto-creates 9 tools:
// blog_post_list, blog_post_get, blog_post_create,
// blog_post_update, blog_post_delete, blog_post_move,
// blog_post_delete_batch, blog_post_update_batch, blog_post_move_batch
PHP;
    }

    /**
     * @param list<array{name: string, description: string, params: list<array{name: string, type: string, required: bool, default: string|null}>, className: class-string, ownerExtensionKey: string|null}> $tools
     * @return array<string, mixed>
     */
    private function buildAutoExtensionConfig(string $extensionId, array $tools): array
    {
        return [
            'extensionKey' => $extensionId,
            'label' => $extensionId,
            'icon' => '🧩',
            'iconIdentifier' => 'actions-extension',
            'iconBg' => '#f3f4f6',
            'color' => '#737373',
            'tagline' => '',
            'skillName' => $extensionId,
            'skillTrigger' => '/' . $extensionId,
            'skillFile' => $extensionId . '.md',
            'skillDesc' => '',
            'toolPrefix' => $this->inferCommonToolPrefix($tools),
            'sortPriority' => 50,
        ];
    }

    /**
     * @param list<array{name: string}> $tools
     */
    private function inferCommonToolPrefix(array $tools): string
    {
        if ($tools === []) {
            return '';
        }

        $firstToolName = $tools[0]['name'];
        $underscorePos = strpos($firstToolName, '_');
        if ($underscorePos === false) {
            return '';
        }

        return substr($firstToolName, 0, $underscorePos + 1);
    }

    /**
     * @param array<string, mixed> $config
     * @param list<array{name: string, description: string, params: list<array{name: string, type: string, required: bool, default: string|null}>}> $tools
     * @return array{
     *     id: string,
     *     label: string,
     *     icon: string,
     *     iconBg: string,
     *     color: string,
     *     tagline: string,
     *     skillName: string,
     *     skillTrigger: string,
     *     skillFile: string,
     *     skillDesc: string,
     *     toolCount: int,
     *     tools: list<array{name: string, description: string, params: list<array{name: string, type: string, required: bool, default: string|null}>}>
     * }
     */
    /**
     * @param array<string, mixed>|string $periodQuery
     */
    private function buildExtensionCard(string $extensionId, array $config, array $tools, array|string $periodQuery = '7d'): array
    {
        return [
            'id' => $extensionId,
            'label' => (string) ($config['label'] ?? $extensionId),
            'iconIdentifier' => (string) ($config['iconIdentifier'] ?? 'actions-extension'),
            'icon' => (string) ($config['icon'] ?? '🧩'),
            'iconBg' => (string) ($config['iconBg'] ?? '#f3f4f6'),
            'color' => (string) ($config['color'] ?? '#737373'),
            'tagline' => (string) ($config['tagline'] ?? ''),
            'skillName' => (string) ($config['skillName'] ?? ($config['label'] ?? $extensionId)),
            'skillTrigger' => (string) ($config['skillTrigger'] ?? ('/' . $extensionId)),
            'skillFile' => (string) ($config['skillFile'] ?? ($extensionId . '.md')),
            'skillDesc' => (string) ($config['skillDesc'] ?? ''),
            'sortPriority' => (int) ($config['sortPriority'] ?? 50),
            'toolCount' => count($tools),
            'tools' => $this->enrichToolsForDisplay($tools, $periodQuery),
            'searchText' => $this->buildExtensionSearchText($extensionId, $config, $tools),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<array{name: string}> $tools
     */
    private function buildExtensionSearchText(string $extensionId, array $config, array $tools): string
    {
        $parts = [
            $extensionId,
            (string) ($config['label'] ?? ''),
            (string) ($config['tagline'] ?? ''),
        ];

        foreach ($tools as $tool) {
            $parts[] = $tool['name'];
        }

        return trim(implode(' ', array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    /**
     * @param list<array{name: string, description: string, params: list<array{name: string, type: string, required: bool, default: string|null, description?: string}>}> $tools
     * @return list<array{
     *     name: string,
     *     description: string,
     *     shortTitle: string,
     *     category: string,
     *     status: string,
     *     tagline: string,
     *     notes: string,
     *     examplePrompts: list<string>,
     *     analytics: array{callsWeek: int, successRate: float, avgLatencyMs: float, lastCalled: int|null},
     *     params: list<array{name: string, type: string, required: bool, default: string|null, description: string}>
     * }>
     */
    /**
     * @param array<string, mixed>|string $periodQuery
     */
    private function enrichToolsForDisplay(array $tools, array|string $periodQuery = '7d'): array
    {
        $toolNames = [];
        foreach ($tools as $tool) {
            $name = trim((string) ($tool['name'] ?? ''));
            if ($name !== '') {
                $toolNames[] = $name;
            }
        }
        $analyticsByTool = $this->analyticsService->getForTools($toolNames, $periodQuery);

        return array_map(function (array $tool) use ($analyticsByTool): array {
            $description = $tool['description'];
            $toolName = (string) $tool['name'];
            $metadata = $this->toolMetadataService->getForTool($toolName);
            $shortTitle = $metadata['tagline'] !== ''
                ? $metadata['tagline']
                : $this->extractShortTitle($description, $toolName);

            $params = [];
            foreach ($tool['params'] as $param) {
                $params[] = [
                    'name' => $param['name'],
                    'type' => $this->formatParamType($param['type']),
                    'required' => $param['required'],
                    'default' => $param['default'],
                    'description' => $param['description'] ?? $this->buildParamDescription($param['name'], $param['type']),
                ];
            }

            return [
                'name' => $toolName,
                'description' => $description,
                'shortTitle' => $shortTitle,
                'category' => $metadata['category'],
                'status' => $metadata['status'],
                'tagline' => $metadata['tagline'],
                'notes' => $metadata['notes'],
                'examplePrompts' => $metadata['examplePrompts'],
                'analytics' => $analyticsByTool[$toolName] ?? [
                    'callsWeek' => 0,
                    'successRate' => 0.0,
                    'avgLatencyMs' => 0.0,
                    'lastCalled' => null,
                ],
                'params' => $params,
            ];
        }, $tools);
    }

    private function extractShortTitle(string $description, string $toolName): string
    {
        if ($description !== '') {
            $firstSentence = preg_split('/[.!?](?:\s|$)/', $description, 2)[0] ?? $description;

            return trim($firstSentence);
        }

        return ucfirst(str_replace('_', ' ', $toolName));
    }

    private function formatParamType(string $type): string
    {
        return match ($type) {
            'int' => 'integer',
            'bool' => 'boolean',
            'float' => 'number',
            'string' => 'string',
            'array' => 'array',
            default => str_replace(['|null', '\\'], ['', ''], $type),
        };
    }

    private function buildParamDescription(string $name, string $type): string
    {
        $label = ucfirst(str_replace(['_', '-'], ' ', $name));

        return sprintf('%s (%s).', $label, $this->formatParamType($type));
    }

    /**
     * @param list<array{name: string, description: string, params: list<array{name: string, type: string, required: bool, default: string|null}>}> $tools
     * @return array{
     *     id: string,
     *     label: string,
     *     icon: string,
     *     iconBg: string,
     *     color: string,
     *     tagline: string,
     *     skillName: string,
     *     skillTrigger: string,
     *     skillFile: string,
     *     skillDesc: string,
     *     toolCount: int,
     *     tools: list<array{name: string, description: string, params: list<array{name: string, type: string, required: bool, default: string|null}>}>
     * }
     */
    /**
     * @param array<string, mixed>|string $periodQuery
     */
    private function buildCoreExtensionCard(array $tools, array|string $periodQuery = '7d'): array
    {
        return [
            'id' => self::CORE_EXTENSION_ID,
            'label' => 'TYPO3 Core',
            'iconIdentifier' => 'actions-document',
            'icon' => '⚡',
            'iconBg' => '#f3f4f6',
            'color' => '#737373',
            'tagline' => 'Built-in MCP tools shipped with AI Foundation — pages, content, schema, workspaces, files, and record writes.',
            'skillName' => 'TYPO3 Core Assistant',
            'skillTrigger' => '/typo3_core',
            'skillFile' => 'typo3-core-skill.md',
            'skillDesc' => 'Core TYPO3 MCP tools for page navigation, content listing, table schema inspection, workspaces, files, and record writes.',
            'toolCount' => count($tools),
            'tools' => $this->enrichToolsForDisplay($tools, $periodQuery),
            'searchText' => $this->buildExtensionSearchText(self::CORE_EXTENSION_ID, [
                'label' => 'TYPO3 Core',
                'tagline' => 'Built-in MCP tools shipped with AI Foundation — pages, content, schema, workspaces, files, and record writes.',
            ], $tools),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isExtensionCatalogEntryVisible(string $extensionId, array $config): bool
    {
        if (($config['showWhenNotLoaded'] ?? false) === true) {
            return true;
        }

        $typo3ExtensionKey = (string) ($config['extensionKey'] ?? $extensionId);

        return $typo3ExtensionKey === '' || AiUniverseUtilityHelper::isExtensionLoaded($typo3ExtensionKey);
    }

    /**
     * @param list<array{name: string, className: class-string, ownerExtensionKey: string|null}> $allTools
     */
    private function countVisibleExtensionGroups(array $allTools): int
    {
        $extensionConfigs = $this->cardProviderRegistry->buildExtensionConfigs();
        $visible = [];

        foreach ($allTools as $tool) {
            $ownerKey = $this->toolOwnershipResolver->resolve($tool, $extensionConfigs);
            if ($ownerKey === null) {
                continue;
            }

            $config = $extensionConfigs[$ownerKey] ?? $this->buildAutoExtensionConfig($ownerKey, [$tool]);
            if (!$this->isExtensionCatalogEntryVisible($ownerKey, $config)) {
                continue;
            }

            $visible[$ownerKey] = true;
        }

        return count($visible);
    }

    /**
     * @param list<array{name: string, className: class-string, ownerExtensionKey: string|null}> $allTools
     */
    private function countCoreTools(array $allTools): int
    {
        $extensionConfigs = $this->cardProviderRegistry->buildExtensionConfigs();
        $coreCount = 0;

        foreach ($allTools as $tool) {
            $ownerKey = $this->toolOwnershipResolver->resolve($tool, $extensionConfigs);
            if ($ownerKey === null) {
                ++$coreCount;
                continue;
            }

            $config = $extensionConfigs[$ownerKey] ?? $this->buildAutoExtensionConfig($ownerKey, [$tool]);
            if (!$this->isExtensionCatalogEntryVisible($ownerKey, $config)) {
                ++$coreCount;
            }
        }

        return $coreCount;
    }

    /** @return array<string, array<string, mixed>> */
    private function getExtconfTables(): array
    {
        $typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3ConfVars)) {
            return [];
        }

        $extConf = $typo3ConfVars['EXTCONF'] ?? [];
        if (!is_array($extConf)) {
            return [];
        }

        $nst3afExtConf = $extConf['ns_t3af'] ?? [];
        if (!is_array($nst3afExtConf)) {
            return [];
        }

        $tables = $nst3afExtConf['tables'] ?? [];

        return is_array($tables) ? $tables : [];
    }
}
