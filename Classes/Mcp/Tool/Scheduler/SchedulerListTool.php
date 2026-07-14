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

namespace NITSAN\NsT3AF\Mcp\Tool\Scheduler;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

readonly class SchedulerListTool implements McpNonAiToolInterface
{
    private const TABLE = 'tx_scheduler_task';

    /** @var list<string> */
    private const LIST_FIELDS = [
        'uid',
        'serialized_task_object',
        'task_group',
        'description',
        'disable',
        'nextexecution',
        'lastexecution_time',
        'lastexecution_failure',
        'lastexecution_context',
    ];

    public function __construct(private RecordService $recordService) {}

    #[McpTool(
        name: 'scheduler_list',
        description: 'List scheduler tasks with pagination and optional filtering.'
            . ' Use tasktype for text search by task class name (LIKE).'
            . ' Use taskGroup to filter by group ID. Use disable (0 or 1) to filter by status.'
            . ' Requires cms-scheduler extension.',
    )]
    public function execute(
        int $limit = 20,
        int $offset = 0,
        string $tasktype = '',
        int $taskGroup = -1,
        int $disable = -1,
    ): string {
        if (!ExtensionManagementUtility::isLoaded('scheduler')) {
            return json_encode(['error' => 'cms-scheduler extension is not installed'], JSON_THROW_ON_ERROR);
        }

        /** @var array<string, array{operator: string, value: string}> $conditions */
        $conditions = [];

        if ($tasktype !== '') {
            $conditions['serialized_task_object'] = ['operator' => 'like', 'value' => $tasktype];
        }
        if ($taskGroup >= 0) {
            $conditions['task_group'] = ['operator' => 'eq', 'value' => (string) $taskGroup];
        }
        if ($disable >= 0) {
            $conditions['disable'] = ['operator' => 'eq', 'value' => (string) $disable];
        }

        $result = $this->recordService->search(
            self::TABLE,
            $conditions,
            $limit,
            $offset,
            self::LIST_FIELDS,
            null,
            'uid',
            'ASC',
        );

        $records = $result['records'] ?? [];
        if (is_array($records)) {
            foreach ($records as &$record) {
                if (!is_array($record)) {
                    continue;
                }
                $serializedTaskObject = (string) ($record['serialized_task_object'] ?? '');
                $record['tasktype'] = $this->extractTaskType($serializedTaskObject);
                unset($record['serialized_task_object']);
            }
            unset($record);
            $result['records'] = $records;
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    private function extractTaskType(string $serializedTaskObject): string
    {
        if ($serializedTaskObject === '') {
            return '';
        }

        if (preg_match('/O:\\d+:\"([^\"]+)\"/', $serializedTaskObject, $match) === 1) {
            return (string) ($match[1] ?? '');
        }

        if (preg_match('/commandIdentifier\";s:\\d+:\"([^\"]+)\"/', $serializedTaskObject, $match) === 1) {
            return (string) ($match[1] ?? '');
        }

        return '';
    }
}
