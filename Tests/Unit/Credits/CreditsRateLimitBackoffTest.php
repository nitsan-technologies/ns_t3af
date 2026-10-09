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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Tests\Unit\Credits;

use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Service\CreditsRateLimitBackoff;
use PHPUnit\Framework\TestCase;

final class CreditsRateLimitBackoffTest extends TestCase
{
    public function testRetryAfterWinsOverExponential(): void
    {
        self::assertSame(3.0, CreditsRateLimitBackoff::delaySeconds(1, 3, 1.0));
        self::assertSame(60.0, CreditsRateLimitBackoff::delaySeconds(1, 120, 1.0));
    }

    public function testExponentialUsesJitterUnit(): void
    {
        self::assertSame(0.5, CreditsRateLimitBackoff::delaySeconds(1, 0, 1.0));
        self::assertSame(1.0, CreditsRateLimitBackoff::delaySeconds(2, 0, 1.0));
        self::assertSame(2.0, CreditsRateLimitBackoff::delaySeconds(3, 0, 1.0));
        self::assertSame(4.0, CreditsRateLimitBackoff::delaySeconds(4, 0, 1.0));
        self::assertSame(8.0, CreditsRateLimitBackoff::delaySeconds(5, 0, 1.0));
    }

    public function testIsRateLimited(): void
    {
        self::assertTrue(CreditsRateLimitBackoff::isRateLimited(CreditsApiErrorCodes::RATE_LIMITED));
        self::assertTrue(CreditsRateLimitBackoff::isRateLimited(CreditsApiErrorCodes::CONCURRENCY_LIMIT));
        self::assertTrue(CreditsRateLimitBackoff::isRateLimited('other', 429));
        self::assertFalse(CreditsRateLimitBackoff::isRateLimited('token_invalid', 401));
    }
}
