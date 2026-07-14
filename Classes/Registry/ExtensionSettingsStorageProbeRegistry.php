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

use NITSAN\NsT3AF\Contract\ExtensionSettingsStorageProbeProviderInterface;

final class ExtensionSettingsStorageProbeRegistry
{
    /**
     * @param iterable<ExtensionSettingsStorageProbeProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /**
     * @return list<ExtensionSettingsStorageProbeProviderInterface>
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
     * @return list<string>
     */
    public function probeKeysForExtension(string $extensionKey): array
    {
        foreach ($this->getAvailableProviders() as $provider) {
            if ($provider->getExtensionKey() === $extensionKey) {
                return $provider->getProbeKeys();
            }
        }

        return [];
    }
}
