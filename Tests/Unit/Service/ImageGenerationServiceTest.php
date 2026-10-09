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

use NITSAN\NsT3AF\Api\ImageGenerationOptions;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderLookupInterface;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Service\ImageGenerationService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class ImageGenerationServiceTest extends TestCase
{
    public function testGenerateDispatchesBeforeEventAndHonorsCancellation(): void
    {
        $provider = $this->makeProvider();
        $dispatcher = new class implements EventDispatcherInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $event): object
            {
                $this->dispatched[] = $event;
                if ($event instanceof BeforeProviderRequestEvent) {
                    $event->cancelWithReason('blocked by ACL');
                }

                return $event;
            }
        };

        $service = new ImageGenerationService(
            $this->makeLookup($provider),
            new AdapterRegistry([$this->makeAdapter()]),
            $dispatcher,
            new CredentialCipher(),
            $this->requestFactoryStub(),
        );

        $this->expectException(AdapterRuntimeException::class);
        $this->expectExceptionMessage('blocked by ACL');

        try {
            $service->generate('a logo');
        } finally {
            self::assertCount(1, $dispatcher->dispatched);
            self::assertInstanceOf(BeforeProviderRequestEvent::class, $dispatcher->dispatched[0]);
            self::assertSame('image_generation', $dispatcher->dispatched[0]->callKind);
        }
    }

    public function testGenerateUsesMutatedPromptFromBeforeEvent(): void
    {
        $provider = $this->makeProvider();
        $platform = new class {
            public ?string $seenPrompt = null;

            /**
             * @return list<array{url: string}>
             */
            public function generateImages(string $model, string $prompt, ImageGenerationOptions $options): array
            {
                $this->seenPrompt = $prompt;

                return [['url' => 'https://example.test/img.png']];
            }

            /** @return list<array{url: string}> */
            public function createImageVariation(string $model, string $path, ImageGenerationOptions $options): array
            {
                return [];
            }
        };

        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof BeforeProviderRequestEvent) {
                    $event->setPrompt($event->getPrompt() . ' [brand]');
                }

                return $event;
            }
        };

        $service = new ImageGenerationService(
            $this->makeLookup($provider),
            new AdapterRegistry([$this->makeAdapter($platform)]),
            $dispatcher,
            new CredentialCipher(),
            $this->requestFactoryStub(),
        );

        $response = $service->generate('draw a cat');

        self::assertSame('draw a cat [brand]', $platform->seenPrompt);
        self::assertCount(1, $response->images);
    }

    public function testGenerateUsesTheOpenAiImageModelInsteadOfTheChatModel(): void
    {
        $platform = $this->recordingPlatform();
        $response = $this->service($this->makeProvider('gpt-5.4-mini'), $platform)->generate('A small red lighthouse');

        self::assertSame(['gpt-image-1'], $platform->models);
        self::assertSame('gpt-image-1', $response->modelId);
    }

    public function testVariationResolvesTheModelTheSameWay(): void
    {
        $platform = $this->recordingPlatform();
        $this->service($this->makeProvider('gpt-5.4-mini'), $platform)->variation('/tmp/source.png');

        self::assertSame(['gpt-image-1'], $platform->models);
    }

    public function testRequestedOrStoredImageModelWins(): void
    {
        $platform = $this->recordingPlatform();
        $this->service($this->makeProvider('gpt-5.4-mini'), $platform)->generate('cat', new ImageGenerationOptions(modelId: 'dall-e-3'));
        $this->service($this->makeProvider('gpt-image-1-mini'), $platform)->generate('cat');

        self::assertSame(['dall-e-3', 'gpt-image-1-mini'], $platform->models);
    }

    public function testChatModelWithoutAnImageDefaultFailsBeforeTheRequest(): void
    {
        $platform = $this->recordingPlatform();

        try {
            $this->service($this->makeProvider('llama3.1', Provider::ADAPTER_OPENAI_COMPATIBLE), $platform)->generate('cat');
            self::fail('Expected an exception');
        } catch (AdapterRuntimeException $e) {
            self::assertStringContainsString('No image model configured for provider "OpenAI"', $e->getMessage());
        }
        self::assertSame([], $platform->models);
    }

    /**
     * @return object{models: list<string>}
     */
    private function recordingPlatform(): object
    {
        return new class {
            /** @var list<string> */
            public array $models = [];

            /** @return list<array{url: string}> */
            public function generateImages(string $model, string $prompt, ImageGenerationOptions $options): array
            {
                $this->models[] = $model;

                return [['url' => 'https://example.test/img.png']];
            }

            /** @return list<array{url: string}> */
            public function createImageVariation(string $model, string $path, ImageGenerationOptions $options): array
            {
                $this->models[] = $model;

                return [['url' => 'https://example.test/img.png']];
            }
        };
    }

    private function service(Provider $provider, object $platform): ImageGenerationService
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('getType')->willReturn($provider->adapterType);
        $adapter->method('platform')->willReturn($platform);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        return new ImageGenerationService(
            $this->makeLookup($provider),
            new AdapterRegistry([$adapter]),
            $dispatcher,
            new CredentialCipher(),
            $this->requestFactoryStub(),
        );
    }

    private function requestFactoryStub(): RequestFactory
    {
        return $this->getMockBuilder(RequestFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function makeLookup(Provider $provider): ProviderLookupInterface
    {
        $lookup = $this->createMock(ProviderLookupInterface::class);
        $lookup->method('findDefault')->willReturn($provider);
        $lookup->method('findByIdentifier')->willReturn($provider);

        return $lookup;
    }

    private function makeAdapter(?object $platform = null): AdapterInterface
    {
        $platform ??= new class {
            /** @return list<array{url: string}> */
            public function generateImages(string $model, string $prompt, ImageGenerationOptions $options): array
            {
                return [['url' => 'https://example.test/img.png']];
            }

            /** @return list<array{url: string}> */
            public function createImageVariation(string $model, string $path, ImageGenerationOptions $options): array
            {
                return [];
            }
        };

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('getType')->willReturn('symfony.openai');
        $adapter->method('platform')->willReturn($platform);

        return $adapter;
    }

    private function makeProvider(string $modelId = 'dall-e-3', string $adapterType = 'symfony.openai'): Provider
    {
        return Provider::fromRow([
            'uid' => 1,
            'pid' => 10,
            'identifier' => 'openai',
            'title' => 'OpenAI',
            'adapter_type' => $adapterType,
            'endpoint_url' => $adapterType === 'symfony.openai' ? '' : 'https://llm.internal/v1',
            'model_id' => $modelId,
            'embedding_model_id' => '',
            'system_prompt' => '',
            'temperature' => 0.7,
            'priority' => 0,
            'is_enabled' => 1,
            'is_default' => 1,
            'capabilities' => Capability::IMAGE_GENERATION,
            'cost_center' => '',
            'be_groups' => '',
            'privacy_level' => 'standard',
            'no_rerouting' => 0,
            'enabled_for_dashboard' => 1,
            'last_used_at' => 0,
        ]);
    }
}
