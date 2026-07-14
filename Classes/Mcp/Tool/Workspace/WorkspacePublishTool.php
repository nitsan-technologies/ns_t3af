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

readonly class WorkspacePublishTool implements McpNonAiToolInterface
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private WorkspaceVersionService $workspaceVersionService,
    ) {}

    #[McpTool(
        name: 'workspace_publish',
        description: 'Publish a workspace version to live (swap). Pass table and workspaceVersionUid.'
            . ' For new placeholders (t3ver_oid=0) the workspace version becomes the live record.'
            . ' Requires cms-workspaces extension.',
    )]
    public function execute(string $table, int $workspaceVersionUid): string
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return json_encode(['error' => 'cms-workspaces extension is not installed'], JSON_THROW_ON_ERROR);
        }

        $row = $this->workspaceVersionService->loadVersionRow($table, $workspaceVersionUid);
        if ($row === null) {
            return json_encode(
                ['error' => 'Workspace version not found', 'table' => $table, 'uid' => $workspaceVersionUid],
                JSON_THROW_ON_ERROR,
            );
        }

        $liveUid = (int) ($row['t3ver_oid'] ?? 0);
        $cmdKey = $liveUid > 0 ? $liveUid : $workspaceVersionUid;

        $this->dataHandlerService->processCommand([
            $table => [
                $cmdKey => [
                    'version' => [
                        'action' => 'swap',
                        'swapWith' => $workspaceVersionUid,
                    ],
                ],
            ],
        ]);

        return json_encode(['published' => true, 'table' => $table, 'liveUid' => $cmdKey], JSON_THROW_ON_ERROR);
    }
}
