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

use NITSAN\NsT3AF\Agent\Service\AgentRunner;
use NITSAN\NsT3AF\Agent\Service\AgentToolTurnProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentRunnerPageRequestTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function requests(): iterable
    {
        yield 'new page' => ['Create a page called Our Services about web development.', true];
        yield 'page structure' => ['Create a page structure for a company website with Home, About and Contact.', true];
        yield 'blog post' => ['Generate a blog post about sustainable investing.', true];
        yield 'german page' => ['Erstelle eine neue Seite Leistungen.', true];
        yield 'intro paragraph' => ['Add an intro paragraph to this page.', false];
        yield 'introduce' => ['Introduce our web development services for small businesses.', false];
        yield 'german paragraph' => ['Füge einen Einleitungsabsatz zu dieser Seite hinzu.', false];
    }

    #[Test]
    #[DataProvider('requests')]
    public function onlyARequestThatNamesAPageAsksForANewPage(string $query, bool $expected): void
    {
        self::assertSame($expected, AgentRunner::asksForNewPage($query));
    }

    #[Test]
    public function aPageToolKeepsItsParentWhenTheOpenPageIsTheParent(): void
    {
        $arguments = ['parentPageId' => 5, 'prompt' => 'Bakery'];

        self::assertSame($arguments, AgentToolTurnProcessor::openPageAsParent($arguments, 5, 't3ai_create_page_structure', 'Create a page structure'));
        self::assertSame($arguments, AgentToolTurnProcessor::openPageAsParent($arguments, 9, 't3ai_create_page_structure', 'Create a structure at the root'));
        self::assertSame($arguments, AgentToolTurnProcessor::openPageAsParent($arguments, 9, 'write_table', 'Create a page structure'));
    }

    #[Test]
    public function retryFailedAlsoDropsThePidAndSaysFinishedRowsAreNotIncluded(): void
    {
        $arguments = AgentToolTurnProcessor::requeueFailedOnly(['pid' => 234, 'pageIds' => [234]], 'Retry the ones that failed.');

        self::assertSame(['includeFinished' => false], $arguments);
    }

    #[Test]
    public function aShortStructurePromptGetsTheEditorRequest(): void
    {
        $request = 'Create a page structure for a company website with Home, About, Services, Contact and 2 subpages under Services.';

        $arguments = AgentToolTurnProcessor::withEditorRequestAsStructurePrompt(['prompt' => 'Home'], 't3ai_create_page_structure', $request);
        self::assertStringContainsString('About, Services, Contact', (string) $arguments['prompt']);

        $full = ['prompt' => $request . ' Keep titles short.'];
        self::assertSame($full, AgentToolTurnProcessor::withEditorRequestAsStructurePrompt($full, 't3ai_create_page_structure', $request));
        self::assertSame(['prompt' => 'Home'], AgentToolTurnProcessor::withEditorRequestAsStructurePrompt(['prompt' => 'Home'], 'write_table', $request));
    }

    #[Test]
    public function aPageCreateWithoutPidGetsTheOpenPage(): void
    {
        $arguments = ['action' => 'create', 'tableName' => 'pages', 'data' => '{"title":"Post","doktype":137}'];

        $result = AgentToolTurnProcessor::openPageAsCreatePid($arguments, 33, 'write_table', 'Create a blog post titled Post');
        self::assertSame(33, json_decode((string) $result['data'], true)['pid']);

        $keep = ['action' => 'create', 'tableName' => 'pages', 'data' => '{"title":"Post","pid":33}'];
        self::assertSame($keep, AgentToolTurnProcessor::openPageAsCreatePid($keep, 33, 'write_table', 'Create a page'));
        $update = ['action' => 'update', 'tableName' => 'pages', 'uid' => 4, 'data' => '{"title":"X"}'];
        self::assertSame($update, AgentToolTurnProcessor::openPageAsCreatePid($update, 33, 'write_table', 'Rename page'));
        $content = ['action' => 'create', 'tableName' => 'tt_content', 'data' => '{"header":"X"}'];
        self::assertSame($content, AgentToolTurnProcessor::openPageAsCreatePid($content, 33, 'write_table', 'Add text'));
    }

    #[Test]
    public function newsRequestMustNotEndInAContentElementOrPage(): void
    {
        self::assertTrue(AgentRunner::isNewsRequestAnsweredWithOtherTable('tt_content', 'Generiere eine Nachricht über unser Sommerfest.'));
        self::assertTrue(AgentRunner::isNewsRequestAnsweredWithOtherTable('pages', 'Create a news article about SEPA'));
        self::assertFalse(AgentRunner::isNewsRequestAnsweredWithOtherTable('tx_news_domain_model_news', 'Create a news article about SEPA'));
        self::assertFalse(AgentRunner::isNewsRequestAnsweredWithOtherTable('tt_content', 'Create a news article and a content element about SEPA'));
    }

    #[Test]
    public function schemaRequestsAreRecognised(): void
    {
        self::assertTrue(AgentRunner::isSchemaRequest('Generate a Person schema for this page'));
        self::assertTrue(AgentRunner::isSchemaRequest('Strukturierte Daten für diese Seite erzeugen'));
        self::assertFalse(AgentRunner::isSchemaRequest('Remove the schema from this page'));
        self::assertFalse(AgentRunner::isSchemaRequest('Generate SEO for this page'));
    }

    #[Test]
    public function schemaToolIsKeptForASchemaRequest(): void
    {
        $tools = [['name' => 'pages_get'], ['name' => 't3ai_apply_schema_markup']];

        self::assertSame([['name' => 't3ai_apply_schema_markup']], AgentRunner::schemaTools($tools));
        self::assertSame([], AgentRunner::schemaTools($tools, ['t3ai_apply_schema_markup' => 1]));
    }
}
