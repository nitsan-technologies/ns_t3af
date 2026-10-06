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

/**
 * Works out, from the sys_history rows one batch wrote, the changes that put everything back.
 *
 *  - a record the batch created is deleted again,
 *  - a record the batch deleted is undeleted,
 *  - a record the batch changed gets the old values of the fields back (the oldest old value wins),
 *  - a record the batch moved goes back to where it was.
 *
 * A record's existence is the SUM of its add/undelete (+1) and delete (-1) rows, so "created, then deleted in
 * the same batch" cancels out and needs nothing. Relation fields (files, inline, MM, categories) hold a count
 * in the database, not a value that can be replayed, so they are not restored and are listed as such.
 *
 * Pure planning: no database access beyond what RecordsUndoSchemaInfo does.
 */
readonly class RecordsUndoPlanner
{
    private const ACTION_ADD = 1;

    private const ACTION_MODIFY = 2;

    private const ACTION_MOVE = 3;

    private const ACTION_DELETE = 4;

    private const ACTION_UNDELETE = 5;

    public function __construct(private RecordsUndoSchemaInfo $schemaInfo) {}

    /**
     * @param list<array{uid: int, actiontype: int, tablename: string, recuid: int, history_data: array<mixed>}> $rows oldest first
     * @return array{
     *     datamap: array<string, array<int, array<string, mixed>>>,
     *     cmdmap: array<string, array<int, array<string, int>>>,
     *     notRestored: list<array{table: string, id: int, fields: list<string>, reason: string}>,
     *     created: array<string, list<int>>,
     *     problems: list<string>,
     *     count: int
     * }
     */
    public function plan(array $rows): array
    {
        /** @var array<string, array{table: string, uid: int, net: int, fields: array<string, mixed>, move: array<mixed>|null}> $records */
        $records = [];

        foreach ($rows as $row) {
            $key = $row['tablename'] . ':' . $row['recuid'];
            $records[$key] ??= ['table' => $row['tablename'], 'uid' => $row['recuid'], 'net' => 0, 'fields' => [], 'move' => null];

            switch ($row['actiontype']) {
                case self::ACTION_ADD:
                case self::ACTION_UNDELETE:
                    ++$records[$key]['net'];
                    break;
                case self::ACTION_DELETE:
                    --$records[$key]['net'];
                    break;
                case self::ACTION_MODIFY:
                    $old = $row['history_data']['oldRecord'] ?? [];
                    foreach (is_array($old) ? $old : [] as $field => $value) {
                        // Rows come oldest first and the oldest old value is the one before the batch.
                        if (!array_key_exists((string) $field, $records[$key]['fields'])) {
                            $records[$key]['fields'][(string) $field] = $value;
                        }
                    }
                    break;
                case self::ACTION_MOVE:
                    $previous = $row['history_data']['oldData'] ?? [];
                    if ($records[$key]['move'] === null && is_array($previous) && isset($previous['pid'])) {
                        $records[$key]['move'] = $previous;
                    }
                    break;
                default:
                    // Stage changes and publishing neither add nor remove a record and carry nothing to put back.
                    break;
            }
        }

        $datamap = [];
        $undeletes = [];
        $moves = [];
        $deletes = [];
        $created = [];
        $notRestored = [];
        $problems = [];

        foreach ($records as $record) {
            $table = $record['table'];
            $uid = $record['uid'];

            if ($record['net'] > 0) {
                $deletes[] = [$table, $uid];
                $created[$table][] = $uid;
                continue;
            }

            if ($record['net'] === 0 && $this->createdAndRemovedInThisBatch($record, $rows)) {
                continue;
            }

            if ($record['net'] < 0) {
                if (!$this->schemaInfo->supportsSoftDelete($table)) {
                    $problems[] = sprintf('%s removes records for good, so the deletion of %s:%d cannot be undone.', $table, $table, $uid);
                    continue;
                }

                $undeletes[] = [$table, $uid];
            }

            $restorable = [];
            $relations = [];
            foreach ($record['fields'] as $field => $value) {
                $kind = $this->schemaInfo->fieldKind($table, $field);
                if ($kind === RecordsUndoSchemaInfo::FIELD_RESTORE) {
                    $restorable[$field] = $value;
                } elseif ($kind === RecordsUndoSchemaInfo::FIELD_RELATION) {
                    $relations[] = $field;
                }
            }

            if ($record['net'] < 0 && ($restorable !== [] || $relations !== [])) {
                // The datamap runs before the commands, and a deleted record cannot be edited: bring it back, leave its fields.
                $notRestored[] = ['table' => $table, 'id' => $uid, 'fields' => array_merge(array_keys($restorable), $relations), 'reason' => 'changed and deleted in the same batch'];
                $restorable = [];
                $relations = [];
            }

            if ($restorable !== []) {
                $datamap[$table][$uid] = $restorable;
            }

            if ($relations !== []) {
                $notRestored[] = ['table' => $table, 'id' => $uid, 'fields' => $relations, 'reason' => 'relation fields (files, inline records, categories) are not restored'];
            }

            if ($record['move'] !== null) {
                $target = $this->schemaInfo->resolveMoveTarget($table, $uid, $record['move']);
                if ($target === null) {
                    $notRestored[] = ['table' => $table, 'id' => $uid, 'fields' => [], 'reason' => 'the previous position of the moved record is unknown'];
                } else {
                    $moves[] = [$table, $uid, $target];
                }
            }
        }

        $cmdmap = [];
        // Undelete oldest first, so a page is back before the records on it.
        foreach ($undeletes as [$table, $uid]) {
            $cmdmap[$table][$uid]['undelete'] = 1;
        }

        foreach ($moves as [$table, $uid, $target]) {
            $cmdmap[$table][$uid]['move'] = $target;
        }

        // Delete newest first, so records created on a page go before the page they sit on.
        foreach (array_reverse($deletes) as [$table, $uid]) {
            $cmdmap[$table][$uid]['delete'] = 1;
        }

        // Pages first: a page has to be back before its records are, and deleting it takes its records along.
        uksort($cmdmap, static fn(string $a, string $b): int => ($b === 'pages') <=> ($a === 'pages'));

        $count = 0;
        foreach ($datamap as $perTable) {
            $count += count($perTable);
        }

        foreach ($cmdmap as $perTable) {
            $count += count($perTable);
        }

        return [
            'datamap' => $datamap,
            'cmdmap' => $cmdmap,
            'notRestored' => $notRestored,
            'created' => $created,
            'problems' => $problems,
            'count' => $count,
        ];
    }

    /**
     * Net zero can also mean "existed before, deleted and undeleted again". Only a record that the batch
     * itself added and removed has nothing to put back, so it needs an add row among its own.
     *
     * @param array{table: string, uid: int, net: int, fields: array<string, mixed>, move: array<mixed>|null} $record
     * @param list<array{uid: int, actiontype: int, tablename: string, recuid: int, history_data: array<mixed>}> $rows
     */
    private function createdAndRemovedInThisBatch(array $record, array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['tablename'] === $record['table'] && $row['recuid'] === $record['uid'] && $row['actiontype'] === self::ACTION_ADD) {
                return true;
            }
        }

        return false;
    }
}
