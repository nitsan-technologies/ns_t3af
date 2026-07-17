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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Event\AfterProviderResponseEvent;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Event\ProviderRequestFailedEvent;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\UnknownAdapterException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
use NITSAN\NsT3AF\Provider\Contract\VerifyResult;
use NITSAN\NsT3AF\Service\AiService;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Tests\Unit\Service\Fixture\CancellingDispatcher;
use NITSAN\NsT3AF\Tests\Unit\Service\Fixture\CapturingDispatcher;
use NITSAN\NsT3AF\Tests\Unit\Service\Fixture\StaticProviderLookup;
use PHPUnit\Framework\TestCase;

final class AiServiceTest extends TestCase
{
    private function makeSiteStorageContext(): SiteStorageContext
    {
        return new SiteStorageContext($this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class));
    }

    public function testCompleteHappyPathDispatchesBothEventsAndReturnsContent(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public function invoke(string $model, mixed $payload, array $invokeOptions = []): string
            {
                return 'reply for hello';
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $events = new CapturingDispatcher();

        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            $events,
            $this->makeSiteStorageContext(),
        );

        $response = $service->complete('hello');

        self::assertSame('reply for hello', $response->content);
        self::assertSame('gpt-4o', $response->modelId);
        self::assertSame('openai-prod', $response->providerIdentifier);
        self::assertCount(2, $events->dispatched);
        self::assertInstanceOf(BeforeProviderRequestEvent::class, $events->dispatched[0]);
        self::assertInstanceOf(AfterProviderResponseEvent::class, $events->dispatched[1]);
    }

    public function testCompleteCancelsWhenListenerSetsReason(): void
    {
        $provider = $this->makeProvider();
        $adapter = $this->makeAdapter('symfony.openai', new \stdClass());
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CancellingDispatcher('blocked by ACL'),
            $this->makeSiteStorageContext(),
        );

        $response = $service->complete('hi');

        self::assertSame('', $response->content);
        self::assertSame(['cancelled' => 'blocked by ACL'], $response->raw);
    }

    public function testCompleteOptionModelIdOverridesProvider(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public string $seenModel = '';
            public function invoke(string $model, mixed $payload, array $invokeOptions = []): string
            {
                $this->seenModel = $model;
                return 'ok';
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $service->complete('hi', new AiOptions(modelId: 'gpt-5'));

        self::assertSame('gpt-5', $platform->seenModel);
    }

    public function testCompleteFallsBackToArrayPayloadWhenStringIsRejected(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public mixed $seenPayload = null;

            public function invoke(string $model, mixed $payload, array $invokeOptions = []): string
            {
                if (!is_array($payload)) {
                    throw new \InvalidArgumentException('Payload must be an array, but a string was given');
                }
                $this->seenPayload = $payload;

                return 'array payload accepted';
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->complete('hello');

        self::assertSame('array payload accepted', $response->content);
        self::assertIsArray($platform->seenPayload);
    }

    public function testCompleteFallsBackToArrayPayloadWhenMessageBagNormalizationFails(): void
    {
        $provider = $this->makeProvider(adapterType: 'symfony.mistral');
        $platform = new class {
            public mixed $seenPayload = null;

            /** @var list<mixed> */
            public array $seenPayloads = [];

            public function invoke(string $model, mixed $payload, array $invokeOptions = []): string
            {
                $this->seenPayloads[] = $payload;

                if (is_object($payload)) {
                    throw new \Symfony\Component\Serializer\Exception\NotNormalizableValueException(
                        'Could not normalize object of type "Symfony\\AI\\Platform\\Message\\UserMessage", no supporting normalizer found.',
                    );
                }
                if (!is_array($payload)) {
                    throw new \InvalidArgumentException('Payload must be an array, but a string was given');
                }
                $this->seenPayload = $payload;

                return 'array payload accepted';
            }
        };
        $adapter = $this->makeAdapter('symfony.mistral', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->complete('hello');

        self::assertSame('array payload accepted', $response->content);
        self::assertIsArray($platform->seenPayload);
        self::assertArrayHasKey('messages', $platform->seenPayload);
        self::assertNotEmpty($platform->seenPayloads);
        self::assertIsObject($platform->seenPayloads[0]);
    }

    public function testCompleteExtractsTextFromDeferredLikeResult(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public function invoke(string $model, mixed $payload): object
            {
                return new class {
                    public function asText(): string
                    {
                        return 'deferred text';
                    }
                };
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->complete('hello');

        self::assertSame('deferred text', $response->content);
    }

    public function testCompleteExtractsTokensFromDeferredMetadataUsage(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public function invoke(string $model, mixed $payload): object
            {
                return new class {
                    public function asText(): string
                    {
                        return 'ok';
                    }

                    public function getResult(): object
                    {
                        return new class {
                            public function getMetadata(): object
                            {
                                return new class {
                                    public function get(string $key): object
                                    {
                                        return new class {
                                            public function getPromptTokens(): int
                                            {
                                                return 123;
                                            }

                                            public function getCompletionTokens(): int
                                            {
                                                return 45;
                                            }
                                        };
                                    }
                                };
                            }
                        };
                    }
                };
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->complete('hello');

        self::assertSame(123, $response->tokensInput);
        self::assertSame(45, $response->tokensOutput);
    }

    public function testCompleteExtractsTokensFromRawUsageFallback(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public function invoke(string $model, mixed $payload): object
            {
                return new class {
                    public function asText(): string
                    {
                        return 'ok';
                    }

                    public function getResult(): object
                    {
                        return new class {
                            public function getMetadata(): object
                            {
                                return new class {
                                    public function get(string $key): mixed
                                    {
                                        return null;
                                    }
                                };
                            }
                        };
                    }

                    public function getRawResult(): object
                    {
                        return new class {
                            public function getData(): array
                            {
                                return [
                                    'usage' => [
                                        'input_tokens' => 88,
                                        'output_tokens' => 22,
                                    ],
                                ];
                            }
                        };
                    }
                };
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->complete('hello');

        self::assertSame(88, $response->tokensInput);
        self::assertSame(22, $response->tokensOutput);
    }

    public function testCompleteFailureDispatchesFailureEventAndWrapsThrowable(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public function invoke(string $model, mixed $payload, array $invokeOptions = []): string
            {
                throw new \LogicException('boom');
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $events = new CapturingDispatcher();
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            $events,
            $this->makeSiteStorageContext(),
        );

        $threw = null;
        try {
            $service->complete('x');
        } catch (AdapterRuntimeException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw);
        self::assertSame('boom', $threw->getMessage());
        $kinds = array_map(static fn(object $e): string => $e::class, $events->dispatched);
        self::assertContains(BeforeProviderRequestEvent::class, $kinds);
        self::assertContains(ProviderRequestFailedEvent::class, $kinds);
    }

    public function testCompleteRateLimitErrorIsClassifiedWithStatusCode(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public function invoke(string $model, mixed $payload): string
            {
                throw new \RuntimeException('HTTP/2 429 returned for "https://api.openai.com/v1/responses".');
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $this->expectException(AdapterRuntimeException::class);
        $this->expectExceptionCode(429);
        $this->expectExceptionMessage('Rate limit or quota exceeded at provider.');
        $service->complete('hello');
    }

    public function testProviderUnknownIdentifierThrows(): void
    {
        $service = new AiService(new StaticProviderLookup(null), new AdapterRegistry(), new CapturingDispatcher(), $this->makeSiteStorageContext());

        $this->expectException(UnknownAdapterException::class);
        $service->provider('missing');
    }

    public function testProviderNoDefaultThrows(): void
    {
        $service = new AiService(new StaticProviderLookup(null), new AdapterRegistry(), new CapturingDispatcher(), $this->makeSiteStorageContext());

        $this->expectException(UnknownAdapterException::class);
        $service->provider();
    }

    public function testCompleteUnknownAdapterTypeThrows(): void
    {
        $provider = $this->makeProvider();
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry(),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $this->expectException(UnknownAdapterException::class);
        $service->complete('hi');
    }

    public function testStreamYieldsChunks(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public function stream(string $model, string $prompt): \Generator
            {
                yield 'a';
                yield 'b';
                yield 'c';
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $chunks = iterator_to_array($service->stream('hi'), false);

        self::assertSame(['a', 'b', 'c'], $chunks);
    }

    public function testStreamSymfonyInvokeTriesStringPromptBeforeInputPayload(): void
    {
        $provider = $this->makeProvider(adapterType: 'symfony.ollama');
        $seenPayloads = [];
        $platform = new class ($seenPayloads) {
            public function __construct(private array &$seenPayloads) {}

            public function invoke(string $model, mixed $payload, array $options = []): object
            {
                $this->seenPayloads[] = $payload;
                if (is_array($payload) && array_key_exists('input', $payload)) {
                    throw new \RuntimeException('HTTP/1.1 400 Bad Request returned for "http://ollama.test/api/chat".');
                }

                return new class {
                    public function asTextStream(): \Generator
                    {
                        yield 'ok';
                    }
                };
            }
        };
        $adapter = $this->makeAdapter('symfony.ollama', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $chunks = iterator_to_array($service->stream('hi'), false);

        self::assertSame(['ok'], $chunks);
        self::assertNotEmpty($seenPayloads);
        $firstIsInputOnly = is_array($seenPayloads[0])
            && array_key_exists('input', $seenPayloads[0])
            && !array_key_exists('messages', $seenPayloads[0]);
        self::assertFalse($firstIsInputOnly);
    }

    public function testStreamYieldsChunksViaPlatformInvokeAsTextStream(): void
    {
        $provider = $this->makeProvider(adapterType: 'symfony.ollama');
        $platform = new class {
            public function invoke(string $model, mixed $payload, array $options = []): object
            {
                return new class {
                    public function asTextStream(): \Generator
                    {
                        yield new class {
                            public function getText(): string
                            {
                                return 'hel';
                            }
                        };
                        yield new class {
                            public function getText(): string
                            {
                                return 'lo';
                            }
                        };
                    }
                };
            }
        };
        $adapter = $this->makeAdapter('symfony.ollama', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $chunks = iterator_to_array($service->stream('hi'), false);

        self::assertSame(['hel', 'lo'], $chunks);
    }

    public function testStreamFailsWhenPlatformHasNoStreamingApi(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public function request(string $model, mixed $payload): string
            {
                return 'nope';
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $this->expectException(AdapterRuntimeException::class);
        $this->expectExceptionMessage('neither stream() nor invoke()');

        iterator_to_array($service->stream('hi'), false);
    }

    public function testCompleteReadsTokenUsageFromArrayInvokeResult(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            /**
             * @return array{content: string, usage: array{prompt_tokens: int, completion_tokens: int}}
             */
            public function invoke(string $model, mixed $payload): array
            {
                return [
                    'content' => 'done',
                    'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 4],
                ];
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->complete('hello');

        self::assertSame('done', $response->content);
        self::assertSame(2, $response->tokensInput);
        self::assertSame(4, $response->tokensOutput);
    }

    public function testEmbedUsesEmbeddingModelIdWhenConfigured(): void
    {
        $provider = $this->makeProvider(embeddingModelId: 'text-embedding-3-small');
        $platform = new class {
            public string $seenModel = '';

            public function embed(string $model, string|array $text): array
            {
                $this->seenModel = $model;

                return [[0.1]];
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->embed('hello');

        self::assertSame('text-embedding-3-small', $platform->seenModel);
        self::assertSame('text-embedding-3-small', $response->modelId);
    }

    public function testEmbedFallsBackToChatModelWhenEmbeddingModelEmpty(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public string $seenModel = '';

            public function embed(string $model, string|array $text): array
            {
                $this->seenModel = $model;

                return [[0.1]];
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $service->embed('hello');

        self::assertSame('gpt-4o', $platform->seenModel);
    }

    public function testEmbedUsesInvokeWhenEmbedMethodMissing(): void
    {
        $provider = $this->makeProvider(embeddingModelId: 'mistral-embed', adapterType: 'symfony.mistral');
        $platform = new class {
            public string $seenModel = '';
            public mixed $seenInput = null;

            public function invoke(string $model, mixed $input): object
            {
                $this->seenModel = $model;
                $this->seenInput = $input;

                return new class {
                    public function asVectors(): array
                    {
                        return [new class {
                            public function getData(): array
                            {
                                return [0.1, 0.2, 0.3];
                            }
                        }];
                    }
                };
            }
        };
        $adapter = $this->makeAdapter('symfony.mistral', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->embed('hello');

        self::assertSame('mistral-embed', $platform->seenModel);
        self::assertSame('hello', $platform->seenInput);
        self::assertSame([[0.1, 0.2, 0.3]], $response->vectors);
        self::assertSame('mistral-embed', $response->modelId);
    }

    public function testEmbedInvokeAcceptsBatchInput(): void
    {
        $provider = $this->makeProvider(embeddingModelId: 'mistral-embed', adapterType: 'symfony.mistral');
        $platform = new class {
            public mixed $seenInput = null;

            public function invoke(string $model, mixed $input): object
            {
                $this->seenInput = $input;

                return new class {
                    public function asVectors(): array
                    {
                        return [
                            new class {
                                public function getData(): array
                                {
                                    return [0.1];
                                }
                            },
                            new class {
                                public function getData(): array
                                {
                                    return [0.2];
                                }
                            },
                        ];
                    }
                };
            }
        };
        $adapter = $this->makeAdapter('symfony.mistral', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->embed(['first', 'second']);

        self::assertSame(['first', 'second'], $platform->seenInput);
        self::assertSame([[0.1], [0.2]], $response->vectors);
    }

    public function testEmbedExtractsTokensFromInvokeMetadata(): void
    {
        $provider = $this->makeProvider(embeddingModelId: 'mistral-embed', adapterType: 'symfony.mistral');
        $platform = new class {
            public function invoke(string $model, mixed $input): object
            {
                return new class {
                    public function asVectors(): array
                    {
                        return [new class {
                            public function getData(): array
                            {
                                return [0.1, 0.2];
                            }
                        }];
                    }

                    public function getMetadata(): object
                    {
                        return new class {
                            public function get(string $key): object
                            {
                                return new class {
                                    public function getPromptTokens(): int
                                    {
                                        return 42;
                                    }

                                    public function getCompletionTokens(): ?int
                                    {
                                        return null;
                                    }

                                    public function getTotalTokens(): int
                                    {
                                        return 42;
                                    }
                                };
                            }
                        };
                    }

                    public function getResult(): object
                    {
                        return new class {
                            public function getMetadata(): object
                            {
                                return new class {
                                    public function get(string $key): ?object
                                    {
                                        return null;
                                    }
                                };
                            }
                        };
                    }

                    public function getRawResult(): object
                    {
                        return new class {
                            public function getData(): array
                            {
                                return [
                                    'data' => [['embedding' => [0.1, 0.2]]],
                                    'usage' => ['prompt_tokens' => 42, 'total_tokens' => 42],
                                ];
                            }
                        };
                    }
                };
            }
        };
        $adapter = $this->makeAdapter('symfony.mistral', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->embed('hello');

        self::assertSame(42, $response->tokensInput);
        self::assertSame(['prompt_tokens' => 42, 'total_tokens' => 42], $response->raw['usage'] ?? []);
    }

    public function testEmbedExtractsTokensViaResultConverter(): void
    {
        $provider = $this->makeProvider(embeddingModelId: 'mistral-embed', adapterType: 'symfony.mistral');
        $platform = new class {
            public function invoke(string $model, mixed $input): object
            {
                return new class {
                    public function asVectors(): array
                    {
                        return [[0.1, 0.2]];
                    }

                    public function getMetadata(): object
                    {
                        return new class {
                            public function get(string $key): ?object
                            {
                                return null;
                            }
                        };
                    }

                    public function getResult(): object
                    {
                        return new class {
                            public function getMetadata(): object
                            {
                                return new class {
                                    public function get(string $key): ?object
                                    {
                                        return null;
                                    }
                                };
                            }
                        };
                    }

                    public function getResultConverter(): object
                    {
                        return new class {
                            public function getTokenUsageExtractor(): object
                            {
                                return new class {
                                    public function extract(object $rawResult, array $options = []): object
                                    {
                                        return new class {
                                            public function getPromptTokens(): int
                                            {
                                                return 31;
                                            }

                                            public function getCompletionTokens(): ?int
                                            {
                                                return null;
                                            }

                                            public function getTotalTokens(): int
                                            {
                                                return 31;
                                            }
                                        };
                                    }
                                };
                            }
                        };
                    }

                    public function getRawResult(): object
                    {
                        return new class {
                            public function getData(): array
                            {
                                return ['data' => [['embedding' => [0.1, 0.2]]]];
                            }
                        };
                    }
                };
            }
        };
        $adapter = $this->makeAdapter('symfony.mistral', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->embed('hello');

        self::assertSame(31, $response->tokensInput);
    }

    public function testEmbedExtractsTokensFromNativeEmbedUsagePayload(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public function embed(string $model, string|array $text): array
            {
                return [
                    'data' => [['embedding' => [0.1, 0.2, 0.3]]],
                    'usage' => ['total_tokens' => 17, 'prompt_tokens' => 17],
                ];
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->embed('hello');

        self::assertSame(17, $response->tokensInput);
    }

    public function testEmbedNormalisesVectors(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public function embed(string $model, string|array $text): array
            {
                return [[0.1, 0.2, 0.3]];
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->embed('hello');

        self::assertSame([[0.1, 0.2, 0.3]], $response->vectors);
        self::assertSame('gpt-4o', $response->modelId);
    }

    public function testCompleteWithVisionExtraMessagesViaSymfonyBridgeSucceeds(): void
    {
        // Vision array content must become MessageBag(Text + ImageUrl), not a raw `messages`
        // payload (OpenAI Responses rejects `messages`) and not the string "Array".
        $provider = $this->makeProvider();
        $receivedPayloads = [];
        $platform = new class ($receivedPayloads) {
            /** @param list<mixed> $log */
            public function __construct(private array &$log) {}

            public function invoke(string $model, mixed $payload): string
            {
                $this->log[] = $payload;

                return 'alt text for the image';
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->complete(
            'Generate alt text',
            new AiOptions(extra: [
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Generate alt text'],
                        ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/img.jpg']],
                    ],
                ]],
            ]),
        );

        self::assertSame('alt text for the image', $response->content);
        self::assertNotEmpty($receivedPayloads);

        $first = $receivedPayloads[0];
        self::assertInstanceOf(\Symfony\AI\Platform\Message\MessageBag::class, $first);
        $userMessage = $first->getUserMessage();
        self::assertInstanceOf(\Symfony\AI\Platform\Message\UserMessage::class, $userMessage);
        $parts = $userMessage->getContent();
        self::assertCount(2, $parts);
        self::assertInstanceOf(\Symfony\AI\Platform\Message\Content\Text::class, $parts[0]);
        self::assertSame('Generate alt text', $parts[0]->getText());
        self::assertInstanceOf(\Symfony\AI\Platform\Message\Content\ImageUrl::class, $parts[1]);
        self::assertSame('https://example.com/img.jpg', $parts[1]->getUrl());

        foreach ($receivedPayloads as $payload) {
            self::assertNotSame('Array', $payload, 'Platform received the literal string "Array" — array cast bug regressed.');
            if (is_string($payload)) {
                self::assertStringNotContainsString('Array', $payload);
            }
        }
    }

    public function testCompleteWithStringMessagesUnchangedForTextCallers(): void
    {
        $provider = $this->makeProvider();
        $receivedPayloads = [];
        $platform = new class ($receivedPayloads) {
            /** @param list<mixed> $log */
            public function __construct(private array &$log) {}

            public function invoke(string $model, mixed $payload): string
            {
                $this->log[] = $payload;

                return 'text reply';
            }
        };
        $adapter = $this->makeAdapter('symfony.openai', $platform);
        $service = new AiService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$adapter]),
            new CapturingDispatcher(),
            $this->makeSiteStorageContext(),
        );

        $response = $service->complete(
            'Summarize this',
            new AiOptions(extra: [
                'messages' => [[
                    'role' => 'user',
                    'content' => 'Summarize this',
                ]],
            ]),
        );

        self::assertSame('text reply', $response->content);
        self::assertNotEmpty($receivedPayloads);
        $first = $receivedPayloads[0];
        self::assertInstanceOf(\Symfony\AI\Platform\Message\MessageBag::class, $first);
        $userMessage = $first->getUserMessage();
        self::assertInstanceOf(\Symfony\AI\Platform\Message\UserMessage::class, $userMessage);
        $parts = $userMessage->getContent();
        self::assertCount(1, $parts);
        self::assertInstanceOf(\Symfony\AI\Platform\Message\Content\Text::class, $parts[0]);
        self::assertSame('Summarize this', $parts[0]->getText());
    }

    private function makeAdapter(string $type, object $platform): AdapterInterface
    {
        return new class ($type, $platform) implements AdapterInterface {
            public function __construct(private string $type, private object $platform) {}
            public function getType(): string
            {
                return $this->type;
            }
            public function getDisplayName(): string
            {
                return 'mock';
            }
            public function getDefaultEndpoint(): string
            {
                return '';
            }
            public function getDefaultCapabilities(): array
            {
                return [Capability::CHAT];
            }
            public function testConnection(Provider $provider): VerifyResult
            {
                return VerifyResult::ok();
            }
            public function platform(Provider $provider): object
            {
                return $this->platform;
            }
        };
    }

    private function makeProvider(string $embeddingModelId = '', string $adapterType = 'symfony.openai'): Provider
    {
        return new Provider(
            uid: 1,
            pid: 0,
            identifier: 'openai-prod',
            title: 'OpenAI Prod',
            adapterType: $adapterType,
            endpointUrl: 'https://api.openai.com/v1',
            apiKeyCipher: '',
            modelId: 'gpt-4o',
            embeddingModelId: $embeddingModelId,
            capabilities: [Capability::CHAT, Capability::STREAMING],
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
