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
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\PageAccessService;
use NITSAN\NsT3AF\Mcp\Service\RecordPayloadNormalizer;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyFileAccess;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyResult;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyValidationException;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * One record per call. The write itself runs on the records_apply engine (preflight, one DataHandler
 * run in a transaction, audit, AI Label marking), so a single write and a batch behave the same way.
 * Not strict by default: fields that cannot be written are dropped and reported in "ignoredFields", as before.
 * With strict=true the call is refused instead, and names those fields.
 * New records are appended after the existing ones of their page (give a negative pid to place one).
 * File fields are attached afterwards, outside that transaction.
 */
#[McpToolSeverity(ToolSeverity::Write)]
readonly class WriteTableTool implements McpNonAiToolInterface, McpPlannableToolInterface
{
    private const ALLOWED_ACTIONS = ['create', 'update', 'delete'];

    /** A NEW id for the one record of a create. */
    private const NEW_ID = 'NEWrecord';

    private RecordPayloadNormalizer $normalizer;

    public function __construct(
        private DataHandlerService $dataHandlerService,
        private RecordService $recordService,
        TcaSchemaService $tcaSchemaService,
        private RecordsApplyService $recordsApply,
        private ?PageAccessService $pageAccess = null,
        private ?RecordsApplyFileAccess $fileAccess = null,
    ) {
        $this->normalizer = new RecordPayloadNormalizer($tcaSchemaService);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(array $arguments): ToolPlan
    {
        $action = (string) ($arguments['action'] ?? '');
        $tableName = (string) ($arguments['tableName'] ?? ($arguments['table'] ?? ''));
        $uid = (int) ($arguments['uid'] ?? 0);
        $dataRaw = $arguments['data'] ?? '{}';
        $dataRaw = is_string($dataRaw) ? $dataRaw : json_encode($dataRaw, JSON_THROW_ON_ERROR);

        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new \InvalidArgumentException('Invalid action. Use create, update, or delete.');
        }

        if (!$this->tableExists($tableName)) {
            throw new \InvalidArgumentException('Table not found: ' . $tableName);
        }

        $payload = self::decodeData($dataRaw, $action);

        return match ($action) {
            'create' => $this->planCreate($tableName, $payload),
            'update' => $this->planUpdate($tableName, $uid, $payload),
            'delete' => $this->planDelete($tableName, $uid),
        };
    }

    #[McpTool(
        name: 'write_table',
        description: 'Create, update, or delete records in a TYPO3 table via DataHandler.'
            . ' Use table_schema first to discover valid field names and types.'
            . ' For create, include "pid" in the JSON data object; the new record is appended after the existing ones on that page'
            . ' (negative pid = insert after that uid instead).'
            . ' For update/delete, pass the record uid.'
            . ' strict=true refuses the whole call and names the fields that are unknown or that you may not change; by default they are dropped and reported in "ignoredFields".'
            . ' Category/MM relations (categories, authors, tags, …) accept a comma-separated UID string, e.g. "126" or "8,12".'
            . ' File/image fields accept [{"uid_local": <sys_file uid>, "alternative": "..."}]'
            . ' (full replace on update; empty array clears attachments).'
            . ' Content Blocks collections: write child rows in the collection table with foreign_table_parent_uid,'
            . ' not the parent counter field. Prefer content_delete when deleting a tt_content element.',
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
        bool $strict = false,
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
            $payload = self::decodeData($data, $action);
        } catch (\InvalidArgumentException $exception) {
            return $this->encodeError($exception->getMessage());
        }

        return match ($action) {
            'create' => $this->create($tableName, $payload, $strict),
            'update' => $this->update($tableName, $uid, $payload, $strict),
            'delete' => $this->delete($tableName, $uid),
        };
    }

    /**
     * The "data" JSON object. A delete needs none (empty or invalid data is ignored there);
     * empty data is an empty object.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException when create/update data is not a JSON object
     */
    public static function decodeData(string $data, string $action): array
    {
        if ($action === 'delete' || trim($data) === '') {
            return [];
        }
        try {
            $payload = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                'data must be a JSON object of field values, e.g. {"pid": 12, "title": "News"} (' . $exception->getMessage() . ').',
                1790400001,
                $exception,
            );
        }
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new \InvalidArgumentException('data must be a JSON object of field values, e.g. {"pid": 12, "title": "News"}.', 1790400002);
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function planCreate(string $tableName, array $payload): ToolPlan
    {
        if (!isset($payload['pid']) || !is_numeric($payload['pid'])) {
            throw new \InvalidArgumentException('Create requires numeric "pid" in data.');
        }

        $pid = (int) $payload['pid'];
        if (!$this->targetIsReadable($tableName, $pid)) {
            throw new \InvalidArgumentException(PageAccessService::ACCESS_DENIED_MESSAGE);
        }
        unset($payload['pid']);
        $filteredData = $this->normalizer->filterWritableFields($tableName, $payload);

        $fields = [];
        foreach ($filteredData as $fieldName => $value) {
            $fields[] = new ToolPlanField(
                ToolPlanField::buildKey($tableName, 0, $fieldName),
                $tableName,
                0,
                $fieldName,
                null,
                $value,
            );
        }

        return new ToolPlan('create', 'write_table', $fields, ['pid' => $pid]);
    }

    /** @param array<string, mixed> $payload */
    private function planUpdate(string $tableName, int $uid, array $payload): ToolPlan
    {
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Update requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            throw new \InvalidArgumentException('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        $filteredData = $this->normalizer->filterWritableFields($tableName, $payload);
        if ($filteredData === []) {
            throw new \InvalidArgumentException($this->normalizer->noWritableFieldsMessage($tableName, $payload, $filteredData));
        }

        $fieldNames = array_keys($filteredData);
        $current = $this->recordService->findByUid($tableName, $uid, $fieldNames) ?? [];

        $fields = [];
        foreach ($filteredData as $fieldName => $value) {
            $fields[] = new ToolPlanField(
                ToolPlanField::buildKey($tableName, $uid, $fieldName),
                $tableName,
                $uid,
                $fieldName,
                $current[$fieldName] ?? null,
                $value,
            );
        }

        return new ToolPlan('update', 'write_table', $fields);
    }

    private function planDelete(string $tableName, int $uid): ToolPlan
    {
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Delete requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            throw new \InvalidArgumentException('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        return new ToolPlan('delete', 'write_table', [
            new ToolPlanField(
                ToolPlanField::buildKey($tableName, $uid, '_record'),
                $tableName,
                $uid,
                '_record',
                'exists',
                'delete',
            ),
        ]);
    }

    /** Refuses a create target page the backend user cannot read (negative pid = after record uid). */
    private function targetIsReadable(string $tableName, int $pid): bool
    {
        if ($this->pageAccess === null || $this->pageAccess->isUnrestricted() || $pid === 0) {
            return true;
        }

        return $pid < 0
            ? $this->recordService->findByUid($tableName, abs($pid), ['uid']) !== null
            : $this->pageAccess->canReadPage($pid);
    }

    /** @param array<string, mixed> $payload */
    private function create(string $tableName, array $payload, bool $strict = false): string
    {
        if (!isset($payload['pid']) || !is_numeric($payload['pid'])) {
            return $this->encodeError('Create requires numeric "pid" in data.');
        }

        $pid = (int) $payload['pid'];
        if (!$this->targetIsReadable($tableName, $pid)) {
            return $this->encodeError(PageAccessService::ACCESS_DENIED_MESSAGE);
        }
        unset($payload['pid']);

        [$payload, $fileFields] = $this->normalizer->extractFileFields($tableName, $payload);
        $payload = $this->normalizer->normalizeRelationUidListFields($tableName, $payload);
        $filteredData = $this->normalizer->filterWritableFields($tableName, $payload);
        $ignoredFields = $this->normalizer->ignoredFields($payload, $filteredData);

        if ($strict && $ignoredFields !== []) {
            return $this->encodeError(
                'Strict mode: these fields are unknown or not writable: ' . implode(', ', $ignoredFields) . '. Nothing was written.',
                $this->normalizer->ignoredFieldsContext($tableName, $ignoredFields),
            );
        }

        $unreadable = $this->unreadableFile($fileFields);
        if ($unreadable !== null) {
            return $this->encodeError($unreadable);
        }

        if ($filteredData === [] && $fileFields === []) {
            return $this->encodeError(
                'No valid writable fields provided.',
                $this->normalizer->ignoredFieldsContext($tableName, $ignoredFields),
            );
        }

        try {
            $result = $this->write([$tableName => [self::NEW_ID => ['pid' => $pid] + $filteredData]], [], $strict);
            $newUid = $result->created[self::NEW_ID] ?? 0;
            if ($newUid <= 0) {
                throw new \RuntimeException('Failed to create record: no uid returned', 1712000020);
            }

            $fileFieldUids = $this->applyFileFields($tableName, $newUid, $fileFields);
        } catch (\Throwable $exception) {
            return $this->encodeError($this->describe($exception));
        }

        [$writtenFields, $ignoredFields] = $this->withEngineIgnored($filteredData, $ignoredFields, $result);

        $response = [
            'action' => 'create',
            'table' => $tableName,
            'uid' => $newUid,
            'pid' => $pid,
            'fields' => $writtenFields,
            'ignoredFields' => $ignoredFields,
        ];
        if ($ignoredFields !== []) {
            $response['ignoredFieldDetails'] = $this->normalizer->ignoredFieldDetails($tableName, $ignoredFields);
        }
        if ($fileFieldUids !== []) {
            $response['fileFields'] = $fileFieldUids;
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload */
    private function update(string $tableName, int $uid, array $payload, bool $strict = false): string
    {
        if ($uid <= 0) {
            return $this->encodeError('Update requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            return $this->encodeError('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        [$payload, $fileFields] = $this->normalizer->extractFileFields($tableName, $payload);
        $payload = $this->normalizer->normalizeRelationUidListFields($tableName, $payload);
        $filteredData = $this->normalizer->filterWritableFields($tableName, $payload);
        $ignoredFields = $this->normalizer->ignoredFields($payload, $filteredData);

        if ($strict && $ignoredFields !== []) {
            return $this->encodeError(
                'Strict mode: these fields are unknown or not writable: ' . implode(', ', $ignoredFields) . '. Nothing was written.',
                $this->normalizer->ignoredFieldsContext($tableName, $ignoredFields),
            );
        }

        $unreadable = $this->unreadableFile($fileFields);
        if ($unreadable !== null) {
            return $this->encodeError($unreadable);
        }

        if ($filteredData === [] && $fileFields === []) {
            return $this->encodeError(
                'No valid writable fields provided.',
                $this->normalizer->ignoredFieldsContext($tableName, $ignoredFields),
            );
        }

        $result = null;

        try {
            if ($filteredData !== []) {
                $result = $this->write([$tableName => [$uid => $filteredData]], [], $strict);
            }
            $fileFieldUids = $this->applyFileFields($tableName, $uid, $fileFields);
        } catch (\Throwable $exception) {
            return $this->encodeError($this->describe($exception));
        }

        [$writtenFields, $ignoredFields] = $this->withEngineIgnored($filteredData, $ignoredFields, $result);

        $response = [
            'action' => 'update',
            'table' => $tableName,
            'uid' => $uid,
            'fields' => $writtenFields,
            'ignoredFields' => $ignoredFields,
        ];
        if ($ignoredFields !== []) {
            $response['ignoredFieldDetails'] = $this->normalizer->ignoredFieldDetails($tableName, $ignoredFields);
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
            $this->write([], [$tableName => [$uid => ['delete' => 1]]]);
        } catch (\Throwable $exception) {
            return $this->encodeError($this->describe($exception));
        }

        return json_encode([
            'action' => 'delete',
            'table' => $tableName,
            'uid' => $uid,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     * @param array<string, array<int, array<string, int>>> $cmdmap
     */
    private function write(array $datamap, array $cmdmap, bool $strict = false): RecordsApplyResult
    {
        return $this->recordsApply->apply($datamap, $cmdmap, false, $strict, true, [], 'write_table');
    }

    /**
     * Fields the engine dropped on top of those the normalizer already ignored.
     *
     * @param array<string, mixed> $filteredData
     * @param list<string> $ignoredFields
     * @return array{0: list<string>, 1: list<string>} the fields that were written, the fields that were ignored
     */
    private function withEngineIgnored(array $filteredData, array $ignoredFields, ?RecordsApplyResult $result): array
    {
        $dropped = [];
        foreach ($result->ignoredFields ?? [] as $entry) {
            $dropped = array_merge($dropped, $entry['fields']);
        }

        return [
            array_values(array_diff(array_keys($filteredData), $dropped)),
            array_values(array_unique(array_merge($ignoredFields, $dropped))),
        ];
    }

    /** The message write_table has always returned, built from what the engine refused with. */
    private function describe(\Throwable $exception): string
    {
        if ($exception instanceof RecordsApplyValidationException) {
            $parts = [];
            foreach ($exception->getProblems() as $problem) {
                $text = (string) ($problem['error'] ?? '');
                $fields = $problem['fields'] ?? [];
                if (is_array($fields) && $fields !== []) {
                    $text .= ' (' . implode(', ', array_map('strval', $fields)) . ')';
                }
                $parts[] = $text;
            }

            return implode('; ', array_slice($parts, 0, 5));
        }

        if ($exception instanceof ToolCallException) {
            $payload = json_decode($exception->getMessage(), true);
            if (is_array($payload) && isset($payload['errors']) && is_array($payload['errors'])) {
                return 'DataHandler errors: ' . implode('; ', array_map('strval', $payload['errors']));
            }
        }

        return $exception->getMessage();
    }

    /**
     * The first file reference that points at a file the backend user may not read, as an error message.
     *
     * @param array<string, list<array{uid_local: int}>> $fileFields
     */
    private function unreadableFile(array $fileFields): ?string
    {
        if ($this->fileAccess === null) {
            return null;
        }

        foreach ($fileFields as $references) {
            foreach ($references as $reference) {
                $fileUid = (int) ($reference['uid_local'] ?? 0);
                if ($fileUid > 0 && !$this->fileAccess->canRead($fileUid)) {
                    return sprintf('File %d was not found or is not accessible to you. Nothing was written.', $fileUid);
                }
            }
        }

        return null;
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
