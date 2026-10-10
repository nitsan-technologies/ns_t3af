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
use NITSAN\NsT3AF\Agent\Service\AgentUndoService;
use NITSAN\NsT3AF\Agent\Service\AgentWriteService;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Undo takes back exactly what the agent wrote, and never reports success when nothing was
 * reverted (ticket 14zervyu2jv).
 *
 * @internal
 */
final class AgentUndoServiceTest extends TestCase
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
    public function undoOfACreateDeletesTheCreatedRecord(): void
    {
        $dataHandler = $this->createMock(DataHandlerService::class);
        $dataHandler->expects(self::once())->method('deleteRecord')->with('pages', 99);
        [$service, $session] = $this->subject($dataHandler);
        $session->storeChange('change-1', [
            'correlationId' => 'corr-1',
            'undoFields' => [
                ['table' => 'pages', 'uid' => 99, 'field' => '_record', 'previousValue' => null, 'action' => 'create'],
            ],
        ]);

        $result = $service->undo('change-1');

        self::assertSame('corr-1', $result['correlationId']);
        self::assertSame([['table' => 'pages', 'uid' => 99, 'field' => '_record', 'reverted' => 'deleted']], $result['reverted']);
        self::assertNull($session->getChange('change-1'));
    }

    #[Test]
    public function undoOfAnAttachRemovesOnlyTheNewReferences(): void
    {
        $dataHandler = $this->createMock(DataHandlerService::class);
        $dataHandler->expects(self::never())->method('deleteRecord');
        $dataHandler->expects(self::exactly(2))->method('removeFileReferences')
            ->willReturnCallback(static function (string $table, int $uid, string $field, array $referenceUids): array {
                self::assertSame(['tt_content', 255, 'assets'], [$table, $uid, $field]);

                return $referenceUids;
            });
        [$service, $session] = $this->subject($dataHandler);
        $undoFields = AgentWriteService::toolUndoFields(
            'file_reference_add',
            '{"table":"tt_content","uid":255,"fieldName":"assets","fileUids":[145,146],"referencesCreated":2,"referenceUids":[100,101]}',
        );
        self::assertTrue(AgentUndoService::isUndoable($undoFields));
        $session->storeChange('change-attach', ['undoFields' => $undoFields]);

        $result = $service->undo('change-attach');

        self::assertSame([100, 101], array_column($result['reverted'], 'uid'));
        self::assertSame(['sys_file_reference', 'sys_file_reference'], array_column($result['reverted'], 'table'));
        self::assertSame([], AgentWriteService::toolUndoFields('file_upload_from_url', ['fileUid' => 5]));
    }

    #[Test]
    public function undoOfAnUpdateRestoresThePreviousValue(): void
    {
        $dataHandler = $this->createMock(DataHandlerService::class);
        $dataHandler->expects(self::once())->method('updateRecord')->with('tt_content', 42, ['header' => 'Old']);
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->willReturn(['header' => 'Old']);
        [$service, $session] = $this->subject($dataHandler, $records);
        $session->storeChange('change-2', [
            'undoFields' => [
                ['table' => 'tt_content', 'uid' => 42, 'field' => 'header', 'previousValue' => 'Old', 'action' => 'update'],
            ],
        ]);

        $result = $service->undo('change-2');

        self::assertSame('restored', $result['reverted'][0]['reverted']);
        self::assertNull($session->getChange('change-2'));
    }

    #[Test]
    public function undoIsRefusedWhenTheFieldWasChangedAfterTheAgent(): void
    {
        $dataHandler = $this->createMock(DataHandlerService::class);
        $dataHandler->expects(self::never())->method('updateRecord');
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->willReturn(['header' => 'Manual newer edit']);
        [$service, $session] = $this->subject($dataHandler, $records);
        $session->storeChange('change-5', [
            'undoFields' => [
                ['table' => 'tt_content', 'uid' => 42, 'field' => 'header', 'previousValue' => 'Old', 'appliedValue' => 'Agent', 'action' => 'update'],
            ],
        ]);

        try {
            $service->undo('change-5');
            self::fail('Undo must not overwrite a newer edit.');
        } catch (\RuntimeException $exception) {
            self::assertSame(1712003303, $exception->getCode());
        }
        self::assertNotNull($session->getChange('change-5'));
    }

    #[Test]
    public function undoRunsWhenTheFieldStillHoldsTheAgentValue(): void
    {
        $dataHandler = $this->createMock(DataHandlerService::class);
        $dataHandler->expects(self::once())->method('updateRecord')->with('tt_content', 42, ['header' => 'Old']);
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->willReturnOnConsecutiveCalls(
            ['header' => 'Agent'],
            ['header' => 'Old'],
        );
        [$service, $session] = $this->subject($dataHandler, $records);
        $session->storeChange('change-6', [
            'undoFields' => [
                ['table' => 'tt_content', 'uid' => 42, 'field' => 'header', 'previousValue' => 'Old', 'appliedValue' => 'Agent', 'action' => 'update'],
            ],
        ]);

        $service->undo('change-6');
    }

    #[Test]
    public function undoBatchesSeoFieldRestoresIntoOneWrite(): void
    {
        $dataHandler = $this->createMock(DataHandlerService::class);
        $dataHandler->expects(self::once())->method('updateRecord')->with('pages', 203, [
            'seo_title' => '',
            'description' => '',
            'keywords' => '',
        ]);
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->willReturnCallback(
            static function (string $table, int $uid, array $fields): array {
                unset($table, $uid);
                // Before undo: still the agent values. After: restored empties.
                $agent = ['seo_title' => 'AI', 'description' => 'AI', 'keywords' => 'AI'];
                $restored = ['seo_title' => '', 'description' => '', 'keywords' => ''];
                static $calls = 0;
                ++$calls;

                return $calls <= 3
                    ? array_intersect_key($agent, array_flip($fields))
                    : array_intersect_key($restored, array_flip($fields));
            },
        );
        [$service, $session] = $this->subject($dataHandler, $records);
        $session->storeChange('change-seo', [
            'undoFields' => [
                ['table' => 'pages', 'uid' => 203, 'field' => 'seo_title', 'previousValue' => '', 'appliedValue' => 'AI', 'action' => 'update'],
                ['table' => 'pages', 'uid' => 203, 'field' => 'description', 'previousValue' => '', 'appliedValue' => 'AI', 'action' => 'update'],
                ['table' => 'pages', 'uid' => 203, 'field' => 'keywords', 'previousValue' => '', 'appliedValue' => 'AI', 'action' => 'update'],
            ],
        ]);

        $result = $service->undo('change-seo');

        self::assertCount(3, $result['reverted']);
    }

    #[Test]
    public function undoWithNothingToRevertFailsAndKeepsTheChange(): void
    {
        $dataHandler = $this->createMock(DataHandlerService::class);
        $dataHandler->expects(self::never())->method('deleteRecord');
        $dataHandler->expects(self::never())->method('updateRecord');
        [$service, $session] = $this->subject($dataHandler);
        // The old create entries pointed at uid 0, so there is no record to take back.
        $session->storeChange('change-3', [
            'undoFields' => [
                ['table' => 'pages', 'uid' => 0, 'field' => 'title', 'previousValue' => null, 'action' => 'create'],
            ],
        ]);

        try {
            $service->undo('change-3');
            self::fail('Undo must not report success when nothing was reverted.');
        } catch (\RuntimeException $exception) {
            self::assertSame(1712003302, $exception->getCode());
        }
        self::assertNotNull($session->getChange('change-3'));
    }

    #[Test]
    public function unknownChangeIsRefused(): void
    {
        [$service] = $this->subject($this->createMock(DataHandlerService::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712003300);
        $service->undo('missing');
    }

    #[Test]
    public function undoOfADeleteIsNotSupported(): void
    {
        [$service, $session] = $this->subject($this->createMock(DataHandlerService::class));
        $session->storeChange('change-4', [
            'undoFields' => [
                ['table' => 'tt_content', 'uid' => 5, 'field' => '_record', 'previousValue' => 'exists', 'action' => 'delete'],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712003301);
        $service->undo('change-4');
    }

    #[Test]
    public function onlyRevertibleChangesOfferUndo(): void
    {
        self::assertTrue(AgentUndoService::isUndoable([
            ['table' => 'tt_content', 'uid' => 42, 'field' => 'header', 'previousValue' => 'Old', 'action' => 'update'],
        ]));
        self::assertTrue(AgentUndoService::isUndoable([
            ['table' => 'pages', 'uid' => 99, 'field' => '_record', 'previousValue' => null, 'action' => 'create'],
        ]));
        // A delete cannot be brought back from here, so no Undo button.
        self::assertFalse(AgentUndoService::isUndoable([
            ['table' => 'tt_content', 'uid' => 5, 'field' => '_record', 'previousValue' => 'exists', 'action' => 'delete'],
        ]));
        self::assertFalse(AgentUndoService::isUndoable([]));
    }

    /**
     * @return array{0: AgentUndoService, 1: AgentDraftSession}
     */
    private function subject(DataHandlerService $dataHandler, ?RecordService $recordService = null): array
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
        $session = new AgentDraftSession();

        return [new AgentUndoService($dataHandler, $session, $this->createAgentTranslator(), $recordService ?? $this->createMock(RecordService::class)), $session];
    }
}
