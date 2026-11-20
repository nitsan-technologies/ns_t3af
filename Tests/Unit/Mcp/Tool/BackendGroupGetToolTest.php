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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool;

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Service\PermissionService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Tool\BackendGroup\BackendGroupGetTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BackendGroupGetTool::class)]
final class BackendGroupGetToolTest extends TestCase
{
    #[Test]
    public function uidZeroIsRefused(): void
    {
        $tool = new BackendGroupGetTool(
            $this->createMock(RecordService::class),
            $this->adminPermissions(),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('positive be_groups uid');
        $tool->execute(0);
    }

    #[Test]
    public function missingGroupFailsHard(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->willReturn(null);
        $tool = new BackendGroupGetTool($records, $this->adminPermissions());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Backend group not found');
        $tool->execute(99);
    }

    private function adminPermissions(): PermissionService
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('isAdmin')->willReturn(true);

        return $permissions;
    }
}
