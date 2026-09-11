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
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\McpConfirmationPlanBuilder;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

#[McpToolSeverity(ToolSeverity::Write)]
readonly class FileReferenceAddTool implements McpNonAiToolInterface, McpPlannableToolInterface
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private TcaSchemaService $tcaSchemaService,
        private McpConfirmationPlanBuilder $confirmationPlanBuilder,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(array $arguments): ToolPlan
    {
        $table = (string) ($arguments['table'] ?? '');
        $uid = (int) ($arguments['uid'] ?? 0);
        $fieldName = (string) ($arguments['fieldName'] ?? '');
        $fileUids = (string) ($arguments['fileUids'] ?? '');

        if ($table === '') {
            throw new \InvalidArgumentException('table is required.');
        }

        if ($uid <= 0) {
            throw new \InvalidArgumentException('uid must be > 0.');
        }

        if ($fieldName === '') {
            throw new \InvalidArgumentException('fieldName is required.');
        }

        return $this->confirmationPlanBuilder->confirmation(
            'update',
            'file_reference_add',
            '_reference',
            $fieldName,
            'attach file(s) ' . $fileUids,
            [
                'table' => $table,
                'uid' => $uid,
                'fieldName' => $fieldName,
                'fileUids' => $fileUids,
            ],
            $table,
            $uid,
        );
    }

    #[McpTool(
        name: 'file_reference_add',
        description: 'Attach uploaded files to a record file/image field.'
            . ' Pass sys_file UIDs from file_upload_from_url (comma-separated).',
    )]
    public function execute(string $table, int $uid, string $fieldName, string $fileUids): string
    {
        $parsedUids = array_values(array_filter(
            array_map(static fn(string $value): int => (int) trim($value), explode(',', $fileUids)),
            static fn(int $value): bool => $value > 0,
        ));

        if ($parsedUids === []) {
            return $this->encodeError('No valid file UIDs provided');
        }

        $fileFields = $this->tcaSchemaService->getFileFields($table);
        if (!in_array($fieldName, $fileFields, true)) {
            return $this->encodeError(
                'Field \'' . $fieldName . '\' is not a file field on table \'' . $table . '\'',
                ['availableFileFields' => $fileFields],
            );
        }

        try {
            $referenceUids = $this->dataHandlerService->createFileReferences($table, $uid, $fieldName, $parsedUids);

            return json_encode([
                'table' => $table,
                'uid' => $uid,
                'fieldName' => $fieldName,
                'referencesCreated' => count($referenceUids),
                'referenceUids' => $referenceUids,
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return $this->encodeError($exception->getMessage());
        }
    }

    /** @param array<string, mixed> $context */
    private function encodeError(string $message, array $context = []): string
    {
        return json_encode(array_merge(['error' => $message], $context), JSON_THROW_ON_ERROR);
    }
}
