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

namespace NITSAN\NsT3AF\Mcp\Tool\Search;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\PageAccessService;
use NITSAN\NsT3AF\Mcp\Service\PermissionService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;

#[McpToolSeverity(ToolSeverity::Read)]
readonly class RecordSearchTool implements McpNonAiToolInterface
{
    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
        private PageAccessService $pageAccess,
        private PermissionService $permissionService,
    ) {}

    #[McpTool(
        name: 'record_search',
        description: 'Search records in any table by field conditions. Pass search as a JSON object with field names as keys.'
            . ' Values can be a plain string for LIKE matching (e.g. {"title":"hello"}) or an object with "op" and "value"'
            . ' for advanced operators (e.g. {"uid":{"op":"gt","value":"10"}, "title":{"op":"eq","value":"Home"}}).'
            . ' Supported operators: eq, neq, like, gt, gte, lt, lte, in (comma-separated), null, notNull.'
            . ' Optionally filter by pid. Use orderBy to sort results by a field name and orderDirection (ASC or DESC).'
            . ' Use selectFields (comma-separated) to choose which fields to return (same semantics as content_list).'
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
        string $selectFields = '',
    ): string {
        $this->permissionService->assertCanSelectTable($tableName);

        if ($pid > 0 && $tableName !== 'pages' && !$this->pageAccess->canReadPage($pid)) {
            throw new \InvalidArgumentException(PageAccessService::ACCESS_DENIED_MESSAGE, 1790600003);
        }

        $missing = $this->tcaSchemaService->missingTableMessage($tableName);
        if ($missing !== null) {
            throw new \InvalidArgumentException($missing, 1790600004);
        }

        $readFields = $this->tcaSchemaService->getReadFields($tableName);
        if ($readFields === ['uid', 'pid']) {
            throw new \InvalidArgumentException(
                $this->tcaSchemaService->noReadableFieldsMessage($tableName),
                1790600004,
            );
        }

        // "Show me all my news": an empty search lists the table (still limited by page access and rights).
        $listAll = in_array(trim($search), ['', '{}', '[]', '*'], true);
        try {
            $labelField = (string) ($GLOBALS['TCA'][$tableName]['ctrl']['label'] ?? '');
            $searchData = $listAll ? [] : SearchParamParser::parse($search, $labelField, array_merge(['uid', 'pid'], $readFields));
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('Invalid search parameter: ' . $e->getMessage(), 1790600005, $e);
        }

        $allowedFields = array_merge(['uid', 'pid'], $readFields);
        $validSearch = SearchConditionParser::fromArray($searchData, $allowedFields);
        $ignoredFields = array_values(array_diff(array_keys($searchData), $allowedFields));

        if ($validSearch === [] && !$listAll) {
            throw new \InvalidArgumentException(
                'No valid search fields provided' . ($ignoredFields !== [] ? ' (ignored: ' . implode(', ', $ignoredFields) . ')' : ''),
                1790600006,
            );
        }

        $resolvedOrderBy = null;
        if ($orderBy !== '') {
            if (!in_array($orderBy, $allowedFields, true)) {
                throw new \InvalidArgumentException('Invalid orderBy field: ' . $orderBy, 1790600007);
            }
            $resolvedOrderBy = $orderBy;
        }

        if (!in_array($orderDirection, ['ASC', 'DESC'], true)) {
            $orderDirection = 'ASC';
        }

        $fields = $this->resolveSelectFields($selectFields, $readFields);

        // pid 0 (and the default -1) mean "do not filter by storage page". Listing all also
        // ignores an incidental pid so "show all my news" is not scoped to the page on screen.
        $result = $this->recordService->search(
            $tableName,
            $validSearch,
            $limit,
            $offset,
            $fields,
            self::resolveSearchPid($pid, $listAll),
            $resolvedOrderBy,
            $orderDirection,
        );

        if ($ignoredFields !== []) {
            $result['ignoredFields'] = $ignoredFields;
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $defaultFields
     * @return list<string>
     */
    private function resolveSelectFields(string $selectFields, array $defaultFields): array
    {
        if ($selectFields === '') {
            return $defaultFields;
        }

        $requested = array_map('trim', explode(',', $selectFields));
        $allowed = array_merge(['uid', 'pid'], $defaultFields);
        $valid = array_values(array_intersect($requested, $allowed));

        return $valid !== []
            ? array_values(array_unique(array_merge(['uid', 'pid'], $valid)))
            : $defaultFields;
    }

    /**
     * @internal used by unit tests
     */
    public static function resolveSearchPid(int $pid, bool $listAll): ?int
    {
        if ($listAll || $pid <= 0) {
            return null;
        }

        return $pid;
    }
}
