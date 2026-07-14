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

use Doctrine\DBAL\ParameterType;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

readonly class WorkspaceChangesListTool implements McpNonAiToolInterface
{
    public function __construct(private ConnectionPool $connectionPool) {}

    #[McpTool(
        name: 'workspace_changes_list',
        description: 'List records modified in the current workspace, grouped by table.'
            . ' Optional table filter restricts to a single workspace-aware table.'
            . ' Each record includes uid (workspace version), liveUid (t3ver_oid, 0 for new placeholders),'
            . ' state (default, new, deletePlaceholder, movePointer), and stage (-10/-20/0/custom).'
            . ' Requires cms-workspaces extension.',
    )]
    public function execute(string $table = '', int $limit = 100): string
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return json_encode(['error' => 'cms-workspaces extension is not installed'], JSON_THROW_ON_ERROR);
        }

        $beUser = $this->requireBackendUser();
        $workspaceId = (int) $beUser->workspace;

        if ($workspaceId === 0) {
            return json_encode(['workspaceId' => 0, 'tables' => []], JSON_THROW_ON_ERROR);
        }

        $tables = $this->workspaceAwareTables();
        if ($table !== '') {
            $tables = in_array($table, $tables, true) ? [$table] : [];
        }

        $limit = min(max($limit, 1), 500);
        $changes = [];

        foreach ($tables as $tableName) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll();

            $rows = $queryBuilder
                ->select('uid', 'pid', 't3ver_oid', 't3ver_state', 't3ver_stage', 't3ver_wsid')
                ->from($tableName)
                ->where(
                    $queryBuilder->expr()->eq(
                        't3ver_wsid',
                        $queryBuilder->createNamedParameter($workspaceId, ParameterType::INTEGER),
                    ),
                )
                ->setMaxResults($limit)
                ->orderBy('uid', 'DESC')
                ->executeQuery()
                ->fetchAllAssociative();

            if ($rows === []) {
                continue;
            }

            $changes[$tableName] = array_map(
                static fn(array $row): array => [
                    'uid' => (int) ($row['uid'] ?? 0),
                    'pid' => (int) ($row['pid'] ?? 0),
                    'liveUid' => (int) ($row['t3ver_oid'] ?? 0),
                    'state' => self::stateLabel((int) ($row['t3ver_state'] ?? 0)),
                    'stage' => (int) ($row['t3ver_stage'] ?? 0),
                ],
                $rows,
            );
        }

        return json_encode(['workspaceId' => $workspaceId, 'tables' => $changes], JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function workspaceAwareTables(): array
    {
        $tables = [];
        /** @var array<string, array{ctrl?: array<string, mixed>}> $tca */
        $tca = $GLOBALS['TCA'] ?? [];
        foreach ($tca as $name => $config) {
            if ((bool) ($config['ctrl']['versioningWS'] ?? false)) {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    private static function stateLabel(int $state): string
    {
        return match ($state) {
            0 => 'default',
            1 => 'new',
            2 => 'deletePlaceholder',
            4 => 'movePointer',
            default => 'unknown',
        };
    }

    private function requireBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No authenticated backend user available', 1714000021);
        }

        return $backendUser;
    }
}
