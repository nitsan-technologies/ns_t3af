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

use NITSAN\NsT3AF\Api\TtsOptions;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Event\AfterProviderResponseEvent;
use NITSAN\NsT3AF\Event\AfterTtsResponseEvent;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Event\BeforeTtsRequestEvent;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\UnknownAdapterException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
use NITSAN\NsT3AF\Provider\Contract\VerifyResult;
use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Service\TtsService;
use NITSAN\NsT3AF\Tests\Unit\Service\Fixture\CapturingDispatcher;
use NITSAN\NsT3AF\Tests\Unit\Service\Fixture\StaticProviderLookup;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\RequestFactory;

final class TtsServiceTest extends TestCase
{
    public function testSpeakCallsPlatformSpeechAndReturnsResponse(): void
    {
        $provider = $this->makeProvider([Capability::TTS]);
        $platform = new class {
            public function speech(string $modelId, string $text, TtsOptions $options): string
            {
                return 'binary-audio-data';
            }
        };
        $events = new CapturingDispatcher();
        $service = $this->makeService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$this->makeAdapter($platform)]),
            $events,
        );

        $response = $service->speak('Hello world', new TtsOptions(format: 'mp3'));

        self::assertSame('binary-audio-data', $response->audio);
        self::assertSame('audio/mpeg', $response->mimeType);
        self::assertSame('gpt-4o', $response->modelId);
        self::assertSame('openai-prod', $response->providerIdentifier);

        $kinds = array_map(static fn(object $e): string => $e::class, $events->dispatched);
        self::assertContains(BeforeProviderRequestEvent::class, $kinds);
        self::assertContains(BeforeTtsRequestEvent::class, $kinds);
        self::assertContains(AfterTtsResponseEvent::class, $kinds);
        self::assertContains(AfterProviderResponseEvent::class, $kinds);
    }

    public function testSpeakHonorsGovernanceCancellation(): void
    {
        $provider = $this->makeProvider([Capability::TTS]);
        $platform = new class {
            public bool $called = false;

            public function speech(string $modelId, string $text, TtsOptions $options): string
            {
                $this->called = true;

                return 'binary-audio-data';
            }
        };
        $events = new class implements \Psr\EventDispatcher\EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof BeforeProviderRequestEvent) {
                    $event->cancelWithReason('Budget exhausted.');
                }

                return $event;
            }
        };
        $service = new TtsService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$this->makeAdapter($platform)]),
            $events,
            new CredentialCipher(),
            $this->createMock(RequestFactory::class),
        );

        $response = $service->speak('Hello world');

        self::assertSame('', $response->audio);
        self::assertFalse($platform->called);
    }

    public function testSpeakThrowsWhenProviderLacksTtsCapability(): void
    {
        $provider = $this->makeProvider([Capability::CHAT]);
        $platform = new class {
            public function speech(string $modelId, string $text, TtsOptions $options): string
            {
                return '';
            }
        };
        $service = $this->makeService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$this->makeAdapter($platform)]),
        );

        $this->expectException(AdapterRuntimeException::class);
        $this->expectExceptionMessage(Capability::TTS);
        $service->speak('Hello');
    }

    public function testSpeakFallsBackToOpenAiCompatibleWhenAdapterPlatformLacksSpeech(): void
    {
        $provider = $this->makeProvider([Capability::TTS]);
        $platform = new class {
            public function invoke(string $model, mixed $payload): string
            {
                return 'text';
            }
        };
        $service = $this->makeService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$this->makeAdapter($platform)]),
        );

        $this->expectException(AdapterRuntimeException::class);
        $this->expectExceptionMessage('audio/speech');
        $service->speak('Hello');
    }

    public function testSpeakThrowsWhenNoDefaultProvider(): void
    {
        $service = $this->makeService(
            new StaticProviderLookup(null),
            new AdapterRegistry(),
        );

        $this->expectException(UnknownAdapterException::class);
        $service->speak('Hello');
    }

    public function testSpeakUsesOpusFormatMimeType(): void
    {
        $provider = $this->makeProvider([Capability::TTS]);
        $platform = new class {
            public function speech(string $modelId, string $text, TtsOptions $options): string
            {
                return 'ogg-data';
            }
        };
        $service = $this->makeService(
            new StaticProviderLookup($provider),
            new AdapterRegistry([$this->makeAdapter($platform)]),
        );

        $response = $service->speak('Hello', new TtsOptions(format: 'opus'));

        self::assertSame('audio/ogg', $response->mimeType);
    }

    private function makeService(
        StaticProviderLookup $lookup,
        AdapterRegistry $adapters,
        ?CapturingDispatcher $events = null,
    ): TtsService {
        return new TtsService(
            $lookup,
            $adapters,
            $events ?? new CapturingDispatcher(),
            new CredentialCipher(),
            $this->createMock(RequestFactory::class),
        );
    }

    /** @param list<string> $capabilities */
    private function makeProvider(array $capabilities): Provider
    {
        return new Provider(
            uid: 1,
            pid: 0,
            identifier: 'openai-prod',
            title: 'OpenAI Prod',
            adapterType: 'symfony.openai',
            endpointUrl: 'https://api.openai.com/v1',
            apiKeyCipher: '',
            modelId: 'gpt-4o',
            embeddingModelId: '',
            capabilities: $capabilities,
            temperature: 1.0,
            systemPrompt: '',
            isDefault: true,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: '',
            lastStatusAt: 0,
            lastStatusMessage: '',
        );
    }

    private function makeAdapter(object $platform): AdapterInterface
    {
        return new class ($platform) implements AdapterInterface {
            public function __construct(private object $platform) {}

            public function getType(): string
            {
                return 'symfony.openai';
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
                return [Capability::TTS];
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
}
