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
use NITSAN\NsT3AF\Agent\Contract\AgentToolIndexInterface;
use NITSAN\NsT3AF\Agent\Contract\AgentToolTurnExecutorInterface;
use NITSAN\NsT3AF\Agent\Embedding\EmbeddingSourceResolver;
use NITSAN\NsT3AF\Agent\Runtime\T3afToolbox;
use NITSAN\NsT3AF\Agent\Service\AgentCoreToolSet;
use NITSAN\NsT3AF\Agent\Service\AgentLowRiskFieldMatrix;
use NITSAN\NsT3AF\Agent\Service\AgentPausePolicy;
use NITSAN\NsT3AF\Agent\Service\AgentPromptBuilder;
use NITSAN\NsT3AF\Agent\Service\AgentRunner;
use NITSAN\NsT3AF\Agent\Service\AgentSettingsService;
use NITSAN\NsT3AF\Agent\Service\AgentToolArgumentValidator;
use NITSAN\NsT3AF\Agent\Service\AgentToolDefinitionMapper;
use NITSAN\NsT3AF\Agent\Service\AgentToolDocumentBuilder;
use NITSAN\NsT3AF\Agent\Service\AgentToolEditorLabelService;
use NITSAN\NsT3AF\Agent\Service\AgentToolSearch;
use NITSAN\NsT3AF\Agent\Service\AgentTranslator;
use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiToolCall;
use NITSAN\NsT3AF\Api\AiToolCallingResponse;
use NITSAN\NsT3AF\Api\AiToolCallingServiceInterface;
use NITSAN\NsT3AF\Api\AiToolDefinition;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolMetadataService;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * @internal
 */
final class AgentRunnerTest extends TestCase
{
    /** @var list<array{messages: list<array<mixed>>, tools: list<string>, options: AiOptions}> */
    private array $requests = [];

    /** @var list<string> */
    private array $executed = [];

