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

use NITSAN\NsT3AF\Mcp\Service\WorkspaceContextService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;

/**
 * Finds the record a new record has to be placed after to land at the end of its page.
 *
 * Only the live records (and, in a workspace, records new in that workspace) count, not deleted ones. Hidden and time-restricted records count too: they still
 * occupy a position in the backend, and placing a new record before them would reverse the order
 * the client sent. For translatable tables only records of the same language count, and for
 * tt_content only those of the same column.
 */
readonly class LastRecordLocator
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private WorkspaceContextService $workspaceContext,
    ) {}

    /**
     * @param array<string, mixed> $fields the new record's fields, read for colPos and language
     */
    public function lastUid(string $table, int $pid, array $fields): ?int
    {
        $sortBy = $GLOBALS['TCA'][$table]['ctrl']['sortby'] ?? '';
        if (!is_string($sortBy) || $sortBy === '') {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($this->workspaceContext->getCurrentWorkspaceId()));

        $queryBuilder->select('uid')->from($table)->where(
            $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
        );

        if ($this->workspaceContext->isTableWorkspaceAware($table)) {
            // In a workspace the restriction also lets the workspace VERSIONS of existing records through
            // (t3ver_oid > 0). Skip them: the position is the live record, or a record NEW in this workspace.
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            );
        }

        $this->constrain($queryBuilder, $table, 'colPos', $fields);

        $languageField = $GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '';
        if (is_string($languageField) && $languageField !== '') {
            $this->constrain($queryBuilder, $table, $languageField, $fields);
        }

        $uid = $queryBuilder
            ->orderBy($sortBy, 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $uid === false ? null : (int) $uid;
    }

    /** @param array<string, mixed> $fields */
    private function constrain(QueryBuilder $queryBuilder, string $table, string $column, array $fields): void
    {
        if (!isset($GLOBALS['TCA'][$table]['columns'][$column])) {
            return;
        }

        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq(
                $column,
                $queryBuilder->createNamedParameter((int) ($fields[$column] ?? 0), Connection::PARAM_INT),
            ),
        );
    }
}
