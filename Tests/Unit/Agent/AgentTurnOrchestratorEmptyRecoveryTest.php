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
use NITSAN\NsT3AF\Agent\Service\AgentTurnOrchestrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * @internal
 */
final class AgentTurnOrchestratorEmptyRecoveryTest extends TestCase
{
    #[Test]
    public function resolveRecoveryUsesContentDeleteWithUidFromHash(): void
    {
        $orchestrator = (new \ReflectionClass(AgentTurnOrchestrator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentTurnOrchestrator::class, 'resolveEmptyTurnRecovery');

        $result = $method->invoke(
            $orchestrator,
            'content_delete',
            [
                ['name' => 'ask_clarification'],
                ['name' => 'explain_capabilities'],
                ['name' => 'content_delete'],
            ],
            'Delete content element #20',
            ['pageId' => 7],
        );

        self::assertSame(['tool' => 'content_delete', 'arguments' => ['uid' => 20]], $result);
    }

    #[Test]
    public function resolveRecoveryExtractsQuotedSearch(): void
    {
        $orchestrator = (new \ReflectionClass(AgentTurnOrchestrator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentTurnOrchestrator::class, 'resolveEmptyTurnRecovery');

        $result = $method->invoke(
            $orchestrator,
            'pages_search',
            [
                ['name' => 'pages_search'],
                ['name' => 'content_list'],
            ],
            'Find pages named "FAQ"',
            ['pageId' => 7],
        );

        self::assertSame(['tool' => 'pages_search', 'arguments' => ['search' => 'FAQ']], $result);
    }

    #[Test]
    public function resolveRecoveryPrefersContentSearchAndStripsContentFor(): void
    {
        $orchestrator = (new \ReflectionClass(AgentTurnOrchestrator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentTurnOrchestrator::class, 'resolveEmptyTurnRecovery');

        $result = $method->invoke(
            $orchestrator,
            'pages_search',
            [
                ['name' => 'pages_search'],
                ['name' => 'content_search'],
                ['name' => 'explain_capabilities'],
            ],
            'Search content for Camino',
            ['pageId' => 7],
        );

        self::assertSame(['tool' => 'content_search', 'arguments' => ['search' => 'Camino']], $result);
    }

    #[Test]
    public function resolveRecoveryExtractsUnquotedFaqPagesSearch(): void
    {
        $orchestrator = (new \ReflectionClass(AgentTurnOrchestrator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentTurnOrchestrator::class, 'resolveEmptyTurnRecovery');

        $result = $method->invoke(
            $orchestrator,
            'content_search',
            [
                ['name' => 'content_search'],
                ['name' => 'pages_search'],
            ],
            'Find pages named FAQ',
            ['pageId' => 7],
        );

        self::assertSame(['tool' => 'pages_search', 'arguments' => ['search' => 'FAQ']], $result);
    }

    #[Test]
    public function resolveRecoveryCreatesHeadlineWriteTableDraft(): void
    {
        $orchestrator = (new \ReflectionClass(AgentTurnOrchestrator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentTurnOrchestrator::class, 'resolveEmptyTurnRecovery');

        $result = $method->invoke(
            $orchestrator,
            'ask_clarification',
            [
                ['name' => 'ask_clarification'],
                ['name' => 'explain_capabilities'],
                // write_table intentionally absent from shortlist
            ],
            'Add a headline Welcome',
            ['pageId' => 7],
        );

        self::assertIsArray($result);
        self::assertSame('write_table', $result['tool']);
        self::assertSame('create', $result['arguments']['action'] ?? null);
        self::assertSame('tt_content', $result['arguments']['tableName'] ?? null);
        $data = json_decode((string) ($result['arguments']['data'] ?? ''), true);
        self::assertIsArray($data);
        self::assertSame(7, $data['pid'] ?? null);
        self::assertSame('header', $data['CType'] ?? null);
        self::assertSame('Welcome', $data['header'] ?? null);
    }

    #[Test]
    public function recoverEmptyTurnRunsWriteTableCreateWithoutShortlist(): void
    {
        $processor = $this->createMock(AgentToolTurnExecutorInterface::class);
        $processor->expects(self::once())
            ->method('execute')
            ->with(
                'write_table',
                self::anything(),
                self::callback(static function (array $body): bool {
                    return ($body['arguments']['action'] ?? null) === 'create'
                        && ($body['arguments']['tableName'] ?? null) === 'tt_content';
                }),
                self::anything(),
                'corr-write',
            )
            ->willReturn([
                'role' => 'assistant',
                'content' => 'Draft proposed',
                'meta' => [
                    'type' => 'inline_draft',
                    'tool' => 'write_table',
                    'correlationId' => 'corr-write',
                ],
            ]);

        $orchestrator = (new \ReflectionClass(AgentTurnOrchestrator::class))->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty(AgentTurnOrchestrator::class, 'toolTurnProcessor');
        $prop->setValue($orchestrator, $processor);

        $method = new \ReflectionMethod(AgentTurnOrchestrator::class, 'recoverEmptyTurnWithPrimaryHit');
        $user = $this->createMock(BackendUserAuthentication::class);
        $message = $method->invoke(
            $orchestrator,
            'write_table',
            [
                'action' => 'create',
                'tableName' => 'tt_content',
                'data' => '{"pid":7,"CType":"header","header":"Welcome"}',
            ],
            [
                ['name' => 'ask_clarification'],
                ['name' => 'explain_capabilities'],
            ],
            ['pageId' => 7],
            [],
            $user,
            'corr-write',
            'embeddings',
            'gpt-4o',
            'openai',
            [],
        );

        self::assertIsArray($message);
        self::assertSame('write_table', $message['meta']['emptyTurnRecovery'] ?? null);
    }

    #[Test]
    public function resolveRecoveryFallsBackToPagesGetWhenPrimaryNeedsArgs(): void
    {
        $orchestrator = (new \ReflectionClass(AgentTurnOrchestrator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentTurnOrchestrator::class, 'resolveEmptyTurnRecovery');

        $result = $method->invoke(
            $orchestrator,
            't3as_list_predefined_questions',
            [
                ['name' => 't3as_list_predefined_questions'],
                ['name' => 'pages_get'],
                ['name' => 'ask_clarification'],
            ],
            'Where am I?',
            ['pageId' => 7],
        );

        self::assertSame(['tool' => 'pages_get', 'arguments' => ['uid' => 7]], $result);
    }

    #[Test]
    public function recoverEmptyTurnRunsToolWithArguments(): void
    {
        $processor = $this->createMock(AgentToolTurnExecutorInterface::class);
        $processor->expects(self::once())
            ->method('execute')
            ->with(
                'content_delete',
                self::anything(),
                self::callback(static fn(array $body): bool => ($body['arguments']['uid'] ?? null) === 20),
                self::anything(),
                'corr-1',
            )
            ->willReturn([
                'role' => 'assistant',
                'content' => 'Delete proposed',
                'meta' => [
                    'type' => 'inline_draft',
                    'tool' => 'content_delete',
                    'correlationId' => 'corr-1',
                ],
            ]);

        $orchestrator = (new \ReflectionClass(AgentTurnOrchestrator::class))->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty(AgentTurnOrchestrator::class, 'toolTurnProcessor');
        $prop->setValue($orchestrator, $processor);

        $method = new \ReflectionMethod(AgentTurnOrchestrator::class, 'recoverEmptyTurnWithPrimaryHit');
        $user = $this->createMock(BackendUserAuthentication::class);
        $message = $method->invoke(
            $orchestrator,
            'content_delete',
            ['uid' => 20],
            [
                ['name' => 'content_delete'],
                ['name' => 'explain_capabilities'],
            ],
            ['pageId' => 7],
            [],
            $user,
            'corr-1',
            'embeddings',
            'gpt-4o',
            'openai',
            [],
        );

        self::assertIsArray($message);
        self::assertSame('content_delete', $message['meta']['emptyTurnRecovery'] ?? null);
    }

    #[Test]
    public function resolveRecoverySkipsContentMove(): void
    {
        $orchestrator = (new \ReflectionClass(AgentTurnOrchestrator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentTurnOrchestrator::class, 'resolveEmptyTurnRecovery');

        $result = $method->invoke(
            $orchestrator,
            'content_move',
            [
                ['name' => 'content_move'],
                ['name' => 'content_list'],
            ],
            'Move something',
            ['pageId' => 7],
        );

        self::assertSame(['tool' => 'content_list', 'arguments' => []], $result);
    }
}
