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
     * Where a record has to go to sit at its previous position again: the previous page (on top), or the negative
     * uid of the record it followed. The stored sorting value alone means nothing, so the predecessor is looked up now.
     *
     * @param array<string, mixed> $previousPosition the old data of the move: pid and, when the table sorts, the sorting value
     * @return int|null null when the previous position is unknown
     */
    public function resolveMoveTarget(string $table, int $uid, array $previousPosition): ?int
    {
        $previousPageId = isset($previousPosition['pid']) && is_numeric($previousPosition['pid']) ? (int) $previousPosition['pid'] : -1;
        if ($previousPageId < 0) {
            return null;
        }

        if (!RecordsApplyMoveCommandChainer::tableSupportsSorting($table)) {
            return $previousPageId;
        }

        $sortBy = (string) ($this->ctrl($table)['sortby'] ?? '');
        if ($sortBy === '' || !isset($previousPosition[$sortBy]) || !is_numeric($previousPosition[$sortBy])) {
            return $previousPageId;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $conditions = [
            $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($previousPageId, Connection::PARAM_INT)),
            $queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            $queryBuilder->expr()->lt($sortBy, $queryBuilder->createNamedParameter((int) $previousPosition[$sortBy], Connection::PARAM_INT)),
        ];
        if ($this->supportsSoftDelete($table)) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        }

        if (!empty($this->ctrl($table)['versioningWS'])) {
            $conditions[] = $queryBuilder->expr()->eq('t3ver_wsid', 0);
        }

        $predecessor = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(...$conditions)
            ->orderBy($sortBy, 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        // Nothing came before it: it was the first record of that page.
        return $predecessor === false ? $previousPageId : -(int) $predecessor;
    }

    /** @return array<string, mixed> */
    private function ctrl(string $table): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? null;

        return is_array($ctrl) ? $ctrl : [];
    }
}
