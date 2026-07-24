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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use NITSAN\NsT3AF\Mcp\Service\WorkspacePreferenceService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
final class WorkspacePreferenceServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalBeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBeUser = $GLOBALS['BE_USER'] ?? [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['BE_USER'] = $this->originalBeUser;
        parent::tearDown();
    }

    #[Test]
    public function getForCurrentUserReturnsStoredPreferenceWhenWorkspaceExists(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->uc = [WorkspacePreferenceService::UC_KEY => 3];
        $backendUser->user = ['uid' => 1];
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new WorkspacePreferenceService(
            $this->createMock(ConnectionPool::class),
            $this->workspaceList([
                ['uid' => 0, 'title' => 'Live'],
                ['uid' => 3, 'title' => 'MCP Workspace for admin'],
            ]),
        );

        self::assertSame(3, $service->getForCurrentUser());
    }

    #[Test]
    public function getForCurrentUserFallsBackToLiveForUnknownWorkspace(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->uc = [WorkspacePreferenceService::UC_KEY => 99];
        $backendUser->user = ['uid' => 1];
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new WorkspacePreferenceService(
            $this->createMock(ConnectionPool::class),
            $this->workspaceList([
                ['uid' => 0, 'title' => 'Live'],
                ['uid' => 3, 'title' => 'MCP Workspace for admin'],
            ]),
        );

        self::assertSame(0, $service->getForCurrentUser());
    }

    /** @param list<array{uid: int, title: string}> $workspaces */
    private function workspaceList(array $workspaces): WorkspaceListService
    {
        $service = $this->createMock(WorkspaceListService::class);
        $service->method('list')->willReturn($workspaces);

        return $service;
    }
}
