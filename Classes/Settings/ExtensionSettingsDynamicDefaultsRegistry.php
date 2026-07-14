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

namespace NITSAN\NsT3AF\Settings;

use NITSAN\NsT3AF\Contract\ExtensionSettingsDynamicDefaultsProviderInterface;

/**
 * @internal
 */
final class ExtensionSettingsDynamicDefaultsRegistry
{
    /**
     * @var array<string, ExtensionSettingsDynamicDefaultsProviderInterface>
     */
    private array $providersByExtensionKey = [];

    /**
     * @param iterable<ExtensionSettingsDynamicDefaultsProviderInterface> $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->providersByExtensionKey[$provider->getExtensionKey()] = $provider;
        }
    }

    /**
     * @return array<string, string>
     */
    public function getForExtension(string $extensionKey, int $storagePid = 0): array
    {
        $provider = $this->providersByExtensionKey[$extensionKey] ?? null;
        if (!$provider instanceof ExtensionSettingsDynamicDefaultsProviderInterface) {
            return [];
        }

        return $provider->getDynamicDefaults($storagePid);
    }
}
