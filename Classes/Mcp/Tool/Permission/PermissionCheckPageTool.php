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

namespace NITSAN\NsT3AF\Mcp\Tool\Permission;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\PermissionService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;

readonly class PermissionCheckPageTool implements McpNonAiToolInterface
{
    public function __construct(
        private PermissionService $permissionService,
        private RecordService $recordService,
    ) {}

    #[McpTool(
        name: 'permission_check_page',
        description: 'Check what the current user can do on a specific page: show, edit, delete, create subpages,'
            . ' and edit content. Use this before page or content operations.',
    )]
    public function execute(int $pageId): string
    {
        $pageRow = $this->recordService->findByUid(
            'pages',
            $pageId,
            ['uid', 'pid', 'perms_userid', 'perms_user', 'perms_groupid', 'perms_group', 'perms_everybody'],
        );

        if ($pageRow === null) {
            return json_encode(['error' => 'Page not found', 'pageId' => $pageId], JSON_THROW_ON_ERROR);
        }

        return json_encode($this->permissionService->checkPageAccess($pageRow), JSON_THROW_ON_ERROR);
    }
}
