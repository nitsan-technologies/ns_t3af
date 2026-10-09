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

use NITSAN\NsT3AF\Mcp\Service\WorkspacePreferenceService;
use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Where confirmed agent changes are written: the same place as the MCP server.
 *
 * An editor already working in a workspace keeps it. From Live, the choice under
 * "MCP Server > Workspace selection" (`WorkspacePreferenceService`) decides: a workspace = the
 * changes go there, Live chosen explicitly (or never chosen) = they go Live. A chosen workspace the
 * editor may not use is not replaced by another one: the caller refuses the write
 * (see {@see self::isPreferredWorkspaceUnusable()}).
 *
 * @internal
 */
final readonly class AgentWorkspaceTarget
{
    public function __construct(
        private WorkspacePreferenceService $preference,
    ) {}

    public function resolve(int $currentWorkspaceId, ?BackendUserAuthentication $user): int
    {
        if ($currentWorkspaceId > 0 || $user === null || !AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            return max(0, $currentWorkspaceId);
        }

        return self::target(
            $this->preference->getStoredForBackendUser($user),
            static fn(int $uid): bool => $user->checkWorkspace($uid) !== false,
        );
    }

    /**
     * True when the editor is in Live and the MCP workspace selection names a workspace they
     * may not use (or that no longer exists).
     */
    public function isPreferredWorkspaceUnusable(int $currentWorkspaceId, BackendUserAuthentication $user): bool
    {
        if ($currentWorkspaceId > 0 || !AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            return false;
        }

        return self::unusable(
            $this->preference->getStoredForBackendUser($user),
            static fn(int $uid): bool => $user->checkWorkspace($uid) !== false,
        );
    }

    /**
     * @param callable(int): bool $canUse
     */
    public static function target(?int $stored, callable $canUse): int
    {
        return $stored !== null && $stored > 0 && $canUse($stored) ? $stored : 0;
    }

    /**
     * @param callable(int): bool $canUse
     */
    public static function unusable(?int $stored, callable $canUse): bool
    {
        return $stored !== null && $stored > 0 && !$canUse($stored);
    }
}
