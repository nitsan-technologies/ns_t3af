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

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiMessageBagFactory;
use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiPlatform;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SymfonyAiPlatformTest extends TestCase
{
    #[Test]
    public function invokeWithToolsReturnsNormalizedToolCalls(): void
    {
        if (!class_exists('Symfony\\AI\\Platform\\Message\\Message')) {
            self::markTestSkipped('symfony/ai-platform message classes are not installed.');
        }

        $platform = new class {
            /**
             * @param array<string, mixed> $options
             */
            public function invoke(string $modelId, object $messageBag, array $options): object
            {
                return new class {
                    public function asText(): string
                    {
                        return 'Done';
                    }

                    public function getRawResult(): object
                    {
                        return new class {
                            /**
                             * @return array<string, mixed>
                             */
                            public function getData(): array
                            {
                                return [
                                    'choices' => [[
                                        'message' => [
                                            'content' => 'Done',
                                            'tool_calls' => [[
                                                'id' => 'call_1',
                                                'function' => [
                                                    'name' => 'pages_get',
                                                    'arguments' => '{"uid":1}',
                                                ],
                                            ]],
                                        ],
                                    ]],
                                ];
                            }
                        };
                    }
                };
            }
        };

        $service = new SymfonyAiPlatform(
            $platform,
            $this->makeProvider(),
            new SymfonyAiMessageBagFactory(),
        );

        $result = $service->invokeWithTools(
            'gpt-4.1-mini',
            [['role' => 'user', 'content' => 'Read page 1']],
            [['name' => 'pages_get', 'description' => 'Get page']],
        );

        self::assertSame('Done', $result['content']);
        self::assertCount(1, $result['toolCalls']);
        self::assertSame('pages_get', $result['toolCalls'][0]['name']);
        self::assertSame(1, $result['toolCalls'][0]['arguments']['uid']);
    }

    #[Test]
    public function invokeDelegatesToInnerPlatform(): void
    {
        if (!class_exists('Symfony\\AI\\Platform\\Message\\Message')) {
            self::markTestSkipped('symfony/ai-platform message classes are not installed.');
        }

        $inner = new class {
            /** @var list<string> */
            public array $models = [];

            /**
             * @param array<string, mixed> $options
             */
            public function invoke(string $modelId, object $messageBag, array $options): SymfonyAiTextResultStub
            {
                $this->models[] = $modelId;

                return new SymfonyAiTextResultStub('Summary text');
            }
        };

        $service = new SymfonyAiPlatform(
            $inner,
            $this->makeProvider(),
            new SymfonyAiMessageBagFactory(),
        );

        $result = $service->invoke('gpt-4.1-mini', 'Summarize this page.');

        self::assertSame(['gpt-4.1-mini'], $inner->models);
        self::assertInstanceOf(SymfonyAiTextResultStub::class, $result);
        self::assertSame('Summary text', $result->asText());
    }

    private function makeProvider(): Provider
    {
        return new Provider(
            uid: 1,
            pid: 0,
            identifier: 'demo',
            title: 'Demo',
            adapterType: 'symfony.openai',
            endpointUrl: '',
            apiKeyCipher: '',
            modelId: 'gpt-4.1-mini',
            embeddingModelId: '',
            capabilities: [Capability::CHAT],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: true,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: '',
            lastStatusAt: 0,
            lastStatusMessage: '',
        );
    }
}

final class SymfonyAiTextResultStub
{
    public function __construct(
        private readonly string $text,
    ) {}

    public function asText(): string
    {
        return $this->text;
    }
}
