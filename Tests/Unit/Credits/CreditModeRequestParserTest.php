<?php

declare(strict_types=1);

/*
 * This file is part of the "AI Foundation for TYPO3" (ns_t3af) extension.
 *
 * (c) T3Planet / NITSAN Technologies <support@t3planet.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
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
