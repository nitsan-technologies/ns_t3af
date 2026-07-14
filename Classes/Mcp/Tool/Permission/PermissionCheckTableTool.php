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

readonly class PermissionCheckTableTool implements McpNonAiToolInterface
{
    public function __construct(private PermissionService $permissionService) {}

    #[McpTool(
        name: 'permission_check_table',
        description: 'Check if the current user can read (select) and/or write (modify) a specific database table.'
            . ' Use this before record operations to verify access.',
    )]
    public function execute(string $tableName): string
    {
        return json_encode($this->permissionService->checkTableAccess($tableName), JSON_THROW_ON_ERROR);
    }
}
