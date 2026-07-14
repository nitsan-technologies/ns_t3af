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

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Persists the MCP module workspace dropdown per backend user (be_users.uc).
 * Used when issuing tokens from the module and during OAuth authorization (e.g. Cursor remote).
 */
readonly class WorkspacePreferenceService
{
    public const UC_KEY = 'nst3af_mcp_workspace';

    public function __construct(
        private ConnectionPool $connectionPool,
        private WorkspaceListService $workspaceListService,
    ) {}

    public function getForUser(int $beUserUid): int
    {
        if ($beUserUid <= 0) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array{uc: string|null}|false $row */
        $row = $queryBuilder
            ->select('uc')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return 0;
        }

        $uc = $this->parseUserConfiguration($row['uc'] ?? '');

        return $this->normalizeWorkspaceId((int) ($uc[self::UC_KEY] ?? 0));
    }

    public function getForCurrentUser(): int
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return 0;
        }

        $fromSession = (int) ($user->uc[self::UC_KEY] ?? 0);
        if ($fromSession > 0) {
            return $this->normalizeWorkspaceId($fromSession);
        }

        return $this->getForUser((int) ($user->user['uid'] ?? 0));
    }

    public function saveForCurrentUser(int $workspaceId): int
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new \RuntimeException('Access denied', 1712300001);
        }

        $workspaceId = $this->normalizeWorkspaceId($workspaceId);
        $user->uc[self::UC_KEY] = $workspaceId;
        $user->writeUC();

        return $workspaceId;
    }

    private function normalizeWorkspaceId(int $workspaceId): int
    {
        if ($workspaceId === 0) {
            return 0;
        }

        foreach ($this->workspaceListService->list() as $workspace) {
            if ($workspace['uid'] === $workspaceId) {
                return $workspaceId;
            }
        }

        return 0;
    }

    /** @return array<string, mixed> */
    private function parseUserConfiguration(?string $serialized): array
    {
        if ($serialized === null || $serialized === '') {
            return [];
        }

        $uc = @unserialize($serialized, ['allowed_classes' => false]);
        if (!is_array($uc)) {
            return [];
        }

        /** @var array<string, mixed> $uc */
        return $uc;
    }
}
