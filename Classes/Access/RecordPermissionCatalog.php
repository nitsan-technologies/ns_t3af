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

use NITSAN\NsT3AF\Registry\AiAccessCatalogProviderRegistry;

/**
 * Record-permission rows for wizard Step 3 (tables_select / tables_modify).
 */
final class RecordPermissionCatalog
{
    public function __construct(
        private readonly ExtensionAvailability $extensionAvailability = new ExtensionAvailability(),
        private readonly ?AiAccessCatalogProviderRegistry $accessProviderRegistry = null,
    ) {}

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     tables: list<string>,
     *     relevantModules: list<string>,
     *     relevantFeatures: list<string>,
     *     readHelp: string,
     *     writeHelp: string,
     *     extension: string|null,
     *     readOnlyWrite: bool
     * }>
     */
    public function all(): array
    {
        $rows = array_merge(
            $this->foundationRows(),
            $this->providerRows(),
        );

        return array_values(array_filter(
            $rows,
            fn(array $row): bool => $this->extensionAvailability->isLoaded($row['extension']),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function findById(string $id): ?array
    {
        foreach ($this->all() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    public function tablesForCatalogId(string $id): array
    {
        $row = $this->findById($id);

        return $row !== null ? $row['tables'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function findByTable(string $table): ?array
    {
        foreach ($this->all() as $row) {
            if (in_array($table, $row['tables'], true)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<string> $enabledModules
     * @return list<array<string, mixed>>
     */
    public function forEnabledModules(array $enabledModules): array
    {
        return array_values(array_filter(
            $this->all(),
            static function (array $row) use ($enabledModules): bool {
                foreach ($row['relevantModules'] as $mod) {
                    if (in_array($mod, $enabledModules, true)) {
                        return true;
                    }
                }
                return false;
            },
        ));
    }

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     tables: list<string>,
     *     relevantModules: list<string>,
     *     relevantFeatures: list<string>,
     *     readHelp: string,
     *     writeHelp: string,
     *     extension: string|null,
     *     readOnlyWrite: bool
     * }>
     */
    private function providerRows(): array
    {
        if ($this->accessProviderRegistry === null) {
            return [];
        }

        $rows = [];
        foreach ($this->accessProviderRegistry->getRecordPermissions() as $descriptor) {
            $rows[] = $descriptor->toArray();
        }

        return $rows;
    }

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     tables: list<string>,
     *     relevantModules: list<string>,
     *     relevantFeatures: list<string>,
     *     readHelp: string,
     *     writeHelp: string,
     *     extension: string|null,
     *     readOnlyWrite: bool
     * }>
     */
    private function foundationRows(): array
    {
        return [
            $this->row('aiProviders', 'AI Providers', ['tx_nst3af_provider'], ['providers'], [], 'View provider configurations', 'Create and edit AI providers'),
            $this->row('brandProfiles', 'Brand Profiles', ['tx_nst3af_brand_context_profile'], ['aiContext'], [], 'Select and use brand profiles', 'Create and manage brand profiles'),
            $this->row('aiPromptStorage', 'AI Prompt Templates', ['tx_nst3af_ai_prompt'], ['aiPrompts'], ['prompts'], 'Select and use templates', 'Create, edit and delete prompt templates'),
            $this->row('usageRequestLog', 'AI Request / Usage Log', ['tx_nst3af_request_log'], ['aiUsage'], [], 'View usage and request logs', 'Export and manage usage logs', null, true),
            $this->row('extensionSettings', 'AI Feature Settings', ['tx_nst3af_extension_setting'], ['aiFeatures'], [], 'View feature settings', 'Edit AI feature configuration'),
            $this->row('oauthClients', 'MCP OAuth Clients', ['tx_nst3af_oauth_client'], ['mcpServer'], [], 'View OAuth clients', 'Manage MCP OAuth clients'),
            $this->row('mcpDiscoveredTables', 'MCP Discovered Tables', ['tx_nst3af_mcp_discovered_table'], ['mcpTools'], [], 'View discovered tables', 'Enable/disable MCP table tools'),
            $this->row('mcpCustomTools', 'MCP Custom Tools', ['tx_nst3af_mcp_custom_tool'], ['mcpTools'], [], 'View custom tools', 'Create and edit custom MCP tools'),
            $this->row('mcpPromptTemplates', 'MCP Prompt Templates', ['tx_nst3af_mcp_prompt_template'], ['mcpTools'], [], 'View MCP prompt templates', 'Manage MCP prompt templates'),
            $this->row('groupSettings', 'AI Group Settings', ['tx_nst3af_group_settings'], ['providers'], [], 'View group AI limits', 'Modify credit caps and constraints'),
            $this->row('runtimeSettings', 'Runtime / Credits Settings', ['tx_nst3af_runtime_setting'], ['providers'], [], 'View runtime settings', 'Modify credits runtime settings'),
        ];
    }

    /**
     * @param list<string> $tables
     * @param list<string> $relevantModules
     * @param list<string> $relevantFeatures
     * @return array{
     *     id: string,
     *     label: string,
     *     tables: list<string>,
     *     relevantModules: list<string>,
     *     relevantFeatures: list<string>,
     *     readHelp: string,
     *     writeHelp: string,
     *     extension: string|null,
     *     readOnlyWrite: bool
     * }
     */
    private function row(
        string $id,
        string $label,
        array $tables,
        array $relevantModules,
        array $relevantFeatures = [],
        string $readHelp = '',
        string $writeHelp = '',
        ?string $extension = null,
        bool $readOnlyWrite = false,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'tables' => $tables,
            'relevantModules' => $relevantModules,
            'relevantFeatures' => $relevantFeatures,
            'readHelp' => $readHelp,
            'writeHelp' => $writeHelp,
            'extension' => $extension,
            'readOnlyWrite' => $readOnlyWrite,
        ];
    }
}
