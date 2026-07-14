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
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

readonly class SchedulerUpdateTool implements McpNonAiToolInterface
{
    private const TABLE = 'tx_scheduler_task';

    /** @var list<string> */
    private const WRITABLE_FIELDS = [
        'disable',
        'description',
        'task_group',
    ];

    public function __construct(private DataHandlerService $dataHandlerService) {}

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

        $this->dataHandlerService->updateRecord(self::TABLE, $uid, $filteredData);

        return json_encode(['uid' => $uid, 'updatedFields' => array_keys($filteredData), 'ignoredFields' => $ignoredFields], JSON_THROW_ON_ERROR);
    }
}
