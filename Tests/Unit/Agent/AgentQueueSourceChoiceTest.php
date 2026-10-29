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
        self::assertFalse(AgentToolTurnProcessor::asksForChatbotSettings('List the chatbot data sources'));
    }
}
