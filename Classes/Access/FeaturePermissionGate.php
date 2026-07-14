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
 * Unified permission checks: legacy custom_options OR feature bits, resolved via access bindings.
 *
 * @api
 */
final class FeaturePermissionGate
{
    public function __construct(
        private readonly T3AiPermissionResolver $resolver = new T3AiPermissionResolver(),
        private readonly FeatureAccessBindingRegistry $bindings = new FeatureAccessBindingRegistry(),
    ) {}

    public function grantsLegacyOrFeature(
        BackendUserAuthentication $user,
        string $legacyKey,
        ?string $featureBit = null,
    ): bool {
        if ($user->isAdmin()) {
            return true;
        }

        if (BackendPermissionCheck::isGranted($user, 'custom_options', $legacyKey)) {
            return true;
        }

        if ($featureBit !== null && $this->resolver->hasFeature($user, $featureBit)) {
            return true;
        }

        return false;
    }

    public function grantsModuleTab(BackendUserAuthentication $user, string $moduleKey, string $tabIdentifier): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $bindings = $this->bindings->getBindings($moduleKey);
        if ($bindings === null) {
            return false;
        }

        $tab = strtolower($tabIdentifier);
        if (in_array($tab, $bindings->alwaysOpenTabs, true)) {
            return true;
        }

        if ($bindings->moduleGroupMod !== null
            && !BackendPermissionCheck::isGranted($user, 'modules', $bindings->moduleGroupMod)
            && $bindings->suiteTabFeatureMap !== []
        ) {
            return false;
        }

        if ($bindings->openWhenNoFeatureBits && !$this->hasAnyFeatureBits($user, $bindings->featureBitPrefix)) {
            return true;
        }

        $bulk = $bindings->bulkTabBindings[$tab] ?? null;
        if ($bulk !== null) {
            return $this->grantsModuleCoreTab($user, $moduleKey, $bulk->coreTab)
                && $this->grantsLegacyOrFeature($user, $bulk->legacyPermKey, $bulk->extraFeature);
        }

        if ($bindings->grantDashboardViaModuleGroup
            && $tab === 'dashboard'
            && $bindings->moduleGroupMod !== null
            && BackendPermissionCheck::isGranted($user, 'modules', $bindings->moduleGroupMod)
        ) {
            return true;
        }

        if ($bindings->suiteTabFeatureMap !== [] && $tabIdentifier === 'Dashboard') {
            $dashboardFeature = $bindings->suiteTabFeatureMap['Dashboard'] ?? $bindings->defaultTabFeature;
            if ($dashboardFeature !== null) {
                return $this->resolver->hasFeature($user, $dashboardFeature);
            }
        }

        return $this->grantsModuleCoreTab($user, $moduleKey, $tabIdentifier);
    }

    public function grantsModuleCard(
        BackendUserAuthentication $user,
        string $moduleKey,
        string $tabIdentifier,
        string $cardKey,
    ): bool {
        if ($user->isAdmin()) {
            return true;
        }

        $bindings = $this->bindings->getBindings($moduleKey);
        if ($bindings === null) {
            return false;
        }

        $tab = strtolower($tabIdentifier);
        if (isset($bindings->bulkTabBindings[$tab])) {
            if (!$this->grantsModuleTab($user, $moduleKey, $tab)) {
                return false;
            }

            return $this->grantsLegacyOrFeature(
                $user,
                $bindings->legacyCardPermPrefix . $tab . ':' . $cardKey,
                null,
            );
        }

        $feature = $this->bindings->resolveCardFeature($moduleKey, $tabIdentifier, $cardKey)
            ?? $bindings->defaultTabFeature;

        return $this->grantsLegacyOrFeature(
            $user,
            $bindings->legacyCardPermPrefix . $tab . ':' . $cardKey,
            $feature,
        );
    }

    private function grantsModuleCoreTab(
        BackendUserAuthentication $user,
        string $moduleKey,
        string $tabIdentifier,
    ): bool {
        $bindings = $this->bindings->getBindings($moduleKey);
        if ($bindings === null) {
            return false;
        }

        $tab = strtolower($tabIdentifier);
        if ($bindings->moduleGroupMod !== null && $bindings->moduleGrantedTabs !== []) {
            if (in_array($tab, $bindings->moduleGrantedTabs, true)
                && BackendPermissionCheck::isGranted($user, 'modules', $bindings->moduleGroupMod . $tab)
            ) {
                return true;
            }
        }

        $feature = $this->bindings->resolveTabFeature($moduleKey, $tabIdentifier)
            ?? $bindings->defaultTabFeature;

        if ($bindings->suiteTabFeatureMap !== [] && $feature !== null) {
            $alternates = $bindings->alternateTabFeatures[$tabIdentifier] ?? [];
            if ($alternates !== []) {
                if ($this->resolver->hasFeature($user, $feature)) {
                    return true;
                }
                foreach ($alternates as $alternate) {
                    if ($this->resolver->hasFeature($user, $alternate)) {
                        return true;
                    }
                }

                return false;
            }

            return $this->resolver->hasFeature($user, $feature);
        }

        return $this->grantsLegacyOrFeature(
            $user,
            $bindings->legacyCardPermPrefix . $tab . ':' . $tab,
            $feature,
        );
    }

    private function hasAnyFeatureBits(BackendUserAuthentication $user, string $prefix): bool
    {
        if ($prefix === '') {
            return false;
        }

        $customOptions = (string) ($user->groupData['custom_options'] ?? '');

        return str_contains($customOptions, FeaturePermissionCatalog::PERM_PREFIX . ':' . $prefix);
    }
}
