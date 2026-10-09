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
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

#[McpToolSeverity(ToolSeverity::Write)]
readonly class FileReferenceAddTool implements McpNonAiToolInterface, McpPlannableToolInterface
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private TcaSchemaService $tcaSchemaService,
        private McpConfirmationPlanBuilder $confirmationPlanBuilder,
        private RecordService $recordService,
        private ResourceFactory $resourceFactory,
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
        $record = $this->findRecord($table, $uid);
        if ($record === null) {
            throw new \InvalidArgumentException(sprintf(
                'There is no %s record with uid %d. Use the uid of the record itself (for a new content element: the uid from the applied result), not the page uid.',
                $table,
                $uid,
            ));
        }
        $this->assertFieldIsShownForRecord($table, $uid, $record, $fieldName, $fileFields);
        $files = $this->filesToAttach(self::numericFileUids($fileUids));
        $fileLabels = implode(', ', array_map(
            static fn(File $file): string => $file->getName() . ' (uid ' . $file->getUid() . ')',
            $files,
        ));

        $arguments = [
            'table' => $table,
            'uid' => $uid,
            'fieldName' => $fieldName,
            'fileUids' => implode(',', array_map(static fn(File $file): int => $file->getUid(), $files)),
        ];

        return $this->confirmationPlanBuilder->confirmation(
            'update',
            'file_reference_add',
            '_reference',
            $fieldName,
            'attach file(s) ' . $fileLabels,
            [
                'arguments' => $arguments,
                'summary' => sprintf('Attach file(s) %s to %s uid %d (%s)', $fileLabels, $table, $uid, $fieldName),
                ...$arguments,
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
            $this->assertFieldIsShownForRecord($table, $uid, $record, $fieldName, $fileFields);
            $this->filesToAttach($parsedUids);
        } catch (\InvalidArgumentException $exception) {
            return $this->encodeError($exception->getMessage());
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
                'fileUids' => $parsedUids,
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
        $fields = ['uid', 'pid'];
        if ($deleteField !== '') {
            $fields[] = $deleteField;
        }
        $typeField = self::typeField($table);
        if ($typeField !== '') {
            $fields[] = $typeField;
        }
        try {
            $row = $this->recordService->findByUid($table, $uid, $fields);
        } catch (\Throwable) {
            return null;
        }

        return $row !== null && ($deleteField === '' || (int) ($row[$deleteField] ?? 0) === 0) ? $row : null;
    }

    /** Type column of the table, or '' when the type comes from a related record ("uid_local:type"). */
    private static function typeField(string $table): string
    {
        $typeField = (string) ($GLOBALS['TCA'][$table]['ctrl']['type'] ?? '');

        return str_contains($typeField, ':') ? '' : $typeField;
    }

    /**
     * "assets" on an Image element is stored but never shown or rendered.
     *
     * @param array<string, mixed> $record
     * @param list<string> $fileFields
     */
    private function assertFieldIsShownForRecord(string $table, int $uid, array $record, string $fieldName, array $fileFields): void
    {
        $typeField = self::typeField($table);
        $recordType = $typeField !== '' ? trim((string) ($record[$typeField] ?? '')) : '';
        if ($recordType === '') {
            return;
        }
        $shown = $this->tcaSchemaService->getShownFields($table, $recordType);
        if ($shown === [] || in_array($fieldName, $shown, true)) {
            return;
        }
        $usable = array_values(array_intersect($fileFields, $shown));

        throw new \InvalidArgumentException(sprintf(
            'Field "%s" is not used by %s uid %d (%s "%s"). %s',
            $fieldName,
            $table,
            $uid,
            $typeField,
            $recordType,
            $usable !== [] ? 'Use fieldName "' . implode('" or "', $usable) . '".' : 'This record type has no file field.',
        ));
    }

    /** @return non-empty-list<int> */
    private static function numericFileUids(string $fileUids): array
    {
        $uids = [];
        foreach (explode(',', $fileUids) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^[1-9]\d*$/', $part) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s" is not a sys_file uid. Pass the numeric uid returned by file_upload_from_url, file_upload or file_list (storageUid 1 is fileadmin), not a file name.',
                    $part,
                ));
            }
            $uids[] = (int) $part;
        }
        if ($uids === []) {
            throw new \InvalidArgumentException('fileUids is required: one or more sys_file uids, comma-separated.');
        }

        return array_values(array_unique($uids));
    }

    /**
     * @param list<int> $fileUids
     * @return list<File>
     */
    private function filesToAttach(array $fileUids): array
    {
        $files = [];
        foreach ($fileUids as $fileUid) {
            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
            } catch (\Throwable) {
                throw new \InvalidArgumentException(sprintf(
                    'There is no file with sys_file uid %d. Look the file up with file_list (storageUid 1) and use its uid.',
                    $fileUid,
                ));
            }
            try {
                $readable = $file->checkActionPermission('read');
                $onDisk = $readable && !$file->isMissing() && $file->exists();
            } catch (\Throwable) {
                $readable = false;
                $onDisk = false;
            }
            if (!$readable) {
                throw new \InvalidArgumentException(sprintf('You may not use the file with sys_file uid %d.', $fileUid));
            }
            if (!$onDisk) {
                throw new \InvalidArgumentException(sprintf(
                    'The file %s (sys_file uid %d) is missing from the storage, so it would show as a broken image. Upload it again or pick another file.',
                    $file->getName(),
                    $fileUid,
                ));
            }
            $files[] = $file;
        }

        return $files;
    }

    /** @param array<string, mixed> $context */
    private function encodeError(string $message, array $context = []): string
    {
        return json_encode(array_merge(['error' => $message], $context), JSON_THROW_ON_ERROR);
    }
}
