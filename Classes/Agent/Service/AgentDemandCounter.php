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
 * Local-only locked-tool activation counts (T20).
 *
 * @internal
 */
final readonly class AgentDemandCounter
{
    public const TABLE = 'tx_nst3af_agent_demand';

    public function __construct(private ConnectionPool $connectionPool) {}

    public function recordActivation(string $ownerExtensionKey, string $toolName, int $beUserId): void
    {
        $ownerExtensionKey = trim($ownerExtensionKey);
        $toolName = trim($toolName);
        if ($ownerExtensionKey === '' || $toolName === '') {
            return;
        }

        $existing = $this->findRow($ownerExtensionKey, $toolName);
        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());

        if ($existing !== null) {
            $this->connection()->update(
                self::TABLE,
                [
                    'activation_count' => (int) ($existing['activation_count'] ?? 0) + 1,
                    'last_activated' => $now,
                    'be_user' => max(0, $beUserId),
                    'tstamp' => $now,
                ],
                ['uid' => (int) $existing['uid']],
            );

            return;
        }

        $this->connection()->insert(self::TABLE, [
            'pid' => 0,
            'owner_extension_key' => $ownerExtensionKey,
            'tool_name' => $toolName,
            'activation_count' => 1,
            'last_activated' => $now,
            'be_user' => max(0, $beUserId),
            'crdate' => $now,
            'tstamp' => $now,
        ]);
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function topSignals(int $limit = 20): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        return array_values($qb->select('*')
            ->from(self::TABLE)
            ->orderBy('activation_count', 'DESC')
            ->addOrderBy('last_activated', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative());
    }

    public function totalActivations(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        return (int) $qb->count('uid')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array<string, scalar|null>|null
     */
    private function findRow(string $ownerExtensionKey, string $toolName): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('owner_extension_key', $qb->createNamedParameter($ownerExtensionKey)),
                $qb->expr()->eq('tool_name', $qb->createNamedParameter($toolName)),
            )
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
