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

namespace NITSAN\NsT3AF\Registry;

use NITSAN\NsT3AF\Access\Dto\FeatureAccessBindingsDescriptor;

final class FeatureAccessBindingRegistry
{
    /** @var array<string, FeatureAccessBindingsDescriptor> */
    private array $bindingsByModuleKey;

    public function __construct(
        ?AiAccessCatalogProviderRegistry $providerRegistry = null,
    ) {
        $this->bindingsByModuleKey = [];
        if ($providerRegistry === null) {
            return;
        }

        foreach ($providerRegistry->getFeatureAccessBindingsByModuleKey() as $moduleKey => $descriptor) {
            $this->bindingsByModuleKey[$moduleKey] = $descriptor;
        }
    }

    public function getBindings(string $moduleKey): ?FeatureAccessBindingsDescriptor
    {
        return $this->bindingsByModuleKey[$moduleKey] ?? null;
    }

    /**
     * @return array<string, FeatureAccessBindingsDescriptor>
     */
    public function allBindings(): array
    {
        return $this->bindingsByModuleKey;
    }

    public function resolveTabFeature(string $moduleKey, string $tabIdentifier): ?string
    {
        $bindings = $this->getBindings($moduleKey);
        if ($bindings === null) {
            return null;
        }

        if ($bindings->suiteTabFeatureMap !== []) {
            return $bindings->suiteTabFeatureMap[$tabIdentifier]
                ?? $bindings->defaultTabFeature;
        }

        $tab = strtolower($tabIdentifier);

        return $bindings->tabFeatureMap[$tab] ?? null;
    }

    public function resolveCardFeature(string $moduleKey, string $tabIdentifier, string $cardKey): ?string
    {
        $bindings = $this->getBindings($moduleKey);
        if ($bindings === null) {
            return null;
        }

        if ($bindings->cardFeatureRules !== []) {
            $normalized = strtolower($cardKey);
            $tab = strtolower($tabIdentifier);
            foreach ($bindings->cardFeatureRules as $rule) {
                if ($rule->tab !== $tab) {
                    continue;
                }
                if (str_contains($normalized, strtolower($rule->contains))) {
                    return $rule->feature;
                }
            }

            return $bindings->defaultTabFeature;
        }

        $tab = strtolower($tabIdentifier);

        return $bindings->tabFeatureMap[$tab] ?? null;
    }

    /**
     * @return list<string>
     */
    public function manageableBaseFeatures(): array
    {
        $bases = [];
        foreach ($this->bindingsByModuleKey as $bindings) {
            array_push($bases, ...$bindings->manageableBaseFeatures);
        }

        return array_values(array_unique($bases));
    }

    /**
     * @return list<string>
     */
    public function manageableFullFeatures(): array
    {
        $features = [];
        foreach ($this->bindingsByModuleKey as $bindings) {
            array_push($features, ...$bindings->manageableFullFeatures);
        }

        return array_values(array_unique($features));
    }

    /**
     * @return array<string, list<string>>
     */
    public function recordAreaCatalogIds(): array
    {
        $areas = [];
        foreach ($this->bindingsByModuleKey as $bindings) {
            foreach ($bindings->recordAreaCatalogIds as $area => $catalogIds) {
                $areas[$area] = array_values(array_unique([
                    ...($areas[$area] ?? []),
                    ...$catalogIds,
                ]));
            }
        }

        return $areas;
    }

    /**
     * @return array<string, list<string>>
     */
    public function legacyPermFallback(): array
    {
        $map = [];
        foreach ($this->bindingsByModuleKey as $bindings) {
            foreach ($bindings->legacyPermissionFallbacks as $permBit => $legacyKeys) {
                $map[$permBit] = array_values(array_unique([
                    ...($map[$permBit] ?? []),
                    ...$legacyKeys,
                ]));
            }
        }

        return $map;
    }

    /**
     * @return array<string, list<string>>
     */
    public function legacyDeserializerAliases(): array
    {
        $map = [];
        foreach ($this->bindingsByModuleKey as $bindings) {
            foreach ($bindings->legacyDeserializerAliases as $legacyPermBase => $featureIds) {
                $map[$legacyPermBase] = array_values(array_unique([
                    ...($map[$legacyPermBase] ?? []),
                    ...$featureIds,
                ]));
            }
        }

        return $map;
    }

    /**
     * @return list<array{featureId: string, recordId: string, requiresBulkOps?: bool}>
     */
    public function featureRecordDefaults(): array
    {
        $rules = [];
        foreach ($this->bindingsByModuleKey as $bindings) {
            array_push($rules, ...$bindings->featureRecordDefaults);
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    public function legacyCustomOptionPrefixes(): array
    {
        $prefixes = [];
        foreach ($this->bindingsByModuleKey as $bindings) {
            if ($bindings->legacyCardPermPrefix !== '') {
                $prefixes[] = $bindings->legacyCardPermPrefix;
            }
        }

        return array_values(array_unique($prefixes));
    }
}
