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

use NITSAN\NsT3AF\Agent\Contract\AgentActionCatalogInterface;
use NITSAN\NsT3AF\Agent\Contract\AgentToolIndexInterface;
use NITSAN\NsT3AF\Agent\Contract\AgentToolTurnExecutorInterface;
use NITSAN\NsT3AF\Agent\Contract\EmbeddingSourceInterface;
use NITSAN\NsT3AF\Agent\Embedding\EmbeddingSourceResolver;
use NITSAN\NsT3AF\Agent\Embedding\ProviderEmbeddingSource;
use NITSAN\NsT3AF\Agent\Service\AgentLanguageResolver;
use NITSAN\NsT3AF\Agent\Service\AgentLowRiskFieldMatrix;
use NITSAN\NsT3AF\Agent\Service\AgentSettingsService;
use NITSAN\NsT3AF\Agent\Service\AgentToolDefinitionMapper;
use NITSAN\NsT3AF\Agent\Service\AgentToolShortlistService;
use NITSAN\NsT3AF\Agent\Service\AgentTranslator;
use NITSAN\NsT3AF\Agent\Service\AgentTurnOrchestrator;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use NITSAN\NsT3AF\Api\AiToolCallingServiceInterface;
use NITSAN\NsT3AF\Service\BrandContextAssembler;
use NITSAN\NsT3AF\Service\BrandContextResolver;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * @internal
 */
final class AgentTurnOrchestratorDirectToolTest extends TestCase
{
    #[Test]
    public function runTurnSkipsLlmWhenDirectToolIsExplainCapabilities(): void
    {
        $toolCalling = $this->createMock(AiToolCallingServiceInterface::class);
        $toolCalling->method('supportsToolCalling')->willReturn(true);
        $toolCalling->expects(self::never())->method('completeWithTools');

        $catalog = $this->createMock(AgentActionCatalogInterface::class);
        $catalog->method('buildCatalog')->willReturn([
            'executable' => [
                [
                    'name' => 'explain_capabilities',
                    'severity' => 'read',
                    'description' => 'Explain',
                    'intent' => ['category' => 'general', 'summary' => '', 'examples' => [], 'verbs' => [], 'nouns' => []],
                ],
                [
                    'name' => 'ask_clarification',
                    'severity' => 'read',
                    'description' => 'Ask',
                    'intent' => ['category' => 'general', 'summary' => '', 'examples' => [], 'verbs' => [], 'nouns' => []],
                ],
            ],
            'locked' => [],
        ]);

        $index = $this->createMock(AgentToolIndexInterface::class);
        $index->method('ensureFresh');
        $index->method('search')->willReturn([
            ['name' => 'explain_capabilities', 'score' => 0.99],
        ]);

        $embeddingSource = new class implements EmbeddingSourceInterface {
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
                return [0.1, 0.2];
            }
        };

        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn([
            'agentEmbeddingSource' => 'provider',
            'agentShortlistSize' => '12',
            'agentMinSimilarity' => '0',
            'agentMaxReadToolsPerTurn' => '5',
            'agentMaxWriteDraftsPerTurn' => '2',
        ]);
        $settings = new AgentSettingsService($extensionSettings);
        $shortlist = new AgentToolShortlistService(
            $index,
            new EmbeddingSourceResolver($settings, [$embeddingSource]),
            $settings,
            $this->createMock(AiServiceInterface::class),
        );

        $processor = $this->createMock(AgentToolTurnExecutorInterface::class);
        $processor->expects(self::once())
            ->method('execute')
            ->with('explain_capabilities', self::anything(), self::anything(), self::anything(), 'corr-direct')
            ->willReturn([
                'role' => 'assistant',
                'content' => "On this page I can help with:\n- Inspect this page",
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 'explain_capabilities',
                    'correlationId' => 'corr-direct',
                ],
            ]);

        $orchestrator = new AgentTurnOrchestrator(
            $toolCalling,
            $catalog,
            (new \ReflectionClass(AgentToolDefinitionMapper::class))->newInstanceWithoutConstructor(),
            $shortlist,
            $processor,
            $settings,
            (new \ReflectionClass(BrandContextResolver::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(BrandContextAssembler::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(AgentLowRiskFieldMatrix::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(AgentTranslator::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(AgentLanguageResolver::class))->newInstanceWithoutConstructor(),
        );

        $progressEvents = [];
        $user = $this->createMock(BackendUserAuthentication::class);
        $result = $orchestrator->runTurn(
            'What can you do?',
            [],
            ['pageId' => 49, 'module' => 'web_layout'],
            [],
            $user,
            'corr-direct',
            static function (string $event, array $payload) use (&$progressEvents): void {
                if ($event === 'progress') {
                    $progressEvents[] = $payload;
                }
            },
        );

        self::assertCount(1, $result['messages']);
        self::assertSame('explain_capabilities', $result['messages'][0]['meta']['directTool'] ?? null);
        self::assertSame('embeddings', $result['messages'][0]['meta']['routingSource'] ?? null);
        self::assertNotEmpty($progressEvents);
        self::assertSame('tool', $progressEvents[0]['status'] ?? null);
        self::assertSame('explain_capabilities', $progressEvents[0]['tool'] ?? null);
    }
}
