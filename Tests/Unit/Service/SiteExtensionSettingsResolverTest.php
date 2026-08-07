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

use NITSAN\NsT3AF\Service\SiteExtensionSettingsResolver;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Settings\ExtensionSettingsRepository;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class SiteExtensionSettingsResolverTest extends TestCase
{
    public function testResolveUsesPageIdBeforeSiteScan(): void
    {
        $site = $this->createMock(Site::class);
        $site->method('getRootPageId')->willReturn(68);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())
            ->method('getSiteByPageId')
            ->with(70)
            ->willReturn($site);
        $siteFinder->expects(self::never())->method('getAllSites');

        $resolver = new SiteExtensionSettingsResolver(
            new SiteStorageContext($siteFinder),
            $siteFinder,
            $this->createMock(ExtensionSettingsRepository::class),
            ProviderTestStubs::t3AiStorageProbeRegistry(),
        );

        self::assertSame(68, $resolver->resolve(70, true));
    }

    public function testResolveScansSitesWhenNoPageContext(): void
    {
        $site = $this->createMock(Site::class);
        $site->method('getRootPageId')->willReturn(42);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([$site]);

        $repository = $this->createMock(ExtensionSettingsRepository::class);
        $repository->method('findByExtensionKey')->with('ns_t3ai', 42)->willReturn([
            'settings_json' => '{"stabilityAiApiKey":"enc:v1:abc"}',
        ]);

        $resolver = new SiteExtensionSettingsResolver(
            new SiteStorageContext($siteFinder),
            $siteFinder,
            $repository,
            ProviderTestStubs::t3AiStorageProbeRegistry(),
        );

        self::assertSame(42, $resolver->resolve(null, true));
    }
}
