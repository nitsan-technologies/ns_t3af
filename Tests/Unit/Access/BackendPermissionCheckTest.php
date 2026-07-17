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

use NITSAN\NsT3AF\Access\BackendPermissionCheck;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class BackendPermissionCheckTest extends TestCase
{
    public function testIsGrantedUsesBooleanCheckResult(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('check')->willReturnMap([
            ['tables_modify', 'tx_nst3af_provider', true],
            ['tables_modify', 'tx_other', false],
        ]);

        self::assertTrue(BackendPermissionCheck::isGranted($user, 'tables_modify', 'tx_nst3af_provider'));
        self::assertFalse(BackendPermissionCheck::isGranted($user, 'tables_modify', 'tx_other'));
    }
}
