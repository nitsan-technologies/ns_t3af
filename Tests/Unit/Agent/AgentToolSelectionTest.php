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
use NITSAN\NsT3AF\Agent\Service\AgentCoreToolSet;
use NITSAN\NsT3AF\Agent\Service\AgentSettingsService;
use NITSAN\NsT3AF\Agent\Service\AgentToolDocumentBuilder;
use NITSAN\NsT3AF\Agent\Service\AgentToolEditorLabelService;
use NITSAN\NsT3AF\Agent\Service\AgentToolSearch;
use NITSAN\NsT3AF\Agent\Service\AgentTranslator;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolMetadataService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Core tool set and find_tools ranking.
 *
 * @internal
 */
final class AgentToolSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
    }

    #[Test]
    public function coreSetHasContextToolsModuleToolsAndRecentToolsSortedByName(): void
    {
        $catalog = [
            $this->tool('pages_get'),
            $this->tool('content_list'),
            $this->tool('ask_clarification'),
            $this->tool('t3ai_generate_all_seo', modules: ['web_layout', 'records']),
            $this->tool('t3aa_update_file_metadata', modules: ['file', 'media_management']),
            $this->tool('redirect_create'),
            $this->tool('scheduler_list'),
        ];
        $history = [['role' => 'assistant', 'content' => 'ok', 'meta' => ['type' => 'tool_result', 'tool' => 'scheduler_list']]];

        $names = array_map(
            static fn(array $tool): string => (string) $tool['name'],
            $this->coreSet()->forTurn($catalog, ['module' => 'web_layout'], $history),
        );

        self::assertSame(['ask_clarification', 'content_list', 'pages_get', 'scheduler_list', 't3ai_generate_all_seo'], $names);
    }

    #[Test]
    public function fileModuleRoutesAreNormalized(): void
    {
        $catalog = [$this->tool('pages_get'), $this->tool('t3aa_update_file_metadata', modules: ['file'])];

        $names = array_map(
            static fn(array $tool): string => (string) $tool['name'],
            $this->coreSet()->forTurn($catalog, ['module' => 'media_management']),
        );

        self::assertSame(['pages_get', 't3aa_update_file_metadata'], $names);
    }

    #[Test]
    public function createContentRequestSkipsRecentContentDelete(): void
    {
        $catalog = [
            $this->tool('pages_get'),
            $this->tool('content_list'),
            $this->tool('content_delete'),
            $this->tool('scheduler_list'),
        ];
        $history = [['role' => 'assistant', 'content' => 'ok', 'meta' => ['type' => 'tool_result', 'tool' => 'content_delete']]];

        $withDelete = array_map(
            static fn(array $tool): string => (string) $tool['name'],
            $this->coreSet()->forTurn($catalog, ['module' => 'web_layout'], $history, 'Delete content element 12'),
        );
        $withoutDelete = array_map(
            static fn(array $tool): string => (string) $tool['name'],
            $this->coreSet()->forTurn($catalog, ['module' => 'web_layout'], $history, 'Create all elements on this page'),
        );

        self::assertContains('content_delete', $withDelete);
        self::assertNotContains('content_delete', $withoutDelete);
    }

    #[Test]
    public function isCreateContentRequestDetectsCreateElementPhrases(): void
    {
        self::assertTrue(AgentCoreToolSet::isCreateContentRequest('Create all elements in the this page'));
        self::assertTrue(AgentCoreToolSet::isCreateContentRequest('Add a text element here'));
        self::assertTrue(AgentCoreToolSet::isCreateContentRequest('Neues Inhaltselement anlegen'));
        self::assertFalse(AgentCoreToolSet::isCreateContentRequest('Delete content element 449'));
        self::assertFalse(AgentCoreToolSet::isCreateContentRequest('Delete the old elements and create new ones'));
        self::assertFalse(AgentCoreToolSet::isCreateContentRequest('Replace the text element with a new one'));
        self::assertFalse(AgentCoreToolSet::isCreateContentRequest('Alte Inhaltselemente löschen und neue anlegen'));
        self::assertFalse(AgentCoreToolSet::isCreateContentRequest('Create a new page'));
        self::assertFalse(AgentCoreToolSet::isCreateContentRequest('List backend user groups'));
    }

    #[Test]
    public function keywordSearchFindsToolsFromGermanExamples(): void
    {
        $catalog = [
            $this->tool('t3aa_update_file_metadata', examples: ['Alt-Texte für Bilder erzeugen', 'generate alt text for images']),
            $this->tool('t3ai_translate_content', examples: ['Inhalt übersetzen', 'translate this content element']),
            $this->tool('redirect_create', examples: ['Weiterleitung anlegen', 'create a redirect']),
        ];

        $result = $this->search()->search('Bitte Alt-Texte für alle Bilder schreiben', $catalog, 2);

        self::assertSame('keywords', $result['method']);
        self::assertSame('t3aa_update_file_metadata', $result['tools'][0]['name']);
    }

    #[Test]
    public function prefixMatchingHandlesInflection(): void
    {
        $catalog = [
            $this->tool('t3ai_translate_content', examples: ['translate content']),
            $this->tool('redirect_create', examples: ['create a redirect']),
        ];

        $result = $this->search()->search('translation of this element', $catalog, 1);

        self::assertSame('t3ai_translate_content', $result['tools'][0]['name']);
    }

    #[Test]
    public function embeddingAndKeywordRankingsAreFused(): void
    {
        $catalog = [
            $this->tool('pages_tree', examples: ['show the page tree']),
            $this->tool('redirect_create', examples: ['create a redirect']),
            $this->tool('scheduler_list', examples: ['list scheduler tasks']),
        ];
        $index = $this->createMock(AgentToolIndexInterface::class);
        $index->method('search')->willReturn([
            ['name' => 'scheduler_list', 'score' => 0.9],
            ['name' => 'unknown_or_not_permitted', 'score' => 0.8],
        ]);

        $result = $this->search($index, 'provider')->search('Weiterleitung', $catalog, 3);

        // scheduler_list from the (mocked) embeddings, redirect_create from the German search terms
        // in McpToolMetadata.yaml; the unknown tool is dropped because it is not in the catalog.
        self::assertSame('embeddings+keywords', $result['method']);
        $names = array_map(static fn(array $t): string => (string) $t['name'], $result['tools']);
        sort($names);
        self::assertSame(['redirect_create', 'scheduler_list'], $names);
    }

    #[Test]
    public function emptyQueryFindsNothing(): void
    {
        self::assertSame([], $this->search()->search('  ', [$this->tool('pages_get')])['tools']);
    }

    /**
     * @param list<string> $modules
     * @param list<string> $examples
     * @return array<string, mixed>
     */
    private function tool(string $name, array $modules = [], array $examples = [], string $category = ''): array
    {
        return [
            'name' => $name,
            'description' => '',
            'severity' => 'read',
            'intent' => $modules === [] && $examples === [] && $category === '' ? null : [
                'modules' => $modules,
                'examples' => $examples,
                'summary' => '',
                'category' => $category,
                'verbs' => [],
                'nouns' => [],
                'requiresPage' => false,
            ],
        ];
    }

    private function documents(): AgentToolDocumentBuilder
    {
        $translator = (new \ReflectionClass(AgentTranslator::class))->newInstanceWithoutConstructor();

        return new AgentToolDocumentBuilder(new AgentToolEditorLabelService($translator), new McpToolMetadataService());
    }

    private function coreSet(): AgentCoreToolSet
    {
        return new AgentCoreToolSet($this->documents());
    }

    private function search(?AgentToolIndexInterface $index = null, string $embeddingMode = 'none'): AgentToolSearch
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn(['agentEmbeddingSource' => $embeddingMode, 'agentMinSimilarity' => '0']);
        $settings = new AgentSettingsService($extensionSettings);
        $source = new class implements EmbeddingSourceInterface {
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
                return [0.1];
            }
        };

        return new AgentToolSearch(
            $index ?? $this->createMock(AgentToolIndexInterface::class),
            new EmbeddingSourceResolver($settings, [$source]),
            $this->documents(),
            $settings,
        );
    }
}
