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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Utility;

use NITSAN\NsT3AF\Mcp\Utility\McpIpMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpIpMatcherTest extends TestCase
{
    #[DataProvider('ipv4Provider')]
    public function testIpv4Matching(string $ip, string $cidr, bool $expected): void
    {
        self::assertSame($expected, McpIpMatcher::matches($ip, $cidr));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function ipv4Provider(): iterable
    {
        yield 'exact match' => ['192.168.1.10', '192.168.1.10', true];
        yield 'exact mismatch' => ['192.168.1.10', '192.168.1.11', false];
        yield 'inside /24' => ['10.0.0.42', '10.0.0.0/24', true];
        yield 'outside /24' => ['10.0.1.1', '10.0.0.0/24', false];
        yield 'inside /32' => ['203.0.113.5', '203.0.113.5/32', true];
    }
}
