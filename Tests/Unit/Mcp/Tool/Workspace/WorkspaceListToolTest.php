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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Workspace;

use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use NITSAN\NsT3AF\Mcp\Tool\Workspace\WorkspaceListTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class WorkspaceListToolTest extends TestCase
{
    #[Test]
    public function listsLiveAndDraftWorkspaces(): void
    {
        $workspaceListService = $this->createMock(WorkspaceListService::class);
        $workspaceListService->method('list')->willReturn([
            ['uid' => 0, 'title' => 'Live'],
            ['uid' => 1, 'title' => 'Test'],
        ]);
        $workspaceListService->method('isWorkspacesExtensionLoaded')->willReturn(true);

        $tool = new WorkspaceListTool($workspaceListService);
        $result = json_decode($tool->execute(), true);

        self::assertIsArray($result['workspaces'] ?? null);
        self::assertCount(2, $result['workspaces']);
        self::assertSame(0, $result['workspaces'][0]['uid']);
        self::assertSame('Live', $result['workspaces'][0]['title']);
        self::assertSame(1, $result['workspaces'][1]['uid']);
        self::assertArrayHasKey('workspacesEnabled', $result);
    }
}
