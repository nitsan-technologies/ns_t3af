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

use NITSAN\NsT3AF\Access\T3AiPermissionResolver;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class T3AiPermissionResolverTest extends TestCase
{
    private T3AiPermissionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new T3AiPermissionResolver();
    }

    public function testManageBitGrantsBaseFeatureCheck(): void
    {
        $user = $this->user('T3Ai:Translation.Manage');

        self::assertTrue($this->resolver->hasFeature($user, 'Translation'));
    }

    public function testUseBitDoesNotImplyManage(): void
    {
        $user = $this->user('T3Ai:Translation');

        self::assertTrue($this->resolver->hasFeature($user, 'Translation'));
    }

    private function user(string $customOptions): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->groupData = ['custom_options' => $customOptions];
        $user->method('check')->willReturnCallback(
            static fn(string $type, string $value): bool => $type === 'custom_options'
                && str_contains($customOptions, $value),
        );

        return $user;
    }
}
