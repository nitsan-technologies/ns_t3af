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

namespace NITSAN\NsT3AF\Mcp\Service\Backend;

use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use NITSAN\NsT3AF\Mcp\Service\McpToolNameResolver;
use NITSAN\NsT3AF\Service\DashboardPeriodResolver;
use ReflectionClass;

/**
 * Aggregates MCP tool invocation metrics from tx_nst3af_mcp_tool_log.
 */
final class McpAnalyticsService
{
    /**
     * @var array<string, list<string>>|null
     */
    private ?array $lookupNamesByTool = null;

    public function __construct(
        private readonly McpToolLogRepository $toolLogRepository,
        private readonly DashboardPeriodResolver $dashboardPeriodResolver,
        private readonly McpToolIntrospectorService $toolIntrospector,
        private readonly McpToolNameResolver $toolNameResolver,
    ) {}

    /**
     * @param array<string, mixed>|string $queryOrPeriod
     *
     * @return array{
     *     key: string,
     *     fromTimestamp: int,
     *     toTimestamp: int
     * }
     */
    public function resolvePeriod(array|string $queryOrPeriod = '7d'): array
    {
        $query = is_array($queryOrPeriod) ? $queryOrPeriod : ['period' => $queryOrPeriod];
        $resolved = $this->dashboardPeriodResolver->resolveFromQueryParams($query);

        return [
            'key' => $resolved['preset'],
            'fromTimestamp' => $resolved['fromTimestamp'],
            'toTimestamp' => $resolved['toTimestamp'],
        ];
    }

    /**
     * @param array<string, mixed>|string $queryOrPeriod
     *
     * @return array{toolCalls: int, avgSuccessRate: float, avgLatencyMs: float}
     */
    public function getSummary(array|string $queryOrPeriod = '7d'): array
    {
        $resolved = $this->resolvePeriod($queryOrPeriod);
        $summary = $this->toolLogRepository->summary(
            $resolved['fromTimestamp'],
            $resolved['toTimestamp'],
        );

        return [
            'toolCalls' => (int) ($summary['totalCalls'] ?? 0),
            'avgSuccessRate' => (float) ($summary['successRate'] ?? 0.0),
            'avgLatencyMs' => (float) ($summary['avgLatencyMs'] ?? 0.0),
        ];
    }

    /**
     * @param array<string, mixed>|string $queryOrPeriod
     *
     * @return array{
     *     callsWeek: int,
     *     successRate: float,
     *     avgLatencyMs: float,
     *     lastCalled: int|null
     * }
     */
    public function getForTool(string $toolName, array|string $queryOrPeriod = '7d'): array
    {
        $batched = $this->getForTools([$toolName], $queryOrPeriod);

        return $batched[$toolName] ?? $this->emptyToolAnalytics();
    }

