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

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Where confirmed agent changes are written.
 *
 * `agentWorkspaceMode = draft` (default): an editor working in Live writes into a draft
 * workspace (`agentDraftWorkspaceUid`, or the first workspace the editor may use), so
 * nothing goes live before it is reviewed and published. An editor already in a workspace
 * keeps it. Without the workspaces extension or an accessible workspace, changes stay Live.
 *
 * `agentWorkspaceMode = current`: the editor's current workspace, Live included.
 *
 * @internal
 */
final readonly class AgentWorkspaceTarget
{
    public const MODE_DRAFT = 'draft';
    public const MODE_CURRENT = 'current';

    public function __construct(
        private AgentSettingsService $settings,
        private ConnectionPool $connectionPool,
    ) {}

    public function resolve(int $currentWorkspaceId, ?BackendUserAuthentication $user): int
    {
        if ($currentWorkspaceId > 0 || $user === null || $this->settings->getWorkspaceMode() !== self::MODE_DRAFT) {
            return max(0, $currentWorkspaceId);
        }
        if (!AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            return 0;
        }

        return self::pick(
            $this->settings->getDraftWorkspaceUid(),
            fn(): array => $this->workspaceUids(),
            static fn(int $uid): bool => $user->checkWorkspace($uid) !== false,
        );
    }

    /**
     * The configured draft workspace if the editor may use it, otherwise (when none is
     * configured) the first workspace the editor may use; 0 = Live.
     *
     * @param callable(): list<int> $candidates
     * @param callable(int): bool $canUse
     */
    public static function pick(int $configured, callable $candidates, callable $canUse): int
    {
        if ($configured > 0) {
            return $canUse($configured) ? $configured : 0;
        }
        foreach ($candidates() as $uid) {
            if ($uid > 0 && $canUse($uid)) {
                return $uid;
            }
        }

        return 0;
    }

    /**
     * @return list<int>
     */
    private function workspaceUids(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
        $rows = $queryBuilder
            ->select('uid')
            ->from('sys_workspace')
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter(array_map('intval', $rows), static fn(int $uid): bool => $uid > 0));
    }
}
