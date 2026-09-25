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

use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use NITSAN\NsT3AF\Mcp\Service\WorkspacePreferenceService;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceProvisionService;
use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Where confirmed agent changes are written: never Live.
 *
 * Uses the same workspace as the MCP server ("MCP Server > Workspace selection", stored per
 * backend user by `WorkspacePreferenceService`). Resolution order for an editor working in Live:
 * the preferred workspace if the editor may use it; otherwise the first existing workspace the
 * editor may use (remembered as the preference); otherwise, when no workspace exists at all and
 * the editor may create one, an "MCP Workspace" is created (as in the MCP module) and remembered.
 * An editor already in a workspace keeps it. 0 means no workspace is available: the caller must
 * refuse the write (see `AgentGovernanceGuard::assertDraftApplyAllowed`).
 *
 * @internal
 */
final readonly class AgentWorkspaceTarget
{
    public function __construct(
        private WorkspacePreferenceService $preference,
        private WorkspaceListService $workspaceList,
        private WorkspaceProvisionService $provision,
    ) {}

    public function resolve(int $currentWorkspaceId, ?BackendUserAuthentication $user): int
    {
        if ($currentWorkspaceId > 0 || $user === null) {
            return max(0, $currentWorkspaceId);
        }
        if (!AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            return 0;
        }

        $preferred = $this->preference->getForUser((int) ($user->user['uid'] ?? 0));
        $canUse = static fn(int $uid): bool => $user->checkWorkspace($uid) !== false;
        $uid = self::pick(
            $preferred,
            fn(): array => array_values(array_filter(
                array_map(static fn(array $w): int => $w['uid'], $this->workspaceList->list()),
                static fn(int $u): bool => $u > 0,
            )),
            $canUse,
        );
        if ($uid > 0) {
            $this->remember($uid, $preferred);

            return $uid;
        }
        if ($this->provision->hasDraftWorkspace() || !$this->provision->canUserCreateWorkspaces($user)) {
            return 0;
        }

        try {
            $uid = $this->provision->createMcpWorkspace($user);
        } catch (\Throwable) {
            return 0;
        }
        $this->remember($uid, $preferred);

        return $uid;
    }

    /**
     * The preferred workspace if the editor may use it, otherwise the first workspace the
     * editor may use; 0 = none.
     *
     * @param callable(): list<int> $candidates
     * @param callable(int): bool $canUse
     */
    public static function pick(int $preferred, callable $candidates, callable $canUse): int
    {
        if ($preferred > 0 && $canUse($preferred)) {
            return $preferred;
        }
        foreach ($candidates() as $uid) {
            if ($uid > 0 && $canUse($uid)) {
                return $uid;
            }
        }

        return 0;
    }

    private function remember(int $uid, int $previous): void
    {
        if ($uid === $previous) {
            return;
        }
        try {
            $this->preference->saveForCurrentUser($uid);
        } catch (\Throwable) {
            // Not fatal: the choice is only remembered for the MCP module.
        }
    }
}
