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

namespace NITSAN\NsT3AF\Agent\Entitlement;

use NITSAN\NsT3AF\Access\ExtensionAvailability;
use NITSAN\NsT3AF\Contract\ExtensionOperationalStatusInterface;

/**
 * Mirrors each child extension's operational self-report (R16).
 *
 * Implements no licence lookup of its own — it asks tagged providers only.
 */
final readonly class EntitlementResolver
{
    private const FOUNDATION_EXTENSION_KEY = 'ns_t3af';

    /**
     * @param iterable<ExtensionOperationalStatusInterface> $operationalStatusProviders
     */
    public function __construct(
        private iterable $operationalStatusProviders,
        private ExtensionAvailability $extensionAvailability,
    ) {}

    /**
     * Whether tools owned by the given extension are executable.
     *
     * {@code null} owner means TYPO3 core tools (always executable).
     */
    public function isExecutable(?string $ownerExtensionKey): bool
    {
        if ($ownerExtensionKey === null || $ownerExtensionKey === '') {
            return true;
        }

        if ($ownerExtensionKey === self::FOUNDATION_EXTENSION_KEY) {
            return true;
        }

        if (!$this->extensionAvailability->isLoaded($ownerExtensionKey)) {
            return false;
        }

        $status = $this->resolveStatus($ownerExtensionKey);
        if ($status === null) {
            // Grace: child has no operational self-report yet (Q-D, one minor version).
            return true;
        }

        return $status->isOperational();
    }

    public function getToolCount(?string $ownerExtensionKey): int
    {
        if ($ownerExtensionKey === null || $ownerExtensionKey === '') {
            return 0;
        }

        return $this->resolveStatus($ownerExtensionKey)?->toolCount() ?? 0;
    }

    public function resolveStatus(string $extensionKey): ?ExtensionOperationalStatusInterface
    {
        foreach ($this->operationalStatusProviders as $provider) {
            if ($provider->extensionKey() === $extensionKey) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @return array<string, ExtensionOperationalStatusInterface>
     */
    public function getAllStatuses(): array
    {
        $statuses = [];
        foreach ($this->operationalStatusProviders as $provider) {
            $statuses[$provider->extensionKey()] = $provider;
        }

        return $statuses;
    }
}
