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
use NITSAN\NsT3AF\Mcp\Tool\File\DirectoryRenameTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DirectoryRenameToolTest extends TestCase
{
    #[Test]
    public function missingFolderAndNameAreRejectedBeforeAPlan(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::never())->method('directoryExists');
        $tool = new DirectoryRenameTool($fileService, new McpFalPlanBuilder($fileService));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Which folder should be renamed');
        $tool->plan([]);
    }

    #[Test]
    public function folderWithoutANewNameIsRejected(): void
    {
        $tool = $this->tool();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('What should the new name');
        $tool->checkArguments(['directoryIdentifier' => '/user_upload/reports/']);
    }

    #[Test]
    public function newNameWithoutAFolderIsRejected(): void
    {
        $tool = $this->tool();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Which folder should be renamed to');
        $tool->checkArguments(['newName' => 'archive']);
    }

    #[Test]
    public function newNameWithASlashIsRejected(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::never())->method('directoryExists');
        $tool = new DirectoryRenameTool($fileService, new McpFalPlanBuilder($fileService));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('without a slash');
        $tool->checkArguments([
            'directoryIdentifier' => '/user_upload/reports/',
            'newName' => 'nested/archive',
        ]);
    }

    #[Test]
    public function missingFolderIsRejected(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->method('directoryExists')->willReturn(false);
        $tool = new DirectoryRenameTool($fileService, new McpFalPlanBuilder($fileService));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That folder was not found: /user_upload/missing/');
        $tool->checkArguments([
            'directoryIdentifier' => '/user_upload/missing/',
            'newName' => 'archive',
        ]);
    }

    #[Test]
    public function completeArgumentsPlanTheFolderPath(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->method('directoryExists')->willReturn(true);
        $tool = new DirectoryRenameTool($fileService, new McpFalPlanBuilder($fileService));

        $plan = $tool->plan([
            'directoryIdentifier' => '/user_upload/reports/',
            'newName' => 'archive',
        ]);

        self::assertSame('directory_rename', $plan->toolName);
        self::assertSame('/user_upload/reports/', $plan->fields[0]->currentValue);
        self::assertSame('rename to archive', $plan->fields[0]->proposedValue);
        self::assertSame('_rename', $plan->fields[0]->field);
    }

    private function tool(): DirectoryRenameTool
    {
        $fileService = $this->createMock(FileService::class);

        return new DirectoryRenameTool($fileService, new McpFalPlanBuilder($fileService));
    }
}
