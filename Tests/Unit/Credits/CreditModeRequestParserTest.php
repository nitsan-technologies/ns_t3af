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

use NITSAN\NsT3AF\Credits\Service\CreditModeRequestParser;
use PHPUnit\Framework\TestCase;

final class CreditModeRequestParserTest extends TestCase
{
    public function testClaimCodeRejectsArrayPayload(): void
    {
        self::assertNull(CreditModeRequestParser::claimCodeFromBody(['claimCode' => ['x']]));
    }

    public function testClaimCodeTrimsScalar(): void
    {
        self::assertSame('abc', CreditModeRequestParser::claimCodeFromBody(['claimCode' => '  abc  ']));
    }

    public function testEnabledRejectsUnknownString(): void
    {
        self::assertNull(CreditModeRequestParser::enabledFromBody(['enabled' => 'maybe']));
    }

    public function testEnabledAcceptsBoolean(): void
    {
        self::assertTrue(CreditModeRequestParser::enabledFromBody(['enabled' => true]));
        self::assertFalse(CreditModeRequestParser::enabledFromBody(['enabled' => false]));
    }
}
