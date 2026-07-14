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

/**
 * Builds permission-matrix scope tabs from merged module / feature / record catalogs.
 */
final class MatrixScopeCatalog
{
    public function __construct(
        private readonly ModuleAccessCatalog $moduleCatalog,
        private readonly FeaturePermissionCatalog $featureCatalog,
        private readonly RecordPermissionCatalog $recordCatalog,
    ) {}

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     accent: string,
     *     type: string,
     *     moduleKeys: list<string>,
     *     adminModuleKeys: list<string>,
     *     featureIds: list<string>,
     *     recordIds: list<string>
     * }>
     */
    public function buildScopes(): array
    {
        $scopes = [$this->foundationScope()];

        foreach ($this->moduleCatalog->visibleChildModules() as $moduleKey => $meta) {
            $scopes[] = [
                'id' => $moduleKey,
                'label' => self::moduleDisplayLabel($meta, $moduleKey),
                'accent' => (string) ($meta['color'] ?? '#64748b'),
                'type' => 'child',
                'moduleKeys' => [$moduleKey],
                'adminModuleKeys' => [],
                'featureIds' => $this->featureIdsForModule($moduleKey),
                'recordIds' => $this->recordIdsForModule($moduleKey),
            ];
        }

        return $scopes;
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     accent: string,
     *     type: string,
     *     moduleKeys: list<string>,
     *     adminModuleKeys: list<string>,
     *     featureIds: list<string>,
     *     recordIds: list<string>
     * }
     */
    private function foundationScope(): array
    {
        return [
            'id' => 'ai-universe',
            'label' => 'AI Foundation',
            'accent' => '#1a56db',
            'type' => 'foundation',
            'moduleKeys' => [],
            'adminModuleKeys' => array_keys(ModuleAccessCatalog::ADMIN_MODULES),
            'featureIds' => [],
            'recordIds' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function featureIdsForModule(string $moduleKey): array
    {
        $ids = [];
        foreach ($this->featureCatalog->all() as $row) {
            if (in_array($moduleKey, $row['relevantModules'], true)) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function recordIdsForModule(string $moduleKey): array
    {
        $ids = [];
        foreach ($this->recordCatalog->all() as $row) {
            if (in_array($moduleKey, $row['relevantModules'], true)) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function moduleDisplayLabel(array $meta, string $moduleKey): string
    {
        $sublabel = trim((string) ($meta['sublabel'] ?? ''));
        if ($sublabel !== '') {
            return $sublabel;
        }

        return (string) ($meta['label'] ?? $moduleKey);
    }
}