    /**
     * Batch-load analytics for many tool names with two DB queries total.
     *
     * @param list<string> $toolNames
     * @param array<string, mixed>|string $queryOrPeriod
     *
     * @return array<string, array{
     *     callsWeek: int,
     *     successRate: float,
     *     avgLatencyMs: float,
     *     lastCalled: int|null
     * }>
     */
    public function getForTools(array $toolNames, array|string $queryOrPeriod = '7d'): array
    {
        $normalizedNames = [];
        foreach ($toolNames as $toolName) {
            $name = trim((string) $toolName);
            if ($name !== '') {
                $normalizedNames[$name] = $name;
            }
        }
        $normalizedNames = array_values($normalizedNames);
        if ($normalizedNames === []) {
            return [];
        }

        $resolved = $this->resolvePeriod($queryOrPeriod);
        $lookupMap = $this->lookupNamesByTool();
        $dbNames = [];
        $aliasesByCanonical = [];
        foreach ($normalizedNames as $toolName) {
            $aliases = $lookupMap[$toolName] ?? [$toolName];
            $aliasesByCanonical[$toolName] = $aliases;
            foreach ($aliases as $alias) {
                $dbNames[$alias] = $alias;
            }
        }

        $metricsByDbName = $this->toolLogRepository->metricsGroupedByToolNames(
            array_values($dbNames),
            $resolved['fromTimestamp'],
            $resolved['toTimestamp'],
        );
        $lastCalledByDbName = $this->toolLogRepository->lastCalledTimestampGroupedByToolNames(
            array_values($dbNames),
        );

        $result = [];
        foreach ($normalizedNames as $toolName) {
            $calls = 0;
            $successCount = 0;
            $weightedLatency = 0.0;
            $lastCalled = null;
            foreach ($aliasesByCanonical[$toolName] as $alias) {
                $metrics = $metricsByDbName[$alias] ?? null;
                if ($metrics !== null) {
                    $aliasCalls = (int) ($metrics['calls'] ?? 0);
                    $calls += $aliasCalls;
                    $successCount += (int) ($metrics['successCount'] ?? 0);
                    $weightedLatency += ((float) ($metrics['avgLatencyMs'] ?? 0.0)) * $aliasCalls;
                }
                $aliasLastCalled = $lastCalledByDbName[$alias] ?? null;
                if ($aliasLastCalled !== null && ($lastCalled === null || $aliasLastCalled > $lastCalled)) {
                    $lastCalled = $aliasLastCalled;
                }
            }

            $result[$toolName] = [
                'callsWeek' => $calls,
                'successRate' => $calls > 0 ? round(($successCount / $calls) * 100, 2) : 0.0,
                'avgLatencyMs' => $calls > 0 ? round($weightedLatency / $calls, 1) : 0.0,
                'lastCalled' => $lastCalled,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|string $queryOrPeriod
     *
     * @return list<array{toolName: string, calls: int, successRate: float, avgLatencyMs: float}>
     */
    public function getTopTools(int $limit, array|string $queryOrPeriod = '7d'): array
    {
        $resolved = $this->resolvePeriod($queryOrPeriod);

        return $this->toolLogRepository->topTools(
            $limit,
            $resolved['fromTimestamp'],
            $resolved['toTimestamp'],
        );
    }

    /**
     * @param array<string, mixed>|string $queryOrPeriod
     *
     * @return list<array{day: string, calls: int, success: int, errors: int}>
     */
    public function getDailyChart(array|string $queryOrPeriod = '7d'): array
    {
        $resolved = $this->resolvePeriod($queryOrPeriod);

        return $this->toolLogRepository->dailyChart(
            $resolved['fromTimestamp'],
            $resolved['toTimestamp'],
        );
    }

    /**
     * @param array<string, mixed>|string $queryOrPeriod
     *
     * @return list<array{crdate: int, clientLabel: string, toolName: string, errorMessage: string, latencyMs: int}>
     */
    public function getErrors(array|string $queryOrPeriod = '7d', int $limit = 50): array
    {
        $resolved = $this->resolvePeriod($queryOrPeriod);

        return $this->toolLogRepository->errors(
            $resolved['fromTimestamp'],
            $resolved['toTimestamp'],
            $limit,
        );
    }

    /**
     * @param array<string, mixed>|string $queryOrPeriod
     *
     * @return list<array{clientLabel: string, used: int, limit: int}>
     */
    public function getRateLimits(array|string $queryOrPeriod = '7d', int $defaultLimit = 1000): array
    {
        $resolved = $this->resolvePeriod($queryOrPeriod);

        return $this->toolLogRepository->rateLimitsByClient(
            $resolved['fromTimestamp'],
            $defaultLimit,
        );
    }

    /**
     * @param array<string, mixed>|string $queryOrPeriod
     */
    public function exportCsv(array|string $queryOrPeriod = '7d'): string
    {
        $resolved = $this->resolvePeriod($queryOrPeriod);
        $rows = $this->toolLogRepository->exportRows(
            $resolved['fromTimestamp'],
            $resolved['toTimestamp'],
        );

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, [
            'crdate',
            'tool_name',
            'handler_name',
            'call_type',
            'token_uid',
            'client_label',
            'be_user',
            'success',
            'error_message',
            'latency_ms',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['crdate'] ?? '',
                $row['tool_name'] ?? '',
                $row['handler_name'] ?? '',
                $row['call_type'] ?? '',
                $row['token_uid'] ?? '',
                $row['client_label'] ?? '',
                $row['be_user'] ?? '',
                $row['success'] ?? '',
                $row['error_message'] ?? '',
                $row['latency_ms'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    /**
     * @return array<string, list<string>>
     */
    private function lookupNamesByTool(): array
    {
        if ($this->lookupNamesByTool !== null) {
            return $this->lookupNamesByTool;
        }

        $map = [];
        foreach ($this->toolIntrospector->listTools() as $tool) {
            $toolName = trim((string) ($tool['name'] ?? ''));
            if ($toolName === '') {
                continue;
            }

            $names = [$toolName];
            $className = (string) ($tool['className'] ?? '');
            if ($className !== '') {
                $legacyName = $this->toolNameResolver->legacyNameFromClassShortName(
                    (new ReflectionClass($className))->getShortName(),
                );
                if ($legacyName !== '' && !in_array($legacyName, $names, true)) {
                    $names[] = $legacyName;
                }
            }
            $map[$toolName] = array_values(array_unique(array_filter($names)));
        }

        return $this->lookupNamesByTool = $map;
    }

    /**
     * @return array{
     *     callsWeek: int,
     *     successRate: float,
     *     avgLatencyMs: float,
     *     lastCalled: int|null
     * }
     */
    private function emptyToolAnalytics(): array
    {
        return [
            'callsWeek' => 0,
            'successRate' => 0.0,
            'avgLatencyMs' => 0.0,
            'lastCalled' => null,
        ];
    }
}
