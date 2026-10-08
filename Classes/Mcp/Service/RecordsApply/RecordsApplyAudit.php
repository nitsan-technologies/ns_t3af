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
    public const AUDITED_ERROR_CODES = [1790500002, 1790500003, 1790500004, 1790500005, 1790500006];

    /**
     * @param array<string, array<string, int>> $operations
     * @param array<string, list<string>> $fieldNames
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
    ): void {
        try {
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

            SysLogWriterUtility::insert(
                sprintf('MCP %s %s %s (batch %s)', $tool, $dryRun ? 'dry-run' : 'apply', $ok ? 'OK' : 'failed', $batchId),
                $ok ? 'info' : 'error',
                AiLogChannelCatalog::CHANNEL_MCP,
                $data,
            );
        } catch (\Throwable) {
            // Audit logging must never break the call itself.
        }
    }

    /**
     * DataHandler words its refusals around the record: "Attempt to modify record 'My title' …".
     * The log keeps the sentence but not the quoted value, because it can be page or content text.
     */
    private static function redact(string $text): string
    {
        $text = preg_replace('/"[^"]*"/u', '"…"', $text) ?? '';

        return preg_replace('/(?<![\p{L}\p{N}])\'[^\']*\'/u', '\'…\'', $text) ?? '';
    }
}
