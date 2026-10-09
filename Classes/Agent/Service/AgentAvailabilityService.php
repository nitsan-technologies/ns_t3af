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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Gates AI Agent surface visibility (group permission + per-user hide preference).
 *
 * @internal
 */
final class AgentAvailabilityService
{
    private const PERMISSION = 'nst3af:agent_enabled';

    private const SESSION_HIDE_KEY = 'nst3af_agent_ui_hidden';

    public function isAvailable(?BackendUserAuthentication $user = null): bool
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $user->check('custom_options', self::PERMISSION);
    }

    public function isHiddenByUser(?BackendUserAuthentication $user = null): bool
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return true;
        }

        return (bool) ($user->getSessionData(self::SESSION_HIDE_KEY) ?? false);
    }

    public function setHiddenByUser(bool $hidden, ?BackendUserAuthentication $user = null): void
    {
        $user ??= $this->resolveBackendUser();
        if ($user === null) {
            return;
        }

        $user->setAndSaveSessionData(self::SESSION_HIDE_KEY, $hidden ? 1 : 0);
    }

    public function shouldRenderUi(?BackendUserAuthentication $user = null): bool
    {
        return $this->isAvailable($user) && !$this->isHiddenByUser($user);
    }

    private function resolveBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }
}
