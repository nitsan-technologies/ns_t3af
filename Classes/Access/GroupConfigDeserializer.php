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
use NITSAN\NsT3AF\Access\Dto\LimitsConfig;
use NITSAN\NsT3AF\Access\Enum\BulkOpsLevel;
use NITSAN\NsT3AF\Access\Enum\FeatureLevel;
use NITSAN\NsT3AF\Access\Enum\RecordAccess;
use NITSAN\NsT3AF\Registry\FeatureAccessBindingRegistry;

/**
 * Reconstructs wizard state from be_groups + group settings row.
 */
final class GroupConfigDeserializer
{
    public function __construct(
        private readonly ModuleAccessCatalog $moduleCatalog,
        private readonly FeaturePermissionCatalog $featureCatalog,
        private readonly RecordPermissionCatalog $recordCatalog,
        private readonly WizardBootstrapFactory $wizardBootstrapFactory,
        private readonly FeatureAccessBindingRegistry $bindingRegistry,
    ) {}

    /**
     * @param array<string, mixed> $beGroupRow be_groups record
     * @param array<string, mixed>|null $settingsRow tx_nst3af_group_settings row
     */
    public function deserialize(array $beGroupRow, ?array $settingsRow = null): GroupConfig
    {
        $groupMods = $this->parseCsvField($beGroupRow['groupMods'] ?? '');
        $customOptions = $this->parseCsvField($beGroupRow['custom_options'] ?? '');
        $tablesSelect = $this->parseCsvField($beGroupRow['tables_select'] ?? '');
        $tablesModify = $this->parseCsvField($beGroupRow['tables_modify'] ?? '');

        $modules = $this->deserializeModules($groupMods, $customOptions);
        $features = $this->deserializeFeatures($customOptions, $modules);
        $records = $this->deserializeRecords($tablesSelect, $tablesModify);

        $limits = $this->deserializeLimits($settingsRow);
        $configured = $this->isConfigured($modules, $customOptions, $settingsRow);

        return new GroupConfig(
            modules: $modules,
            features: $features,
            records: $records,
            limits: $limits,
            configured: $configured,
        );
    }

    /**
     * @param list<string> $groupMods
     * @param list<string> $customOptions
     * @return array<string, bool>
     */
    private function deserializeModules(array $groupMods, array $customOptions): array
    {
        $modules = [];
        foreach ($this->moduleCatalog->childModules() as $key => $meta) {
            $modules[$key] = in_array($meta['groupMod'], $groupMods, true);
        }
        foreach (ModuleAccessCatalog::ADMIN_MODULES as $key => $meta) {
            $perm = ModuleAccessCatalog::PERM_PREFIX_TAB . ':' . $meta['permKey'];
            $modules[$key] = in_array($perm, $customOptions, true);
        }
        return $modules;
    }

    /**
     * @param list<string> $customOptions
     * @param array<string, bool> $modules
     * @return array<string, string>
     */
    private function deserializeFeatures(array $customOptions, array $modules): array
    {
        $features = $this->wizardBootstrapFactory->defaultFeatures();
        $prefix = FeaturePermissionCatalog::PERM_PREFIX . ':';

        foreach ($this->featureCatalog->all() as $def) {
            $featureId = (string) $def['id'];
            if (($def['type'] ?? '') === 'bulk') {
                continue;
            }
            if (!$this->isFeatureModuleEnabled($def, $modules)) {
                continue;
            }

            $permBase = (string) $def['permBase'];
            $useBit = $prefix . $permBase;
            $manageBit = $prefix . $permBase . '.Manage';
            if (in_array($manageBit, $customOptions, true)) {
                $features[$featureId] = FeatureLevel::Manage->value;
            } elseif (in_array($useBit, $customOptions, true)) {
                $features[$featureId] = FeatureLevel::Use->value;
            }
        }

        if (in_array($prefix . 'Pages.Any', $customOptions, true)) {
            $features['bulkOps'] = BulkOpsLevel::Any->value;
        } elseif (in_array($prefix . 'Pages', $customOptions, true)) {
            $features['bulkOps'] = BulkOpsLevel::Scoped->value;
        }

        if (!empty($modules['t3cs']) && in_array($prefix . 'T3CS.Logs', $customOptions, true)) {
            $features['t3csAnalytics'] = FeatureLevel::Use->value;
        }

        $features = $this->applyLegacyFeatureAliases($features, $customOptions, $prefix);

        return $features;
    }

