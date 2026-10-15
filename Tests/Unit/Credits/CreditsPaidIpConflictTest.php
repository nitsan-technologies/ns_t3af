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
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Service\TokenResolver;
use PHPUnit\Framework\TestCase;

/**
 * `token_ip_paid_conflict` must never be handled as an ordinary IP conflict.
 *
 * An ordinary conflict routes into TokenResolver::adoptIpBoundAccount(), which clears the stored
 * bearer before re-minting. If the IP belongs to a paying customer — the shared-hosting case —
 * that would both hand this install someone else's paid credits and destroy its own token on the
 * way. The two codes therefore have to stay distinguishable.
 */
final class CreditsPaidIpConflictTest extends TestCase
{
    public function testPaidConflictIsRecognised(): void
    {
        $exception = new CreditsApiException(
            CreditsApiErrorCodes::TOKEN_IP_PAID_CONFLICT,
            403,
            'This IP belongs to a paid account',
        );

        self::assertTrue(TokenResolver::isPaidIpConflict($exception));
    }

    public function testPaidConflictIsNotAnOrdinaryIpConflict(): void
    {
        $exception = new CreditsApiException(
            CreditsApiErrorCodes::TOKEN_IP_PAID_CONFLICT,
            403,
            'This IP belongs to a paid account',
        );

        self::assertFalse(
            TokenResolver::isIpConflict($exception),
            'a paid conflict must not reach the adopt path',
        );
    }

    public function testOrdinaryIpConflictStillAdopts(): void
    {
        $exception = new CreditsApiException(
            CreditsApiErrorCodes::TOKEN_IP_CONFLICT,
            403,
            'Another token owns this IP',
        );

        self::assertTrue(TokenResolver::isIpConflict($exception));
        self::assertFalse(TokenResolver::isPaidIpConflict($exception));
    }

    public function testUnrelatedErrorIsNeither(): void
    {
        $exception = new CreditsApiException(CreditsApiErrorCodes::RATE_LIMITED, 429, 'Slow down');

        self::assertFalse(TokenResolver::isIpConflict($exception));
        self::assertFalse(TokenResolver::isPaidIpConflict($exception));
    }

    public function testCodesAreDistinctValues(): void
    {
        self::assertNotSame(
            CreditsApiErrorCodes::TOKEN_IP_CONFLICT,
            CreditsApiErrorCodes::TOKEN_IP_PAID_CONFLICT,
        );
        self::assertSame('token_ip_paid_conflict', CreditsApiErrorCodes::TOKEN_IP_PAID_CONFLICT);
    }
}
