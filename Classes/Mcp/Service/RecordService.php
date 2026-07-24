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

namespace NITSAN\NsT3AF\Mcp\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

readonly class RecordService
{
    public function __construct(private ConnectionPool $connectionPool, private WorkspaceContextService $workspaceContext) {}

    /**
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function findByUid(string $table, int $uid, array $fields): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        $row = $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return $this->workspaceContext->overlay($table, $row);
    }

    /**
     * Return the subset of UIDs that actually exist in the given table.
     *
     * @param list<int> $uids
     * @return list<int>
     */
    public function findExistingUids(string $table, array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        /** @var list<array{uid: int|string}> $rows */
        $rows = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where($queryBuilder->expr()->in(
                'uid',
                $queryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER),
            ))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int) $row['uid'], $rows);
    }

    /**
     * @param list<string> $fields
     * @return array{records: list<array<string, mixed>>, total: int}
     */
    public function findByPid(
        string $table,
        int $pid,
        int $limit,
        int $offset,
        array $fields,
        ?int $sysLanguageUid = null,
        ?string $languageField = null,
    ): array {
        $limit = min(max($limit, 1), 500);
        $offset = max(0, $offset);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $countQueryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($countQueryBuilder, $table);
        $countQueryBuilder
            ->count('uid')
            ->from($table)
            ->where($countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pid, ParameterType::INTEGER)));

        $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)));

        if ($sysLanguageUid !== null && $languageField !== null) {
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->eq(
                    $languageField,
                    $countQueryBuilder->createNamedParameter($sysLanguageUid, ParameterType::INTEGER),
                ),
            );
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($sysLanguageUid, ParameterType::INTEGER)),
            );
        }

        /** @var int|string $totalResult */
        $totalResult = $countQueryBuilder->executeQuery()->fetchOne();

        $records = $queryBuilder
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy($this->defaultOrderByField($table), 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $records = $this->workspaceContext->overlayMany($table, $records);

        return [
            'records' => $records,
            'total' => (int) $totalResult,
        ];
    }

    /**
     * Sorted tables (pages, tt_content, …) must be listed in their TCA
     * `sortby` order — listing by uid reports creation order, not the
     * on-page/tree order the MCP client expects (CM-02).
     */
    private function defaultOrderByField(string $table): string
    {
        $sortby = $GLOBALS['TCA'][$table]['ctrl']['sortby'] ?? '';

        return is_string($sortby) && $sortby !== '' ? $sortby : 'uid';
    }

    /**
     * @param list<string> $fields
     * @param array<string, array{operator: string, value: string}> $searchConditions field => {operator, value}
     * @return array{records: list<array<string, mixed>>, total: int}
     */
    public function search(
        string $table,
        array $searchConditions,
        int $limit,
        int $offset,
        array $fields,
        ?int $pid = null,
        ?string $orderBy = null,
        string $orderDirection = 'ASC',
    ): array {
        $limit = min(max($limit, 1), 500);
        $offset = max(0, $offset);

        if (!in_array($orderDirection, ['ASC', 'DESC'], true)) {
            $orderDirection = 'ASC';
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);
        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $countQueryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($countQueryBuilder, $table);

        $queryBuilder->select(...$fields)->from($table);
        $countQueryBuilder->count('uid')->from($table);

        if ($pid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            );
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            );
        }

        foreach ($searchConditions as $field => $condition) {
            $this->applyCondition($queryBuilder, $field, $condition);
            $this->applyCondition($countQueryBuilder, $field, $condition);
        }

        /** @var int|string $totalResult */
        $totalResult = $countQueryBuilder->executeQuery()->fetchOne();

        $records = $queryBuilder
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy($orderBy ?? 'uid', $orderDirection)
            ->executeQuery()
            ->fetchAllAssociative();

        $records = $this->workspaceContext->overlayMany($table, $records);

        return [
            'records' => $records,
            'total' => (int) $totalResult,
        ];
    }

    /** @param array{operator: string, value: string} $condition */
    private function applyCondition(QueryBuilder $queryBuilder, string $field, array $condition): void
    {
        $operator = $condition['operator'];
        $value = $condition['value'];
        $expr = $queryBuilder->expr();

        $queryBuilder->andWhere(match ($operator) {
            'eq' => $expr->eq($field, $queryBuilder->createNamedParameter($value)),
            'neq' => $expr->neq($field, $queryBuilder->createNamedParameter($value)),
            'gt' => $expr->gt($field, $queryBuilder->createNamedParameter($value)),
            'gte' => $expr->gte($field, $queryBuilder->createNamedParameter($value)),
            'lt' => $expr->lt($field, $queryBuilder->createNamedParameter($value)),
            'lte' => $expr->lte($field, $queryBuilder->createNamedParameter($value)),
            'in' => $expr->in(
                $field,
                $queryBuilder->createNamedParameter(
                    array_map('trim', explode(',', $value)),
                    ArrayParameterType::STRING,
                ),
            ),
            'null' => $expr->isNull($field),
            'notNull' => $expr->isNotNull($field),
            default => $expr->like($field, $queryBuilder->createNamedParameter('%' . $value . '%')),
        });
    }

    /**
     * Count records matching optional conditions without fetching them.
     *
     * @param array<string, array{operator: string, value: string}> $searchConditions
     */
    public function count(string $table, ?int $pid = null, array $searchConditions = []): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        $queryBuilder->count('uid')->from($table);

        if ($pid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            );
        }

        foreach ($searchConditions as $field => $condition) {
            $this->applyCondition($queryBuilder, $field, $condition);
        }

        /** @var int|string $result */
        $result = $queryBuilder->executeQuery()->fetchOne();

        return (int) $result;
    }

    /**
     * Find all file references for a record field.
     *
     * @return list<array<string, mixed>>
     */
    public function findFileReferences(string $table, int $uid, string $fieldName): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, 'sys_file_reference');

        $rows = $queryBuilder
            ->select('uid', 'uid_local', 'title', 'description', 'alternative', 'link', 'crop', 'autoplay', 'sorting_foreign')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->andWhere($queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)))
            ->andWhere($queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($fieldName)))
            ->orderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->workspaceContext->overlayMany('sys_file_reference', $rows);
    }

    /**
     * Find all translations of a record.
     *
     * @return list<array{uid: int, sys_language_uid: int}>
     */
    public function findTranslations(string $table, int $uid, string $languageField, string $transOrigPointerField): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        /** @var list<array{uid: int|string, sys_language_uid: int|string}> $rows */
        $rows = $queryBuilder
            ->select('uid', $languageField . ' AS sys_language_uid')
            ->from($table)
            ->where($queryBuilder->expr()->eq($transOrigPointerField, $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->orderBy($languageField, 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): array => [
                'uid' => (int) $row['uid'],
                'sys_language_uid' => (int) $row['sys_language_uid'],
            ],
            $rows,
        );
    }
}
