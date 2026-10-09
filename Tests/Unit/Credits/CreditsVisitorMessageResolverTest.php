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

use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Exception\InsufficientCreditsException;
use NITSAN\NsT3AF\Credits\Service\CreditsVisitorMessageResolver;
use PHPUnit\Framework\TestCase;

final class CreditsVisitorMessageResolverTest extends TestCase
{
    public function testInsufficientCreditsIsExhausted(): void
    {
        self::assertTrue(CreditsVisitorMessageResolver::isCreditsExhausted(new InsufficientCreditsException()));
    }

    public function testPlanExpiredAndDailyCapAreExhausted(): void
    {
        self::assertTrue(CreditsVisitorMessageResolver::isCreditsExhausted(new CreditsApiException('plan_expired', 402)));
        self::assertTrue(CreditsVisitorMessageResolver::isCreditsExhausted(new CreditsApiException('daily_cap_exceeded', 402)));
    }

    public function testWrappedExceptionIsDetected(): void
    {
        $wrapped = new \RuntimeException('failed', 0, new InsufficientCreditsException());
        self::assertTrue(CreditsVisitorMessageResolver::isCreditsExhausted($wrapped));
    }

    public function testOtherErrorsAreNotExhausted(): void
    {
        self::assertFalse(CreditsVisitorMessageResolver::isCreditsExhausted(new CreditsApiException('upstream_ai_error', 502)));
        self::assertFalse(CreditsVisitorMessageResolver::isCreditsExhausted(new \RuntimeException('boom')));
    }

    public function testVisitorMessageIsNeutral(): void
    {
        $previous = $GLOBALS['LANG'] ?? null;
        $GLOBALS['LANG'] = new class {
            public function sL(string $label): string
            {
                return '';
            }
        };
        try {
            $message = CreditsVisitorMessageResolver::visitorMessage();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['LANG']);
            } else {
                $GLOBALS['LANG'] = $previous;
            }
        }

        self::assertStringContainsString('temporarily unavailable', $message);
        self::assertStringNotContainsStringIgnoringCase('credit', $message);
    }
}
