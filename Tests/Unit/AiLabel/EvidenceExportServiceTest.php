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

use NITSAN\NsT3AF\AiLabel\Service\ComplianceStringsService;
use NITSAN\NsT3AF\AiLabel\Service\EvidenceExportService;
use NITSAN\NsT3AF\AiLabel\Service\MediaRuleEngine;
use NITSAN\NsT3AF\AiLabel\Service\TextRuleEngine;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class EvidenceExportServiceTest extends TestCase
{
    /**
     * @dataProvider relevanceCases
     * @param array<string, mixed> $record
     */
    public function testIsEvidenceRelevant(array $record, bool $expected): void
    {
        $service = new EvidenceExportService(
            $this->createMock(ConnectionPool::class),
            new MediaRuleEngine(new ComplianceStringsService()),
            new TextRuleEngine(),
            new ComplianceStringsService(),
        );
        $method = new \ReflectionMethod(EvidenceExportService::class, 'isEvidenceRelevant');
        $method->setAccessible(true);

        self::assertSame($expected, $method->invoke($service, $record));
    }

    /**
     * @return \Generator<string, array{array<string, mixed>, bool}>
     */
    public static function relevanceCases(): \Generator
    {
        yield 'untouched not_reviewed' => [
            ['uid' => 1, 'tx_nst3af_ailabel_involvement' => 'not_reviewed'],
            false,
        ];
        yield 'recording source set' => [
            [
                'uid' => 2,
                'tx_nst3af_ailabel_involvement' => 'not_reviewed',
                'tx_nst3af_ailabel_recording_source' => 'ns_t3aa',
            ],
            true,
        ];
        yield 'ai_generated involvement' => [
            ['uid' => 3, 'tx_nst3af_ailabel_involvement' => 'ai_generated'],
            true,
        ];
        yield 'confirmed only' => [
            [
                'uid' => 4,
                'tx_nst3af_ailabel_involvement' => 'not_reviewed',
                'tx_nst3af_ailabel_confirmed_at' => 1700000000,
            ],
            true,
        ];
    }

    /**
     * @dataProvider scopeTableCases
     * @param list<string> $expected
     */
    public function testTablesForScope(string $scope, array $expected): void
    {
        $service = new EvidenceExportService(
            $this->createMock(ConnectionPool::class),
            new MediaRuleEngine(new ComplianceStringsService()),
            new TextRuleEngine(),
            new ComplianceStringsService(),
        );

        self::assertSame($expected, $service->tablesForScope($scope));
    }

    /**
     * @return \Generator<string, array{string, list<string>}>
     */
    public static function scopeTableCases(): \Generator
    {
        yield 'media' => ['media', ['sys_file_metadata']];
        yield 'texts' => ['texts', ['tt_content', 'pages']];
        yield 'all' => ['all', ['tt_content', 'pages', 'sys_file_metadata']];
        yield 'unknown defaults to all' => ['other', ['tt_content', 'pages', 'sys_file_metadata']];
    }
}
