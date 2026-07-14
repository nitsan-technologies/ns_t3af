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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Access\ExtensionAvailability;
use NITSAN\NsT3AF\Access\ModuleAccessCatalog;
use NITSAN\NsT3AF\Contract\AiFeatureCardDescriptor;
use NITSAN\NsT3AF\Registry\AiFeatureCardProviderRegistry;
use NITSAN\NsT3AF\Registry\WizardSuiteBadgeProviderRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsSchemaService;
use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;

/**
 * Builds Quick Setup wizard step 5 catalog from wizard-eligible AI feature cards.
 *
 * @internal
 */
final class WizardExtensionCatalogService
{
    public function __construct(
        private readonly AiFeatureCardProviderRegistry $featureCardProviderRegistry,
        private readonly ModuleAccessCatalog $moduleAccessCatalog,
        private readonly ExtensionSettingsRegistry $extensionSettingsRegistry,
        private readonly ExtensionSettingsSchemaService $extensionSettingsSchemaService,
        private readonly WizardFeatureToggleService $wizardFeatureToggleService,
        private readonly WizardSuiteBadgeProviderRegistry $suiteBadgeProviderRegistry,
        private readonly ExtensionAvailability $extensionAvailability = new ExtensionAvailability(),
    ) {}

    public function hasEligibleExtensions(): bool
    {
        return $this->buildCatalog()['hasToggles'] ?? false;
    }

    /**
     * @return array{
     *   hasToggles: bool,
     *   groups: list<array<string, mixed>>,
     *   defaults: array<string, array<string, bool>>
     * }
     */
    public function buildCatalog(): array
    {
        $moduleMetaByExtension = $this->moduleMetaByExtensionKey();
        $cardsByExtension = $this->collectWizardCardsByExtension();

        $groups = [];
        $defaults = [];
        $hasToggles = false;

        foreach ($moduleMetaByExtension as $extensionKey => $meta) {
            $moduleKey = (string) ($meta['moduleKey'] ?? '');
            if ($extensionKey === '' || !$this->extensionAvailability->isLoaded($extensionKey)) {
                continue;
            }

            $cards = $cardsByExtension[$extensionKey] ?? [];
            $currentValues = AiUniverseUtilityHelper::getExtensionConf($extensionKey);
            $schemaDefaults = $this->extensionSettingsRegistry->isManaged($extensionKey)
                ? $this->extensionSettingsSchemaService->getDefaults($extensionKey)
                : [];

            $featureCards = [];

            foreach ($cards as $descriptor) {
                if (!$descriptor->wizardEligible || $descriptor->wizardGroup !== 'feature') {
                    continue;
                }
                $toggleField = $descriptor->wizardToggleField;
                if ($toggleField === null || $toggleField === '') {
                    continue;
                }

                $default = $this->wizardFeatureToggleService->resolveMasterToggleDefault(
                    $extensionKey,
                    $toggleField,
                    $descriptor->wizardToggleChildFields,
                    $currentValues,
                    $schemaDefaults,
                );
                $defaults[$extensionKey][$toggleField] = $default;
                $hasToggles = true;

                $featureCards[] = [
                    'id' => $descriptor->id,
                    'name' => $descriptor->name,
                    'subtitle' => $descriptor->subtitle,
                    'icon' => $descriptor->icon,
                    'toggleField' => $toggleField,
                    'default' => $default,
                    'description' => $descriptor->description,
                ];
            }

            $suiteBadges = $moduleKey !== ''
                ? $this->suiteBadgeProviderRegistry->getSuiteBadgeLabels($moduleKey)
                : [];

            if ($featureCards === [] && $suiteBadges === []) {
                continue;
            }

            $groups[] = [
                'moduleKey' => $moduleKey,
                'extensionKey' => $extensionKey,
                'label' => (string) ($meta['label'] ?? $extensionKey),
                'sublabel' => (string) ($meta['sublabel'] ?? ''),
                'color' => (string) ($meta['color'] ?? '#64748b'),
                'features' => $featureCards,
                'suiteBadges' => $suiteBadges,
                'informational' => $featureCards === [],
            ];
        }

        usort(
            $groups,
            static fn(array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']),
        );

        return [
            'hasToggles' => $hasToggles,
            'groups' => $groups,
            'defaults' => $defaults,
        ];
    }

    /**
     * @return array<string, list<AiFeatureCardDescriptor>>
     */
    private function collectWizardCardsByExtension(): array
    {
        $byExtension = [];
        foreach ($this->featureCardProviderRegistry->getAvailableProviders() as $provider) {
            $extensionKey = $provider->getExtensionKey();
            foreach ($provider->getFeatureCards() as $descriptor) {
                if (!$descriptor->wizardEligible) {
                    continue;
                }
                $byExtension[$extensionKey][] = $descriptor;
            }
        }

        foreach ($byExtension as &$cards) {
            usort(
                $cards,
                static fn(AiFeatureCardDescriptor $a, AiFeatureCardDescriptor $b): int => $a->sortPriority <=> $b->sortPriority,
            );
        }
        unset($cards);

        return $byExtension;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function moduleMetaByExtensionKey(): array
    {
        $byExtension = [];
        foreach ($this->moduleAccessCatalog->childModules() as $moduleKey => $meta) {
            $extensionKey = (string) ($meta['extension'] ?? '');
            if ($extensionKey !== '') {
                $byExtension[$extensionKey] = $meta + ['moduleKey' => $moduleKey];
            }
        }

        return $byExtension;
    }
}
