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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\File;

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\File\FileReferenceAddTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FileReferenceAddToolTest extends TestCase
{
    #[Test]
    public function executeRejectsInvalidFileUids(): void
    {
        $tool = new FileReferenceAddTool(
            $this->createMock(DataHandlerService::class),
            $this->createMock(TcaSchemaService::class),
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
            ->with('tt_content', 10, 'image', [42, 43])
            ->willReturn([501, 502]);

        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->method('getFileFields')->with('tt_content')->willReturn(['image']);

        $tool = new FileReferenceAddTool($dataHandlerService, $tcaSchemaService);

        $result = json_decode($tool->execute('tt_content', 10, 'image', '42, 43'), true);

        self::assertIsArray($result);
        self::assertSame('tt_content', $result['table']);
        self::assertSame(10, $result['uid']);
        self::assertSame('image', $result['fieldName']);
        self::assertSame(2, $result['referencesCreated']);
        self::assertSame([501, 502], $result['referenceUids']);
    }
}
