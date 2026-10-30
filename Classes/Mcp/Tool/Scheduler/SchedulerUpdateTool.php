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

namespace NITSAN\NsT3AF\Mcp\Tool\Scheduler;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\McpRecordPlanService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

#[McpToolSeverity(ToolSeverity::Write)]
readonly class SchedulerUpdateTool implements McpNonAiToolInterface, McpPlannableToolInterface
{
    private const TABLE = 'tx_scheduler_task';

    /** @var list<string> */
    private const WRITABLE_FIELDS = [
        'disable',
        'description',
        'task_group',
    ];

    public function __construct(
        private DataHandlerService $dataHandlerService,
        private McpRecordPlanService $recordPlanService,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(array $arguments): ToolPlan
    {
        $fieldsRaw = (string) ($arguments['fields'] ?? '{}');
        /** @var array<string, mixed> $data */
        $data = json_decode($fieldsRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('fields must be a JSON object.');
        }

        return $this->recordPlanService->planUpdate(
            self::TABLE,
            (int) ($arguments['uid'] ?? 0),
            $data,
            'scheduler_update',
            self::WRITABLE_FIELDS,
        );
    }

    #[McpTool(
        name: 'scheduler_update',
        description: 'Update a scheduler task. Pass fields as a JSON object string.'
            . ' Available fields: disable, description, task_group.'
            . ' Requires cms-scheduler extension.',
    )]
    public function execute(int $uid, string $fields): string
    {
        if (!ExtensionManagementUtility::isLoaded('scheduler')) {
            return json_encode(['error' => 'cms-scheduler extension is not installed'], JSON_THROW_ON_ERROR);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

        $filteredData = array_intersect_key($data, array_flip(self::WRITABLE_FIELDS));
        $ignoredFields = array_values(array_diff(array_keys($data), array_keys($filteredData)));

        if ($filteredData === []) {
            return json_encode(['error' => 'No valid fields provided', 'ignoredFields' => $ignoredFields], JSON_THROW_ON_ERROR);
        }

        $updatedFields = array_keys($filteredData);
        $existing = BackendUtility::getRecord(self::TABLE, $uid) ?? [];
        $filteredData = self::withExistingSchedule($filteredData, is_array($existing) ? $existing : []);

        $this->dataHandlerService->updateRecord(self::TABLE, $uid, $filteredData);

        return json_encode(['uid' => $uid, 'updatedFields' => $updatedFields, 'ignoredFields' => $ignoredFields], JSON_THROW_ON_ERROR);
    }

    /**
     * The core scheduler validator checks start date and frequency on every save.
     * A description-only update keeps the stored schedule so that check still passes.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    public static function withExistingSchedule(array $fields, array $existing): array
    {
        if (!array_key_exists('execution_details', $fields) && array_key_exists('execution_details', $existing)) {
            $fields['execution_details'] = $existing['execution_details'];
        }

        return $fields;
    }
}
