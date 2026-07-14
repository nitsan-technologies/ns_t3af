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

namespace NITSAN\NsT3AF\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Persistence for per-user budget counters in `tx_nst3af_usage_budget`.
 *
 * One row per (user, period type). Counters reset lazily: when a period has
 * elapsed the row is rewound on the next read instead of via a scheduler.
 *
 * @internal
 */
final class UsageBudgetRepository
{
    public const TABLE = 'tx_nst3af_usage_budget';

    private const DURATIONS = [
        'daily' => 86400,
        'weekly' => 604800,
        'monthly' => 2592000,
    ];

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * Return the live usage for a user/period, resetting the window if expired.
     *
     * @return array{tokens_used:int,cost_used:float,requests_used:int}
     */
    public function getCurrentUsage(int $userId, string $periodType): array
    {
        $periodType = $this->normalizePeriod($periodType);
        $row = $this->fetchRow($userId, $periodType) ?? $this->createInitialRecord($userId, $periodType);

        if ($this->isPeriodExpired((int) $row['period_start'], $periodType)) {
            $row = $this->resetPeriod((int) $row['uid']);
        }

        return [
            'tokens_used' => (int) $row['tokens_used'],
            'cost_used' => (float) $row['cost_used'],
            'requests_used' => (int) $row['requests_used'],
        ];
    }

    /**
     * Increment the counters for a user/period, creating/resetting as needed.
     */
    public function recordUsage(int $userId, string $periodType, int $tokens, float $cost): void
    {
        if ($userId <= 0) {
            return;
        }
        $periodType = $this->normalizePeriod($periodType);
        $row = $this->fetchRow($userId, $periodType) ?? $this->createInitialRecord($userId, $periodType);

        if ($this->isPeriodExpired((int) $row['period_start'], $periodType)) {
            $row = $this->resetPeriod((int) $row['uid']);
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->update(self::TABLE)
            ->set('tokens_used', 'tokens_used + ' . max(0, $tokens), false)
            ->set('cost_used', 'cost_used + ' . $this->sqlFloat(max(0.0, $cost)), false)
            ->set('requests_used', 'requests_used + 1', false)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter((int) $row['uid'], Connection::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * @return array<string, scalar|null>|null
     */
    private function fetchRow(int $userId, string $periodType): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        /** @var array<string, scalar|null>|false $row */
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, Connection::PARAM_INT)),
                $qb->expr()->eq('period_type', $qb->createNamedParameter($periodType)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Insert a fresh zeroed row. Tolerates a concurrent insert (unique key) by
     * falling back to a re-read.
     *
     * @return array<string, scalar|null>
     */
    private function createInitialRecord(int $userId, string $periodType): array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        try {
            $connection->insert(self::TABLE, [
                'user_id' => $userId,
                'period_type' => $periodType,
                'period_start' => (int) ($GLOBALS['EXEC_TIME'] ?? time()),
                'tokens_used' => 0,
                'cost_used' => 0.0,
                'requests_used' => 0,
            ]);
        } catch (\Throwable) {
            // Concurrent insert won the race — the row now exists, re-read below.
        }

        $row = $this->fetchRow($userId, $periodType);
        if ($row === null) {
            // Should not happen, but keep callers safe with an in-memory shape.
            return [
                'uid' => 0,
                'period_start' => (int) ($GLOBALS['EXEC_TIME'] ?? time()),
                'tokens_used' => 0,
                'cost_used' => 0.0,
                'requests_used' => 0,
            ];
        }

        return $row;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function resetPeriod(int $uid): array
    {
        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            [
                'period_start' => $now,
                'tokens_used' => 0,
                'cost_used' => 0.0,
                'requests_used' => 0,
            ],
            ['uid' => $uid],
            ['period_start' => Connection::PARAM_INT, 'tokens_used' => Connection::PARAM_INT, 'requests_used' => Connection::PARAM_INT, 'uid' => Connection::PARAM_INT],
        );

        return [
            'uid' => $uid,
            'period_start' => $now,
            'tokens_used' => 0,
            'cost_used' => 0.0,
            'requests_used' => 0,
        ];
    }

    private function isPeriodExpired(int $periodStart, string $periodType): bool
    {
        if ($periodStart <= 0) {
            return false;
        }
        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());

        return ($now - $periodStart) >= $this->getPeriodDuration($periodType);
    }

    private function getPeriodDuration(string $periodType): int
    {
        return self::DURATIONS[$periodType] ?? self::DURATIONS['monthly'];
    }

    private function normalizePeriod(string $periodType): string
    {
        $periodType = strtolower(trim($periodType));

        return isset(self::DURATIONS[$periodType]) ? $periodType : 'monthly';
    }

    private function sqlFloat(float $value): string
    {
        return number_format($value, 6, '.', '');
    }
}
