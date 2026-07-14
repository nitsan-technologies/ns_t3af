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

readonly class SchedulerGetTool implements McpNonAiToolInterface
{
    private const TABLE = 'tx_scheduler_task';

    /** @var list<string> */
    private const READ_FIELDS = [
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
        name: 'scheduler_get',
        description: 'Get a single scheduler task by its uid. Requires cms-scheduler extension.',
    )]
    public function execute(int $uid): string
    {
        if (!ExtensionManagementUtility::isLoaded('scheduler')) {
            return json_encode(['error' => 'cms-scheduler extension is not installed'], JSON_THROW_ON_ERROR);
        }

        $record = $this->recordService->findByUid(self::TABLE, $uid, self::READ_FIELDS);

        if ($record === null) {
            return json_encode(['error' => 'Scheduler task not found'], JSON_THROW_ON_ERROR);
        }

        $serializedTaskObject = (string) ($record['serialized_task_object'] ?? '');
        $record['tasktype'] = $this->extractTaskType($serializedTaskObject);
        unset($record['serialized_task_object']);

        return json_encode($record, JSON_THROW_ON_ERROR);
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
