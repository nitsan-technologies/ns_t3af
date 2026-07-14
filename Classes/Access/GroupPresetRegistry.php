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
use NITSAN\NsT3AF\Access\Enum\LoggingPolicy;
use NITSAN\NsT3AF\Access\Enum\RecordAccess;
use NITSAN\NsT3AF\Contract\GroupPresetContributorInterface;

/**
 * Role templates for wizard Step 0. Values may be tuned later without UI changes.
 */
final class GroupPresetRegistry
{
    /**
     * @param iterable<GroupPresetContributorInterface> $contributors
     */
    public function __construct(
        private readonly RecordPermissionCatalog $recordCatalog,
        private readonly ModuleAccessCatalog $moduleCatalog,
        private readonly DefaultGroupConfigFactory $defaultGroupConfigFactory,
        private readonly iterable $contributors = [],
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function allForBootstrap(): array
    {
        $out = [];
        foreach ($this->presetMeta() as $id => $meta) {
            $config = $this->build($id);
            $out[] = array_merge($meta, [
                'id' => $id,
                'moduleCount' => count(array_filter($config->modules)),
                'featureCount' => count(array_filter(
                    $config->features,
                    static fn(string $v): bool => $v !== FeatureLevel::Disabled->value && $v !== BulkOpsLevel::Disabled->value,
                )),
                'config' => $config->toArray(),
            ]);
        }
        return $out;
    }

    public function build(string $presetId): GroupConfig
    {
        return match ($presetId) {
            'consumer' => $this->consumer(),
            'editor' => $this->editor(),
            'manager' => $this->manager(),
            'admin' => $this->admin(),
            default => $this->defaultGroupConfigFactory->create(),
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function presetMeta(): array
    {
        return [
            'consumer' => [
                'label' => 'AI Consumer',
                'tagline' => 'Use AI tools, no configuration access',
                'badge' => 'Read-use only',
                'badgeTone' => 'info',
            ],
            'editor' => [
                'label' => 'AI Editor',
                'tagline' => 'Create & manage AI content across features',
                'badge' => 'Content focused',
                'badgeTone' => 'primary',
            ],
            'manager' => [
                'label' => 'AI Manager',
                'tagline' => 'Manage team AI usage, prompts and analytics',
                'badge' => 'Team oversight',
                'badgeTone' => 'warning',
            ],
            'admin' => [
                'label' => 'AI Administrator',
                'tagline' => 'Full access — providers, MCP, safety and all extensions',
                'badge' => 'Full access',
                'badgeTone' => 'success',
            ],
        ];
    }

    private function consumer(): GroupConfig
    {
        return $this->finalizePreset('consumer', new GroupConfig(
            modules: $this->foundationModules(['aiPrompts']),
            features: $this->featureOverrides([]),
            records: $this->records([
                'aiPromptStorage' => RecordAccess::Read,
                'brandProfiles' => RecordAccess::Read,
            ]),
            limits: new LimitsConfig(
                creditCapEnabled: true,
                creditCapMonthly: 2000,
                dailyRequestCapEnabled: true,
                dailyRequestCap: 50,
                workspaceEnforcement: true,
                loggingPolicy: LoggingPolicy::Errors,
            ),
        ));
    }

    private function editor(): GroupConfig
    {
        return $this->finalizePreset('editor', new GroupConfig(
            modules: $this->foundationModules(['aiUsage', 'aiPrompts']),
            features: $this->featureOverrides([]),
            records: $this->records([
                'aiPromptStorage' => RecordAccess::ReadWrite,
                'usageRequestLog' => RecordAccess::Read,
                'brandProfiles' => RecordAccess::ReadWrite,
            ]),
            limits: new LimitsConfig(
                creditCapEnabled: true,
                creditCapMonthly: 10000,
                workspaceEnforcement: true,
                loggingPolicy: LoggingPolicy::Always,
            ),
        ));
    }

    private function manager(): GroupConfig
    {
        return $this->finalizePreset('manager', new GroupConfig(
            modules: $this->foundationModules(['aiUsage', 'aiPrompts', 'aiFeatures']),
            features: $this->featureOverrides([]),
            records: $this->records([
                'aiPromptStorage' => RecordAccess::ReadWrite,
                'usageRequestLog' => RecordAccess::ReadWrite,
                'brandProfiles' => RecordAccess::ReadWrite,
                'groupSettings' => RecordAccess::Read,
            ]),
            limits: new LimitsConfig(
                workspaceEnforcement: true,
                loggingPolicy: LoggingPolicy::Always,
                logRetentionDays: 60,
                piiMasking: true,
            ),
        ));
    }

    private function admin(): GroupConfig
    {
        $modules = $this->defaultGroupConfigFactory->defaultModules();
        foreach (array_keys($modules) as $key) {
            if ($this->moduleCatalog->isExtensionLoaded($key) || !isset($this->moduleCatalog->childModules()[$key])) {
                $modules[$key] = true;
            }
        }

        $features = $this->defaultGroupConfigFactory->defaultFeatures();
        foreach (array_keys($features) as $fid) {
            if ($fid === 'bulkOps') {
                $features[$fid] = BulkOpsLevel::Any->value;
            } else {
                $features[$fid] = FeatureLevel::Manage->value;
            }
        }

        $records = $this->defaultGroupConfigFactory->defaultRecords();
        foreach ($this->recordCatalog->all() as $row) {
            $records[$row['id']] = RecordAccess::ReadWrite->value;
        }

        return new GroupConfig(
            modules: $modules,
            features: $features,
            records: $records,
            limits: new LimitsConfig(
                loggingPolicy: LoggingPolicy::Always,
                logRetentionDays: 90,
                piiMasking: true,
            ),
        );
    }

    /**
     * @param list<string> $foundationModuleKeys
     * @return array<string, bool>
     */
    private function foundationModules(array $foundationModuleKeys): array
    {
        $modules = $this->defaultGroupConfigFactory->defaultModules();
        $this->enableModules($modules, $foundationModuleKeys);

        return $modules;
    }

    private function finalizePreset(string $presetId, GroupConfig $config): GroupConfig
    {
        $modules = $config->modules;
        $features = $config->features;
        $records = $config->records;

        foreach ($this->contributors as $contributor) {
            if (!$contributor->isAvailable()) {
                continue;
            }
            $fragment = $contributor->contribute($presetId);
            if (isset($fragment['modules'])) {
                $this->enableModules($modules, $fragment['modules']);
            }
            if (isset($fragment['features'])) {
                $features = $this->featureOverrides($fragment['features'], $features);
            }
            if (isset($fragment['records'])) {
                $records = $this->records($fragment['records'], $records);
            }
        }

        return new GroupConfig(
            modules: $modules,
            features: $features,
            records: $records,
            limits: $config->limits,
        );
    }

    /**
     * @param array<string, bool> $modules
     * @param list<string> $keys
     */
    private function enableModules(array &$modules, array $keys): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $modules)) {
                $modules[$key] = true;
            }
        }
    }

    /**
     * @param array<string, string> $overrides
     * @param array<string, string>|null $base
     * @return array<string, string>
     */
    private function featureOverrides(array $overrides, ?array $base = null): array
    {
        $features = $base ?? $this->defaultGroupConfigFactory->defaultFeatures();
        foreach ($overrides as $id => $level) {
            if (array_key_exists($id, $features)) {
                $features[$id] = $level;
            }
        }

        return $features;
    }

    /**
     * @param array<string, RecordAccess> $overrides
     * @param array<string, string>|null $base
     * @return array<string, string>
     */
    private function records(array $overrides, ?array $base = null): array
    {
        $records = $base ?? $this->defaultGroupConfigFactory->defaultRecords();
        foreach ($overrides as $id => $access) {
            if (array_key_exists($id, $records)) {
                $records[$id] = $access->value;
            }
        }

        return $records;
    }
}
