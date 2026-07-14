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

namespace NITSAN\NsT3AF\Access\Dto;

/**
 * Runtime tab/card → feature bit bindings for a child extension backend module.
 */
final readonly class FeatureAccessBindingsDescriptor
{
    /**
     * @param list<string> $alwaysOpenTabs Lowercase tab identifiers (no feature gate).
     * @param array<string, string|null> $tabFeatureMap Lowercase tab => perm base (null = open when module granted).
     * @param array<string, BulkTabBinding> $bulkTabBindings Lowercase bulk tab id => rule.
     * @param array<string, string> $suiteTabFeatureMap Case-sensitive tab id => perm base (T3CS).
     * @param list<CardFeatureRule> $cardFeatureRules Card key substring rules (T3AA).
     * @param array<string, list<string>> $recordAreaCatalogIds UI area => catalog row ids.
     * @param list<string> $manageableBaseFeatures Bases granted via *.Manage sibling (T3AI).
     * @param list<string> $manageableFullFeatures Full bits granted via *.Manage sibling (T3AA).
     * @param array<string, list<string>> $alternateTabFeatures Tab id => extra perm bases that also grant access.
     * @param array<string, list<string>> $legacyPermissionFallbacks permBit => list of legacy custom_options for fallback.
     * @param list<string> $moduleGrantedTabs Lowercase tabs grantable via moduleGroupMod prefix (T3AI core tabs).
     * @param bool $grantDashboardViaModuleGroup Whether the dashboard tab is granted when moduleGroupMod module is active (T3AA).
     * @param bool $openWhenNoFeatureBits Whether all tabs are open when no suite feature bits are set (T3CS).
     * @param string $featureBitPrefix Prefix used to detect suite feature bits, e.g. 'T3CS.' (T3CS).
     * @param string|null $suiteBaseFeature Base feature emitted when the child module is enabled, e.g. 'T3AA' / 'T3CS'.
     * @param array<string, list<string>> $legacyDeserializerAliases Legacy perm base => wizard feature ids.
     * @param list<array{featureId: string, recordId: string, requiresBulkOps?: bool}> $featureRecordDefaults
     * @param bool $grantsCapabilities Whether enabling the module emits nst3af:capability_* options.
     */
    public function __construct(
        public string $moduleKey,
        public string $legacyCardPermPrefix,
        public ?string $moduleGroupMod = null,
        public ?string $defaultTabFeature = null,
        public array $alwaysOpenTabs = [],
        public array $tabFeatureMap = [],
        public array $bulkTabBindings = [],
        public array $suiteTabFeatureMap = [],
        public array $cardFeatureRules = [],
        public array $recordAreaCatalogIds = [],
        public array $manageableBaseFeatures = [],
        public array $manageableFullFeatures = [],
        public array $alternateTabFeatures = [],
        public array $legacyPermissionFallbacks = [],
        public array $moduleGrantedTabs = [],
        public bool $grantDashboardViaModuleGroup = false,
        public bool $openWhenNoFeatureBits = false,
        public string $featureBitPrefix = '',
        public ?string $suiteBaseFeature = null,
        public array $legacyDeserializerAliases = [],
        public array $featureRecordDefaults = [],
        public bool $grantsCapabilities = false,
    ) {}
}
