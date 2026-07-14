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

use NITSAN\NsT3AF\Contract\BrandContextPromptCategoryProviderInterface;

/**
 * Resolves AI Context (brand context) profile labels per AI Prompts category for drawer UI.
 *
 * @internal
 */
final class AiPromptsBrandContextInfoService
{
    /**
     * @param iterable<BrandContextPromptCategoryProviderInterface> $categoryProviders
     */
    public function __construct(
        private readonly BrandContextResolver $brandContextResolver,
        private readonly BrandContextFeatureSettingsService $brandContextFeatureSettings,
        private readonly iterable $categoryProviders = [],
    ) {}

    /**
     * @return array<string, array{
     *   available: bool,
     *   profileName: string,
     *   featureLabel: string,
     *   isDefault: bool,
     *   brandScope: string
     * }>
     */
    public function buildByCategory(?int $pageId): array
    {
        $scopeLabels = $this->brandContextFeatureSettings->getScopeLabels();
        $result = [];

        foreach ($this->categoryProviders as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            foreach ($provider->getCategoryBrandScopes() as $categoryId => $brandScope) {
                $profile = $this->brandContextResolver->resolveForPageId(
                    $pageId,
                    $provider->getExtensionKey(),
                    $brandScope,
                );
                if ($profile === null) {
                    $result[$categoryId] = [
                        'available' => false,
                        'profileName' => '',
                        'featureLabel' => $scopeLabels[$brandScope] ?? $brandScope,
                        'isDefault' => false,
                        'brandScope' => $brandScope,
                    ];
                    continue;
                }

                $profileName = $profile->brandName !== '' ? $profile->brandName : ('Profile #' . $profile->uid);
                $result[$categoryId] = [
                    'available' => true,
                    'profileName' => $profileName,
                    'featureLabel' => $scopeLabels[$brandScope] ?? $brandScope,
                    'isDefault' => $profile->isDefault,
                    'brandScope' => $brandScope,
                ];
            }
        }

        return $result;
    }
}
