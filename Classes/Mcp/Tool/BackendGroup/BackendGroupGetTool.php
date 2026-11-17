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

namespace NITSAN\NsT3AF\Mcp\Tool\BackendGroup;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\PermissionService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;

#[McpToolSeverity(ToolSeverity::Read)]
readonly class BackendGroupGetTool implements McpNonAiToolInterface
{
    /** @var list<string> */
    private const DETAIL_FIELDS = [
        'uid',
        'title',
        'description',
        'hidden',
        'deleted',
        'subgroup',
        'db_mountpoints',
        'file_mountpoints',
        'file_permissions',
        'workspace_perms',
        'pagetypes_select',
        'tables_modify',
        'tables_select',
        'non_exclude_fields',
        'explicit_allowdeny',
        'allowed_languages',
        'custom_options',
        'groupMods',
        'mfa_providers',
        'TSconfig',
    ];

    public function __construct(
        private RecordService $recordService,
        private PermissionService $permissionService,
    ) {}

    #[McpTool(
        name: 'backend_group_get',
        description: 'Get a single backend user group (be_groups) by uid. Restricted to admin backend users.'
            . ' Requires a positive be_groups uid — never use pageId or 0.'
            . ' When the editor did not name a group uid, call backend_group_list (or ask) first.'
            . ' Fails for uid ≤ 0, soft-deleted, or missing groups.',
    )]
    public function execute(int $uid): string
    {
        if (!$this->permissionService->isAdmin()) {
            throw new ToolCallException('Admin access required');
        }

        if ($uid <= 0) {
            throw new ToolCallException(
                'backend_group_get requires a positive be_groups uid.'
                . ' Call backend_group_list first or ask which group — never pass 0 or a page id.',
            );
        }

        $row = $this->recordService->findByUid('be_groups', $uid, self::DETAIL_FIELDS);

        if ($row === null || (int) ($row['deleted'] ?? 0) === 1) {
            throw new ToolCallException('Backend group not found for uid ' . $uid);
        }

        return json_encode($row, JSON_THROW_ON_ERROR);
    }
}
