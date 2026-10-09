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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service\RecordsApply;

use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyMoveCommandChainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordsApplyMoveCommandChainerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA']['tt_content']['ctrl']['sortby'] = 'sorting';
        $GLOBALS['TCA']['pages']['ctrl']['sortby'] = 'sorting';
        $GLOBALS['TCA']['tx_nst3af_no_sort']['ctrl'] = [];
    }

    #[Test]
    public function aPositivePidChainsAfterThePreviousUid(): void
    {
        self::assertSame(
            [
                10 => ['move' => 5],
                20 => ['move' => -10],
                30 => ['move' => -20],
            ],
            RecordsApplyMoveCommandChainer::chain('tt_content', [10, 20, 30], 5),
        );
    }

    #[Test]
    public function aNegativeTargetKeepsTheAnchorThenChains(): void
    {
        self::assertSame(
            [
                10 => ['move' => -7],
                20 => ['move' => -10],
                30 => ['move' => -20],
            ],
            RecordsApplyMoveCommandChainer::chain('tt_content', [10, 20, 30], -7),
        );
    }

    #[Test]
    public function aOneRecordMoveIsUnchanged(): void
    {
        self::assertSame(
            [10 => ['move' => 5]],
            RecordsApplyMoveCommandChainer::chain('tt_content', [10], 5),
        );
        self::assertSame(
            [10 => ['move' => -7]],
            RecordsApplyMoveCommandChainer::chain('tt_content', [10], -7),
        );
    }

    #[Test]
    public function pagesChainTheSameWay(): void
    {
        self::assertSame(
            [
                2 => ['move' => 1],
                3 => ['move' => -2],
            ],
            RecordsApplyMoveCommandChainer::chain('pages', [2, 3], 1),
        );
    }

    #[Test]
    public function tablesWithoutSortbyKeepTheSameTargetForEveryUid(): void
    {
        self::assertSame(
            [
                10 => ['move' => 5],
                20 => ['move' => 5],
            ],
            RecordsApplyMoveCommandChainer::chain('tx_nst3af_no_sort', [10, 20], 5),
        );
    }

    #[Test]
    public function tableSupportsSortingFollowsCtrlSortby(): void
    {
        self::assertTrue(RecordsApplyMoveCommandChainer::tableSupportsSorting('tt_content'));
        self::assertFalse(RecordsApplyMoveCommandChainer::tableSupportsSorting('tx_nst3af_no_sort'));
    }
}
