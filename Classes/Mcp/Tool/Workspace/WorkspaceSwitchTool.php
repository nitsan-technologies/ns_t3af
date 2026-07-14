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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

readonly class WorkspaceSwitchTool implements McpNonAiToolInterface
{
    public function __construct() {}

    #[McpTool(
        name: 'workspace_switch',
        description: 'Switch the active workspace for the current backend user. Persists to be_users.workspace_id.'
            . ' Subsequent record reads/writes will use the new workspace context. Use 0 for the live workspace.'
            . ' Requires cms-workspaces extension.',
    )]
    public function execute(int $workspaceId): string
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return json_encode(['error' => 'cms-workspaces extension is not installed'], JSON_THROW_ON_ERROR);
        }

        $beUser = $this->requireBackendUser();
        $access = $beUser->checkWorkspace($workspaceId);
        if ($access === false) {
            return json_encode(['error' => 'Workspace not accessible to current user', 'workspaceId' => $workspaceId], JSON_THROW_ON_ERROR);
        }

        $beUser->setWorkspace($workspaceId);

        return json_encode(['workspaceId' => $workspaceId, 'switched' => true], JSON_THROW_ON_ERROR);
    }

    private function requireBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No authenticated backend user available', 1714000020);
        }

        return $backendUser;
    }
}
