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
final class AgentPromptBuilderSeoTextTest extends TestCase
{
    #[Test]
    public function improvingThisPageForGooglePreparesSuggestions(): void
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

        $prompt = $builder->buildSystemPrompt(['pageId' => 0]);

        self::assertStringContainsString('To make this page look better in Google, or to write SEO texts for this page, call t3ai_generate_all_seo', $prompt);
        self::assertStringContainsString('nothing is written until the editor applies them', $prompt);
        self::assertStringContainsString('Do not add the page to the SEO queue for that request', $prompt);
        self::assertStringContainsString('only for an explicit add, list, or "list for Google" request', $prompt);
        self::assertStringContainsString('SEO texts for the subpages of this page still use t3ai_generate_seo_batch', $prompt);
    }
}
