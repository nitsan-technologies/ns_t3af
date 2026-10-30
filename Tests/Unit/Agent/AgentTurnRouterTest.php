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

use NITSAN\NsT3AF\Agent\Contract\AgentToolTurnExecutorInterface;
use NITSAN\NsT3AF\Agent\Contract\AgentTurnRunnerInterface;
use NITSAN\NsT3AF\Agent\Service\AgentMessageParser;
use NITSAN\NsT3AF\Agent\Service\AgentRecordAttachmentResolver;
use NITSAN\NsT3AF\Agent\Service\AgentSlashArgumentBinder;
use NITSAN\NsT3AF\Agent\Service\AgentTargetPageResolver;
use NITSAN\NsT3AF\Agent\Service\AgentTranslator;
use NITSAN\NsT3AF\Agent\Service\AgentTurnRouter;
use NITSAN\NsT3AF\Agent\Service\PermittedActionProvider;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use NITSAN\NsT3AF\Mcp\Service\FileService;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * @internal
 */
final class AgentTurnRouterTest extends TestCase
{
    private AgentToolTurnExecutorInterface&MockObject $toolTurnProcessor;

    private AgentTurnRunnerInterface&MockObject $turnOrchestrator;

    private AgentTurnRouter $router;

    protected function setUp(): void
    {
        $this->toolTurnProcessor = $this->createMock(AgentToolTurnExecutorInterface::class);
        $this->turnOrchestrator = $this->createMock(AgentTurnRunnerInterface::class);

        $introspector = $this->createMock(McpToolIntrospectorService::class);
        $introspector->method('listTools')->willReturn([]);

        $this->router = new AgentTurnRouter(
            new AgentMessageParser(),
            new AgentSlashArgumentBinder($introspector),
            new AgentRecordAttachmentResolver(),
            $this->toolTurnProcessor,
            $this->turnOrchestrator,
            (new \ReflectionClass(PermittedActionProvider::class))->newInstanceWithoutConstructor(),
            $this->createMock(FileService::class),
            (new \ReflectionClass(AgentTranslator::class))->newInstanceWithoutConstructor(),
            new AgentTargetPageResolver($this->createMock(McpPlaygroundService::class)),
        );
    }

    #[Test]
    public function slashCommandRoutesStructurallyToToolProcessor(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->toolTurnProcessor->expects(self::once())
            ->method('execute')
            ->with(
                'pages_get',
                self::isType('array'),
                self::callback(static fn(array $body): bool => ($body['arguments']['uid'] ?? null) === 49),
                $user,
                'corr-1',
            )
            ->willReturn([
                'role' => 'assistant',
                'content' => 'Page 49: Home',
                'meta' => ['type' => 'tool_result', 'tool' => 'pages_get', 'success' => true],
            ]);
        $this->turnOrchestrator->expects(self::never())->method('runTurn');

        $messages = $this->router->route(
            '/pages_get 49',
            ['pageId' => 1],
            [],
            $user,
            'corr-1',
        );

        self::assertCount(2, $messages);
        self::assertSame('tool_result', $messages[0]['meta']['type'] ?? null);
        self::assertSame('pages_get', $messages[0]['meta']['tool'] ?? null);
        self::assertSame('nl_reply', $messages[1]['meta']['type'] ?? null);
        self::assertSame('Page 49: Home', $messages[1]['content']);
        self::assertTrue($messages[1]['meta']['fromToolResult'] ?? false);
    }

    #[Test]
    public function structuralNlReplyForwardsMediaPreviews(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $previews = [[
            'fileUid' => 11,
            'url' => 'https://example.test/thumb.webp',
            'href' => '/fileadmin/camino-portugues.webp',
            'name' => 'camino-portugues.webp',
            'alt' => '',
        ]];
        $this->toolTurnProcessor->expects(self::once())
            ->method('execute')
            ->willReturn([
                'role' => 'assistant',
                'content' => 'Found 1 matching files.',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 'file_search',
                    'success' => true,
                    'previews' => $previews,
                ],
            ]);

