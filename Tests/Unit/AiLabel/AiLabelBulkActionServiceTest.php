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

namespace NITSAN\NsT3AF\Tests\Unit\AiLabel;

use NITSAN\NsT3AF\AiLabel\Service\AiLabelBulkActionService;
use NITSAN\NsT3AF\AiLabel\Service\ConfirmationService;
use NITSAN\NsT3AF\AiLabel\Service\UndoCacheService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class AiLabelBulkActionServiceTest extends TestCase
{
    public function testEmptyRefsReturnsZeroProcessed(): void
    {
        $pool = $this->createMock(ConnectionPool::class);
        $cacheManager = $this->createMock(CacheManager::class);

        $service = new AiLabelBulkActionService(
            $pool,
            new ConfirmationService($pool, null),
            new UndoCacheService($cacheManager, $pool),
        );

        $result = $service->execute('confirm', [], 1);

        self::assertSame(0, $result['processed']);
    }

    public function testInvalidRefsAreSkipped(): void
    {
        $connection = $this->createMock(Connection::class);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $cacheManager = $this->createMock(CacheManager::class);

        $service = new AiLabelBulkActionService(
            $pool,
            new ConfirmationService($pool, null),
            new UndoCacheService($cacheManager, $pool),
        );

        $result = $service->execute('confirm', ['invalid', 'bad:0'], 1);

        self::assertSame(0, $result['processed']);
    }

    public function testUnknownActionDoesNotTouchDatabase(): void
    {
        $pool = $this->createMock(ConnectionPool::class);
        $pool->expects(self::never())->method('getConnectionForTable');
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects(self::never())->method('getCache');

        $service = new AiLabelBulkActionService(
            $pool,
            new ConfirmationService($pool, null),
            new UndoCacheService($cacheManager, $pool),
        );

        $result = $service->execute('not_a_real_action', ['sys_file_metadata:1'], 1);

        self::assertSame(0, $result['processed']);
    }

    public function testRememberBulkKeepsOnlyTheLastBatch(): void
    {
        $store = [];
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('set')->willReturnCallback(
            static function (string $key, mixed $data, array $tags = [], mixed $lifetime = null) use (&$store): void {
                unset($tags, $lifetime);
                $store[$key] = $data;
            },
        );
        $cache->method('get')->willReturnCallback(
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? false;
            },
        );
        $cache->method('remove')->willReturnCallback(
            static function (string $key) use (&$store): bool {
                unset($store[$key]);

                return true;
            },
        );

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('update')->with(
            'sys_file_metadata',
            self::callback(static fn(array $fields): bool => ($fields['tx_nst3af_ailabel_involvement'] ?? '') === 'no_ai'),
            ['uid' => 2],
        );
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $undo = new UndoCacheService($cacheManager, $pool);
        $undo->rememberBulk(5, [[
            'table' => 'sys_file_metadata',
            'uid' => 1,
            'values' => ['tx_nst3af_ailabel_involvement' => 'ai_generated'],
        ]]);
        $undo->rememberBulk(5, [[
            'table' => 'sys_file_metadata',
            'uid' => 2,
            'values' => ['tx_nst3af_ailabel_involvement' => 'no_ai'],
        ]]);

        self::assertSame(1, $undo->restoreBulk(5));
    }
}
