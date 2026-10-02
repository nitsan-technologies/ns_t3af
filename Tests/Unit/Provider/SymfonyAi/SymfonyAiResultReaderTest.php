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

namespace NITSAN\NsT3AF\Tests\Unit\Provider\SymfonyAi;

use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiResultReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;

/**
 * @internal
 */
final class SymfonyAiResultReaderTest extends TestCase
{
    #[Test]
    public function reasoningModelAnswerIsNotLost(): void
    {
        // gpt-5 / o-series via the Responses API, Anthropic extended thinking: thinking + text.
        $deferred = $this->deferred(new MultiPartResult([
            new ThinkingResult('The user greets me.'),
            new TextResult('Hallo! Wie kann ich helfen?'),
        ]));

        self::assertSame('Hallo! Wie kann ich helfen?', SymfonyAiResultReader::text($deferred));
        self::assertSame('The user greets me.', SymfonyAiResultReader::thinking($deferred));
        self::assertSame([], SymfonyAiResultReader::toolCalls($deferred));
    }

    #[Test]
    public function toolCallsAreReadFromTheResultObjects(): void
    {
        $deferred = $this->deferred(new MultiPartResult([
            new ThinkingResult('Need the page first.'),
            new ToolCallResult([new ToolCall('call_1', 'pages_get', ['uid' => 49])]),
        ]));

        self::assertSame([['id' => 'call_1', 'name' => 'pages_get', 'arguments' => ['uid' => 49]]], SymfonyAiResultReader::toolCalls($deferred));
        self::assertSame('', SymfonyAiResultReader::text($deferred));
    }

    #[Test]
    public function plainTextResult(): void
    {
        self::assertSame('Hi', SymfonyAiResultReader::text($this->deferred(new TextResult('Hi'))));
        self::assertSame('Hi', SymfonyAiResultReader::text(new TextResult('Hi')));
    }

    private function deferred(ResultInterface $result): DeferredResult
    {
        return new DeferredResult(new PlainConverter($result), new InMemoryRawResult([]));
    }
}
