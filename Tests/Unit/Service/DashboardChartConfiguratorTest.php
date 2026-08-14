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

use NITSAN\NsT3AF\Service\DashboardChartConfigurator;
use PHPUnit\Framework\TestCase;

final class DashboardChartConfiguratorTest extends TestCase
{
    public function testRequestsSuccessFailChartZeroFillsMissingDays(): void
    {
        $configurator = new DashboardChartConfigurator();
        $from = strtotime('2026-07-01 00:00:00');
        $to = strtotime('2026-07-03 23:59:59');

        $json = $configurator->requestsSuccessFailChart(
            [
                ['day' => '2026-07-02', 'success' => 2, 'failed' => 1],
            ],
            'Success',
            'Failed',
            $from,
            $to,
        );
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['2026-07-01', '2026-07-02', '2026-07-03'], $decoded['data']['labels']);
        self::assertSame([0, 2, 0], $decoded['data']['datasets'][0]['data']);
        self::assertSame([0, 1, 0], $decoded['data']['datasets'][1]['data']);
    }

    public function testCreditsBurnChartZeroFillsEmptyPeriod(): void
    {
        $configurator = new DashboardChartConfigurator();
        $from = strtotime('2026-07-01 12:00:00');
        $to = strtotime('2026-07-02 12:00:00');

        $json = $configurator->creditsBurnChart([], 'Credits', $from, $to);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['2026-07-01', '2026-07-02'], $decoded['data']['labels']);
        self::assertSame([0, 0], $decoded['data']['datasets'][0]['data']);
    }

    public function testCostTrendMultiLineChartPadsDaysForProviders(): void
    {
        $configurator = new DashboardChartConfigurator();
        $from = strtotime('2026-07-01 00:00:00');
        $to = strtotime('2026-07-03 00:00:00');

        $json = $configurator->costTrendMultiLineChart(
            [
                ['day' => '2026-07-01', 'provider' => 'openai', 'cost' => 1.5],
                ['day' => '2026-07-03', 'provider' => 'openai', 'cost' => 0.5],
            ],
            $from,
            $to,
        );
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['2026-07-01', '2026-07-02', '2026-07-03'], $decoded['data']['labels']);
        self::assertSame([1.5, 0, 0.5], $decoded['data']['datasets'][0]['data']);
    }
}
