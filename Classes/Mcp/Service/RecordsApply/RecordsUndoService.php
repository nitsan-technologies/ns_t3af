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

use Mcp\Exception\ToolCallException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Puts back everything one records_apply batch changed.
 *
 * The inverse is rebuilt from the batch's sys_history rows and applied through the same engine: one DataHandler run
 * in one transaction, your own backend permissions, a dry run, an audit entry. The undo is a batch itself, named
 * `ru-` plus a fingerprint of the batch it undoes, so it can be recognised ("already undone") and undone in turn.
 *
 * It only runs when nothing else touched those records after the batch: a record that somebody edited since is
 * not reverted behind their back. That check is also what stops the same undo from running twice.
 */
readonly class RecordsUndoService
{
    /** More history rows than this is a batch too large to undo in one call. */
    private const MAX_HISTORY_ROWS = 5000;

    private const MAX_LISTED = 20;

    public function __construct(
        private ConnectionPool $connectionPool,
        private RecordsUndoPlanner $planner,
        private RecordsApplyService $recordsApply,
    ) {}

    /** The batch id of the undo of a batch: fixed, so the undo can be found again. */
    public static function undoBatchId(string $batchId): string
    {
        return 'ru-' . substr(sha1($batchId), 0, 20);
    }

    public static function isBatchId(string $batchId): bool
    {
        return preg_match('/^r[au]-[0-9a-f]{20}$/D', $batchId) === 1;
    }

    /**
     * @return array{result: RecordsApplyResult, undoes: string, notRestored: list<array{table: string, id: int, fields: list<string>, reason: string}>}
     * @throws ToolCallException when the batch cannot be undone; nothing was written
     * @throws RecordsApplyValidationException when the inverse itself is refused by the engine's checks
     */
    public function undo(string $batchId, bool $dryRun): array
    {
        if (!self::isBatchId($batchId)) {
            throw new ToolCallException('batchId is not a batch id. It looks like ra-0123456789abcdef0123 and comes from the answer of records_apply. Nothing was written.', 1790500017);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new ToolCallException('records_undo needs an authenticated backend user. Nothing was written.', 1790500017);
        }

        if ((int) $backendUser->workspace !== 0) {
            throw new ToolCallException('records_undo works in the live workspace. Changes made in a workspace are taken back with workspace_discard. Nothing was written.', 1790500021);
        }

        $undoBatchId = self::undoBatchId($batchId);
        if ($this->historyRows($undoBatchId, null, 1) !== []) {
            throw new ToolCallException(sprintf('Batch %s was already undone (by %s). Nothing was written.', $batchId, $undoBatchId), 1790500018);
        }

        $rows = $this->historyRows($batchId, $backendUser->isAdmin() ? null : (int) ($backendUser->user['uid'] ?? 0), self::MAX_HISTORY_ROWS + 1);
        if ($rows === []) {
            // The same answer for "unknown", "somebody else's" and "cleaned up", so it cannot be used to probe batches.
            throw new ToolCallException(sprintf('No history was found for batch %s: the id is unknown, the batch wrote nothing, it was written by another user, or its history was cleaned up. Nothing was written.', $batchId), 1790500019);
        }

        if (count($rows) > self::MAX_HISTORY_ROWS) {
            throw new ToolCallException(sprintf('Batch %s changed too many records to undo in one call (more than %d history entries). Nothing was written.', $batchId, self::MAX_HISTORY_ROWS), 1790500020);
        }

        foreach ($rows as $row) {
            if ($row['workspace'] !== 0) {
                throw new ToolCallException(sprintf('Batch %s was written in a workspace. Take it back with workspace_discard. Nothing was written.', $batchId), 1790500021);
            }
        }

        $this->refuseWhenChangedSince($batchId, $rows);

        $plan = $this->planner->plan(array_map(static fn(array $row): array => [
            'uid' => $row['uid'],
            'actiontype' => $row['actiontype'],
            'tablename' => $row['tablename'],
            'recuid' => $row['recuid'],
            'history_data' => $row['history_data'],
        ], $rows));

        if ($plan['problems'] !== []) {
            throw new ToolCallException(
                'Batch ' . $batchId . ' cannot be undone: ' . implode(' ', array_slice($plan['problems'], 0, self::MAX_LISTED)) . ' Nothing was written.',
                1790500023,
            );
        }

        $this->refuseWhenContentWasAddedToCreatedPages($batchId, $plan['created']);

        if ($plan['count'] === 0) {
            throw new ToolCallException(sprintf('Batch %s has nothing to undo: it changed no record that still exists in a different state. Nothing was written.', $batchId), 1790500025);
        }

        $result = $this->recordsApply->apply($plan['datamap'], $plan['cmdmap'], $dryRun, false, false, [], 'records_undo', '', $undoBatchId);

        return ['result' => $result, 'undoes' => $batchId, 'notRestored' => $plan['notRestored']];
    }

    /**
     * @return list<array{uid: int, actiontype: int, tablename: string, recuid: int, history_data: array<mixed>, workspace: int}> oldest first
     */
    private function historyRows(string $scope, ?int $userUid, int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');
        $queryBuilder
            ->select('uid', 'actiontype', 'tablename', 'recuid', 'history_data', 'workspace')
            ->from('sys_history')
            // Stored as <flags>$<scope>:<subject>; the scope is only hex digits and a dash, so nothing needs escaping.
            ->where($queryBuilder->expr()->like('correlation_id', $queryBuilder->createNamedParameter('%$' . $scope . ':%')))
            ->orderBy('uid', 'ASC')
            ->setMaxResults($limit);
        if ($userUid !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('userid', $queryBuilder->createNamedParameter($userUid, Connection::PARAM_INT)));
        }

        $rows = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $data = [];
            $raw = $row['history_data'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = str_starts_with($raw, 'a') ? @unserialize($raw, ['allowed_classes' => false]) : json_decode($raw, true);
                $data = is_array($decoded) ? $decoded : [];
            }

            $rows[] = [
                'uid' => (int) $row['uid'],
                'actiontype' => (int) $row['actiontype'],
                'tablename' => (string) $row['tablename'],
                'recuid' => (int) $row['recuid'],
                'history_data' => $data,
                'workspace' => (int) $row['workspace'],
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{uid: int, actiontype: int, tablename: string, recuid: int, history_data: array<mixed>, workspace: int}> $rows
     */
    private function refuseWhenChangedSince(string $batchId, array $rows): void
    {
        $firstRow = PHP_INT_MAX;
        $uidsByTable = [];
        foreach ($rows as $row) {
            $firstRow = min($firstRow, $row['uid']);
            $uidsByTable[$row['tablename']][$row['recuid']] = true;
        }

        $changed = [];
        foreach ($uidsByTable as $table => $uids) {
            foreach (array_chunk(array_keys($uids), 500) as $chunk) {
                $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');
                $found = $queryBuilder
                    ->select('recuid')
                    ->distinct()
                    ->from('sys_history')
                    ->where(
                        $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                        $queryBuilder->expr()->in('recuid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                        $queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($firstRow, Connection::PARAM_INT)),
                        $queryBuilder->expr()->notLike('correlation_id', $queryBuilder->createNamedParameter('%$' . $batchId . ':%')),
                    )
                    ->executeQuery()
                    ->fetchFirstColumn();

                foreach ($found as $uid) {
                    $changed[] = $table . ':' . $uid;
                }
            }
        }

        if ($changed !== []) {
            throw new ToolCallException(
                sprintf(
                    'Batch %s cannot be undone: %d record(s) were changed after it (%s%s). Reverting them would overwrite that work. This is also what a batch that was undone by hand looks like. Nothing was written.',
                    $batchId,
                    count($changed),
                    implode(', ', array_slice($changed, 0, self::MAX_LISTED)),
                    count($changed) > self::MAX_LISTED ? ', …' : '',
                ),
                1790500022,
            );
        }
    }

    /**
     * Deleting a page deletes everything on it. Records somebody added to a page this batch created would go too.
     *
     * @param array<string, list<int>> $created table => uids the batch created
     */
    private function refuseWhenContentWasAddedToCreatedPages(string $batchId, array $created): void
    {
        $pageUids = $created['pages'] ?? [];
        if ($pageUids === []) {
            return;
        }

        $foreign = [];
        foreach (array_keys($GLOBALS['TCA'] ?? []) as $table) {
            $table = (string) $table;
            if (!empty($GLOBALS['TCA'][$table]['ctrl']['rootLevel']) && (int) $GLOBALS['TCA'][$table]['ctrl']['rootLevel'] === 1) {
                continue;
            }

            try {
                $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
                $queryBuilder->getRestrictions()->removeAll();
                if (!empty($GLOBALS['TCA'][$table]['ctrl']['delete'])) {
                    $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                }

                $queryBuilder
                    ->select('uid')
                    ->from($table)
                    ->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)))
                    ->setMaxResults(self::MAX_LISTED + 1);
                $known = $created[$table] ?? [];
                if ($known !== []) {
                    $queryBuilder->andWhere($queryBuilder->expr()->notIn('uid', $queryBuilder->createNamedParameter($known, Connection::PARAM_INT_ARRAY)));
                }

                foreach ($queryBuilder->executeQuery()->fetchFirstColumn() as $uid) {
                    $foreign[] = $table . ':' . $uid;
                }
            } catch (\Throwable) {
                // A TCA table without a database table: nothing can sit on a page in it.
                continue;
            }
        }

        if ($foreign !== []) {
            throw new ToolCallException(
                sprintf(
                    'Batch %s cannot be undone: pages it created now hold records it did not create (%s%s), and deleting the pages would delete those too. Move or delete them first. Nothing was written.',
                    $batchId,
                    implode(', ', array_slice($foreign, 0, self::MAX_LISTED)),
                    count($foreign) > self::MAX_LISTED ? ', …' : '',
                ),
                1790500024,
            );
        }
    }
}
