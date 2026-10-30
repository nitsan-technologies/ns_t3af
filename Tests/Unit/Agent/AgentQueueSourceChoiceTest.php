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
        $failedApply = [[
            'role' => 'assistant',
            'meta' => [
                'type' => 'inline_draft',
                'tool' => 't3cs_save_datasource',
                'draft' => ['tool' => 't3cs_save_datasource', 'applied' => false],
            ],
        ], [
            'role' => 'assistant',
            'content' => 'The change could not be saved: The URL returned an error (HTTP 404).',
            'meta' => ['type' => 'error', 'tool' => 't3cs_save_datasource', 'success' => false],
        ]];
        self::assertTrue(AgentToolTurnProcessor::latestSaveFailed($failedApply));
        self::assertTrue(AgentToolTurnProcessor::blocksFollowUpOfFailedCreate('Sync that one now', $failedApply));
        self::assertTrue(AgentToolTurnProcessor::blocksFollowUpOfFailedCreate('Now activate it.', $failedApply));
        self::assertTrue(AgentToolTurnProcessor::latestSaveFailed([[
            'role' => 'assistant',
            'meta' => [
                'type' => 'inline_draft',
                'tool' => 't3cs_save_datasource',
                'draft' => ['tool' => 't3cs_save_datasource', 'failed' => true, 'failureMessage' => 'The URL returned an error (HTTP 404).'],
            ],
        ]]));
        self::assertFalse(AgentToolTurnProcessor::blocksFollowUpOfFailedCreate('Sync the Asus source', $failedApply));
        self::assertSame(8, AgentToolTurnProcessor::namedSyncDecision(
            'Sync that one now',
            [['uid' => 8, 'name' => 'Asus', 'type' => 'sitemap']],
            'one',
            8,
        )['dataId']);
        self::assertTrue(AgentToolTurnProcessor::asksForChatbotSettings('Show the AI Chatbot settings for chatbot 1'));
        self::assertTrue(AgentToolTurnProcessor::asksForChatbotSettings('chatbot settings?'));
        self::assertTrue(AgentToolTurnProcessor::asksForChatbotSettings('Rename the chatbot to Maybelline Helper'));
        self::assertTrue(AgentToolTurnProcessor::asksForChatbotSettings("Change the title of AI chatbot 1 to 'QA Deep Chatbot'."));
        self::assertFalse(AgentToolTurnProcessor::asksForChatbotSettings('List the chatbot data sources'));
        self::assertTrue(AgentToolTurnProcessor::asksForChatbotSettings("show at most 3 links under the bot's answers"));
        self::assertTrue(AgentToolTurnProcessor::asksForChatbotSettings('turn off the source links under the chatbot answers'));
        self::assertTrue(AgentToolTurnProcessor::asksForChatbotSettings("answer in the visitor's language"));
        self::assertTrue(AgentToolTurnProcessor::asksForChatbotSettings('tell the bot to never recommend other brands'));
        self::assertFalse(AgentToolTurnProcessor::asksForChatbotSettings('make search answer in the visitor\'s language'));
        self::assertTrue(AgentToolTurnProcessor::asksToChangeChatbotBehavior('make it 2 actually', [[
            'role' => 'user',
            'content' => "show at most 3 links under the bot's answers",
        ]]));
        self::assertTrue(AgentToolTurnProcessor::unappliedChatbotStatusQuestion('so is that active now?', [[
            'role' => 'user',
            'content' => 'tell the bot to never recommend other brands',
        ]]));
        self::assertFalse(AgentToolTurnProcessor::unappliedChatbotStatusQuestion('so is that active now?', [[
            'role' => 'user',
            'content' => 'tell the bot to never recommend other brands',
        ], [
            'role' => 'assistant',
            'content' => 'Chatbot settings saved.',
            'meta' => ['tool' => 't3ac_chatbot_settings', 'success' => true, 'autoRan' => false],
        ]]));
        $colour = 'make the search look more like our brand, use pink #e6007e as the main colour';
        $kept = AgentRunner::searchSettingsArgumentsForRequest([
            'settingsJson' => '{"primaryColor":"#e6007e","secondaryColor":"#ffffff","textColor":"#ffffff","widgetAppearance":"light","widgetMode":"sidebar","enableAiSearch":1,"instructions":"Be pink."}',
        ], $colour);
        $decoded = json_decode((string) $kept['settingsJson'], true);
        self::assertSame(['primaryColor' => '#e6007e'], $decoded);
        $withSwitch = AgentRunner::searchSettingsArgumentsForRequest([
            'settingsJson' => '{"primaryColor":"#e6007e","enableAiSearch":true}',
        ], 'turn on AI search and use pink #e6007e as the main colour');
        self::assertSame(
            ['primaryColor' => '#e6007e', 'enableAiSearch' => true],
            json_decode((string) $withSwitch['settingsJson'], true),
        );
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
        self::assertSame('ask_text', AgentToolTurnProcessor::datasourceCreateAdjustment(
            'Create a text source for beauty tips.',
            ['operation' => 'create', 'type' => 'text', 'typeValue' => "**Skincare Routine:**\nWash your face."],
        )['action']);
        $quoted = AgentToolTurnProcessor::datasourceCreateAdjustment(
            "Create a text source with the text 'Wash your face. **Keep this.**'",
            ['operation' => 'create', 'type' => 'text', 'typeValue' => 'invented tips'],
        );
        self::assertSame('text', $quoted['action']);
        self::assertSame('Wash your face. **Keep this.**', $quoted['text'] ?? null);
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
    public function allMyDataSourcesIsScopeAll(): void
    {
        $decision = AgentToolTurnProcessor::namedSyncDecision(
            'Request a sync of all my data sources on root page 1 (only changed pages).',
            [
                ['uid' => 1, 'name' => 'Sitemap', 'type' => 'sitemap'],
                ['uid' => 9, 'name' => 'Single Web Pages', 'type' => 'web_pages'],
            ],
            'one',
            0,
        );

        self::assertSame('keep', $decision['action']);
        self::assertSame('all', $decision['scope']);
        self::assertSame(0, $decision['dataId']);
        self::assertFalse(AgentToolTurnProcessor::askedForEverySource('List all my data sources'));
    }

    #[Test]
    public function theSecondListedSourceIsThatUid(): void
    {
        $sources = [
            ['uid' => 1, 'name' => 'QA Gap Queue Source', 'type' => 'qa'],
            ['uid' => 9, 'name' => 'Single Web Pages', 'type' => 'web_pages'],
        ];
        $history = [[
            'role' => 'assistant',
            'meta' => [
                'type' => 'tool_result',
                'tool' => 't3cs_list_datasources',
                'success' => true,
                'details' => ['datasources' => $sources],
            ],
        ]];

        self::assertSame(9, AgentToolTurnProcessor::sourceUidFromOrdinal('Tell me more about the second one', $sources));
        self::assertSame(9, AgentToolTurnProcessor::sourceUidFromOrdinal('Tell me more about the second one', AgentToolTurnProcessor::datasourcesInLatestList($history)));
        self::assertSame(0, AgentToolTurnProcessor::sourceUidFromOrdinal('Tell me more about the second page', $sources));
        self::assertSame([], AgentToolTurnProcessor::datasourcesInLatestList([[
            'role' => 'assistant',
            'meta' => ['type' => 'tool_result', 'tool' => 'pages_get', 'success' => true, 'details' => ['uid' => 48]],
        ]]));
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

    #[Test]
    public function aBotRefreshAGreetingAndARenameStayOnTheRightTool(): void
    {
        $sources = [
            ['uid' => 8, 'name' => 'Asus product pages', 'type' => 'web_pages'],
            ['uid' => 3, 'name' => 'Myntra', 'type' => 'web_pages'],
            ['uid' => 10, 'name' => 'Makeup brush article', 'type' => 'web_pages'],
            ['uid' => 9, 'name' => 'Beginners makeup kit', 'type' => 'web_pages'],
            ['uid' => 11, 'name' => 'Blush types article', 'type' => 'web_pages'],
        ];

        $refresh = AgentToolTurnProcessor::directToolRequest(
            'the blush types article on maybelline was updated, please refresh it in the bot',
        );
        self::assertSame('t3cs_sync_datasource', $refresh['tool'] ?? '');
        self::assertSame('one', $refresh['arguments']['scope'] ?? '');
        $blush = AgentToolTurnProcessor::namedSyncDecision(
            'the blush types article on maybelline was updated, please refresh it in the bot',
            $sources,
            'one',
            0,
        );
        self::assertSame('use', $blush['action']);
        self::assertSame(11, $blush['dataId']);

        self::assertTrue(AgentToolTurnProcessor::askedForEverySource('refresh everything in the bot now'));
        $both = AgentToolTurnProcessor::namedSyncDecision(
            'ok no, just the makeup brush article and the beginners makeup kit one',
            $sources,
            'one',
            0,
        );
        self::assertSame('ask', $both['action']);
        self::assertCount(2, $both['options']);

        $retry = AgentToolTurnProcessor::directToolRequest('something failed with the makeup brush article, fix it');
        self::assertSame('t3cs_reset_failed_queue_item', $retry['tool'] ?? '');
        self::assertSame(10, AgentToolTurnProcessor::trainingSourceTarget(
            'something failed with the makeup brush article, fix it',
            $sources,
        ));
        self::assertNull(AgentToolTurnProcessor::directToolRequest('retry the failed translations'));

        $rename = AgentToolTurnProcessor::directToolRequest("rename the myntra source to 'Myntra shop (old)'");
        self::assertSame('t3cs_save_datasource', $rename['tool'] ?? '');
        self::assertSame('Myntra shop (old)', $rename['arguments']['name'] ?? '');
        self::assertSame(3, AgentToolTurnProcessor::datasourceRenameTarget(
            "rename the myntra source to 'Myntra shop (old)'",
            $sources,
        ));
        self::assertNull(AgentToolTurnProcessor::directToolRequest('Rename this page to QA Gap A'));
        self::assertFalse(AgentToolTurnProcessor::asksToRefreshADatasource('learn page 66'));

        $greeting = AgentToolTurnProcessor::directToolRequest(
            "Ändere die Begrüßung des Chatbots zu 'Hallo! Wie kann ich dir bei Make-up helfen?'",
        );
        self::assertSame('t3ac_chatbot_settings', $greeting['tool'] ?? '');
        self::assertSame('update', $greeting['arguments']['operation'] ?? '');
        self::assertStringContainsString('first_message', (string) ($greeting['arguments']['settingsJson'] ?? ''));
        self::assertStringContainsString('Hallo! Wie kann ich dir bei Make-up helfen?', (string) ($greeting['arguments']['settingsJson'] ?? ''));
        $tuned = AgentToolTurnProcessor::tuneChatbotArguments(
            't3ac_chatbot_settings',
            ['operation' => 'get', 'chatbotUid' => 4],
            "Change the chatbot welcome message to 'Welcome to Maybelline! Ask me anything.'",
        );
        self::assertSame('update', $tuned['operation']);
        self::assertStringNotContainsString('instructions', (string) $tuned['settingsJson']);

        $title = AgentToolTurnProcessor::directToolRequest('Rename the chatbot to Maybelline Helper');
        self::assertSame('t3ac_chatbot_settings', $title['tool'] ?? '');
        self::assertStringContainsString('Maybelline Helper', (string) ($title['arguments']['settingsJson'] ?? ''));

        $history = [[
            'role' => 'assistant',
            'content' => 'Saved.',
            'meta' => [
                'type' => 'tool_result',
                'tool' => 't3ac_chatbot_settings',
                'autoRan' => false,
                'success' => true,
                'recordBaseline' => ['uid' => 4, 'values' => ['title' => 'AI Chatbot']],
            ],
        ]];
        $back = AgentToolTurnProcessor::directToolRequest('change it back', $history);
        self::assertSame('t3ac_chatbot_settings', $back['tool'] ?? '');
        self::assertSame(4, $back['arguments']['chatbotUid'] ?? 0);
        self::assertStringContainsString('AI Chatbot', (string) ($back['arguments']['settingsJson'] ?? ''));
        $withoutPrevious = [[
            'role' => 'assistant',
            'content' => 'Saved.',
            'meta' => [
                'type' => 'tool_result',
                'tool' => 't3ac_chatbot_settings',
                'autoRan' => false,
                'success' => true,
            ],
        ]];
        self::assertSame(
            'agent.chatbot.changeBack',
            AgentToolTurnProcessor::directToolRequest('change it back', $withoutPrevious)['ask'] ?? '',
        );
        self::assertNull(AgentToolTurnProcessor::directToolRequest('change it back'));

        $scheduler = AgentToolTurnProcessor::directToolRequest(
            "Change the description of scheduler task 1 to 'T3CS Training (Site 1) QA'.",
        );
        self::assertSame('scheduler_update', $scheduler['tool'] ?? '');
        self::assertSame(1, $scheduler['arguments']['uid'] ?? 0);
        self::assertStringContainsString('T3CS Training (Site 1) QA', (string) ($scheduler['arguments']['fields'] ?? ''));
        self::assertFalse(AgentToolTurnProcessor::asksToSetDatasourceDescription(
            "Change the description of scheduler task 1 to 'T3CS Training (Site 1) QA'.",
        ));

        self::assertTrue(AgentToolTurnProcessor::asksToChangeAiProvider(
            'our boss wants us to use OpenAI GPT-4o instead of Mistral. here is the key: sk-qa-test-1234567890. please set it up',
        ));
        self::assertFalse(AgentToolTurnProcessor::asksToChangeAiProvider('Write SEO with OpenAI'));
    }

    #[Test]
    public function aNamedDescriptionAndSourceUsageStayOnThatSource(): void
    {
        $sources = [
            ['uid' => 21, 'name' => 'QA Gap Queue Src', 'type' => 'web_pages', 'typeValue' => 'https://example.com/queue'],
            ['uid' => 34, 'name' => 'QA Gap XConv', 'type' => 'text', 'typeValue' => 'notes'],
            ['uid' => 1, 'name' => 'sitemap', 'type' => 'sitemap', 'typeValue' => 'https://tata.cars/sitemap.xml'],
        ];
        $description = "Set the description of the data source 'QA Gap XConv' (root page 1) to 'from conv2'.";
        $update = AgentToolTurnProcessor::describedSourceUpdate($description, $sources);
        self::assertSame('use', $update['status']);
        self::assertSame(34, $update['dataId']);
        self::assertSame('from conv2', $update['description']);
        $card = AgentToolTurnProcessor::directToolRequest($description);
        self::assertSame('t3cs_save_datasource', $card['tool'] ?? '');
        self::assertSame('update', $card['arguments']['operation'] ?? '');
        self::assertSame('from conv2', $card['arguments']['description'] ?? '');
        self::assertArrayNotHasKey('name', $card['arguments'] ?? []);
        self::assertFalse(AgentToolTurnProcessor::asksToSetDatasourceDescription("Set the description of this page to 'hello'"));
        self::assertSame('missing', AgentToolTurnProcessor::describedSourceUpdate(
            "Set the description of the data source 'Missing Source' to 'from conv2'.",
            $sources,
        )['status']);

        $usage = 'the search shouldn\'t use the tata cars stuff anymore, only the chatbot';
        $usageCard = AgentToolTurnProcessor::directToolRequest($usage);
        self::assertSame('t3cs_save_datasource', $usageCard['tool'] ?? '');
        self::assertSame('chatbot', $usageCard['arguments']['usageType'] ?? '');
        $usageUpdate = AgentToolTurnProcessor::usageSourceUpdate($usage, [], $sources);
        self::assertSame('use', $usageUpdate['status']);
        self::assertSame(1, $usageUpdate['dataId']);
        self::assertSame('chatbot', $usageUpdate['usageType']);

        $history = [['role' => 'user', 'content' => $usage]];
        $neither = 'actually no, neither of them should use it';
        $neitherCard = AgentToolTurnProcessor::directToolRequest($neither, $history);
        self::assertSame('t3cs_save_datasource', $neitherCard['tool'] ?? '');
        self::assertSame(0, $neitherCard['arguments']['activate'] ?? null);
        $neitherUpdate = AgentToolTurnProcessor::usageSourceUpdate($neither, $history, $sources);
        self::assertSame(1, $neitherUpdate['dataId']);
        self::assertSame(0, $neitherUpdate['activate']);

        self::assertNull(AgentToolTurnProcessor::directToolRequest('Turn off AI search'));
        self::assertTrue(AgentToolTurnProcessor::isGlobalAiSearchSwitch('Turn on AI search'));
        self::assertFalse(AgentToolTurnProcessor::asksToChangeDatasourceUsage('Turn off AI search'));
    }
}
