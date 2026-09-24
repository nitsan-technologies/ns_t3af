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

use NITSAN\NsT3AF\Api\AiToolDefinition;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderLookupInterface;
use NITSAN\NsT3AF\Event\AfterProviderResponseEvent;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
use NITSAN\NsT3AF\Provider\Contract\ToolCallingCapableInterface;
use NITSAN\NsT3AF\Provider\Contract\VerifyResult;
use NITSAN\NsT3AF\Service\AiToolCallingService;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

final class AiToolCallingServiceTest extends TestCase
{
    public function testSupportsToolCallingIsFalseForNonCapableAdapter(): void
    {
        $provider = $this->makeProvider('plain.text');

        $providers = $this->createMock(ProviderLookupInterface::class);
        $providers->method('findDefault')->willReturn($provider);

        $registry = new AdapterRegistry([$this->makePlainAdapter('plain.text')]);
        $events = $this->createMock(EventDispatcherInterface::class);
        $siteFinder = $this->createMock(SiteFinder::class);
        $context = new SiteStorageContext($siteFinder);

        $service = new AiToolCallingService($providers, $registry, $events, $context);

        self::assertFalse($service->supportsToolCalling());
    }

    public function testCompleteWithToolsFailsLoudlyForNonCapableAdapter(): void
    {
        $provider = $this->makeProvider('plain.text');

        $providers = $this->createMock(ProviderLookupInterface::class);
        $providers->method('findDefault')->willReturn($provider);

        $registry = new AdapterRegistry([$this->makePlainAdapter('plain.text')]);
        $events = $this->createMock(EventDispatcherInterface::class);
        $siteFinder = $this->createMock(SiteFinder::class);
        $context = new SiteStorageContext($siteFinder);

        $service = new AiToolCallingService($providers, $registry, $events, $context);

        $this->expectException(AdapterRuntimeException::class);
        $service->completeWithTools([], []);
    }

    public function testCancelledRequestNeverReachesTheProvider(): void
    {
        $platform = new class {
            public int $calls = 0;

            /**
             * @param list<array<string, mixed>> $messages
             * @param list<array<string, mixed>> $tools
             *
             * @return array<string, mixed>
             */
            public function invokeWithTools(string $modelId, array $messages, array $tools): array
            {
                ++$this->calls;

                return ['content' => 'should not happen'];
            }
        };

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnCallback(static function (object $event): object {
            if ($event instanceof BeforeProviderRequestEvent) {
                $event->cancelWithReason('Daily AI request limit reached for your backend user group.');
            }

            return $event;
        });

        $service = $this->makeToolCallingService($platform, $events);
        $response = $service->completeWithTools([['role' => 'user', 'content' => 'Hi']], []);

        self::assertSame(0, $platform->calls);
        self::assertSame('', $response->content);
        self::assertSame([], $response->toolCalls);
        self::assertSame('Daily AI request limit reached for your backend user group.', $response->raw['cancelled'] ?? null);
    }

    public function testSuccessfulRoundDispatchesAfterResponseEventWithUsage(): void
    {
        $platform = new class {
            /**
             * @param list<array<string, mixed>> $messages
             * @param list<array<string, mixed>> $tools
             *
             * @return array<string, mixed>
             */
            public function invokeWithTools(string $modelId, array $messages, array $tools): array
            {
                return [
                    'content' => '',
                    'toolCalls' => [['id' => 'call_1', 'name' => 'pages_get', 'arguments' => ['uid' => 3]]],
                    'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 7],
                    'raw' => [],
                ];
            }
        };

        $dispatched = [];
        $events = $this->createMock(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatched): object {
            $dispatched[] = $event;

            return $event;
        });

        $service = $this->makeToolCallingService($platform, $events);
        $response = $service->completeWithTools(
            [['role' => 'user', 'content' => 'Show page 3']],
            [new AiToolDefinition('pages_get', 'Get a page', ['type' => 'object', 'properties' => []])],
        );

        self::assertCount(1, $response->toolCalls);
        self::assertSame('pages_get', $response->toolCalls[0]->name);

        $after = array_values(array_filter($dispatched, static fn(object $e): bool => $e instanceof AfterProviderResponseEvent));
        self::assertCount(1, $after);
        self::assertSame(120, $after[0]->getResponse()->tokensInput);
        self::assertSame(7, $after[0]->getResponse()->tokensOutput);
    }

    private function makeToolCallingService(object $platform, EventDispatcherInterface $events): AiToolCallingService
    {
        $provider = $this->makeProvider('tools.capable');
        $providers = $this->createMock(ProviderLookupInterface::class);
        $providers->method('findDefault')->willReturn($provider);

        $adapter = new class ($platform) implements ToolCallingCapableInterface {
            public function __construct(private readonly object $platform) {}

            public function getType(): string
            {
                return 'tools.capable';
            }

            public function getDisplayName(): string
            {
                return 'Tools';
            }

            public function getDefaultEndpoint(): string
            {
                return '';
            }

            public function getDefaultCapabilities(): array
            {
                return [];
            }

            public function testConnection(Provider $provider): VerifyResult
            {
                return VerifyResult::failure('unsupported');
            }

            public function platform(Provider $provider): object
            {
                return $this->platform;
            }

            public function supportsToolCalling(Provider $provider): bool
            {
                return true;
            }
        };

        return new AiToolCallingService(
            $providers,
            new AdapterRegistry([$adapter]),
            $events,
            new SiteStorageContext($this->createMock(SiteFinder::class)),
        );
    }

    private function makeProvider(string $adapterType): Provider
    {
        return new Provider(
            uid: 1,
            pid: 0,
            identifier: 'demo',
            title: 'Demo',
            adapterType: $adapterType,
            endpointUrl: '',
            apiKeyCipher: '',
            modelId: 'gpt-4o',
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

    private function makePlainAdapter(string $type): AdapterInterface
    {
        return new class ($type) implements AdapterInterface {
            public function __construct(private readonly string $type) {}

            public function getType(): string
            {
                return $this->type;
            }

            public function getDisplayName(): string
            {
                return $this->type;
            }

            public function getDefaultEndpoint(): string
            {
                return '';
            }

            public function getDefaultCapabilities(): array
            {
                return [];
            }

            public function testConnection(Provider $provider): VerifyResult
            {
                return VerifyResult::failure('unsupported');
            }

            public function platform(Provider $provider): object
            {
                return new \stdClass();
            }
        };
    }
}
