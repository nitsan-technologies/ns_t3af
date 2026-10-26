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
use NITSAN\NsT3AF\Credits\Service\CreditsApiResponseGuard;
use PHPUnit\Framework\TestCase;

final class CreditsApiResponseGuardTest extends TestCase
{
    private CreditsApiResponseGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new CreditsApiResponseGuard();
    }

    public function testRejectsOneCharacterBearer(): void
    {
        $this->expectException(CreditsApiException::class);
        $this->guard->assertBearerToken('x');
    }

    public function testAcceptsTypicalBearerLength(): void
    {
        $this->guard->assertBearerToken('bearer-test-token-abc');
    }

    public function testRejectsNegativeBalance(): void
    {
        try {
            $this->guard->guardBalance(['credits' => ['free_credits' => -1]]);
            self::fail('Expected CreditsApiException');
        } catch (CreditsApiException $exception) {
            self::assertSame(CreditsApiErrorCodes::INVALID_RESPONSE, $exception->errorCode);
        }
    }

    public function testRejectsHugeCreditMagnitude(): void
    {
        $this->expectException(CreditsApiException::class);
        $this->guard->guardBalance(['credits' => ['free_credits' => '1e18']]);
    }

    public function testRejectsHtmlInPlanName(): void
    {
        $this->expectException(CreditsApiException::class);
        $this->guard->guardCurrentPlan(['plan_name' => '<script>x</script>']);
    }

    public function testRejectsNonNumericExpiry(): void
    {
        $this->expectException(CreditsApiException::class);
        $this->guard->guardCurrentPlan(['plan_expires_at' => 'not-a-date']);
    }
}
