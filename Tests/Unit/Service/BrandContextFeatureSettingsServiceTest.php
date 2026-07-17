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

final class BrandContextFeatureSettingsServiceTest extends TestCase
{
    public function testPerScopeOverrideWinsOverLegacyValue(): void
    {
        $service = $this->makeService([
            'brandContextProfileUid' => '2',
            'brandContextProfileUid_seo' => '7',
        ]);

        self::assertSame(7, $service->resolveProfileUid(10, 'ns_t3ai', 'seo'));
    }

    public function testFallsBackToLegacyValueWhenScopeUnset(): void
    {
        $service = $this->makeService([
            'brandContextProfileUid' => '2',
            'brandContextProfileUid_seo' => '7',
        ]);

        self::assertSame(2, $service->resolveProfileUid(10, 'ns_t3ai', 'page'));
    }

    public function testEmptyScopeReadsLegacyValueOnly(): void
    {
        $service = $this->makeService([
            'brandContextProfileUid' => '2',
            'brandContextProfileUid_seo' => '7',
        ]);

        self::assertSame(2, $service->resolveProfileUid(10, 'ns_t3ai', ''));
    }

    public function testUnsupportedScopeFallsBackToLegacyValue(): void
    {
        $service = $this->makeService([
            'brandContextProfileUid' => '2',
            'brandContextProfileUid_translation' => '9',
        ]);

        // 'translation' is no longer a supported brand-context scope → normalises away → legacy.
        self::assertSame(2, $service->resolveProfileUid(10, 'ns_t3ai', 'translation'));
    }

    public function testResolveAllScopeLinksReturnsOnlyConfiguredScopes(): void
    {
        $service = $this->makeService([
            'brandContextProfileUid_seo' => '7',
            'brandContextProfileUid_content' => '3',
        ]);

        self::assertSame(
            ['seo' => 7, 'content' => 3],
            $service->resolveAllScopeLinks(10, 'ns_t3ai'),
        );
    }

    /**
     * @param array<string, string> $stored
     */
    private function makeService(array $stored): BrandContextFeatureSettingsService
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getStoredValues')->willReturn($stored);

        return new BrandContextFeatureSettingsService(
            $this->createMock(BrandContextProfileRepositoryInterface::class),
            $extensionSettings,
            $this->createMock(LanguageServiceFactory::class),
            ProviderTestStubs::t3AiBrandContextScopeProviders(),
        );
    }
}
