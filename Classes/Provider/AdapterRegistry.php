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

namespace NITSAN\NsT3AF\Provider;

use NITSAN\NsT3AF\Exception\UnknownAdapterException;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;

/**
 * Central lookup for installed adapter implementations, indexed by
 * {@see AdapterInterface::getType()}.
 *
 * Wired through Symfony's `!tagged_iterator nst3af.adapter` in
 * `Configuration/Services.yaml` — every service implementing
 * {@see AdapterInterface} is auto-tagged via the `_instanceof` rule.
 *
 * @internal Not part of the semver-stable API. Child extensions should consume
 *           {@see \NITSAN\NsT3AF\Api\AiServiceInterface} (Phase 3).
 */
final class AdapterRegistry
{
    /** @var array<string, AdapterInterface> */
    private array $adapters = [];

    /**
     * @param iterable<AdapterInterface> $adapters Tagged services collected via Symfony tagged_iterator.
     */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->add($adapter);
        }
    }

    public function add(AdapterInterface $adapter): void
    {
        $this->adapters[$adapter->getType()] = $adapter;
    }

    public function has(string $type): bool
    {
        return isset($this->adapters[$type]);
    }

    /**
     * @throws UnknownAdapterException When `$type` is not registered.
     */
    public function get(string $type): AdapterInterface
    {
        if (!isset($this->adapters[$type])) {
            throw new UnknownAdapterException(sprintf('No AI adapter registered for type "%s".', $type));
        }

        return $this->adapters[$type];
    }

    /**
     * @return array<string, AdapterInterface>
     */
    public function all(): array
    {
        return $this->adapters;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->adapters);
    }
}
