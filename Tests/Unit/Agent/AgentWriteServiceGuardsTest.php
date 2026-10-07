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
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Safety checks before a change card is applied: old cards expire (ticket 14zervyu2kg) and a value
 * somebody else changed since the plan was made is not silently overwritten (ticket 14zervyu2jy).
 *
 * @internal
 */
final class AgentWriteServiceGuardsTest extends TestCase
{
    use AgentTranslatorTrait;

    private const FIELD_KEY = 'tt_content:42:header';

    /** @var array<string, mixed> */
    private array $sessionData = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function cardOlderThanOneHourIsRefused(): void
    {
        $dataHandler = $this->createMock(DataHandlerService::class);
        $dataHandler->expects(self::never())->method('applyFilteredPlan');
        $service = $this->service($dataHandler, $this->createMock(RecordService::class), time() - 7200);

        try {
            $service->apply('draft-1', [self::FIELD_KEY]);
            self::fail('An old card must not be applied.');
        } catch (\RuntimeException $exception) {
            self::assertSame(1712003220, $exception->getCode());
        }
    }

    #[Test]
    public function recentCardIsApplied(): void
    {
        $service = $this->service($this->dataHandlerApplying(), $this->recordsWithHeader('Old'), time() - 60);

        $result = $service->apply('draft-1', [self::FIELD_KEY]);

        self::assertSame(1, $result['appliedCount']);
    }

    #[Test]
    public function cardWithoutTimestampStillWorks(): void
    {
        $service = $this->service($this->dataHandlerApplying(), $this->recordsWithHeader('Old'), null);

        $result = $service->apply('draft-1', [self::FIELD_KEY]);

        self::assertSame(1, $result['appliedCount']);
    }

    #[Test]
    public function valueChangedBySomebodyElseIsNotOverwritten(): void
    {
        $dataHandler = $this->createMock(DataHandlerService::class);
        $dataHandler->expects(self::never())->method('applyFilteredPlan');
        $service = $this->service($dataHandler, $this->recordsWithHeader('Edited by a colleague'), time());

        try {
            $service->apply('draft-1', [self::FIELD_KEY]);
            self::fail('A changed value must stop the apply.');
        } catch (\RuntimeException $exception) {
            self::assertSame(1712003221, $exception->getCode());
            self::assertStringContainsString('tt_content #42 (header)', $exception->getMessage());
        }
    }

    #[Test]
    public function whitespaceOnlyDifferenceIsNotAConflict(): void
    {
        $service = $this->service($this->dataHandlerApplying(), $this->recordsWithHeader("Old \n"), time());

        $result = $service->apply('draft-1', [self::FIELD_KEY]);

        self::assertSame(1, $result['appliedCount']);
    }

    #[Test]
    public function createPlanHasNothingToCompare(): void
    {
        $plan = new ToolPlan('create', 'write_table', [
            new ToolPlanField('tt_content:0:header', 'tt_content', 0, 'header', null, 'New'),
        ], ['pid' => 5]);
        $records = $this->createMock(RecordService::class);
        $records->expects(self::never())->method('findByUid');
        $dataHandler = $this->createMock(DataHandlerService::class);
        $dataHandler->method('applyFilteredPlan')->willReturn(['appliedFieldKeys' => ['tt_content:0:header'], 'affected' => []]);
        $service = $this->service($dataHandler, $records, time(), $plan);

        $result = $service->apply('draft-1', ['tt_content:0:header']);

        self::assertSame('create', $result['action']);
    }

    /** @return DataHandlerService&MockObject */
    private function dataHandlerApplying(): DataHandlerService
    {
        $dataHandler = $this->createMock(DataHandlerService::class);
        $dataHandler->method('applyFilteredPlan')->willReturn([
            'appliedFieldKeys' => [self::FIELD_KEY],
            'affected' => [['table' => 'tt_content', 'uid' => 42]],
        ]);

        return $dataHandler;
    }

    /** @return RecordService&MockObject */
    private function recordsWithHeader(string $header): RecordService
    {
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->willReturn(['header' => $header]);

        return $records;
    }

    private function service(
        DataHandlerService $dataHandler,
        RecordService $records,
        ?int $createdAt,
        ?ToolPlan $plan = null,
    ): AgentWriteService {
        $plan ??= new ToolPlan('update', 'write_table', [
            new ToolPlanField(self::FIELD_KEY, 'tt_content', 42, 'header', 'Old', 'New'),
        ]);
        $stored = [
            'plan' => $plan->toArray(),
            'severity' => 'write',
            'tool' => 'write_table',
            'destructiveArmed' => false,
        ];
        if ($createdAt !== null) {
            $stored['createdAt'] = $createdAt;
        }
        $draftSession = $this->createDraftSession();
        $draftSession->storeDraft('draft-1', $stored);

        $translator = $this->createAgentTranslator();

        return new AgentWriteService(
            $dataHandler,
            $records,
            $draftSession,
            $this->createMock(McpPlaygroundService::class),
            new AgentToolResultPresenter(
                $this->createMock(AiServiceInterface::class),
                new AgentToolEditorLabelService($translator),
                new AgentLanguageResolver($this->createMock(SiteFinder::class)),
                $translator,
            ),
            $translator,
            new AgentLowRiskFieldMatrix(),
        );
    }

    private function createDraftSession(): AgentDraftSession
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('getSessionData')->willReturnCallback(
            fn(string $key): mixed => $this->sessionData[$key] ?? null,
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
