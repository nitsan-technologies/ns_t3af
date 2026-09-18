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

use NITSAN\NsT3AF\Agent\Service\AgentDraftSession;
use NITSAN\NsT3AF\Agent\Service\AgentLanguageResolver;
use NITSAN\NsT3AF\Agent\Service\AgentLowRiskFieldMatrix;
use NITSAN\NsT3AF\Agent\Service\AgentToolEditorLabelService;
use NITSAN\NsT3AF\Agent\Service\AgentToolResultPresenter;
use NITSAN\NsT3AF\Agent\Service\AgentWriteService;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use NITSAN\NsT3AF\Mcp\Dto\PreviewResult;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\McpModeResolver;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * @internal
 */
final class AgentWriteServiceSuggestionsTest extends TestCase
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
    public function applySuggestionsWritesExactSelectedVariantValuesViaContextMode(): void
    {
        $preview = new PreviewResult(
            tool: 't3ai_generate_all_seo',
            target: ['table' => 'pages', 'uid' => 42, 'languageId' => 0],
            fields: [
                ['key' => 'metaTitle', 'label' => 'Meta Title', 'current' => 'Old title'],
                ['key' => 'keywords', 'label' => 'Keywords', 'current' => 'old'],
            ],
            variants: [
                [
                    'label' => 'Variant 1',
                    'angle' => '',
                    'values' => [
                        'metaTitle' => 'Title A',
                        'keywords' => 'kw-a',
                    ],
                ],
                [
                    'label' => 'Variant 2',
                    'angle' => '',
                    'values' => [
                        'metaTitle' => 'Title B',
                        'keywords' => 'kw-b',
                    ],
                ],
            ],
            callCount: 2,
        );

        $draftSession = $this->createDraftSession();
        $draftSession->storeDraft('draft-1', [
            'flow' => 'agent_preview',
            'previewResult' => $preview->toArray(),
            'arguments' => ['pageId' => 42],
            'severity' => 'write',
            'tool' => 't3ai_generate_all_seo',
            'destructiveArmed' => false,
        ]);

        $capturedArguments = null;
        $playground = $this->createMock(McpPlaygroundService::class);
        $playground->expects(self::once())
            ->method('invokeWithMode')
            ->willReturnCallback(
                static function (string $tool, array $arguments, string $mode) use (&$capturedArguments): array {
                    self::assertSame('t3ai_generate_all_seo', $tool);
                    self::assertSame(McpModeResolver::MODE_CONTEXT, $mode);
                    $capturedArguments = $arguments;

                    return [
                        'success' => true,
                        'result' => ['ok' => true],
                        'latencyMs' => 12,
                        'message' => '',
                    ];
                },
            );

        $service = new AgentWriteService(
            $this->createMock(DataHandlerService::class),
            $this->createMock(RecordService::class),
            $draftSession,
            $playground,
            $this->createPresenter(),
            $this->createAgentTranslator(),
            new AgentLowRiskFieldMatrix(),
        );

        $result = $service->applySuggestions(
            'draft-1',
            ['metaTitle' => 1, 'keywords' => 0],
        );

        self::assertTrue(($result['suggestionsApply'] ?? false) === true);
        self::assertSame(2, $result['appliedCount']);
        self::assertSame(
            ['metaTitle' => 'Title B', 'keywords' => 'kw-a'],
            $result['appliedValues'],
        );
        self::assertIsArray($capturedArguments);
        self::assertSame(42, $capturedArguments['pageId'] ?? null);
        self::assertSame(
            ['metaTitle' => 'Title B', 'keywords' => 'kw-a'],
            $capturedArguments['fields'] ?? null,
        );
        self::assertNull($draftSession->getDraft('draft-1'));
    }

    #[Test]
    public function applySuggestionsMergesEditorEditsOverSelections(): void
    {
        $preview = new PreviewResult(
            tool: 't3aa_update_file_metadata',
            target: ['table' => 'sys_file_metadata', 'uid' => 9, 'languageId' => 0],
            fields: [
                ['key' => 'altText', 'label' => 'Alt', 'current' => ''],
                ['key' => 'title', 'label' => 'Title', 'current' => ''],
            ],
            variants: [
                [
                    'label' => 'Variant 1',
                    'angle' => '',
                    'values' => [
                        'altText' => 'Generated alt',
                        'title' => 'Generated title',
                    ],
                ],
            ],
            callCount: 1,
        );

        $draftSession = $this->createDraftSession();
        $draftSession->storeDraft('draft-2', [
            'flow' => 'agent_preview',
            'previewResult' => $preview->toArray(),
            'arguments' => ['fileUid' => 9],
            'severity' => 'write',
            'tool' => 't3aa_update_file_metadata',
            'destructiveArmed' => false,
        ]);

        $capturedArguments = null;
        $playground = $this->createMock(McpPlaygroundService::class);
        $playground->method('invokeWithMode')->willReturnCallback(
            static function (string $tool, array $arguments, string $mode) use (&$capturedArguments): array {
                self::assertSame(McpModeResolver::MODE_CONTEXT, $mode);
                $capturedArguments = $arguments;

                return ['success' => true, 'result' => [], 'latencyMs' => 1, 'message' => ''];
            },
        );

        $service = new AgentWriteService(
            $this->createMock(DataHandlerService::class),
            $this->createMock(RecordService::class),
            $draftSession,
            $playground,
            $this->createPresenter(),
            $this->createAgentTranslator(),
            new AgentLowRiskFieldMatrix(),
        );

        $result = $service->applySuggestions(
            'draft-2',
            ['altText' => 0, 'title' => 0],
            ['altText' => 'Editor override'],
            true,
        );

        self::assertSame(
            ['altText' => 'Editor override', 'title' => 'Generated title'],
            $result['appliedValues'],
        );
        self::assertSame('Editor override', $capturedArguments['altText'] ?? null);
        self::assertSame('Generated title', $capturedArguments['title'] ?? null);
        self::assertArrayNotHasKey('fields', $capturedArguments ?? []);
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

    private function createPresenter(): AgentToolResultPresenter
    {
        $translator = $this->createAgentTranslator();

        return new AgentToolResultPresenter(
            $this->createMock(AiServiceInterface::class),
            new AgentToolEditorLabelService($translator),
            new AgentLanguageResolver($this->createMock(SiteFinder::class)),
            $translator,
        );
    }
}
