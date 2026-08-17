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

use NITSAN\NsT3AF\Credits\Service\LocalReceiptCache;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class LocalReceiptCacheHistoryUpsertTest extends TestCase
{
    public function testUpsertFromHistoryEntryInsertsMappedRow(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nst3af_credit_receipt',
                self::callback(static function (array $row): bool {
                    return $row['request_uuid'] === 'hist-credit-1'
                        && $row['feature_key'] === 'purchase'
                        && $row['entry_type'] === 'credit'
                        && (int) $row['cost_units'] === 250000
                        && (int) $row['crdate'] === 1710001000
                        && $row['model'] === ''
                        && $row['bucket'] === 'paid';
                }),
            );

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $cache = new LocalReceiptCache($pool);
        self::assertTrue($cache->upsertFromHistoryEntry([
            'request_uuid' => 'hist-credit-1',
            'feature_key' => 'purchase',
            'entry_type' => 'credit',
            'cost_units' => 250000,
            'cost' => 250.0,
            'bucket' => 'paid',
            'crdate' => 1710001000,
        ]));
    }

    public function testUpsertFromHistoryEntrySkipsWithoutUuid(): void
    {
        $pool = $this->createMock(ConnectionPool::class);
        $pool->expects(self::never())->method('getConnectionForTable');

        $cache = new LocalReceiptCache($pool);
        self::assertFalse($cache->upsertFromHistoryEntry([
            'feature_key' => 'purchase',
            'entry_type' => 'credit',
            'cost_units' => 1,
        ]));
    }

    public function testHistoryUpsertPreservesExistingClientContext(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->willThrowException(new \Doctrine\DBAL\Exception\UniqueConstraintViolationException(
                $this->createMock(\Doctrine\DBAL\Driver\Exception::class),
                null,
            ));

        $connection->expects(self::once())
            ->method('select')
            ->with(['extra'], 'tx_nst3af_credit_receipt', ['request_uuid' => 'uuid-keep'])
            ->willReturn(self::createConfiguredMock(\Doctrine\DBAL\Result::class, [
                'fetchOne' => json_encode([
                    'status' => true,
                    'client' => [
                        'extension_key' => 'ns_t3ai',
                        'latency_ms' => 4472,
                        'status' => 'success',
                    ],
                ], JSON_THROW_ON_ERROR),
            ]));

        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_nst3af_credit_receipt',
                self::callback(static function (array $row): bool {
                    $extra = json_decode((string) $row['extra'], true);
                    return is_array($extra)
                        && ($extra['client']['extension_key'] ?? null) === 'ns_t3ai'
                        && ($extra['client']['latency_ms'] ?? null) === 4472;
                }),
                ['request_uuid' => 'uuid-keep'],
            );

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $cache = new LocalReceiptCache($pool);
        self::assertTrue($cache->upsertFromHistoryEntry([
            'request_uuid' => 'uuid-keep',
            'feature_key' => 'seo_page_metadata',
            'entry_type' => 'debit',
            'cost_units' => 130,
            'cost' => 0.13,
            'crdate' => 1710001000,
        ]));
    }
}
