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

namespace NITSAN\NsT3AF\Mcp\Tool\Workspace;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceVersionService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

readonly class WorkspaceStageSetTool implements McpNonAiToolInterface
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private WorkspaceVersionService $workspaceVersionService,
    ) {}

    #[McpTool(
        name: 'workspace_stage_set',
        description: 'Move a workspace version to a different stage. Pass table, workspaceVersionUid, and stage.'
            . ' Built-in stages: 0 = editing, -10 = ready to publish, -20 = ready to review.'
            . ' Custom stage uids (>0) reference sys_workspace_stage. Requires cms-workspaces extension.',
    )]
    public function execute(string $table, int $workspaceVersionUid, int $stage): string
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return json_encode(['error' => 'cms-workspaces extension is not installed'], JSON_THROW_ON_ERROR);
        }

        $beUser = $this->workspaceVersionService->requireBackendUser();
        if (!$beUser->workspaceCheckStageForCurrent($stage)) {
            return json_encode(['error' => 'Stage not accessible to current user', 'stage' => $stage], JSON_THROW_ON_ERROR);
        }

        $row = $this->workspaceVersionService->loadVersionRow($table, $workspaceVersionUid);
        if ($row === null) {
            return json_encode(
                ['error' => 'Workspace version not found', 'table' => $table, 'uid' => $workspaceVersionUid],
                JSON_THROW_ON_ERROR,
            );
        }

        $this->dataHandlerService->updateRecord($table, $workspaceVersionUid, ['t3ver_stage' => $stage]);

        return json_encode(
            ['updated' => true, 'table' => $table, 'uid' => $workspaceVersionUid, 'stage' => $stage],
            JSON_THROW_ON_ERROR,
        );
    }
}
