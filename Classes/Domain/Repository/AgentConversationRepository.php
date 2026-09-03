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

namespace NITSAN\NsT3AF\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Persists module-scoped AI Agent conversations per backend user.
 */
final class AgentConversationRepository
{
    public const TABLE = 'tx_nst3af_agent_conversation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findByScope(int $beUserUid, string $moduleRoute, int $pageId): ?array
    {
        if ($beUserUid <= 0) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('be_user_uid', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('module_route', $qb->createNamedParameter($moduleRoute)),
                $qb->expr()->eq('page_id', $qb->createNamedParameter($pageId, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array{messages?: list<array<string, mixed>>, context?: array<string, mixed>} $payload
     */
    public function save(int $beUserUid, string $moduleRoute, int $pageId, array $payload): void
    {
        if ($beUserUid <= 0) {
            return;
        }

        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $messagesJson = json_encode($payload['messages'] ?? [], JSON_THROW_ON_ERROR);
        $contextJson = json_encode($payload['context'] ?? [], JSON_THROW_ON_ERROR);

        $existing = $this->findByScope($beUserUid, $moduleRoute, $pageId);
        if ($existing !== null) {
            $this->connection()->update(
                self::TABLE,
                [
                    'messages' => $messagesJson,
                    'context' => $contextJson,
                    'tstamp' => $now,
                ],
                ['uid' => (int) $existing['uid']],
            );

            return;
        }

        $this->connection()->insert(self::TABLE, [
            'be_user_uid' => $beUserUid,
            'module_route' => $moduleRoute,
            'page_id' => max(0, $pageId),
            'messages' => $messagesJson,
            'context' => $contextJson,
            'crdate' => $now,
            'tstamp' => $now,
        ]);
    }

    public function deleteOlderThan(int $cutoffTimestamp): int
    {
        if ($cutoffTimestamp <= 0) {
            return 0;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        return $qb->delete(self::TABLE)
            ->where($qb->expr()->lt('tstamp', $qb->createNamedParameter($cutoffTimestamp, Connection::PARAM_INT)))
            ->executeStatement();
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