    /** @var list<array<mixed>> */
    private array $executedArguments = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requests = [];
        $this->executed = [];
        $this->executedArguments = [];
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
    }

    #[Test]
    public function plainAnswerBecomesOneReply(): void
    {
        $result = $this->runScripted([new AiToolCallingResponse('Hallo! Wie kann ich helfen?', 'gpt-test', 'openai')]);

        self::assertFalse($result['paused']);
        self::assertCount(1, $result['messages']);
        self::assertSame('Hallo! Wie kann ich helfen?', $result['messages'][0]['content']);
        self::assertSame('nl_reply', $result['messages'][0]['meta']['type']);
        self::assertSame('gpt-test', $result['messages'][0]['meta']['modelId']);
        self::assertSame(['pages_get', 'find_tools'], $this->requests[0]['tools']);
        self::assertSame('agent.nl_turn', $this->requests[0]['options']->featureKey);
    }

    #[Test]
    public function readToolResultIsSentBackAsToolRound(): void
    {
        $result = $this->runScripted([
            new AiToolCallingResponse('', 'gpt-test', 'openai', [new AiToolCall('call_1', 'pages_get', ['uid' => 49])]),
            new AiToolCallingResponse('Die Seite heißt "AI ChEddi".', 'gpt-test', 'openai'),
        ]);

        self::assertSame(['pages_get'], $this->executed);
        self::assertCount(2, $this->requests);
        self::assertSame(['tool_result', 'nl_reply'], array_map(static fn(array $m): string => $m['meta']['type'], $result['messages']));

        $second = $this->requests[1]['messages'];
        $assistant = $second[count($second) - 2];
        $tool = $second[count($second) - 1];
        self::assertSame('assistant', $assistant['role']);
        self::assertSame([['id' => 'call_1', 'name' => 'pages_get', 'arguments' => ['uid' => 49]]], $assistant['tool_calls']);
        self::assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'pages_get', 'content' => 'Page 49: AI ChEddi'], $tool);
    }

    #[Test]
    public function draftNeedingReviewEndsTheTurnAndSkipsLaterCalls(): void
    {
        $result = $this->runScripted([
            new AiToolCallingResponse('', 'gpt-test', 'openai', [
                new AiToolCall('call_1', 'content_update', ['uid' => 7, 'header' => 'Neu']),
                new AiToolCall('call_2', 'pages_get', ['uid' => 49]),
            ]),
        ]);

        self::assertTrue($result['paused']);
        self::assertSame('draft_review', $result['pauseReason']);
        self::assertSame(['content_update'], $this->executed);
        self::assertCount(1, $this->requests);
    }

    #[Test]
    public function toolThatWasNotOfferedNeverRuns(): void
    {
        $this->runScripted([
            new AiToolCallingResponse('', 'gpt-test', 'openai', [new AiToolCall('call_1', 'backend_user_delete', ['uid' => 1])]),
            new AiToolCallingResponse('Das kann ich nicht.', 'gpt-test', 'openai'),
        ]);

        self::assertSame([], $this->executed);
        $tool = $this->requests[1]['messages'][count($this->requests[1]['messages']) - 1];
        self::assertStringContainsString('not available', (string) $tool['content']);
    }

    #[Test]
    public function governanceCancelShowsTheReason(): void
    {
        $result = $this->runScripted([
            new AiToolCallingResponse('', '', '', raw: ['cancelled' => 'Daily limit reached']),
        ]);

        self::assertFalse($result['paused']);
        self::assertSame('governance_blocked', $result['messages'][0]['meta']['type']);
        self::assertStringContainsString('agent.turn.governanceBlocked', $result['messages'][0]['content']);
    }

    #[Test]
    public function readOverTheBudgetAsksTheModelToUseWhatItHas(): void
    {
        $calls = [];
        for ($i = 1; $i <= 6; ++$i) {
            $calls[] = new AiToolCall('call_' . $i, 'pages_get', ['uid' => $i]);
        }
        $result = $this->runScripted([
            new AiToolCallingResponse('', 'gpt-test', 'openai', $calls),
            new AiToolCallingResponse('Hier ist die Antwort.', 'gpt-test', 'openai'),
        ]);

        self::assertFalse($result['paused']);
        self::assertCount(5, $this->executed);
        $toolMessage = $this->requests[1]['messages'][count($this->requests[1]['messages']) - 1];
        self::assertStringContainsString('Use what you already have', (string) $toolMessage['content']);
        self::assertSame('nl_reply', $result['messages'][count($result['messages']) - 1]['meta']['type']);
    }

    #[Test]
    public function readBudgetPausesWhenTheModelKeepsReading(): void
    {
        $calls = [];
        for ($i = 1; $i <= 5 + T3afToolbox::READ_REFUSALS_BEFORE_PAUSE; ++$i) {
            $calls[] = new AiToolCall('call_' . $i, 'pages_get', ['uid' => $i]);
        }
        $result = $this->runScripted([new AiToolCallingResponse('', 'gpt-test', 'openai', $calls)]);

        self::assertTrue($result['paused']);
        self::assertSame('read_budget', $result['pauseReason']);
        self::assertCount(5, $this->executed);
        self::assertSame('budget_exceeded', $result['messages'][5]['meta']['type']);
    }

    #[Test]
    public function theSameReadRunsOnceAndDoesNotUseTheBudget(): void
    {
        $result = $this->runScripted([
            new AiToolCallingResponse('', 'gpt-test', 'openai', [new AiToolCall('call_1', 'pages_get', ['uid' => 7])]),
            new AiToolCallingResponse('', 'gpt-test', 'openai', [new AiToolCall('call_2', 'pages_get', ['uid' => 7])]),
            new AiToolCallingResponse('Fertig.', 'gpt-test', 'openai'),
        ], readBudget: 1);

        self::assertFalse($result['paused']);
        self::assertSame(['pages_get'], $this->executed);
        $toolMessage = $this->requests[2]['messages'][count($this->requests[2]['messages']) - 1];
        self::assertStringContainsString('Already read in this turn', (string) $toolMessage['content']);
    }

    #[Test]
    public function repeatingTheSameReadEndsTheTurnWithAHint(): void
    {
        $responses = [];
        for ($i = 0; $i <= T3afToolbox::REPEATED_READS_BEFORE_STOP + 1; ++$i) {
            $responses[] = new AiToolCallingResponse('', 'gpt-test', 'openai', [new AiToolCall('c' . $i, 'pages_get', ['uid' => 45])]);
        }
        $result = $this->runScripted($responses);

        self::assertTrue($result['paused']);
        self::assertSame('repeated_reads', $result['pauseReason']);
        self::assertSame(['pages_get'], $this->executed);
        self::assertStringContainsString('agent.turn.repeatedReads', $result['messages'][count($result['messages']) - 1]['content']);
    }

    #[Test]
    public function toolsThatFitTheRequestAreOfferedRightAway(): void
    {
        $this->runScripted([new AiToolCallingResponse('Ok', 'gpt-test', 'openai')], message: 'Please update the header of content element 7');

        self::assertContains('content_update', $this->requests[0]['tools']);
    }

    #[Test]
    public function createContentRequestPrimesCreateToolsAndDropsDelete(): void
    {
        $this->runScripted(
            [new AiToolCallingResponse('Ok', 'gpt-test', 'openai')],
            message: 'Create all elements in this page',
            history: [['role' => 'assistant', 'content' => 'deleted', 'meta' => ['type' => 'tool_result', 'tool' => 'content_delete']]],
            extraTools: [
                ['name' => 'content_delete', 'severity' => 'destructive', 'description' => 'Delete a content element', 'params' => [['name' => 'uid', 'type' => 'int', 'required' => true]]],
                ['name' => 't3ai_create_content_element', 'severity' => 'write', 'description' => 'Create a header or text content element', 'params' => []],
                ['name' => 'write_table', 'severity' => 'write', 'description' => 'Create update or delete table records', 'params' => []],
            ],
        );

        $tools = $this->requests[0]['tools'];
        self::assertContains('t3ai_create_content_element', $tools);
        self::assertContains('write_table', $tools);
        self::assertNotContains('content_delete', $tools);
    }

    #[Test]
    public function createContentToolsPicksNamedAndIntentMatchedTools(): void
    {
        $catalog = [
            ['name' => 'content_delete', 'intent' => ['category' => 'content', 'verbs' => ['delete'], 'nouns' => ['content']]],
            ['name' => 't3ai_create_content_element', 'intent' => null],
            ['name' => 'write_table', 'intent' => null],
            ['name' => 'other_create_block', 'intent' => ['category' => 'content', 'verbs' => ['add'], 'nouns' => ['element']]],
            ['name' => 'pages_create', 'intent' => ['category' => 'pages', 'verbs' => ['create'], 'nouns' => ['page']]],
        ];
        $names = array_map(
            static fn(array $t): string => (string) $t['name'],
            AgentRunner::createContentTools($catalog),
        );
        sort($names);

        self::assertSame(['other_create_block', 't3ai_create_content_element', 'write_table'], $names);
    }

    #[Test]
    public function aShortReplyIsSearchedWithThePreviousRequest(): void
    {
        $query = AgentRunner::requestQuery('Yes', [
            ['role' => 'user', 'content' => 'create a new page for AI Universe vs Symfony', 'meta' => []],
            ['role' => 'assistant', 'content' => 'Read the page tree.', 'meta' => ['type' => 'tool_result']],
            ['role' => 'assistant', 'content' => 'Shall we proceed?', 'meta' => ['type' => 'nl_reply']],
        ]);

        self::assertSame("create a new page for AI Universe vs Symfony\nShall we proceed?\nYes", $query);
        self::assertSame('Translate page 12 into German', AgentRunner::requestQuery('Translate page 12 into German', []));
    }

    #[Test]
    public function endlessToolCallingStopsAtTheLoopLimit(): void
    {
        $responses = [];
        for ($i = 0; $i < AgentRunner::MAX_MODEL_ROUNDS + 1; ++$i) {
            $responses[] = new AiToolCallingResponse('', 'gpt-test', 'openai', [new AiToolCall('c' . $i, 'pages_get', ['uid' => $i + 1])]);
        }
        $result = $this->runScripted($responses, readBudget: 50);

        self::assertTrue($result['paused']);
        self::assertSame('loop_limit', $result['pauseReason']);
        self::assertCount(AgentRunner::MAX_MODEL_ROUNDS + 1, $this->requests);
    }

    #[Test]
    public function findToolsOffersTheMatchForTheNextRound(): void
    {
        $result = $this->runScripted([
            new AiToolCallingResponse('', 'gpt-test', 'openai', [new AiToolCall('call_1', 'find_tools', ['query' => 'update content element header'])]),
            new AiToolCallingResponse('', 'gpt-test', 'openai', [new AiToolCall('call_2', 'content_update', ['uid' => 7])]),
        ]);

        self::assertNotContains('content_update', $this->requests[0]['tools']);
        self::assertContains('content_update', $this->requests[1]['tools']);
        $toolMessage = $this->requests[1]['messages'][count($this->requests[1]['messages']) - 1];
        self::assertStringContainsString('content_update (write)', (string) $toolMessage['content']);
        self::assertSame(['content_update'], $this->executed);
        self::assertSame('draft_review', $result['pauseReason']);
    }

    #[Test]
    public function invalidArgumentsGoBackToTheModelWithoutRunningTheTool(): void
    {
        $this->runScripted([
            new AiToolCallingResponse('', 'gpt-test', 'openai', [new AiToolCall('call_1', 'pages_get', [])]),
            new AiToolCallingResponse('Welche Seite?', 'gpt-test', 'openai'),
        ]);

        self::assertSame([], $this->executed);
        $toolMessage = $this->requests[1]['messages'][count($this->requests[1]['messages']) - 1];
        self::assertStringContainsString('invalid arguments for pages_get', (string) $toolMessage['content']);
        self::assertStringContainsString('uid', (string) $toolMessage['content']);
    }

    #[Test]
    public function numericStringArgumentIsCoerced(): void
    {
        $this->runScripted([
            new AiToolCallingResponse('', 'gpt-test', 'openai', [new AiToolCall('call_1', 'pages_get', ['uid' => '49'])]),
            new AiToolCallingResponse('Done', 'gpt-test', 'openai'),
        ]);

        self::assertSame([['uid' => 49]], $this->executedArguments);
    }

    #[Test]
    public function providerErrorIsReportedWithoutPause(): void
    {
        $toolCalling = $this->createMock(AiToolCallingServiceInterface::class);
        $toolCalling->method('supportsToolCalling')->willReturn(true);
        $toolCalling->method('completeWithTools')->willThrowException(new \RuntimeException('HTTP 500'));

        $result = $this->makeRunner($toolCalling, 5)->runTurn('Hi', [], [], [], $this->createMock(BackendUserAuthentication::class), 'corr');

        self::assertFalse($result['paused']);
        self::assertCount(1, $result['messages']);
        self::assertSame('error', $result['messages'][0]['meta']['type']);
    }

    /**
     * @param list<AiToolCallingResponse> $responses
     * @param list<array<string, mixed>> $history
     * @param list<array<string, mixed>> $extraTools
     * @return array{messages: list<array{role: string, content: string, meta: array<string, mixed>}>, paused: bool, pauseReason: string|null}
     */
    private function runScripted(
        array $responses,
        int $readBudget = 5,
        string $message = 'Wie heißt diese Seite?',
        array $history = [],
        array $extraTools = [],
    ): array {
        $toolCalling = $this->createMock(AiToolCallingServiceInterface::class);
        $toolCalling->method('supportsToolCalling')->willReturn(true);
        $toolCalling->method('completeWithTools')->willReturnCallback(
            function (array $messages, array $tools, AiOptions $options) use (&$responses): AiToolCallingResponse {
                $this->record($messages, $tools, $options);
                $next = array_shift($responses);
                self::assertNotNull($next, 'More model requests than scripted.');

                return $next;
            },
        );

        return $this->makeRunner($toolCalling, $readBudget, $extraTools)
            ->runTurn($message, $history, ['pageId' => 49, 'module' => 'web_layout'], [], $this->createMock(BackendUserAuthentication::class), 'corr-1');
    }

    /**
     * @param array<mixed> $messages
     * @param array<mixed> $tools
     */
    private function record(array $messages, array $tools, AiOptions $options): void
    {
        $names = [];
        foreach ($tools as $tool) {
            self::assertInstanceOf(AiToolDefinition::class, $tool);
            $names[] = $tool->name;
        }
        $rows = [];
        foreach ($messages as $message) {
            self::assertIsArray($message);
            $rows[] = $message;
        }
        $this->requests[] = ['messages' => $rows, 'tools' => $names, 'options' => $options];
    }

    /**
     * @param list<array<string, mixed>> $extraTools
     */
    private function makeRunner(AiToolCallingServiceInterface $toolCalling, int $readBudget, array $extraTools = []): AgentRunner
    {
        $tools = [
            ['name' => 'pages_get', 'severity' => 'read', 'description' => 'Get a page', 'params' => [['name' => 'uid', 'type' => 'int', 'required' => true, 'description' => 'Page uid']]],
            ['name' => 'content_update', 'severity' => 'write', 'description' => 'Update a content element (header, text)', 'params' => [['name' => 'uid', 'type' => 'int', 'required' => true]]],
            ['name' => 'explain_page', 'severity' => 'read', 'description' => 'Explain the page', 'params' => []],
            ...$extraTools,
        ];
        $catalog = $this->createMock(AgentActionCatalogInterface::class);
        $catalog->method('buildCatalog')->willReturn(['executable' => $tools, 'locked' => []]);

        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn([
            'agentEmbeddingSource' => 'none',
            'agentMinSimilarity' => '0',
            'agentMaxReadToolsPerTurn' => (string) $readBudget,
            'agentMaxWriteDraftsPerTurn' => '2',
            'agentShowProviderThinking' => '0',
        ]);
        $settings = new AgentSettingsService($extensionSettings);
        $translator = (new \ReflectionClass(AgentTranslator::class))->newInstanceWithoutConstructor();
        $documents = new AgentToolDocumentBuilder(new AgentToolEditorLabelService($translator), new McpToolMetadataService());
        $toolSearch = new AgentToolSearch(
            $this->createMock(AgentToolIndexInterface::class),
            new EmbeddingSourceResolver($settings, []),
            $documents,
            $settings,
        );

        $introspector = $this->createMock(McpToolIntrospectorService::class);
        $introspector->method('listTools')->willReturn($tools);

        $executor = $this->createMock(AgentToolTurnExecutorInterface::class);
        $executor->method('execute')->willReturnCallback(
            function (string $tool, array $context, array $body): array {
                $this->executed[] = $tool;
                $this->executedArguments[] = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
                if ($tool === 'content_update') {
                    return [
                        'role' => 'assistant',
                        'content' => 'Draft ready',
                        'meta' => [
                            'type' => 'inline_draft',
                            'orchestratorPause' => true,
                            'severity' => 'write',
                            'draft' => ['fields' => [['table' => 'tt_content', 'field' => 'header']]],
                        ],
                    ];
                }

                return [
                    'role' => 'assistant',
                    'content' => 'Page ' . (int) ($body['arguments']['uid'] ?? 0),
                    'meta' => ['type' => 'tool_result', 'llmSummary' => 'Page ' . (int) ($body['arguments']['uid'] ?? 0) . ': AI ChEddi'],
                ];
            },
        );

        $prompt = $this->createMock(AgentPromptBuilder::class);
        $prompt->method('buildSystemPrompt')->willReturn('SYSTEM');
        $prompt->method('buildHistory')->willReturn([]);

        return new AgentRunner(
            $toolCalling,
            $catalog,
            new AgentToolDefinitionMapper($introspector),
            new AgentCoreToolSet($documents),
            $toolSearch,
            new AgentToolArgumentValidator(),
            $executor,
            $settings,
            $prompt,
            new AgentPausePolicy(new AgentLowRiskFieldMatrix()),
            $translator,
        );
    }
}
