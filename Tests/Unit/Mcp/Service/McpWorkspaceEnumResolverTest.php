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

use NITSAN\NsT3AF\Mcp\Service\McpWorkspaceEnumResolver;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class McpWorkspaceEnumResolverTest extends TestCase
{
    #[Test]
    public function theHelpNamesTheDefaultAndListsOnlyDraftWorkspaces(): void
    {
        $list = $this->createMock(WorkspaceListService::class);
        $list->method('list')->willReturn([['uid' => 0, 'title' => 'Live'], ['uid' => 2, 'title' => 'QA Draft WS']]);

        $description = (new McpWorkspaceEnumResolver($list))->buildDescription();

        self::assertStringContainsString('Leave it out to use the workspace selected in the TYPO3 MCP Server backend module', $description);
        self::assertStringContainsString('Draft workspaces: 2 = QA Draft WS.', $description);
        self::assertStringNotContainsString('do not pass 0', $description);
        self::assertStringNotContainsString('0 = Live', $description);
    }
}
