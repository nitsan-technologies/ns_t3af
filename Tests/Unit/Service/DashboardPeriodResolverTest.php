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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Service\DashboardPeriodResolver;
use PHPUnit\Framework\TestCase;

final class DashboardPeriodResolverTest extends TestCase
{
    private int $previousExecTime;

    protected function setUp(): void
    {
        $this->previousExecTime = (int) ($GLOBALS['EXEC_TIME'] ?? 0);
        $GLOBALS['EXEC_TIME'] = strtotime('2026-07-28 12:00:00');
    }

    protected function tearDown(): void
    {
        if ($this->previousExecTime > 0) {
            $GLOBALS['EXEC_TIME'] = $this->previousExecTime;
        } else {
            unset($GLOBALS['EXEC_TIME']);
        }
    }

    public function testResolveFromQueryParamsUsesRollingWindowForSevenDays(): void
    {
        $resolver = new DashboardPeriodResolver();
        $period = $resolver->resolveFromQueryParams(['period' => '7d']);

        self::assertSame('7d', $period['preset']);
        self::assertSame(7, $period['days']);
        self::assertSame(strtotime('2026-07-21 12:00:00'), $period['fromTimestamp']);
        self::assertSame(strtotime('2026-07-28 12:00:00'), $period['toTimestamp']);
    }

    public function testResolveFromQueryParamsUsesCustomRange(): void
    {
        $resolver = new DashboardPeriodResolver();
        $period = $resolver->resolveFromQueryParams([
            'period' => 'custom',
            'from' => '2026-07-01',
            'to' => '2026-07-15',
        ]);

        self::assertSame('custom', $period['preset']);
        self::assertSame(strtotime('2026-07-01 00:00:00'), $period['fromTimestamp']);
        self::assertSame(strtotime('2026-07-15 23:59:59'), $period['toTimestamp']);
        self::assertSame(15, $period['days']);
    }

    public function testResolveFromQueryParamsNormalizesUnknownPreset(): void
    {
        $resolver = new DashboardPeriodResolver();
        $period = $resolver->resolveFromQueryParams(['period' => 'invalid']);

        self::assertSame('30d', $period['preset']);
        self::assertSame(30, $period['days']);
    }

    public function testResolveFromQueryParamsDefaultsToThirtyDays(): void
    {
        $resolver = new DashboardPeriodResolver();
        $period = $resolver->resolveFromQueryParams([]);

        self::assertSame('30d', $period['preset']);
        self::assertSame(30, $period['days']);
        self::assertSame(strtotime('2026-06-28 12:00:00'), $period['fromTimestamp']);
        self::assertSame(strtotime('2026-07-28 12:00:00'), $period['toTimestamp']);
    }
}
