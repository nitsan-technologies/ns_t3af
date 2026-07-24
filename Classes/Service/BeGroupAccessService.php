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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Access\BeGroupPermissionMerger;
use NITSAN\NsT3AF\Access\Dto\GroupConfig;
use NITSAN\NsT3AF\Access\Dto\LimitsConfig;
use NITSAN\NsT3AF\Access\GroupConfigDeserializer;
use NITSAN\NsT3AF\Access\GroupConfigNormalizer;
use NITSAN\NsT3AF\Access\GroupConfigSerializer;
use NITSAN\NsT3AF\Access\ModuleAccessCatalog;
use NITSAN\NsT3AF\Domain\Repository\BeGroupRepository;
use NITSAN\NsT3AF\Domain\Repository\GroupSettingsRepository;

final class BeGroupAccessService
{
    public function __construct(
        private readonly BeGroupRepository $beGroupRepository,
        private readonly GroupSettingsRepository $groupSettingsRepository,
        private readonly GroupConfigSerializer $serializer,
        private readonly GroupConfigDeserializer $deserializer,
        private readonly BeGroupPermissionMerger $permissionMerger,
        private readonly GroupConfigNormalizer $configNormalizer,
        private readonly BeGroupScopeResolver $scopeResolver,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listGroupsSummary(): array
    {
        $groups = $this->beGroupRepository->findAll();
        $memberCounts = $this->beGroupRepository->memberCountsByGroup();
        $groupsByUid = [];
        foreach ($groups as $group) {
            $groupsByUid[(int) $group['uid']] = $group['title'];
        }

        $out = [];
        foreach ($groups as $group) {
            $uid = (int) $group['uid'];
            $settings = $this->groupSettingsRepository->findByBeGroupUid($uid);
            $config = $this->deserializer->deserialize($group, $settings);
            $subgroupOf = (int) ($group['subgroup'] ?? 0);

            $out[] = [
                'uid' => $uid,
                'name' => (string) ($group['title'] ?? ''),
                'memberCount' => $memberCounts[$uid] ?? 0,
                'subgroupOf' => $subgroupOf,
                'parentName' => $subgroupOf > 0 ? ($groupsByUid[$subgroupOf] ?? '') : '',
                'configured' => $config->configured,
                'moduleCount' => count(array_filter($config->modules)),
                'status' => $this->resolveStatus($config),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getGroupDetail(int $uid): ?array
    {
        $group = $this->beGroupRepository->findByUid($uid);
        if ($group === null) {
            return null;
        }

        $settings = $this->groupSettingsRepository->findByBeGroupUid($uid);
        $config = $this->deserializer->deserialize($group, $settings);
        $memberCounts = $this->beGroupRepository->memberCountsByGroup();

        return [
            'uid' => $uid,
            'name' => (string) ($group['title'] ?? ''),
            'memberCount' => $memberCounts[$uid] ?? 0,
            'dbMountpoints' => (string) ($group['db_mountpoints'] ?? ''),
            'allowedLanguages' => (string) ($group['allowed_languages'] ?? ''),
            'scope' => $this->scopeResolver->resolve($group),
            'config' => $config->toArray(),
            'healthAlerts' => $this->buildHealthAlerts($config, $group),
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
    public function previewConfig(GroupConfig $config, ?int $beGroupUid = null): array
    {
        $config = $this->configNormalizer->normalize($config);
        $serialized = $this->serializer->serialize($config);

        if ($beGroupUid === null || $beGroupUid <= 0) {
            return $this->serializedToPreviewLists($serialized);
        }

        $group = $this->beGroupRepository->findByUid($beGroupUid);
        if ($group === null) {
            return $this->serializedToPreviewLists($serialized);
        }

        $merged = $this->permissionMerger->merge($group, $serialized);

        return [
            'groupMods' => $this->parseCsvField($merged['groupMods']),
            'customOptions' => $this->parseCsvField($merged['custom_options']),
            'tablesSelect' => $this->parseCsvField($merged['tables_select']),
            'tablesModify' => $this->parseCsvField($merged['tables_modify']),
        ];
    }

    /**
     * @param array<string, mixed> $serialized
     * @return array{
     *     groupMods: list<string>,
     *     customOptions: list<string>,
     *     tablesSelect: list<string>,
     *     tablesModify: list<string>
     * }
     */
    private function serializedToPreviewLists(array $serialized): array
    {
        return [
            'groupMods' => $serialized['groupMods'],
            'customOptions' => $serialized['customOptions'],
            'tablesSelect' => $serialized['tablesSelect'],
            'tablesModify' => $serialized['tablesModify'],
        ];
    }

    public function applyConfig(int $beGroupUid, GroupConfig $config): void
    {
        $group = $this->beGroupRepository->findByUid($beGroupUid);
        if ($group === null) {
            return;
        }

        $config = $this->configNormalizer->normalize($config);
        $serialized = $this->serializer->serialize($config);
        $limits = $serialized['limits'];
        assert($limits instanceof LimitsConfig);

        $merged = $this->permissionMerger->merge($group, $serialized);

        $this->beGroupRepository->update($beGroupUid, $merged);

        $this->groupSettingsRepository->upsertForBeGroup($beGroupUid, [
            'limits_json' => json_encode($limits->toArray(), JSON_THROW_ON_ERROR),
            'credit_cap_monthly' => $limits->creditCapEnabled ? $limits->creditCapMonthly : 0,
            'daily_request_cap' => $limits->dailyRequestCapEnabled ? $limits->dailyRequestCap : 0,
            'bulk_page_limit' => $limits->bulkPageLimitEnabled ? $limits->bulkPageLimit : 0,
            'scheduler_batch_limit' => $limits->schedulerBatchLimitEnabled ? $limits->schedulerBatchLimit : 0,
            'configured' => 1,
            'configured_at' => time(),
        ]);
    }

    /**
     * @param array<string, mixed> $beGroupRow
     * @return list<string>
     */
    private function buildHealthAlerts(GroupConfig $config, array $beGroupRow = []): array
    {
        $alerts = [];
        $enabledCount = count(array_filter($config->modules));
        if ($config->configured && $enabledCount > 0) {
            $groupMods = $this->parseCsvField($beGroupRow['groupMods'] ?? '');
            foreach (ModuleAccessCatalog::SHELL_GROUP_MODS as $shellMod) {
                if (!in_array($shellMod, $groupMods, true)) {
                    $alerts[] = 'This group is missing AI Foundation backend module access. Re-apply the wizard configuration so editors can open the AI Foundation menu.';
                    break;
                }
            }
        }
        if (!empty($config->modules['t3ai'])) {
            $promptAccess = $config->records['aiPromptStorage'] ?? 'none';
            if ($promptAccess === 'none') {
                $alerts[] = 'AI Assistant module is enabled but prompt templates have no record access. The prompt library may show an access error.';
            }
        }
        if (!empty($config->modules['t3aa'])) {
            $t3aaPromptAccess = $config->records['aiPromptStorage'] ?? 'none';
            if ($t3aaPromptAccess === 'none') {
                $alerts[] = 'AI Accessibility module is enabled but AI Accessibility prompt templates have no record access. The AI Prompts library may show an access error for AI Accessibility categories.';
            }
        }
        return $alerts;
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

    private function resolveStatus(GroupConfig $config): string
    {
        if (!$config->configured) {
            return 'unconfigured';
        }
        $moduleCount = count(array_filter($config->modules));
        if ($moduleCount === 0) {
            return 'empty';
        }
        return 'configured';
    }
}
