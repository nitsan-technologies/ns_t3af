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

use NITSAN\NsT3AF\Mcp\Service\FileService;
use NITSAN\NsT3AF\Mcp\Service\McpFalPlanBuilder;
use NITSAN\NsT3AF\Mcp\Tool\File\FileRenameTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FileRenameToolTest extends TestCase
{
    #[Test]
    public function missingFileAndNameAreRejectedBeforeAPlan(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::never())->method('getFileInfo');
        $tool = new FileRenameTool($fileService, new McpFalPlanBuilder($fileService));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Which file should be renamed');
        $tool->plan([]);
    }

    #[Test]
    public function fileWithoutANewNameIsRejected(): void
    {
        $tool = $this->tool();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('What should the new name');
        $tool->checkArguments(['fileIdentifier' => '/user_upload/test.txt']);
    }

    #[Test]
    public function newNameWithoutAFileIsRejected(): void
    {
        $tool = $this->tool();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Which file should be renamed to');
        $tool->checkArguments(['newName' => 'renamed.txt']);
    }

    #[Test]
    public function newNameWithASlashIsRejected(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::never())->method('getFileInfo');
        $tool = new FileRenameTool($fileService, new McpFalPlanBuilder($fileService));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('without a slash');
        $tool->checkArguments([
            'fileIdentifier' => '/user_upload/test.txt',
            'newName' => 'nested/name.txt',
        ]);
    }

    #[Test]
    public function missingFileIsRejected(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->method('getFileInfo')->willThrowException(new \RuntimeException('File not found: /missing.txt', 1712002001));
        $tool = new FileRenameTool($fileService, new McpFalPlanBuilder($fileService));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That file was not found: /missing.txt');
        $tool->checkArguments([
            'fileIdentifier' => '/missing.txt',
            'newName' => 'renamed.txt',
        ]);
    }

    #[Test]
    public function completeArgumentsPlanTheRealFile(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->method('getFileInfo')->willReturn([
            'uid' => 12,
            'name' => 'test.txt',
            'identifier' => '/user_upload/test.txt',
        ]);
        $tool = new FileRenameTool($fileService, new McpFalPlanBuilder($fileService));

        $plan = $tool->plan([
            'fileIdentifier' => '/user_upload/test.txt',
            'newName' => 'renamed.txt',
        ]);

        self::assertSame('file_rename', $plan->toolName);
        self::assertSame(12, $plan->fields[0]->uid);
        self::assertSame('/user_upload/test.txt', $plan->fields[0]->currentValue);
        self::assertSame('rename to renamed.txt', $plan->fields[0]->proposedValue);
        self::assertSame('_rename', $plan->fields[0]->field);
    }

    private function tool(): FileRenameTool
    {
        $fileService = $this->createMock(FileService::class);

        return new FileRenameTool($fileService, new McpFalPlanBuilder($fileService));
    }
}
