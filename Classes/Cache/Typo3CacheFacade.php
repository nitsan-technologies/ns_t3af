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

namespace NITSAN\NsT3AF\Cache;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * @internal
 */
final class Typo3CacheFacade implements CacheFacadeInterface
{
    public function __construct(
        private readonly FrontendInterface $cache,
    ) {}

    public function get(string $key): mixed
    {
        return $this->cache->get($key);
    }

    public function set(string $key, mixed $value, array $tags = [], ?int $lifetimeSeconds = null): void
    {
        $this->cache->set($key, $value, $tags, $lifetimeSeconds);
    }

    public function remove(string $key): void
    {
        $this->cache->remove($key);
    }

    public function flush(): void
    {
        $this->cache->flush();
    }
}
