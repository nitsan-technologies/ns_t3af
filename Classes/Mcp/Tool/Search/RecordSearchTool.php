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

readonly class RecordSearchTool implements McpNonAiToolInterface
{
    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
    ) {}

    #[McpTool(
        name: 'record_search',
        description: 'Search records in any table by field conditions. Pass search as a JSON object with field names as keys.'
            . ' Values can be a plain string for LIKE matching (e.g. {"title":"hello"}) or an object with "op" and "value"'
            . ' for advanced operators (e.g. {"uid":{"op":"gt","value":"10"}, "title":{"op":"eq","value":"Home"}}).'
            . ' Supported operators: eq, neq, like, gt, gte, lt, lte, in (comma-separated), null, notNull.'
            . ' Optionally filter by pid. Use orderBy to sort results by a field name and orderDirection (ASC or DESC).'
            . ' Returns matching records with pagination.',
    )]
    public function execute(
        string $tableName,
        string $search,
        int $limit = 20,
        int $offset = 0,
        int $pid = -1,
        string $orderBy = '',
        string $orderDirection = 'ASC',
    ): string {
        $readFields = $this->tcaSchemaService->getReadFields($tableName);
        if ($readFields === ['uid', 'pid']) {
            return json_encode(['error' => 'Table not found or has no readable fields: ' . $tableName], JSON_THROW_ON_ERROR);
        }

        try {
            /** @var array<string, mixed> $searchData */
            $searchData = json_decode($search, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return json_encode(['error' => 'Invalid JSON in search parameter: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
        }

        $allowedFields = array_merge(['uid', 'pid'], $readFields);
        $validSearch = SearchConditionParser::fromArray($searchData, $allowedFields);
        $ignoredFields = array_values(array_diff(array_keys($searchData), $allowedFields));

        if ($validSearch === []) {
            return json_encode(
                ['error' => 'No valid search fields provided', 'ignoredFields' => $ignoredFields],
                JSON_THROW_ON_ERROR,
            );
        }

        $resolvedOrderBy = null;
        if ($orderBy !== '') {
            if (!in_array($orderBy, $allowedFields, true)) {
                return json_encode(
                    ['error' => 'Invalid orderBy field: ' . $orderBy, 'allowedFields' => $allowedFields],
                    JSON_THROW_ON_ERROR,
                );
            }
            $resolvedOrderBy = $orderBy;
        }

        if (!in_array($orderDirection, ['ASC', 'DESC'], true)) {
            $orderDirection = 'ASC';
        }

        $result = $this->recordService->search(
            $tableName,
            $validSearch,
            $limit,
            $offset,
            $readFields,
            $pid >= 0 ? $pid : null,
            $resolvedOrderBy,
            $orderDirection,
        );

        if ($ignoredFields !== []) {
            $result['ignoredFields'] = $ignoredFields;
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
