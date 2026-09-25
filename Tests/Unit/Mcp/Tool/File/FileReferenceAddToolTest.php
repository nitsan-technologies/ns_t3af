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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\File;

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\McpConfirmationPlanBuilder;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\File\FileReferenceAddTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FileReferenceAddToolTest extends TestCase
{
    /**
     * @param array<string, mixed> $row
     */
    private function record(array $row = ['uid' => 10, 'pid' => 1, 'deleted' => 0]): RecordService
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findByUid')->willReturn($row);

        return $recordService;
    }

    #[Test]
    public function executeRejectsInvalidFileUids(): void
    {
        $tool = new FileReferenceAddTool(
            $this->createMock(DataHandlerService::class),
            $this->createMock(TcaSchemaService::class),
            new McpConfirmationPlanBuilder(),
            $this->record(),
        );

        $result = json_decode($tool->execute('tt_content', 1, 'image', '0,abc'), true);

        self::assertIsArray($result);
        self::assertSame('No valid file UIDs provided', $result['error']);
    }

    #[Test]
    public function executeRejectsNonFileField(): void
    {
        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->method('getFileFields')->with('tt_content')->willReturn(['image', 'assets']);

        $tool = new FileReferenceAddTool(
            $this->createMock(DataHandlerService::class),
            $tcaSchemaService,
            new McpConfirmationPlanBuilder(),
            $this->record(),
        );

        $result = json_decode($tool->execute('tt_content', 1, 'header', '42'), true);

        self::assertIsArray($result);
        self::assertStringContainsString('not a file field', (string) ($result['error'] ?? ''));
        self::assertSame(['image', 'assets'], $result['availableFileFields']);
    }

    #[Test]
    public function executeCreatesFileReferences(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService
            ->expects(self::once())
            ->method('createFileReferences')
            ->with('tt_content', 10, 'image', [42, 43], 1)
            ->willReturn([501, 502]);

        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->method('getFileFields')->with('tt_content')->willReturn(['image']);

        $tool = new FileReferenceAddTool(
            $dataHandlerService,
            $tcaSchemaService,
            new McpConfirmationPlanBuilder(),
            $this->record(['uid' => 10, 'pid' => 1, 'deleted' => 0]),
        );

        $result = json_decode($tool->execute('tt_content', 10, 'image', '42, 43'), true);

        self::assertIsArray($result);
        self::assertSame('tt_content', $result['table']);
        self::assertSame(10, $result['uid']);
        self::assertSame('image', $result['fieldName']);
        self::assertSame(2, $result['referencesCreated']);
        self::assertSame([501, 502], $result['referenceUids']);
    }

    #[Test]
    public function executeRejectsMissingOrDeletedRecord(): void
    {
        $GLOBALS['TCA']['tt_content']['ctrl']['delete'] = 'deleted';
        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->method('getFileFields')->willReturn(['image']);
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 128, 'pid' => 6, 'deleted' => 1]);

        $tool = new FileReferenceAddTool(
            $this->createMock(DataHandlerService::class),
            $tcaSchemaService,
            new McpConfirmationPlanBuilder(),
            $recordService,
        );

        $result = json_decode($tool->execute('tt_content', 128, 'image', '5'), true);

        self::assertIsArray($result);
        self::assertStringContainsString('missing or deleted', (string) ($result['error'] ?? ''));
    }

    #[Test]
    public function planRejectsARecordThatDoesNotExist(): void
    {
        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->method('getFileFields')->willReturn(['image', 'assets']);
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);
        $tool = new FileReferenceAddTool($this->createMock(DataHandlerService::class), $tcaSchemaService, new McpConfirmationPlanBuilder(), $recordService);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no tt_content record with uid 128');
        $tool->plan(['table' => 'tt_content', 'uid' => 128, 'fieldName' => 'image', 'fileUids' => '5']);
    }

    #[Test]
    public function planRejectsAFieldThatIsNoFileField(): void
    {
        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->method('getFileFields')->willReturn(['image', 'assets']);
        $tool = new FileReferenceAddTool($this->createMock(DataHandlerService::class), $tcaSchemaService, new McpConfirmationPlanBuilder(), $this->record());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File fields: image, assets');
        $tool->plan(['table' => 'tt_content', 'uid' => 7, 'fieldName' => '_reference', 'fileUids' => '5']);
    }

    #[Test]
    public function referencesAreStoredOnThePageOfTheirRecord(): void
    {
        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->method('getFileFields')->willReturn(['assets']);
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 812, 'pid' => 128, 'deleted' => 0]);
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())->method('createFileReferences')
            ->with('tt_content', 812, 'assets', [5], 128)
            ->willReturn([900]);
        $tool = new FileReferenceAddTool($dataHandlerService, $tcaSchemaService, new McpConfirmationPlanBuilder(), $recordService);

        $result = json_decode($tool->execute('tt_content', 812, 'assets', '5'), true);

        self::assertSame([900], $result['referenceUids'] ?? null);
    }
}
