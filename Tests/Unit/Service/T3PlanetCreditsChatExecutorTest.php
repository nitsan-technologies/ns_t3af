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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiToolCall;
use NITSAN\NsT3AF\Api\AiToolDefinition;
use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Exception\InsufficientCreditsException;
use NITSAN\NsT3AF\Credits\Platform\T3PlanetCreditsPlatformFactory;
use NITSAN\NsT3AF\Credits\Service\CreditsChargeRecorder;
use NITSAN\NsT3AF\Credits\Service\T3PlanetCreditsModelsService;
use NITSAN\NsT3AF\Credits\Service\TokenResolver;
use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiMessageBagFactory;
use NITSAN\NsT3AF\Service\T3PlanetCreditsChatExecutor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\TextResult;

final class T3PlanetCreditsChatExecutorTest extends TestCase
{
    public function testMapsToolCallsAndRecordsCharge(): void
    {
        $raw = [
            'id' => 'chatcmpl-1',
            'model' => 't3planet/agent-standard',
            'choices' => [[
                'message' => [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'pages_tree', 'arguments' => '{"uid":1}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 3, 'total_tokens' => 14],
            't3planet' => ['charged' => true, 'credits' => 1500, 'bucket' => 'plan'],
        ];

        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('invoke')->willReturn(
            new DeferredResult(new PlainConverter(new TextResult('')), new InMemoryRawResult($raw)),
        );

        $factory = $this->createMock(T3PlanetCreditsPlatformFactory::class);
        $factory->method('create')->willReturn($platform);

        $models = $this->createMock(T3PlanetCreditsModelsService::class);
        $models->method('resolveDefaultAlias')->willReturn('t3planet/agent-standard');

        $recorder = $this->createMock(CreditsChargeRecorder::class);
        $recorder->expects(self::once())->method('record');

        $executor = new T3PlanetCreditsChatExecutor(
            $factory,
            $models,
            $this->createMock(TokenResolver::class),
            $recorder,
            new SymfonyAiMessageBagFactory(),
        );

        $response = $executor->completeWithTools(
            [['role' => 'user', 'content' => 'Show tree']],
            [new AiToolDefinition('pages_tree', 'Page tree', ['type' => 'object', 'properties' => []])],
            new AiOptions(extra: ['turn_id' => 'turn-abc']),
        );

        self::assertCount(1, $response->toolCalls);
        self::assertInstanceOf(AiToolCall::class, $response->toolCalls[0]);
        self::assertSame('pages_tree', $response->toolCalls[0]->name);
        self::assertSame(11, $response->tokensInput);
        self::assertSame(3, $response->tokensOutput);
    }

    public function testMapsInsufficientCreditsFromHttpError(): void
    {
        $httpException = new class ('insufficient') extends \RuntimeException implements \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface {
            public function getResponse(): \Symfony\Contracts\HttpClient\ResponseInterface
            {
                return new class implements \Symfony\Contracts\HttpClient\ResponseInterface {
                    public function getStatusCode(): int
                    {
                        return 402;
                    }

                    public function getHeaders(bool $throw = true): array
                    {
                        return [];
                    }

                    public function getContent(bool $throw = true): string
                    {
                        return json_encode([
                            'error' => [
                                'message' => 'Out of credits',
                                'code' => 'insufficient_credits',
                                'topup_url' => 'https://buy.example',
                            ],
                        ], JSON_THROW_ON_ERROR);
                    }

                    /**
                     * @return array<string, mixed>
                     */
                    public function toArray(bool $throw = true): array
                    {
                        return [];
                    }

                    public function cancel(): void {}

                    public function getInfo(?string $type = null): mixed
                    {
                        return null;
                    }
                };
            }
        };

        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('invoke')->willThrowException($httpException);

        $factory = $this->createMock(T3PlanetCreditsPlatformFactory::class);
        $factory->method('create')->willReturn($platform);

        $models = $this->createMock(T3PlanetCreditsModelsService::class);
        $models->method('resolveDefaultAlias')->willReturn('t3planet/agent-standard');

        $executor = new T3PlanetCreditsChatExecutor(
            $factory,
            $models,
            $this->createMock(TokenResolver::class),
            $this->createMock(CreditsChargeRecorder::class),
            new SymfonyAiMessageBagFactory(),
        );

        $this->expectException(InsufficientCreditsException::class);
        $executor->completeWithTools([['role' => 'user', 'content' => 'Hi']], [], new AiOptions());
    }

    public function testRetriesOnceOnIdempotencyConflict(): void
    {
        $raw = [
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            't3planet' => ['charged' => true, 'credits' => 1],
            'model' => 't3planet/agent-standard',
        ];

        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects(self::exactly(2))->method('invoke')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new CreditsApiException(CreditsApiErrorCodes::IDEMPOTENCY_CONFLICT, 409)),
                new DeferredResult(new PlainConverter(new TextResult('ok')), new InMemoryRawResult($raw)),
            );

        $factory = $this->createMock(T3PlanetCreditsPlatformFactory::class);
        $factory->method('create')->willReturn($platform);

        $models = $this->createMock(T3PlanetCreditsModelsService::class);
        $models->method('resolveDefaultAlias')->willReturn('t3planet/agent-standard');

        $executor = new T3PlanetCreditsChatExecutor(
            $factory,
            $models,
            $this->createMock(TokenResolver::class),
            $this->createMock(CreditsChargeRecorder::class),
            new SymfonyAiMessageBagFactory(),
        );

        $response = $executor->completeWithTools([['role' => 'user', 'content' => 'Hi']], [], new AiOptions());
        self::assertSame('ok', $response->content);
    }
}
