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

namespace NITSAN\NsT3AF\Tests\Unit\Domain\Repository;

use NITSAN\NsT3AF\Domain\Repository\ProviderRepository;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ProviderRepositorySoftDeleteTest extends TestCase
{
    public function testSoftDeleteTombstonesIdentifierAndClearsDefault(): void
    {
        $GLOBALS['EXEC_TIME'] = 100;
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_nst3af_provider',
                [
                    'deleted' => 1,
                    'is_default' => 0,
                    'tstamp' => 100,
                    'identifier' => 'deleted-4',
                ],
                ['uid' => 4],
            );
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->with('tx_nst3af_provider')->willReturn($connection);

        (new ProviderRepository($pool))->softDelete(4);
    }

    public function testSoftDeleteIgnoresNonPositiveUid(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('update');
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        (new ProviderRepository($pool))->softDelete(0);
    }
}
