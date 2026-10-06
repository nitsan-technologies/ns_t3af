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

namespace NITSAN\NsT3AF\Mcp\Service\RecordsApply;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What the undo planner needs to know about the TCA and about where records sit now.
 *
 * Kept apart from the planner so that the planning itself is plain data in, plain data out.
 */
readonly class RecordsUndoSchemaInfo
{
    /** The field can be written back with its old value. */
    public const FIELD_RESTORE = 1;

    /** A relation (file, inline, MM, category): the database holds a count or a list that cannot be replayed as a value. */
    public const FIELD_RELATION = 2;

    /** System or unknown field: DataHandler maintains it itself, or it is not a TCA column. Silently left alone. */
    public const FIELD_IGNORE = 3;

    private const SYSTEM_FIELDS = ['uid', 'pid', 'tstamp', 'crdate', 'deleted', 'sorting', 'l10n_diffsource', 'l10n_source', 'l18n_parent'];

    public function __construct(private ConnectionPool $connectionPool) {}

    public function supportsSoftDelete(string $table): bool
    {
        $delete = $this->ctrl($table)['delete'] ?? '';

        return is_string($delete) && $delete !== '';
    }

    /** @return self::FIELD_* */
    public function fieldKind(string $table, string $field): int
    {
        if (in_array($field, self::SYSTEM_FIELDS, true) || str_starts_with($field, 't3ver_')) {
            return self::FIELD_IGNORE;
        }

        $columns = $GLOBALS['TCA'][$table]['columns'] ?? null;
        $config = is_array($columns) && is_array($columns[$field] ?? null) ? ($columns[$field]['config'] ?? null) : null;
        if (!is_array($config)) {
            return self::FIELD_IGNORE;
        }

        $type = (string) ($config['type'] ?? '');
        if (in_array($type, ['inline', 'file', 'category'], true) || !empty($config['MM'])) {
            return self::FIELD_RELATION;
        }

        if (in_array($type, ['none', 'passthrough'], true)) {
            return self::FIELD_IGNORE;
        }

        return self::FIELD_RESTORE;
    }

    /**
     * Non-deleted records currently on a page, ordered by the table's sort field then uid.
     * Hidden records are kept (default restrictions are cleared; only DeletedRestriction is re-applied).
     * Language overlays (uid > 0) are excluded; default language (0) and "all languages" (-1) stay.
     *
     * @return list<array{uid: int, sorting: int}>
     */
    public function recordsOnPage(string $table, int $pageId): array
    {
        if ($pageId < 0 || !RecordsApplyMoveCommandChainer::tableSupportsSorting($table)) {
            return [];
        }

        $sortBy = (string) ($this->ctrl($table)['sortby'] ?? '');
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // Keep hidden / starttime / endtime / fe_group rows; only drop soft-deleted ones.
        $queryBuilder->getRestrictions()->removeAll();
        if ($this->supportsSoftDelete($table)) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        }

        $conditions = [
            $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
        ];
        $languageField = $this->ctrl($table)['languageField'] ?? '';
        if (is_string($languageField) && $languageField !== '') {
            // Default language (0) and "all languages" (-1) share the default-language sort order.
            $conditions[] = $queryBuilder->expr()->in(
                $languageField,
                $queryBuilder->createNamedParameter([-1, 0], Connection::PARAM_INT_ARRAY),
            );
        }
        if (!empty($this->ctrl($table)['versioningWS'])) {
            $conditions[] = $queryBuilder->expr()->eq('t3ver_wsid', 0);
        }

        $rows = $queryBuilder
            ->select('uid', $sortBy)
            ->from($table)
            ->where(...$conditions)
            ->orderBy($sortBy, 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'uid' => (int) $row['uid'],
                'sorting' => (int) ($row[$sortBy] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Previous page for a move without a sort field (positive pid). Sorted tables rebuild order in the
     * planner from {@see recordsOnPage()} plus each mover's old sorting from history.
     *
     * @param array<string, mixed> $previousPosition the old data of the move (needs pid)
     * @return int|null null when the previous page is unknown
     */
    public function resolveMoveTarget(string $table, int $uid, array $previousPosition): ?int
    {
        $previousPageId = isset($previousPosition['pid']) && is_numeric($previousPosition['pid']) ? (int) $previousPosition['pid'] : -1;

        return $previousPageId < 0 ? null : $previousPageId;
    }

    /** @return array<string, mixed> */
    private function ctrl(string $table): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? null;

        return is_array($ctrl) ? $ctrl : [];
    }
}
