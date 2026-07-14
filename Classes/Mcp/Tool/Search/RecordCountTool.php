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

namespace NITSAN\NsT3AF\Mcp\Tool\Search;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;

readonly class RecordCountTool implements McpNonAiToolInterface
{
    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
    ) {}

    #[McpTool(
        name: 'record_count',
        description: 'Count records in any table without fetching them. Optionally filter by pid and/or search conditions.'
            . ' Pass search as a JSON object with field names as keys (same format as record_search).'
            . ' Returns only the count, not the records themselves.',
    )]
    public function execute(string $tableName, int $pid = -1, string $search = ''): string
    {
        $readFields = $this->tcaSchemaService->getReadFields($tableName);
        if ($readFields === ['uid', 'pid']) {
            return json_encode(['error' => 'Table not found or has no readable fields: ' . $tableName], JSON_THROW_ON_ERROR);
        }

        $searchConditions = [];
        $ignoredFields = [];

        if ($search !== '') {
            try {
                /** @var array<string, mixed> $searchData */
                $searchData = json_decode($search, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return json_encode(
                    ['error' => 'Invalid JSON in search parameter: ' . $e->getMessage()],
                    JSON_THROW_ON_ERROR,
                );
            }

            $allowedFields = array_merge(['uid', 'pid'], $readFields);
            $searchConditions = SearchConditionParser::fromArray($searchData, $allowedFields);
            $ignoredFields = array_values(array_diff(array_keys($searchData), $allowedFields));
        }

        $count = $this->recordService->count($tableName, $pid >= 0 ? $pid : null, $searchConditions);

        $response = ['table' => $tableName, 'count' => $count];
        if ($ignoredFields !== []) {
            $response['ignoredFields'] = $ignoredFields;
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }
}
