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
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class WorkspaceListService
{
    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * @return list<array{uid: int, title: string}>
     */
    public function list(): array
    {
        $workspaces = [
            ['uid' => 0, 'title' => $this->translateLiveTitle()],
        ];

        if (!AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            return $workspaces;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from('sys_workspace')
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->orderBy('title', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $workspaces[] = [
                'uid' => (int) ($row['uid'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
            ];
        }

        return $workspaces;
    }

    public function isWorkspacesExtensionLoaded(): bool
    {
        return AiUniverseUtilityHelper::isExtensionLoaded('workspaces');
    }

    public function resolveTitle(int $workspaceId): string
    {
        if ($workspaceId === 0) {
            return $this->translateLiveTitle();
        }

        foreach ($this->list() as $workspace) {
            if ($workspace['uid'] === $workspaceId) {
                return $workspace['title'];
            }
        }

        return 'Workspace #' . $workspaceId;
    }

    private function translateLiveTitle(): string
    {
        return (string) ($GLOBALS['LANG']?->sL(
            'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod.xlf:module.mcpServer.workspace.live',
        ) ?? 'Live');
    }
}
