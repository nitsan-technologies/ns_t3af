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

use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Domain\RequestLog\RequestLogProviderScope;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Read model for the AI dashboard statistics.
 *
 * @internal
 */
final class DashboardAnalyticsService
{
    public function __construct(
        private readonly RequestLogRepository $requestLogs,
        private readonly ProviderRepositoryInterface $providers,
        private readonly ConnectionPool $connectionPool,
        private readonly DashboardStatisticsCache $statisticsCache,
    ) {}

    /**
     * @param array{fromTimestamp:int,toTimestamp:int,days:int,preset:string} $period
     * @return array<string, mixed>
     */
    public function forPeriod(
        array $period,
        RequestLogProviderScope $scope,
        int $storagePid = 0,
    ): array {
        $cached = $this->statisticsCache->getAnalytics($period, $scope, $storagePid);
        if ($cached !== null) {
            return $cached;
        }

        $fromTimestamp = (int) $period['fromTimestamp'];
        $toTimestamp = (int) $period['toTimestamp'];
        $days = max(1, (int) $period['days']);
        $providerUids = $this->resolveProviderUidsForScope($scope, $storagePid);

        $totals = $this->requestLogs->totals($fromTimestamp, $toTimestamp, $scope, $providerUids);
        $successFail = $this->requestLogs->successFailTotals($fromTimestamp, $toTimestamp, $scope, $providerUids);
        $extensionSpendRows = $scope === RequestLogProviderScope::Credits
            ? $this->normalizeExtensionSpendRows(
                $this->requestLogs->usageByExtensionCredits($fromTimestamp, $toTimestamp, 8, $scope, $providerUids),
            )
            : [];

        $payload = [
            'periodDays' => $days,
            'periodPreset' => (string) ($period['preset'] ?? ''),
            'periodFrom' => $fromTimestamp,
            'periodTo' => $toTimestamp,
            'totals' => $totals,
            'successFail' => $successFail,
            'activeProviders' => $this->countActiveProviders($scope, $storagePid),
            'scheduledTasks' => $this->scheduledTaskStats(),
            'requestsOverTime' => $this->requestLogs->requestsByDay($fromTimestamp, $toTimestamp, $scope, $providerUids),
            'requestsSuccessFailOverTime' => $this->requestLogs->requestsByDaySuccessFail($fromTimestamp, $toTimestamp, $scope, $providerUids),
            'creditsOverTime' => $this->requestLogs->creditsByDay($fromTimestamp, $toTimestamp, $scope, $providerUids),
            'creditsByDayAndExtension' => $this->requestLogs->creditsByDayAndExtension($fromTimestamp, $toTimestamp, $scope, $providerUids),
            'costByDayAndProvider' => $this->requestLogs->costByDayAndProvider($fromTimestamp, $toTimestamp, $scope, $providerUids),
            'extensionUsage' => $this->requestLogs->usageByExtension($fromTimestamp, $toTimestamp, 12, $scope, $providerUids),
            'extensionSpendRows' => $extensionSpendRows,
            'extensionFeatureUsage' => $this->requestLogs->usageByExtensionAndFeature($fromTimestamp, $toTimestamp, 20, $scope, $providerUids),
            'featureCredits' => $this->requestLogs->usageByFeatureCredits($fromTimestamp, $toTimestamp, 8, $scope, $providerUids),
            'topModels' => $this->requestLogs->topModelsByTokens($fromTimestamp, $toTimestamp, 10, $scope, $providerUids),
            'providerDistribution' => $this->requestLogs->providerDistribution($fromTimestamp, $toTimestamp, $scope, $providerUids),
            'providerStats' => $this->requestLogs->providerStats($fromTimestamp, $toTimestamp, $scope, $providerUids),
            'recentRequests' => $this->requestLogs->recent(8, $fromTimestamp, $toTimestamp, $scope, $providerUids),
            'avgCreditsPerRequest' => $this->averageCreditsPerRequest($totals),
        ];

        $this->statisticsCache->setAnalytics($period, $scope, $storagePid, $payload);

        return $payload;
    }

    /**
     * Provider UIDs for Own Keys request-log filtering at a site root (null = no filter).
     *
     * @return list<int>|null
     */
    public function resolveOwnKeysProviderUids(int $storagePid): ?array
    {
        return $this->resolveProviderUidsForScope(RequestLogProviderScope::OwnKeys, $storagePid);
    }

