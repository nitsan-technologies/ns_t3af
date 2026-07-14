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

use NITSAN\NsT3AF\Contract\ExtensionSettingsSecretProviderInterface;

/**
 * Lists extension settings keys that must be encrypted at rest.
 *
 * @internal
 */
final class ExtensionSettingsSecretRegistry
{
    /**
     * @var array<string, list<string>>|null
     */
    private ?array $map = null;

    /**
     * @param iterable<ExtensionSettingsSecretProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers = [],
    ) {}

    public function isSecret(string $extensionKey, string $fieldName): bool
    {
        if ($fieldName === '') {
            return false;
        }

        return in_array($fieldName, $this->secretFields($extensionKey), true);
    }

    /**
     * @return list<string>
     */
    public function secretFields(string $extensionKey): array
    {
        return $this->map()[$extensionKey] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        /** @var array<string, list<string>> $map */
        $map = [];

        $path = dirname(__DIR__, 2) . '/Configuration/ExtensionSettings/secrets.php';
        if (is_file($path)) {
            /** @var array<string, list<string>> $foundation */
            $foundation = require $path;
            foreach ($foundation as $extensionKey => $fields) {
                $map[$extensionKey] = array_values(array_unique(array_merge($map[$extensionKey] ?? [], $fields)));
            }
        }

        foreach ($this->providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            $extensionKey = $provider->getExtensionKey();
            $map[$extensionKey] = array_values(array_unique(array_merge(
                $map[$extensionKey] ?? [],
                $provider->getSecretFieldNames(),
            )));
        }

        $this->map = $map;

        return $this->map;
    }
}
