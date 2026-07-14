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

use NITSAN\NsT3AF\Access\GroupConfigDeserializer;
use NITSAN\NsT3AF\Access\MatrixScopeCatalog;
use NITSAN\NsT3AF\Domain\Repository\BeGroupRepository;
use NITSAN\NsT3AF\Domain\Repository\GroupSettingsRepository;

final class PermissionMatrixService
{
    public function __construct(
        private readonly BeGroupRepository $beGroupRepository,
        private readonly GroupSettingsRepository $groupSettingsRepository,
        private readonly GroupConfigDeserializer $deserializer,
        private readonly MatrixScopeCatalog $matrixScopeCatalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildMatrix(): array
    {
        $groups = $this->beGroupRepository->findAll();
        $memberCounts = $this->beGroupRepository->memberCountsByGroup();
        $groupsByUid = [];
        foreach ($groups as $group) {
            $groupsByUid[(int) $group['uid']] = (string) ($group['title'] ?? '');
        }

        $rows = [];

        foreach ($groups as $group) {
            $uid = (int) $group['uid'];
            $settings = $this->groupSettingsRepository->findByBeGroupUid($uid);
            $config = $this->deserializer->deserialize($group, $settings);
            $subgroupOf = (int) ($group['subgroup'] ?? 0);

            $rows[] = [
                'uid' => $uid,
                'name' => (string) ($group['title'] ?? ''),
                'memberCount' => $memberCounts[$uid] ?? 0,
                'subgroupOf' => $subgroupOf,
                'parentName' => $subgroupOf > 0 ? ($groupsByUid[$subgroupOf] ?? '') : '',
                'configured' => $config->configured,
                'modules' => $config->modules,
                'features' => $config->features,
                'records' => $config->records,
                'limits' => $config->limits->toArray(),
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                if ($a['configured'] !== $b['configured']) {
                    return $a['configured'] ? -1 : 1;
                }

                return strcasecmp((string) $a['name'], (string) $b['name']);
            },
        );

        $configuredCount = count(array_filter($rows, static fn(array $row): bool => (bool) $row['configured']));
        $scopes = $this->matrixScopeCatalog->buildScopes();

        return [
            'groups' => $rows,
            'configuredCount' => $configuredCount,
            'scopes' => $scopes,
            'tabs' => array_column($scopes, 'id'),
        ];
    }
}
