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

use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;
use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;
use NITSAN\NsT3AF\Service\AiPromptsBrandContextInfoService;
use NITSAN\NsT3AF\Service\BrandContextFeatureSettingsService;
use NITSAN\NsT3AF\Service\BrandContextProfileOverrideReaderInterface;
use NITSAN\NsT3AF\Service\BrandContextResolver;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class AiPromptsBrandContextInfoServiceTest extends TestCase
{
    public function testBuildByCategoryResolvesProfilePerScope(): void
    {
        $seoProfile = BrandContextProfile::fromRow([
            'uid' => 5,
            'pid' => 10,
            'brand_name' => 'T3Planet',
            'is_default' => 1,
        ]);
        $contentProfile = BrandContextProfile::fromRow([
            'uid' => 6,
            'pid' => 10,
            'brand_name' => 'Content Brand',
            'is_default' => 0,
        ]);

        $profiles = $this->createMock(BrandContextProfileRepositoryInterface::class);
        $profiles->method('findByUid')->willReturnCallback(static fn(int $uid): ?BrandContextProfile => match ($uid) {
            5 => $seoProfile,
            6 => $contentProfile,
            default => null,
        });
        $profiles->method('belongsToStorage')->willReturn(true);
        $profiles->method('findDefault')->willReturn($seoProfile);

        $overrideReader = $this->createMock(BrandContextProfileOverrideReaderInterface::class);
        $overrideReader->method('resolveProfileUid')->willReturnCallback(
            static fn(int $storagePid, string $extensionKey, string $scope): int => match ($scope) {
                'seo' => 5,
                'content' => 6,
                default => 0,
            },
        );

        $site = $this->createMock(Site::class);
        $site->method('getRootPageId')->willReturn(10);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->with(42)->willReturn($site);

        $resolver = new BrandContextResolver(
            $profiles,
            new SiteStorageContext($siteFinder),
            $overrideReader,
        );

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(static function (string $key): string {
            return match (true) {
                str_ends_with($key, 'seo') => 'AI SEO',
                str_ends_with($key, 'page') => 'AI Pages',
                str_ends_with($key, 'content') => 'AI Content',
                default => $key,
            };
        });
        $languageServiceFactory = $this->createMock(LanguageServiceFactory::class);
        $languageServiceFactory->method('createFromUserPreferences')->willReturn($languageService);

        $featureSettings = new BrandContextFeatureSettingsService(
            $profiles,
            $this->createMock(\NITSAN\NsT3AF\Settings\ExtensionSettingsService::class),
            $languageServiceFactory,
            ProviderTestStubs::t3AiBrandContextScopeProviders(),
        );

        $service = new AiPromptsBrandContextInfoService(
            $resolver,
            $featureSettings,
            ProviderTestStubs::t3AiPromptCategoryProviders(),
        );
        $result = $service->buildByCategory(42);

        self::assertTrue($result['seo']['available']);
        self::assertSame('T3Planet', $result['seo']['profileName']);
        self::assertSame('AI SEO', $result['seo']['featureLabel']);
        self::assertTrue($result['seo']['isDefault']);

        self::assertTrue($result['pages']['available']);
        self::assertSame('T3Planet', $result['pages']['profileName']);
        self::assertSame('AI Pages', $result['pages']['featureLabel']);

        self::assertTrue($result['content']['available']);
        self::assertSame('Content Brand', $result['content']['profileName']);
    }
}
