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

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
readonly class McpSkillRepository
{
    public const TABLE = 'tx_nst3af_mcp_skill';

    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * @return list<array{uid:int,name:string,triggerKeyword:string,version:string,source:string,sourceUrl:string,body:string,tags:string,crdate:int,tstamp:int}>
     */
    public function findAll(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn(array $row): array => $this->mapRow($row), $rows);
    }

    public function findByTriggerKeyword(string $triggerKeyword): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('trigger_keyword', $queryBuilder->createNamedParameter($triggerKeyword)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->mapRow($row) : null;
    }

    /**
     * @param list<string> $tags
     */
    public function insert(
        string $name,
        string $triggerKeyword,
        string $version,
        string $source,
        string $sourceUrl,
        string $body,
        array $tags,
    ): int {
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'name' => $name,
            'trigger_keyword' => $triggerKeyword,
            'version' => $version,
            'source' => $source,
            'source_url' => $sourceUrl,
            'body' => $body,
            'tags' => implode(',', $tags),
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return (int) $connection->lastInsertId();
    }

    public function update(
        int $uid,
        string $name,
        string $triggerKeyword,
        string $version,
        string $source,
        string $sourceUrl,
        string $body,
        string $tags,
    ): void {
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'name' => $name,
            'trigger_keyword' => $triggerKeyword,
            'version' => $version,
            'source' => $source,
            'source_url' => $sourceUrl,
            'body' => $body,
            'tags' => $tags,
            'tstamp' => $now,
        ], ['uid' => $uid]);
    }

    public function delete(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->delete(self::TABLE, ['uid' => $uid]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{uid:int,name:string,triggerKeyword:string,version:string,source:string,sourceUrl:string,body:string,tags:string,crdate:int,tstamp:int}
     */
    private function mapRow(array $row): array
    {
        return [
            'uid' => (int) ($row['uid'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'triggerKeyword' => (string) ($row['trigger_keyword'] ?? ''),
            'version' => (string) ($row['version'] ?? '1.0.0'),
            'source' => (string) ($row['source'] ?? 'local'),
            'sourceUrl' => (string) ($row['source_url'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'tags' => (string) ($row['tags'] ?? ''),
            'crdate' => (int) ($row['crdate'] ?? 0),
            'tstamp' => (int) ($row['tstamp'] ?? 0),
        ];
    }
}
