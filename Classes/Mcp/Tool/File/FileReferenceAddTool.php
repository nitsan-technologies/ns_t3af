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
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

#[McpToolSeverity(ToolSeverity::Write)]
readonly class FileReferenceAddTool implements McpNonAiToolInterface, McpPlannableToolInterface
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private TcaSchemaService $tcaSchemaService,
        private McpConfirmationPlanBuilder $confirmationPlanBuilder,
        private RecordService $recordService,
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
        // Check before the editor sees a card: a wrong uid (e.g. the page uid instead of the
        // content element uid) or field would only fail after "Execute".
        $fileFields = $this->tcaSchemaService->getFileFields($table);
        if (!in_array($fieldName, $fileFields, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Field "%s" is not a file field of %s. File fields: %s.',
                $fieldName,
                $table,
                $fileFields !== [] ? implode(', ', $fileFields) : 'none',
            ));
        }
        if ($this->findRecord($table, $uid) === null) {
            throw new \InvalidArgumentException(sprintf(
                'There is no %s record with uid %d. Use the uid of the record itself (for a new content element: the uid from the applied result), not the page uid.',
                $table,
                $uid,
            ));
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
            . ' Pass sys_file UIDs from file_upload_from_url, file_upload, or file_upload_prepare (comma-separated).'
            . ' Updates the parent FAL counter column (e.g. og_image) so SEO generators see the attachment.',
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

        $record = $this->findRecord($table, $uid);
        if ($record === null) {
            return $this->encodeError(sprintf(
                'There is no %s record with uid %d (missing or deleted). Use the content element uid, not the page uid.',
                $table,
                $uid,
            ));
        }

        try {
            $referenceUids = $this->dataHandlerService->createFileReferences(
                $table,
                $uid,
                $fieldName,
                $parsedUids,
                // References live on the page of their record.
                (int) ($record['pid'] ?? 0),
            );
            $parentCount = count($this->recordService->findFileReferences($table, $uid, $fieldName));

            return json_encode([
                'table' => $table,
                'uid' => $uid,
                'fieldName' => $fieldName,
                'referencesCreated' => count($referenceUids),
                'referenceUids' => $referenceUids,
                'parentFieldCount' => $parentCount,
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return $this->encodeError($exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>|null the record (uid, pid), or null when missing/deleted
     */
    private function findRecord(string $table, int $uid): ?array
    {
        $deleteField = (string) ($GLOBALS['TCA'][$table]['ctrl']['delete'] ?? '');
        try {
            $row = $this->recordService->findByUid($table, $uid, $deleteField !== '' ? ['uid', 'pid', $deleteField] : ['uid', 'pid']);
        } catch (\Throwable) {
            return null;
        }

        return $row !== null && ($deleteField === '' || (int) ($row[$deleteField] ?? 0) === 0) ? $row : null;
    }

    /** @param array<string, mixed> $context */
    private function encodeError(string $message, array $context = []): string
    {
        return json_encode(array_merge(['error' => $message], $context), JSON_THROW_ON_ERROR);
    }
}
