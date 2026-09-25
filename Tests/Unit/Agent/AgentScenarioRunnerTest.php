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

use NITSAN\NsT3AF\Agent\Contract\AgentActionCatalogInterface;
use NITSAN\NsT3AF\Agent\Contract\AgentTurnRunnerInterface;
use NITSAN\NsT3AF\Agent\Eval\AgentScenarioJudge;
use NITSAN\NsT3AF\Agent\Eval\AgentScenarioRunner;
use NITSAN\NsT3AF\Agent\Service\AgentConversationRecorder;
use NITSAN\NsT3AF\Agent\Service\AgentToolEditorLabelService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The eval runs scenario turns through the agent runner and simulates the editor's apply.
 *
 * @internal
 */
final class AgentScenarioRunnerTest extends TestCase
{
    use AgentTranslatorTrait;

    /** @var list<array{message: string, history: array<mixed>}> */
    private array $calls = [];

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function pageThenContentPassesWhenTheNewUidIsUsed(): void
    {
        $runner = $this->runner([
            // Turn 1: the page card.
            static function (callable $emit): array {
                $emit('model_request', []);
                $emit('tool_call', ['tool' => 'write_table', 'arguments' => ['table' => 'pages', 'action' => 'create'], 'outcome' => 'executed']);

                return self::turnResult([self::card('d1', 'Create page')]);
            },
            // Turn 2 (after apply): the content card on the new page.
            static function (callable $emit): array {
                $emit('model_request', []);
                $emit('tool_call', ['tool' => 'write_table', 'arguments' => ['table' => 'tt_content', 'data' => ['pid' => 990001]], 'outcome' => 'executed']);

                return self::turnResult([self::card('d2', 'Create content')]);
            },
        ]);

        $report = $runner->run($this->scenario(), 'openai', ['pageId' => 47], $this->createMock(BackendUserAuthentication::class));

        self::assertSame('pass', $report['status'], implode("\n", $report['errors']));
        self::assertCount(2, $report['turns']);
        // The second turn is the continuation the window sends after "apply", with the new uid.
        self::assertStringContainsString('990001', $this->calls[1]['message']);
        self::assertStringContainsString('[The editor confirmed', $this->calls[1]['message']);
        $appliedCard = $this->calls[1]['history'][1];
        self::assertTrue($appliedCard['meta']['draft']['applied']);
    }

    #[Test]
    public function aLoopFailsAndStopsTheScenario(): void
    {
        $runner = $this->runner([
            static fn(callable $emit): array => ['messages' => [], 'paused' => true, 'pauseReason' => 'loop_limit'],
        ]);

        $report = $runner->run($this->scenario(), 'openai', ['pageId' => 47], $this->createMock(BackendUserAuthentication::class));

        self::assertSame('fail', $report['status']);
        self::assertCount(1, $report['turns']);
        self::assertContains('Turn 1: No answer: the turn stopped with "loop_limit".', $report['errors']);
    }

    #[Test]
    public function aScenarioWithoutItsToolIsSkipped(): void
    {
        $scenario = ['id' => 'x', 'requires' => ['t3ai_generate_image'], 'turns' => [['message' => 'Draw', 'expect' => []]]];

        $report = $this->runner([])->run($scenario, 'openai', ['pageId' => 47], $this->createMock(BackendUserAuthentication::class));

        self::assertSame('skip', $report['status']);
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function placeholdersAreFilledIn(): void
    {
        self::assertSame(
            ['pid' => 47, 'text' => 'Page 47'],
            AgentScenarioRunner::withPlaceholders(['pid' => '{{pageId}}', 'text' => 'Page {{pageId}}'], ['{{pageId}}' => 47]),
        );
    }

    #[Test]
    public function theShippedScenariosAreValid(): void
    {
        $runner = $this->runner([]);
        $scenarios = $runner->load();
        $knownExpectations = ['anyTool', 'forbidTools', 'noTools', 'card', 'reply', 'argumentsContain', 'maxToolCalls', 'maxModelRequests', 'maxSeconds', 'maxRepeatedCalls', 'argumentsExclude'];

        self::assertGreaterThanOrEqual(10, count($scenarios));
        $ids = [];
        foreach ($scenarios as $scenario) {
            $ids[] = $scenario['id'];
            self::assertNotSame('', trim((string) ($scenario['title'] ?? '')), $scenario['id']);
            foreach ($scenario['turns'] as $turn) {
                self::assertTrue(isset($turn['message']) || isset($turn['applied']), $scenario['id']);
                self::assertSame([], array_diff(array_keys($turn['expect'] ?? []), $knownExpectations), $scenario['id']);
            }
        }
        self::assertSame($ids, array_values(array_unique($ids)));
        self::assertCount(2, $runner->load(null, ['04', '05']));
    }

    /**
     * @param list<\Closure(callable): array{messages: list<array<string, mixed>>, paused: bool, pauseReason: string|null}> $script
     */
    private function runner(array $script): AgentScenarioRunner
    {
        $turnRunner = $this->createMock(AgentTurnRunnerInterface::class);
        $turnRunner->method('runTurn')->willReturnCallback(
            function (string $message, array $history, array $context, array $body, BackendUserAuthentication $user, string $correlationId, ?callable $emit) use (&$script): array {
                $this->calls[] = ['message' => $message, 'history' => $history];
                $next = array_shift($script);
                self::assertNotNull($next, 'More turns than scripted.');

                return $next($emit ?? static function (): void {});
            },
        );
        $catalog = $this->createMock(AgentActionCatalogInterface::class);
        $catalog->method('buildCatalog')->willReturn(['executable' => [['name' => 'write_table'], ['name' => 'pages_get']], 'locked' => []]);
        $translator = $this->createAgentTranslator();

        return new AgentScenarioRunner(
            $turnRunner,
            new AgentConversationRecorder($translator, new AgentToolEditorLabelService($translator)),
            $catalog,
            new AgentScenarioJudge(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        return [
            'id' => 'page-then-content',
            'title' => 'Page first, then content',
            'turns' => [
                ['message' => 'Create a subpage with two text elements', 'expect' => ['anyTool' => ['write_table'], 'card' => true]],
                [
                    'applied' => ['table' => 'pages', 'uid' => 990001, 'values' => ['title' => 'Eval', 'pid' => '{{pageId}}']],
                    'expect' => ['anyTool' => ['write_table'], 'card' => true, 'argumentsContain' => ['pid' => 990001]],
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array{messages: list<array<string, mixed>>, paused: bool, pauseReason: string|null}
     */
    private static function turnResult(array $messages): array
    {
        return ['messages' => $messages, 'paused' => true, 'pauseReason' => 'draft_review'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function card(string $draftId, string $label): array
    {
        return [
            'role' => 'assistant',
            'content' => $label,
            'meta' => ['type' => 'inline_draft', 'tool' => 'write_table', 'editorLabel' => $label, 'draft' => ['draftId' => $draftId, 'fields' => []]],
        ];
    }
}
