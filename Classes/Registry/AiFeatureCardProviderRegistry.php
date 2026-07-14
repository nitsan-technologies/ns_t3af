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

use NITSAN\NsT3AF\Access\BackendPermissionCheck;
use NITSAN\NsT3AF\Contract\AiFeatureCardProviderInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class AiFeatureCardProviderRegistry
{
    /**
     * @param iterable<AiFeatureCardProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /**
     * @return list<AiFeatureCardProviderInterface>
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
     * Extension keys for the AI Features filter dropdown (all loaded providers, before module ACL).
     *
     * @return list<string>
     */
    public function collectFilterExtensionKeys(): array
    {
        $keys = [];
        foreach ($this->getAvailableProviders() as $provider) {
            foreach ($provider->getFeatureCards() as $descriptor) {
                $displayKey = $descriptor->displayExtKey ?? '';
                if ($displayKey !== '') {
                    $keys[] = $displayKey;
                    continue;
                }
                if ($descriptor->extKey !== '') {
                    $keys[] = $descriptor->extKey;
                }
            }
        }

        return array_values(array_unique(array_filter(
            $keys,
            static fn(string $key): bool => $key !== '',
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildCatalog(?BackendUserAuthentication $user = null): array
    {
        $cards = [];
        foreach ($this->getAvailableProviders() as $provider) {
            foreach ($provider->getFeatureCards() as $descriptor) {
                $cards[] = [
                    'descriptor' => $descriptor,
                    'sortPriority' => $descriptor->sortPriority,
                    'requiredBackendModule' => $descriptor->requiredBackendModule,
                ];
            }
        }

        usort(
            $cards,
            static fn(array $a, array $b): int => $a['sortPriority'] <=> $b['sortPriority'],
        );

        $catalog = [];
        foreach ($cards as $entry) {
            if (!$this->isGrantedForUser($entry['requiredBackendModule'], $user)) {
                continue;
            }
            $catalog[] = $entry['descriptor']->toArray();
        }

        return $catalog;
    }

    private function isGrantedForUser(?string $requiredBackendModule, ?BackendUserAuthentication $user): bool
    {
        if ($requiredBackendModule === null || $requiredBackendModule === '') {
            return true;
        }
        if ($user === null || $user->isAdmin()) {
            return true;
        }

        return BackendPermissionCheck::isGranted($user, 'modules', $requiredBackendModule);
    }
}
