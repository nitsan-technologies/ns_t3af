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
                self::callback(static fn(array $row): bool => $row['request_uuid'] === 'uuid-1'
                    && $row['feature_key'] === 'text_to_speech'),
            );

        $connection->method('count')->willReturn(1);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $recorder = new CreditsChargeRecorder(new LocalReceiptCache($pool));
        $recorder->record('uuid-1', 'text_to_speech', [
            'status' => true,
            'credits' => ['free' => 1],
            'charged' => ['model' => 'tts-1'],
        ]);
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
