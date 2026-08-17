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

namespace NITSAN\NsT3AF\Tests\Unit\Credits;

use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Credits\Service\CreditsHistorySyncService;
use NITSAN\NsT3AF\Credits\Service\LocalReceiptCache;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class CreditsHistorySyncServiceTest extends TestCase
{
    public function testSyncPageUpsertsHistoryEntries(): void
    {
        $apiClient = $this->createMock(T3PlanetApiClient::class);
        $apiClient->expects(self::once())
            ->method('history')
            ->with('example.test', 'bearer-token', 'credit', 2, 20)
            ->willReturn([
                'status' => true,
                'entries' => [
                    [
                        'request_uuid' => 'hist-1',
                        'feature_key' => 'purchase',
                        'entry_type' => 'credit',
                        'cost_units' => 250000,
                        'cost' => 250.0,
                        'crdate' => 1710001000,
                    ],
                    [
                        // skipped — missing request_uuid
                        'feature_key' => 'purchase',
                        'entry_type' => 'credit',
                        'cost_units' => 1,
                    ],
                ],
            ]);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nst3af_credit_receipt',
                self::callback(static fn(array $row): bool => $row['request_uuid'] === 'hist-1'
                    && $row['entry_type'] === 'credit'
                    && (int) $row['cost_units'] === 250000),
            );

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $service = new CreditsHistorySyncService(
            $apiClient,
            new LocalReceiptCache($pool),
        );

        self::assertSame(1, $service->syncPage('example.test', 'bearer-token', 'credit', 2, 20));
    }
}
