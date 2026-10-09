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

namespace NITSAN\NsT3AF\Mcp\Service;

use NITSAN\NsT3AF\Access\RecordAccessGate;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Shared TCA record planning for write_table and dynamic per-table tools.
 *
 * @internal
 */
final class McpRecordPlanService
{
    public function __construct(
        private readonly RecordService $recordService,
        private readonly TcaSchemaService $tcaSchemaService,
        private readonly ?PageAccessService $pageAccess = null,
    ) {}

    /**
     * Refuses a write target (pid for create, target for move) the backend user cannot read.
     * A negative value means "after record uid" in TYPO3 and is checked through that record.
     */
    private function assertTargetAccessible(string $tableName, int $target): void
    {
        if ($this->pageAccess === null || $this->pageAccess->isUnrestricted()) {
            return;
        }

        $allowed = $target < 0
            ? $this->recordService->findByUid($tableName, abs($target), ['uid']) !== null
            : ($target === 0 || $this->pageAccess->canReadPage($target));

        if (!$allowed) {
            throw new \InvalidArgumentException(PageAccessService::ACCESS_DENIED_MESSAGE);
        }
    }

    /**
     * Refuses a change to a table the backend user's groups may not modify, before any draft is offered.
     * Without a backend user in the request (unit tests, CLI helpers) nothing is checked here: the
     * DataHandler still enforces the permission when the change is applied.
     */
    private function assertTableModifiable(string $tableName): void
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return;
        }
        if (!(new RecordAccessGate())->canModifyTable($user, $tableName)) {
            throw new \InvalidArgumentException('You are not allowed to change this kind of record with your backend account.');
        }
    }

    /** Fields every create carries; they do not count as content the editor asked for. */
    private const STRUCTURAL_FIELDS = ['CType', 'colPos', 'sys_language_uid', 'l18n_parent', 'hidden', 'sorting', 'list_type'];

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null $allowedFields
     */
    public function planCreate(string $tableName, array $payload, string $toolName, ?array $allowedFields = null): ToolPlan
    {
        $this->assertTableModifiable($tableName);
        if (!isset($payload['pid']) || !is_numeric($payload['pid'])) {
            throw new \InvalidArgumentException('Create requires numeric "pid" in data.');
        }

        $pid = (int) $payload['pid'];
        $this->assertTargetAccessible($tableName, $pid);
        if ($pid > 0) {
            $this->recordService->assertParentPageExists($pid);
        } elseif ($pid < 0) {
            $this->recordService->assertInsertAfterExists($tableName, abs($pid));
        }
        unset($payload['pid']);
        $filteredData = $this->filterWritableFields($tableName, $payload, $allowedFields);
        // Fields the group may not edit would be dropped silently at Apply: the card must not offer them,
        // and a create whose requested content fields are all forbidden is refused.
        $notAllowed = [];
        $user = $GLOBALS['BE_USER'] ?? null;
        if ($user instanceof BackendUserAuthentication && $filteredData !== []) {
            $allowed = (new RecordAccessGate())->withoutForbiddenFields($user, $tableName, $filteredData);
            $notAllowed = array_values(array_map('strval', array_keys(array_diff_key($filteredData, $allowed))));
            if ($notAllowed !== [] && array_diff(array_keys($allowed), self::STRUCTURAL_FIELDS) === []) {
                throw new \InvalidArgumentException('You are not allowed to change this field with your backend account: ' . implode(', ', $notAllowed) . '.');
            }
            $filteredData = $allowed;
        }

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

        return new ToolPlan('create', $toolName, $fields, ['pid' => $pid] + ($notAllowed !== [] ? ['notAllowedFields' => $notAllowed] : []));
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null $allowedFields
     */
    public function planUpdate(string $tableName, int $uid, array $payload, string $toolName, ?array $allowedFields = null): ToolPlan
    {
        $this->assertTableModifiable($tableName);
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Update requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            throw new \InvalidArgumentException('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        $filteredData = $this->filterWritableFields($tableName, $payload, $allowedFields);
        if ($filteredData === []) {
            $ignored = array_values(array_diff(array_keys($payload), array_keys($filteredData)));
            $hints = [];
            foreach ($ignored as $fieldName) {
                if (!is_string($fieldName) || $fieldName === '') {
                    continue;
                }
                $detail = $this->tcaSchemaService->describeIgnoredField($tableName, $fieldName);
                $hint = trim((string) ($detail['hint'] ?? ''));
                if ($hint !== '') {
                    $hints[] = $fieldName . ': ' . $hint;
                }
            }

            throw new \InvalidArgumentException(
                $hints !== []
                    ? 'No valid writable fields provided. ' . implode(' ', $hints)
                    : 'No valid writable fields provided.',
            );
        }

        $user = $GLOBALS['BE_USER'] ?? null;
        if ($user instanceof BackendUserAuthentication) {
            $allowed = (new RecordAccessGate())->withoutForbiddenFields($user, $tableName, $filteredData);
            if ($allowed === []) {
                throw new \InvalidArgumentException('You are not allowed to change this field with your backend account: ' . implode(', ', array_keys($filteredData)) . '.');
            }
            $filteredData = $allowed;
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

        return new ToolPlan('update', $toolName, $fields);
    }

    public function planDelete(string $tableName, int $uid, string $toolName): ToolPlan
    {
        $this->assertTableModifiable($tableName);
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Delete requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            throw new \InvalidArgumentException('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        return new ToolPlan('delete', $toolName, [
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

    public function planMove(string $tableName, int $uid, int $target, string $toolName, string $label = ''): ToolPlan
    {
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Move requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            throw new \InvalidArgumentException('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        $this->assertTargetAccessible($tableName, $target);
        if ($target > 0) {
            $this->recordService->assertParentPageExists($target);
        }
        $current = $this->recordService->findByUid($tableName, $uid, ['pid']) ?? [];

        return new ToolPlan('move', $toolName, [
            new ToolPlanField(
                ToolPlanField::buildKey($tableName, $uid, '_move'),
                $tableName,
                $uid,
                '_move',
                (string) ($current['pid'] ?? ''),
                'move to target ' . $target,
            ),
        ], [
            'target' => $target,
            'label' => $label !== '' ? $label : $tableName . ' ' . $uid,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null $allowedFields
     * @return array<string, mixed>
     */
    private function filterWritableFields(string $tableName, array $payload, ?array $allowedFields): array
    {
        $writable = $allowedFields ?? $this->tcaSchemaService->getWritableFields($tableName);
        if ($writable === []) {
            return [];
        }

        return array_intersect_key($payload, array_flip($writable));
    }
}
