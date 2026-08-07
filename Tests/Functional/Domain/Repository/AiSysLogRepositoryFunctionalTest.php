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

use NITSAN\NsT3AF\Domain\Repository\AiSysLogRepository;
use NITSAN\NsT3AF\Service\AiLogChannelCatalog;
use NITSAN\NsT3AF\Utility\ExportLimits;
use NITSAN\NsT3AF\Utility\SysLogWriterUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TC-02: AiSysLogRepository round-trip against sys_log.
 */
final class AiSysLogRepositoryFunctionalTest extends FunctionalTestCase
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
    public function insertFilterCountAndExportRespectChannelAndPeriod(): void
    {
        $now = time();
        SysLogWriterUtility::insert('Provider saved', 'info', AiLogChannelCatalog::CHANNEL_PROVIDERS);
        SysLogWriterUtility::insert('MCP tool invoked', 'info', AiLogChannelCatalog::CHANNEL_MCP);
        SysLogWriterUtility::insert('Older provider event', 'warning', AiLogChannelCatalog::CHANNEL_PROVIDERS);

        $connection = $this->getConnectionPool()->getConnectionForTable('sys_log');
        $messageColumn = SysLogWriterUtility::usesLegacySchema($connection) ? 'details' : 'message';
        $connection->update(
            'sys_log',
            ['tstamp' => $now - 86400 * 40],
            [$messageColumn => 'Older provider event'],
        );

        /** @var AiSysLogRepository $repository */
        $repository = $this->get(AiSysLogRepository::class);

        $filters = [
            'logChannel' => AiLogChannelCatalog::CHANNEL_PROVIDERS,
            'fromTimestamp' => $now - 3600,
            'toTimestamp' => $now + 60,
        ];

        $rows = $repository->findFiltered($filters);
        self::assertCount(1, $rows);
        $logText = (string) ($rows[0]['message'] ?? $rows[0]['details'] ?? '');
        self::assertSame('Provider saved', $logText);

        $stats = $repository->getStatistics($filters);
        self::assertSame(1, $stats['total']);
        self::assertSame(1, $stats['info']);

        $export = $repository->findForExport($filters, ExportLimits::MAX_ROWS);
        self::assertCount(1, $export);
        self::assertLessThanOrEqual(ExportLimits::MAX_ROWS, count($export));
    }
}
