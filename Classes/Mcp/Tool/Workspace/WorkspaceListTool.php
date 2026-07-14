<?php

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

namespace NITSAN\NsT3AF\Mcp\Tool\Workspace;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;

readonly class WorkspaceListTool implements McpNonAiToolInterface
{
    public function __construct(
        private WorkspaceListService $workspaceListService,
    ) {}

    #[McpTool(
        name: 'workspace_list',
        description: 'List workspaces available to the current backend user (Live = uid 0, plus draft workspaces from sys_workspace). Use before write operations to ask the user which workspace content should be created in; pass the chosen uid as workspaceId on write tools.',
    )]
    public function execute(): string
    {
        return json_encode([
            'workspaces' => $this->workspaceListService->list(),
            'workspacesEnabled' => $this->workspaceListService->isWorkspacesExtensionLoaded(),
        ], JSON_THROW_ON_ERROR);
    }
}
