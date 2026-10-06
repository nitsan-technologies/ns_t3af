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
 * Expands the `bulk` shorthand into the same datamap / cmdmap the rest of records_apply works on.
 *
 * One entry says "do this to these records of one table":
 *
 *   {"table": "tt_content", "uids": [1, 2, 3], "set": {"hidden": 1}}   => datamap, one entry per uid
 *   {"table": "tt_content", "uids": [4, 5],    "delete": true}         => cmdmap delete
 *   {"table": "tt_content", "uids": [6, 7],    "move": 12}             => cmdmap move (page, or -uid to go after)
 *
 * It only reshapes. The expanded maps then go through the SAME preflight, the SAME transaction and
 * the SAME audit as hand-written data / cmd, so a bulk entry cannot do anything a data / cmd entry
 * could not. A record may be named only once per section, so two entries (or an entry and the
 * explicit data / cmd) can never silently overwrite each other.
 */
readonly class RecordsApplyBulkExpander
{
    private const ACTIONS = ['set', 'delete', 'move'];

    private const ALLOWED_KEYS = ['table', 'uids', 'set', 'delete', 'move'];

    /**
     * @param array<mixed> $bulk list of entries
     * @param array<mixed> $datamap explicit data of the call
     * @param array<mixed> $cmdmap explicit cmd of the call
     * @return array{0: array<mixed>, 1: array<mixed>} the datamap and the cmdmap with the bulk entries merged in
     * @throws RecordsApplyValidationException
     */
    public function expand(array $bulk, array $datamap, array $cmdmap): array
    {
        if ($bulk === []) {
            return [$datamap, $cmdmap];
        }

        $problems = new RecordsApplyProblems();

        if (!array_is_list($bulk)) {
            $problems->add(['error' => 'bulk must be a list of entries, e.g. [{"table": "tt_content", "uids": [1, 2], "set": {"hidden": 1}}].']);

            throw $problems->toException();
        }

        // Which records are already spoken for in each section, so nothing is named twice.
        $taken = ['data' => [], 'cmd' => []];
        foreach ($datamap as $table => $records) {
            foreach (is_array($records) ? array_keys($records) : [] as $uid) {
                $taken['data'][(string) $table][(string) $uid] = true;
            }
        }

        foreach ($cmdmap as $table => $records) {
            foreach (is_array($records) ? array_keys($records) : [] as $uid) {
                $taken['cmd'][(string) $table][(string) $uid] = true;
            }
        }

        $expanded = 0;
        foreach ($bulk as $index => $entry) {
            $where = ['bulk' => $index];

            $parsed = $this->parseEntry($entry, $where, $problems);
            if ($parsed === null) {
                continue;
            }

            [$table, $uids, $action, $argument] = $parsed;
            $expanded += count($uids);
            if ($expanded > RecordsApplyPreflight::MAX_RECORDS) {
                $problems->add(['error' => sprintf('Too many records in bulk: the maximum is %d per call. Split the call.', RecordsApplyPreflight::MAX_RECORDS)]);
                break;
            }

            $section = $action === 'set' ? 'data' : 'cmd';
            $clashes = [];
            foreach ($uids as $uid) {
                if (isset($taken[$section][$table][(string) $uid])) {
                    $clashes[] = $uid;
                }
            }

            if ($clashes !== []) {
                $problems->add($where + [
                    'table' => RecordsApplyProblems::safe($table),
                    'error' => sprintf('These records are already named elsewhere in this call (data, cmd or another bulk entry): %s. A record may appear only once per section.', implode(', ', array_slice($clashes, 0, 10))),
                ]);
                continue;
            }

            foreach ($uids as $uid) {
                $taken[$section][$table][(string) $uid] = true;
                if ($action === 'set') {
                    $datamap[$table][$uid] = $argument;
                } else {
                    $cmdmap[$table][$uid] = [$action => $argument];
                }
            }
        }

        if (!$problems->isEmpty()) {
            throw $problems->toException();
        }

        return [$datamap, $cmdmap];
    }

    /**
     * @param array<string, mixed> $where
     * @return array{0: string, 1: list<int>, 2: string, 3: mixed}|null table, uids, action, argument
     */
    private function parseEntry(mixed $entry, array $where, RecordsApplyProblems $problems): ?array
    {
        if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
            $problems->add($where + ['error' => 'A bulk entry must be an object: {"table": "...", "uids": [...], "set" | "delete" | "move": ...}.']);

            return null;
        }

        $valid = true;

        $unknown = array_values(array_diff(array_map('strval', array_keys($entry)), self::ALLOWED_KEYS));
        if ($unknown !== []) {
            $problems->add($where + [
                'error' => 'Unknown keys in a bulk entry. Allowed: ' . implode(', ', self::ALLOWED_KEYS) . '.',
                'fields' => array_map(RecordsApplyProblems::safe(...), array_slice($unknown, 0, 10)),
            ]);
            $valid = false;
        }

        $table = $entry['table'] ?? null;
        if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            $problems->add($where + ['error' => '"table" must be a table name.']);
            $valid = false;
            $table = '';
        }

        $uids = $this->parseUids($entry['uids'] ?? null);
        if ($uids === null) {
            $problems->add($where + [
                'error' => sprintf('"uids" must be a non-empty list of record uids (positive integers), at most %d.', RecordsApplyPreflight::MAX_RECORDS),
            ]);
            $valid = false;
            $uids = [];
        }

        $actions = array_values(array_filter(self::ACTIONS, static fn(string $action): bool => array_key_exists($action, $entry)));
        if (count($actions) !== 1) {
            $problems->add($where + ['error' => 'Give exactly one of "set", "delete" or "move" per bulk entry.']);

            return null;
        }

        $action = $actions[0];
        $argument = $entry[$action];

        if ($action === 'set') {
            if (!is_array($argument) || $argument === [] || array_is_list($argument)) {
                $problems->add($where + ['error' => '"set" must be a non-empty object of field => value.']);
                $valid = false;
            }
        } elseif ($action === 'delete') {
            if (!in_array($argument, [true, 1, '1'], true)) {
                $problems->add($where + ['error' => '"delete" takes true.']);
                $valid = false;
            } else {
                $argument = 1;
            }
        } elseif (is_int($argument) || (is_string($argument) && preg_match('/^-?\d+$/', $argument) === 1)) {
            $argument = (int) $argument;
        } else {
            $problems->add($where + ['error' => '"move" takes a page uid, or a negative record uid to place the record after it.']);
            $valid = false;
        }

        return $valid ? [$table, $uids, $action, $argument] : null;
    }

    /** @return list<int>|null the distinct uids, or null when the value is not a valid uid list */
    private function parseUids(mixed $value): ?array
    {
        if (!is_array($value) || $value === [] || !array_is_list($value) || count($value) > RecordsApplyPreflight::MAX_RECORDS) {
            return null;
        }

        $uids = [];
        foreach ($value as $item) {
            if (is_string($item) && ctype_digit($item)) {
                $item = (int) $item;
            }

            if (!is_int($item) || $item <= 0) {
                return null;
            }

            $uids[$item] = $item;
        }

        return array_values($uids);
    }
}
