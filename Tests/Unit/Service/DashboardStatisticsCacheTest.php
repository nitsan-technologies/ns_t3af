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

use NITSAN\NsT3AF\Cache\CacheFacadeInterface;
use NITSAN\NsT3AF\Domain\RequestLog\RequestLogProviderScope;
use NITSAN\NsT3AF\Service\DashboardStatisticsCache;
use PHPUnit\Framework\TestCase;

final class DashboardStatisticsCacheTest extends TestCase
{
    public function testSetAnalyticsUsesFifteenMinuteTtl(): void
    {
        $cache = $this->createMock(CacheFacadeInterface::class);
        $cache->expects(self::once())
            ->method('set')
            ->with(
                self::stringStartsWith('analytics_'),
                self::isType('array'),
                ['nst3af_dashboard'],
                DashboardStatisticsCache::TTL_SECONDS,
            );

        $service = new DashboardStatisticsCache($cache);
        $service->setAnalytics(
            [
                'fromTimestamp' => 100,
                'toTimestamp' => 200,
                'days' => 7,
                'preset' => '7d',
            ],
            RequestLogProviderScope::OwnKeys,
            68,
            ['totals' => ['totalRequests' => 1]],
        );
    }

    public function testGetAnalyticsReturnsNullOnMiss(): void
    {
        $cache = $this->createMock(CacheFacadeInterface::class);
        $cache->method('get')->willReturn(false);

        $service = new DashboardStatisticsCache($cache);

        self::assertNull($service->getAnalytics(
            ['fromTimestamp' => 1, 'toTimestamp' => 2, 'days' => 1],
            RequestLogProviderScope::Credits,
            0,
        ));
    }
}
