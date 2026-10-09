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
use NITSAN\NsT3AF\Credits\Exception\InsufficientCreditsException;
use NITSAN\NsT3AF\Credits\Http\CreditsApiErrorParser;
use PHPUnit\Framework\TestCase;

final class CreditsApiErrorParserTest extends TestCase
{
    public function testFlatEnvelopeKeepsDiagnosticExtras(): void
    {
        $e = CreditsApiErrorParser::toException([
            'status' => false,
            'error_code' => 'tools_unsupported',
            'message' => 'No tools',
            'param' => 'tools',
            'model' => 'gpt-x',
        ], 422);

        self::assertSame(CreditsApiErrorCodes::TOOLS_UNSUPPORTED, $e->errorCode);
        self::assertSame(422, $e->httpStatus);
        self::assertSame('tools', $e->extra['param']);
        self::assertSame('gpt-x', $e->extra['model']);
    }

    public function testOpenAiEnvelopeUsesNestedCodeAndExtras(): void
    {
        $e = CreditsApiErrorParser::toException([
            'error' => ['message' => 'bad', 'type' => 'invalid_request_error', 'code' => 'model_not_allowed', 'param' => null, 'model' => 'm1'],
        ]);

        self::assertSame(CreditsApiErrorCodes::MODEL_NOT_ALLOWED, $e->errorCode);
        self::assertSame(403, $e->httpStatus);
        self::assertSame('m1', $e->extra['model']);
        self::assertArrayNotHasKey('param', $e->extra);
    }

    public function testUpstreamContextLengthIsPromotedToTopLevelCode(): void
    {
        $e = CreditsApiErrorParser::toException([
            'error_code' => 'upstream_ai_error',
            'upstream_error_code' => 'context_length_exceeded',
        ], 502);

        self::assertSame(CreditsApiErrorCodes::CONTEXT_LENGTH_EXCEEDED, $e->errorCode);
        self::assertSame('context_length_exceeded', $e->extra['upstream_error_code']);
    }

    public function testInsufficientCreditsYieldsDedicatedException(): void
    {
        $e = CreditsApiErrorParser::toException([
            'error_code' => 'insufficient_credits',
            'topup_url' => 'https://example.test/top-up',
        ], 402);

        self::assertInstanceOf(InsufficientCreditsException::class, $e);
        self::assertSame('https://example.test/top-up', $e->topupUrl);
    }

    public function testAdditionalExtraDoesNotOverrideParsedFields(): void
    {
        $e = CreditsApiErrorParser::toException(
            ['error_code' => 'upstream_ai_error', 'request_uuid' => 'a'],
            0,
            null,
            ['request_uuid' => 'b', 'cost_units' => 3],
        );

        self::assertSame('a', $e->extra['request_uuid']);
        self::assertSame(3, $e->extra['cost_units']);
        self::assertSame(502, $e->httpStatus);
    }
}
