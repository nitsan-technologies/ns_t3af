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

final class FeaturePermissionCatalog
{
    public function __construct(
        private readonly ExtensionAvailability $extensionAvailability = new ExtensionAvailability(),
        private readonly ?AiAccessCatalogProviderRegistry $accessProviderRegistry = null,
    ) {}

    public const PERM_PREFIX = 'T3Ai';

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     description: string,
     *     permBase: string,
     *     relevantModules: list<string>,
     *     group: string,
     *     type: string,
     *     extension: string|null
     * }>
     */
    public function all(): array
    {
        $rows = $this->providerFeatures();

        return array_values(array_filter(
            $rows,
            fn(array $row): bool => $this->extensionAvailability->isLoaded($row['extension']),
        ));
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
     *     description: string,
     *     permBase: string,
     *     relevantModules: list<string>,
     *     group: string,
     *     type: string,
     *     extension: string|null
     * }>
     */
    private function providerFeatures(): array
    {
        if ($this->accessProviderRegistry === null) {
            return [];
        }

        $rows = [];
        foreach ($this->accessProviderRegistry->getFeaturePermissions() as $descriptor) {
            $rows[] = $descriptor->toArray();
        }

        return $rows;
    }
}
