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

use const JSON_THROW_ON_ERROR;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
readonly class McpPromptTemplateRepository
{
    public const TABLE = 'tx_nst3af_mcp_prompt_template';

    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * @return list<array{uid:int,name:string,description:string,templateBody:string,arguments:array<int, array<string, mixed>>,hidden:bool,deleted:bool,crdate:int,tstamp:int}>
     */
    public function findVisible(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_map(fn(array $row): array => $this->mapRow($row), $rows));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('name', $queryBuilder->createNamedParameter($name)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->mapRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAnyByName(string $name): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('name', $queryBuilder->createNamedParameter($name)),
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->mapRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->mapRow($row) : null;
    }

    public function countVisible(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @param array<int, array<string, mixed>> $arguments
     */
    public function insert(
        string $name,
        string $description,
        string $templateBody,
        array $arguments,
    ): int {
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'name' => $name,
            'description' => $description,
            'template_body' => $templateBody,
            'arguments_json' => json_encode($arguments, JSON_THROW_ON_ERROR),
            'hidden' => 0,
            'deleted' => 0,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return (int) $connection->lastInsertId();
    }

    /**
     * @param array<int, array<string, mixed>> $arguments
     */
    public function update(
        int $uid,
        string $name,
        string $description,
        string $templateBody,
        array $arguments,
    ): void {
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'name' => $name,
            'description' => $description,
            'template_body' => $templateBody,
            'arguments_json' => json_encode($arguments, JSON_THROW_ON_ERROR),
            'tstamp' => $now,
        ], ['uid' => $uid]);
    }

    public function softDelete(int $uid): void
    {
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'deleted' => 1,
            'tstamp' => $now,
        ], ['uid' => $uid]);
    }

    /**
     * @param array<int, array<string, mixed>> $arguments
     */
    public function restore(
        int $uid,
        string $description,
        string $templateBody,
        array $arguments,
    ): void {
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'description' => $description,
            'template_body' => $templateBody,
            'arguments_json' => json_encode($arguments, JSON_THROW_ON_ERROR),
            'hidden' => 0,
            'deleted' => 0,
            'tstamp' => $now,
        ], ['uid' => $uid]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{uid:int,name:string,description:string,templateBody:string,arguments:array<int, array<string, mixed>>,hidden:bool,deleted:bool,crdate:int,tstamp:int}
     */
    private function mapRow(array $row): array
    {
        $arguments = [];
        $raw = (string) ($row['arguments_json'] ?? '');
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $arguments = $decoded;
                }
            } catch (\JsonException) {
                $arguments = [];
            }
        }

        return [
            'uid' => (int) ($row['uid'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'templateBody' => (string) ($row['template_body'] ?? ''),
            'arguments' => $arguments,
            'hidden' => (int) ($row['hidden'] ?? 0) === 1,
            'deleted' => (int) ($row['deleted'] ?? 0) === 1,
            'crdate' => (int) ($row['crdate'] ?? 0),
            'tstamp' => (int) ($row['tstamp'] ?? 0),
        ];
    }
}
