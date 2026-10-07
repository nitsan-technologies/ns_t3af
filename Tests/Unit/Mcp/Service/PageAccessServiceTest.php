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

use NITSAN\NsT3AF\Mcp\Service\PageAccessService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * @internal
 */
final class PageAccessServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function adminMayReadEveryPageAndRecord(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(true);
        $service = new PageAccessService();

        self::assertTrue($service->isUnrestricted());
        self::assertTrue($service->canReadPage(99));
        self::assertTrue($service->canReadRecord('tt_content', ['pid' => 99]));
        self::assertSame([5, 6], $service->filterAllowedAnchors('pages', [5, 6]));
    }

    #[Test]
    public function withoutBackendUserNothingIsReadable(): void
    {
        unset($GLOBALS['BE_USER']);
        $service = new PageAccessService();

        self::assertFalse($service->isUnrestricted());
        self::assertFalse($service->canReadPage(1));
        self::assertFalse($service->canReadRecord('pages', ['uid' => 1]));
    }

    #[Test]
    public function nonAdminCannotReadRootOrNegativePage(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(false);
        $service = new PageAccessService();

        self::assertFalse($service->canReadPage(0));
        self::assertFalse($service->canReadPage(-1));
    }

    #[Test]
    public function anchorColumnIsUidForPagesAndPidForOtherTables(): void
    {
        $service = new PageAccessService();

        self::assertSame('uid', $service->anchorColumn('pages'));
        self::assertSame('pid', $service->anchorColumn('tt_content'));
    }

    #[Test]
    public function rootLevelRecordsOfNonPageTablesAreNotPageScoped(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(false);
        $service = new PageAccessService();

        self::assertTrue($service->canReadRecord('sys_redirect', ['pid' => 0]));
        self::assertFalse($service->canReadRecord('pages', ['uid' => 0]));
    }

    private function backendUser(bool $admin): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($admin);

        return $user;
    }
}
