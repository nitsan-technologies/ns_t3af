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

use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;

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
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null $allowedFields
     */
    public function planCreate(string $tableName, array $payload, string $toolName, ?array $allowedFields = null): ToolPlan
    {
        if (!isset($payload['pid']) || !is_numeric($payload['pid'])) {
            throw new \InvalidArgumentException('Create requires numeric "pid" in data.');
        }

        $pid = (int) $payload['pid'];
        unset($payload['pid']);
        $filteredData = $this->filterWritableFields($tableName, $payload, $allowedFields);

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

        return new ToolPlan('create', $toolName, $fields, ['pid' => $pid]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null $allowedFields
     */
    public function planUpdate(string $tableName, int $uid, array $payload, string $toolName, ?array $allowedFields = null): ToolPlan
    {
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Update requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            throw new \InvalidArgumentException('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        $filteredData = $this->filterWritableFields($tableName, $payload, $allowedFields);
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
