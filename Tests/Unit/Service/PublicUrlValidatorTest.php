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

use NITSAN\NsT3AF\Service\PublicUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicUrlValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedUrlProvider(): iterable
    {
        yield 'cloud metadata (link-local)' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'loopback' => ['http://127.0.0.1:8080/'];
        yield 'rfc1918 10.x' => ['http://10.0.0.1/'];
        yield 'rfc1918 192.168.x' => ['https://192.168.1.1/admin'];
        yield 'rfc1918 172.16.x' => ['http://172.16.0.1/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'non-http scheme' => ['ftp://93.184.216.34/'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'missing host' => ['http:///path-only'];
        yield 'unresolvable host' => ['http://this-host-does-not-exist.invalid/'];
    }

    #[DataProvider('blockedUrlProvider')]
    public function testRejectsPrivateReservedAndInvalidUrls(string $url): void
    {
        self::assertFalse((new PublicUrlValidator())->isPublicUrl($url));
    }

    public function testAcceptsPublicIpLiteral(): void
    {
        self::assertTrue((new PublicUrlValidator())->isPublicUrl('https://93.184.216.34/'));
    }
}
