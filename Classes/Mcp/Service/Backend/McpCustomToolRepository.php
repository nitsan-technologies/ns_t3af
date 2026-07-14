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
readonly class McpCustomToolRepository
{
    public const TABLE = 'tx_nst3af_mcp_custom_tool';

    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * @return list<array{
     *     uid: int,
     *     toolKey: string,
     *     label: string,
     *     description: string,
     *     handlerType: string,
     *     handlerValue: string,
     *     parameters: list<array<string, mixed>>,
     *     hidden: bool,
     *     deleted: bool,
     *     crdate: int,
     *     tstamp: int
     * }>
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
            ->orderBy('label', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn(array $row): array => $this->mapRow($row), $rows);
    }

    public function findByToolKey(string $toolKey): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('tool_key', $queryBuilder->createNamedParameter($toolKey)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->mapRow($row) : null;
    }

    public function findByUid(int $uid): ?array
    {
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
     * @param list<array<string, mixed>> $parameters
     */
    public function insert(
        string $toolKey,
        string $label,
        string $description,
        string $handlerType,
        string $handlerValue,
        array $parameters,
    ): int {
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'tool_key' => $toolKey,
            'label' => $label,
            'description' => $description,
            'handler_type' => $handlerType,
            'handler_value' => $handlerValue,
            'parameters_json' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'hidden' => 0,
            'deleted' => 0,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return (int) $connection->lastInsertId();
    }

    /**
     * @param list<array<string, mixed>> $parameters
     */
    public function update(
        int $uid,
        string $label,
        string $description,
        string $handlerType,
        string $handlerValue,
        array $parameters,
    ): void {
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'label' => $label,
            'description' => $description,
            'handler_type' => $handlerType,
            'handler_value' => $handlerValue,
            'parameters_json' => json_encode($parameters, JSON_THROW_ON_ERROR),
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
     * @param array<string, mixed> $row
     * @return array{
     *     uid: int,
     *     toolKey: string,
     *     label: string,
     *     description: string,
     *     handlerType: string,
     *     handlerValue: string,
     *     parameters: list<array<string, mixed>>,
     *     hidden: bool,
     *     deleted: bool,
     *     crdate: int,
     *     tstamp: int
     * }
     */
    private function mapRow(array $row): array
    {
        $parameters = [];
        $raw = (string) ($row['parameters_json'] ?? '');
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $parameters = $decoded;
                }
            } catch (\JsonException) {
                $parameters = [];
            }
        }

        return [
            'uid' => (int) ($row['uid'] ?? 0),
            'toolKey' => (string) ($row['tool_key'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'handlerType' => (string) ($row['handler_type'] ?? 'php'),
            'handlerValue' => (string) ($row['handler_value'] ?? ''),
            'parameters' => $parameters,
            'hidden' => (int) ($row['hidden'] ?? 0) === 1,
            'deleted' => (int) ($row['deleted'] ?? 0) === 1,
            'crdate' => (int) ($row['crdate'] ?? 0),
            'tstamp' => (int) ($row['tstamp'] ?? 0),
        ];
    }
}
