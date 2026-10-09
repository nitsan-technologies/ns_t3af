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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Search;

use NITSAN\NsT3AF\Mcp\Tool\Search\RecordSearchTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RecordSearchToolPidTest extends TestCase
{
    #[Test]
    #[DataProvider('resolveSearchPidCases')]
    public function resolveSearchPidTreatsZeroAndListAllAsNoFilter(int $pid, bool $listAll, ?int $expected): void
    {
        self::assertSame($expected, RecordSearchTool::resolveSearchPid($pid, $listAll));
    }

    /**
     * @return iterable<string, array{int, bool, int|null}>
     */
    public static function resolveSearchPidCases(): iterable
    {
        yield 'default -1' => [-1, false, null];
        yield 'model sent 0' => [0, false, null];
        yield 'explicit storage' => [173, false, 173];
        yield 'list all ignores storage pid' => [224, true, null];
        yield 'list all with 0' => [0, true, null];
    }
}
