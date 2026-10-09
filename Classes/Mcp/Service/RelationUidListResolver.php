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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Service;

use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Replaces MM / category parent counters with comma-separated related UIDs
 * (same shape as write_table accepts: "126" or "8,12").
 */
readonly class RelationUidListResolver
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaService $tcaSchemaService,
    ) {}

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function enrichRecord(string $table, array $record): array
    {
        $enriched = $this->enrichRecords($table, [$record]);

        return $enriched[0] ?? $record;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function enrichRecords(string $table, array $records): array
    {
        if ($records === []) {
            return [];
        }

        $relationFields = $this->tcaSchemaService->getReadableRelationUidListFields($table);
        if ($relationFields === []) {
            return $records;
        }

        $presentFields = [];
        foreach ($relationFields as $fieldName) {
            foreach ($records as $record) {
                if (array_key_exists($fieldName, $record)) {
                    $presentFields[] = $fieldName;
                    break;
                }
            }
        }

        if ($presentFields === []) {
            return $records;
        }

        $recordUids = [];
        foreach ($records as $record) {
            $uid = (int) ($record['uid'] ?? 0);
            if ($uid > 0) {
                $recordUids[] = $uid;
            }
        }
        $recordUids = array_values(array_unique($recordUids));
        if ($recordUids === []) {
            return $records;
        }

        /** @var array<string, array<int, list<int>>> $uidsByFieldAndRecord */
        $uidsByFieldAndRecord = [];
        foreach ($presentFields as $fieldName) {
            $uidsByFieldAndRecord[$fieldName] = $this->resolveFieldUids($table, $fieldName, $recordUids);
        }

        $result = [];
        foreach ($records as $record) {
            $uid = (int) ($record['uid'] ?? 0);
            foreach ($presentFields as $fieldName) {
                if (!array_key_exists($fieldName, $record)) {
                    continue;
                }
                $related = $uidsByFieldAndRecord[$fieldName][$uid] ?? [];
                $record[$fieldName] = $related === []
                    ? ''
                    : implode(',', array_map(static fn(int $id): string => (string) $id, $related));
            }
            $result[] = $record;
        }

        return $result;
    }

    /**
     * @param list<int> $recordUids
     * @return array<int, list<int>>
     */
    private function resolveFieldUids(string $table, string $fieldName, array $recordUids): array
    {
        $config = $this->tcaSchemaService->getColumnFieldConfig($table, $fieldName);
        if ($config === null) {
            return [];
        }

        $type = $config['type'] ?? null;
        if ($type === 'category') {
            $mmTable = is_string($config['MM'] ?? null) && $config['MM'] !== ''
                ? $config['MM']
                : 'sys_category_record_mm';

            return $this->fetchMmMap(
                $mmTable,
                $recordUids,
                localColumn: 'uid_foreign',
                relatedColumn: 'uid_local',
                matchFields: [
                    'tablenames' => $table,
                    'fieldname' => $fieldName,
                ],
            );
        }

        $mmTable = $config['MM'] ?? null;
        if (!is_string($mmTable) || $mmTable === '') {
            return [];
        }

        $opposite = isset($config['MM_opposite_field']);
        $matchFields = [];
        $rawMatch = $config['MM_match_fields'] ?? null;
        if (is_array($rawMatch)) {
            foreach ($rawMatch as $key => $value) {
                if (is_string($key) && (is_string($value) || is_int($value))) {
                    $matchFields[$key] = (string) $value;
                }
            }
        }

        return $this->fetchMmMap(
            $mmTable,
            $recordUids,
            localColumn: $opposite ? 'uid_foreign' : 'uid_local',
            relatedColumn: $opposite ? 'uid_local' : 'uid_foreign',
            matchFields: $matchFields,
        );
    }

    /**
     * @param list<int> $recordUids
     * @param array<string, string> $matchFields
     * @return array<int, list<int>>
     */
    private function fetchMmMap(
        string $mmTable,
        array $recordUids,
        string $localColumn,
        string $relatedColumn,
        array $matchFields,
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($mmTable);
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select($localColumn, $relatedColumn)
            ->from($mmTable)
            ->where($queryBuilder->expr()->in(
                $localColumn,
                $queryBuilder->createNamedParameter($recordUids, ArrayParameterType::INTEGER),
            ));

        foreach ($matchFields as $column => $value) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $column,
                    $queryBuilder->createNamedParameter($value),
                ),
            );
        }

        $queryBuilder->orderBy($localColumn, 'ASC')->addOrderBy($relatedColumn, 'ASC');

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        /** @var array<int, list<int>> $map */
        $map = [];
        foreach ($recordUids as $uid) {
            $map[$uid] = [];
        }

        foreach ($rows as $row) {
            $localUid = (int) ($row[$localColumn] ?? 0);
            $relatedUid = (int) ($row[$relatedColumn] ?? 0);
            if ($localUid <= 0 || $relatedUid <= 0) {
                continue;
            }
            $map[$localUid][] = $relatedUid;
        }

        return $map;
    }
}
