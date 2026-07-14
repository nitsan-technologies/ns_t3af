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
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;

readonly class FileReferenceRemoveTool implements McpNonAiToolInterface
{
    public function __construct(private DataHandlerService $dataHandlerService) {}

    #[McpTool(
        name: 'file_reference_remove',
        description: 'Remove file references by their UIDs (from file_reference_list).'
            . ' Detaches files from the record without deleting the underlying sys_file.',
    )]
    public function execute(string $referenceUids): string
    {
        $parsedUids = array_values(array_filter(
            array_map(static fn(string $value): int => (int) trim($value), explode(',', $referenceUids)),
            static fn(int $value): bool => $value > 0,
        ));

        if ($parsedUids === []) {
            return $this->encodeError('No valid reference UIDs provided');
        }

        try {
            foreach ($parsedUids as $referenceUid) {
                $this->dataHandlerService->deleteRecord('sys_file_reference', $referenceUid);
            }

            return json_encode([
                'referencesRemoved' => count($parsedUids),
                'referenceUids' => $parsedUids,
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return $this->encodeError($exception->getMessage());
        }
    }

    /** @param array<string, mixed> $context */
    private function encodeError(string $message, array $context = []): string
    {
        return json_encode(['error' => $message], JSON_THROW_ON_ERROR);
    }
}
