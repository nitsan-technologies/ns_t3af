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

namespace NITSAN\NsT3AF\Mcp\Tool\File;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;

#[McpToolSeverity(ToolSeverity::Read)]
readonly class FileReferenceListTool implements McpNonAiToolInterface
{
    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
    ) {}

    #[McpTool(
        name: 'file_reference_list',
        description: 'List file references attached to a record file/image field.'
            . ' Returns reference UIDs (for file_reference_remove) and sys_file UIDs (uid_local).',
    )]
    public function execute(string $table, int $uid, string $fieldName): string
    {
        $fileFields = $this->tcaSchemaService->getFileFields($table);
        if (!in_array($fieldName, $fileFields, true)) {
            return $this->encodeError(
                'Field \'' . $fieldName . '\' is not a file field on table \'' . $table . '\'',
                ['availableFileFields' => $fileFields],
            );
        }

        if ($uid > 0 && !$this->recordService->recordExists($table, $uid)) {
            return $this->encodeError('Record not found: ' . $table . ' uid ' . $uid . '.');
        }

        // "image" on a Text & Media element is always empty: the element keeps its pictures in "assets".
        $note = '';
        $usable = $this->usableFileFieldsOfRecord($table, $uid, $fieldName, $fileFields);
        if ($usable !== null) {
            if (count($usable) !== 1) {
                return $this->encodeError(
                    'Field \'' . $fieldName . '\' is not used by this record type.'
                    . ($usable !== [] ? ' Use fieldName "' . implode('" or "', $usable) . '".' : ' This record type has no file field.'),
                    ['availableFileFields' => $usable],
                );
            }
            $note = 'Field \'' . $fieldName . '\' is not used by this record type; listed \'' . $usable[0] . '\' instead.';
            $fieldName = $usable[0];
        }

        $references = $this->recordService->findFileReferences($table, $uid, $fieldName);

        return json_encode([
            'table' => $table,
            'uid' => $uid,
            'fieldName' => $fieldName,
            'total' => count($references),
            'references' => $references,
            ...($note !== '' ? ['note' => $note] : []),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The file fields the record type really shows when the asked-for one is not among them, else null.
     *
     * @param list<string> $fileFields
     * @return list<string>|null
     */
    private function usableFileFieldsOfRecord(string $table, int $uid, string $fieldName, array $fileFields): ?array
    {
        $typeField = (string) ($GLOBALS['TCA'][$table]['ctrl']['type'] ?? '');
        if ($typeField === '' || str_contains($typeField, ':') || $uid <= 0) {
            return null;
        }
        try {
            $row = $this->recordService->findByUid($table, $uid, ['uid', $typeField]);
        } catch (\Throwable) {
            return null;
        }
        $recordType = $row !== null ? trim((string) ($row[$typeField] ?? '')) : '';
        if ($recordType === '') {
            return null;
        }
        $shown = $this->tcaSchemaService->getShownFields($table, $recordType);
        if ($shown === [] || in_array($fieldName, $shown, true)) {
            return null;
        }

        return array_values(array_intersect($fileFields, $shown));
    }

    /** @param array<string, mixed> $context */
    private function encodeError(string $message, array $context = []): string
    {
        return json_encode(array_merge(['error' => $message], $context), JSON_THROW_ON_ERROR);
    }
}
