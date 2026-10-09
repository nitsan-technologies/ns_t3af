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

use NITSAN\NsT3AF\Mcp\Service\FileService;
use NITSAN\NsT3AF\Mcp\Service\McpFalPlanBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class McpFalPlanBuilderTest extends TestCase
{
    #[Test]
    public function aFileThatCannotBeFoundGetsNoCard(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->method('getFileInfo')->willThrowException(new \RuntimeException('File not found: logo.png', 1712002001));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found: logo.png');
        (new McpFalPlanBuilder($fileService))->filePathChange('copy', 'file_copy', '_copy', 1, 'logo.png', 'copy to /x/');
    }

    #[Test]
    public function aFoundFileGetsACardWithItsRealPath(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->method('getFileInfo')->willReturn(['uid' => 9, 'identifier' => '/user_upload/logo.png']);

        $plan = (new McpFalPlanBuilder($fileService))->filePathChange('copy', 'file_copy', '_copy', 1, 'logo.png', 'copy to /x/');

        self::assertSame(9, $plan->fields[0]->uid);
        self::assertSame('/user_upload/logo.png', $plan->fields[0]->currentValue);
    }
}
