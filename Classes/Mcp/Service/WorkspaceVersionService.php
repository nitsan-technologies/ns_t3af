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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * @internal
 */
readonly class WorkspaceVersionService
{
    public function __construct(private ConnectionPool $connectionPool) {}

    public function requireBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No authenticated backend user available', 1714000030);
        }

        return $backendUser;
    }

    /** @return array<string, mixed>|null */
    public function loadVersionRow(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('uid', 'pid', 't3ver_oid', 't3ver_state', 't3ver_stage', 't3ver_wsid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    public function assertStageExistsForWorkspace(int $stageId, int $workspaceId): void
    {
        if ($stageId === -20) {
            throw new \InvalidArgumentException(
                'Stage -20 is publish-now in the TYPO3 backend and is not stored on the record. Use workspace_publish instead.',
            );
        }

        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            throw new \InvalidArgumentException('cms-workspaces extension is not installed.');
        }

        if (in_array($stageId, [0, -10], true)) {
            return;
        }

        if ($stageId < 0) {
            throw new \InvalidArgumentException('stage must be 0, -10, or a positive sys_workspace_stage uid.');
        }

        $stageRow = BackendUtility::getRecord('sys_workspace_stage', $stageId);
        if (!is_array($stageRow)) {
            throw new \InvalidArgumentException('Workspace stage ' . $stageId . ' does not exist.');
        }

        $stageWorkspaceId = (int) ($stageRow['parentid'] ?? 0);
        if ($workspaceId > 0 && $stageWorkspaceId !== $workspaceId) {
            throw new \InvalidArgumentException(
                'Workspace stage ' . $stageId . ' does not belong to workspace ' . $workspaceId . '.',
            );
        }
    }

    public function assertUserMaySetStage(BackendUserAuthentication $backendUser, int $stageId): void
    {
        if ($backendUser->isAdmin()) {
            return;
        }

        if (!$backendUser->workspaceCheckStageForCurrent($stageId)) {
            throw new \InvalidArgumentException('Stage not accessible to current user.');
        }
    }
}
