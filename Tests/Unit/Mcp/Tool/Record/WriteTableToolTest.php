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
use NITSAN\NsT3AF\Mcp\Service\PageAccessService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Record\WriteTableTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * @internal
 */
final class WriteTableToolTest extends TestCase
{
    private WriteTableTool $tool;

    /** @var array<string, mixed> */
    private array $originalTca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTca = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => ['label' => 'header'],
            'columns' => [
                'header' => ['config' => ['type' => 'input']],
            ],
        ];

        $tcaSchemaService = new TcaSchemaService();
        $recordService = $this->createMock(RecordService::class);
        $dataHandlerService = $this->createMock(DataHandlerService::class);

        $this->tool = new WriteTableTool($dataHandlerService, $recordService, $tcaSchemaService, $this->createMock(RecordsApplyService::class));
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->originalTca;
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function rejectsUnknownAction(): void
    {
        $this->bootstrapAdminUser();

        $result = json_decode($this->tool->execute('patch', 'tt_content'), true);

        self::assertSame('Invalid action. Use create, update, or delete.', $result['error']);
    }

    #[Test]
    public function rejectsUnknownTable(): void
    {
        $this->bootstrapAdminUser();

        $result = json_decode($this->tool->execute('create', 'does_not_exist', '{"pid":1,"header":"Hi"}'), true);

        self::assertSame('Table not found: does_not_exist', $result['error']);
    }

    #[Test]
    public function createRequiresPidInData(): void
    {
        $this->bootstrapAdminUser();

        $result = json_decode($this->tool->execute('create', 'tt_content', '{"header":"Hi"}'), true);

        self::assertSame('Create requires numeric "pid" in data.', $result['error']);
    }

    #[Test]
    public function updateRequiresUid(): void
    {
        $this->bootstrapAdminUser();

        $result = json_decode($this->tool->execute('update', 'tt_content', '{"header":"Hi"}', 0), true);

        self::assertSame('Update requires uid > 0.', $result['error']);
    }

    #[Test]
    public function planUpdateBuildsDiffFields(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([42]);
        $recordService->method('findByUid')->willReturn(['header' => 'Old']);

        $tool = new WriteTableTool(
            $this->createMock(DataHandlerService::class),
            $recordService,
            new TcaSchemaService(),
            $this->createMock(RecordsApplyService::class),
        );

        $plan = $tool->plan([
            'action' => 'update',
            'tableName' => 'tt_content',
            'uid' => 42,
            'data' => ['header' => 'New'],
        ]);

        self::assertSame('update', $plan->action);
        self::assertSame('tt_content:42:header', $plan->fields[0]->key);
        self::assertSame('Old', $plan->fields[0]->currentValue);
        self::assertSame('New', $plan->fields[0]->proposedValue);
    }

    #[Test]
    public function planUpdateWithOnlyFileFieldsGivesAClearHintInsteadOfSelectError(): void
    {
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => ['label' => 'header'],
            'columns' => [
                'header' => ['config' => ['type' => 'input']],
                'assets' => ['config' => ['type' => 'file']],
            ],
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([480]);
        $recordService->expects(self::never())->method('findByUid');

        $tool = new WriteTableTool(
            $this->createMock(DataHandlerService::class),
            $recordService,
            new TcaSchemaService(),
            $this->createMock(RecordsApplyService::class),
        );

        try {
            $tool->plan([
                'action' => 'update',
                'tableName' => 'tt_content',
                'uid' => 480,
                'data' => ['assets' => [['uid_local' => 93]]],
            ]);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('No valid writable fields provided', $exception->getMessage());
            self::assertStringContainsString('file_reference_add', $exception->getMessage());
            self::assertStringNotContainsString('uid_local', $exception->getMessage());
            self::assertStringNotContainsString('No SELECT expressions', $exception->getMessage());
        }
    }

    #[Test]
    public function aTableTheUserMayNotChangeIsRefusedBeforeAnyPageOrFieldCheck(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        // No pid and a page the user cannot read: neither may be mentioned, the table decides first.
        foreach ([[], ['pid' => 999999, 'title' => 'X']] as $data) {
            try {
                $this->tool->plan(['action' => 'create', 'tableName' => 'tt_content', 'data' => json_encode($data)]);
                self::fail('A table without modify rights must be refused.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('You are not allowed to change this kind of record with your backend account.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function aUserWhoMayChangeTheTableStillGetsThePidHint(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Create requires numeric "pid" in data.');
        $this->tool->plan(['action' => 'create', 'tableName' => 'tt_content', 'data' => '{}']);
    }

    #[Test]
    public function aFieldTheGroupMayNotEditIsRefusedWhenThePlanIsBuilt(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['subheader'] = ['exclude' => true, 'config' => ['type' => 'input']];
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->willReturnCallback(
            static fn(string $type, string $value): bool => $type === 'tables_modify',
        );
        $GLOBALS['BE_USER'] = $backendUser;

        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([42]);
        $tool = new WriteTableTool(
            $this->createMock(DataHandlerService::class),
            $recordService,
            new TcaSchemaService(),
            $this->createMock(RecordsApplyService::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not allowed to change this field with your backend account: subheader.');
        $tool->plan(['action' => 'update', 'tableName' => 'tt_content', 'uid' => 42, 'data' => ['subheader' => 'Neu']]);
    }

    #[Test]
    public function aCreateCardDoesNotOfferAFieldTheGroupMayNotEdit(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['subheader'] = ['exclude' => true, 'config' => ['type' => 'input']];
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->willReturnCallback(
            static fn(string $type, string $value): bool => $type === 'tables_modify',
        );
        $GLOBALS['BE_USER'] = $backendUser;

        $plan = $this->tool->plan([
            'action' => 'create',
            'tableName' => 'tt_content',
            'data' => ['pid' => 1, 'header' => 'Hello', 'subheader' => 'Sub'],
        ]);

        self::assertSame(['header'], array_map(static fn($field): string => $field->field, $plan->fields));
        self::assertSame(['subheader'], $plan->context['notAllowedFields'] ?? null);
    }

    #[Test]
    public function aCreateOfOnlyForbiddenFieldsIsRefusedEvenWhenTheTypeIsKept(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['subheader'] = ['exclude' => true, 'config' => ['type' => 'input']];
        $GLOBALS['TCA']['tt_content']['columns']['CType'] = ['config' => ['type' => 'select']];
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->willReturnCallback(
            static fn(string $type, string $value): bool => $type === 'tables_modify',
        );
        $GLOBALS['BE_USER'] = $backendUser;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not allowed to change this field with your backend account: subheader.');
        $this->tool->plan([
            'action' => 'create',
            'tableName' => 'tt_content',
            'data' => ['pid' => 1, 'CType' => 'text', 'subheader' => 'Sub'],
        ]);
    }

    private function bootstrapAdminUser(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('check')->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    #[Test]
    public function deleteNeedsNoDataAndBadDataIsExplained(): void
    {
        self::assertSame([], \NITSAN\NsT3AF\Mcp\Tool\Record\WriteTableTool::decodeData('', 'delete'));
        self::assertSame([], \NITSAN\NsT3AF\Mcp\Tool\Record\WriteTableTool::decodeData('not json', 'delete'));
        self::assertSame([], \NITSAN\NsT3AF\Mcp\Tool\Record\WriteTableTool::decodeData(' ', 'update'));
        self::assertSame(['title' => 'A'], \NITSAN\NsT3AF\Mcp\Tool\Record\WriteTableTool::decodeData('{"title":"A"}', 'create'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('data must be a JSON object of field values');
        \NITSAN\NsT3AF\Mcp\Tool\Record\WriteTableTool::decodeData('title=A', 'create');
    }

    #[Test]
    public function planCreateOnPageOutsideEditorAccessIsRefused(): void
    {
        $access = $this->createMock(PageAccessService::class);
        $access->method('isUnrestricted')->willReturn(false);
        $access->method('canReadPage')->with(225)->willReturn(false);

        $tool = new WriteTableTool(
            $this->createMock(DataHandlerService::class),
            $this->createMock(RecordService::class),
            new TcaSchemaService(),
            $this->createMock(RecordsApplyService::class),
            $access,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(PageAccessService::ACCESS_DENIED_MESSAGE);
        $tool->plan([
            'action' => 'create',
            'tableName' => 'tt_content',
            'data' => ['pid' => 225, 'header' => 'Test'],
        ]);
    }

    #[Test]
    public function planCreateAfterAMissingRecordIsRefused(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->expects(self::once())
            ->method('assertInsertAfterExists')
            ->with('tt_content', 12)
            ->willThrowException(new \InvalidArgumentException('Record not found: tt_content uid 12'));

        $tool = new WriteTableTool(
            $this->createMock(DataHandlerService::class),
            $records,
            new TcaSchemaService(),
            $this->createMock(RecordsApplyService::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Record not found');
        $tool->plan([
            'action' => 'create',
            'tableName' => 'tt_content',
            'data' => ['pid' => -12, 'header' => 'After'],
        ]);
    }

    #[Test]
    public function planCreateAfterARecordKeepsTheNegativePid(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->expects(self::once())->method('assertInsertAfterExists')->with('tt_content', 12);

        $tool = new WriteTableTool(
            $this->createMock(DataHandlerService::class),
            $records,
            new TcaSchemaService(),
            $this->createMock(RecordsApplyService::class),
        );

        $plan = $tool->plan([
            'action' => 'create',
            'tableName' => 'tt_content',
            'data' => ['pid' => -12, 'header' => 'After'],
        ]);

        self::assertSame(-12, $plan->context['pid']);
    }

    #[Test]
    public function planCreateAfterAPageTheEditorCannotReadIsRefused(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->willReturn(null);
        $access = $this->createMock(PageAccessService::class);
        $access->method('isUnrestricted')->willReturn(false);

        $tool = new WriteTableTool(
            $this->createMock(DataHandlerService::class),
            $records,
            new TcaSchemaService(),
            $this->createMock(RecordsApplyService::class),
            $access,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(PageAccessService::ACCESS_DENIED_MESSAGE);
        $tool->plan([
            'action' => 'create',
            'tableName' => 'tt_content',
            'data' => ['pid' => -12, 'header' => 'After'],
        ]);
    }
}
