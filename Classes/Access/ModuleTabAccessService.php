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

namespace NITSAN\NsT3AF\Access;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Gates AI Foundation module sub-tab visibility via custom_options nst3af_tab:*.
 *
 * When a backend user's merged group permissions contain no nst3af_tab entries,
 * navigation stays permissive (legacy behaviour). Admins always see every tab.
 */
final class ModuleTabAccessService
{
    private const PERM_PREFIX = ModuleAccessCatalog::PERM_PREFIX_TAB . ':';

    /** @var array<string, string> ModuleTabUtility key => ext_localconf perm item key */
    private const TAB_PERMISSIONS = [
        'providers' => 'providers',
        'aiContext' => 'ai_context',
        'mcpServer' => 'mcp_server',
        'mcpTools' => 'mcp_tools',
        'aiFeatures' => 'ai_features',
        'aiPrompts' => 'ai_prompts',
        'schedulerCli' => 'scheduler_cli',
        'aiUsage' => 'ai_usage',
        'aiLogs' => 'ai_logs',
    ];

    public function isTabVisible(string $tabKey, ?BackendUserAuthentication $user): bool
    {
        if ($tabKey === 'dashboard') {
            return true;
        }

        if ($tabKey === 'aiAccessRoles') {
            return $user?->isAdmin() ?? false;
        }

        if ($user === null) {
            return true;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $permKey = self::TAB_PERMISSIONS[$tabKey] ?? null;
        if ($permKey === null) {
            return true;
        }

        if (!$this->isTabGatingActive($user)) {
            return true;
        }

        return $user->check('custom_options', self::PERM_PREFIX . $permKey);
    }

    private function isTabGatingActive(BackendUserAuthentication $user): bool
    {
        $customOptions = (string) ($user->groupData['custom_options'] ?? '');

        return str_contains($customOptions, self::PERM_PREFIX);
    }
}
