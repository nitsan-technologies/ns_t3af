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

readonly class PagesSearchTool implements McpNonAiToolInterface
{
    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
    ) {}

    #[McpTool(
        name: 'pages_search',
        description: 'Search pages by title or other fields. Pass a plain text string for LIKE matching on title'
            . ' (e.g. "hello") or a JSON object for advanced conditions'
            . ' (e.g. {"doktype":{"op":"eq","value":"1"}, "title":"Home"}).'
            . ' Supports operators: eq, neq, like, gt, gte, lt, lte, in, null, notNull.'
            . ' Use orderBy and orderDirection for sorting.',
    )]
    public function execute(
        string $search,
        int $limit = 20,
        int $offset = 0,
        int $pid = -1,
        string $orderBy = '',
        string $orderDirection = 'ASC',
    ): string {
        $readFields = $this->tcaSchemaService->getReadFields('pages');
        $allowedFields = array_merge(['uid', 'pid'], $readFields);
        $searchConditions = $this->parseSearch($search, $allowedFields);

        if ($searchConditions === []) {
            return json_encode(['error' => 'No valid search conditions provided'], JSON_THROW_ON_ERROR);
        }

        $resolvedOrderBy = null;
        if ($orderBy !== '' && in_array($orderBy, $allowedFields, true)) {
            $resolvedOrderBy = $orderBy;
        }

        if (!in_array($orderDirection, ['ASC', 'DESC'], true)) {
            $orderDirection = 'ASC';
        }

        return json_encode(
            $this->recordService->search(
                'pages',
                $searchConditions,
                $limit,
                $offset,
                $readFields,
                $pid >= 0 ? $pid : null,
                $resolvedOrderBy,
                $orderDirection,
            ),
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param list<string> $allowedFields
     * @return array<string, array{operator: string, value: string}>
     */
    private function parseSearch(string $search, array $allowedFields): array
    {
        /** @var array<string, mixed>|null $jsonData */
        $jsonData = json_decode($search, true);
        if (is_array($jsonData)) {
            return SearchConditionParser::fromArray($jsonData, $allowedFields);
        }

        return ['title' => ['operator' => 'like', 'value' => $search]];
    }
}
