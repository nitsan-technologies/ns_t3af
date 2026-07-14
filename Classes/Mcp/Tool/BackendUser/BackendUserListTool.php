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

namespace NITSAN\NsT3AF\Mcp\Tool\BackendUser;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\PermissionService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;

readonly class BackendUserListTool implements McpNonAiToolInterface
{
    /** @var list<string> */
    private const SUMMARY_FIELDS = [
        'uid',
        'username',
        'realName',
        'email',
        'admin',
        'disable',
        'starttime',
        'endtime',
        'lastlogin',
    ];

    public function __construct(
        private RecordService $recordService,
        private PermissionService $permissionService,
    ) {}

    #[McpTool(
        name: 'backend_user_list',
        description: 'List backend users (be_users). Restricted to admin backend users.'
            . ' Optional substring "search" matches against username (LIKE %search%).'
            . ' "activeOnly" excludes disabled accounts; "adminOnly" returns only admins.'
            . ' Soft-deleted accounts are always excluded. Sensitive fields (password, mfa) are never returned.',
    )]
    public function execute(
        string $search = '',
        bool $activeOnly = false,
        bool $adminOnly = false,
        int $limit = 20,
        int $offset = 0,
    ): string {
        if (!$this->permissionService->isAdmin()) {
            throw new ToolCallException('Admin access required');
        }

        $conditions = [
            'deleted' => ['operator' => 'eq', 'value' => '0'],
        ];

        if ($search !== '') {
            $conditions['username'] = ['operator' => 'like', 'value' => $search];
        }
        if ($activeOnly) {
            $conditions['disable'] = ['operator' => 'eq', 'value' => '0'];
        }
        if ($adminOnly) {
            $conditions['admin'] = ['operator' => 'eq', 'value' => '1'];
        }

        $result = $this->recordService->search(
            'be_users',
            $conditions,
            $limit,
            $offset,
            self::SUMMARY_FIELDS,
            null,
            'username',
            'ASC',
        );

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
