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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Service\DashboardTrendMath;
use PHPUnit\Framework\TestCase;

final class DashboardTrendMathTest extends TestCase
{
    public function testMetricComputesPercentChangeAndDirection(): void
    {
        $trend = DashboardTrendMath::metric(100.0, 40.0, [10, 20, 30]);

        self::assertSame(150.0, $trend['changePercent']);
        self::assertSame('up', $trend['direction']);
        self::assertSame([10, 20, 30], $trend['sparkline']);
    }

    public function testMetricTreatsZeroPriorAsHundredPercentGrowth(): void
    {
        $trend = DashboardTrendMath::metric(5.0, 0.0);

        self::assertSame(100.0, $trend['changePercent']);
        self::assertSame('up', $trend['direction']);
    }

    public function testMetricIsNeutralWhenUnchanged(): void
    {
        $trend = DashboardTrendMath::metric(10.0, 10.0);

        self::assertSame(0.0, $trend['changePercent']);
        self::assertSame('neutral', $trend['direction']);
    }
}
