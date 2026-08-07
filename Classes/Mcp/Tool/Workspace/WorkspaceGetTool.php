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
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceVersionService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

readonly class WorkspaceGetTool implements McpNonAiToolInterface
{
    /** @var list<string> */
    private const WORKSPACE_FIELDS = [
        'uid',
        'title',
        'adminusers',
        'members',
        'db_mountpoints',
        'file_mountpoints',
        'live_edit',
        'custom_stages',
        'publish_access',
    ];

    public function __construct(
        private RecordService $recordService,
        private WorkspaceVersionService $workspaceVersionService,
    ) {}

    #[McpTool(
        name: 'workspace_get',
        description: 'Get workspace metadata by uid. Returns title, custom_stages flag, and current user access level.'
            . ' Use uid 0 for the live workspace. Requires cms-workspaces extension.',
    )]
    public function execute(int $workspaceId): string
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return json_encode(['error' => 'cms-workspaces extension is not installed'], JSON_THROW_ON_ERROR);
        }

        $beUser = $this->workspaceVersionService->requireBackendUser();
        $access = $beUser->checkWorkspace($workspaceId);
        if ($access === false) {
            return json_encode(['error' => 'Workspace not accessible to current user'], JSON_THROW_ON_ERROR);
        }

        $accessLabel = (string) ($access['_ACCESS'] ?? '');

        if ($workspaceId === 0) {
            return json_encode([
                'uid' => 0,
                'title' => 'Live workspace',
                'access' => $accessLabel !== '' ? $accessLabel : 'online',
            ], JSON_THROW_ON_ERROR);
        }

        $record = $this->recordService->findByUid('sys_workspace', $workspaceId, self::WORKSPACE_FIELDS);
        if ($record === null) {
            return json_encode(['error' => 'Workspace not found'], JSON_THROW_ON_ERROR);
        }

        $record['access'] = $accessLabel;

        return json_encode($record, JSON_THROW_ON_ERROR);
    }
}
