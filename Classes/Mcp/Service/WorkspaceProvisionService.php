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

namespace NITSAN\NsT3AF\Mcp\Service;

use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates a draft TYPO3 workspace for MCP staging when none exists yet.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class WorkspaceProvisionService
{
    public function __construct(private ConnectionPool $connectionPool) {}

    public function hasDraftWorkspace(): bool
    {
        if (!AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            return false;
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
            $queryBuilder->getRestrictions()->removeAll();

            $count = $queryBuilder
                ->count('uid')
                ->from('sys_workspace')
                ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchOne();

            return (int) $count > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function canUserCreateWorkspaces(BackendUserAuthentication $backendUser): bool
    {
        if ($backendUser->isAdmin()) {
            return true;
        }

        return $backendUser->check('modules', 'web_WorkspacesWorkspaces');
    }

    /**
     * @throws \RuntimeException when creation fails or user lacks permission
     */
    public function createMcpWorkspace(BackendUserAuthentication $backendUser): int
    {
        if (!AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            throw new \RuntimeException('The Workspaces extension is not loaded.', 1712200010);
        }

        if (!$this->canUserCreateWorkspaces($backendUser)) {
            throw new \RuntimeException('You do not have permission to create workspaces.', 1712200011);
        }

        if ($this->hasDraftWorkspace()) {
            throw new \RuntimeException('A draft workspace already exists.', 1712200012);
        }

        $realName = trim((string) ($backendUser->user['realName'] ?? ''));
        $username = trim((string) ($backendUser->user['username'] ?? 'unknown_user'));
        $workspaceTitle = 'MCP Workspace for ' . ($realName !== '' ? $realName : $username);
        $workspaceDescription = 'Automatically created workspace for Model Context Protocol operations from AI Foundation';

        $workspaceData = [
            'pid' => 0,
            'title' => $workspaceTitle,
            'description' => $workspaceDescription,
            'adminusers' => (int) ($backendUser->user['uid'] ?? 0),
            'members' => '',
            'db_mountpoints' => '',
            'file_mountpoints' => '',
            'publish_access' => 0,
            'stagechg_notification' => 0,
            'live_edit' => 0,
            'publish_time' => 0,
        ];

        $previousAdminFlag = (int) ($backendUser->user['admin'] ?? 0);
        $backendUser->user['admin'] = 1;

        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $newId = 'NEW' . bin2hex(random_bytes(8));
            $dataHandler->start(
                [
                    'sys_workspace' => [
                        $newId => $workspaceData,
                    ],
                ],
                [],
            );
            $dataHandler->process_datamap();

            if ($dataHandler->errorLog !== []) {
                throw new \RuntimeException(
                    'Workspace creation failed: ' . implode('; ', $dataHandler->errorLog),
                    1712200013,
                );
            }

            $newUid = $dataHandler->substNEWwithIDs[$newId] ?? null;
            if (!is_numeric($newUid) || (int) $newUid <= 0) {
                throw new \RuntimeException('Workspace creation failed: no uid returned.', 1712200014);
            }

            return (int) $newUid;
        } finally {
            $backendUser->user['admin'] = $previousAdminFlag;
        }
    }
}
