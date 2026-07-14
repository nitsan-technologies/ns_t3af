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

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Persistence for structured MCP tool invocation logs.
 *
 * @internal
 */
readonly class McpToolLogRepository
{
    public const TABLE = 'tx_nst3af_mcp_tool_log';

    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * @param array<string, scalar|null> $values
     */
    public function insert(array $values): void
    {
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $payload = $values;
        $payload['pid'] = 0;
        $payload['crdate'] = $now;

        $this->connection()->insert(self::TABLE, $payload);
    }

    /**
     * @return array{totalCalls:int,successCount:int,errorCount:int,avgLatencyMs:float,successRate:float}
     */
    public function summary(int $fromTimestamp, ?int $toTimestamp = null): array
    {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'COUNT(*) AS total_calls',
            'COALESCE(SUM(success), 0) AS success_count',
            'COALESCE(AVG(latency_ms), 0) AS avg_latency_ms',
        )->from(self::TABLE);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);

        /** @var array<string, scalar|null>|false $row */
        $row = $qb->executeQuery()->fetchAssociative();
        $totalCalls = (int) ($row['total_calls'] ?? 0);
        $successCount = (int) ($row['success_count'] ?? 0);
        $errorCount = $totalCalls - $successCount;

        return [
            'totalCalls' => $totalCalls,
            'successCount' => $successCount,
            'errorCount' => $errorCount,
            'avgLatencyMs' => round((float) ($row['avg_latency_ms'] ?? 0.0), 1),
            'successRate' => $totalCalls > 0 ? round(($successCount / $totalCalls) * 100, 2) : 0.0,
        ];
    }

    /**
     * @return list<array{toolName:string,calls:int,successCount:int,avgLatencyMs:float,successRate:float}>
     */
    public function topTools(int $limit, int $fromTimestamp, ?int $toTimestamp = null): array
    {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'tool_name',
            'COUNT(*) AS calls',
            'COALESCE(SUM(success), 0) AS success_count',
            'COALESCE(AVG(latency_ms), 0) AS avg_latency_ms',
        )
            ->from(self::TABLE)
            ->groupBy('tool_name')
            ->orderBy('calls', 'DESC')
            ->setMaxResults(max(1, $limit));
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(static function (array $row): array {
            $calls = (int) ($row['calls'] ?? 0);
            $successCount = (int) ($row['success_count'] ?? 0);

            return [
                'toolName' => (string) ($row['tool_name'] ?? ''),
                'calls' => $calls,
                'successCount' => $successCount,
                'avgLatencyMs' => round((float) ($row['avg_latency_ms'] ?? 0.0), 1),
                'successRate' => $calls > 0 ? round(($successCount / $calls) * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * @return list<array{day:string,calls:int,success:int,errors:int}>
     */
    public function dailyChart(int $fromTimestamp, ?int $toTimestamp = null): array
    {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            "DATE_FORMAT(FROM_UNIXTIME(crdate), '%Y-%m-%d') AS day",
            'COUNT(*) AS calls',
            'COALESCE(SUM(success), 0) AS success',
        )
            ->from(self::TABLE)
            ->groupBy('day')
            ->orderBy('day', 'ASC');
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(static function (array $row): array {
            $calls = (int) ($row['calls'] ?? 0);
            $success = (int) ($row['success'] ?? 0);

            return [
                'day' => (string) ($row['day'] ?? ''),
                'calls' => $calls,
                'success' => $success,
                'errors' => $calls - $success,
            ];
        }, $rows);
    }

    /**
     * @return list<array{crdate:int,clientLabel:string,toolName:string,errorMessage:string,latencyMs:int}>
     */
    public function errors(int $fromTimestamp, ?int $toTimestamp = null, int $limit = 50): array
    {
        $qb = $this->queryBuilder();
        $qb->select('crdate', 'client_label', 'tool_name', 'error_message', 'latency_ms')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('success', $qb->createNamedParameter(0, ParameterType::INTEGER)))
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(max(1, $limit));
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(static fn(array $row): array => [
            'crdate' => (int) ($row['crdate'] ?? 0),
            'clientLabel' => (string) ($row['client_label'] ?? ''),
            'toolName' => (string) ($row['tool_name'] ?? ''),
            'errorMessage' => (string) ($row['error_message'] ?? ''),
            'latencyMs' => (int) ($row['latency_ms'] ?? 0),
        ], $rows);
    }

    /**
     * @return array{calls:int,successCount:int,avgLatencyMs:float,successRate:float}
     */
    public function metricsForTool(string $toolName, int $fromTimestamp, ?int $toTimestamp = null): array
    {
        return $this->metricsForToolNames([$toolName], $fromTimestamp, $toTimestamp);
    }

    /**
     * @param list<string> $toolNames
     *
     * @return array{calls:int,successCount:int,avgLatencyMs:float,successRate:float}
     */
    public function metricsForToolNames(array $toolNames, int $fromTimestamp, ?int $toTimestamp = null): array
    {
        $toolNames = array_values(array_unique(array_filter(array_map(
            static fn(mixed $name): string => trim((string) $name),
            $toolNames,
        ))));
        if ($toolNames === []) {
            return [
                'calls' => 0,
                'successCount' => 0,
                'avgLatencyMs' => 0.0,
                'successRate' => 0.0,
            ];
        }

        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'COUNT(*) AS calls',
            'COALESCE(SUM(success), 0) AS success_count',
            'COALESCE(AVG(latency_ms), 0) AS avg_latency_ms',
        )
            ->from(self::TABLE)
            ->where($qb->expr()->in(
                'tool_name',
                $qb->createNamedParameter($toolNames, Connection::PARAM_STR_ARRAY),
            ));
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);

        /** @var array<string, scalar|null>|false $row */
        $row = $qb->executeQuery()->fetchAssociative();
        $calls = (int) ($row['calls'] ?? 0);
        $successCount = (int) ($row['success_count'] ?? 0);

        return [
            'calls' => $calls,
            'successCount' => $successCount,
            'avgLatencyMs' => round((float) ($row['avg_latency_ms'] ?? 0.0), 1),
            'successRate' => $calls > 0 ? round(($successCount / $calls) * 100, 2) : 0.0,
        ];
    }

    public function lastCalledTimestampForTool(string $toolName): ?int
    {
        return $this->lastCalledTimestampForTools([$toolName]);
    }

    /**
     * @param list<string> $toolNames
     */
    public function lastCalledTimestampForTools(array $toolNames): ?int
    {
        $grouped = $this->lastCalledTimestampGroupedByToolNames($toolNames);
        $lastCalled = null;
        foreach ($grouped as $timestamp) {
            if ($lastCalled === null || $timestamp > $lastCalled) {
                $lastCalled = $timestamp;
            }
        }

        return $lastCalled;
    }

    /**
     * @param list<string> $toolNames
     *
     * @return array<string, array{calls:int,successCount:int,avgLatencyMs:float,successRate:float}>
     */
    public function metricsGroupedByToolNames(array $toolNames, int $fromTimestamp, ?int $toTimestamp = null): array
    {
        $toolNames = array_values(array_unique(array_filter(array_map(
            static fn(mixed $name): string => trim((string) $name),
            $toolNames,
        ))));
        if ($toolNames === []) {
            return [];
        }

        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'tool_name',
            'COUNT(*) AS calls',
            'COALESCE(SUM(success), 0) AS success_count',
            'COALESCE(AVG(latency_ms), 0) AS avg_latency_ms',
        )
            ->from(self::TABLE)
            ->where($qb->expr()->in(
                'tool_name',
                $qb->createNamedParameter($toolNames, Connection::PARAM_STR_ARRAY),
            ))
            ->groupBy('tool_name');
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $grouped = [];
        foreach ($rows as $row) {
            $name = (string) ($row['tool_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $calls = (int) ($row['calls'] ?? 0);
            $successCount = (int) ($row['success_count'] ?? 0);
            $grouped[$name] = [
                'calls' => $calls,
                'successCount' => $successCount,
                'avgLatencyMs' => round((float) ($row['avg_latency_ms'] ?? 0.0), 1),
                'successRate' => $calls > 0 ? round(($successCount / $calls) * 100, 2) : 0.0,
            ];
        }

        return $grouped;
    }

    /**
     * @param list<string> $toolNames
     *
     * @return array<string, int>
     */
    public function lastCalledTimestampGroupedByToolNames(array $toolNames): array
    {
        $toolNames = array_values(array_unique(array_filter(array_map(
            static fn(mixed $name): string => trim((string) $name),
            $toolNames,
        ))));
        if ($toolNames === []) {
            return [];
        }

        $qb = $this->queryBuilder();
        $qb->selectLiteral('tool_name', 'MAX(crdate) AS last_called')
            ->from(self::TABLE)
            ->where($qb->expr()->in(
                'tool_name',
                $qb->createNamedParameter($toolNames, Connection::PARAM_STR_ARRAY),
            ))
            ->groupBy('tool_name');

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $grouped = [];
        foreach ($rows as $row) {
            $name = (string) ($row['tool_name'] ?? '');
            $lastCalled = (int) ($row['last_called'] ?? 0);
            if ($name === '' || $lastCalled <= 0) {
                continue;
            }
            $grouped[$name] = $lastCalled;
        }

        return $grouped;
    }

    /**
     * @return list<array{clientLabel:string,used:int,limit:int}>
     */
    public function rateLimitsByClient(int $fromTimestamp, int $defaultLimit): array
    {
        $qb = $this->queryBuilder();
        $qb->selectLiteral('client_label', 'COUNT(*) AS used')
            ->from(self::TABLE)
            ->groupBy('client_label')
            ->orderBy('used', 'DESC');
        $this->applyPeriodConstraint($qb, $fromTimestamp, null);

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(static fn(array $row): array => [
            'clientLabel' => (string) ($row['client_label'] ?? ''),
            'used' => (int) ($row['used'] ?? 0),
            'limit' => $defaultLimit,
        ], $rows);
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function exportRows(int $fromTimestamp, ?int $toTimestamp = null): array
    {
        $qb = $this->queryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('crdate', 'ASC');
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return $rows;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }

    private function queryBuilder(): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        return $qb;
    }

    private function applyPeriodConstraint(QueryBuilder $qb, int $fromTimestamp, ?int $toTimestamp): void
    {
        if ($fromTimestamp > 0) {
            $qb->andWhere(
                $qb->expr()->gte('crdate', $qb->createNamedParameter($fromTimestamp, ParameterType::INTEGER)),
            );
        }

        if ($toTimestamp !== null && $toTimestamp > 0) {
            $qb->andWhere(
                $qb->expr()->lte('crdate', $qb->createNamedParameter($toTimestamp, ParameterType::INTEGER)),
            );
        }
    }
}
