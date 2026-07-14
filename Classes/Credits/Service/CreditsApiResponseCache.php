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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Cache\CacheFacadeInterface;
use NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface;

/**
 * Short-lived TYPO3 cache for T3Planet Credits HTTP payloads (dashboard: Balance, CurrentPlan, Products, Features).
 *
 * Keys are scoped by endpoint label, site domain, and bearer token so pools never leak across installs/tokens.
 *
 * @internal
 */
final class CreditsApiResponseCache implements CreditsApiResponseCacheInterface
{
    public const SCOPE_BALANCE = 'balance';

    public const SCOPE_CURRENT_PLAN = 'current_plan';

    public const SCOPE_PRODUCTS = 'products';

    public const SCOPE_FEATURES = 'features';

    /** Match server Balance / CurrentPlan ETag guidance (~60s). */
    public const TTL_BALANCE = 60;

    public const TTL_CURRENT_PLAN = 60;

    /** Product catalog changes rarely; still revalidate via DB/ETag after TTL. */
    public const TTL_PRODUCTS = 300;

    /** Feature catalog (~server 1h ETag); local TTL avoids hammering on every backend page load. */
    public const TTL_FEATURES = 1800;

    public static function scopeProducts(string $redirectTo): string
    {
        $redirectTo = trim($redirectTo);

        return $redirectTo === ''
            ? self::SCOPE_PRODUCTS
            : self::SCOPE_PRODUCTS . '_' . substr(hash('sha256', $redirectTo), 0, 16);
    }

    public function __construct(
        private readonly CacheFacadeInterface $cache,
    ) {}

    /**
     * @return array<string, mixed>|null Cached JSON-decoded-style payload, or null on miss.
     */
    public function get(string $scope, string $domain, string $bearerToken): ?array
    {
        $entry = $this->cache->get($this->entryIdentifier($scope, $domain, $bearerToken));
        if (!is_array($entry)) {
            return null;
        }

        /* @var array<string, mixed> $entry */
        return $entry;
    }

    /**
     * @param array<string, mixed> $payload Response body to reuse until TTL expires.
     */
    public function set(
        string $scope,
        string $domain,
        string $bearerToken,
        array $payload,
        int $lifetimeSeconds,
    ): void {
        $this->cache->set(
            $this->entryIdentifier($scope, $domain, $bearerToken),
            $payload,
            ['nst3af', 'nst3af_credits'],
            max(1, $lifetimeSeconds),
        );
    }

    /**
     * Drop all cached T3Planet dashboard API payloads (e.g. after token invalidation).
     */
    public function flush(): void
    {
        $this->cache->flush();
    }

    private function entryIdentifier(string $scope, string $domain, string $bearerToken): string
    {
        $token = trim($bearerToken);
        $domain = trim($domain);

        return 'v1_' . $scope . '_' . hash('sha256', $scope . '|' . $domain . '|' . $token);
    }
}
