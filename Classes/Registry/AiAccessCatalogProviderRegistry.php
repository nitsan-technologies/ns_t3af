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
use NITSAN\NsT3AF\Access\Dto\FeaturePermissionDescriptor;
use NITSAN\NsT3AF\Access\Dto\ModuleAccessDescriptor;
use NITSAN\NsT3AF\Access\Dto\RecordPermissionDescriptor;
use NITSAN\NsT3AF\Contract\AiAccessCatalogProviderInterface;

final class AiAccessCatalogProviderRegistry
{
    /**
     * @param iterable<AiAccessCatalogProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /**
     * @return list<AiAccessCatalogProviderInterface>
     */
    public function getAvailableProviders(): array
    {
        $available = [];
        foreach ($this->providers as $provider) {
            if ($provider->isAvailable()) {
                $available[] = $provider;
            }
        }

        return $available;
    }

    /**
     * @return array<string, ModuleAccessDescriptor>
     */
    public function getModuleAccessByKey(): array
    {
        $modules = [];
        foreach ($this->getAvailableProviders() as $provider) {
            $module = $provider->getModuleAccess();
            if ($module === null) {
                continue;
            }
            $modules[$provider->getCatalogModuleKey()] = $module;
        }

        return $modules;
    }

    /**
     * @return list<FeaturePermissionDescriptor>
     */
    public function getFeaturePermissions(): array
    {
        $rows = [];
        foreach ($this->getAvailableProviders() as $provider) {
            array_push($rows, ...$provider->getFeaturePermissions());
        }

        return $rows;
    }

    /**
     * @return list<RecordPermissionDescriptor>
     */
    public function getRecordPermissions(): array
    {
        $rows = [];
        foreach ($this->getAvailableProviders() as $provider) {
            array_push($rows, ...$provider->getRecordPermissions());
        }

        return $rows;
    }

    /**
     * @return array<string, FeatureAccessBindingsDescriptor>
     */
    public function getFeatureAccessBindingsByModuleKey(): array
    {
        $bindings = [];
        foreach ($this->getAvailableProviders() as $provider) {
            $descriptor = $provider->getFeatureAccessBindings();
            if ($descriptor === null) {
                continue;
            }
            $existing = $bindings[$descriptor->moduleKey] ?? null;
            if ($existing !== null && $existing->suiteTabFeatureMap !== [] && $descriptor->suiteTabFeatureMap === []) {
                continue;
            }
            $bindings[$descriptor->moduleKey] = $descriptor;
        }

        return $bindings;
    }
}
