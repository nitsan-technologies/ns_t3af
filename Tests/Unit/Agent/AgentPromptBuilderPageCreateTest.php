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

use NITSAN\NsT3AF\Agent\Service\AgentLanguageResolver;
use NITSAN\NsT3AF\Agent\Service\AgentPromptBuilder;
use NITSAN\NsT3AF\Agent\Service\QueueAutomationStatus;
use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;
use NITSAN\NsT3AF\Service\BrandContextAssembler;
use NITSAN\NsT3AF\Service\BrandContextProfileOverrideReaderInterface;
use NITSAN\NsT3AF\Service\BrandContextResolver;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * @internal
 */
final class AgentPromptBuilderPageCreateTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function aPageWithContentIsCreatedByTheCreatePageToolNotAsAnEmptyPage(): void
    {
        $prompt = $this->prompt();

        self::assertStringContainsString('call it once with topic and parentPageId', $prompt);
        self::assertStringContainsString('drafts the title, the content elements and the SEO texts', $prompt);
        self::assertStringContainsString('Never create such a page with write_table, that makes an empty page', $prompt);
        self::assertStringContainsString('call t3ai_create_page_structure once with prompt and parentPageId; never create those pages one by one', $prompt);
        self::assertStringContainsString('when t3ai_create_blog_simple or t3ai_create_blog_advanced is offered', $prompt);
    }

    #[Test]
    public function aFailedDataSourceCreateIsNotAPageFollowUp(): void
    {
        $prompt = $this->prompt();

        self::assertStringContainsString('do not talk about the SEO scheduler', $prompt);
        self::assertStringContainsString('not a translation of this page', $prompt);
        self::assertStringContainsString('scope all and do not ask which source', $prompt);
    }

    #[Test]
    public function aSavedDataSourceStaysInTheHistoryNote(): void
    {
        $block = AgentPromptBuilder::appliedRecordsBlock([
            [
                'role' => 'assistant',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [[
                        'table' => 'pages',
                        'uid' => 4,
                        'values' => ['title' => 'Home'],
                    ]],
                ],
            ],
            [
                'role' => 'assistant',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3cs_save_datasource',
                    'success' => true,
                    'autoRan' => false,
                    'details' => [
                        'dataId' => 25,
                        'type' => 'text',
                        'typeValue' => 'Wash your face.',
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('pages uid 4', $block);
        self::assertStringContainsString('data source uid 25 (text) text "Wash your face."', $block);
        self::assertStringContainsString('do not say nothing was saved', $block);
    }

    #[Test]
    public function aSearchSettingUsesTheFormValues(): void
    {
        $prompt = $this->prompt();

        self::assertStringContainsString('Do not call pages_move for a rename', $prompt);
        self::assertStringContainsString('Do not describe the older card as saved', $prompt);
        self::assertStringContainsString('ask what text to add and do not draft a card', $prompt);
        self::assertStringContainsString('Do not say nothing was saved', $prompt);
        self::assertStringContainsString('questionsLimit is 3, 5, or 10', $prompt);
        self::assertStringContainsString('allowedDomains is host names such as example.com', $prompt);
        self::assertStringContainsString('Do not claim the setting was saved', $prompt);
        self::assertStringContainsString('A main colour is primaryColor and nothing else', $prompt);
        self::assertStringContainsString('answer_in_visitor_language', $prompt);
        self::assertStringContainsString('Make it 2', $prompt);
        self::assertStringContainsString('Show at most 3 links under the bot\'s answers', $prompt);
        self::assertStringContainsString('After those settings are applied, say they were saved', $prompt);
        self::assertStringContainsString('The title is only the new name, not the rest of the sentence', $prompt);
        self::assertStringContainsString('call scheduler_update with that uid and the new description', $prompt);
        self::assertStringContainsString('Providers page', $prompt);
        self::assertStringContainsString('Do not repeat a pasted key', $prompt);
    }

    #[Test]
    public function aContentAnalysisIsAReportThatChangesNothing(): void
    {
        $prompt = $this->prompt();

        self::assertStringContainsString('call t3ai_analyze_content with the page uid and show its report as the answer', $prompt);
        self::assertStringContainsString('It changes nothing', $prompt);
    }

    private function prompt(): string
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willThrowException(new \RuntimeException('no database'));
        $builder = new AgentPromptBuilder(
            new BrandContextResolver(
                $this->createMock(BrandContextProfileRepositoryInterface::class),
                new SiteStorageContext($siteFinder),
                $this->createMock(BrandContextProfileOverrideReaderInterface::class),
            ),
            (new ReflectionClass(BrandContextAssembler::class))->newInstanceWithoutConstructor(),
            new AgentLanguageResolver($siteFinder),
            new QueueAutomationStatus($connectionPool),
        );

        return $builder->buildSystemPrompt(['pageId' => 0]);
    }
}
