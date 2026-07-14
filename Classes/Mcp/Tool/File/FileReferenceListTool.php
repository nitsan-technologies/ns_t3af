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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Tool\File;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;

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

        $references = $this->recordService->findFileReferences($table, $uid, $fieldName);

        return json_encode([
            'table' => $table,
            'uid' => $uid,
            'fieldName' => $fieldName,
            'total' => count($references),
            'references' => $references,
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $context */
    private function encodeError(string $message, array $context = []): string
    {
        return json_encode(array_merge(['error' => $message], $context), JSON_THROW_ON_ERROR);
    }
}
