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
