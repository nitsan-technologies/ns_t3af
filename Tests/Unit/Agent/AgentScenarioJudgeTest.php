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

use NITSAN\NsT3AF\Agent\Eval\AgentScenarioJudge;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The eval judge catches turns without an answer, loops and wrong tools.
 *
 * @internal
 */
final class AgentScenarioJudgeTest extends TestCase
{
    #[Test]
    public function aTurnWithTheRightCardPasses(): void
    {
        $errors = (new AgentScenarioJudge())->judge(
            ['anyTool' => ['write_table'], 'card' => true, 'forbidTools' => ['content_delete']],
            $this->turn([self::call('pages_get', ['uid' => 45]), self::call('write_table', ['table' => 'pages'])], [self::card()]),
        );

        self::assertSame([], $errors);
    }

    #[Test]
    public function theStepLimitCountsAsNoAnswer(): void
    {
        $errors = (new AgentScenarioJudge())->judge([], $this->turn([], [], 'loop_limit'));

        self::assertSame(['No answer: the turn stopped with "loop_limit".'], $errors);
    }

    #[Test]
    public function repeatedReadsAndAMissingCardAreReported(): void
    {
        $errors = (new AgentScenarioJudge())->judge(
            ['anyTool' => ['write_table'], 'card' => true],
            $this->turn([
                self::call('pages_get', ['uid' => 45]),
                self::call('pages_get', ['uid' => 45], 'repeated'),
                self::call('pages_get', ['uid' => 45], 'repeated'),
            ], [['role' => 'assistant', 'content' => 'Shall we proceed?', 'meta' => ['type' => 'nl_reply']]]),
        );

        self::assertSame([
            'The same read was repeated 2 times (allowed: 1).',
            'Expected one of [write_table], ran [pages_get].',
            'Expected a prepared change to review, got none.',
        ], $errors);
    }

    #[Test]
    public function aForbiddenToolFailsEvenWhenItDidNotRun(): void
    {
        $errors = (new AgentScenarioJudge())->judge(
            ['forbidTools' => ['content_delete']],
            $this->turn([self::call('content_delete', ['uid' => 449], 'invalid')], []),
        );

        self::assertSame(['Must not call [content_delete].'], $errors);
    }

    #[Test]
    public function theNewPageUidMustBeUsedAsPid(): void
    {
        $judge = new AgentScenarioJudge();
        $expect = ['anyTool' => ['write_table'], 'argumentsContain' => ['pid' => 990001]];

        self::assertSame([], $judge->judge($expect, $this->turn([
            self::call('write_table', ['table' => 'tt_content', 'data' => '{"pid": 990001, "header": "Intro"}']),
        ], [self::card()])));
        self::assertSame(['Expected a call with pid = 990001.'], $judge->judge($expect, $this->turn([
            self::call('write_table', ['table' => 'tt_content', 'data' => ['pid' => 47]]),
        ], [self::card()])));
    }

    #[Test]
    public function aSettingsUpdateSendsOnlyWhatTheEditorNamed(): void
    {
        $judge = new AgentScenarioJudge();
        $expect = [
            'anyTool' => ['t3as_search_settings'],
            'argumentsContain' => ['primaryColor|primary_color' => '#1a73e8'],
            'argumentsExclude' => ['enableVoiceover|enable_voiceover', 'widgetHideOnMobile|widget_hide_on_mobile'],
        ];

        self::assertSame([], $judge->judge($expect, $this->turn([
            self::call('t3as_search_settings', ['operation' => 'update', 'settingsJson' => '{"primary_color":"#1A73E8"}']),
        ], [self::card()])));
        self::assertSame(['t3as_search_settings must not send [enableVoiceover|enable_voiceover].'], $judge->judge($expect, $this->turn([
            self::call('t3as_search_settings', ['settingsJson' => '{"primaryColor":"#1a73e8","enableVoiceover":false}']),
        ], [self::card()])));
    }

