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

/**
 * Builds default wizard {@see GroupConfig} from merged access catalogs (providers + legacy).
 */
final class WizardBootstrapFactory
{
    public function __construct(
        private readonly ModuleAccessCatalog $moduleCatalog,
        private readonly FeaturePermissionCatalog $featureCatalog,
        private readonly RecordPermissionCatalog $recordCatalog,
    ) {}

    public function createDefaultConfig(): GroupConfig
    {
        return new GroupConfig(
            modules: $this->defaultModules(),
            features: $this->defaultFeatures(),
            records: $this->defaultRecords(),
            limits: new LimitsConfig(),
            configured: false,
        );
    }

    /**
     * @return array<string, bool>
     */
    public function defaultModules(): array
    {
        $modules = [];
        foreach ($this->moduleCatalog->childModules() as $key => $_meta) {
            $modules[$key] = false;
        }
        foreach (ModuleAccessCatalog::ADMIN_MODULES as $key => $_meta) {
            $modules[$key] = false;
        }

        return $modules;
    }

    /**
     * @return array<string, string>
     */
    public function defaultFeatures(): array
    {
        $features = [];
        foreach ($this->featureCatalog->all() as $row) {
            if (($row['type'] ?? '') === 'bulk') {
                $features[$row['id']] = BulkOpsLevel::Disabled->value;
                continue;
            }
            $features[$row['id']] = FeatureLevel::Disabled->value;
        }

        return $features;
    }

    /**
     * @return array<string, string>
     */
    public function defaultRecords(): array
    {
        $records = [];
        foreach ($this->recordCatalog->all() as $row) {
            $records[$row['id']] = RecordAccess::None->value;
        }

        return $records;
    }
}
