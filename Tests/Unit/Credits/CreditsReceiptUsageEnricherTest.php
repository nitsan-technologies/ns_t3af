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

use NITSAN\NsT3AF\Credits\Service\CreditsReceiptUsageEnricher;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use PHPUnit\Framework\TestCase;

final class CreditsReceiptUsageEnricherTest extends TestCase
{
    public function testEnrichMergesRequestLogHintsIntoReceiptClient(): void
    {
        $logs = $this->createMock(RequestLogRepository::class);
        $logs->expects(self::once())
            ->method('resolveCreditsClientContextByReceipts')
            ->willReturn([
                'uuid-1' => [
                    'extension_key' => 'ns_t3ai',
                    'latency_ms' => 4472,
                    'page_id' => 0,
                    'page_title' => '',
                    'status' => 'success',
                ],
            ]);

        $enricher = new CreditsReceiptUsageEnricher($logs);
        $receipts = $enricher->enrich([
            [
                'request_uuid' => 'uuid-1',
                'feature_key' => 'seo_page_metadata',
                'cost' => 0.13,
                'crdate' => 1710001000,
                'extra' => json_encode(['status' => true], JSON_THROW_ON_ERROR),
            ],
        ]);

        $extra = json_decode((string) $receipts[0]['extra'], true);
        self::assertIsArray($extra);
        self::assertSame('ns_t3ai', $extra['client']['extension_key']);
        self::assertSame(4472, $extra['client']['latency_ms']);
        self::assertSame('success', $extra['client']['status']);
    }
}
