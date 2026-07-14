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

final class AiUsageAnalyticsService
{
    public function __construct(
        private readonly RequestLogRepository $requestLogs,
        private readonly DashboardPeriodResolver $dashboardPeriodResolver,
    ) {}

    /**
     * @param array<string, mixed> $queryParams
     *
     * @return array{
     *   key: string,
     *   label: string,
     *   fromTimestamp: int,
     *   toTimestamp: int,
     *   fromDate?: string,
     *   toDate?: string
     * }
     */
    public function resolvePeriod(array $queryParams): array
    {
        $resolved = $this->dashboardPeriodResolver->resolveFromQueryParams(
            $queryParams,
            DashboardPeriodResolver::PRESET_7D,
        );

        return $this->mapResolvedPeriod($resolved);
    }

    /**
     * @param array{
     *   preset: string,
     *   days: int,
     *   fromTimestamp: int,
     *   toTimestamp: int,
     *   labelKey: string
     * } $resolved
     *
     * @return array{
     *   key: string,
     *   label: string,
     *   fromTimestamp: int,
     *   toTimestamp: int,
     *   fromDate?: string,
     *   toDate?: string
     * }
     */
    public function mapResolvedPeriod(array $resolved): array
    {
        $preset = (string) $resolved['preset'];
        $period = [
            'key' => $preset,
            'label' => '',
            'fromTimestamp' => (int) $resolved['fromTimestamp'],
            'toTimestamp' => (int) $resolved['toTimestamp'],
        ];

        if ($preset === DashboardPeriodResolver::PRESET_CUSTOM) {
            $period['fromDate'] = date('Y-m-d', (int) $resolved['fromTimestamp']);
            $period['toDate'] = date('Y-m-d', (int) $resolved['toTimestamp']);
        }

        return $period;
    }

    /**
     * @param array<string, mixed> $queryParams
     *
     * @return array{
     *   search:string,
     *   engine:string,
     *   model:string,
     *   module:string,
     *   scope:string,
     *   reqType:string,
     *   status:string,
     *   user:string,
     *   max:int,
     *   currentPage:int
     * }
     */
    public function normalizeFilters(array $queryParams): array
    {
        $maxRaw = (string) ($queryParams['max'] ?? '20');
        $max = ctype_digit($maxRaw) ? (int) $maxRaw : 20;
        if (!in_array($max, [20, 50, 100, 200, 500], true)) {
            $max = 20;
        }

        return [
            'search' => trim((string) ($queryParams['search'] ?? '')),
            'engine' => trim((string) ($queryParams['engine'] ?? 'Any')),
            'model' => trim((string) ($queryParams['model'] ?? 'Any')),
            'module' => trim((string) ($queryParams['module'] ?? 'Any')),
            'scope' => trim((string) ($queryParams['scope'] ?? 'Any')),
            'reqType' => trim((string) ($queryParams['reqType'] ?? 'Any')),
            'status' => trim((string) ($queryParams['status'] ?? 'All')),
            'user' => trim((string) ($queryParams['user'] ?? 'ALL')),
            'max' => $max,
            'currentPage' => max(1, (int) ($queryParams['currentPage'] ?? 1)),
        ];
    }

    /**
     * @param array{fromTimestamp:int,toTimestamp:int|null} $period
     * @param array{search:string,engine:string,model:string,module:string,scope:string,reqType:string,status:string,user:string,max:int,currentPage:int} $filters
     *
     * @return array{
     *   kpis:array<string,mixed>,
     *   summary:array<string,mixed>,
     *   filterOptions:array<string,list<string>>
     * }
     */
    public function buildUsageData(array $period, array $filters): array
    {
        $from = (int) $period['fromTimestamp'];
        $to = (int) $period['toTimestamp'];

        $totals = $this->requestLogs->totals($from, $to);
        $providerUsage = $this->requestLogs->providerDistribution($from, $to);
        $topModels = $this->requestLogs->topModelsByTokens($from, $to, 10);
        $extensionUsage = $this->requestLogs->usageByExtension($from, $to, 12);

        return [
            'kpis' => [
                'totalRequests' => (int) ($totals['totalRequests'] ?? 0),
                'totalTokens' => (int) ($totals['totalTokens'] ?? 0),
                'totalCost' => (float) ($totals['totalCost'] ?? 0.0),
                'successRate' => (float) ($totals['successRate'] ?? 0.0),
            ],
            'summary' => [
                'providers' => $providerUsage,
                'models' => $topModels,
                'extensions' => $extensionUsage,
            ],
            'filterOptions' => $this->requestLogs->usageFilterOptions($from, $to),
        ];
    }

