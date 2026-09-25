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

namespace NITSAN\NsT3AF\Mcp\Tool\Redirect;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\McpRecordPlanService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

#[McpToolSeverity(ToolSeverity::Write)]
readonly class RedirectUpdateTool implements McpNonAiToolInterface, McpPlannableToolInterface
{
    private const TABLE = 'sys_redirect';

    /** @var list<string> */
    private const WRITABLE_FIELDS = [
        'source_host',
        'source_path',
        'is_regexp',
        'target',
        'target_statuscode',
        'force_https',
        'keep_query_parameters',
        'respect_query_parameters',
        'protected',
        'disabled',
        'description',
        'starttime',
        'endtime',
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
            'redirect_update',
            self::WRITABLE_FIELDS,
        );
    }

    #[McpTool(
        name: 'redirect_update',
        description: 'Update an existing redirect record. Pass fields as a JSON object string.'
            . ' Available fields: source_host, source_path, is_regexp, target, target_statuscode, force_https,'
            . ' keep_query_parameters, respect_query_parameters, protected, disabled, description, starttime, endtime.'
            . ' Requires cms-redirects extension.',
    )]
    public function execute(int $uid, string $fields): string
    {
        if (!ExtensionManagementUtility::isLoaded('redirects')) {
            return json_encode(['error' => 'cms-redirects extension is not installed'], JSON_THROW_ON_ERROR);
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
