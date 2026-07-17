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

use NITSAN\NsT3AF\Registry\FeatureAccessBindingRegistry;

/**
 * Merges wizard output into existing be_groups ACL fields without touching
 * unrelated module, custom-option, or table permissions.
 */
final class BeGroupPermissionMerger
{
    public function __construct(
        private readonly RecordPermissionCatalog $recordCatalog,
        private readonly ModuleAccessCatalog $moduleCatalog,
        private readonly FeatureAccessBindingRegistry $bindingRegistry,
    ) {}

    /**
     * @param array<string, mixed> $existingBeGroupRow
     * @param array{
     *     groupMods: list<string>,
     *     customOptions: list<string>,
     *     tablesSelect: list<string>,
     *     tablesModify: list<string>
     * } $serialized
     * @return array{
     *     groupMods: string,
     *     custom_options: string,
     *     tables_select: string,
     *     tables_modify: string
     * }
     */
    public function merge(array $existingBeGroupRow, array $serialized): array
    {
        $managedTables = $this->managedTables();

        return [
            'groupMods' => $this->mergeListField(
                $existingBeGroupRow['groupMods'] ?? '',
                $serialized['groupMods'],
                $this->managedGroupMods(),
            ),
            'custom_options' => $this->mergeCustomOptions(
                $existingBeGroupRow['custom_options'] ?? '',
                $serialized['customOptions'],
            ),
            'tables_select' => $this->mergeListField(
                $existingBeGroupRow['tables_select'] ?? '',
                $serialized['tablesSelect'],
                $managedTables,
            ),
            'tables_modify' => $this->mergeListField(
                $existingBeGroupRow['tables_modify'] ?? '',
                $serialized['tablesModify'],
                $managedTables,
            ),
        ];
    }

    /**
     * @param string|list<string|int> $existing
     * @param list<string> $newValues
     * @param list<string> $managedKeys
     */
    private function mergeListField(string|array $existing, array $newValues, array $managedKeys): string
    {
        $managedLookup = array_flip($managedKeys);
        $preserved = array_values(array_filter(
            $this->parseCsvField($existing),
            static fn(string $value): bool => !isset($managedLookup[$value]),
        ));

        return implode(',', array_values(array_unique([...$preserved, ...$newValues])));
    }

    /**
     * @param string|list<string|int> $existing
     * @param list<string> $newValues
     */
    private function mergeCustomOptions(string|array $existing, array $newValues): string
    {
        $preserved = array_values(array_filter(
            $this->parseCsvField($existing),
            fn(string $value): bool => !$this->isManagedCustomOption($value),
        ));

        return implode(',', array_values(array_unique([...$preserved, ...$newValues])));
    }

    private function isManagedCustomOption(string $option): bool
    {
        if (str_starts_with($option, ModuleAccessCatalog::PERM_PREFIX_TAB . ':')
            || str_starts_with($option, FeaturePermissionCatalog::PERM_PREFIX . ':')
            || str_starts_with($option, 'nst3af:capability_')
        ) {
            return true;
        }

        foreach ($this->bindingRegistry->legacyCustomOptionPrefixes() as $prefix) {
            if (str_starts_with($option, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function managedGroupMods(): array
    {
        return array_values(array_unique([
            ...ModuleAccessCatalog::SHELL_GROUP_MODS,
            ...$this->moduleCatalog->childGroupMods(),
        ]));
    }

    /**
     * @return list<string>
     */
    private function managedTables(): array
    {
        $tables = [];
        foreach ($this->recordCatalog->all() as $row) {
            foreach ($row['tables'] as $table) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
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
