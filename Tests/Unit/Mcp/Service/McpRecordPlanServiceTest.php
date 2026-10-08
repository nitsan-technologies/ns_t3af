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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\McpRecordPlanService;
use NITSAN\NsT3AF\Mcp\Service\PageAccessService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * @internal
 */
final class McpRecordPlanServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function planCreateIsRefusedForATableTheEditorMayNotModify(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')->with('tables_modify', 'sys_category')->willReturn(false);
        $GLOBALS['BE_USER'] = $user;

        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->expects(self::never())->method('getWritableFields');

        $service = new McpRecordPlanService($this->createMock(RecordService::class), $tcaSchemaService);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not allowed');
        $service->planCreate('sys_category', ['pid' => 0, 'title' => 'RT category'], 'write_table');
    }

    #[Test]
    public function planCreateAllowsTheRootForATableTheEditorMayModify(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')->willReturn(true);
        $GLOBALS['BE_USER'] = $user;

        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->method('getWritableFields')->willReturn(['title']);

        $service = new McpRecordPlanService($this->createMock(RecordService::class), $tcaSchemaService);
        $plan = $service->planCreate('sys_category', ['pid' => 0, 'title' => 'RT category'], 'write_table');

        self::assertSame('create', $plan->action);
        self::assertSame(0, $plan->context['pid']);
    }

    #[Test]
    public function planUpdateBuildsBeforeAfterFields(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([42]);
        $recordService->method('findByUid')->willReturn(['description' => 'Old meta']);

        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->method('getWritableFields')->willReturn(['description']);

        $service = new McpRecordPlanService($recordService, $tcaSchemaService);
        $plan = $service->planUpdate('pages', 42, ['description' => 'New meta'], 'write_table');

        self::assertSame('update', $plan->action);
        self::assertSame('write_table', $plan->toolName);
        self::assertCount(1, $plan->fields);
        self::assertSame('pages:42:description', $plan->fields[0]->key);
        self::assertSame('Old meta', $plan->fields[0]->currentValue);
        self::assertSame('New meta', $plan->fields[0]->proposedValue);
    }

    #[Test]
    public function planUpdateWithNoWritableFieldsExplainsInsteadOfLoadingTheRecord(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([480]);
        $recordService->expects(self::never())->method('findByUid');

        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->method('getWritableFields')->willReturn(['header']);
        $tcaSchemaService->method('describeIgnoredField')->with('tt_content', 'assets')->willReturn([
            'field' => 'assets',
            'reason' => 'file_field',
            'hint' => 'Use file_reference_add.',
        ]);

        $service = new McpRecordPlanService($recordService, $tcaSchemaService);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Use file_reference_add');
        $service->planUpdate('tt_content', 480, ['assets' => [['uid_local' => 93]]], 'write_table');
    }

    #[Test]
    public function planCreateOnPageOutsideTheEditorsAccessIsRefused(): void
    {
        $access = $this->createMock(PageAccessService::class);
        $access->method('isUnrestricted')->willReturn(false);
        $access->method('canReadPage')->with(225)->willReturn(false);

        $service = new McpRecordPlanService(
            $this->createMock(RecordService::class),
            $this->createMock(TcaSchemaService::class),
            $access,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(PageAccessService::ACCESS_DENIED_MESSAGE);
        $service->planCreate('tt_content', ['pid' => 225, 'header' => 'Hi'], 'write_table');
    }

    #[Test]
    public function planCreateOnReadablePageIsAllowed(): void
    {
        $access = $this->createMock(PageAccessService::class);
        $access->method('isUnrestricted')->willReturn(false);
        $access->method('canReadPage')->with(224)->willReturn(true);

        $tca = $this->createMock(TcaSchemaService::class);
        $tca->method('getWritableFields')->willReturn(['header']);

        $service = new McpRecordPlanService($this->createMock(RecordService::class), $tca, $access);
        $plan = $service->planCreate('tt_content', ['pid' => 224, 'header' => 'Hi'], 'write_table');

        self::assertSame('create', $plan->action);
    }

    #[Test]
    public function planCreateUnderADeletedParentIsRefusedForAnAdmin(): void
    {
        $access = $this->createMock(PageAccessService::class);
        $access->method('isUnrestricted')->willReturn(true);

        $records = $this->createMock(RecordService::class);
        $records->expects(self::once())
            ->method('assertParentPageExists')
            ->with(64)
            ->willThrowException(new \InvalidArgumentException(
                'Parent page 64 does not exist or was deleted. Choose another page.',
            ));

        $service = new McpRecordPlanService($records, $this->createMock(TcaSchemaService::class), $access);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('was deleted');
        $service->planCreate('pages', ['pid' => 64, 'title' => 'Agent Test Child'], 'write_table');
    }

    #[Test]
    public function planMoveOntoADeletedPageIsRefused(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->method('findExistingUids')->willReturn([20]);
        $records->expects(self::once())
            ->method('assertParentPageExists')
            ->with(64)
            ->willThrowException(new \InvalidArgumentException(
                'Parent page 64 does not exist or was deleted. Choose another page.',
            ));

        $service = new McpRecordPlanService($records, $this->createMock(TcaSchemaService::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('was deleted');
        $service->planMove('tt_content', 20, 64, 'content_move');
    }

    #[Test]
    public function planCreateAfterADeletedPageIsRefused(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->expects(self::once())
            ->method('assertInsertAfterExists')
            ->with('pages', 68)
            ->willThrowException(new \InvalidArgumentException(
                'Page 68 does not exist or was deleted. Choose another page.',
            ));

        $service = new McpRecordPlanService($records, $this->createMock(TcaSchemaService::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('was deleted');
        $service->planCreate('pages', ['pid' => -68, 'title' => 'Page between 1 and 2'], 'write_table');
    }

    #[Test]
    public function planCreateAfterAnExistingPageKeepsTheNegativePid(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->expects(self::once())->method('assertInsertAfterExists')->with('pages', 68);

        $tca = $this->createMock(TcaSchemaService::class);
        $tca->method('getWritableFields')->willReturn(['title']);

        $service = new McpRecordPlanService($records, $tca);
        $plan = $service->planCreate('pages', ['pid' => -68, 'title' => 'Page between 1 and 2'], 'write_table');

        self::assertSame(-68, $plan->context['pid']);
        self::assertSame('create', $plan->action);
    }
}
