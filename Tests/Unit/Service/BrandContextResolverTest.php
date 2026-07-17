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
use NITSAN\NsT3AF\Service\BrandContextProfileOverrideReaderInterface;
use NITSAN\NsT3AF\Service\BrandContextResolver;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class BrandContextResolverTest extends TestCase
{
    public function testResolveDefaultForPageIdUsesSiteDefaultProfile(): void
    {
        $default = $this->makeProfile(uid: 1, brandName: 'Default Brand');
        $resolver = $this->makeResolver(storagePid: 10, pageId: 42, defaultProfile: $default);

        $result = $resolver->resolveDefaultForPageId(42);

        self::assertNotNull($result);
        self::assertSame('Default Brand', $result->brandName);
    }

    public function testResolveForPageIdUsesExtensionOverrideWhenConfigured(): void
    {
        $default = $this->makeProfile(uid: 1, brandName: 'Default Brand');
        $override = $this->makeProfile(uid: 5, brandName: 'T3AI Override');

        $profiles = $this->createMock(BrandContextProfileRepositoryInterface::class);
        $profiles->method('findByUid')->with(5)->willReturn($override);
        $profiles->method('belongsToStorage')->with(5, 10)->willReturn(true);
        $profiles->expects(self::never())->method('findDefault');

        $featureSettings = $this->createMock(BrandContextProfileOverrideReaderInterface::class);
        $featureSettings->method('resolveProfileUid')->with(10, 'ns_t3ai', 'seo')->willReturn(5);

        $resolver = $this->makeResolverWithMocks(10, 42, $profiles, $featureSettings);

        $result = $resolver->resolveForPageId(42, 'ns_t3ai', 'seo');

        self::assertNotNull($result);
        self::assertSame('T3AI Override', $result->brandName);
        self::assertSame(5, $result->uid);
    }

    public function testResolveForPageIdFallsBackToDefaultWhenOverrideMissing(): void
    {
        $default = $this->makeProfile(uid: 1, brandName: 'Default Brand');

        $profiles = $this->createMock(BrandContextProfileRepositoryInterface::class);
        $profiles->method('findByUid')->with(99)->willReturn(null);
        $profiles->method('findDefault')->with(10)->willReturn($default);

        $featureSettings = $this->createMock(BrandContextProfileOverrideReaderInterface::class);
        $featureSettings->method('resolveProfileUid')->with(10, 'ns_t3aa', '')->willReturn(99);

        $resolver = $this->makeResolverWithMocks(10, 42, $profiles, $featureSettings);

        $result = $resolver->resolveForPageId(42, 'ns_t3aa');

        self::assertNotNull($result);
        self::assertSame('Default Brand', $result->brandName);
    }

    private function makeResolver(int $storagePid, int $pageId, BrandContextProfile $defaultProfile): BrandContextResolver
    {
        $profiles = $this->createMock(BrandContextProfileRepositoryInterface::class);
        $profiles->method('findDefault')->with($storagePid)->willReturn($defaultProfile);

        $featureSettings = $this->createMock(BrandContextProfileOverrideReaderInterface::class);
        $featureSettings->method('resolveProfileUid')->willReturn(0);

        return $this->makeResolverWithMocks($storagePid, $pageId, $profiles, $featureSettings);
    }

    private function makeResolverWithMocks(
        int $storagePid,
        int $pageId,
        BrandContextProfileRepositoryInterface $profiles,
        BrandContextProfileOverrideReaderInterface $featureSettings,
    ): BrandContextResolver {
        $site = $this->createMock(Site::class);
        $site->method('getRootPageId')->willReturn($storagePid);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->with($pageId)->willReturn($site);

        return new BrandContextResolver($profiles, new SiteStorageContext($siteFinder), $featureSettings);
    }

    private function makeProfile(int $uid, string $brandName): BrandContextProfile
    {
        return BrandContextProfile::fromRow([
            'uid' => $uid,
            'pid' => 10,
            'brand_name' => $brandName,
            'industry' => 'Technology',
            'website_url' => '',
            'tagline' => '',
            'description' => '',
            'tone_tags' => '[]',
            'voice_notes' => '',
            'personas' => '[]',
            'content_rules' => '[]',
            'forbidden_words' => '[]',
            'keywords' => '[]',
            'competitors' => '[]',
            'language_code' => 'en',
            'sample_content' => '',
            'compliance_notes' => '',
            'document_extract' => '',
            'is_default' => 1,
            'completeness' => 20,
            'crdate' => 0,
            'tstamp' => 0,
        ]);
    }
}
