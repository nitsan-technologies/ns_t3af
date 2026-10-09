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

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyResult;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyValidationException;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Record\WriteTableTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * write_table's execute() paths: what the tool returns and what it hands to the records_apply engine,
 * which does the actual write. The response shapes are the contract MCP clients and the Agent know.
 *
 * @internal
 */
final class WriteTableToolExecuteTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalTca;

    private DataHandlerService&MockObject $dataHandler;

    private RecordService&MockObject $recordService;

    private RecordsApplyService&MockObject $engine;

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
        $this->engine = $this->createMock(RecordsApplyService::class);
        $this->tool = new WriteTableTool($this->dataHandler, $this->recordService, new TcaSchemaService(), $this->engine);

        $this->bootstrapUser(true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->originalTca;
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function createHandsTheFilteredFieldsToTheEngineAndReportsIgnoredOnes(): void
    {
        $this->expectEngineCall(['tt_content' => ['NEWrecord' => ['pid' => 5, 'header' => 'Hi']]], [])
            ->willReturn($this->created(77));

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
    public function createRunsNonStrictAppendingAndIsAuditedAsWriteTable(): void
    {
        $this->engine->expects(self::once())
            ->method('apply')
            ->with(self::anything(), [], false, false, true, [], 'write_table')
            ->willReturn($this->created(77));

        $this->callTool('create', '{"pid":5,"header":"Hi"}');
    }

    #[Test]
    public function createResponseWithoutIgnoredFieldsHasExactlyTheDocumentedKeys(): void
    {
        $this->engine->method('apply')->willReturn($this->created(77));

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
        $this->expectEngineCall(['tt_content' => ['NEWrecord' => ['pid' => 5, 'header' => 'Hi', 'categories' => '8,12']]], [])
            ->willReturn($this->created(77));

        $this->callTool('create', '{"pid":5,"header":"Hi","categories":[8,"12"]}');
    }

    #[Test]
    public function aNegativePidIsPassedOnAndReportedAsGiven(): void
    {
        $this->expectEngineCall(['tt_content' => ['NEWrecord' => ['pid' => -9, 'header' => 'Hi']]], [])
            ->willReturn($this->created(77));

        self::assertSame(-9, $this->callTool('create', '{"pid":-9,"header":"Hi"}')['pid']);
    }

    #[Test]
    public function createWritesFileReferencesAfterTheRecordAndReportsTheirUids(): void
    {
        $this->engine->expects(self::once())->method('apply')->willReturn($this->created(77));
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
        $this->expectEngineCall(['tt_content' => ['NEWrecord' => ['pid' => 5]]], [])
            ->willReturn($this->created(77));
        $this->dataHandler->expects(self::once())
            ->method('replaceFileFieldReferences')
            ->willReturn([301]);

        $result = $this->callTool('create', '{"pid":5,"assets":[{"uid_local":93}]}');

        self::assertSame([], $result['fields']);
    }

    #[Test]
    public function createWithoutAnyWritableFieldIsRefusedWithTheIgnoredDetails(): void
    {
        $this->engine->expects(self::never())->method('apply');

        $result = $this->callTool('create', '{"pid":5,"bogus":1}');

        self::assertSame('No valid writable fields provided.', $result['error']);
        self::assertSame(['bogus'], $result['ignoredFields']);
        self::assertArrayHasKey('ignoredFieldDetails', $result);
    }

    #[Test]
    public function fieldsTheEngineDropsAreReportedAsIgnored(): void
    {
        $this->engine->method('apply')->willReturn(new RecordsApplyResult(
            'ra-test',
            false,
            true,
            ['NEWrecord' => 77],
            [],
            [],
            [['table' => 'tt_content', 'id' => 'NEWrecord', 'fields' => ['header']]],
        ));

        $result = $this->callTool('create', '{"pid":5,"header":"Hi"}');

        self::assertSame([], $result['fields']);
        self::assertSame(['header'], $result['ignoredFields']);
    }

    #[Test]
    public function createWithoutAUidFromTheEngineIsAnError(): void
    {
        $this->engine->method('apply')->willReturn($this->created(0));

        self::assertSame(
            'Failed to create record: no uid returned',
            $this->callTool('create', '{"pid":5,"header":"Hi"}')['error'],
        );
    }

    #[Test]
    public function updateHandsTheFilteredFieldsOfAnExistingRecordToTheEngine(): void
    {
        $this->recordService->method('findExistingUids')->willReturn([42]);
        $this->expectEngineCall(['tt_content' => [42 => ['header' => 'New']]], [])
            ->willReturn($this->created());

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
        $this->engine->expects(self::never())->method('apply');

        self::assertSame(
            'Record not found: tt_content uid 42',
            $this->callTool('update', '{"header":"New"}', 42)['error'],
        );
    }

    #[Test]
    public function updateWithOnlyFileFieldsSkipsTheFieldWriteAndReplacesTheReferences(): void
    {
        $this->recordService->method('findExistingUids')->willReturn([42]);
        $this->engine->expects(self::never())->method('apply');
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
        $this->engine->expects(self::never())->method('apply');

        self::assertSame(
            'No valid writable fields provided.',
            $this->callTool('update', '{"bogus":1}', 42)['error'],
        );
    }

    #[Test]
    public function deleteHandsACmdDeleteToTheEngine(): void
    {
        $this->recordService->method('findExistingUids')->willReturn([42]);
        $this->expectEngineCall([], ['tt_content' => [42 => ['delete' => 1]]])
            ->willReturn($this->created());

        self::assertSame(
            ['action' => 'delete', 'table' => 'tt_content', 'uid' => 42],
            $this->callTool('delete', '{}', 42),
        );
    }

    #[Test]
    public function deleteOfAMissingRecordIsAnError(): void
    {
        $this->recordService->method('findExistingUids')->willReturn([]);
        $this->engine->expects(self::never())->method('apply');

        self::assertSame('Record not found: tt_content uid 42', $this->callTool('delete', '{}', 42)['error']);
    }

    #[Test]
    public function aUserWithoutTableModifyRightIsDeniedBeforeAnythingIsRead(): void
    {
        $this->bootstrapUser(false);
        $this->recordService->expects(self::never())->method('findExistingUids');
        $this->engine->expects(self::never())->method('apply');

        self::assertSame(
            'Permission denied: tables_modify on tt_content',
            $this->callTool('update', '{"header":"New"}', 42)['error'],
        );
    }

    #[Test]
    public function withoutABackendUserNothingIsWritten(): void
    {
        unset($GLOBALS['BE_USER']);
        $this->engine->expects(self::never())->method('apply');

        self::assertStringContainsString(
            'No backend user context',
            $this->callTool('create', '{"pid":5,"header":"Hi"}')['error'],
        );
    }

    #[Test]
    public function anEngineFailureBecomesAnErrorPayload(): void
    {
        $this->engine->method('apply')->willThrowException(new \RuntimeException('boom'));

        self::assertSame('boom', $this->callTool('create', '{"pid":5,"header":"Hi"}')['error']);
    }

    #[Test]
    public function aRefusalBeforeTheWriteIsReportedWithItsProblems(): void
    {
        $this->engine->method('apply')->willThrowException(new RecordsApplyValidationException([
            ['table' => 'tt_content', 'id' => 'NEWrecord', 'error' => 'Page 404 was not found or is not accessible.'],
            ['table' => 'tt_content', 'id' => 'NEWrecord', 'error' => 'Not writable fields (strict mode refuses the whole call).', 'fields' => ['a', 'b']],
        ]));

        self::assertSame(
            'Page 404 was not found or is not accessible.; Not writable fields (strict mode refuses the whole call). (a, b)',
            $this->callTool('create', '{"pid":404,"header":"Hi"}')['error'],
        );
    }

    #[Test]
    public function aDataHandlerRefusalKeepsTheMessageFormatClientsKnow(): void
    {
        $this->engine->method('apply')->willThrowException(new ToolCallException(
            (string) json_encode(['ok' => false, 'stage' => 'datahandler', 'errors' => ['first', 'second']]),
            1790500004,
        ));

        self::assertSame(
            'DataHandler errors: first; second',
            $this->callTool('create', '{"pid":5,"header":"Hi"}')['error'],
        );
    }

    /**
     * @param array<string, mixed> $datamap
     * @param array<string, mixed> $cmdmap
     */
    private function expectEngineCall(array $datamap, array $cmdmap): \PHPUnit\Framework\MockObject\Builder\InvocationMocker
    {
        return $this->engine->expects(self::once())
            ->method('apply')
            ->with($datamap, $cmdmap, false, false, true, [], 'write_table');
    }

    private function created(int $uid = 0): RecordsApplyResult
    {
        return new RecordsApplyResult('ra-test', false, true, $uid > 0 ? ['NEWrecord' => $uid] : [], [], [], []);
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

    #[Test]
    public function strictRefusesUnknownFieldsAndWritesNothing(): void
    {
        $this->engine->expects(self::never())->method('apply');

        $result = json_decode($this->tool->execute('create', 'tt_content', '{"pid":5,"header":"Hi","bogus":1}', 0, true), true);

        self::assertStringContainsString('bogus', $result['error']);
        self::assertSame(['bogus'], $result['ignoredFields']);
    }

    #[Test]
    public function strictIsHandedToTheEngineSoItCanRefuseFieldsTheEditorMayNotChange(): void
    {
        $this->engine->expects(self::once())
            ->method('apply')
            ->with(self::anything(), [], false, true, true, [], 'write_table')
            ->willReturn($this->created(77));

        $this->tool->execute('create', 'tt_content', '{"pid":5,"header":"Hi"}', 0, true);
    }
}
