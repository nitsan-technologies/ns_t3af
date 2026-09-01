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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Agent\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Persists per-turn correlation ids and tool-call guard state (T16/T17).
 *
 * @internal
 */
final readonly class AgentTurnRepository
{
    public const TABLE = 'tx_nst3af_agent_turn';

    public function __construct(private ConnectionPool $connectionPool) {}

    public function startTurn(int $beUserId): string
    {
        $correlationId = bin2hex(random_bytes(16));
        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());

        $this->connection()->insert(self::TABLE, [
            'pid' => 0,
            'correlation_id' => $correlationId,
            'be_user' => max(0, $beUserId),
            'tool_call_count' => 0,
            'guard_state' => 'ok',
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return $correlationId;
    }

    public function incrementToolCalls(string $correlationId): int
    {
        $row = $this->findByCorrelationId($correlationId);
        if ($row === null) {
            return 0;
        }

        $count = (int) ($row['tool_call_count'] ?? 0) + 1;
        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $this->connection()->update(
            self::TABLE,
            ['tool_call_count' => $count, 'tstamp' => $now],
            ['uid' => (int) $row['uid']],
        );

        return $count;
    }

    public function updateGuardState(string $correlationId, string $guardState): void
    {
        $row = $this->findByCorrelationId($correlationId);
        if ($row === null) {
            return;
        }

        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $this->connection()->update(
            self::TABLE,
            ['guard_state' => $guardState, 'tstamp' => $now],
            ['uid' => (int) $row['uid']],
        );
    }

    public function countTurnsToday(int $beUserId): int
    {
        if ($beUserId <= 0) {
            return 0;
        }

        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $dayStart = (int) strtotime('today', $now);

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $count = $qb->count('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUserId, Connection::PARAM_INT)),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($dayStart, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return (int) $count;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function recentTurns(int $limit = 25): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('correlation_id', 'be_user', 'tool_call_count', 'guard_state', 'crdate')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values($rows);
    }

    /**
     * @return array<string, scalar|null>|null
     */
    private function findByCorrelationId(string $correlationId): ?array
    {
        if ($correlationId === '') {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('correlation_id', $qb->createNamedParameter($correlationId)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
