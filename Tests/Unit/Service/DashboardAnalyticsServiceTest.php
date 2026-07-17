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

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Service\DashboardAnalyticsService;
use NITSAN\NsT3AF\Service\DashboardStatisticsCache;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class DashboardAnalyticsServiceTest extends TestCase
{
    private function createService(
        ProviderRepositoryInterface $providers,
        ?DashboardStatisticsCache $statisticsCache = null,
    ): DashboardAnalyticsService {
        return new DashboardAnalyticsService(
            new RequestLogRepository($this->createMock(ConnectionPool::class)),
            $providers,
            $this->createMock(ConnectionPool::class),
            $statisticsCache ?? new DashboardStatisticsCache($this->createMock(\NITSAN\NsT3AF\Cache\CacheFacadeInterface::class)),
        );
    }

    public function testResolveOwnKeysProviderUidsReturnsSiteProviderUids(): void
    {
        $openAi = Provider::fromRow([
            'uid' => 5,
            'pid' => 68,
            'identifier' => 'openai',
            'title' => 'OpenAI',
            'is_enabled' => 1,
        ]);
        $credits = Provider::fromRow([
            'uid' => 6,
            'pid' => 68,
            'identifier' => 't3planet_credits',
            'title' => 'Credits',
            'is_enabled' => 1,
        ]);

        $providers = $this->createMock(ProviderRepositoryInterface::class);
        $providers->method('findAllByStoragePid')->with(68, true)->willReturn([$openAi, $credits]);

        $service = $this->createService($providers);

        self::assertSame([5], $service->resolveOwnKeysProviderUids(68));
    }

    public function testResolveOwnKeysProviderUidsReturnsEmptyListWhenSiteHasNoOwnKeysProviders(): void
    {
        $providers = $this->createMock(ProviderRepositoryInterface::class);
        $providers->method('findAllByStoragePid')->with(99, true)->willReturn([]);

        $service = $this->createService($providers);

        self::assertSame([], $service->resolveOwnKeysProviderUids(99));
    }

    public function testResolveOwnKeysProviderUidsReturnsNullWhenStoragePidIsZero(): void
    {
        $providers = $this->createMock(ProviderRepositoryInterface::class);
        $providers->expects(self::never())->method('findAllByStoragePid');

        $service = $this->createService($providers);

        self::assertNull($service->resolveOwnKeysProviderUids(0));
    }
}
