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

use NITSAN\NsT3AF\Agent\Service\AgentToolRetriever;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentToolRetrieverTest extends TestCase
{
    private AgentToolRetriever $retriever;

    protected function setUp(): void
    {
        $this->retriever = new AgentToolRetriever();
    }

    #[Test]
    public function newsCreationQueryRanksNewsToolFirst(): void
    {
        $catalog = [
            $this->tool('record_search', 'Search records', ['verbs' => ['search'], 'nouns' => ['record'], 'modules' => ['records']]),
            $this->tool('pages_get', 'Get page', ['verbs' => ['get'], 'nouns' => ['page'], 'modules' => ['records', 'web_layout']]),
            $this->tool('t3ai_create_news_simple', 'Create news', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['news', 'article'],
                'modules' => ['records'],
                'requiresPage' => true,
            ]),
        ];

        $shortlist = $this->retriever->shortlist(
            'create a news for the AI vs Non-AI users',
            ['module' => 'records', 'pageId' => 53],
            $catalog,
        );

        self::assertNotSame([], $shortlist);
        self::assertSame('t3ai_create_news_simple', $shortlist[0]['name']);
    }

    #[Test]
    public function infersIntentFromToolNameWhenMetadataMissing(): void
    {
        $catalog = [
            $this->tool('content_list', 'List content elements'),
            $this->tool('t3ai_create_news_simple', 'Create an EXT:news record (simple mode).'),
        ];

        $shortlist = $this->retriever->shortlist(
            'create news about TYPO3 AI',
            ['module' => 'records', 'pageId' => 53],
            $catalog,
        );

        self::assertSame('t3ai_create_news_simple', $shortlist[0]['name']);
    }

    #[Test]
    public function highConfidenceCreateNewsBuildsAutoInvocation(): void
    {
        $catalog = [
            $this->tool('t3ai_create_news_advanced', 'Create news advanced', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['news', 'article'],
                'modules' => ['records'],
                'requiresPage' => true,
            ], 'write', $this->newsContextHints()),
            $this->tool('t3ai_create_news_simple', 'Create news simple', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['news', 'article'],
                'modules' => ['records'],
                'requiresPage' => true,
            ], 'write', $this->newsContextHints()),
            $this->tool('t3ai_create_blog_simple', 'Create blog', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['blog', 'post'],
                'modules' => ['records'],
                'requiresPage' => true,
            ], 'write', $this->parentPageContextHints()),
        ];

        $auto = $this->retriever->buildAutoInvocation(
            'create a news for the PHP vs DotNet',
            ['module' => 'records', 'pageId' => 53],
            $catalog,
        );

        self::assertNotNull($auto);
        self::assertSame('t3ai_create_news_simple', $auto['tool']);
        self::assertSame('PHP vs DotNet', $auto['arguments']['topic']);
        self::assertSame(53, $auto['arguments']['pageId']);
    }

    #[Test]
    public function pageCreationQueryRanksPageToolFirst(): void
    {
        $catalog = [
            $this->tool('t3ai_create_news_simple', 'Create news', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['news', 'article'],
                'modules' => ['records'],
                'requiresPage' => true,
            ], 'write'),
            $this->tool('t3ai_create_page_simple', 'Create page', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['page'],
                'modules' => ['records', 'web_layout'],
                'requiresPage' => true,
            ], 'write', $this->parentPageContextHints()),
            $this->tool('t3ai_create_page_advanced', 'Create page advanced', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['page'],
                'modules' => ['records', 'web_layout'],
                'requiresPage' => true,
            ], 'write', $this->parentPageContextHints()),
            $this->tool('t3ai_create_page_structure', 'Create page structure', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['page', 'structure'],
                'modules' => ['records', 'web_layout'],
                'requiresPage' => true,
            ], 'write', $this->structureContextHints()),
        ];

        $shortlist = $this->retriever->shortlist(
            'create a page for the PHP vs DotNet',
            ['module' => 'records', 'pageId' => 53],
            $catalog,
        );

        self::assertSame('t3ai_create_page_simple', $shortlist[0]['name']);
    }

    #[Test]
    public function highConfidenceCreatePageBuildsAutoInvocationWithParentPageId(): void
    {
        $catalog = [
            $this->tool('t3ai_create_page_simple', 'Create page simple', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['page'],
                'modules' => ['records', 'web_layout'],
                'requiresPage' => true,
            ], 'write', $this->parentPageContextHints()),
            $this->tool('t3ai_create_page_advanced', 'Create page advanced', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['page'],
                'modules' => ['records', 'web_layout'],
                'requiresPage' => true,
            ], 'write', $this->parentPageContextHints()),
            $this->tool('t3ai_create_page_structure', 'Create page structure', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['page', 'structure'],
                'modules' => ['records', 'web_layout'],
                'requiresPage' => true,
            ], 'write', $this->structureContextHints()),
            $this->tool('t3ai_create_news_simple', 'Create news', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['news', 'article'],
                'modules' => ['records'],
                'requiresPage' => true,
            ], 'write', $this->newsContextHints()),
        ];

        $auto = $this->retriever->buildAutoInvocation(
            'create a page for the PHP vs DotNet',
            ['module' => 'records', 'pageId' => 53],
            $catalog,
        );

        self::assertNotNull($auto);
        self::assertSame('t3ai_create_page_simple', $auto['tool']);
        self::assertSame('PHP vs DotNet', $auto['arguments']['topic']);
        self::assertSame(53, $auto['arguments']['parentPageId']);
        self::assertArrayNotHasKey('pageId', $auto['arguments']);
    }

    #[Test]
    public function postLlmFailureAutoInvokesTopWriteTool(): void
    {
        $catalog = [
            $this->tool('t3ai_create_page_simple', 'Create page simple', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['page'],
                'modules' => ['records', 'web_layout'],
                'requiresPage' => true,
            ], 'write', $this->parentPageContextHints()),
            $this->tool('t3ai_create_page_structure', 'Create page structure', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['page', 'structure'],
                'modules' => ['records', 'web_layout'],
                'requiresPage' => true,
            ], 'write', $this->structureContextHints()),
        ];

        $auto = $this->retriever->buildAutoInvocation(
            'create a page for the PHP vs DotNet',
            ['module' => 'records', 'pageId' => 53],
            $catalog,
            true,
        );

        self::assertNotNull($auto);
        self::assertSame('t3ai_create_page_simple', $auto['tool']);
    }

    #[Test]
    public function advancedPageQueryRanksAdvancedToolAndExtractsTopic(): void
    {
        $catalog = [
            $this->tool('t3ai_create_page_simple', 'Create page simple', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['page'],
                'modules' => ['records', 'web_layout'],
                'requiresPage' => true,
            ], 'write', $this->parentPageContextHints()),
            $this->tool('t3ai_create_page_advanced', 'Create page advanced', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['page'],
                'modules' => ['records', 'web_layout'],
                'requiresPage' => true,
            ], 'write', $this->parentPageContextHints()),
        ];

        $shortlist = $this->retriever->shortlist(
            'create a advanced page for the PHP vs DotNet',
            ['module' => 'records', 'pageId' => 53],
            $catalog,
        );

        self::assertSame('t3ai_create_page_advanced', $shortlist[0]['name']);

        $auto = $this->retriever->buildAutoInvocation(
            'create a advanced page for the PHP vs DotNet',
            ['module' => 'records', 'pageId' => 53],
            $catalog,
        );

        self::assertNotNull($auto);
        self::assertSame('t3ai_create_page_advanced', $auto['tool']);
        self::assertSame('PHP vs DotNet', $auto['arguments']['topic']);
        self::assertSame(53, $auto['arguments']['parentPageId']);
    }

    #[Test]
    public function blogCreationUsesParentPageIdFromContextHints(): void
    {
        $catalog = [
            $this->tool('t3ai_create_blog_simple', 'Create blog', [
                'verbs' => ['create', 'write', 'add'],
                'nouns' => ['blog', 'post'],
                'modules' => ['records'],
                'requiresPage' => true,
            ], 'write', $this->parentPageContextHints()),
        ];

        $auto = $this->retriever->buildAutoInvocation(
            'create a blog for the PHP vs DotNet',
            ['module' => 'records', 'pageId' => 53],
            $catalog,
        );

        self::assertNotNull($auto);
        self::assertSame('t3ai_create_blog_simple', $auto['tool']);
        self::assertSame('PHP vs DotNet', $auto['arguments']['topic']);
        self::assertSame(53, $auto['arguments']['parentPageId']);
    }

    #[Test]
    public function pageStructureUsesPromptSubjectParam(): void
    {
        $catalog = [
            $this->tool('t3ai_create_page_structure', 'Create page structure', [
                'verbs' => ['create', 'generate', 'structure'],
                'nouns' => ['page', 'structure', 'outline'],
                'modules' => ['records', 'web_layout'],
                'requiresPage' => true,
            ], 'write', $this->structureContextHints()),
        ];

        $auto = $this->retriever->buildAutoInvocation(
            'create a page structure for our services',
            ['module' => 'records', 'pageId' => 53],
            $catalog,
        );

        self::assertNotNull($auto);
        self::assertSame('t3ai_create_page_structure', $auto['tool']);
        self::assertSame('our services', $auto['arguments']['prompt']);
        self::assertSame(53, $auto['arguments']['parentPageId']);
    }

    /**
     * @return array<string, string|null>
     */
    private function parentPageContextHints(): array
    {
        return [
            'parentPageParam' => 'parentPageId',
            'newsStorageParam' => null,
            'pageParam' => null,
            'subjectParam' => 'topic',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function newsContextHints(): array
    {
        return [
            'parentPageParam' => null,
            'newsStorageParam' => 'pageId',
            'pageParam' => null,
            'subjectParam' => 'topic',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function structureContextHints(): array
    {
        return [
            'parentPageParam' => 'parentPageId',
            'newsStorageParam' => null,
            'pageParam' => null,
            'subjectParam' => 'prompt',
        ];
    }

    /**
     * @param array<string, mixed>|null $intent
     * @param array<string, mixed>|null $contextHints
     * @return array<string, mixed>
     */
    private function tool(
        string $name,
        string $description,
        ?array $intent = null,
        string $severity = 'read',
        ?array $contextHints = null,
    ): array {
        return [
            'name' => $name,
            'description' => $description,
            'executable' => true,
            'intent' => $intent,
            'severity' => $severity,
            'contextHints' => $contextHints,
        ];
    }
}
