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

use NITSAN\NsT3AF\Service\BrandContextWebsiteFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class BrandContextWebsiteFetcherTest extends TestCase
{
    public function testStripPageContentRemovesScriptsAndTags(): void
    {
        $fetcher = new BrandContextWebsiteFetcher(
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
        );

        $html = <<<'HTML'
<html><head><title>Test</title></head>
<body><nav>Menu</nav><script>alert(1)</script><p>Hello <strong>World</strong></p><footer>Foot</footer></body>
</html>
HTML;

        $text = $fetcher->stripPageContent($html);

        self::assertStringContainsString('Hello World', preg_replace('/\s+/', ' ', $text) ?? $text);
        self::assertStringNotContainsString('alert', $text);
        self::assertStringNotContainsString('Menu', $text);
        self::assertStringNotContainsString('Foot', $text);
    }

    public function testFetchTextReturnsNoticeForInvalidUrl(): void
    {
        $fetcher = new BrandContextWebsiteFetcher(
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
        );

        $result = $fetcher->fetchText('not a valid url %%');

        self::assertFalse($result['fetched']);
        self::assertSame('', $result['content']);
        self::assertNotNull($result['notice']);
    }

    public function testFetchTextStripsSuccessfulHtmlResponse(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn('<html><body><p>Acme brand site</p></body></html>');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);

        $fetcher = new BrandContextWebsiteFetcher($client, $requestFactory);
        $result = $fetcher->fetchText('https://example.com');

        self::assertTrue($result['fetched']);
        self::assertStringContainsString('Acme brand site', $result['content']);
        self::assertNull($result['notice']);
    }
}
