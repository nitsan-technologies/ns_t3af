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

use NITSAN\NsT3AF\Service\AiLogChannelCatalog;
use NITSAN\NsT3AF\Utility\SysLogWriterUtility;

/**
 * Writes the one audit entry of a batch write (records_apply, or write_table which runs on the same engine) to sys_log.
 *
 * It is called AFTER the transaction has been committed or rolled back. A row written inside the
 * transaction would vanish with a rollback, and failed and dry-run calls are exactly the ones an
 * administrator wants to find. Like the generic per-call entry the MCP error proxy writes, it
 * holds no field values: tables, operation counts, field NAMES and the batch id.
 */
readonly class RecordsApplyAudit
{
    private const MAX_FIELD_NAMES = 20;

    /**
     * Error codes of the failures this class logs. The MCP error proxy writes one generic entry for every failed tool call;
     * for these it skips that entry, so a refused batch leaves ONE entry, not two.
     */
    public const AUDITED_ERROR_CODES = [1790500002, 1790500003, 1790500004, 1790500005, 1790500006, 1790500032];

    /**
     * @param array<string, array<string, int>> $operations
     * @param array<string, list<string>> $fieldNames
     * @param list<array<string, mixed>> $problems
     * @param array<string, list<string>> $recordIds
     */
    public function log(
        string $tool,
        string $batchId,
        bool $dryRun,
        bool $ok,
        string $stage,
        array $operations,
        array $fieldNames,
        string $error = '',
        array $problems = [],
        array $recordIds = [],
    ): void {
        try {
            SysLogWriterUtility::insert(
                sprintf('MCP %s %s %s (batch %s)', $tool, $dryRun ? 'dry-run' : 'apply', $ok ? 'OK' : 'failed', $batchId),
                self::level($ok, $stage),
                AiLogChannelCatalog::CHANNEL_MCP,
                self::details($tool, $batchId, $dryRun, $ok, $stage, $operations, $fieldNames, $error, $problems, $recordIds),
            );
        } catch (\Throwable) {
            // Audit logging must never break the call itself.
        }
    }

    /**
     * A validation refusal is a bad request. A DataHandler failure, including a dry run, is an error.
     */
    private static function level(bool $ok, string $stage): string
    {
        if ($ok) {
            return 'info';
        }

        return $stage === 'validation' ? 'warning' : 'error';
    }

    /**
     * @param array<string, array<string, int>> $operations
     * @param array<string, list<string>> $fieldNames
     * @param list<array<string, mixed>> $problems
     * @param array<string, list<string>> $recordIds
     * @return array<string, mixed>
     */
    private static function details(
        string $tool,
        string $batchId,
        bool $dryRun,
        bool $ok,
        string $stage,
        array $operations,
        array $fieldNames,
        string $error,
        array $problems,
        array $recordIds,
    ): array {
        $data = [
            'tool' => $tool,
            'batchId' => $batchId,
            'dryRun' => $dryRun,
            'ok' => $ok,
            'stage' => $stage,
            'operations' => $operations,
            'fields' => array_map(
                static fn(array $names): array => array_slice($names, 0, self::MAX_FIELD_NAMES),
                $fieldNames,
            ),
        ];
        if ($error !== '') {
            $data['error'] = mb_substr(self::redact($error), 0, 300);
        }
        $listedProblems = self::problems($problems);
        if ($listedProblems !== []) {
            $data['problems'] = $listedProblems;
        }
        $listedRecords = self::recordIds($recordIds);
        if ($listedRecords !== []) {
            $data['records'] = $listedRecords;
        }

        return $data;
    }

    /**
     * @param list<array<string, mixed>> $problems
     * @return list<array<string, mixed>>
     */
    private static function problems(array $problems): array
    {
        $listed = [];
        foreach (array_slice($problems, 0, 3) as $problem) {
            if (!is_array($problem)) {
                continue;
            }
            $entry = [];
            foreach (['table', 'id'] as $key) {
                if (isset($problem[$key]) && is_scalar($problem[$key])) {
                    $entry[$key] = RecordsApplyProblems::safe((string) $problem[$key]);
                }
            }
            $fields = $problem['fields'] ?? [];
            if (is_array($fields) && $fields !== []) {
                $names = [];
                foreach (array_slice($fields, 0, self::MAX_FIELD_NAMES) as $field) {
                    if (is_scalar($field)) {
                        $names[] = RecordsApplyProblems::safe((string) $field);
                    }
                }
                if ($names !== []) {
                    $entry['fields'] = $names;
                }
            }
            if (isset($problem['error']) && is_scalar($problem['error'])) {
                $entry['error'] = self::redact(RecordsApplyProblems::safe((string) $problem['error']));
            }
            if ($entry !== []) {
                $listed[] = $entry;
            }
        }

        return $listed;
    }

    /**
     * @param array<string, list<string>> $recordIds
     * @return array<string, list<string>>
     */
    private static function recordIds(array $recordIds): array
    {
        $listed = [];
        foreach ($recordIds as $table => $ids) {
            if (!is_array($ids)) {
                continue;
            }
            $safeIds = [];
            foreach (array_slice($ids, 0, self::MAX_FIELD_NAMES) as $id) {
                if (is_scalar($id)) {
                    $safeIds[] = RecordsApplyProblems::safe((string) $id);
                }
            }
            if ($safeIds !== []) {
                $listed[RecordsApplyProblems::safe((string) $table)] = $safeIds;
            }
        }

        return $listed;
    }

    /**
     * Quoted record text stays out of the log. CType and colPos are kept, cut to 80 characters,
     * because those values are what a refusal is about. The record title stays hidden.
     */
    private static function redact(string $text): string
    {
        $kept = [];
        $text = preg_replace_callback(
            '/\b(CType|colPos)\s+"([^"]*)"/u',
            static function (array $match) use (&$kept): string {
                $token = "\x00K" . count($kept) . "\x00";
                $kept[$token] = $match[1] . ' "' . RecordsApplyProblems::safe($match[2]) . '"';

                return $token;
            },
            $text,
        ) ?? $text;
        $text = preg_replace('/"[^"]*"/u', '"…"', $text) ?? '';
        $text = preg_replace('/(?<![\p{L}\p{N}])\'[^\']*\'/u', '\'…\'', $text) ?? '';

        return strtr($text, $kept);
    }
}
