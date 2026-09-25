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
use NITSAN\NsT3AF\Agent\Service\AgentTranslator;
use NITSAN\NsT3AF\Agent\Service\AgentTurnRouter;
use NITSAN\NsT3AF\Agent\Service\PermittedActionProvider;
use NITSAN\NsT3AF\Mcp\Service\FileService;
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

        $this->router = new AgentTurnRouter(
            new AgentMessageParser(),
            new AgentRecordAttachmentResolver(),
            $this->toolTurnProcessor,
            $this->turnOrchestrator,
            (new \ReflectionClass(PermittedActionProvider::class))->newInstanceWithoutConstructor(),
            $this->createMock(FileService::class),
            (new \ReflectionClass(AgentTranslator::class))->newInstanceWithoutConstructor(),
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
                'content' => 'ok',
                'meta' => ['tool' => 'pages_get'],
            ]);
        $this->turnOrchestrator->expects(self::never())->method('runTurn');

        $messages = $this->router->route(
            '/pages_get 49',
            ['pageId' => 1],
            [],
            $user,
            'corr-1',
        );

        self::assertCount(1, $messages);
        self::assertSame('pages_get', $messages[0]['meta']['tool'] ?? null);
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
                'meta' => ['tool' => 't3ai_generate_all_seo'],
            ]);
        $this->turnOrchestrator->expects(self::never())->method('runTurn');

        $messages = $this->router->route(
            '/generate_seo_metadata',
            ['pageId' => 7],
            ['action' => 'generate_seo_metadata'],
            $user,
            'corr-3',
        );

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
}
