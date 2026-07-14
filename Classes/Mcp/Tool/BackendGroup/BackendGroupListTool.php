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

namespace NITSAN\NsT3AF\Mcp\Tool\BackendGroup;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\PermissionService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;

readonly class BackendGroupListTool implements McpNonAiToolInterface
{
    /** @var list<string> */
    private const SUMMARY_FIELDS = [
        'uid',
        'title',
        'description',
        'hidden',
        'subgroup',
    ];

    public function __construct(
        private RecordService $recordService,
        private PermissionService $permissionService,
    ) {}

    #[McpTool(
        name: 'backend_group_list',
        description: 'List backend user groups (be_groups). Restricted to admin backend users.'
            . ' Optional substring "search" matches against title (LIKE %search%).'
            . ' Soft-deleted groups are always excluded.',
    )]
    public function execute(string $search = '', int $limit = 20, int $offset = 0): string
    {
        if (!$this->permissionService->isAdmin()) {
            throw new ToolCallException('Admin access required');
        }

        $conditions = [
            'deleted' => ['operator' => 'eq', 'value' => '0'],
        ];

        if ($search !== '') {
            $conditions['title'] = ['operator' => 'like', 'value' => $search];
        }

        $result = $this->recordService->search(
            'be_groups',
            $conditions,
            $limit,
            $offset,
            self::SUMMARY_FIELDS,
            null,
            'title',
            'ASC',
        );

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
