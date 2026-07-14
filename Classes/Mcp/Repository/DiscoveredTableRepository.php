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

namespace NITSAN\NsT3AF\Mcp\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Persistence for TCA-discovered extension tables exposed as MCP tools.
 */
readonly class DiscoveredTableRepository
{
    private const TABLE = 'tx_nst3af_mcp_discovered_table';

    public function __construct(private ConnectionPool $connectionPool) {}

    /** @return list<array{uid: int, table_name: string, label: string, prefix: string, enabled: int}> */
    public function findAll(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        /** @var list<array{uid: int, table_name: string, label: string, prefix: string, enabled: int}> $rows */
        $rows = $queryBuilder
            ->select('uid', 'table_name', 'label', 'prefix', 'enabled')
            ->from(self::TABLE)
            ->orderBy('table_name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    /** @return list<array{uid: int, table_name: string, label: string, prefix: string, enabled: int}> */
    public function findEnabled(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        /** @var list<array{uid: int, table_name: string, label: string, prefix: string, enabled: int}> $rows */
        $rows = $queryBuilder
            ->select('uid', 'table_name', 'label', 'prefix', 'enabled')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('enabled', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)))
            ->orderBy('table_name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    /** @return array{uid: int, table_name: string, label: string, prefix: string, enabled: int}|null */
    public function findByUid(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        /** @var array{uid: int, table_name: string, label: string, prefix: string, enabled: int}|false $row */
        $row = $queryBuilder
            ->select('uid', 'table_name', 'label', 'prefix', 'enabled')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    public function insertIfNew(string $tableName, string $label, string $prefix): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        try {
            $connection->insert(self::TABLE, [
                'table_name' => $tableName,
                'label' => $label,
                'prefix' => $prefix,
                'enabled' => 0,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function update(int $uid, string $label, string $prefix): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'label' => $label,
            'prefix' => $prefix,
        ], ['uid' => $uid]);
    }

    public function setEnabled(int $uid, bool $enabled): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'enabled' => $enabled ? 1 : 0,
        ], ['uid' => $uid]);
    }
}
