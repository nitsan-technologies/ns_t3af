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

namespace NITSAN\NsT3AF\Mcp\Tool\Record;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

readonly class WriteTableTool implements McpNonAiToolInterface
{
    private const ALLOWED_ACTIONS = ['create', 'update', 'delete'];

    /** @var list<string> */
    private const FILE_REF_META_KEYS = ['alternative', 'title', 'description', 'link', 'crop'];

    public function __construct(
        private DataHandlerService $dataHandlerService,
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
    ) {}

    #[McpTool(
        name: 'write_table',
        description: 'Create, update, or delete records in a TYPO3 table via DataHandler.'
            . ' Use table_schema first to discover valid field names and types.'
            . ' For create, include "pid" in the JSON data object (negative pid = insert after that uid for sorting).'
            . ' For update/delete, pass the record uid.'
            . ' Category/MM relations (categories, authors, tags, …) accept a comma-separated UID string, e.g. "126" or "8,12".'
            . ' File/image fields accept [{"uid_local": <sys_file uid>, "alternative": "..."}]'
            . ' (full replace on update; empty array clears attachments).'
            . ' Content Blocks collections: write child rows in the collection table with foreign_table_parent_uid,'
            . ' not the parent counter field.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
        ),
    )]
    public function execute(
        #[Schema(enum: ['create', 'update', 'delete'])]
        string $action,
        string $tableName,
        string $data = '{}',
        int $uid = 0,
    ): string {
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            return $this->encodeError('Invalid action. Use create, update, or delete.');
        }

        if (!$this->tableExists($tableName)) {
            return $this->encodeError('Table not found: ' . $tableName);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->encodeError('No backend user context. Authenticate via OAuth or stdio --user.');
        }

        if (!$backendUser->check('tables_modify', $tableName)) {
            return $this->encodeError('Permission denied: tables_modify on ' . $tableName);
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                return $this->encodeError('Data must be a JSON object.');
            }
        } catch (\JsonException $exception) {
            return $this->encodeError('Invalid JSON in data: ' . $exception->getMessage());
        }

        return match ($action) {
            'create' => $this->create($tableName, $payload),
            'update' => $this->update($tableName, $uid, $payload),
            'delete' => $this->delete($tableName, $uid),
        };
    }

    /** @param array<string, mixed> $payload */
    private function create(string $tableName, array $payload): string
    {
        if (!isset($payload['pid']) || !is_numeric($payload['pid'])) {
            return $this->encodeError('Create requires numeric "pid" in data.');
        }

        $pid = (int) $payload['pid'];
        unset($payload['pid']);

        [$payload, $fileFields] = $this->extractInlineFileFields($tableName, $payload);
        $payload = $this->normalizeRelationUidListFields($tableName, $payload);
        $filteredData = $this->filterWritableFields($tableName, $payload);
        $ignoredFields = $this->ignoredFields($payload, $filteredData);

        if ($filteredData === [] && $fileFields === []) {
            return $this->encodeError(
                'No valid writable fields provided.',
                $this->ignoredFieldsContext($tableName, $ignoredFields),
            );
        }

        try {
            $newUid = $this->dataHandlerService->createRecord($tableName, $pid, $filteredData);
            $fileFieldUids = $this->applyFileFields($tableName, $newUid, $fileFields);
        } catch (\Throwable $exception) {
            return $this->encodeError($exception->getMessage());
        }

        $response = [
            'action' => 'create',
            'table' => $tableName,
            'uid' => $newUid,
            'pid' => $pid,
            'fields' => array_keys($filteredData),
            'ignoredFields' => $ignoredFields,
        ];
        if ($ignoredFields !== []) {
            $response['ignoredFieldDetails'] = $this->ignoredFieldDetails($tableName, $ignoredFields);
        }
        if ($fileFieldUids !== []) {
            $response['fileFields'] = $fileFieldUids;
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload */
    private function update(string $tableName, int $uid, array $payload): string
    {
        if ($uid <= 0) {
            return $this->encodeError('Update requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            return $this->encodeError('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        [$payload, $fileFields] = $this->extractInlineFileFields($tableName, $payload);
        $payload = $this->normalizeRelationUidListFields($tableName, $payload);
        $filteredData = $this->filterWritableFields($tableName, $payload);
        $ignoredFields = $this->ignoredFields($payload, $filteredData);

        if ($filteredData === [] && $fileFields === []) {
            return $this->encodeError(
                'No valid writable fields provided.',
                $this->ignoredFieldsContext($tableName, $ignoredFields),
            );
        }

        try {
            if ($filteredData !== []) {
                $this->dataHandlerService->updateRecord($tableName, $uid, $filteredData);
            }
            $fileFieldUids = $this->applyFileFields($tableName, $uid, $fileFields);
        } catch (\Throwable $exception) {
            return $this->encodeError($exception->getMessage());
        }

        $response = [
            'action' => 'update',
            'table' => $tableName,
            'uid' => $uid,
            'fields' => array_keys($filteredData),
            'ignoredFields' => $ignoredFields,
        ];
        if ($ignoredFields !== []) {
            $response['ignoredFieldDetails'] = $this->ignoredFieldDetails($tableName, $ignoredFields);
        }
        if ($fileFieldUids !== []) {
            $response['fileFields'] = $fileFieldUids;
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }

    private function delete(string $tableName, int $uid): string
    {
        if ($uid <= 0) {
            return $this->encodeError('Delete requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            return $this->encodeError('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        try {
            $this->dataHandlerService->deleteRecord($tableName, $uid);
        } catch (\Throwable $exception) {
            return $this->encodeError($exception->getMessage());
        }

        return json_encode([
            'action' => 'delete',
            'table' => $tableName,
            'uid' => $uid,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Pull TCA file fields shaped as [{"uid_local": N, ...}] (or []) out of the payload.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *     0: array<string, mixed>,
     *     1: array<string, list<array{uid_local: int, alternative?: string, title?: string, description?: string, link?: string, crop?: string}>>
     * }
     */
    private function extractInlineFileFields(string $tableName, array $payload): array
    {
        $extracted = [];
        foreach ($this->tcaSchemaService->getFileFields($tableName) as $fieldName) {
            if (!array_key_exists($fieldName, $payload)) {
                continue;
            }

            $value = $payload[$fieldName];
            if (!is_array($value) || !$this->isUidLocalReferenceList($value)) {
                continue;
            }

            $normalized = [];
            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $uidLocal = (int) ($item['uid_local'] ?? 0);
                if ($uidLocal <= 0) {
                    continue;
                }
                $ref = ['uid_local' => $uidLocal];
                foreach (self::FILE_REF_META_KEYS as $metaKey) {
                    if (isset($item[$metaKey]) && is_string($item[$metaKey])) {
                        $ref[$metaKey] = $item[$metaKey];
                    }
                }
                $normalized[] = $ref;
            }

            $extracted[$fieldName] = $normalized;
            unset($payload[$fieldName]);
        }

        return [$payload, $extracted];
    }

    /** @param array<mixed> $value */
    private function isUidLocalReferenceList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        foreach ($value as $item) {
            if (!is_array($item) || !array_key_exists('uid_local', $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, list<array{uid_local: int, alternative?: string, title?: string, description?: string, link?: string, crop?: string}>> $fileFields
     * @return array<string, list<int>>
     */
    private function applyFileFields(string $tableName, int $recordUid, array $fileFields): array
    {
        $result = [];
        foreach ($fileFields as $fieldName => $references) {
            $result[$fieldName] = $this->dataHandlerService->replaceFileFieldReferences(
                $tableName,
                $recordUid,
                $fieldName,
                $references,
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeRelationUidListFields(string $tableName, array $payload): array
    {
        foreach ($this->tcaSchemaService->getRelationUidListFields($tableName) as $fieldName) {
            if (!array_key_exists($fieldName, $payload)) {
                continue;
            }
            $value = $payload[$fieldName];
            if (is_int($value) || is_float($value)) {
                $payload[$fieldName] = (string) (int) $value;
                continue;
            }
            if (is_array($value)) {
                $uids = [];
                foreach ($value as $item) {
                    if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                        $uids[] = (string) (int) $item;
                    }
                }
                $payload[$fieldName] = implode(',', $uids);
            }
        }

        return $payload;
    }

    /**
     * @param list<string> $ignoredFields
     * @return array{ignoredFields: list<string>, ignoredFieldDetails: list<array<string, mixed>>}
     */
    private function ignoredFieldsContext(string $tableName, array $ignoredFields): array
    {
        return [
            'ignoredFields' => $ignoredFields,
            'ignoredFieldDetails' => $this->ignoredFieldDetails($tableName, $ignoredFields),
        ];
    }

    /**
     * @param list<string> $ignoredFields
     * @return list<array<string, mixed>>
     */
    private function ignoredFieldDetails(string $tableName, array $ignoredFields): array
    {
        $details = [];
        foreach ($ignoredFields as $fieldName) {
            $details[] = $this->tcaSchemaService->describeIgnoredField($tableName, $fieldName);
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function filterWritableFields(string $tableName, array $payload): array
    {
        $writableFields = $this->tcaSchemaService->getWritableFields($tableName);

        return array_intersect_key($payload, array_flip($writableFields));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $filteredData
     * @return list<string>
     */
    private function ignoredFields(array $payload, array $filteredData): array
    {
        return array_values(array_diff(array_keys($payload), array_keys($filteredData)));
    }

    private function tableExists(string $tableName): bool
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca)) {
            return false;
        }

        return isset($tca[$tableName]) && is_array($tca[$tableName]);
    }

    /** @param array<string, mixed> $context */
    private function encodeError(string $message, array $context = []): string
    {
        return json_encode(array_merge(['error' => $message], $context), JSON_THROW_ON_ERROR);
    }
}
