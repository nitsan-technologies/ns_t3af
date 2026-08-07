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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;
use NITSAN\NsT3AF\Service\BrandContextFeatureSettingsService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final class BrandContextFeatureSettingsServiceScopeTest extends TestCase
{
    private BrandContextFeatureSettingsService $service;

    protected function setUp(): void
    {
        $this->service = new BrandContextFeatureSettingsService(
            $this->createMock(BrandContextProfileRepositoryInterface::class),
            $this->createMock(ExtensionSettingsService::class),
            $this->createMock(LanguageServiceFactory::class),
            ProviderTestStubs::t3AiBrandContextScopeProviders(),
        );
    }

    public function testSupportsBrandContextOverrideOnAiFeatureScopes(): void
    {
        self::assertTrue($this->service->supportsScopeOverride('ns_t3ai', 'seo'));
        self::assertTrue($this->service->supportsScopeOverride('ns_t3ai', 'page'));
        self::assertTrue($this->service->supportsScopeOverride('ns_t3ai', 'content'));
    }

    public function testDoesNotSupportBrandContextOverrideOnTokenlessFeatures(): void
    {
        // Translation and media prompts carry no {brand_*} tokens.
        self::assertFalse($this->service->supportsScopeOverride('ns_t3ai', 'translation'));
        self::assertFalse($this->service->supportsScopeOverride('ns_t3ai', 't3ai-media'));
        self::assertFalse($this->service->supportsScopeOverride('ns_t3ai', 't3ai-media-dalle'));
    }

    public function testDoesNotSupportBrandContextOverrideOnFeatureToggles(): void
    {
        self::assertFalse($this->service->supportsScopeOverride('ns_t3ai', 'feature configurations'));
        self::assertFalse($this->service->supportsScopeOverride('ns_t3aa', 't3aa-feature-toggles'));
        self::assertFalse($this->service->supportsScopeOverride('ns_t3cs', 'ai engine'));
        self::assertFalse($this->service->supportsScopeOverride('ns_t3ai', ''));
    }
}
