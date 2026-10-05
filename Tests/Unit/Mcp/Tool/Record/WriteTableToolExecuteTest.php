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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Record;

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Record\WriteTableTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Characterization tests for write_table's execute() paths.
 *
 * They pin what the tool returns and which DataHandlerService calls it makes today, so the
 * move onto the shared records_apply engine can be checked against them: a change here is a
 * behaviour change for MCP clients and the Agent.
 *
 * @internal
 */
final class WriteTableToolExecuteTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalTca;

    private DataHandlerService&MockObject $dataHandler;

    private RecordService&MockObject $recordService;

    private WriteTableTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTca = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => ['label' => 'header'],
            'columns' => [
                'header' => ['config' => ['type' => 'input']],
                'categories' => ['config' => ['type' => 'category']],
                'assets' => ['config' => ['type' => 'file']],
            ],
        ];

        $this->dataHandler = $this->createMock(DataHandlerService::class);
        $this->recordService = $this->createMock(RecordService::class);
        $this->tool = new WriteTableTool($this->dataHandler, $this->recordService, new TcaSchemaService());

        $this->bootstrapUser(true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->originalTca;
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function createPassesFilteredFieldsAndReportsIgnoredOnes(): void
    {
        $this->dataHandler->expects(self::once())
            ->method('createRecord')
            ->with('tt_content', 5, ['header' => 'Hi'])
            ->willReturn(77);

        $result = $this->callTool('create', '{"pid":5,"header":"Hi","bogus":1}');

        self::assertSame('create', $result['action']);
        self::assertSame('tt_content', $result['table']);
        self::assertSame(77, $result['uid']);
        self::assertSame(5, $result['pid']);
        self::assertSame(['header'], $result['fields']);
        self::assertSame(['bogus'], $result['ignoredFields']);
        self::assertSame('unknown_or_not_in_tca', $result['ignoredFieldDetails'][0]['reason']);
    }

    #[Test]
    public function createResponseWithoutIgnoredFieldsHasExactlyTheDocumentedKeys(): void
    {
        $this->dataHandler->method('createRecord')->willReturn(77);

        self::assertSame(
            [
                'action' => 'create',
                'table' => 'tt_content',
                'uid' => 77,
                'pid' => 5,
                'fields' => ['header'],
                'ignoredFields' => [],
            ],
            $this->callTool('create', '{"pid":5,"header":"Hi"}'),
        );
    }

    #[Test]
    public function createTurnsRelationUidListsIntoCommaSeparatedStrings(): void
    {
        $this->dataHandler->expects(self::once())
            ->method('createRecord')
            ->with('tt_content', 5, ['header' => 'Hi', 'categories' => '8,12'])
            ->willReturn(77);

        $this->callTool('create', '{"pid":5,"header":"Hi","categories":[8,"12"]}');
    }

    #[Test]
    public function createWritesFileReferencesAfterTheRecordAndReportsTheirUids(): void
    {
        $this->dataHandler->expects(self::once())
            ->method('createRecord')
            ->with('tt_content', 5, ['header' => 'Hi'])
            ->willReturn(77);
        $this->dataHandler->expects(self::once())
            ->method('replaceFileFieldReferences')
            ->with('tt_content', 77, 'assets', [['uid_local' => 93, 'alternative' => 'Alt']])
            ->willReturn([301]);

        $result = $this->callTool('create', '{"pid":5,"header":"Hi","assets":[{"uid_local":93,"alternative":"Alt"}]}');

        self::assertSame(['assets' => [301]], $result['fileFields']);
    }

    #[Test]
    public function createWithOnlyFileFieldsStillCreatesTheRecord(): void
    {
        $this->dataHandler->expects(self::once())
            ->method('createRecord')
            ->with('tt_content', 5, [])
            ->willReturn(77);
        $this->dataHandler->expects(self::once())
            ->method('replaceFileFieldReferences')
            ->willReturn([301]);

        $result = $this->callTool('create', '{"pid":5,"assets":[{"uid_local":93}]}');

        self::assertSame([], $result['fields']);
    }

    #[Test]
    public function createWithoutAnyWritableFieldIsRefusedWithTheIgnoredDetails(): void
    {
        $this->dataHandler->expects(self::never())->method('createRecord');

        $result = $this->callTool('create', '{"pid":5,"bogus":1}');

        self::assertSame('No valid writable fields provided.', $result['error']);
        self::assertSame(['bogus'], $result['ignoredFields']);
        self::assertArrayHasKey('ignoredFieldDetails', $result);
    }

    #[Test]
    public function updatePassesFilteredFieldsForAnExistingRecord(): void
    {
        $this->recordService->method('findExistingUids')->willReturn([42]);
        $this->dataHandler->expects(self::once())
            ->method('updateRecord')
            ->with('tt_content', 42, ['header' => 'New']);

        self::assertSame(
            [
                'action' => 'update',
                'table' => 'tt_content',
                'uid' => 42,
                'fields' => ['header'],
                'ignoredFields' => [],
            ],
            $this->callTool('update', '{"header":"New"}', 42),
        );
    }

    #[Test]
    public function updateOfAMissingRecordIsAnError(): void
    {
        $this->recordService->method('findExistingUids')->willReturn([]);
        $this->dataHandler->expects(self::never())->method('updateRecord');

        self::assertSame(
            'Record not found: tt_content uid 42',
            $this->callTool('update', '{"header":"New"}', 42)['error'],
        );
    }

    #[Test]
    public function updateWithOnlyFileFieldsSkipsTheFieldWriteAndReplacesTheReferences(): void
    {
        $this->recordService->method('findExistingUids')->willReturn([42]);
        $this->dataHandler->expects(self::never())->method('updateRecord');
        $this->dataHandler->expects(self::once())
            ->method('replaceFileFieldReferences')
            ->with('tt_content', 42, 'assets', [])
            ->willReturn([]);

        $result = $this->callTool('update', '{"assets":[]}', 42);

        self::assertSame([], $result['fields']);
    }

    #[Test]
    public function updateWithoutAnyWritableFieldIsRefused(): void
    {
        $this->recordService->method('findExistingUids')->willReturn([42]);
        $this->dataHandler->expects(self::never())->method('updateRecord');

        self::assertSame(
            'No valid writable fields provided.',
            $this->callTool('update', '{"bogus":1}', 42)['error'],
        );
    }

    #[Test]
    public function deleteRemovesAnExistingRecord(): void
    {
        $this->recordService->method('findExistingUids')->willReturn([42]);
        $this->dataHandler->expects(self::once())->method('deleteRecord')->with('tt_content', 42);

        self::assertSame(
            ['action' => 'delete', 'table' => 'tt_content', 'uid' => 42],
            $this->callTool('delete', '{}', 42),
        );
    }

    #[Test]
    public function deleteOfAMissingRecordIsAnError(): void
    {
        $this->recordService->method('findExistingUids')->willReturn([]);
        $this->dataHandler->expects(self::never())->method('deleteRecord');

        self::assertSame('Record not found: tt_content uid 42', $this->callTool('delete', '{}', 42)['error']);
    }

    #[Test]
    public function aUserWithoutTableModifyRightIsDeniedBeforeAnythingIsRead(): void
    {
        $this->bootstrapUser(false);
        $this->recordService->expects(self::never())->method('findExistingUids');
        $this->dataHandler->expects(self::never())->method('updateRecord');

        self::assertSame(
            'Permission denied: tables_modify on tt_content',
            $this->callTool('update', '{"header":"New"}', 42)['error'],
        );
    }

    #[Test]
    public function withoutABackendUserNothingIsWritten(): void
    {
        unset($GLOBALS['BE_USER']);
        $this->dataHandler->expects(self::never())->method('createRecord');

        self::assertStringContainsString(
            'No backend user context',
            $this->callTool('create', '{"pid":5,"header":"Hi"}')['error'],
        );
    }

    #[Test]
    public function aDataHandlerFailureBecomesAnErrorPayload(): void
    {
        $this->dataHandler->method('createRecord')->willThrowException(new \RuntimeException('boom'));

        self::assertSame('boom', $this->callTool('create', '{"pid":5,"header":"Hi"}')['error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function callTool(string $action, string $data, int $uid = 0): array
    {
        $decoded = json_decode($this->tool->execute($action, 'tt_content', $data, $uid), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function bootstrapUser(bool $mayModify): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('check')->willReturn($mayModify);
        $GLOBALS['BE_USER'] = $backendUser;
    }
}
