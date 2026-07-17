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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\McpPublicUrlService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * @internal
 */
final class McpPublicUrlServiceTest extends TestCase
{
    #[Test]
    public function resolveOriginUsesRequestHostNotSiteBasePath(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn('t3af-v2.thebetaspace.com');
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getPort')->willReturn(null);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturnCallback(static function (string $name): string {
            return match ($name) {
                'Host' => 't3af-v2.thebetaspace.com',
                'X-Forwarded-Proto' => 'https',
                default => '',
            };
        });

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $service = new McpPublicUrlService($siteFinder);

        self::assertSame('https://t3af-v2.thebetaspace.com', $service->resolveOrigin($request));
    }

    #[Test]
    public function resolveOriginIgnoresPathOnlySiteBase(): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn('');
        $uri->method('getScheme')->willReturn('');
        $uri->method('getPort')->willReturn(null);

        $site = new class ($uri) {
            public function __construct(private UriInterface $uri) {}

            public function getBase(): UriInterface
            {
                return $this->uri;
            }
        };

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([$site]);

        $service = new McpPublicUrlService($siteFinder);

        self::assertSame('https://your-site.com', $service->resolveOrigin(null));
    }

    #[Test]
    public function resolveOriginUsesAbsoluteSiteBaseHost(): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn('t3af-v2.thebetaspace.com');
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getPort')->willReturn(null);

        $site = new class ($uri) {
            public function __construct(private UriInterface $uri) {}

            public function getBase(): UriInterface
            {
                return $this->uri;
            }
        };

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([$site]);

        $service = new McpPublicUrlService($siteFinder);

        self::assertSame('https://t3af-v2.thebetaspace.com', $service->resolveOrigin(null));
    }
}
