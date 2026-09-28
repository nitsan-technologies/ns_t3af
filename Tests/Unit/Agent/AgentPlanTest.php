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

use NITSAN\NsT3AF\Agent\Runtime\AgentTurnState;
use NITSAN\NsT3AF\Agent\Service\AgentPlan;
use NITSAN\NsT3AF\Agent\Service\AgentPromptBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The "Progress" plan: saved with the next assistant message, replayed to the model while unfinished.
 *
 * @internal
 */
#[CoversClass(AgentTurnState::class)]
#[CoversClass(AgentPromptBuilder::class)]
#[CoversClass(AgentPlan::class)]
final class AgentPlanTest extends TestCase
{
    #[Test]
    public function aPlanChangeIsSavedWithTheNextAssistantMessageAndEmitted(): void
    {
        $events = [];
        $state = new AgentTurnState('c1', static function (string $event, array $payload) use (&$events): void {
            $events[] = [$event, $payload];
        });
        $steps = [['title' => 'Create page', 'status' => 'in_progress']];

        $state->setPlan($steps);
        $state->addMessage(['role' => 'assistant', 'content' => 'x', 'meta' => []]);
        $state->addMessage(['role' => 'assistant', 'content' => 'y', 'meta' => []]);

        self::assertSame($steps, $state->messages[0]['meta']['plan']);
        self::assertArrayNotHasKey('plan', $state->messages[1]['meta']);
        self::assertSame('plan', $events[0][0]);
    }

    #[Test]
    public function aPendingPlanChangeIsAttachedToTheNewestAssistantMessage(): void
    {
        $state = new AgentTurnState('c1');
        $state->addMessage(['role' => 'assistant', 'content' => 'x', 'meta' => []]);
        $state->setPlan([['title' => 'A', 'status' => 'completed']]);
        $state->attachPendingPlan();

        self::assertSame('completed', $state->messages[0]['meta']['plan'][0]['status']);
    }

    #[Test]
    public function anUnfinishedPlanIsReplayedToTheModel(): void
    {
        $history = [
            ['role' => 'assistant', 'content' => 'x', 'meta' => ['plan' => [
                ['title' => 'Create page', 'status' => 'completed'],
                ['title' => 'Add content', 'status' => 'in_progress'],
            ]]],
            ['role' => 'assistant', 'content' => 'y', 'meta' => []],
        ];

        $block = AgentPromptBuilder::planBlock($history);

        self::assertStringContainsString('1. [completed] Create page', $block);
        self::assertStringContainsString('2. [in_progress] Add content', $block);
    }

    #[Test]
    public function aFinishedOrMissingPlanIsNotReplayed(): void
    {
        self::assertSame('', AgentPromptBuilder::planBlock([]));
        self::assertSame('', AgentPromptBuilder::planBlock([
            ['role' => 'assistant', 'content' => 'x', 'meta' => ['plan' => [['title' => 'A', 'status' => 'completed']]]],
        ]));
    }

    #[Test]
    public function aConfirmedChangeFinishesTheCurrentStepAndStartsTheNext(): void
    {
        $steps = [
            ['title' => 'Create', 'status' => 'completed'],
            ['title' => 'SEO', 'status' => 'in_progress'],
            ['title' => 'Translate', 'status' => 'pending'],
        ];

        $advanced = AgentPlan::advance($steps);

        self::assertSame(['completed', 'completed', 'in_progress'], array_column($advanced, 'status'));
        self::assertSame(['completed', 'completed', 'completed'], array_column(AgentPlan::advance($advanced), 'status'));
    }

    #[Test]
    public function aCleanAnswerLeavesNoStepOpen(): void
    {
        $steps = [['title' => 'A', 'status' => 'in_progress'], ['title' => 'B', 'status' => 'pending']];

        self::assertTrue(AgentPlan::hasOpenSteps($steps));
        self::assertFalse(AgentPlan::hasOpenSteps(AgentPlan::completeAll($steps)));
    }
}
