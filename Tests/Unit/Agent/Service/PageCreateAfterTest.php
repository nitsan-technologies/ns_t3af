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

namespace NITSAN\NsT3AF\Tests\Unit\Agent\Service;

use NITSAN\NsT3AF\Agent\Service\PageCreateAfter;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PageCreateAfterTest extends UnitTestCase
{
    #[Test]
    public function createAfterNamesThePage(): void
    {
        self::assertTrue(PageCreateAfter::isCreateAfter('Create a page after A'));
        self::assertSame(['title' => 'A', 'uid' => 113], PageCreateAfter::target('Create NA directly after A [113]'));
        self::assertSame(['title' => 'A', 'uid' => 0], PageCreateAfter::target('Create a page after A named U'));
        self::assertSame(['title' => 'A', 'uid' => 0], PageCreateAfter::target('Create a page after A called U'));
        self::assertFalse(PageCreateAfter::isCreateAfter('Create NB directly before C'));
        self::assertFalse(PageCreateAfter::isCreateAfter('Move C to directly after X'));
    }

    #[Test]
    public function lastUnderNamesTheParent(): void
    {
        self::assertTrue(PageCreateAfter::isLastUnder('Create a page as the last page under Home'));
        self::assertTrue(PageCreateAfter::isLastUnder('Create a page inside Home at the end'));
        self::assertSame(['title' => 'Home', 'uid' => 1], PageCreateAfter::lastUnderTarget('Create a page as the last page under Home [1]'));
        self::assertSame(['title' => 'Home', 'uid' => 0], PageCreateAfter::lastUnderTarget('Create a page inside Home at the end named Last'));
        self::assertFalse(PageCreateAfter::isLastUnder('Create a page after A'));
        self::assertFalse(PageCreateAfter::isCreateAfter('Create a page as the last page under Home'));
    }
}
