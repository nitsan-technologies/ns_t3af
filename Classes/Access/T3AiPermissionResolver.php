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

use NITSAN\NsT3AF\Registry\FeatureAccessBindingRegistry;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Checks simplified T3Ai:* permission bits with optional legacy fallback.
 */
final class T3AiPermissionResolver
{
    public function __construct(
        private readonly FeatureAccessBindingRegistry $bindings = new FeatureAccessBindingRegistry(),
    ) {}

    public function hasFeature(?BackendUserAuthentication $user, string $permBit): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        $full = FeaturePermissionCatalog::PERM_PREFIX . ':' . $permBit;
        if ($user->check('custom_options', $full)) {
            return true;
        }

        $base = explode('.', $permBit)[0];
        if (
            in_array($base, $this->bindings->manageableBaseFeatures(), true)
            && $user->check('custom_options', FeaturePermissionCatalog::PERM_PREFIX . ':' . $base . '.Manage')
        ) {
            return true;
        }

        if (
            in_array($permBit, $this->bindings->manageableFullFeatures(), true)
            && $user->check('custom_options', FeaturePermissionCatalog::PERM_PREFIX . ':' . $permBit . '.Manage')
        ) {
            return true;
        }

        return $this->legacyFallback($user, $permBit);
    }

    public function hasTabAccess(?BackendUserAuthentication $user, string $tabPermKey): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return $user->check(
            'custom_options',
            ModuleAccessCatalog::PERM_PREFIX_TAB . ':' . $tabPermKey,
        );
    }

    private function legacyFallback(BackendUserAuthentication $user, string $permBit): bool
    {
        $legacyMap = $this->bindings->legacyPermFallback();

        foreach ($legacyMap[$permBit] ?? [] as $legacy) {
            if ($user->check('custom_options', $legacy)) {
                return true;
            }
        }

        if (str_contains($permBit, '.')) {
            return false;
        }

        $base = explode('.', $permBit)[0];
        foreach ($legacyMap[$base] ?? [] as $legacy) {
            if ($user->check('custom_options', $legacy)) {
                return true;
            }
        }

        return false;
    }
}
