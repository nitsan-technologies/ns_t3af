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

use NITSAN\NsT3AF\Access\ExtensionAvailability;
use NITSAN\NsT3AF\Agent\Contract\AgentActionCatalogInterface;
use NITSAN\NsT3AF\Agent\Contract\AgentToolIndexInterface;
use NITSAN\NsT3AF\Agent\Contract\AgentToolTurnExecutorInterface;
use NITSAN\NsT3AF\Agent\Embedding\EmbeddingSourceResolver;
use NITSAN\NsT3AF\Agent\Entitlement\EntitlementResolver;
use NITSAN\NsT3AF\Agent\PremiumCatalog\PremiumCatalogProvider;
use NITSAN\NsT3AF\Agent\Runtime\T3afToolbox;
use NITSAN\NsT3AF\Agent\Service\AgentCoreToolSet;
use NITSAN\NsT3AF\Agent\Service\AgentEntitlementExplanation;
use NITSAN\NsT3AF\Agent\Service\AgentLanguageResolver;
use NITSAN\NsT3AF\Agent\Service\AgentLowRiskFieldMatrix;
use NITSAN\NsT3AF\Agent\Service\AgentPausePolicy;
use NITSAN\NsT3AF\Agent\Service\AgentPlan;
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
use NITSAN\NsT3AF\Tests\Unit\Access\Support\LoadedExtensionsTestTrait;
use NITSAN\NsT3AF\Utility\ModuleTabUtility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * @internal
 */
final class AgentRunnerTest extends TestCase
{
    use LoadedExtensionsTestTrait;

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

    protected function tearDown(): void
    {
        $this->resetLoadedExtensions();
        parent::tearDown();
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
        self::assertSame(['pages_get', 'find_tools', 'update_plan'], $this->requests[0]['tools']);
        self::assertSame('agent.nl_turn', $this->requests[0]['options']->featureKey);
    }

    #[Test]
    public function cardHistoryEchoIsRetriedAndNotShownToTheEditor(): void
    {
        $parrot = '[Prepared change: Change a record — applied] Review the proposed changes for Change a record before anything is written.';
        $result = $this->runScripted([
            new AiToolCallingResponse($parrot, 'gpt-test', 'openai'),
            new AiToolCallingResponse('I will prepare the next content element next.', 'gpt-test', 'openai'),
        ]);

        self::assertCount(2, $this->requests, 'A card-history echo must trigger one corrective model call.');
        self::assertSame('nl_reply', $result['messages'][0]['meta']['type']);
        self::assertSame('I will prepare the next content element next.', $result['messages'][0]['content']);
        self::assertStringNotContainsString('[Prepared change:', $result['messages'][0]['content']);
        $nudge = (string) ($this->requests[1]['messages'][count($this->requests[1]['messages']) - 1]['content'] ?? '');
        self::assertStringContainsString('Do not quote or repeat', $nudge);
    }

