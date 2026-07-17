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

namespace NITSAN\NsT3AF\Tests\Unit\Access;

use NITSAN\NsT3AF\Access\ModuleTabAccessService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class ModuleTabAccessServiceTest extends TestCase
{
    private ModuleTabAccessService $service;

    protected function setUp(): void
    {
        $this->service = new ModuleTabAccessService();
    }

    public function testDashboardAlwaysVisible(): void
    {
        self::assertTrue($this->service->isTabVisible('dashboard', null));
        self::assertTrue($this->service->isTabVisible('dashboard', $this->user(isAdmin: false)));
    }

    public function testAccessRolesAdminOnly(): void
    {
        self::assertFalse($this->service->isTabVisible('aiAccessRoles', $this->user(isAdmin: false)));
        self::assertTrue($this->service->isTabVisible('aiAccessRoles', $this->user(isAdmin: true)));
    }

    public function testPermissiveWhenNoTabPermissionsConfigured(): void
    {
        $user = $this->user(isAdmin: false, customOptions: 'T3Ai:Content');

        self::assertTrue($this->service->isTabVisible('providers', $user));
        self::assertTrue($this->service->isTabVisible('aiPrompts', $user));
    }

    public function testGatedTabRequiresMatchingPermission(): void
    {
        $user = $this->user(isAdmin: false, customOptions: 'nst3af_tab:ai_prompts');

        self::assertTrue($this->service->isTabVisible('aiPrompts', $user));
        self::assertFalse($this->service->isTabVisible('providers', $user));
    }

    public function testAdminBypassesTabGating(): void
    {
        $user = $this->user(isAdmin: true, customOptions: 'nst3af_tab:ai_prompts');

        self::assertTrue($this->service->isTabVisible('providers', $user));
    }

    private function user(bool $isAdmin, string $customOptions = ''): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($isAdmin);
        $user->groupData = ['custom_options' => $customOptions];
        $user->method('check')->willReturnCallback(
            static fn(string $type, string $value): bool => $type === 'custom_options' && str_contains($customOptions, $value),
        );

        return $user;
    }
}
