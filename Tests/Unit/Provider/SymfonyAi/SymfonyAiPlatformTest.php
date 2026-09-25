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

    #[Test]
    public function embedPassesRawStringWithoutMessageBagOrTemperature(): void
    {
        $inner = new class {
            public mixed $receivedInput = null;
            /** @var array<string, mixed>|null */
            public ?array $receivedOptions = null;

            /**
             * @param array<string, mixed> $options
             */
            public function invoke(string $modelId, mixed $input, array $options = []): SymfonyAiTextResultStub
            {
                $this->receivedInput = $input;
                $this->receivedOptions = $options;

                return new SymfonyAiTextResultStub('unused');
            }
        };

        $service = new SymfonyAiPlatform(
            $inner,
            $this->makeProvider(),
            new SymfonyAiMessageBagFactory(),
        );

        $service->embed('text-embedding-3-large', 'Unable to search query');

        self::assertSame('Unable to search query', $inner->receivedInput);
        self::assertSame([], $inner->receivedOptions);
    }

    #[Test]
    public function invokeWithToolsStripsStdClassFromEmptyProperties(): void
    {
        if (!class_exists('Symfony\\AI\\Platform\\Message\\Message')) {
            self::markTestSkipped('symfony/ai-platform message classes are not installed.');
        }

        $seenTools = null;
        $platform = new class ($seenTools) {
            /** @var mixed */
            public mixed $seenToolsRef;

            public function __construct(mixed &$seenTools)
            {
                $this->seenToolsRef = &$seenTools;
            }

            /**
             * @param array<string, mixed> $options
             */
            public function invoke(string $modelId, object $messageBag, array $options): object
            {
                $this->seenToolsRef = $options['tools'] ?? null;

                return new class {
                    public function asText(): string
                    {
                        return 'ok';
                    }

                    public function getRawResult(): object
                    {
                        return new class {
                            /** @return array<string, mixed> */
                            public function getData(): array
                            {
                                return ['choices' => [['message' => ['content' => 'ok', 'tool_calls' => []]]]];
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

        $service->invokeWithTools(
            'gpt-4.1-mini',
            [['role' => 'user', 'content' => 'Help']],
            [[
                'name' => 'noop',
                'description' => 'No args',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ]],
        );

        self::assertIsArray($seenTools);
        self::assertNotEmpty($seenTools);
        $first = $seenTools[0];
        if (class_exists(\Symfony\AI\Platform\Tool\Tool::class)) {
            self::assertInstanceOf(\Symfony\AI\Platform\Tool\Tool::class, $first);
            self::assertSame('noop', $first->getName());
            // No-arg schema (empty properties) is omitted — OpenAI Responses rejects properties:[].
            self::assertNull($first->getParameters());
        } else {
            self::assertIsArray($first);
            self::assertSame('noop', $first['name'] ?? null);
            self::assertArrayNotHasKey('parameters', $first);
        }
    }

    #[Test]
    public function toolSchemasKeepUnionTypesAndDropEmptyNestedProperties(): void
    {
        $platform = (new \ReflectionClass(SymfonyAiPlatform::class))->newInstanceWithoutConstructor();
        $normalize = new \ReflectionMethod(SymfonyAiPlatform::class, 'normalizeParametersForTool');

        $schema = $normalize->invoke($platform, [
            'type' => 'object',
            'properties' => [
                'nullable' => ['type' => ['string', 'null'], 'description' => 'Optional text'],
                'either' => ['anyOf' => [['type' => 'string'], ['type' => 'object', 'properties' => new \stdClass()]]],
                'untyped' => ['description' => ''],
                'nested' => [
                    'type' => 'object',
                    'properties' => [
                        'inner' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => ['ghost']],
                    ],
                    'required' => ['inner', 'ghost'],
                ],
                'list' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => []]],
            ],
            'required' => ['nullable', 'missing'],
        ]);

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'nullable' => ['type' => ['string', 'null'], 'description' => 'Optional text'],
                'either' => ['anyOf' => [['type' => 'string'], ['type' => 'object']]],
                'untyped' => ['type' => 'string'],
                'nested' => [
                    'type' => 'object',
                    'properties' => ['inner' => ['type' => 'object']],
                    'required' => ['inner'],
                ],
                'list' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
            'required' => ['nullable'],
            'additionalProperties' => false,
        ], $schema);
        self::assertStringNotContainsString('"properties":[]', json_encode($schema, JSON_THROW_ON_ERROR));
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

    public function testMessageBagKeepsToolRounds(): void
    {
        $bag = (new SymfonyAiMessageBagFactory())->createFromChatMessages([
            ['role' => 'system', 'content' => 'SYSTEM'],
            ['role' => 'user', 'content' => 'Title of page 49?'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'name' => 'pages_get', 'arguments' => ['uid' => 49]]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'pages_get', 'content' => 'AI ChEddi'],
        ]);

        self::assertInstanceOf(\Symfony\AI\Platform\Message\MessageBag::class, $bag);
        $messages = $bag->getMessages();
        self::assertCount(4, $messages);
        self::assertInstanceOf(\Symfony\AI\Platform\Message\AssistantMessage::class, $messages[2]);
        self::assertSame('pages_get', $messages[2]->getToolCalls()[0]->getName());
        self::assertSame(['uid' => 49], $messages[2]->getToolCalls()[0]->getArguments());
        self::assertInstanceOf(\Symfony\AI\Platform\Message\ToolCallMessage::class, $messages[3]);
        self::assertSame('call_1', $messages[3]->getToolCall()->getId());
        self::assertSame('AI ChEddi', $messages[3]->asText());
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
