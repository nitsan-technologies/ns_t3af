<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


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

namespace NITSAN\NsT3AF\Mcp\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

readonly class SessionRepository
{
    private const TABLE = 'tx_nst3af_mcp_session';

    public function __construct(private ConnectionPool $connectionPool) {}

    /** @return array{session_id: string, data: string, last_activity: int}|null */
    public function findBySessionId(string $sessionId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        /** @var array{session_id: string, data: string|resource|null, last_activity: int}|false $row */
        $row = $queryBuilder
            ->select('session_id', 'data', 'last_activity')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('session_id', $queryBuilder->createNamedParameter($sessionId)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $rawData = $row['data'];
        $data = is_resource($rawData) ? (string) stream_get_contents($rawData) : (is_string($rawData) ? $rawData : '');

        return [
            'session_id' => $row['session_id'],
            'data' => $data,
            'last_activity' => $row['last_activity'],
        ];
    }

    public function touch(string $sessionId, int $now): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            ['last_activity' => $now],
            ['session_id' => $sessionId],
        );
    }

    public function upsert(string $sessionId, string $data, int $now): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        try {
            $connection->insert(
                self::TABLE,
                [
                    'session_id' => $sessionId,
                    'data' => $data,
                    'last_activity' => $now,
                    'crdate' => $now,
                ],
                ['data' => ParameterType::LARGE_OBJECT],
            );

            return;
        } catch (UniqueConstraintViolationException) {
            // Row already exists — fall through to UPDATE.
        }

        $connection->update(
            self::TABLE,
            ['data' => $data, 'last_activity' => $now],
            ['session_id' => $sessionId],
            ['data' => ParameterType::LARGE_OBJECT],
        );
    }

    public function delete(string $sessionId): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $affected = $connection->delete(self::TABLE, ['session_id' => $sessionId]);

        return $affected > 0;
    }

    public function deleteExpired(int $cutoff): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return (int) $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->lt(
                    'last_activity',
                    $queryBuilder->createNamedParameter($cutoff, ParameterType::INTEGER),
                ),
            )
            ->executeStatement();
    }
}
