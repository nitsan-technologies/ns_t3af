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
use NITSAN\NsT3AF\Access\Enum\BulkOpsLevel;
use NITSAN\NsT3AF\Access\Enum\FeatureLevel;
use NITSAN\NsT3AF\Registry\FeatureAccessBindingRegistry;

/**
 * Ensures wizard payloads contain explicit module flags and drops orphan features/records.
 */
final class GroupConfigNormalizer
{
    public function __construct(
        private readonly FeaturePermissionCatalog $featureCatalog,
        private readonly RecordPermissionCatalog $recordCatalog,
        private readonly WizardBootstrapFactory $wizardBootstrapFactory,
        private readonly FeatureAccessBindingRegistry $bindingRegistry,
    ) {}

    public function normalize(GroupConfig $config): GroupConfig
    {
        $modules = $this->wizardBootstrapFactory->defaultModules();
        foreach ($config->modules as $key => $enabled) {
            if (array_key_exists($key, $modules)) {
                $modules[$key] = PayloadBoolean::parse($enabled);
            }
        }

        $features = $this->wizardBootstrapFactory->defaultFeatures();
        $incomingFeatures = $config->features;
        if (
            isset($incomingFeatures['t3csLogs'])
            && (string) $incomingFeatures['t3csLogs'] !== FeatureLevel::Disabled->value
            && (string) ($incomingFeatures['t3csAnalytics'] ?? FeatureLevel::Disabled->value) === FeatureLevel::Disabled->value
        ) {
            $incomingFeatures['t3csAnalytics'] = (string) $incomingFeatures['t3csLogs'];
        }

        foreach ($incomingFeatures as $key => $level) {
            if (!array_key_exists($key, $features)) {
                continue;
            }
            if (!$this->isFeatureAllowed($key, $modules)) {
                continue;
            }
            $features[$key] = (string) $level;
        }

        foreach ($features as $key => $level) {
            if (!$this->isFeatureAllowed($key, $modules)) {
                $features[$key] = $key === 'bulkOps'
                    ? BulkOpsLevel::Disabled->value
                    : FeatureLevel::Disabled->value;
            }
        }

        $records = $this->wizardBootstrapFactory->defaultRecords();
        foreach ($config->records as $rowId => $level) {
            if (!array_key_exists($rowId, $records)) {
                continue;
            }
            if (!$this->isRecordAllowed($rowId, $modules, $features)) {
                continue;
            }
            $records[$rowId] = (string) $level;
        }

        foreach ($this->recordCatalog->all() as $row) {
            $rowId = (string) $row['id'];
            if (!$this->isRecordAllowed($rowId, $modules, $features)) {
                $records[$rowId] = 'none';
            }
        }

        $records = $this->applyFeatureDrivenRecordDefaults($features, $records);

        return new GroupConfig(
            modules: $modules,
            features: $features,
            records: $records,
            limits: $config->limits,
            configured: $config->configured,
        );
    }

    /**
     * @param array<string, bool> $modules
     */
    private function isFeatureAllowed(string $featureId, array $modules): bool
    {
        foreach ($this->featureCatalog->all() as $def) {
            if ($def['id'] !== $featureId) {
                continue;
            }
            foreach ($def['relevantModules'] as $moduleKey) {
                if (!empty($modules[$moduleKey])) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    /**
     * @param array<string, bool> $modules
     * @param array<string, string> $features
     */
    private function isRecordAllowed(string $recordId, array $modules, array $features): bool
    {
        $row = $this->recordCatalog->findById($recordId);
        if ($row === null) {
            return false;
        }

        $moduleAllowed = false;
        foreach ($row['relevantModules'] as $moduleKey) {
            if (!empty($modules[$moduleKey])) {
                $moduleAllowed = true;
                break;
            }
        }
        if (!$moduleAllowed) {
            return false;
        }

        $relevantFeatures = $row['relevantFeatures'] ?? [];
        if ($relevantFeatures === []) {
            return true;
        }

        foreach ($relevantFeatures as $featureId) {
            $level = $features[$featureId] ?? FeatureLevel::Disabled->value;
            if ($level !== FeatureLevel::Disabled->value && $level !== BulkOpsLevel::Disabled->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Suggest minimum Step 3 record levels from Step 2 feature Use/Manage (only fills "none" or upgrades read → readwrite).
     *
     * @param array<string, string> $features
     * @param array<string, string> $records
     * @return array<string, string>
     */
    private function applyFeatureDrivenRecordDefaults(array $features, array $records): array
    {
        $bulkEnabled = BulkOpsLevel::tryFromString($features['bulkOps'] ?? 'disabled') !== BulkOpsLevel::Disabled;

        foreach ($this->bindingRegistry->featureRecordDefaults() as $rule) {
            if (!empty($rule['requiresBulkOps']) && !$bulkEnabled) {
                continue;
            }
            $this->applyRecordDefault(
                $records,
                $features,
                (string) $rule['featureId'],
                (string) $rule['recordId'],
            );
        }

        return $records;
    }

    /**
     * @param array<string, string> $features
     * @param array<string, string> $records
     */
    private function applyRecordDefault(
        array &$records,
        array $features,
        string $featureId,
        string $recordId,
    ): void {
        if (!array_key_exists($recordId, $records)) {
            return;
        }

        $featureLevel = FeatureLevel::tryFromString($features[$featureId] ?? 'disabled');
        if ($featureLevel === FeatureLevel::Disabled) {
            return;
        }

        $target = $featureLevel === FeatureLevel::Manage ? 'readwrite' : 'read';
        $current = $records[$recordId] ?? 'none';

        if ($current === 'none') {
            $records[$recordId] = $target;

            return;
        }

        if ($current === 'read' && $target === 'readwrite') {
            $records[$recordId] = 'readwrite';
        }
    }
}
