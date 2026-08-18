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

namespace NITSAN\NsT3AF\Tests\Unit\Provider\Model;

use NITSAN\NsT3AF\Cache\CacheFacadeInterface;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Model\CapabilityInferrer;
use NITSAN\NsT3AF\Provider\Model\LiveModelProbe;
use NITSAN\NsT3AF\Provider\Model\ModelCatalogFilter;
use NITSAN\NsT3AF\Provider\Model\ModelDiscoveryService;
use NITSAN\NsT3AF\Provider\Model\ModelInfo;
use NITSAN\NsT3AF\Provider\Model\SymfonyAiCatalogReader;
use PHPUnit\Framework\TestCase;

final class ModelDiscoveryServiceTest extends TestCase
{
    public function testCacheHitSkipsProbeAndReader(): void
    {
        $cache = $this->createMock(CacheFacadeInterface::class);
        $cache->expects(self::once())->method('get')->willReturn([[
            'id' => 'gpt-4o',
            'label' => 'gpt-4o',
            'capabilities' => ['chat'],
            'source' => 'live',
        ]]);
        $cache->expects(self::never())->method('set');

        $probe = $this->createMock(LiveModelProbe::class);
        $probe->expects(self::never())->method('probe');
        $reader = $this->createMock(SymfonyAiCatalogReader::class);
        $reader->expects(self::never())->method('read');

        $service = new ModelDiscoveryService($probe, $reader, new CapabilityInferrer(), $cache, new ModelCatalogFilter());
        $models = $service->discover($this->provider('symfony.openai'));

        self::assertCount(1, $models);
        self::assertSame('gpt-4o', $models[0]->id);
    }

    public function testRefreshBypassesCache(): void
    {
        $cache = $this->createMock(CacheFacadeInterface::class);
        $cache->expects(self::never())->method('get');
        $cache->expects(self::once())->method('set');

        $probe = $this->createMock(LiveModelProbe::class);
        $probe->expects(self::once())->method('probe')->willReturn(['gpt-4o-mini']);
        $reader = $this->createMock(SymfonyAiCatalogReader::class);
        $reader->expects(self::once())->method('read')->willReturn([]);

        $service = new ModelDiscoveryService($probe, $reader, new CapabilityInferrer(), $cache, new ModelCatalogFilter());
        $models = $service->discover($this->provider('symfony.openai'), refresh: true);

        self::assertCount(1, $models);
        self::assertSame('gpt-4o-mini', $models[0]->id);
        self::assertSame('live', $models[0]->source);
        self::assertContains(Capability::VISION, $models[0]->capabilities);
    }

    public function testMergesCatalogAndLiveWithLiveWinning(): void
    {
        $cache = $this->createMock(CacheFacadeInterface::class);
        $cache->method('get')->willReturn(false);

        $probe = $this->createMock(LiveModelProbe::class);
        $probe->method('probe')->willReturn(['gpt-4o', 'gpt-4-new']);
        $reader = $this->createMock(SymfonyAiCatalogReader::class);
        $reader->method('read')->willReturn([
            new ModelInfo('gpt-4o', 'GPT-4 Omni', [Capability::CHAT, Capability::VISION], 'catalog'),
            new ModelInfo('text-embedding-3', 'Embedding v3', [Capability::EMBEDDINGS], 'catalog'),
        ]);

        $service = new ModelDiscoveryService($probe, $reader, new CapabilityInferrer(), $cache, new ModelCatalogFilter());
        $models = $service->discover($this->provider('symfony.openai'));

        $ids = array_map(static fn(ModelInfo $m): string => $m->id, $models);
        self::assertContains('gpt-4o', $ids);
        self::assertContains('gpt-4-new', $ids);
        self::assertContains('text-embedding-3', $ids);

        $byId = [];
        foreach ($models as $m) {
            $byId[$m->id] = $m;
        }
        self::assertSame('live', $byId['gpt-4o']->source);
        self::assertSame([Capability::CHAT, Capability::VISION], $byId['gpt-4o']->capabilities);
        self::assertSame('live', $byId['gpt-4-new']->source);
        self::assertSame('catalog', $byId['text-embedding-3']->source);
    }

    public function testNonSymfonyAdapterReadsNoCatalog(): void
    {
        $cache = $this->createMock(CacheFacadeInterface::class);
        $cache->method('get')->willReturn(false);
        $probe = $this->createMock(LiveModelProbe::class);
        $probe->method('probe')->willReturn([]);
        $reader = $this->createMock(SymfonyAiCatalogReader::class);
        $reader->expects(self::never())->method('read');

        $service = new ModelDiscoveryService($probe, $reader, new CapabilityInferrer(), $cache, new ModelCatalogFilter());
        $models = $service->discover($this->provider('custom.azure'));

        self::assertSame([], $models);
    }

    public function testMergeFiltersRetiredCatalogModels(): void
    {
        $cache = $this->createMock(CacheFacadeInterface::class);
        $cache->method('get')->willReturn(false);

        $probe = $this->createMock(LiveModelProbe::class);
        $probe->method('probe')->willReturn([]);
        $reader = $this->createMock(SymfonyAiCatalogReader::class);
        $reader->method('read')->willReturn([
            new ModelInfo('claude-sonnet-4-20250514', 'retired', [Capability::CHAT], 'catalog'),
            new ModelInfo('claude-sonnet-4-5-20250929', 'current', [Capability::CHAT], 'catalog'),
            new ModelInfo('gemini-2.5-flash-lite-preview-09-2025', 'preview', [Capability::CHAT], 'catalog'),
            new ModelInfo('gemini-2.5-pro', 'pro', [Capability::CHAT], 'catalog'),
        ]);

        $service = new ModelDiscoveryService($probe, $reader, new CapabilityInferrer(), $cache, new ModelCatalogFilter());
        $models = $service->discover($this->provider('symfony.anthropic'));

        $ids = array_map(static fn(ModelInfo $m): string => $m->id, $models);
        self::assertSame(['claude-sonnet-4-5-20250929', 'gemini-2.5-pro'], $ids);
    }

    private function provider(string $adapterType): Provider
    {
        return new Provider(
            uid: 42,
            pid: 0,
            identifier: 'test',
            title: 'Test',
            adapterType: $adapterType,
            endpointUrl: 'https://example.com',
            apiKeyCipher: '',
            modelId: '',
            embeddingModelId: '',
            capabilities: [],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: false,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: '',
            lastStatusAt: 0,
            lastStatusMessage: '',
            beGroups: [],
        );
    }
}
