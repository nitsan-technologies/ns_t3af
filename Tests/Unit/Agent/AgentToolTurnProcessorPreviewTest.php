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

use NITSAN\NsT3AF\Agent\Service\AgentAuditLogger;
use NITSAN\NsT3AF\Agent\Service\AgentDraftSession;
use NITSAN\NsT3AF\Agent\Service\AgentToolEditorLabelService;
use NITSAN\NsT3AF\Agent\Service\AgentToolTurnProcessor;
use NITSAN\NsT3AF\Mcp\Dto\PreviewResult;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolLogRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * @internal
 */
final class AgentToolTurnProcessorPreviewTest extends TestCase
{
    use AgentTranslatorTrait;

    /** @var array<string, mixed> */
    private array $sessionData = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function previewPathStoresDraftOnlyAndNeverInvokesWrite(): void
    {
        $preview = new PreviewResult(
            tool: 't3ai_generate_all_seo',
            target: ['table' => 'pages', 'uid' => 7, 'languageId' => 0],
            fields: [
                ['key' => 'metaTitle', 'label' => 'Meta Title', 'current' => ''],
                ['key' => 'keywords', 'label' => 'Keywords', 'current' => ''],
            ],
            variants: [
                [
                    'label' => 'A',
                    'angle' => '',
                    'values' => ['metaTitle' => 'Title A', 'keywords' => 'kw-a'],
                ],
            ],
            callCount: 1,
            generationPath: PreviewResult::PATH_ENVELOPE,
        );

        $playground = $this->createMock(McpPlaygroundService::class);
        $playground->expects(self::once())
            ->method('preview')
            ->with('t3ai_generate_all_seo', self::isType('array'), 3)
            ->willReturn([
                'success' => true,
                'preview' => $preview,
                'latencyMs' => 5,
                'message' => '',
                'callCount' => 1,
            ]);
        $playground->expects(self::never())->method('invoke');
        $playground->expects(self::never())->method('invokeWithMode');

        $logRepository = $this->createMock(McpToolLogRepository::class);
        $logRepository->expects(self::once())->method('insert');

        $draftSession = $this->createDraftSession();
        $translator = $this->createAgentTranslator();
        $processor = $this->createProcessorForPreview(
            $playground,
            $draftSession,
            new AgentToolEditorLabelService($translator),
            new AgentAuditLogger($logRepository),
            $translator,
        );

        $tool = [
            'name' => 't3ai_generate_all_seo',
            'executable' => true,
            'dualMode' => true,
            'previewable' => true,
            'severity' => ToolSeverity::Write->value,
            'editorLabel' => 'Generate SEO',
        ];

        $method = (new ReflectionClass(AgentToolTurnProcessor::class))->getMethod('processPreviewToolTurn');
        /** @var array{role: string, content: string, meta: array<string, mixed>} $message */
        $message = $method->invoke(
            $processor,
            $tool,
            ['pageId' => 7],
            ['arguments' => ['variants' => 3, 'fieldKeys' => ['metaTitle', 'keywords']]],
            ToolSeverity::Write->value,
            'corr-preview-1',
        );

        self::assertSame('suggestions', $message['meta']['type'] ?? null);
        $draftId = (string) ($message['meta']['draftId'] ?? '');
        self::assertNotSame('', $draftId);
        $stored = $draftSession->getDraft($draftId);
        self::assertIsArray($stored);
        self::assertSame('agent_preview', $stored['flow'] ?? null);
        self::assertSame('t3ai_generate_all_seo', $stored['tool'] ?? null);
        self::assertArrayHasKey('previewResult', $stored);
        self::assertArrayNotHasKey('plan', $stored);
    }

    private function createProcessorForPreview(
        McpPlaygroundService $playground,
        AgentDraftSession $draftSession,
        AgentToolEditorLabelService $editorLabels,
        AgentAuditLogger $auditLogger,
        \NITSAN\NsT3AF\Agent\Service\AgentTranslator $translator,
    ): AgentToolTurnProcessor {
        $reflection = new ReflectionClass(AgentToolTurnProcessor::class);
        /** @var AgentToolTurnProcessor $processor */
        $processor = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'playgroundService' => $playground,
            'draftSession' => $draftSession,
            'editorLabelService' => $editorLabels,
            'auditLogger' => $auditLogger,
            'translator' => $translator,
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($processor, $value);
        }

        return $processor;
    }

    private function createDraftSession(): AgentDraftSession
    {
        $this->sessionData = [];
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('getSessionData')->willReturnCallback(
            function (string $key): mixed {
                return $this->sessionData[$key] ?? null;
            },
        );
        $user->method('setAndSaveSessionData')->willReturnCallback(
            function (string $key, mixed $data): void {
                $this->sessionData[$key] = $data;
            },
        );
        $GLOBALS['BE_USER'] = $user;

        return new AgentDraftSession();
    }
}
