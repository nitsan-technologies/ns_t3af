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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\QueueAutomationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class QueueAutomationStatusTest extends TestCase
{
    #[Test]
    public function noTasksMeansAutomaticProcessingIsOff(): void
    {
        $flags = QueueAutomationStatus::flagsFromRows([]);

        self::assertFalse($flags['seo']);
        self::assertFalse($flags['translation']);
        $lines = implode("\n", QueueAutomationStatus::linesFor(false, false));
        self::assertStringContainsString('SEO queue is off', $lines);
        self::assertStringContainsString('enabling the SEO scheduler task', $lines);
        self::assertStringContainsString('translation queue is off', $lines);
        self::assertStringContainsString('Do not say a scheduled job will run', $lines);
        self::assertStringContainsString('t3ai_mass_seo_queue_list again', $lines);
    }

    #[Test]
    public function anEnabledSeoTaskTurnsOnlySeoProcessingOn(): void
    {
        $flags = QueueAutomationStatus::flagsFromRows([
            [
                'tasktype' => 'NITSAN\\NsT3Ai\\Task\\BulkMassSeoOptimizeTask',
                'disable' => 0,
                'deleted' => 0,
            ],
            [
                'tasktype' => 't3af:bulk:translate',
                'disable' => 1,
                'deleted' => 0,
            ],
        ]);

        self::assertTrue($flags['seo']);
        self::assertFalse($flags['translation']);
        $lines = implode("\n", QueueAutomationStatus::linesFor(true, false));
        self::assertStringContainsString('SEO queue is on', $lines);
        self::assertStringContainsString('translation queue is off', $lines);
    }

    #[Test]
    public function aDisabledOrDeletedTaskDoesNotCountAsOn(): void
    {
        $flags = QueueAutomationStatus::flagsFromRows([
            [
                'tasktype' => 'NITSAN\\NsT3Ai\\Task\\BulkMassSeoOptimizeTask',
                'disable' => 1,
                'deleted' => 0,
            ],
            [
                'tasktype' => 'nst3ai:bulk:seo-optimize',
                'disable' => 0,
                'deleted' => 1,
            ],
            [
                'tasktype' => 'NITSAN\\NsT3Ai\\Task\\BulkPageTranslateTask',
                'disable' => 1,
                'deleted' => 0,
            ],
        ]);

        self::assertSame(['seo' => false, 'translation' => false], $flags);
    }

    #[Test]
    public function anEnabledCommandAliasTurnsTranslationProcessingOn(): void
    {
        $flags = QueueAutomationStatus::flagsFromRows([
            [
                'tasktype' => 'nst3ai:bulk:translate',
                'disable' => 0,
                'deleted' => 0,
            ],
        ]);

        self::assertFalse($flags['seo']);
        self::assertTrue($flags['translation']);
        self::assertStringContainsString(
            'translation queue is on',
            implode("\n", QueueAutomationStatus::linesFor(false, true)),
        );
    }
}
