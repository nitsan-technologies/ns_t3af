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

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Mcp\Exception\UnsupportedPlanException;
use NITSAN\NsT3AF\Mcp\Repository\DiscoveredTableRepository;
use NITSAN\NsT3AF\Mcp\Service\McpRecordPlanService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

/**
 * Plans dynamic per-table MCP tools registered at runtime.
 *
 * @internal
 */
final class DynamicToolPlanService
{
    public function __construct(
        private readonly McpRecordPlanService $recordPlanService,
        private readonly DiscoveredTableRepository $discoveredTableRepository,
    ) {}

    public function supports(string $toolName): bool
    {
        return $this->resolve($toolName) !== null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(string $toolName, array $arguments): ToolPlan
    {
        $resolved = $this->resolve($toolName);
        if ($resolved === null) {
            throw new UnsupportedPlanException('Unknown dynamic tool: ' . $toolName);
        }

        ['table' => $tableName, 'operation' => $operation] = $resolved;

        return match ($operation) {
            'create' => $this->recordPlanService->planCreate(
                $tableName,
                $this->decodeDataArgument($arguments),
                $toolName,
            ),
            'update' => $this->recordPlanService->planUpdate(
                $tableName,
                (int) ($arguments['uid'] ?? 0),
                $this->decodeDataArgument($arguments),
                $toolName,
            ),
            'delete' => $this->recordPlanService->planDelete(
                $tableName,
                (int) ($arguments['uid'] ?? 0),
                $toolName,
            ),
            'move' => $this->recordPlanService->planMove(
                $tableName,
                (int) ($arguments['uid'] ?? 0),
                (int) ($arguments['target'] ?? 0),
                $toolName,
            ),
            'delete_batch' => $this->planBatchDelete($tableName, $arguments, $toolName),
            'update_batch' => $this->planBatchUpdate($tableName, $arguments, $toolName),
            'move_batch' => $this->planBatchMove($tableName, $arguments, $toolName),
            default => throw new UnsupportedPlanException('Unsupported dynamic operation: ' . $operation),
        };
    }

    /**
     * @return array{table: string, operation: string}|null
     */
    private function resolve(string $toolName): ?array
    {
        if (!preg_match('/^(.+)_(create|update|delete|move|delete_batch|update_batch|move_batch)$/', $toolName, $matches)) {
            return null;
        }

        $prefix = $matches[1];
        $operation = $matches[2];
        $tableName = $this->resolveTableForPrefix($prefix);
        if ($tableName === null) {
            return null;
        }

        return ['table' => $tableName, 'operation' => $operation];
    }

    private function resolveTableForPrefix(string $prefix): ?string
    {
        $extconf = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['tables'] ?? [];
        if (is_array($extconf)) {
            foreach ($extconf as $tableName => $config) {
                if (!is_array($config)) {
                    continue;
                }
                if (($config['prefix'] ?? '') === $prefix) {
                    return (string) $tableName;
                }
            }
        }

        try {
            foreach ($this->discoveredTableRepository->findEnabled() as $row) {
                if (($row['prefix'] ?? '') === $prefix) {
                    return (string) ($row['table_name'] ?? '');
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function decodeDataArgument(array $arguments): array
    {
        $data = $arguments['data'] ?? $arguments['fields'] ?? '{}';
        if (is_array($data)) {
            return $data;
        }

        $decoded = json_decode((string) $data, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function planBatchDelete(string $tableName, array $arguments, string $toolName): ToolPlan
    {
        $uids = $this->normalizeUidList($arguments['uids'] ?? $arguments['uidList'] ?? []);
        $plans = [];
        foreach ($uids as $uid) {
            $plans[] = $this->recordPlanService->planDelete($tableName, $uid, $toolName);
        }

        return $this->mergePlans('delete', $toolName, $plans, ['uids' => $uids]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function planBatchUpdate(string $tableName, array $arguments, string $toolName): ToolPlan
    {
        $uids = $this->normalizeUidList($arguments['uids'] ?? $arguments['uidList'] ?? []);
        $payload = $this->decodeDataArgument($arguments);
        $fields = [];
        foreach ($uids as $uid) {
            $plan = $this->recordPlanService->planUpdate($tableName, $uid, $payload, $toolName);
            foreach ($plan->fields as $field) {
                $fields[] = $field;
            }
        }

        return new ToolPlan('update', $toolName, $fields, ['uids' => $uids]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function planBatchMove(string $tableName, array $arguments, string $toolName): ToolPlan
    {
        $uids = $this->normalizeUidList($arguments['uids'] ?? $arguments['uidList'] ?? []);
        $target = (int) ($arguments['target'] ?? 0);
        $fields = [];
        foreach ($uids as $uid) {
            $plan = $this->recordPlanService->planMove($tableName, $uid, $target, $toolName);
            foreach ($plan->fields as $field) {
                $fields[] = $field;
            }
        }

        return new ToolPlan('move', $toolName, $fields, ['uids' => $uids, 'target' => $target]);
    }

    /**
     * @param list<ToolPlan> $plans
     * @param array<string, mixed> $context
     */
    private function mergePlans(string $action, string $toolName, array $plans, array $context): ToolPlan
    {
        $fields = [];
        foreach ($plans as $plan) {
            foreach ($plan->fields as $field) {
                $fields[] = $field;
            }
        }

        return new ToolPlan($action, $toolName, $fields, $context);
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function normalizeUidList(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array_map('intval', explode(',', $raw));
        }
        if (!is_array($raw)) {
            return [];
        }

        $uids = [];
        foreach ($raw as $uid) {
            $uid = (int) $uid;
            if ($uid > 0) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }
}
