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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Registry;

use NITSAN\NsT3AF\Contract\ExtensionSettingsFieldRedirectProviderInterface;

final class ExtensionSettingsFieldRedirectRegistry
{
    /**
     * @param iterable<ExtensionSettingsFieldRedirectProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    public function findRedirectHandler(string $extensionKey, string $scope, string $field): ?ExtensionSettingsFieldRedirectProviderInterface
    {
        if ($extensionKey === '' || $scope === '' || $field === '') {
            return null;
        }

        foreach ($this->providers as $provider) {
            if ($provider->getExtensionKey() !== $extensionKey) {
                continue;
            }
            foreach ($provider->getRedirectedFields() as $binding) {
                if (($binding['scope'] ?? '') === $scope && ($binding['field'] ?? '') === $field) {
                    return $provider;
                }
            }
        }

        return null;
    }
}
