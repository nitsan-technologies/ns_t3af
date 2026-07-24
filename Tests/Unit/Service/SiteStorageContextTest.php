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

use NITSAN\NsT3AF\Service\SiteStorageContext;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class SiteStorageContextTest extends TestCase
{
    public function testResolvePageIdFromQueryId(): void
    {
        $request = (new ServerRequest('http://localhost/'))->withQueryParams(['id' => '12']);
        $context = new SiteStorageContext($this->createMock(SiteFinder::class));

        self::assertSame(12, $context->resolvePageIdFromRequest($request));
    }

    public function testResolvePageIdFromQueryPageId(): void
    {
        $request = (new ServerRequest('http://localhost/'))->withQueryParams(['pageId' => '68']);
        $context = new SiteStorageContext($this->createMock(SiteFinder::class));

        self::assertSame(68, $context->resolvePageIdFromRequest($request));
    }

    public function testResolvePageIdPrefersQueryIdOverPageId(): void
    {
        $request = (new ServerRequest('http://localhost/'))->withQueryParams([
            'id' => '5',
            'pageId' => '68',
        ]);
        $context = new SiteStorageContext($this->createMock(SiteFinder::class));

        self::assertSame(5, $context->resolvePageIdFromRequest($request));
    }

    public function testResolvePageIdFromBodyPageId(): void
    {
        $request = (new ServerRequest('http://localhost/'))
            ->withParsedBody(['pageId' => '42']);
        $context = new SiteStorageContext($this->createMock(SiteFinder::class));

        self::assertSame(42, $context->resolvePageIdFromRequest($request));
    }

    public function testResolveStoragePidFromPageId(): void
    {
        $site = $this->createMock(Site::class);
        $site->method('getRootPageId')->willReturn(68);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->with(70)->willReturn($site);

        $context = new SiteStorageContext($siteFinder);

        self::assertSame(68, $context->resolveStoragePidFromPageId(70));
    }

    public function testResolveFirstRootStoragePidReturnsNullWithoutConfiguredSites(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $context = new SiteStorageContext($siteFinder);

        self::assertNull($context->resolveFirstRootStoragePid());
    }
}
