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

readonly class BackendUserGetTool implements McpNonAiToolInterface
{
    /** @var list<string> */
    private const DETAIL_FIELDS = [
        'uid',
        'username',
        'realName',
        'email',
        'admin',
        'disable',
        'deleted',
        'starttime',
        'endtime',
        'lastlogin',
        'usergroup',
        'lang',
        'description',
        'db_mountpoints',
        'file_mountpoints',
        'file_permissions',
        'workspace_perms',
        'options',
        'userMods',
        'allowed_languages',
        'TSconfig',
        'category_perms',
    ];

    public function __construct(
        private RecordService $recordService,
        private PermissionService $permissionService,
    ) {}

    #[McpTool(
        name: 'backend_user_get',
        description: 'Get a single backend user (be_users) by uid. Restricted to admin backend users.'
            . ' Returns an error for soft-deleted or missing users.'
            . ' Sensitive fields (password, mfa) are never returned.',
    )]
    public function execute(int $uid): string
    {
        if (!$this->permissionService->isAdmin()) {
            throw new ToolCallException('Admin access required');
        }

        $row = $this->recordService->findByUid('be_users', $uid, self::DETAIL_FIELDS);

        if ($row === null || (int) ($row['deleted'] ?? 0) === 1) {
            return json_encode(['error' => 'Backend user not found', 'uid' => $uid], JSON_THROW_ON_ERROR);
        }

        return json_encode($row, JSON_THROW_ON_ERROR);
    }
}
