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

namespace NITSAN\NsT3AF\Tests\Functional\Domain\Repository;

use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Utility\ExportLimits;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TC-02 / PF-05: RequestLogRepository filters, aggregates, and export caps.
 */
final class RequestLogRepositoryFunctionalTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'frontend',
        'workspaces',
        'scheduler',
        'extensionmanager',
    ];

    protected array $testExtensionsToLoad = [
        'ns_license',
        'ns_t3af',
    ];

    #[Test]
    public function findFilteredPaginationAndUsageByExtensionMatchFixtures(): void
    {
        $now = time();
        $this->seedRequestLog('ns_t3af', 'chat', 100, 0.01, $now - 120);
        $this->seedRequestLog('ns_t3af', 'embed', 50, 0.02, $now - 60);
        $this->seedRequestLog('other_ext', 'chat', 20, 0.005, $now - 30);

        /** @var RequestLogRepository $repository */
        $repository = $this->get(RequestLogRepository::class);
        $from = $now - 3600;

        $page = $repository->findFiltered([], $from, null, null, 2, 0);
        self::assertCount(2, $page);

        $usage = $repository->usageByExtension($from, null, 10);
        self::assertNotEmpty($usage);
        $byExtension = [];
        foreach ($usage as $row) {
            $byExtension[$row['extensionKey']] = $row;
        }
        self::assertSame(2, $byExtension['ns_t3af']['requests'] ?? 0);
        self::assertSame(150, $byExtension['ns_t3af']['tokens'] ?? 0);
        self::assertEqualsWithDelta(0.03, $byExtension['ns_t3af']['cost'] ?? 0.0, 0.0001);
    }

    #[Test]
    public function findForExportIsCappedAtExportLimit(): void
    {
        $now = time();
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nst3af_request_log');
        $overCap = ExportLimits::MAX_ROWS + 5;

        for ($i = 0; $i < $overCap; $i++) {
            $connection->insert(
                'tx_nst3af_request_log',
                [
                    'pid' => 0,
                    'crdate' => $now - $i,
                    'tstamp' => $now - $i,
                    'hidden' => 0,
                    'deleted' => 0,
                    'provider_identifier' => 'openai-prod',
                    'extension_key' => 'ns_t3af',
                    'feature_key' => 'bulk',
                    'request_type' => 'complete',
                    'success' => 1,
                    'total_tokens' => 1,
                    'estimated_cost' => 0.0,
                ],
                [
                    'crdate' => Connection::PARAM_INT,
                    'tstamp' => Connection::PARAM_INT,
                ],
            );
        }

        /** @var RequestLogRepository $repository */
        $repository = $this->get(RequestLogRepository::class);
        $rows = $repository->findForExport([], $now - 86400, $now + 60);

        self::assertCount(ExportLimits::MAX_ROWS, $rows);
    }

    private function seedRequestLog(
        string $extensionKey,
        string $featureKey,
        int $tokens,
        float $cost,
        int $crdate,
    ): void {
        $this->getConnectionPool()->getConnectionForTable('tx_nst3af_request_log')->insert(
            'tx_nst3af_request_log',
            [
                'pid' => 0,
                'crdate' => $crdate,
                'tstamp' => $crdate,
                'hidden' => 0,
                'deleted' => 0,
                'provider_identifier' => 'openai-prod',
                'extension_key' => $extensionKey,
                'feature_key' => $featureKey,
                'request_type' => 'complete',
                'success' => 1,
                'total_tokens' => $tokens,
                'estimated_cost' => $cost,
            ],
            [
                'crdate' => Connection::PARAM_INT,
                'tstamp' => Connection::PARAM_INT,
            ],
        );
    }
}