    #[Test]
    public function falseDoneNlReplyIsSuppressedWhenProgressStillOpen(): void
    {
        $history = [
            [
                'role' => 'user',
                'content' => 'Add Text & Media with a relevant image, Text, and Bullets about AI.',
                'meta' => [],
            ],
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 546, 'values' => ['CType' => 'textmedia']]],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'x',
                'meta' => ['plan' => [
                    ['title' => 'Text & Media', 'status' => 'completed'],
                    ['title' => 'Text', 'status' => 'completed'],
                    ['title' => 'Bullets', 'status' => 'completed'],
                    ['title' => 'Attach an image to Text & Media element', 'status' => 'in_progress'],
                ]],
            ],
        ];
        $done = 'All requested content elements have been successfully added to the page.';
        $result = $this->runScripted(
            [
                new AiToolCallingResponse($done, 'gpt-test', 'openai'),
                new AiToolCallingResponse($done, 'gpt-test', 'openai'),
            ],
            message: '[The editor confirmed "Change a record" and it was applied.] Continue with the remaining steps of my request.',
            history: $history,
            extraTools: [
                ['name' => 't3ai_generate_image', 'severity' => 'write', 'description' => 'Generate image', 'params' => []],
                ['name' => 'file_reference_add', 'severity' => 'write', 'description' => 'Attach file', 'params' => []],
                ['name' => 'write_table', 'severity' => 'write', 'description' => 'Write', 'params' => []],
            ],
        );

        self::assertSame('nl_reply', $result['messages'][0]['meta']['type']);
        self::assertStringNotContainsString('successfully added', strtolower($result['messages'][0]['content']));
        $plan = $result['messages'][0]['meta']['plan'] ?? [];
        self::assertNotSame([], $plan);
        self::assertTrue(
            AgentPlan::hasOpenSteps($plan) || AgentPlan::hasOpenImageAttachStep($plan),
            'Progress must keep the attach (or other) step open',
        );
    }

    #[Test]
    public function repeatedCardHistoryEchoIsDropped(): void
    {
        $parrot = '[Prepared change: Change a record — applied] Review the proposed changes for Change a record before anything is written.';
        $result = $this->runScripted([
            new AiToolCallingResponse($parrot, 'gpt-test', 'openai'),
            new AiToolCallingResponse($parrot, 'gpt-test', 'openai'),
        ]);

        self::assertCount(2, $this->requests);
        self::assertSame('nl_reply', $result['messages'][0]['meta']['type']);
        self::assertStringNotContainsString('[Prepared change:', $result['messages'][0]['content']);
        self::assertStringNotContainsString('Review the proposed changes', $result['messages'][0]['content']);
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
    public function theSamePrepareFailureIsShownOnlyOnce(): void
    {
        $failure = [
            'role' => 'assistant',
            'content' => 'Could not prepare this change.',
            'meta' => [
                'type' => 'tool_result',
                'tool' => 'pages_move',
                'success' => false,
                'error' => 'Provide exactly one of targetPid or afterUid, not both.',
            ],
        ];

        self::assertFalse(T3afToolbox::isRepeatedPlanFailure($failure, []));
        self::assertTrue(T3afToolbox::isRepeatedPlanFailure($failure, [$failure]));
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
    public function aPageMoveRequestKeepsPagesMoveInTheToolbox(): void
    {
        self::assertTrue(AgentRunner::isPageMoveRequest('move page Page between 1 and 2 with uid 80 before page Page 2 with uid 69'));
        self::assertFalse(AgentRunner::isPageMoveRequest('Create the page "About" under Home'));

        $catalog = [
            ['name' => 'pages_tree'],
            ['name' => 'pages_move'],
            ['name' => 'content_move'],
        ];
        $names = array_map(
            static fn(array $t): string => (string) $t['name'],
            AgentRunner::pageMoveTools($catalog, ['pages_tree' => 0]),
        );

        self::assertSame(['pages_move'], $names);
        self::assertSame([], AgentRunner::pageMoveTools($catalog, ['pages_move' => 0]));
    }

    #[Test]
    public function pendingAttachToolsOffersFileReferenceAdd(): void
    {
        $catalog = [
            ['name' => 'write_table'],
            ['name' => 'file_reference_add'],
            ['name' => 'pages_get'],
        ];
        $names = array_map(
            static fn(array $t): string => (string) $t['name'],
            AgentRunner::pendingAttachTools($catalog),
        );

        self::assertSame(['file_reference_add'], $names);
        self::assertSame([], AgentRunner::pendingAttachTools($catalog, ['file_reference_add' => 0]));
    }

    #[Test]
    public function imageWorkToolsOmitsAttachWhenEveryFileIsAlreadyLinked(): void
    {
        $catalog = [
            ['name' => 't3ai_generate_image'],
            ['name' => 'file_reference_add'],
        ];
        $history = [
            [
                'role' => 'assistant',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'details' => ['fileUid' => 5],
                ],
            ],
            [
                'role' => 'assistant',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 'file_reference_add',
                    'success' => true,
                    'details' => ['fileUid' => 5],
                ],
            ],
        ];

        $names = array_map(
            static fn(array $t): string => (string) $t['name'],
            AgentRunner::imageWorkTools($catalog, [], $history),
        );

        self::assertSame(['t3ai_generate_image'], $names);
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
    public function aShortRequestOfItsOwnIsNotGluedToThePreviousTurn(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Which content element types can an editor create on this page?', 'meta' => []],
            ['role' => 'assistant', 'content' => '1. Header 2. Text 3. Text & Media', 'meta' => ['type' => 'nl_reply']],
        ];

        self::assertSame('List all workspaces', AgentRunner::requestQuery('List all workspaces', $history));
        // A bare "create it" still needs the turn before it.
        self::assertStringContainsString('Which content element types', AgentRunner::requestQuery('create it', $history));
    }

    #[Test]
    public function aContinuationUsesTheEditorsRequestNotTheLastAnswer(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Change the header of content element uid 153 to QA', 'meta' => []],
            ['role' => 'assistant', 'content' => 'Done. The Text element was updated.', 'meta' => ['type' => 'nl_reply']],
        ];

        self::assertSame(
            'Change the header of content element uid 153 to QA',
            AgentRunner::requestQuery('[The editor confirmed the change]', $history),
        );
    }

    #[Test]
    public function everyToolCallIsReportedWithItsOutcome(): void
    {
        $responses = [
            new AiToolCallingResponse('', 'gpt-test', 'openai', [new AiToolCall('c1', 'pages_get', ['uid' => 45])]),
            new AiToolCallingResponse('', 'gpt-test', 'openai', [new AiToolCall('c2', 'pages_get', ['uid' => 45]), new AiToolCall('c3', 'pages_get', [])]),
            new AiToolCallingResponse('Done', 'gpt-test', 'openai'),
        ];
        $toolCalling = $this->createMock(AiToolCallingServiceInterface::class);
        $toolCalling->method('supportsToolCalling')->willReturn(true);
        $toolCalling->method('completeWithTools')->willReturnCallback(static function () use (&$responses): AiToolCallingResponse {
            return array_shift($responses) ?? new AiToolCallingResponse('Done', 'gpt-test', 'openai');
        });
        $events = [];
        $this->makeRunner($toolCalling, 5)->runTurn(
            'Read page 45',
            [],
            ['pageId' => 49],
            [],
            $this->createMock(BackendUserAuthentication::class),
            'corr',
            static function (string $event, array $payload) use (&$events): void {
                $events[] = [$event, $payload];
            },
        );

        $calls = array_values(array_map(static fn(array $e): string => $e[1]['tool'] . ':' . $e[1]['outcome'], array_filter($events, static fn(array $e): bool => $e[0] === 'tool_call')));
        self::assertSame(['pages_get:executed', 'pages_get:repeated', 'pages_get:invalid'], $calls);
        self::assertCount(3, array_filter($events, static fn(array $e): bool => $e[0] === 'model_request'));
    }

    #[Test]
    public function copyingAPageIsOfferedOnlyWhenAskedFor(): void
    {
        $gated = ['pages_copy', 'content_move', 'pages_move', 'workspace_switch', 't3aa_summarize_content', 't3aa_update_file_metadata', 't3ai_generate_all_seo', 't3ai_generate_seo_batch', 't3aa_generate_voice_over'];
        self::assertSame($gated, AgentRunner::toolsNotAskedFor('I want a new subpage "Eval yes" under this page'));
        self::assertSame($gated, AgentRunner::toolsNotAskedFor('Ändere die Unterüberschrift von Element 12 auf Hallo'));
        self::assertSame(['pages_copy', 'content_move', 'pages_move', 't3aa_summarize_content', 't3aa_update_file_metadata', 't3ai_generate_all_seo', 't3ai_generate_seo_batch', 't3aa_generate_voice_over'], AgentRunner::toolsNotAskedFor('Switch to the QA Draft workspace'));
        self::assertNotContains('content_move', AgentRunner::toolsNotAskedFor('Move element 12 below element 15'));
        self::assertSame(['content_move', 'pages_move', 'workspace_switch', 't3aa_summarize_content', 't3aa_update_file_metadata', 't3ai_generate_all_seo', 't3ai_generate_seo_batch', 't3aa_generate_voice_over'], AgentRunner::toolsNotAskedFor('Copy this page below "Services"'));
        self::assertSame(['content_move', 'pages_move', 'workspace_switch', 't3aa_summarize_content', 't3aa_update_file_metadata', 't3ai_generate_all_seo', 't3ai_generate_seo_batch', 't3aa_generate_voice_over'], AgentRunner::toolsNotAskedFor('Dupliziere diese Seite'));
        self::assertSame(['content_move', 'pages_move', 't3aa_summarize_content', 't3aa_update_file_metadata', 't3ai_generate_all_seo', 't3ai_generate_seo_batch', 't3aa_generate_voice_over'], AgentRunner::toolsNotAskedFor('Copy this page and switch to the draft workspace'));
        self::assertNotContains('t3aa_summarize_content', AgentRunner::toolsNotAskedFor('Summarize this page'));
        self::assertContains('t3aa_summarize_content', AgentRunner::toolsNotAskedFor('Rewrite the block so it sounds friendlier'));
        self::assertContains('t3aa_update_file_metadata', AgentRunner::toolsNotAskedFor('Generate a picture and attach it to the news'));
        self::assertNotContains('t3aa_update_file_metadata', AgentRunner::toolsNotAskedFor('Write alt text for this image'));
        self::assertContains('t3ai_generate_all_seo', AgentRunner::toolsNotAskedFor('Yes, generate the picture and attach it to the new news article'));
        self::assertNotContains('t3ai_generate_all_seo', AgentRunner::toolsNotAskedFor('Write all SEO texts for this page'));
        self::assertContains('t3aa_generate_voice_over', AgentRunner::toolsNotAskedFor('Generate a picture for the news'));
        self::assertNotContains('t3aa_generate_voice_over', AgentRunner::toolsNotAskedFor('Create a voice-over for this page'));
    }

    #[Test]
    public function hasOpenGenerateImageDraftDetectsWaitingCard(): void
    {
        $history = [
            [
                'role' => 'assistant',
                'meta' => [
                    'type' => 'inline_draft',
                    'tool' => 't3ai_generate_image',
                    'draft' => ['tool' => 't3ai_generate_image', 'discarded' => false],
                ],
            ],
        ];

        self::assertTrue(AgentRunner::hasOpenGenerateImageDraft($history));
        self::assertFalse(AgentRunner::hasOpenGenerateImageDraft([]));
    }

    #[Test]
    public function newsRequestsDoNotGetTheContentDeleteTool(): void
    {
        self::assertTrue(AgentRunner::isNewsOnlyRequest('Delete the Winter Opening Hours news.'));
        self::assertTrue(AgentRunner::isNewsOnlyRequest('Lösche die Meldung Sommerfest'));
        self::assertFalse(AgentRunner::isNewsOnlyRequest('Delete the news content element on this page'));
        self::assertFalse(AgentRunner::isNewsOnlyRequest('Delete element 12'));
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
    public function standalonePremiumRequestSkipsTheModelEntirely(): void
    {
        $this->resetLoadedExtensions();

        $result = $this->runScripted([], message: 'Translate this page to German.');

        self::assertSame([], $this->requests, 'The model must not be called at all.');
        self::assertCount(1, $result['messages']);
        self::assertSame('not_purchased', $result['messages'][0]['meta']['type']);
        self::assertStringContainsString('agent.entitlement.notPurchasedLead', (string) $result['messages'][0]['content']);
        self::assertStringContainsString('Content rewriting, SEO, translation', (string) $result['messages'][0]['content']);
    }

    #[Test]
    public function compoundPremiumRequestStillReachesTheModel(): void
    {
        $this->resetLoadedExtensions();

        $result = $this->runScripted(
            [new AiToolCallingResponse('Seite angelegt.', 'gpt-test', 'openai')],
            message: 'Create this page, then translate it to German',
        );

        self::assertCount(1, $this->requests, 'A compound request must still reach the model.');
        self::assertSame('nl_reply', $result['messages'][count($result['messages']) - 1]['meta']['type']);
    }

    #[Test]
    public function barePageSearchDoesNotShortCircuitToAiSearchUpsell(): void
    {
        $this->resetLoadedExtensions();

        $result = $this->runScripted(
            [new AiToolCallingResponse('Found the page.', 'gpt-test', 'openai')],
            message: 'Search for page Home',
        );

        self::assertCount(1, $this->requests, 'Core page search must reach the model, not the AI Search upsell.');
        self::assertNotSame('not_purchased', $result['messages'][count($result['messages']) - 1]['meta']['type'] ?? null);
        self::assertSame('nl_reply', $result['messages'][count($result['messages']) - 1]['meta']['type']);
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
    public function namedProviderDoesNotUseIdentifierAsModelId(): void
    {
        $toolCalling = $this->createMock(AiToolCallingServiceInterface::class);
        $toolCalling->method('supportsToolCalling')->willReturn(true);
        $toolCalling->method('completeWithTools')->willReturnCallback(
            function (array $messages, array $tools, AiOptions $options): AiToolCallingResponse {
                $this->record($messages, $tools, $options);
                self::assertSame('gemini', $options->providerIdentifier);
                self::assertNull($options->modelId);

                return new AiToolCallingResponse('Hello', 'gemini-3.5-flash', 'gemini');
            },
        );

        $this->makeRunner($toolCalling, 5)->runTurn(
            'Hi',
            [],
            ['pageId' => 49],
            ['provider' => 'gemini'],
            $this->createMock(BackendUserAuthentication::class),
            'corr-gemini',
        );

        self::assertCount(1, $this->requests);
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
            new PremiumCatalogProvider(new ExtensionAvailability()),
            new AgentEntitlementExplanation(
                new EntitlementResolver([], new ExtensionAvailability()),
                new ModuleTabUtility(),
                $this->createMock(UriBuilder::class),
                $translator,
                new AgentLanguageResolver($this->createMock(SiteFinder::class)),
            ),
        );
    }

    #[Test]
    public function providerErrorsAreShownAsOneReadableSentence(): void
    {
        self::assertSame(
            'Rate limit reached',
            AgentRunner::readableProviderError('HTTP 429 returned for "https://api.example.com/v1/chat": {"error":{"message":"Rate limit reached","type":"x"}}'),
        );
        self::assertSame(
            'HTTP 400 returned for "https://api.example.com/v1/chat"',
            AgentRunner::readableProviderError('HTTP 400 returned for "https://api.example.com/v1/chat": {not json'),
        );
        self::assertSame(
            'The AI provider returned an error. Please try again.',
            AgentRunner::readableProviderError('{"raw":true}'),
        );
        self::assertLessThanOrEqual(201, mb_strlen(AgentRunner::readableProviderError(str_repeat('word ', 200))));
    }

    #[Test]
    public function permissionRefusalInATurnIsRecognised(): void
    {
        $denied = 'You do not have permission to change this page or its content.';

        self::assertTrue(AgentRunner::turnWasRefusedByPermissions(
            [['role' => 'assistant', 'content' => $denied . ' Ask an administrator.', 'meta' => ['type' => 'error']]],
            $denied,
        ));
        self::assertTrue(AgentRunner::turnWasRefusedByPermissions(
            [['role' => 'assistant', 'content' => "Could not prepare this change: You don't have access to this page.", 'meta' => ['type' => 'error']]],
        ));
        // Only failed tool results count: a normal reply that mentions the words does not.
        self::assertFalse(AgentRunner::turnWasRefusedByPermissions(
            [['role' => 'assistant', 'content' => "You don't have access to this page.", 'meta' => ['type' => 'nl_reply']]],
        ));
        self::assertFalse(AgentRunner::turnWasRefusedByPermissions(
            [['role' => 'assistant', 'content' => 'Rate limit reached', 'meta' => ['type' => 'error']]],
            $denied,
        ));
        self::assertFalse(AgentRunner::turnWasRefusedByPermissions([]));
    }

    #[Test]
    public function aDeclinedChangeIsNotOfferedAgain(): void
    {
        $card = static fn(string $proposed, bool $discarded = false): array => [
            'role' => 'assistant',
            'content' => 'Rename page',
            'meta' => [
                'type' => 'inline_draft',
                'draft' => [
                    'tool' => 'write_table',
                    'discarded' => $discarded,
                    'fields' => [['table' => 'pages', 'uid' => 3, 'field' => 'title', 'proposed' => $proposed]],
                ],
            ],
        ];
        $history = [$card('Renamed', true)];
        $info = ['role' => 'assistant', 'content' => 'ok', 'meta' => ['type' => 'nl_reply']];

        $kept = AgentRunner::withoutRepeatedDeclinedDrafts([$card('Renamed'), $card('Something else'), $info], $history);

        self::assertCount(2, $kept);
        self::assertSame('Something else', $kept[0]['meta']['draft']['fields'][0]['proposed']);
        self::assertSame($info, $kept[1]);
        // A still-open update is not a duplicate create, so it stays.
        self::assertCount(3, AgentRunner::withoutRepeatedDeclinedDrafts([$card('Renamed'), $card('Other'), $info], [$card('Renamed')]));
    }

    #[Test]
    public function aDownloadThatFailedForGoodIsNotOfferedAgainUnchanged(): void
    {
        $download = static fn(string $url, bool $failed = false): array => [
            'role' => 'assistant',
            'content' => 'Upload file from URL',
            'meta' => [
                'type' => 'inline_draft',
                'draft' => ['tool' => 'file_upload_from_url', 'failed' => $failed, 'fields' => [], 'arguments' => ['url' => $url]],
            ],
        ];
        $history = [$download('https://no-such-host.invalid/a.jpg', true)];
        $newRequest = ['role' => 'user', 'content' => 'Try again', 'meta' => ['type' => 'message']];

        $declinedDropped = 0;
        $failedDropped = 0;
        $kept = AgentRunner::withoutRepeatedDeclinedDrafts([$download('https://no-such-host.invalid/a.jpg')], [...$history, $newRequest], false, $declinedDropped, $failedDropped);

        self::assertSame([], $kept);
        self::assertSame(0, $declinedDropped);
        self::assertSame(1, $failedDropped);
        // Another address is a new attempt.
        self::assertCount(1, AgentRunner::withoutRepeatedDeclinedDrafts([$download('https://upload.wikimedia.org/a.jpg')], $history));

        // Tool cards in the window carry no arguments: the address is only in the summary.
        $card = static fn(string $url, bool $failed = false): array => [
            'role' => 'assistant',
            'content' => 'upload from ' . $url . ' to user_upload',
            'meta' => [
                'type' => 'inline_draft',
                'draft' => ['tool' => 'file_upload_from_url', 'kind' => 'tool_confirmation', 'action' => 'create', 'failed' => $failed, 'fields' => [], 'arguments' => [], 'summary' => 'upload from ' . $url . ' to user_upload'],
            ],
        ];
        $history = [$card('https://no-such-host.invalid/a.jpg', true)];
        self::assertSame([], AgentRunner::withoutRepeatedDeclinedDrafts([$card('https://no-such-host.invalid/a.jpg')], $history));
        self::assertCount(1, AgentRunner::withoutRepeatedDeclinedDrafts([$card('https://upload.wikimedia.org/a.jpg')], $history));
    }

    #[Test]
    public function aPendingCreateIsNotOfferedAgain(): void
    {
        $create = static fn(string $title, bool $applied = false): array => [
            'role' => 'assistant',
            'content' => 'Create page',
            'meta' => [
                'type' => 'inline_draft',
                'applied' => $applied,
                'draft' => [
                    'tool' => 'write_table',
                    'action' => 'create',
                    'applied' => $applied,
                    'fields' => [['table' => 'pages', 'uid' => 0, 'field' => 'title', 'proposed' => $title]],
                ],
            ],
        ];
        $info = ['role' => 'assistant', 'content' => 'ok', 'meta' => ['type' => 'nl_reply']];

        $kept = AgentRunner::withoutRepeatedDeclinedDrafts([$create('Agent Test'), $info], [$create('Agent Test')]);

        self::assertCount(1, $kept);
        self::assertSame($info, $kept[0]);
        // The page was already created: a later request may create another one.
        self::assertCount(2, AgentRunner::withoutRepeatedDeclinedDrafts([$create('Agent Test'), $info], [$create('Agent Test', true)]));
        // One reply that proposes the same create twice keeps a single card.
        self::assertCount(2, AgentRunner::withoutRepeatedDeclinedDrafts([$create('Agent Test'), $create('Agent Test'), $info], []));
    }

    #[Test]
    public function aCreateTheEditorJustDeclinedIsNotProposedAgainWithOneFieldMore(): void
    {
        $textMedia = static fn(array $extra = [], bool $discarded = false): array => [
            'role' => 'assistant',
            'content' => 'Create element',
            'meta' => [
                'type' => 'inline_draft',
                'draft' => [
                    'tool' => 'write_table',
                    'action' => 'create',
                    'discarded' => $discarded,
                    'fields' => [
                        ['table' => 'tt_content', 'uid' => 0, 'field' => 'CType', 'proposed' => 'textmedia'],
                        ['table' => 'tt_content', 'uid' => 0, 'field' => 'header', 'proposed' => 'Preview Test'],
                        ...$extra,
                    ],
                ],
            ],
        ];
        $request = ['role' => 'user', 'content' => 'Create a text & media element', 'meta' => ['type' => 'message']];
        $declined = ['role' => 'user', 'content' => '[The editor declined …]', 'meta' => ['type' => 'continuation', 'hidden' => true]];
        $history = [$request, $textMedia([], true), $declined];
        $again = $textMedia([['table' => 'tt_content', 'uid' => 0, 'field' => 'sys_language_uid', 'proposed' => '0']]);

        self::assertSame([], AgentRunner::withoutRepeatedDeclinedDrafts([$again], $history, true));
        // A new request of the editor may ask for that element after all.
        self::assertCount(1, AgentRunner::withoutRepeatedDeclinedDrafts([$again], [...$history, $request], true));
        self::assertCount(1, AgentRunner::withoutRepeatedDeclinedDrafts([$again], $history));
    }

    #[Test]
    public function aToolCardTheEditorJustDeclinedIsNotProposedAgainInOtherWords(): void
    {
        $generate = static fn(string $prompt, bool $discarded = false): array => [
            'role' => 'assistant',
            'content' => 'Generate an image',
            'meta' => [
                'type' => 'inline_draft',
                'draft' => ['tool' => 't3ai_generate_image', 'discarded' => $discarded, 'fields' => [], 'arguments' => ['prompt' => $prompt]],
            ],
        ];
        $request = ['role' => 'user', 'content' => 'Create a text & media element with an AI-generated image', 'meta' => ['type' => 'message']];
        $declined = ['role' => 'user', 'content' => '[The editor declined …]', 'meta' => ['type' => 'continuation', 'hidden' => true]];
        $history = [$request, $generate('A red lighthouse', true), $declined];

        self::assertSame([], AgentRunner::withoutRepeatedDeclinedDrafts([$generate('A red lighthouse on a cliff')], $history, true));
        // Also later in the same request, after another card was applied.
        $dropped = 0;
        self::assertSame([], AgentRunner::withoutRepeatedDeclinedDrafts([$generate('A red lighthouse on a cliff')], $history, false, $dropped));
        self::assertSame(1, $dropped);
        self::assertCount(1, AgentRunner::withoutRepeatedDeclinedDrafts([$generate('A red lighthouse on a cliff')], [...$history, $request], true));
    }

    #[Test]
    public function fileRequestsKeepTheMatchingFileTools(): void
    {
        $tools = [['name' => 'file_copy'], ['name' => 'file_move'], ['name' => 'directory_create'], ['name' => 'content_get']];

        $copy = AgentRunner::fileActionTools('Copy the file a.jpg to the folder b', $tools);
        self::assertSame(['file_copy'], array_column($copy, 'name'));

        $folder = AgentRunner::fileActionTools('Ordner "neu" anlegen', $tools);
        self::assertSame(['directory_create'], array_column($folder, 'name'));

        self::assertSame([], AgentRunner::fileActionTools('Change the header', $tools));
        self::assertSame([], AgentRunner::fileActionTools('Copy the file a.jpg', $tools, ['file_copy' => 1]));
    }

    #[Test]
    public function aPlainEditDoesNotKeepTheDeleteTool(): void
    {
        self::assertTrue(AgentRunner::isPlainEditRequest('Change the header of content element uid 1647 to QA Btn A2'));
        self::assertTrue(AgentRunner::isPlainEditRequest('Ändere die Überschrift von Element 5'));
        self::assertFalse(AgentRunner::isPlainEditRequest('Delete the old header and change the title'));
        self::assertFalse(AgentRunner::isPlainEditRequest('Create a text element'));
    }

    #[Test]
    public function providerTimeoutsAreRecognised(): void
    {
        self::assertTrue(AgentRunner::looksLikeTimeout(new \RuntimeException('Idle timeout reached for "https://api.mistral.ai/v1/chat/completions".')));
        self::assertTrue(AgentRunner::looksLikeTimeout(new \RuntimeException('wrapped', 0, new \RuntimeException('Operation timed out after 30000 ms'))));
        self::assertFalse(AgentRunner::looksLikeTimeout(new \RuntimeException('The model is overloaded.')));
    }

    #[Test]
    public function providerBillingTextIsRecognisedSoEditorsDoNotSeeIt(): void
    {
        self::assertTrue(AgentRunner::looksLikeProviderAccountProblem('Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing.'));
        self::assertTrue(AgentRunner::looksLikeProviderAccountProblem('You exceeded your current quota, please check your plan and billing details.'));
        self::assertFalse(AgentRunner::looksLikeProviderAccountProblem('The model is overloaded, try again.'));
    }

    #[Test]
    public function offerToApplyWithoutADraftIsRecognised(): void
    {
        self::assertTrue(AgentRunner::offersUnbackedApply("Welcome to our QA section!\n\nWould you like to apply this change?"));
        self::assertTrue(AgentRunner::offersUnbackedApply('Would you like me to save it as the new text?'));
        self::assertTrue(AgentRunner::offersUnbackedApply('Soll ich diese Änderung übernehmen?'));
    }

    #[Test]
    public function plainAnswersAreNeverForcedIntoAWrite(): void
    {
        self::assertFalse(AgentRunner::offersUnbackedApply(''));
        self::assertFalse(AgentRunner::offersUnbackedApply('The page has 3 content elements and 2 translations.'));
        self::assertFalse(AgentRunner::offersUnbackedApply('Which page do you mean: Home or About?'));
        self::assertFalse(AgentRunner::offersUnbackedApply('Die Seite hat drei Inhaltselemente.'));
        self::assertFalse(AgentRunner::offersUnbackedApply('You can use the Content module to review the text.'));
    }

    #[Test]
    public function aToolCallWrittenAsTextIsRecognised(): void
    {
        self::assertTrue(AgentRunner::looksLikeLeakedToolCall('{"newsId":1,"targetLanguageUid":1} to=functions.t3ai_translate_news 天天爱彩票'));
        self::assertTrue(AgentRunner::looksLikeLeakedToolCall('<|channel|>commentary functions.pages_get {"uid":3}'));
        self::assertFalse(AgentRunner::looksLikeLeakedToolCall('I translated the news article into German.'));
        self::assertFalse(AgentRunner::looksLikeLeakedToolCall(''));
    }

    #[Test]
    public function newsCreateRequestsAreToldApartFromOtherNewsRequests(): void
    {
        self::assertTrue(AgentRunner::isNewsCreateRequest('Write a short news article about our summer team party.'));
        self::assertTrue(AgentRunner::isNewsCreateRequest('Now write a longer, detailed news story about our anniversary, with a few sections and a picture.'));
        self::assertTrue(AgentRunner::isNewsCreateRequest('Create a news article titled Winter Opening Hours'));
        self::assertTrue(AgentRunner::isNewsCreateRequest('Erstelle einen neuen Newsartikel zum Sommerfest'));
        self::assertFalse(AgentRunner::isNewsCreateRequest('Delete the Winter news'));
        self::assertFalse(AgentRunner::isNewsCreateRequest('Translate the news into German'));
        self::assertFalse(AgentRunner::isNewsCreateRequest('Show me all my news articles'));
        self::assertFalse(AgentRunner::isNewsCreateRequest('Create a new text element'));
    }

    #[Test]
    public function imageAlreadyGeneratedThisRequestBlocksASecondGenerate(): void
    {
        $withFile = [
            [
                'role' => 'assistant',
                'content' => 'Image saved.',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'details' => ['fileUid' => 155],
                ],
            ],
        ];
        $withOpenCard = [
            [
                'role' => 'assistant',
                'content' => 'Review.',
                'meta' => [
                    'type' => 'inline_draft',
                    'tool' => 't3ai_generate_image',
                    'draft' => ['tool' => 't3ai_generate_image', 'discarded' => false],
                ],
            ],
        ];

        self::assertTrue(AgentRunner::imageAlreadyGeneratedThisRequest('Yes, generate the picture.', $withFile));
        self::assertTrue(AgentRunner::imageAlreadyGeneratedThisRequest('Yes, generate the picture.', $withOpenCard));
        self::assertFalse(AgentRunner::imageAlreadyGeneratedThisRequest('Write a news story with a picture.', []));
    }

    #[Test]
    public function newsCreateToolsPreferWriteTableWhenCreateNewsToolsAreHidden(): void
    {
        $catalog = [
            ['name' => 'write_table'],
            ['name' => 't3ai_create_content_element'],
            ['name' => 'explain_capabilities'],
            ['name' => 't3ai_create_news_simple'],
        ];

        self::assertSame(
            [
                ['name' => 'write_table'],
                ['name' => 't3ai_create_news_simple'],
            ],
            AgentRunner::newsCreateTools($catalog),
        );
        self::assertSame(
            [['name' => 'write_table']],
            AgentRunner::newsCreateTools($catalog, ['t3ai_create_news_simple' => 0]),
        );
    }
}
