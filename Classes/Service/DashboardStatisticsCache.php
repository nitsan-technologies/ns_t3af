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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Cache\CacheFacadeInterface;
use NITSAN\NsT3AF\Domain\RequestLog\RequestLogProviderScope;

/**
 * Short-lived TYPO3 cache for AI Foundation dashboard statistics (15 minutes).
 *
 * @internal
 */
final class DashboardStatisticsCache
{
    public const TTL_SECONDS = 900;

    private const TAG = 'nst3af_dashboard';

    public function __construct(
        private readonly CacheFacadeInterface $cache,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getAnalytics(
        array $period,
        RequestLogProviderScope $scope,
        int $storagePid,
    ): ?array {
        $entry = $this->cache->get($this->analyticsKey($period, $scope, $storagePid));

        return is_array($entry) ? $entry : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setAnalytics(
        array $period,
        RequestLogProviderScope $scope,
        int $storagePid,
        array $payload,
    ): void {
        $this->cache->set(
            $this->analyticsKey($period, $scope, $storagePid),
            $payload,
            [self::TAG],
            self::TTL_SECONDS,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTrends(
        array $period,
        RequestLogProviderScope $scope,
        int $storagePid,
        ?array $providerUids,
    ): ?array {
        $entry = $this->cache->get($this->trendsKey($period, $scope, $storagePid, $providerUids));

        return is_array($entry) ? $entry : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setTrends(
        array $period,
        RequestLogProviderScope $scope,
        int $storagePid,
        ?array $providerUids,
        array $payload,
    ): void {
        $this->cache->set(
            $this->trendsKey($period, $scope, $storagePid, $providerUids),
            $payload,
            [self::TAG],
            self::TTL_SECONDS,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExtensionHealth(int $windowDays): ?array
    {
        $entry = $this->cache->get($this->extensionHealthKey($windowDays));

        return is_array($entry) ? $entry : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setExtensionHealth(int $windowDays, array $payload): void
    {
        $this->cache->set(
            $this->extensionHealthKey($windowDays),
            $payload,
            [self::TAG],
            self::TTL_SECONDS,
        );
    }

    /**
     * @return array{total:int,active:int,failing:int}|null
     */
    public function getScheduledTasks(): ?array
    {
        $entry = $this->cache->get('scheduled_tasks');

        return is_array($entry) ? $entry : null;
    }

    /**
     * @param array{total:int,active:int,failing:int} $payload
     */
    public function setScheduledTasks(array $payload): void
    {
        $this->cache->set('scheduled_tasks', $payload, [self::TAG], self::TTL_SECONDS);
    }

    /**
     * @param array{fromTimestamp:int,toTimestamp:int,days:int,preset?:string} $period
     */
    private function analyticsKey(
        array $period,
        RequestLogProviderScope $scope,
        int $storagePid,
    ): string {
        return 'analytics_' . $this->hash([
            $scope->name,
            $storagePid,
            (int) ($period['fromTimestamp'] ?? 0),
            (int) ($period['toTimestamp'] ?? 0),
            (int) ($period['days'] ?? 0),
            (string) ($period['preset'] ?? ''),
        ]);
    }

    /**
     * @param list<int>|null $providerUids
     */
    private function trendsKey(
        array $period,
        RequestLogProviderScope $scope,
        int $storagePid,
        ?array $providerUids,
    ): string {
        $uids = $providerUids ?? [];
        sort($uids);

        return 'trends_' . $this->hash([
            $scope->name,
            $storagePid,
            (int) ($period['fromTimestamp'] ?? 0),
            (int) ($period['toTimestamp'] ?? 0),
            (int) ($period['days'] ?? 0),
            implode(',', array_map('strval', $uids)),
        ]);
    }

    private function extensionHealthKey(int $windowDays): string
    {
        return 'extension_health_' . max(1, $windowDays);
    }

    /**
     * @param list<int|string> $parts
     */
    private function hash(array $parts): string
    {
        return substr(hash('sha256', implode('|', $parts)), 0, 32);
    }
}