        $messages = $this->router->route(
            '/file_search logo',
            ['pageId' => 1],
            ['tool' => 'file_search', 'arguments' => ['namePattern' => 'logo']],
            $user,
            'corr-preview',
        );

        self::assertCount(2, $messages);
        self::assertSame('nl_reply', $messages[1]['meta']['type'] ?? null);
        self::assertSame($previews, $messages[1]['meta']['previews'] ?? null);
    }

    #[Test]
    public function structuralNlReplyForwardsQueueLines(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $lines = ['/products/156 (Completed) [#156]', '/products/157 (Completed) [#157]'];
        $this->toolTurnProcessor->expects(self::once())
            ->method('execute')
            ->willReturn([
                'role' => 'assistant',
                'content' => 'Found 2 items.',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3cs_list_queue_items',
                    'success' => true,
                    'lines' => $lines,
                ],
            ]);

        $messages = $this->router->route(
            '/t3cs_list_queue_items {"dataId":8}',
            ['pageId' => 1],
            [],
            $user,
            'corr-lines',
        );

        self::assertSame($lines, $messages[1]['meta']['lines'] ?? null);
    }

    #[Test]
    public function structuralDraftDoesNotGetTrailingNlReply(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->toolTurnProcessor->expects(self::once())
            ->method('execute')
            ->willReturn([
                'role' => 'assistant',
                'content' => 'Review changes',
                'meta' => ['type' => 'inline_draft', 'tool' => 'write_table'],
            ]);

        $messages = $this->router->route(
            '/write_table',
            ['pageId' => 1],
            ['tool' => 'write_table', 'arguments' => ['action' => 'update', 'tableName' => 'pages']],
            $user,
            'corr-draft',
        );

        self::assertCount(1, $messages);
        self::assertSame('inline_draft', $messages[0]['meta']['type'] ?? null);
    }

    #[Test]
    public function failedSlashToolDoesNotRepeatTheErrorAsAReply(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $hint = 'Missing required argument: rootPageId. Required: rootPageId. Example: /t3cs_training_summary {"rootPageId":1}';
        $this->toolTurnProcessor->expects(self::once())
            ->method('execute')
            ->willReturn([
                'role' => 'assistant',
                'content' => $hint,
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3cs_training_summary',
                    'success' => false,
                ],
            ]);

        $messages = $this->router->route(
            '/t3cs_training_summary',
            ['pageId' => 1],
            [],
            $user,
            'corr-queue',
        );

        self::assertCount(1, $messages);
        self::assertSame('tool_result', $messages[0]['meta']['type'] ?? null);
        self::assertSame($hint, $messages[0]['content']);
    }

    #[Test]
    public function queueListSlashWithoutAnIdCallsTheTool(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->toolTurnProcessor->expects(self::once())
            ->method('execute')
            ->with(
                't3cs_list_queue_items',
                self::isType('array'),
                self::callback(static fn(array $body): bool => (int) ($body['arguments']['dataId'] ?? 0) === 0),
                $user,
                'corr-queue-nl',
            )
            ->willReturn([
                'role' => 'assistant',
                'content' => 'Which data source?',
                'meta' => ['type' => 'clarification', 'tool' => 'ask_clarification'],
            ]);
        $this->turnOrchestrator->expects(self::never())->method('runTurn');

        $messages = $this->router->route(
            '/t3cs_list_queue_items',
            ['pageId' => 1],
            [],
            $user,
            'corr-queue-nl',
        );

        self::assertSame('clarification', $messages[0]['meta']['type'] ?? null);
        self::assertSame('Which data source?', $messages[0]['content'] ?? null);
    }

    #[Test]
    public function queueSourceButtonOpensThatQueue(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->toolTurnProcessor->expects(self::once())
            ->method('execute')
            ->with(
                't3cs_list_queue_items',
                self::isType('array'),
                self::callback(static fn(array $body): bool => ($body['arguments']['dataId'] ?? null) === 6),
                $user,
                'corr-queue-choice',
            )
            ->willReturn([
                'role' => 'assistant',
                'content' => '6 items',
                'meta' => ['type' => 'tool_result', 'tool' => 't3cs_list_queue_items', 'success' => true],
            ]);
        $this->turnOrchestrator->expects(self::never())->method('runTurn');

        $messages = $this->router->route(
            'T3CS FAQ Source (qa) [6]',
            ['pageId' => 1],
            [],
            $user,
            'corr-queue-choice',
            [[
                'role' => 'assistant',
                'content' => 'Which data source?',
                'meta' => [
                    'type' => 'clarification',
                    'askedFor' => 't3cs_list_queue_items',
                    'options' => ['Asus (web_pages) [5]', 'T3CS FAQ Source (qa) [6]'],
                ],
            ]],
        );

        self::assertSame('6 items', $messages[1]['content'] ?? null);
    }

    #[Test]
    public function queueListSlashWithAnIdStillCallsTheTool(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->toolTurnProcessor->expects(self::once())
            ->method('execute')
            ->with(
                't3cs_list_queue_items',
                self::isType('array'),
                self::callback(static fn(array $body): bool => ($body['arguments']['dataId'] ?? null) === 8),
                $user,
                'corr-queue-id',
            )
            ->willReturn([
                'role' => 'assistant',
                'content' => '6 items',
                'meta' => ['type' => 'tool_result', 'tool' => 't3cs_list_queue_items', 'success' => true],
            ]);
        $this->turnOrchestrator->expects(self::never())->method('runTurn');

        $messages = $this->router->route(
            '/t3cs_list_queue_items {"dataId":8}',
            ['pageId' => 1],
            [],
            $user,
            'corr-queue-id',
        );

        self::assertSame('tool_result', $messages[0]['meta']['type'] ?? null);
        self::assertSame('6 items', $messages[1]['content'] ?? null);
    }

    #[Test]
    public function folderRenameSlashUsesDirectoryRename(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->toolTurnProcessor->expects(self::once())
            ->method('execute')
            ->with(
                'directory_rename',
                self::isType('array'),
                self::isType('array'),
                $user,
                'corr-folder',
            )
            ->willReturn([
                'role' => 'assistant',
                'content' => 'Review changes',
                'meta' => ['type' => 'inline_draft', 'tool' => 'directory_rename'],
            ]);
        $this->turnOrchestrator->expects(self::never())->method('runTurn');

        $messages = $this->router->route(
            '/folder_rename /user_upload/reports/ archive',
            ['pageId' => 1],
            [],
            $user,
            'corr-folder',
        );

        self::assertSame('inline_draft', $messages[0]['meta']['type'] ?? null);
    }

    #[Test]
    public function freeTextRoutesToOrchestratorOnly(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->toolTurnProcessor->expects(self::never())->method('execute');
        $this->turnOrchestrator->expects(self::once())
            ->method('runTurn')
            ->with(
                'Optimize SEO for this page please',
                self::isType('array'),
                self::isType('array'),
                self::isType('array'),
                $user,
                'corr-2',
                null,
            )
            ->willReturn([
                'messages' => [[
                    'role' => 'assistant',
                    'content' => 'orchestrated',
                    'meta' => ['type' => 'nl_reply'],
                ]],
                'paused' => false,
                'pauseReason' => null,
            ]);

        $messages = $this->router->route(
            'Optimize SEO for this page please',
            ['pageId' => 12],
            [],
            $user,
            'corr-2',
        );

        self::assertCount(1, $messages);
        self::assertSame('orchestrated', $messages[0]['content']);
    }

    #[Test]
    public function aKeepClickOnImageTextsCarriesTheOriginalNamedFiles(): void
    {
        $request = 'Write alt text for this "/camino/victoriano-izquierdo-o7G89a9GFdA-unsplash.webp , /camino/damien-dufour-2mMrxyI13QU-unsplash.webp" image and save it.';
        $history = [
            ['role' => 'user', 'content' => $request, 'meta' => []],
            ['role' => 'assistant', 'content' => 'Already has texts', 'meta' => [
                'type' => 'clarification',
                'fileMetaOverwrite' => [
                    'fileUid' => 10,
                    'languageUid' => 0,
                    'choices' => ['regenerate' => 'Regenerate all', 'keep' => 'Keep current texts'],
                ],
            ]],
        ];
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->toolTurnProcessor->expects(self::once())
            ->method('execute')
            ->with(
                't3aa_update_file_metadata',
                self::isType('array'),
                self::callback(static function (array $body) use ($request): bool {
                    $query = (string) ($body['requestQuery'] ?? '');

                    return ($body['userMessage'] ?? null) === 'Keep current texts'
                        && (int) ($body['arguments']['fileUid'] ?? 0) === 10
                        && str_contains($query, 'victoriano-izquierdo')
                        && str_contains($query, 'damien-dufour')
                        && ($body['requestForGate'] ?? null) === $request;
                }),
                $user,
                'corr-keep',
            )
            ->willReturn([
                'role' => 'assistant',
                'content' => 'next file',
                'meta' => ['type' => 'clarification', 'tool' => 'ask_clarification'],
            ]);
        $this->turnOrchestrator->expects(self::never())->method('runTurn');

        $messages = $this->router->route(
            'Keep current texts',
            ['pageId' => 1],
            [],
            $user,
            'corr-keep',
            $history,
        );

        self::assertSame('clarification', $messages[0]['meta']['type'] ?? null);
    }

    #[Test]
    public function aKnownGlossaryPhraseIsSavedWithoutTheModel(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->turnOrchestrator->expects(self::never())->method('runTurn');
        $this->toolTurnProcessor->expects(self::once())
            ->method('execute')
            ->with(
                't3ai_glossary_save',
                self::callback(static fn(array $context): bool => (int) ($context['pageId'] ?? 0) === 2),
                self::callback(static function (array $body): bool {
                    $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];

                    return ($arguments['sourceTerm'] ?? null) === 'Layout'
                        && ($arguments['targetTerm'] ?? null) === 'Layoutansicht'
                        && ($body['requestQuery'] ?? null) === 'remember that we say Layoutansicht not Layout';
                }),
                $user,
                'corr-glossary',
            )
            ->willReturn([
                'role' => 'assistant',
                'content' => '"Layout" is already in the word list as "Layoutansicht" (German).',
                'meta' => ['type' => 'info', 'orchestratorPause' => true],
            ]);

        $messages = $this->router->route(
            'remember that we say Layoutansicht not Layout',
            ['pageId' => 2],
            [],
            $user,
            'corr-glossary',
        );

        self::assertSame('info', $messages[0]['meta']['type'] ?? null);
        self::assertSame('"Layout" is already in the word list as "Layoutansicht" (German).', $messages[0]['content']);
    }

    #[Test]
    public function legacySeoActionMapsToGenerateAllSeoTool(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->toolTurnProcessor->expects(self::once())
            ->method('execute')
            ->with(
                't3ai_generate_all_seo',
                self::callback(static fn(array $context): bool => (int) ($context['pageId'] ?? 0) === 7),
                self::callback(static function (array $body): bool {
                    $args = $body['arguments'] ?? [];

                    return ($args['pageId'] ?? null) === 7;
                }),
                $user,
                'corr-3',
            )
            ->willReturn([
                'role' => 'assistant',
                'content' => 'seo draft',
                'meta' => ['type' => 'suggestions', 'tool' => 't3ai_generate_all_seo'],
            ]);
        $this->turnOrchestrator->expects(self::never())->method('runTurn');

        $messages = $this->router->route(
            '/generate_seo_metadata',
            ['pageId' => 7],
            ['action' => 'generate_seo_metadata'],
            $user,
            'corr-3',
        );

        self::assertCount(1, $messages);
        self::assertSame('t3ai_generate_all_seo', $messages[0]['meta']['tool'] ?? null);
    }

    #[Test]
    public function keywordSeoPathIsGoneFromRouterSource(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Classes/Agent/Service/AgentTurnRouter.php',
        );

        self::assertStringNotContainsString('AgentSeoMetadataFlow', $source);
        self::assertStringNotContainsString('AgentWorkflowService', $source);
        self::assertStringNotContainsString('AgentNlIntentResolver', $source);
        self::assertStringNotContainsString('AgentReadFastPathService', $source);
        self::assertStringNotContainsString('resolveCompoundSteps', $source);
    }

    #[Test]
    public function anUnmatchedNameIsLeftForTheModelOnTheOpenPage(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->turnOrchestrator->expects(self::once())
            ->method('runTurn')
            ->with(
                'Add the voice-over content element for the Hindi page',
                self::isType('array'),
                self::callback(static fn(array $context): bool => (int) ($context['pageId'] ?? 0) === 54 && (int) ($context['lockedPageId'] ?? 0) === 0),
                self::isType('array'),
                $user,
                'corr-unmatched',
                null,
            )
            ->willReturn([
                'messages' => [[
                    'role' => 'assistant',
                    'content' => 'decided',
                    'meta' => ['type' => 'info'],
                ]],
                'paused' => false,
                'pauseReason' => null,
            ]);

        $messages = $this->router->route(
            'Add the voice-over content element for the Hindi page',
            ['pageId' => 54, 'details' => ['page' => ['uid' => 54, 'title' => 'Page 5']]],
            [],
            $user,
            'corr-unmatched',
        );

        self::assertSame('decided', $messages[0]['content']);
    }

    #[Test]
    public function aPageNumberIsNotAskedAsATitle(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->turnOrchestrator->expects(self::once())
            ->method('runTurn')
            ->with(
                'Remove page 138 from the SEO queue.',
                self::isType('array'),
                self::callback(static fn(array $context): bool => (int) ($context['pageId'] ?? 0) === 144 && (int) ($context['lockedPageId'] ?? 0) === 0),
                self::isType('array'),
                $user,
                'corr-page-number',
                null,
            )
            ->willReturn([
                'messages' => [[
                    'role' => 'assistant',
                    'content' => 'queued',
                    'meta' => ['type' => 'info'],
                ]],
                'paused' => false,
                'pauseReason' => null,
            ]);

        $messages = $this->router->route(
            'Remove page 138 from the SEO queue.',
            ['pageId' => 144, 'details' => ['page' => ['uid' => 144, 'title' => 'QA Big Tree']]],
            [],
            $user,
            'corr-page-number',
        );

        self::assertSame('queued', $messages[0]['content']);
    }

    #[Test]
    public function theFirstSimilarPageBecomesThePageOfTheNextTurn(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $this->turnOrchestrator->expects(self::once())
            ->method('runTurn')
            ->with(
                'the first one',
                self::isType('array'),
                self::callback(static fn(array $context): bool => (int) ($context['lockedPageId'] ?? 0) === 4 && (int) ($context['pageId'] ?? 0) === 4),
                self::isType('array'),
                $user,
                'corr-choice',
                null,
            )
            ->willReturn(['messages' => [], 'paused' => false, 'pauseReason' => null]);

        $this->router->route(
            'the first one',
            ['pageId' => 2, 'details' => ['page' => ['uid' => 2, 'title' => 'QA Mounted']]],
            [],
            $user,
            'corr-choice',
            [[
                'role' => 'assistant',
                'content' => 'Which page?',
                'meta' => [
                    'type' => 'clarification',
                    'missingPage' => true,
                    'options' => ['About us [4]', 'Home [1]'],
                ],
            ]],
        );
    }
}
