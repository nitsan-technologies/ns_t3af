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

use NITSAN\NsT3AF\Credits\Service\CreditsChargeRecorder;
use NITSAN\NsT3AF\Credits\Service\LocalReceiptCache;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class CreditsChargeRecorderTest extends TestCase
{
    public function testRecordDelegatesToLocalReceiptCacheWhenStatusTrue(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nst3af_credit_receipt',
                self::callback(static function (array $row): bool {
                    if ($row['request_uuid'] !== 'uuid-1'
                        || $row['feature_key'] !== 'text_to_speech'
                        || ($row['entry_type'] ?? '') !== 'debit'
                    ) {
                        return false;
                    }
                    $extra = json_decode((string) $row['extra'], true);
                    return is_array($extra)
                        && ($extra['client']['extension_key'] ?? null) === 'ns_t3ai'
                        && ($extra['client']['latency_ms'] ?? null) === 250
                        && ($extra['client']['status'] ?? null) === 'success';
                }),
            );

        $connection->method('count')->willReturn(1);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $recorder = new CreditsChargeRecorder(new LocalReceiptCache($pool));
        $recorder->record(
            'uuid-1',
            'text_to_speech',
            [
                'status' => true,
                'credits' => ['free' => 1],
                'charged' => ['model' => 'tts-1'],
            ],
            [
                'extension_key' => 'ns_t3ai',
                'latency_ms' => 250,
                'status' => 'success',
            ],
        );
    }

    public function testRecordSkipsWhenStatusFalse(): void
    {
        $pool = $this->createMock(ConnectionPool::class);
        $pool->expects(self::never())->method('getConnectionForTable');

        $recorder = new CreditsChargeRecorder(new LocalReceiptCache($pool));
        $recorder->record('uuid-1', 'embedding', [
            'status' => false,
        ]);
    }
}
