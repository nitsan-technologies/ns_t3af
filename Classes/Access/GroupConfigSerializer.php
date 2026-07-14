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
use NITSAN\NsT3AF\Contract\LegacyCustomOptionExpanderInterface;
use NITSAN\NsT3AF\Registry\FeatureAccessBindingRegistry;

/**
 * Maps wizard {@see GroupConfig} to TYPO3 Backend Usergroups fields + limits payload.
 */
final class GroupConfigSerializer
{
    /**
     * @param iterable<LegacyCustomOptionExpanderInterface> $legacyCustomOptionExpanders
     */
    public function __construct(
        private readonly ModuleAccessCatalog $moduleCatalog,
        private readonly FeaturePermissionCatalog $featureCatalog,
        private readonly RecordPermissionCatalog $recordCatalog,
        private readonly FeatureAccessBindingRegistry $bindingRegistry,
        private readonly iterable $legacyCustomOptionExpanders = [],
    ) {}

    /**
     * @return array{
     *     groupMods: list<string>,
     *     customOptions: list<string>,
     *     tablesSelect: list<string>,
     *     tablesModify: list<string>,
     *     limits: LimitsConfig,
     *     configured: bool
     * }
     */
    public function serialize(GroupConfig $config): array
    {
        $groupMods = $this->serializeGroupMods($config);
        $customOptions = $this->serializeCustomOptions($config);
        [$tablesSelect, $tablesModify] = $this->serializeTableAccess($config);

        return [
            'groupMods' => $groupMods,
            'customOptions' => $customOptions,
            'tablesSelect' => $tablesSelect,
            'tablesModify' => $tablesModify,
            'limits' => $config->limits,
            'configured' => true,
        ];
    }

    /**
     * @return array{
     *     groupMods: list<string>,
     *     customOptions: list<string>,
     *     tablesSelect: list<string>,
     *     tablesModify: list<string>
     * }
     */
    public function preview(GroupConfig $config): array
    {
        return [
            'groupMods' => $this->serializeGroupMods($config),
            'customOptions' => $this->serializeCustomOptions($config),
            'tablesSelect' => $this->serializeTableAccess($config)[0],
            'tablesModify' => $this->serializeTableAccess($config)[1],
        ];
    }

    /**
     * @return list<string>
     */
    private function serializeGroupMods(GroupConfig $config): array
    {
        $mods = [];
        foreach ($this->moduleCatalog->childModules() as $key => $meta) {
            if (!empty($config->modules[$key]) && $this->moduleCatalog->isExtensionLoaded($key)) {
                $mods[] = $meta['groupMod'];
            }
        }

        if ($this->moduleCatalog->hasAnyEnabledModule($config->modules)) {
            foreach (ModuleAccessCatalog::SHELL_GROUP_MODS as $shellMod) {
                $mods[] = $shellMod;
            }
        }

        return array_values(array_unique($mods));
    }

    /**
     * @return list<string>
     */
    private function serializeCustomOptions(GroupConfig $config): array
    {
        $options = [];

        foreach (ModuleAccessCatalog::ADMIN_MODULES as $key => $meta) {
            if (!empty($config->modules[$key])) {
                $options[] = ModuleAccessCatalog::PERM_PREFIX_TAB . ':' . $meta['permKey'];
            }
        }

        $options = array_merge($options, $this->serializeFeatureOptions($config));
        $options = array_merge($options, $this->serializeCapabilityOptions($config));
        foreach ($this->legacyCustomOptionExpanders as $expander) {
            $options = array_merge($options, $expander->expandForConfig($config));
        }

        return array_values(array_unique($options));
    }

    /**
     * @return list<string>
     */
    private function serializeCapabilityOptions(GroupConfig $config): array
    {
        foreach ($this->bindingRegistry->allBindings() as $bindings) {
            if (!$bindings->grantsCapabilities) {
                continue;
            }
            if (!empty($config->modules[$bindings->moduleKey])) {
                return [
                    'nst3af:capability_chat',
                    'nst3af:capability_streaming',
                    'nst3af:capability_embeddings',
                ];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function serializeFeatureOptions(GroupConfig $config): array
    {
        $options = [];
        $prefix = FeaturePermissionCatalog::PERM_PREFIX;

        foreach ($this->featureCatalog->all() as $def) {
            $featureId = (string) $def['id'];
            if (($def['type'] ?? '') === 'bulk') {
                continue;
            }
            if (!$this->isFeatureModuleEnabledForConfig($def, $config)) {
                continue;
            }

            $level = FeatureLevel::tryFromString($config->features[$featureId] ?? 'disabled');
            if ($level === FeatureLevel::Disabled) {
                continue;
            }

            $base = $prefix . ':' . $def['permBase'];
            $options[] = $level === FeatureLevel::Manage ? $base . '.Manage' : $base;
        }

        $bulk = BulkOpsLevel::tryFromString($config->features['bulkOps'] ?? 'disabled');
        if ($bulk !== BulkOpsLevel::Disabled) {
            $options[] = $prefix . ':Pages';
            if ($bulk === BulkOpsLevel::Any) {
                $options[] = $prefix . ':Pages.Any';
            }
        }

        foreach ($this->bindingRegistry->allBindings() as $bindings) {
            if ($bindings->suiteBaseFeature === null) {
                continue;
            }
            if (!empty($config->modules[$bindings->moduleKey])) {
                $options[] = $prefix . ':' . $bindings->suiteBaseFeature;
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $def
     */
    private function isFeatureModuleEnabledForConfig(array $def, GroupConfig $config): bool
    {
        foreach ($def['relevantModules'] as $moduleKey) {
            if (!empty($config->modules[$moduleKey])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function serializeTableAccess(GroupConfig $config): array
    {
        $select = [];
        $modify = [];

        foreach ($config->records as $rowId => $levelRaw) {
            $level = RecordAccess::tryFromString($levelRaw);
            if ($level === RecordAccess::None) {
                continue;
            }
            $row = $this->recordCatalog->findById($rowId);
            if ($row === null) {
                continue;
            }
            foreach ($row['tables'] as $table) {
                $select[] = $table;
                if ($level === RecordAccess::ReadWrite && !($row['readOnlyWrite'] ?? false)) {
                    $modify[] = $table;
                }
            }
        }

        return [array_values(array_unique($select)), array_values(array_unique($modify))];
    }
}
