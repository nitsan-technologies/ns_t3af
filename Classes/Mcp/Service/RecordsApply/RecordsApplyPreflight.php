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

use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Checks a records_apply request completely BEFORE anything is written.
 *
 * Refuses what DataHandler would only discover half-way: unknown tables, tables the user may not
 * modify, tables on a separate database connection (a transaction cannot cover them), unwritable
 * fields (strict mode), bad ids and relation lists, records that do not exist or are not
 * accessible, bad `cmd` commands and targets, and requests over the record limit.
 *
 * It only tells the client WHAT is wrong and where. It never repeats a field value. Record-level
 * and page-level permissions beyond tables_modify stay with DataHandler, which refuses what the
 * user may not touch; the transaction then rolls the whole call back.
 */
readonly class RecordsApplyPreflight
{
    public const MAX_RECORDS = 500;

    private const COMMANDS = ['delete', 'undelete', 'move', 'copy', 'localize'];

    private const NEW_ID = '/^NEW[A-Za-z0-9]+$/';

    private const PID = '/^-?(?:\d+|NEW[A-Za-z0-9]+)$/';

    /** A uid, a NEW id, or table_uid for group fields that allow several tables. */
    private const LIST_ITEM = '/^(?:\d+|NEW[A-Za-z0-9]+|(?!NEW)[A-Za-z0-9_]+_\d+)$/';

    public function __construct(
        private TcaSchemaService $tcaSchemaService,
        private RecordService $recordService,
        private ConnectionPool $connectionPool,
        private ?RecordsApplyFileAccess $fileAccess = null,
    ) {}

    /**
     * @param array<mixed> $datamap
     * @param array<mixed> $cmdmap
     * @return array{
     *     datamap: array<string, array<int|string, array<string, mixed>>>,
     *     cmdmap: array<string, array<int, array<string, int>>>,
     *     ignored: list<array{table: string, id: string, fields: list<string>}>,
     *     count: int
     * }
     * @throws RecordsApplyValidationException
     */
    public function check(array $datamap, array $cmdmap, bool $strict): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new RecordsApplyValidationException([
                ['error' => 'No backend user context. Authenticate via OAuth or stdio --user.'],
            ]);
        }

        $problems = new RecordsApplyProblems();
        $count = 0;

        [$cleanData, $ignored, $dataCount] = $this->checkDatamap($datamap, $strict, $backendUser, $problems);
        [$cleanCmd, $cmdCount] = $this->checkCmdmap($cmdmap, $backendUser, $problems);
        $count = $dataCount + $cmdCount;

        if ($count === 0 && $problems->isEmpty()) {
            $problems->add(['error' => 'Nothing to apply: data and cmd are both empty.']);
        }

        if ($count > self::MAX_RECORDS) {
            $problems->add([
                'error' => sprintf('Too many records: %d in this call, the maximum is %d. Split the call.', $count, self::MAX_RECORDS),
            ]);
        }

        if (!$problems->isEmpty()) {
            throw $problems->toException();
        }

        return ['datamap' => $cleanData, 'cmdmap' => $cleanCmd, 'ignored' => $ignored, 'count' => $count];
    }

    /**
     * @param array<mixed> $datamap
     * @return array{0: array<string, array<int|string, array<string, mixed>>>, 1: list<array{table: string, id: string, fields: list<string>}>, 2: int}
     */
    private function checkDatamap(array $datamap, bool $strict, BackendUserAuthentication $backendUser, RecordsApplyProblems $problems): array
    {
        $clean = [];
        $ignored = [];
        $count = 0;

        // NEW ids created in this call, per table and overall: a pid or a list entry may only point at those.
        $newIds = [];
        $allNewIds = [];
        foreach ($datamap as $tableKey => $records) {
            if (!is_array($records)) {
                continue;
            }

            foreach (array_keys($records) as $idKey) {
                if (preg_match(self::NEW_ID, (string) $idKey) === 1) {
                    $newIds[(string) $tableKey][(string) $idKey] = true;
                    $allNewIds[(string) $idKey] = true;
                }
            }
        }

        /** @var array<int, list<array<string, mixed>>> $pagePids pages that new records are placed on, with where each was asked for */
        $pagePids = [];

        foreach ($datamap as $tableKey => $records) {
            $table = (string) $tableKey;
            if (!$this->checkTable($table, 'data', $backendUser, $problems)) {
                continue;
            }

            if (!is_array($records) || ($records !== [] && array_is_list($records))) {
                $problems->add(['table' => RecordsApplyProblems::safe($table), 'error' => 'Records must be an object keyed by uid or NEW id.']);
                continue;
            }

            $writable = array_flip($this->tcaSchemaService->getApplyWritableFields($table));
            $listFields = array_flip($this->tcaSchemaService->getApplyListFields($table));
            /** @var list<int> $existing */
            $existing = [];
            /** @var array<int, list<array<string, mixed>>> $afterRecords records that new ones are placed after (negative pid) */
            $afterRecords = [];

            foreach ($records as $idKey => $fields) {
                $id = (string) $idKey;
                ++$count;
                $where = ['table' => $table, 'id' => RecordsApplyProblems::safe($id)];

                $isNew = preg_match(self::NEW_ID, $id) === 1;
                if (!$isNew && !(ctype_digit($id) && (int) $id > 0)) {
                    $problems->add($where + ['error' => 'Invalid record id. Use an existing uid, or a NEW id of letters and digits only, e.g. NEWpage1 (no underscore).']);
                    continue;
                }

                if (!is_array($fields) || ($fields !== [] && array_is_list($fields))) {
                    $problems->add($where + ['error' => 'Fields must be an object of field => value.']);
                    continue;
                }

                $pid = null;
                if (array_key_exists('pid', $fields)) {
                    if (!$isNew) {
                        $problems->add($where + ['error' => 'pid cannot be changed in data. Use cmd {"move": <target>} to move an existing record.']);
                        continue;
                    }

                    $pidRaw = $fields['pid'];
                    unset($fields['pid']);
                    if (!(is_int($pidRaw) || is_string($pidRaw)) || preg_match(self::PID, (string) $pidRaw) !== 1) {
                        $problems->add($where + ['error' => 'Invalid pid. Use a page uid, a negative uid (after that record), or a NEW id.']);
                        continue;
                    }

                    $pid = preg_match('/^-?\d+$/', (string) $pidRaw) === 1 ? (int) $pidRaw : (string) $pidRaw;

                    // DataHandler does not refuse a record placed on a page that does not exist, so check the placement here.
                    if (is_int($pid)) {
                        if ($pid > 0) {
                            $pagePids[$pid][] = $where;
                        } elseif ($pid < 0) {
                            $afterRecords[-$pid][] = $where;
                        }
                    } elseif (str_starts_with($pid, '-')) {
                        if (!isset($newIds[$table][substr($pid, 1)])) {
                            $problems->add($where + ['error' => 'pid refers to a NEW id that is not created in this call (in this table).']);
                            continue;
                        }
                    } elseif (!isset($newIds['pages'][$pid])) {
                        $problems->add($where + ['error' => 'pid refers to a NEW id that is not created as a page in this call.']);
                        continue;
                    }
                } elseif ($isNew) {
                    $problems->add($where + ['error' => 'A new record needs a pid.']);
                    continue;
                }

                $unwritable = [];
                foreach (array_keys($fields) as $fieldName) {
                    if (!isset($writable[(string) $fieldName])) {
                        $unwritable[] = (string) $fieldName;
                    }
                }

                if ($unwritable !== []) {
                    $names = array_map(RecordsApplyProblems::safe(...), $unwritable);
                    if ($strict) {
                        $problems->add($where + ['error' => 'Not writable fields (strict mode refuses the whole call).', 'fields' => $names]);
                        continue;
                    }

                    $ignored[] = ['table' => $table, 'id' => RecordsApplyProblems::safe($id), 'fields' => $names];
                    $fields = array_diff_key($fields, array_flip($unwritable));
                }

                $denied = [];
                foreach ($fields as $fieldName => $fieldValue) {
                    if (!self::userMayWrite($backendUser, $table, (string) $fieldName, $fieldValue)) {
                        $denied[] = (string) $fieldName;
                    }
                }

                if ($denied !== []) {
                    $names = array_map(RecordsApplyProblems::safe(...), $denied);
                    if ($strict) {
                        $problems->add($where + ['error' => 'Your backend user may not change these fields, or not to this value (strict mode refuses the whole call).', 'fields' => $names]);
                        continue;
                    }

                    $ignored[] = ['table' => $table, 'id' => RecordsApplyProblems::safe($id), 'fields' => $names];
                    $fields = array_diff_key($fields, array_flip($denied));
                }

                if ($fields === [] && !$isNew) {
                    $problems->add($where + ['error' => 'No writable fields left to update.']);
                    continue;
                }

                $valid = true;
                foreach ($fields as $fieldName => $value) {
                    if (!isset($listFields[(string) $fieldName])) {
                        continue;
                    }

                    $normalized = $this->normalizeList($value);
                    if ($normalized === null) {
                        $problems->add($where + [
                            'error' => 'Expected a list of uids or NEW ids (array or comma-separated string).',
                            'fields' => [RecordsApplyProblems::safe((string) $fieldName)],
                        ]);
                        $valid = false;
                        continue;
                    }

                    foreach ($normalized === '' ? [] : explode(',', $normalized) as $item) {
                        if (preg_match(self::NEW_ID, $item) === 1 && !isset($allNewIds[$item])) {
                            $problems->add($where + [
                                'error' => sprintf('Refers to the NEW id %s, which is not created in this call.', RecordsApplyProblems::safe($item)),
                                'fields' => [RecordsApplyProblems::safe((string) $fieldName)],
                            ]);
                            $valid = false;
                        }
                    }

                    $fields[$fieldName] = $normalized;
                }

                if (!$valid) {
                    continue;
                }

                if ($table === 'sys_file_reference' && isset($fields['uid_local']) && $this->fileAccess !== null) {
                    $fileUid = self::fileUid($fields['uid_local']);
                    if ($fileUid === null || !$this->fileAccess->canRead($fileUid)) {
                        $problems->add($where + ['error' => 'The file was not found or is not accessible to you.', 'fields' => ['uid_local']]);
                        continue;
                    }
                }

                $clean[$table][$id] = $pid === null ? $fields : ['pid' => $pid] + $fields;
                if (!$isNew) {
                    $existing[] = (int) $id;
                }
            }

            $this->requireExisting($table, $existing, $problems);

            if ($afterRecords !== []) {
                $found = $this->recordService->findExistingUids($table, array_keys($afterRecords));
                foreach (array_diff(array_keys($afterRecords), $found) as $missing) {
                    foreach ($afterRecords[$missing] as $where) {
                        $problems->add($where + ['error' => sprintf('Record %d to place this one after was not found or is not accessible.', $missing)]);
                    }
                }
            }
        }

        if ($pagePids !== []) {
            $found = $this->recordService->findExistingUids('pages', array_keys($pagePids));
            foreach (array_diff(array_keys($pagePids), $found) as $missing) {
                foreach ($pagePids[$missing] as $where) {
                    $problems->add($where + ['error' => sprintf('Page %d was not found or is not accessible.', $missing)]);
                }
            }
        }

        return [$clean, $ignored, $count];
    }

    /**
     * @param array<mixed> $cmdmap
     * @return array{0: array<string, array<int, array<string, int>>>, 1: int}
     */
    private function checkCmdmap(array $cmdmap, BackendUserAuthentication $backendUser, RecordsApplyProblems $problems): array
    {
        $clean = [];
        $count = 0;

        foreach ($cmdmap as $tableKey => $records) {
            $table = (string) $tableKey;
            if (!$this->checkTable($table, 'cmd', $backendUser, $problems)) {
                continue;
            }

            if (!is_array($records) || ($records !== [] && array_is_list($records))) {
                $problems->add(['table' => RecordsApplyProblems::safe($table), 'error' => 'cmd records must be an object keyed by uid.']);
                continue;
            }

            /** @var list<int> $existing */
            $existing = [];

            foreach ($records as $uidKey => $commands) {
                $uid = (string) $uidKey;
                $where = ['table' => $table, 'id' => RecordsApplyProblems::safe($uid), 'cmd' => true];
                ++$count;

                if (!ctype_digit($uid) || (int) $uid <= 0) {
                    $problems->add($where + ['error' => 'cmd needs an existing record uid.']);
                    continue;
                }

                if (!is_array($commands) || $commands === [] || array_is_list($commands)) {
                    $problems->add($where + ['error' => 'Commands must be an object, e.g. {"delete": 1} or {"move": 12}.']);
                    continue;
                }

                $needsExisting = false;
                foreach ($commands as $command => $value) {
                    $command = (string) $command;
                    if (!in_array($command, self::COMMANDS, true)) {
                        $problems->add($where + [
                            'error' => 'Unknown command. Allowed: ' . implode(', ', self::COMMANDS) . '.',
                            'fields' => [RecordsApplyProblems::safe($command)],
                        ]);
                        continue;
                    }

                    $argument = $this->checkCommand($table, $command, $value, $where, $problems);
                    if ($argument === null) {
                        continue;
                    }

                    $clean[$table][(int) $uid][$command] = $argument;
                    $needsExisting = $needsExisting || $command !== 'undelete';
                }

                if ($needsExisting) {
                    $existing[] = (int) $uid;
                }
            }

            $this->requireExisting($table, $existing, $problems);
        }

        return [$clean, $count];
    }

    /**
     * @param array<string, mixed> $where
     * @return int|null the value to hand to DataHandler, null when the command was refused
     */
    private function checkCommand(string $table, string $command, mixed $value, array $where, RecordsApplyProblems $problems): ?int
    {
        if ($command === 'delete' || $command === 'undelete') {
            if (!in_array($value, [1, true, '1'], true)) {
                $problems->add($where + ['error' => sprintf('"%s" takes the value 1.', $command)]);

                return null;
            }

            return 1;
        }

        $number = null;
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $number = (int) $value;
        }

        if ($number === null) {
            $problems->add($where + ['error' => sprintf('"%s" takes an integer.', $command)]);

            return null;
        }

        if ($command === 'localize') {
            if ($number <= 0) {
                $problems->add($where + ['error' => '"localize" takes a language uid greater than 0.']);

                return null;
            }

            return $number;
        }

        // move / copy: a positive number is a page (the record goes on top of it),
        // a negative one is a record of the same table (the record goes after it).
        if ($number > 0) {
            if ($this->recordService->findExistingUids('pages', [$number]) === []) {
                $problems->add($where + ['error' => sprintf('Target page %d for "%s" was not found or is not accessible.', $number, $command)]);

                return null;
            }
        } elseif ($number < 0) {
            if ($this->recordService->findExistingUids($table, [-$number]) === []) {
                $problems->add($where + ['error' => sprintf('Target record %d for "%s" was not found or is not accessible.', -$number, $command)]);

                return null;
            }
        } elseif ($table !== 'pages') {
            $problems->add($where + ['error' => sprintf('Target 0 for "%s" is only valid for pages.', $command)]);

            return null;
        }

        return $number;
    }

    /** @param list<int> $uids */
    private function requireExisting(string $table, array $uids, RecordsApplyProblems $problems): void
    {
        if ($uids === []) {
            return;
        }

        $found = $this->recordService->findExistingUids($table, $uids);
        foreach (array_diff($uids, $found) as $missing) {
            // The same answer for "does not exist" and "not visible to you", so a call cannot be
            // used to probe which uids exist behind pages the user may not read.
            $problems->add([
                'table' => $table,
                'id' => (string) $missing,
                'error' => 'Record not found or not accessible.',
            ]);
        }
    }

    private function checkTable(string $table, string $section, BackendUserAuthentication $backendUser, RecordsApplyProblems $problems): bool
    {
        $where = ['table' => RecordsApplyProblems::safe($table), 'in' => $section];

        if (!isset($GLOBALS['TCA'][$table]) || !is_array($GLOBALS['TCA'][$table])) {
            $problems->add($where + ['error' => 'Unknown table.']);

            return false;
        }

        if (!$backendUser->check('tables_modify', $table)) {
            $problems->add($where + ['error' => 'No permission to modify this table.']);

            return false;
        }

        $default = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        if ($this->connectionPool->getConnectionForTable($table) !== $default) {
            $problems->add($where + ['error' => 'This table lives on a separate database connection, so records_apply cannot roll it back atomically.']);

            return false;
        }

        return true;
    }

    /**
     * Does DataHandler let this backend user set this field to this value? It skips the rest without a word,
     * so strict mode has to ask first: exclude fields the group did not allow, admin-only fields, select values
     * behind authMode, and the page types (doktype) the group may use.
     */
    private static function userMayWrite(BackendUserAuthentication $backendUser, string $table, string $field, mixed $value): bool
    {
        $column = $GLOBALS['TCA'][$table]['columns'][$field] ?? null;
        if (!is_array($column)) {
            return true;
        }

        if (($column['exclude'] ?? false) && !$backendUser->check('non_exclude_fields', $table . ':' . $field)) {
            return false;
        }

        if (($column['displayCond'] ?? '') === 'HIDE_FOR_NON_ADMINS' && !$backendUser->isAdmin()) {
            return false;
        }

        $scalar = is_int($value) || is_string($value) ? (string) $value : null;
        if ($scalar === null) {
            return true;
        }

        if ($table === 'pages' && $field === 'doktype') {
            return $backendUser->check('pagetypes_select', $scalar);
        }

        $config = $column['config'] ?? [];
        if (is_array($config) && ($config['type'] ?? '') === 'select' && ($config['authMode'] ?? false)) {
            return $backendUser->checkAuthMode($table, $field, $scalar);
        }

        return true;
    }

    private static function fileUid(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^(?:sys_file_)?(\d+)$/', trim($value), $match) === 1 && (int) $match[1] > 0) {
            return (int) $match[1];
        }

        return null;
    }

    /** @return string|null comma-separated list, or null when the value is not a valid uid list */
    private function normalizeList(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : '';
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return '';
            }
            $items = explode(',', $trimmed);
        } elseif (is_array($value) && array_is_list($value)) {
            $items = $value;
        } else {
            return null;
        }

        $normalized = [];
        foreach ($items as $item) {
            if (is_int($item)) {
                $item = (string) $item;
            }

            if (!is_string($item)) {
                return null;
            }

            $item = trim($item);
            if (preg_match(self::LIST_ITEM, $item) !== 1) {
                return null;
            }

            $normalized[] = $item;
        }

        return implode(',', $normalized);
    }
}
