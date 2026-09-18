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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Contract\EmbeddingSourceInterface;
use NITSAN\NsT3AF\Agent\Embedding\EmbeddingSourceResolver;
use NITSAN\NsT3AF\Agent\Embedding\ProviderEmbeddingSource;
use NITSAN\NsT3AF\Agent\Service\AgentSettingsService;
use NITSAN\NsT3AF\Agent\Service\AgentToolEditorLabelService;
use NITSAN\NsT3AF\Agent\Service\AgentToolIndexService;
use NITSAN\NsT3AF\Agent\Service\PermittedActionProvider;
use NITSAN\NsT3AF\Cache\CacheFacadeInterface;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
final class AgentToolIndexServiceTest extends TestCase
{
    use AgentTranslatorTrait;

    #[Test]
    public function ensureFreshRebuildsWhenDefinitionHashChanges(): void
    {
        $toolA = $this->tool('pages_get', 'Get page');
        $toolB = $this->tool('pages_get', 'Get page with SEO fields');
        $tools = [$toolA];

        $cache = new InMemoryCacheFacade();
        $source = $this->stubSource();
        $settings = $this->settings(['agentEmbeddingSource' => 'provider']);
        $resolver = new EmbeddingSourceResolver($settings, [$source]);

        $introspector = $this->createMock(McpToolIntrospectorService::class);
        $introspector->method('listTools')->willReturnCallback(
            static function () use (&$tools): array {
                return $tools;
            },
        );

        $service = new AgentToolIndexService(
            $cache,
            $resolver,
            $introspector,
            $this->permittedActionProvider(),
            new AgentToolEditorLabelService($this->createAgentTranslator()),
        );

        $service->ensureFresh();
        $first = $cache->get('tool_index_v1');
        self::assertIsArray($first);
        $firstHash = (string) ($first['hash'] ?? '');
        self::assertNotSame('', $firstHash);

        $service->ensureFresh();
        $second = $cache->get('tool_index_v1');
        self::assertIsArray($second);
        self::assertSame($firstHash, (string) ($second['hash'] ?? ''));

        $tools = [$toolB];
        $service->ensureFresh();
        $third = $cache->get('tool_index_v1');
        self::assertIsArray($third);
        self::assertNotSame($firstHash, (string) ($third['hash'] ?? ''));
    }

    #[Test]
    public function searchReturnsSimilarityScoresFromCachedVectors(): void
    {
        $cache = new InMemoryCacheFacade();
        $source = $this->stubSource();
        $settings = $this->settings(['agentEmbeddingSource' => 'provider']);
        $resolver = new EmbeddingSourceResolver($settings, [$source]);

        $introspector = $this->createMock(McpToolIntrospectorService::class);
        $introspector->method('listTools')->willReturn([
            $this->tool('pages_get', 'Get page'),
            $this->tool('file_list', 'List files'),
        ]);

        $service = new AgentToolIndexService(
            $cache,
            $resolver,
            $introspector,
            $this->permittedActionProvider(),
            new AgentToolEditorLabelService($this->createAgentTranslator()),
        );

        $service->rebuild();
        $hits = $service->search(ProviderEmbeddingSource::ID, 'page', 5);

        self::assertNotSame([], $hits);
        self::assertArrayHasKey('name', $hits[0]);
        self::assertArrayHasKey('score', $hits[0]);
        self::assertGreaterThanOrEqual(0.0, $hits[0]['score']);
        self::assertLessThanOrEqual(1.0, $hits[0]['score']);
    }

    /**
     * @param array<string, string> $stored
     */
    private function settings(array $stored): AgentSettingsService
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn($stored);

        return new AgentSettingsService($extensionSettings);
    }

    private function permittedActionProvider(): PermittedActionProvider
    {
        return (new ReflectionClass(PermittedActionProvider::class))->newInstanceWithoutConstructor();
    }

    /**
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'severity' => 'read',
            'ownerExtensionKey' => 'ns_t3af',
            'intent' => [
                'category' => 'pages',
                'summary' => $description,
                'examples' => ['show page'],
                'verbs' => ['get'],
                'nouns' => ['page'],
            ],
        ];
    }

    private function stubSource(): EmbeddingSourceInterface
    {
        return new class implements EmbeddingSourceInterface {
            public function id(): string
            {
                return ProviderEmbeddingSource::ID;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function embed(string $text): array
            {
                $len = max(1, strlen($text));
                $a = ($len % 7) / 7.0;
                $b = (ord($text[0]) % 11) / 11.0;
                $c = (ord($text[min(1, $len - 1)]) % 13) / 13.0;

                return [$a, $b, $c];
            }
        };
    }
}

/**
 * @internal
 */
final class InMemoryCacheFacade implements CacheFacadeInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? false;
    }

    public function set(string $key, mixed $value, array $tags = [], ?int $lifetimeSeconds = null): void
    {
        $this->store[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->store[$key]);
    }

    public function flush(): void
    {
        $this->store = [];
    }
}
