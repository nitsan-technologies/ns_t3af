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

namespace NITSAN\NsT3AF\Tests\Unit\Credits;

use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Http\T3PlanetSseStreamParser;
use PHPUnit\Framework\TestCase;

final class T3PlanetSseStreamParserTest extends TestCase
{
    private T3PlanetSseStreamParser $parser;

    protected function setUp(): void
    {
        $this->parser = new T3PlanetSseStreamParser();
    }

    public function testParseYieldsDeltasAndReturnsUsagePayload(): void
    {
        $lines = $this->lineGenerator([
            'event: token',
            'data: {"delta":"Hel"}',
            '',
            'event: token',
            'data: {"delta":"lo"}',
            '',
            'event: ping',
            'data: {}',
            '',
            'event: usage',
            'data: {"status":true,"content":"Hello","tokens_total":5,"credits":{},"charged":{}}',
            '',
        ]);

        $parsed = $this->parser->parse($lines);
        self::assertSame(['Hel', 'lo'], iterator_to_array($parsed, false));
        $usage = $parsed->getReturn();
        self::assertIsArray($usage);
        self::assertSame('Hello', $usage['content']);
        self::assertTrue($usage['status']);
    }

    public function testParseFailsWhenTokensReceivedWithoutUsage(): void
    {
        $lines = $this->lineGenerator([
            'event: token',
            'data: {"delta":"x"}',
            '',
        ]);

        $parsed = $this->parser->parse($lines);
        try {
            iterator_to_array($parsed, false);
            self::fail('Expected CreditsApiException');
        } catch (CreditsApiException $exception) {
            self::assertSame(CreditsApiErrorCodes::INVALID_RESPONSE, $exception->errorCode);
        }
    }

    public function testParseThrowsOnFailedUsageStatus(): void
    {
        $lines = $this->lineGenerator([
            'event: token',
            'data: {"delta":"part"}',
            '',
            'event: usage',
            'data: {"status":false,"error_code":"upstream_ai_error","upstream_message":"fail","content":"part","cost_units":3}',
            '',
        ]);

        $parsed = $this->parser->parse($lines);
        try {
            iterator_to_array($parsed, false);
            $parsed->getReturn();
            self::fail('Expected CreditsApiException');
        } catch (CreditsApiException $exception) {
            self::assertSame(CreditsApiErrorCodes::UPSTREAM_AI_ERROR, $exception->errorCode);
            self::assertSame(3, $exception->extra['cost_units']);
            self::assertSame('part', $exception->extra['content']);
        }
    }

    public function testParseHandlesSingleTokenReplay(): void
    {
        $lines = $this->lineGenerator([
            'event: token',
            'data: {"delta":"Full replayed content"}',
            '',
            'event: usage',
            'data: {"status":true,"content":"Full replayed content","tokens_total":10,"credits":{},"charged":{}}',
            '',
        ]);

        $parsed = $this->parser->parse($lines);
        self::assertSame(['Full replayed content'], iterator_to_array($parsed, false));
        self::assertSame('Full replayed content', $parsed->getReturn()['content']);
    }

    /**
     * @param list<string> $lines
     * @return \Generator<int, string, mixed, void>
     */
    private function lineGenerator(array $lines): \Generator
    {
        foreach ($lines as $line) {
            yield $line;
        }
    }
}
