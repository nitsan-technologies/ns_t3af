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

use NITSAN\NsT3AF\Mcp\Service\PermissionService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * @internal
 */
final class PermissionServiceTableTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TCA']);
        parent::tearDown();
    }

    #[Test]
    public function checkTableAccessRejectsUnknownTable(): void
    {
        $GLOBALS['TCA'] = ['pages' => []];
        $GLOBALS['BE_USER'] = $this->createMock(BackendUserAuthentication::class);

        $service = new PermissionService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not registered');

        $service->checkTableAccess('not_a_real_table_xyz');
    }
}
