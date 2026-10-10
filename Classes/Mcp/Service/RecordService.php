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
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

readonly class RecordService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private WorkspaceContextService $workspaceContext,
        private RelationUidListResolver $relationUidListResolver,
        private PageAccessService $pageAccess,
    ) {}

    /**
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function findByUid(string $table, int $uid, array $fields): ?array
    {
        if ($fields === []) {
            throw new \InvalidArgumentException('fields must not be empty when loading a record.', 1790400010);
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        $selectFields = $this->workspaceContext->withOverlayFields($table, $fields);
        $anchorColumn = $this->pageAccess->anchorColumn($table);
        $anchorWasRequested = in_array($anchorColumn, $selectFields, true) || in_array('*', $selectFields, true);
        if (!$anchorWasRequested) {
            $selectFields[] = $anchorColumn;
        }

        $row = $queryBuilder
            ->select(...$selectFields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $row = $this->workspaceContext->overlay($table, $row);
        if ($row === null) {
            return null;
        }
        if (!$this->pageAccess->canReadRecord($table, $row)) {
            return null;
        }
        $row = $this->workspaceContext->stripOverlayFields($row, $fields);
        if (!$anchorWasRequested) {
            unset($row[$anchorColumn]);
        }

        return $this->relationUidListResolver->enrichRecord($table, $row);
    }

    /**
     * A positive page id the editor may create or move under: present, not deleted, and visible
     * in the current workspace. Admins are included; a soft-deleted parent is not a valid target.
     */
    public function parentPageExists(int $pageId): bool
    {
        if ($pageId <= 0) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, 'pages');

        $row = $queryBuilder
            ->select(...$this->workspaceContext->withOverlayFields('pages', ['uid']))
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return false;
        }

        return $this->workspaceContext->overlay('pages', $row) !== null;
    }

    /**
     * pid 0 (site root) and negative "insert after" targets are left to the caller.
     */
    public function assertParentPageExists(int $pageId): void
    {
        if ($pageId <= 0 || $this->parentPageExists($pageId)) {
            return;
        }

        throw new \InvalidArgumentException(
            'Parent page ' . $pageId . ' does not exist or was deleted. Choose another page.',
        );
    }

    /**
     * The record a negative pid inserts after: present, not deleted, and visible in this workspace.
     */
    public function assertInsertAfterExists(string $table, int $uid): void
    {
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Create requires a record to insert after.');
        }

        if ($table === 'pages') {
            if ($this->parentPageExists($uid)) {
                return;
            }

            throw new \InvalidArgumentException(
                'Page ' . $uid . ' does not exist or was deleted. Choose another page.',
            );
        }

        if ($this->findExistingUids($table, [$uid]) === []) {
            throw new \InvalidArgumentException('Record not found: ' . $table . ' uid ' . $uid);
        }
    }

    /**
     * DataHandler target that places a record directly before this page.
     * The previous default-language sibling becomes a negative "after" target.
     * The page being moved is skipped. The first child uses the parent pid.
     */
    public function targetBeforePage(int $pageUid, int $movingUid = 0): int
    {
        if ($pageUid <= 0 || !$this->parentPageExists($pageUid)) {
            throw new \InvalidArgumentException(
                'Page ' . $pageUid . ' does not exist or was deleted. Choose another page.',
            );
        }

        $page = $this->findByUid('pages', $pageUid, ['pid', 'sorting']);
        if ($page === null) {
            throw new \InvalidArgumentException(
                'Page ' . $pageUid . ' does not exist or was deleted. Choose another page.',
            );
        }

        $pid = (int) ($page['pid'] ?? 0);
        $sorting = (int) ($page['sorting'] ?? 0);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, 'pages');

        $constraints = [
            $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            $queryBuilder->expr()->lt('sorting', $queryBuilder->createNamedParameter($sorting, ParameterType::INTEGER)),
        ];
        if ($movingUid > 0) {
            $constraints[] = $queryBuilder->expr()->neq(
                'uid',
                $queryBuilder->createNamedParameter($movingUid, ParameterType::INTEGER),
            );
        }

        $previous = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(...$constraints)
            ->orderBy('sorting', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($previous !== false) {
            return -((int) $previous['uid']);
        }

        return $pid;
    }

    /**
     * Default-language pages with this exact title.
     *
     * @return list<array{uid: int, pid: int}>
     */
    public function findDefaultLanguagePagesByTitle(string $title): array
    {
        $title = trim($title);
        if ($title === '') {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, 'pages');

        $rows = $queryBuilder
            ->select('uid', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($title)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->setMaxResults(10)
            ->executeQuery()
            ->fetchAllAssociative();

        $pages = [];
        foreach ($rows as $row) {
            $pages[] = ['uid' => (int) $row['uid'], 'pid' => (int) $row['pid']];
        }

        return $pages;
    }

    /**
     * The last default-language child of this page, or 0 when it has none.
     */
    public function lastDefaultLanguageChildUid(int $parentPid): int
    {
        if ($parentPid <= 0) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, 'pages');

        $row = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentPid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? 0 : (int) $row['uid'];
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
            ->select('uid', $this->pageAccess->anchorColumn($table))
            ->from($table)
            ->where($queryBuilder->expr()->in(
                'uid',
                $queryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER),
            ))
            ->executeQuery()
            ->fetchAllAssociative();

        $rows = array_filter($rows, fn(array $row): bool => $this->pageAccess->canReadRecord($table, $row));

        return array_values(array_map(static fn(array $row): int => (int) $row['uid'], $rows));
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
            ->select(...$this->workspaceContext->withOverlayFields($table, $fields))
            ->from($table)
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)));

        $this->applyPageAccessConstraint($queryBuilder, $table, $pid);
        $this->applyPageAccessConstraint($countQueryBuilder, $table, $pid);

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
        $records = array_map(fn(array $r): array => $this->workspaceContext->stripOverlayFields($r, $fields), $records);
        $records = $this->relationUidListResolver->enrichRecords($table, $records);

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

        $resolvedOrderBy = $this->resolveOrderByField($table, $orderBy);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);
        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $countQueryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($countQueryBuilder, $table);

        $queryBuilder->select(...$this->workspaceContext->withOverlayFields($table, $fields))->from($table);
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

        $this->applyPageAccessConstraint($queryBuilder, $table, $pid);
        $this->applyPageAccessConstraint($countQueryBuilder, $table, $pid);

        /** @var int|string $totalResult */
        $totalResult = $countQueryBuilder->executeQuery()->fetchOne();

        $records = $queryBuilder
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy($resolvedOrderBy, $orderDirection)
            ->executeQuery()
            ->fetchAllAssociative();

        $records = $this->workspaceContext->overlayMany($table, $records);
        $records = array_map(fn(array $r): array => $this->workspaceContext->stripOverlayFields($r, $fields), $records);
        $records = $this->relationUidListResolver->enrichRecords($table, $records);

        return [
            'records' => $records,
            'total' => (int) $totalResult,
        ];
    }

    /**
     * Restrict a query to records the current backend user may read through the
     * page they live on (web mounts + "show" permission). Admins are unrestricted.
     *
     * For pages the anchor is the page uid itself, for every other table its pid;
     * the distinct anchors of the table are resolved against the page permissions
     * once and then applied as an IN() constraint so limit/offset/total stay correct.
     */
    private function applyPageAccessConstraint(QueryBuilder $queryBuilder, string $table, ?int $pid): void
    {
        if ($this->pageAccess->isUnrestricted()) {
            return;
        }

        $column = $this->pageAccess->anchorColumn($table);

        // Non-page table scoped to one page: a single page check is enough.
        if ($table !== 'pages' && $pid !== null) {
            if ($pid !== 0 && !$this->pageAccess->canReadPage($pid)) {
                $queryBuilder->andWhere('1 = 0');
            }

            return;
        }

        $anchorQuery = $this->connectionPool->getQueryBuilderForTable($table);
        $anchorQuery->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($anchorQuery, $table);
        $anchorQuery->select($column)->distinct()->from($table);
        if ($pid !== null) {
            $anchorQuery->andWhere($anchorQuery->expr()->eq('pid', $anchorQuery->createNamedParameter($pid, ParameterType::INTEGER)));
        }

        /** @var list<int|string> $anchors */
        $anchors = $anchorQuery->executeQuery()->fetchFirstColumn();
        $allowed = $this->pageAccess->filterAllowedAnchors($table, array_map('intval', $anchors));

        if ($allowed === []) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder->andWhere($queryBuilder->expr()->in(
            $column,
            $queryBuilder->createNamedParameter($allowed, ArrayParameterType::INTEGER),
        ));
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
     * Prefer a real storage folder over a random page that happens to hold more rows:
     * pages.module=news, then sysfolder (doktype 254), then highest row count.
     * Used when a create omits storage (e.g. news while the Layout page is open).
     */
    public function preferredPidForTable(string $table): int
    {
        if ($table === '' || $table === 'pages') {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);
        $queryBuilder
            ->select('pid')
            ->addSelectLiteral('COUNT(*) AS c')
            ->from($table)
            ->andWhere($queryBuilder->expr()->gt('pid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)))
            ->groupBy('pid')
            ->orderBy('c', 'DESC')
            ->setMaxResults(50);

        /** @var list<array{pid: int|string, c: int|string}> $rows */
        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        $bestPid = 0;
        $bestScore = -1;
        foreach ($rows as $row) {
            $pid = (int) ($row['pid'] ?? 0);
            if ($pid <= 0 || !$this->pageAccess->canReadPage($pid)) {
                continue;
            }
            $page = $this->pageMeta($pid);
            $score = self::scorePreferredStoragePid(
                (int) ($row['c'] ?? 0),
                (int) ($page['doktype'] ?? 0),
                (string) ($page['module'] ?? ''),
            );
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPid = $pid;
            }
        }

        return $bestPid;
    }

    /**
     * True when the page is a news storage folder (module=news) or a sysfolder (doktype 254).
     * Ordinary pages (e.g. Privacy) must not keep a model-supplied pid on news create.
     */
    public function isSuitableNewsStoragePid(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        $page = $this->pageMeta($pid);

        return self::isSuitableNewsStorage(
            (int) ($page['doktype'] ?? 0),
            (string) ($page['module'] ?? ''),
        );
    }

    /**
     * @internal unit-tested
     */
    public static function isSuitableNewsStorage(int $doktype, string $module): bool
    {
        return strtolower(trim($module)) === 'news' || $doktype === 254;
    }

    /**
     * @internal unit-tested
     */
    public static function scorePreferredStoragePid(int $recordCount, int $doktype, string $module): int
    {
        $score = max(0, $recordCount);
        if (strtolower(trim($module)) === 'news') {
            $score += 10000;
        }
        // 254 = sysfolder — typical EXT:news storage, beat a higher count on a normal page.
        if ($doktype === 254) {
            $score += 1000;
        }

        return $score;
    }

    /**
     * @return array{doktype?: int|string, module?: string}
     */
    private function pageMeta(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('doktype', 'module')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : [];
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

        $this->applyPageAccessConstraint($queryBuilder, $table, $pid);

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
        if ($this->findByUid($table, $uid, ['uid']) === null) {
            return [];
        }

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
        if ($this->findByUid($table, $uid, ['uid']) === null) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        // The enable column tells the model whether a translation is visible; without it a hidden
        // translation looks like any other and "make it visible" is answered with "already visible".
        $disabled = $GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled'] ?? null;
        $hiddenField = is_string($disabled) && $disabled !== '' ? $disabled : null;
        $select = ['uid', $languageField . ' AS sys_language_uid'];
        if ($hiddenField !== null) {
            $select[] = $hiddenField . ' AS hidden_flag';
        }

        /** @var list<array{uid: int|string, sys_language_uid: int|string, hidden_flag?: int|string}> $rows */
        $rows = $queryBuilder
            ->select(...$select)
            ->from($table)
            ->where($queryBuilder->expr()->eq($transOrigPointerField, $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->orderBy($languageField, 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static function (array $row) use ($hiddenField): array {
                $entry = [
                    'uid' => (int) $row['uid'],
                    'sys_language_uid' => (int) $row['sys_language_uid'],
                ];
                if ($hiddenField !== null) {
                    $entry['hidden'] = (int) ($row['hidden_flag'] ?? 0);
                }

                return $entry;
            },
            $rows,
        );
    }

    /**
     * Defense-in-depth: callers should allowlist orderBy, but search() re-validates
     * against the table's TCA columns before building ORDER BY (S-11).
     */
    private function resolveOrderByField(string $table, ?string $orderBy): string
    {
        if ($orderBy === null || $orderBy === '') {
            return 'uid';
        }

        return in_array($orderBy, $this->allowedOrderByFields($table), true) ? $orderBy : 'uid';
    }

    /**
     * @return list<string>
     */
    private function allowedOrderByFields(string $table): array
    {
        $columns = array_map(
            static fn(int|string $field): string => (string) $field,
            array_keys($GLOBALS['TCA'][$table]['columns'] ?? []),
        );

        return array_values(array_unique(array_merge(['uid', 'pid'], $columns)));
    }
}
