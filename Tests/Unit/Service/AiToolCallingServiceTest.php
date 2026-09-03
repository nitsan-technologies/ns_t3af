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

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderLookupInterface;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
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
