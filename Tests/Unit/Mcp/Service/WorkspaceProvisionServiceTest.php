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

use NITSAN\NsT3AF\Mcp\Service\WorkspaceProvisionService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class WorkspaceProvisionServiceTest extends TestCase
{
    #[Test]
    public function nonAdminWithoutWorkspacesModuleAccessCannotCreateWorkspaces(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->with('modules', 'web_WorkspacesWorkspaces')->willReturn(false);

        $service = new WorkspaceProvisionService(
            new \TYPO3\CMS\Core\Database\ConnectionPool(),
        );

        self::assertFalse($service->canUserCreateWorkspaces($backendUser));
    }

    #[Test]
    public function adminCanCreateWorkspaces(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);

        $service = new WorkspaceProvisionService(
            new \TYPO3\CMS\Core\Database\ConnectionPool(),
        );

        self::assertTrue($service->canUserCreateWorkspaces($backendUser));
    }
}
