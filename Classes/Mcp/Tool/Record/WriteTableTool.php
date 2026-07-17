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

    public function __construct(
        private DataHandlerService $dataHandlerService,
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
    ) {}

    #[McpTool(
        name: 'write_table',
        description: 'Create, update, or delete records in a TYPO3 table via DataHandler.'
            . ' Use table_schema first to discover valid field names and types.'
            . ' For create, include "pid" in the JSON data object. For update/delete, pass the record uid.',
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

        $filteredData = $this->filterWritableFields($tableName, $payload);
        $ignoredFields = $this->ignoredFields($payload, $filteredData);

        if ($filteredData === []) {
            return $this->encodeError('No valid writable fields provided.', ['ignoredFields' => $ignoredFields]);
        }

        try {
            $newUid = $this->dataHandlerService->createRecord($tableName, $pid, $filteredData);
        } catch (\Throwable $exception) {
            return $this->encodeError($exception->getMessage());
        }

        return json_encode([
            'action' => 'create',
            'table' => $tableName,
            'uid' => $newUid,
            'pid' => $pid,
            'fields' => array_keys($filteredData),
            'ignoredFields' => $ignoredFields,
        ], JSON_THROW_ON_ERROR);
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

        $filteredData = $this->filterWritableFields($tableName, $payload);
        $ignoredFields = $this->ignoredFields($payload, $filteredData);

        if ($filteredData === []) {
            return $this->encodeError('No valid writable fields provided.', ['ignoredFields' => $ignoredFields]);
        }

        try {
            $this->dataHandlerService->updateRecord($tableName, $uid, $filteredData);
        } catch (\Throwable $exception) {
            return $this->encodeError($exception->getMessage());
        }

        return json_encode([
            'action' => 'update',
            'table' => $tableName,
            'uid' => $uid,
            'fields' => array_keys($filteredData),
            'ignoredFields' => $ignoredFields,
        ], JSON_THROW_ON_ERROR);
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
