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

use NITSAN\NsT3AF\Agent\Contract\AgentToolIndexInterface;
use NITSAN\NsT3AF\Agent\Contract\EmbeddingSourceInterface;
use NITSAN\NsT3AF\Agent\Embedding\EmbeddingSourceResolver;
use NITSAN\NsT3AF\Agent\Embedding\ProviderEmbeddingSource;
use NITSAN\NsT3AF\Agent\Service\AgentSettingsService;
use NITSAN\NsT3AF\Agent\Service\AgentToolShortlistService;
use NITSAN\NsT3AF\Api\AiResponse;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentToolShortlistServiceTest extends TestCase
{
    #[Test]
    public function alwaysOnToolsAreIncludedWhenPresentInCatalog(): void
    {
        $catalog = [
            $this->tool('pages_get', 'pages'),
            $this->tool('ask_clarification', 'general'),
            $this->tool('explain_capabilities', 'general'),
            $this->tool('content_list', 'content'),
        ];

        $index = $this->createMock(AgentToolIndexInterface::class);
        $index->expects(self::once())->method('ensureFresh');
        $index->method('search')->willReturn([
            ['name' => 'content_list', 'score' => 0.9],
            ['name' => 'pages_get', 'score' => 0.8],
        ]);

        $service = $this->createService(
            index: $index,
            embeddingSource: $this->stubSource(ProviderEmbeddingSource::ID),
            embeddingMode: 'provider',
        );

        $result = $service->shortlist('list content', ['module' => 'web_layout'], $catalog);

        self::assertSame('embeddings', $result['routingSource']);
        $names = array_column($result['tools'], 'name');
        self::assertContains('ask_clarification', $names);
        self::assertContains('explain_capabilities', $names);
        self::assertContains('content_list', $names);
    }

    #[Test]
    public function permissionFilterDropsHitsNotInExecutableCatalog(): void
    {
        $catalog = [
            $this->tool('pages_get', 'pages'),
            $this->tool('ask_clarification', 'general'),
        ];

        $index = $this->createMock(AgentToolIndexInterface::class);
        $index->method('search')->willReturn([
            ['name' => 'secret_admin_tool', 'score' => 0.99],
            ['name' => 'pages_get', 'score' => 0.5],
        ]);

        $service = $this->createService(
            index: $index,
            embeddingSource: $this->stubSource(ProviderEmbeddingSource::ID),
            embeddingMode: 'provider',
        );

        $result = $service->shortlist('inspect page', [], $catalog);
        $names = array_column($result['tools'], 'name');

        self::assertContains('pages_get', $names);
        self::assertNotContains('secret_admin_tool', $names);
    }

    #[Test]
    public function categoryPickFallbackWhenEmbeddingsUnavailable(): void
    {
        $catalog = [
            $this->tool('pages_get', 'pages'),
            $this->tool('content_list', 'content'),
            $this->tool('file_list', 'media_files'),
            $this->tool('ask_clarification', 'general'),
        ];

        $index = $this->createMock(AgentToolIndexInterface::class);
        $index->expects(self::never())->method('search');

        $ai = $this->createMock(AiServiceInterface::class);
        $ai->method('complete')->willReturn(new AiResponse(
            content: '{"categories":["pages","content"]}',
            modelId: 'test',
            providerIdentifier: 'test',
        ));

        $service = $this->createService(
            index: $index,
            embeddingSource: null,
            embeddingMode: EmbeddingSourceResolver::MODE_NONE,
            ai: $ai,
        );

        $result = $service->shortlist('open the page tree', ['module' => 'file'], $catalog);

        self::assertSame('category_pick', $result['routingSource']);
        $names = array_column($result['tools'], 'name');
        self::assertContains('pages_get', $names);
        self::assertContains('content_list', $names);
        self::assertContains('ask_clarification', $names);
        self::assertNotContains('file_list', $names);
    }

    #[Test]
    public function continuityKeepsToolsFromLastTwoTurns(): void
    {
        $catalog = [
            $this->tool('pages_get', 'pages'),
            $this->tool('content_list', 'content'),
            $this->tool('ask_clarification', 'general'),
        ];

        $index = $this->createMock(AgentToolIndexInterface::class);
        $index->method('search')->willReturn([
            ['name' => 'pages_get', 'score' => 0.7],
        ]);

        $service = $this->createService(
            index: $index,
            embeddingSource: $this->stubSource(ProviderEmbeddingSource::ID),
            embeddingMode: 'provider',
        );

        $history = [
            ['role' => 'assistant', 'content' => 'ok', 'meta' => ['tool' => 'content_list']],
            ['role' => 'assistant', 'content' => 'ok', 'meta' => ['tool' => 'pages_get']],
        ];

        $result = $service->shortlist('continue', [], $catalog, $history);
        $names = array_column($result['tools'], 'name');

        self::assertContains('content_list', $names);
        self::assertContains('pages_get', $names);
    }

    private function createService(
        AgentToolIndexInterface $index,
        ?EmbeddingSourceInterface $embeddingSource,
        string $embeddingMode,
        ?AiServiceInterface $ai = null,
    ): AgentToolShortlistService {
        $settings = $this->settings([
            'agentEmbeddingSource' => $embeddingMode,
            'agentShortlistSize' => '12',
            'agentMinSimilarity' => '0',
        ]);
        $sources = $embeddingSource !== null ? [$embeddingSource] : [];
        $resolver = new EmbeddingSourceResolver($settings, $sources);
        $ai ??= $this->createMock(AiServiceInterface::class);

        return new AgentToolShortlistService($index, $resolver, $settings, $ai);
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

    private function stubSource(string $id): EmbeddingSourceInterface
    {
        return new class ($id) implements EmbeddingSourceInterface {
            public function __construct(private readonly string $sourceId) {}

            public function id(): string
            {
                return $this->sourceId;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function embed(string $text): array
            {
                return [0.1, 0.2, 0.3];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function tool(string $name, string $category): array
    {
        return [
            'name' => $name,
            'description' => $name,
            'severity' => 'read',
            'intent' => [
                'category' => $category,
                'summary' => $name,
                'examples' => [],
                'verbs' => [],
                'nouns' => [],
            ],
        ];
    }
}
