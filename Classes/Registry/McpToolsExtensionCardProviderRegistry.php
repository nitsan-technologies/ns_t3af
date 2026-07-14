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

use NITSAN\NsT3AF\Contract\McpToolsExtensionCardProviderInterface;

final class McpToolsExtensionCardProviderRegistry
{
    /**
     * @param iterable<McpToolsExtensionCardProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /**
     * @return list<McpToolsExtensionCardProviderInterface>
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

    public function hasProviderForExtension(string $extensionKey): bool
    {
        foreach ($this->getAvailableProviders() as $provider) {
            if ($provider->getExtensionKey() === $extensionKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merges tagged card providers with legacy EXTCONF catalog entries.
     * Provider descriptors win on key conflicts; legacy fills gaps.
     *
     * @return array<string, array<string, mixed>>
     */
    public function buildExtensionConfigs(): array
    {
        $configs = $this->getLegacyExtensionConfigs();

        foreach ($this->getAvailableProviders() as $provider) {
            $extensionKey = $provider->getExtensionKey();
            $descriptor = $provider->getCardDescriptor();
            $configs[$extensionKey] = array_merge(
                $configs[$extensionKey] ?? [],
                $descriptor->toArray(),
                ['extensionKey' => $extensionKey],
            );
        }

        return $configs;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getLegacyExtensionConfigs(): array
    {
        $typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3ConfVars)) {
            return [];
        }

        $extConf = $typo3ConfVars['EXTCONF'] ?? [];
        if (!is_array($extConf)) {
            return [];
        }

        $nst3afExtConf = $extConf['ns_t3af'] ?? [];
        if (!is_array($nst3afExtConf)) {
            return [];
        }

        $extensions = $nst3afExtConf['extensions'] ?? [];

        return is_array($extensions) ? $extensions : [];
    }
}
