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

use NITSAN\NsT3AF\Agent\Service\AgentToolTurnProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentQueueSourceChoiceTest extends TestCase
{
    #[Test]
    public function oneSourceIsUsedWithoutAsking(): void
    {
        $decision = AgentToolTurnProcessor::queueSourceDecision([
            ['uid' => 6, 'name' => 'T3CS FAQ Source', 'type' => 'qa'],
        ]);

        self::assertSame(6, $decision['dataId']);
        self::assertSame([], $decision['options']);
    }

    #[Test]
    public function severalSourcesBecomeNamedButtons(): void
    {
        $decision = AgentToolTurnProcessor::queueSourceDecision([
            ['uid' => 0, 'name' => 'Ignored'],
            ['uid' => 5, 'name' => 'Asus', 'type' => 'web_pages'],
            ['uid' => 6, 'name' => '', 'type' => 'qa'],
        ]);

        self::assertSame(0, $decision['dataId']);
        self::assertSame(['Asus (web_pages) [5]', 'Source (qa) [6]'], $decision['options']);
    }

    #[Test]
    public function noSourceStaysEmpty(): void
    {
        self::assertSame(
            ['dataId' => 0, 'options' => []],
            AgentToolTurnProcessor::queueSourceDecision([['name' => 'Missing uid']]),
        );
    }

    #[Test]
    public function aButtonOrItsUidOpensThatQueue(): void
    {
        $history = [[
            'role' => 'assistant',
            'meta' => [
                'type' => 'clarification',
                'askedFor' => 't3cs_list_queue_items',
                'options' => ['Asus (web_pages) [5]', 'T3CS FAQ Source (qa) [6]'],
            ],
        ]];

        self::assertSame(6, AgentToolTurnProcessor::dataIdFromQueueChoice('T3CS FAQ Source (qa) [6]', $history));
        self::assertSame(5, AgentToolTurnProcessor::dataIdFromQueueChoice('5', $history));
        self::assertSame(0, AgentToolTurnProcessor::dataIdFromQueueChoice('9', $history));
        self::assertSame(0, AgentToolTurnProcessor::dataIdFromQueueChoice('T3CS FAQ Source (qa) [6]', []));
    }

    #[Test]
    public function oneChatbotOnThisSiteIsUsed(): void
    {
        $decision = AgentToolTurnProcessor::chatbotDecision([
            ['chatbotUid' => 3, 'title' => 'Other site', 'onThisSite' => false],
            ['chatbotUid' => 4, 'title' => 'Home bot', 'onThisSite' => true],
        ]);

        self::assertSame(4, $decision['chatbotUid']);
        self::assertSame([], $decision['options']);
    }

    #[Test]
    public function aButtonKeepsTheArgumentsAlreadyKnown(): void
    {
        $target = AgentToolTurnProcessor::openChoiceTarget('Home bot [4]', [[
            'role' => 'assistant',
            'meta' => [
                'type' => 'clarification',
                'askedFor' => 't3ac_chatbot_settings',
                'options' => ['Home bot [4]', 'Docs bot [5]'],
                'pendingArguments' => ['operation' => 'update', 'settingsJson' => '{"title":"Home"}'],
            ],
        ]]);

        self::assertSame('t3ac_chatbot_settings', $target['tool'] ?? null);
        self::assertSame(4, $target['arguments']['chatbotUid'] ?? null);
        self::assertSame('{"title":"Home"}', $target['arguments']['settingsJson'] ?? null);
    }

    #[Test]
    public function thatOneAfterAFailedCreateIsRecognized(): void
    {
        self::assertTrue(AgentToolTurnProcessor::refersToThatSource('Sync that one now'));
        self::assertFalse(AgentToolTurnProcessor::refersToThatSource('Sync all sources'));
        self::assertTrue(AgentToolTurnProcessor::latestSaveFailed([[
            'role' => 'assistant',
            'meta' => ['tool' => 't3cs_save_datasource', 'type' => 'tool_result', 'success' => false],
        ]]));
        self::assertFalse(AgentToolTurnProcessor::latestSaveFailed([[
            'role' => 'assistant',
            'meta' => ['tool' => 't3cs_save_datasource', 'type' => 'tool_result', 'success' => true],
        ]]));
        self::assertTrue(AgentToolTurnProcessor::asksForChatbotSettings('Show the AI Chatbot settings for chatbot 1'));
        self::assertTrue(AgentToolTurnProcessor::asksForChatbotSettings('chatbot settings?'));
        self::assertTrue(AgentToolTurnProcessor::asksForChatbotSettings('Rename the chatbot to Maybelline Helper'));
        self::assertTrue(AgentToolTurnProcessor::asksForChatbotSettings("Change the title of AI chatbot 1 to 'QA Deep Chatbot'."));
        self::assertFalse(AgentToolTurnProcessor::asksForChatbotSettings('List the chatbot data sources'));
        self::assertTrue(AgentToolTurnProcessor::chatbotSettingsNeedLicenceMessage('Rename the chatbot to Maybelline Helper', true));
        self::assertFalse(AgentToolTurnProcessor::chatbotSettingsNeedLicenceMessage('Rename the chatbot to Maybelline Helper', false));
        self::assertTrue(AgentToolTurnProcessor::asksToDeleteDataSources('delete all my data sources'));
        self::assertFalse(AgentToolTurnProcessor::asksToDeleteDataSources('list all my data sources'));
        self::assertFalse(AgentToolTurnProcessor::asksToDeleteDataSources('delete this page'));
        self::assertFalse(AgentToolTurnProcessor::asksToDeleteDataSources('remove the failed items from this data source'));
        self::assertSame('invalid_url', AgentToolTurnProcessor::datasourceCreateAdjustment(
            "Create a web page data source named 'QA Bad URL' for root page 1 with the URL not-a-url.",
            ['operation' => 'create', 'type' => 'qa', 'qaPairs' => '[{"q":"What happens?","a":"An error."}]'],
        )['action']);
        self::assertSame('web_pages', AgentToolTurnProcessor::datasourceCreateAdjustment(
            'teach our search about this article https://en.wikipedia.org/wiki/TYPO3 - only for the search',
            ['operation' => 'create', 'type' => 'qa'],
        )['action']);
        self::assertSame(
            'https://en.wikipedia.org/wiki/TYPO3',
            AgentToolTurnProcessor::datasourceCreateAdjustment(
                'teach our search about this article https://en.wikipedia.org/wiki/TYPO3',
                ['operation' => 'create', 'type' => 'qa'],
            )['url'] ?? null,
        );
        self::assertSame('keep', AgentToolTurnProcessor::datasourceCreateAdjustment(
            'Add an FAQ about opening hours. The answer can link to https://example.com/hours',
            ['operation' => 'create', 'type' => 'qa'],
        )['action']);
        self::assertSame('ask_page', AgentToolTurnProcessor::datasourceCreateAdjustment(
            'yes the Summer Offers page. both chatbot and search pls',
            ['operation' => 'create', 'type' => 'qa'],
        )['action']);
        self::assertSame(66, AgentToolTurnProcessor::datasourceCreateAdjustment(
            'make sure the chatbot and the search know about page 66',
            ['operation' => 'create', 'type' => 'qa'],
        )['pageId'] ?? null);
        self::assertSame('keep', AgentToolTurnProcessor::datasourceCreateAdjustment(
            'teach our search about this article https://en.wikipedia.org/wiki/TYPO3',
            ['operation' => 'update', 'type' => 'qa', 'dataId' => 15],
        )['action']);
        $textSource = AgentToolTurnProcessor::datasourceCreateAdjustment(
            "Create an inactive text data source 'QA Gap Multi' with the text 'multi test' for root page 1.",
            ['operation' => 'create', 'type' => 'qa'],
        );
        self::assertSame('text', $textSource['action']);
        self::assertSame('multi test', $textSource['text'] ?? null);
        self::assertTrue($textSource['inactive'] ?? false);
        self::assertSame(
            'QA RUALL FAQ',
            AgentToolTurnProcessor::addedAnswersTarget('Add these answers to QA RUALL FAQ'),
        );
        self::assertNull(AgentToolTurnProcessor::addedAnswersTarget('Add a suggested search question about opening hours'));
    }

    #[Test]
    public function aNamedSourceStaysScopeOne(): void
    {
        $decision = AgentToolTurnProcessor::namedSyncDecision(
            'Sync only the sitemap data source of root page 1 (changed pages only).',
            [
                ['uid' => 1, 'name' => 'Sitemap', 'type' => 'sitemap'],
                ['uid' => 8, 'name' => 'Pages', 'type' => 'typo3_pages'],
            ],
            'all',
            0,
        );

        self::assertSame('use', $decision['action']);
        self::assertSame('one', $decision['scope']);
        self::assertSame(1, $decision['dataId']);
    }

    #[Test]
    public function twoSourcesWithTheSameNameAreAQuestion(): void
    {
        $decision = AgentToolTurnProcessor::namedSyncDecision(
            "Sync the data source 'QA Deep Web OK' now.",
            [
                ['uid' => 14, 'name' => 'QA Deep Web OK', 'type' => 'web_pages'],
                ['uid' => 17, 'name' => 'QA Deep Web OK', 'type' => 'web_pages'],
                ['uid' => 1, 'name' => 'Sitemap', 'type' => 'sitemap'],
            ],
            'one',
            123,
        );

        self::assertSame('ask', $decision['action']);
        self::assertSame(['QA Deep Web OK (web_pages) [14]', 'QA Deep Web OK (web_pages) [17]'], $decision['options']);
    }

    #[Test]
    public function aChosenUidBreaksTheTie(): void
    {
        $decision = AgentToolTurnProcessor::namedSyncDecision(
            'QA Deep Web OK (web_pages) [14]',
            [
                ['uid' => 14, 'name' => 'QA Deep Web OK', 'type' => 'web_pages'],
                ['uid' => 17, 'name' => 'QA Deep Web OK', 'type' => 'web_pages'],
            ],
            'one',
            0,
        );

        self::assertSame('use', $decision['action']);
        self::assertSame(14, $decision['dataId']);
    }

    #[Test]
    public function everySourceStaysScopeAll(): void
    {
        $decision = AgentToolTurnProcessor::namedSyncDecision(
            'Sync all sources',
            [
                ['uid' => 1, 'name' => 'Sitemap', 'type' => 'sitemap'],
                ['uid' => 8, 'name' => 'Pages', 'type' => 'typo3_pages'],
            ],
            'one',
            8,
        );

        self::assertSame('keep', $decision['action']);
        self::assertSame('all', $decision['scope']);
        self::assertSame(0, $decision['dataId']);
    }

    #[Test]
    public function anInventedIdIsNotFound(): void
    {
        $decision = AgentToolTurnProcessor::namedSyncDecision(
            'Sync it now',
            [
                ['uid' => 1, 'name' => 'Sitemap', 'type' => 'sitemap'],
            ],
            'one',
            123,
        );

        self::assertSame('notFound', $decision['action']);
        self::assertSame(123, $decision['dataId']);
    }

    #[Test]
    public function aGuessedRootIsReplacedOnlyWhenItIsNotASiteRoot(): void
    {
        self::assertSame(48, AgentToolTurnProcessor::resolvedRootPageId(1, false, 0, 0, 48));
        self::assertSame(48, AgentToolTurnProcessor::resolvedRootPageId(1, false, 0, 48, 0));
        self::assertSame(1, AgentToolTurnProcessor::resolvedRootPageId(1, true, 48, 0, 48));
        self::assertSame(0, AgentToolTurnProcessor::resolvedRootPageId(1, false, 0, 0, 0));
    }

    #[Test]
    public function oneNamedSourceIsUsedAfterTheList(): void
    {
        $sources = [
            ['uid' => 8, 'name' => 'Asus', 'type' => 'sitemap'],
            ['uid' => 14, 'name' => 'Pages', 'type' => 'typo3_pages'],
        ];

        $byName = AgentToolTurnProcessor::namedSyncDecision("Sync 'Asus'", $sources, 'one', 0);
        self::assertSame('use', $byName['action']);
        self::assertSame(8, $byName['dataId']);

        $byUid = AgentToolTurnProcessor::namedSyncDecision('sync data source 8', $sources, 'one', 0);
        self::assertSame('use', $byUid['action']);
        self::assertSame(8, $byUid['dataId']);
    }
}