    /**
     * @param array<string, string> $features
     * @param list<string> $customOptions
     * @return array<string, string>
     */
    private function applyLegacyFeatureAliases(array $features, array $customOptions, string $prefix): array
    {
        foreach ($this->bindingRegistry->legacyDeserializerAliases() as $legacyPermBase => $featureIds) {
            $level = null;
            if (in_array($prefix . $legacyPermBase . '.Manage', $customOptions, true)) {
                $level = FeatureLevel::Manage;
            } elseif (in_array($prefix . $legacyPermBase, $customOptions, true)) {
                $level = FeatureLevel::Use;
            }

            if ($level === null) {
                continue;
            }

            foreach ($featureIds as $featureId) {
                $current = FeatureLevel::tryFromString($features[$featureId] ?? 'disabled');
                if ($current === FeatureLevel::Manage || ($current === FeatureLevel::Use && $level === FeatureLevel::Use)) {
                    continue;
                }

                $features[$featureId] = $level->value;
            }
        }

        return $features;
    }

    /**
     * @param array<string, mixed> $def
     * @param array<string, bool> $modules
     */
    private function isFeatureModuleEnabled(array $def, array $modules): bool
    {
        foreach ($def['relevantModules'] as $moduleKey) {
            if (!empty($modules[$moduleKey])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $tablesSelect
     * @param list<string> $tablesModify
     * @return array<string, string>
     */
    private function deserializeRecords(array $tablesSelect, array $tablesModify): array
    {
        $records = $this->wizardBootstrapFactory->defaultRecords();

        foreach ($this->recordCatalog->all() as $row) {
            $hasSelect = false;
            $hasModify = false;
            foreach ($row['tables'] as $table) {
                if (in_array($table, $tablesSelect, true)) {
                    $hasSelect = true;
                }
                if (in_array($table, $tablesModify, true)) {
                    $hasModify = true;
                }
            }
            if ($hasModify) {
                $records[$row['id']] = RecordAccess::ReadWrite->value;
            } elseif ($hasSelect) {
                $records[$row['id']] = RecordAccess::Read->value;
            }
        }

        return $records;
    }

    /**
     * @param array<string, mixed>|null $settingsRow
     */
    private function deserializeLimits(?array $settingsRow): LimitsConfig
    {
        if ($settingsRow === null) {
            return new LimitsConfig();
        }

        $json = $settingsRow['limits_json'] ?? '';
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return LimitsConfig::fromArray($decoded);
            }
        }

        return new LimitsConfig();
    }

    /**
     * @param array<string, bool> $modules
     * @param list<string> $customOptions
     * @param array<string, mixed>|null $settingsRow
     */
    private function isConfigured(array $modules, array $customOptions, ?array $settingsRow): bool
    {
        if ($settingsRow !== null && (int) ($settingsRow['configured'] ?? 0) === 1) {
            return true;
        }

        foreach ($modules as $enabled) {
            if ($enabled) {
                return true;
            }
        }

        foreach ($customOptions as $opt) {
            if (str_starts_with($opt, FeaturePermissionCatalog::PERM_PREFIX . ':')
                || str_starts_with($opt, ModuleAccessCatalog::PERM_PREFIX_TAB . ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string|list<string|int> $value
     * @return list<string>
     */
    private function parseCsvField(string|array $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn(string|int $item): string => trim((string) $item), $value)));
        }
        if ($value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