    #[Test]
    public function aQuestionBackSkipsTheArgumentCheckWhenAllowed(): void
    {
        $turn = $this->turn([self::call('ask_clarification', ['question' => 'Which chatbot?'])], [
            ['role' => 'assistant', 'content' => 'Which chatbot?', 'meta' => ['type' => 'clarification']],
        ]);

        self::assertSame([], (new AgentScenarioJudge())->judge(
            ['card' => 'orClarification', 'argumentsContain' => ['widget_hide_on_mobile' => true]],
            $turn,
        ));
    }

    #[Test]
    public function switchValuesCompareLoosely(): void
    {
        self::assertTrue(AgentScenarioJudge::containsKeyValue(['settingsJson' => '{"widgetHideOnMobile":true}'], 'widgetHideOnMobile|widget_hide_on_mobile', 1));
        self::assertTrue(AgentScenarioJudge::containsKeyValue(['widget_hide_on_mobile' => '1'], 'widgetHideOnMobile|widget_hide_on_mobile', true));
        self::assertFalse(AgentScenarioJudge::containsKeyValue(['widget_hide_on_mobile' => false], 'widget_hide_on_mobile', true));
    }

    #[Test]
    public function aGreetingNeedsAWrittenAnswerWithoutTools(): void
    {
        $judge = new AgentScenarioJudge();
        $expect = ['noTools' => true, 'reply' => true, 'card' => false];

        self::assertSame([], $judge->judge($expect, $this->turn([], [['role' => 'assistant', 'content' => 'Hello!', 'meta' => ['type' => 'nl_reply']]])));
        self::assertSame(
            ['Expected an answer without tools, ran [pages_tree].', 'Expected a written answer, got none.'],
            $judge->judge($expect, $this->turn([self::call('pages_tree', [])], [])),
        );
    }

    #[Test]
    public function slowTurnsAndFailuresAreReported(): void
    {
        $judge = new AgentScenarioJudge();
        $slow = $this->turn([], [['role' => 'assistant', 'content' => 'Hi', 'meta' => ['type' => 'nl_reply']]]);
        $slow['seconds'] = 75.0;
        $failed = $this->turn([], []);
        $failed['error'] = 'HTTP 500';

        self::assertSame(['Took 75.0 s (allowed: 30 s).'], $judge->judge(['maxSeconds' => 30], $slow));
        self::assertSame(['The turn failed: HTTP 500'], $judge->judge([], $failed));
    }

    #[Test]
    public function aClarificationCountsWhenAllowed(): void
    {
        $turn = $this->turn([self::call('ask_clarification', ['question' => 'Which language?'])], [
            ['role' => 'assistant', 'content' => 'Which language?', 'meta' => ['type' => 'clarification']],
        ]);

        self::assertSame([], (new AgentScenarioJudge())->judge(['card' => 'orClarification'], $turn));
        self::assertSame(['Expected a prepared change to review, got none.'], (new AgentScenarioJudge())->judge(['card' => true], $turn));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{tool: string, arguments: array<mixed>, outcome: string}
     */
    private static function call(string $tool, array $arguments, string $outcome = 'executed'): array
    {
        return ['tool' => $tool, 'arguments' => $arguments, 'outcome' => $outcome];
    }

    /**
     * @return array<string, mixed>
     */
    private static function card(): array
    {
        return ['role' => 'assistant', 'content' => 'Prepared', 'meta' => ['type' => 'inline_draft', 'draft' => ['draftId' => 'd1']]];
    }

    /**
     * @param list<array{tool: string, arguments: array<mixed>, outcome: string}> $calls
     * @param list<array<string, mixed>> $messages
     * @return array{messages: list<array<string, mixed>>, paused: bool, pauseReason: string|null, toolCalls: list<array{tool: string, arguments: array<mixed>, outcome: string}>, modelRequests: int, seconds: float, error: string|null}
     */
    private function turn(array $calls, array $messages, ?string $pauseReason = null): array
    {
        return [
            'messages' => $messages,
            'paused' => $pauseReason !== null,
            'pauseReason' => $pauseReason,
            'toolCalls' => $calls,
            'modelRequests' => 2,
            'seconds' => 3.0,
            'error' => null,
        ];
    }
}