    /**
     * @param array{fromTimestamp:int,toTimestamp:int|null} $period
     * @param array{search:string,engine:string,model:string,module:string,scope:string,reqType:string,status:string,user:string,max:int,currentPage:int} $filters
     *
     * @return array{
     *   entries:list<array<string,mixed>>,
     *   totalCount:int,
     *   currentPage:int,
     *   perPage:int
     * }
     */
    public function buildLogPage(array $period, array $filters): array
    {
        $from = (int) $period['fromTimestamp'];
        $to = (int) $period['toTimestamp'];
        $perPage = $filters['max'];
        $currentPage = max(1, $filters['currentPage']);
        $totalCount = $this->requestLogs->countFiltered($filters, $from, $to);
        $totalPages = max(1, (int) ceil($totalCount / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;

        return [
            'entries' => $this->requestLogs->findFiltered($filters, $from, $to, null, $perPage, $offset),
            'totalCount' => $totalCount,
            'currentPage' => $currentPage,
            'perPage' => $perPage,
        ];
    }

    /**
     * @param array{fromTimestamp:int,toTimestamp:int|null} $period
     * @param array{search:string,engine:string,model:string,module:string,scope:string,reqType:string,status:string,user:string,max:int,currentPage:int} $filters
     *
     * @return array<string,mixed>
     */
    public function buildExportPayload(array $period, array $filters): array
    {
        $from = (int) $period['fromTimestamp'];
        $to = (int) $period['toTimestamp'];
        $usage = $this->buildUsageData($period, $filters);
        $entries = $this->requestLogs->findForExport($filters, $from, $to);

        return [
            'generatedAt' => date(DATE_ATOM),
            'period' => $period,
            'filters' => $filters,
            'summary' => $usage['summary'],
            'kpis' => $usage['kpis'],
            'requestLog' => $entries,
        ];
    }

    /**
     * @param array{fromTimestamp:int,toTimestamp:int|null} $period
     * @param array{search:string,engine:string,model:string,module:string,scope:string,reqType:string,status:string,user:string,max:int,currentPage:int} $filters
     *
     * @return list<list<string>>
     */
    public function buildLogCsvRows(array $period, array $filters): array
    {
        $from = (int) $period['fromTimestamp'];
        $to = (int) $period['toTimestamp'];
        $rows = $this->requestLogs->findForExport($filters, $from, $to);
        $lines = [[
            'Time',
            'Provider',
            'Module',
            'Scope',
            'Model',
            'Type',
            'Status',
            'Tokens',
            'Cost',
        ]];

        foreach ($rows as $row) {
            $lines[] = [
                date('Y-m-d H:i:s', (int) ($row['crdate'] ?? 0)),
                (string) ($row['provider_identifier'] ?? ''),
                (string) ($row['extension_key'] ?? ''),
                (string) ($row['feature_key'] ?? ''),
                (string) ($row['model_used'] ?? ''),
                (string) ($row['request_type'] ?? ''),
                ((int) ($row['success'] ?? 0)) === 1 ? 'success' : 'failed',
                (string) ($row['total_tokens'] ?? 0),
                (string) ($row['estimated_cost'] ?? 0),
            ];
        }

        return $lines;
    }
}
