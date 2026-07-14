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

use NITSAN\NsT3AF\Contract\ExtensionSettingsScopeProviderInterface;

final class ExtensionSettingsScopeRegistry
{
    /**
     * @param iterable<ExtensionSettingsScopeProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /**
     * @return list<ExtensionSettingsScopeProviderInterface>
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

    public function supportsExtension(string $extensionKey): bool
    {
        return $this->findProvider($extensionKey) !== null;
    }

    public function isValidScope(string $extensionKey, string $scope): bool
    {
        $provider = $this->findProvider($extensionKey);
        if ($provider === null || $scope === '') {
            return false;
        }

        return in_array($scope, $provider->getAllowedScopes(), true);
    }

    /**
     * @return list<string>
     */
    public function getManagedExtensionKeys(): array
    {
        $keys = [];
        foreach ($this->getAvailableProviders() as $provider) {
            $keys[] = $provider->getExtensionKey();
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    public function resolveCategoriesForScope(string $extensionKey, string $scope): array
    {
        $provider = $this->findProvider($extensionKey);
        if ($provider === null) {
            return [];
        }

        $composite = $provider->getCompositeScopeCategories()[$scope] ?? null;
        if (is_array($composite) && $composite !== []) {
            return $composite;
        }

        return [$scope];
    }

    public function hasPaletteScope(string $extensionKey, string $scope): bool
    {
        $provider = $this->findProvider($extensionKey);

        return $provider !== null && isset($provider->getPaletteScopes()[$scope]);
    }

    /**
     * @return list<array{id: string, label: string, scope: string}>
     */
    public function getPaletteDefinitions(string $extensionKey, string $scope): array
    {
        $provider = $this->findProvider($extensionKey);
        if ($provider === null) {
            return [];
        }

        return $provider->getPaletteScopes()[$scope] ?? [];
    }

    /**
     * @return list<array{category: string, fields: list<string>}>
     */
    public function getFieldFilterDefinitions(string $extensionKey, string $scope): array
    {
        $provider = $this->findProvider($extensionKey);
        if ($provider === null) {
            return [];
        }

        return $provider->getFieldFilterScopes()[$scope] ?? [];
    }

    public function getSaveSuccessMessageKey(string $extensionKey): string
    {
        return $this->findProvider($extensionKey)?->getSaveSuccessMessageKey()
            ?? 'module.aiFeatures.saveSuccessMessage';
    }

    public function getUnavailableLabelKey(string $extensionKey): string
    {
        return $this->findProvider($extensionKey)?->getUnavailableLabelKey()
            ?? 'module.aiFeatures.errorExtensionMissing';
    }

    private function findProvider(string $extensionKey): ?ExtensionSettingsScopeProviderInterface
    {
        foreach ($this->getAvailableProviders() as $provider) {
            if ($provider->getExtensionKey() === $extensionKey) {
                return $provider;
            }
        }

        return null;
    }
}
