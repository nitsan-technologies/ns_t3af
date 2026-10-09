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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Authentication;

use NITSAN\NsT3AF\Mcp\Authentication\BackendUserBootstrap;
use NITSAN\NsT3AF\Tests\Unit\Access\Support\LoadedExtensionsTestTrait;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final class BackendUserBootstrapTest extends TestCase
{
    use LoadedExtensionsTestTrait;

    protected function tearDown(): void
    {
        $this->resetLoadedExtensions();
        parent::tearDown();
    }

    public function testApplyWorkspaceSwitchesTemporarilyAndNeverPersists(): void
    {
        $this->mockLoadedExtensions(['workspaces']);
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->expects(self::once())->method('setTemporaryWorkspace')->with(3)->willReturn(true);
        $user->expects(self::never())->method('setWorkspace');

        $this->subject()->applyWorkspace($user, 3);
    }

    public function testApplyWorkspaceFallsBackToLiveWhenWorkspaceIsNotAllowed(): void
    {
        $this->mockLoadedExtensions(['workspaces']);
        $user = $this->createMock(BackendUserAuthentication::class);
        $calls = [];
        $user->method('setTemporaryWorkspace')->willReturnCallback(static function (int $id) use (&$calls): bool {
            $calls[] = $id;

            return $id === 0;
        });
        $user->expects(self::never())->method('setWorkspace');

        $this->subject()->applyWorkspace($user, 9);

        self::assertSame([9, 0], $calls);
    }

    public function testApplyWorkspaceUsesCoreDefaultOnlyWhenLiveIsRefusedToo(): void
    {
        $this->mockLoadedExtensions(['workspaces']);
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('setTemporaryWorkspace')->willReturn(false);
        $user->expects(self::once())->method('setWorkspace')->with(9);

        $this->subject()->applyWorkspace($user, 9);
    }

    public function testApplyWorkspaceDoesNothingWithoutWorkspacesExtension(): void
    {
        $this->mockLoadedExtensions([]);
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->expects(self::never())->method('setTemporaryWorkspace');
        $user->expects(self::never())->method('setWorkspace');

        $this->subject()->applyWorkspace($user, 3);
    }

    private function subject(): BackendUserBootstrap
    {
        return new BackendUserBootstrap(
            $this->createMock(ConnectionPool::class),
            $this->createMock(LanguageServiceFactory::class),
        );
    }
}
