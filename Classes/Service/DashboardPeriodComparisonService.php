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

use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Domain\RequestLog\RequestLogProviderScope;

/**
 * Compares dashboard KPIs to the prior period of equal length.
 *
 * @internal
 */
final class DashboardPeriodComparisonService
{
    public function __construct(
        private readonly RequestLogRepository $requestLogs,
        private readonly DashboardAnalyticsService $dashboardAnalytics,
        private readonly DashboardStatisticsCache $statisticsCache,
    ) {}

    /**
     * @param array{fromTimestamp:int,toTimestamp:int,days:int} $period
     * @param array{totalRequests:int,totalTokens:int,totalCost:float,totalCredits?:float,successRate:float} $currentTotals
     * @param list<int>|null $providerUids
     * @return array<string, array{value:float,changePercent:float,direction:string,sparkline:list<int>}>
     */
    public function buildTrends(
        array $period,
        array $currentTotals,
        RequestLogProviderScope $scope,
        int $activeProviders = 0,
        ?array $providerUids = null,
        int $storagePid = 0,
    ): array {
        $cached = $this->statisticsCache->getTrends($period, $scope, $storagePid, $providerUids);
        if ($cached !== null) {
            $cached['activeProviders']['value'] = (float) $activeProviders;

            return $cached;
        }

        $from = (int) $period['fromTimestamp'];
        $to = (int) $period['toTimestamp'];
        $length = max(1, $to - $from);
        $priorFrom = max(0, $from - $length);
        $priorTo = $from > 0 ? $from - 1 : 0;

        $priorTotals = $this->requestLogs->totals($priorFrom, $priorTo > 0 ? $priorTo : null, $scope, $providerUids);
        $scheduled = $this->dashboardAnalytics->scheduledTaskStats();

        $sparkline = array_map(
            static fn(array $row): int => (int) ($row['requests'] ?? 0),
            $this->requestLogs->requestsByDay($from, $to, $scope, $providerUids),
        );

        $currentCost = $scope === RequestLogProviderScope::Credits
            ? (float) ($currentTotals['totalCredits'] ?? 0.0)
            : (float) ($currentTotals['totalCost'] ?? 0.0);
        $priorCost = $scope === RequestLogProviderScope::Credits
            ? (float) ($priorTotals['totalCredits'] ?? 0.0)
            : (float) ($priorTotals['totalCost'] ?? 0.0);

        $payload = [
            'totalRequests' => DashboardTrendMath::metric(
                (float) ($currentTotals['totalRequests'] ?? 0),
                (float) ($priorTotals['totalRequests'] ?? 0),
                $sparkline,
            ),
            'totalTokens' => DashboardTrendMath::metric(
                (float) ($currentTotals['totalTokens'] ?? 0),
                (float) ($priorTotals['totalTokens'] ?? 0),
            ),
            'cost' => DashboardTrendMath::metric($currentCost, $priorCost),
            'successRate' => DashboardTrendMath::metric(
                (float) ($currentTotals['successRate'] ?? 0.0),
                (float) ($priorTotals['successRate'] ?? 0.0),
            ),
            'activeProviders' => [
                'value' => (float) $activeProviders,
                'changePercent' => 0.0,
                'direction' => 'neutral',
                'sparkline' => [],
            ],
            'scheduledTasks' => [
                'value' => (float) ($scheduled['active'] ?? 0),
                'changePercent' => 0.0,
                'direction' => 'neutral',
                'sparkline' => [],
            ],
        ];

        $this->statisticsCache->setTrends($period, $scope, $storagePid, $providerUids, $payload);

        return $payload;
    }
}
