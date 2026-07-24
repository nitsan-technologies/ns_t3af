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
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
readonly class McpIpAllowlistRepository
{
    public const TABLE = 'tx_nst3af_mcp_ip_allowlist';

    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * @return list<array{uid:int,label:string,cidr:string,enabled:bool,crdate:int}>
     */
    public function findAll(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_map(fn(array $row): array => $this->mapRow($row), $rows));
    }

    /**
     * @return list<array{uid:int,label:string,cidr:string,enabled:bool,crdate:int}>
     */
    public function findEnabled(): array
    {
        return array_values(array_filter(
            $this->findAll(),
            static fn(array $row): bool => $row['enabled'],
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->mapRow($row) : null;
    }

    public function insert(string $label, string $cidr, bool $enabled = true): int
    {
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'label' => $label,
            'cidr' => $cidr,
            'enabled' => $enabled ? 1 : 0,
            'crdate' => $now,
        ]);

        return (int) $connection->lastInsertId();
    }

    public function update(int $uid, string $label, string $cidr, bool $enabled): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'label' => $label,
            'cidr' => $cidr,
            'enabled' => $enabled ? 1 : 0,
        ], ['uid' => $uid]);
    }

    public function delete(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->delete(self::TABLE, ['uid' => $uid]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{uid:int,label:string,cidr:string,enabled:bool,crdate:int}
     */
    private function mapRow(array $row): array
    {
        return [
            'uid' => (int) ($row['uid'] ?? 0),
            'label' => (string) ($row['label'] ?? ''),
            'cidr' => (string) ($row['cidr'] ?? ''),
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'crdate' => (int) ($row['crdate'] ?? 0),
        ];
    }
}