    /**
     * @param list<array{extensionKey:string,credits:float|int|string,requests?:int}> $rows
     * @return list<array{extensionKey:string,credits:float,requests:int,percent:float}>
     */
    private function normalizeExtensionSpendRows(array $rows): array
    {
        $max = 0.0;
        foreach ($rows as $row) {
            $max = max($max, (float) ($row['credits'] ?? 0.0));
        }
        if ($max <= 0.0) {
            $max = 1.0;
        }

        $normalized = [];
        foreach ($rows as $row) {
            $credits = (float) ($row['credits'] ?? 0.0);
            $normalized[] = [
                'extensionKey' => (string) ($row['extensionKey'] ?? ''),
                'credits' => $credits,
                'requests' => (int) ($row['requests'] ?? 0),
                'percent' => round(100 * $credits / $max, 1),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $totals
     */
    private function averageCreditsPerRequest(array $totals): float
    {
        $requests = (int) ($totals['totalRequests'] ?? 0);
        if ($requests <= 0) {
            return 0.0;
        }

        return round((float) ($totals['totalCredits'] ?? 0.0) / $requests, 2);
    }

    /**
     * @return array{total:int,active:int,failing:int}
     */
    public function scheduledTaskStats(): array
    {
        $cached = $this->statisticsCache->getScheduledTasks();
        if ($cached !== null) {
            return $cached;
        }

        $stats = $this->fetchScheduledTaskStats();
        $this->statisticsCache->setScheduledTasks($stats);

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    public function forLastDays(
        int $days = 7,
        RequestLogProviderScope $scope = RequestLogProviderScope::OwnKeys,
        int $storagePid = 0,
    ): array {
        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());

        return $this->forPeriod([
            'preset' => '7d',
            'days' => max(1, $days),
            'fromTimestamp' => $now - max(1, $days) * 86400,
            'toTimestamp' => $now,
        ], $scope, $storagePid);
    }

    /**
     * @return list<int>|null null = no provider_uid filter (Credits or global Own Keys)
     */
    private function resolveProviderUidsForScope(RequestLogProviderScope $scope, int $storagePid): ?array
    {
        if ($scope === RequestLogProviderScope::Credits || $storagePid <= 0) {
            return null;
        }

        $uids = [];
        foreach ($this->providers->findAllByStoragePid($storagePid, includeHidden: true) as $provider) {
            if ($this->isOwnKeysProvider($provider) && $provider->uid > 0) {
                $uids[] = $provider->uid;
            }
        }

        return $uids;
    }

    private function countActiveProviders(RequestLogProviderScope $scope, int $storagePid = 0): int
    {
        if ($scope === RequestLogProviderScope::Credits) {
            return 0;
        }

        $providerList = $storagePid > 0
            ? $this->providers->findAllByStoragePid($storagePid, includeHidden: true)
            : $this->providers->findAll(includeHidden: true);

        $count = 0;
        foreach ($providerList as $provider) {
            if ($this->isOwnKeysProvider($provider) && $provider->isEnabled) {
                $count++;
            }
        }

        return $count;
    }

    private function isOwnKeysProvider(Provider $provider): bool
    {
        return $provider->identifier !== CreditsProviderIdentifier::IDENTIFIER;
    }

    /**
     * @return array{total:int,active:int,failing:int}
     */
    private function fetchScheduledTaskStats(): array
    {
        $rows = [];
        try {
            $table = 'tx_scheduler_task';
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll();
            $qb->select('disable', 'lastexecution_failure')
                ->from($table)
                ->where($qb->expr()->like('classname', $qb->createNamedParameter('%NsT3AF%')));

            /** @var array<int, array<string, scalar|null>> $rows */
            $rows = $qb->executeQuery()->fetchAllAssociative();
        } catch (\Throwable) {
            return [
                'total' => 0,
                'active' => 0,
                'failing' => 0,
            ];
        }

        $total = count($rows);
        $active = 0;
        $failing = 0;
        foreach ($rows as $row) {
            if ((int) ($row['disable'] ?? 1) === 0) {
                $active++;
            }
            if ((int) ($row['lastexecution_failure'] ?? 0) === 1) {
                $failing++;
            }
        }

        return [
            'total' => $total,
            'active' => $active,
            'failing' => $failing,
        ];
    }
}
