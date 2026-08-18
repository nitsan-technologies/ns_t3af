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
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class AiLabelBulkActionServiceTest extends TestCase
{
    public function testEmptyRefsReturnsZeroProcessed(): void
    {
        $pool = $this->createMock(ConnectionPool::class);
        $cacheManager = $this->createMock(CacheManager::class);
        $cache = new VariableFrontend('ailabel_undo_test', new \TYPO3\CMS\Core\Cache\Backend\NullBackend('ailabel', []));
        $cacheManager->method('getCache')->willReturn($cache);

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
        $cache = new VariableFrontend('ailabel_undo_test2', new \TYPO3\CMS\Core\Cache\Backend\NullBackend('ailabel2', []));
        $cacheManager->method('getCache')->willReturn($cache);

        $service = new AiLabelBulkActionService(
            $pool,
            new ConfirmationService($pool, null),
            new UndoCacheService($cacheManager, $pool),
        );

        $result = $service->execute('confirm', ['invalid', 'bad:0'], 1);

        self::assertSame(0, $result['processed']);
    }
}
