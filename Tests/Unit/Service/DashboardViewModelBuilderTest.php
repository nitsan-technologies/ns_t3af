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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Service\DashboardViewModelBuilder;
use PHPUnit\Framework\TestCase;

final class DashboardViewModelBuilderTest extends TestCase
{
    private DashboardViewModelBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DashboardViewModelBuilder();
    }

    public function testBuildApiSpendSummaryIncludesDailyAverageAndBars(): void
    {
        $summary = $this->builder->buildApiSpendSummary([
            'periodDays' => 7,
            'periodPreset' => '7d',
            'providerStats' => [
                ['provider' => 'openai', 'cost' => 8.42],
                ['provider' => 'anthropic', 'cost' => 2.47],
            ],
        ], 10.89);

        self::assertSame('7-day API spend', $summary['title']);
        self::assertSame('$10.89', $summary['totalFormatted']);
        self::assertSame('$1.56/day avg', $summary['dailyAvgFormatted']);
        self::assertCount(2, $summary['rows']);
        self::assertSame(100.0, $summary['rows'][0]['barPercent']);
    }

    public function testBuildApiSpendSummaryUsesPeriodAwareTitleAndSubCentPrecision(): void
    {
        $summary = $this->builder->buildApiSpendSummary([
            'periodDays' => 30,
            'periodPreset' => '30d',
            'providerStats' => [
                ['provider' => 'openai', 'cost' => 0.004463],
            ],
        ], 0.004463);

        self::assertSame('30-day API spend', $summary['title']);
        self::assertSame('$0.0045', $summary['totalFormatted']);
        self::assertSame('$0.0001/day avg', $summary['dailyAvgFormatted']);
        self::assertSame('$0.0045', $summary['rows'][0]['costFormatted']);
        self::assertSame("Today's API spend", $this->builder->buildApiSpendSummary([
            'periodDays' => 1,
            'periodPreset' => 'today',
            'providerStats' => [],
        ], 0.0)['title']);
    }

    public function testEnrichRecentRequestsAddsQualityView(): void
    {
        $rows = $this->builder->enrichRecentRequests([
            [
                'quality_score' => 94,
                'quality_dimensions' => json_encode([
                    'score' => 94,
                    'relevance' => 96,
                    'readability' => 92,
                    'seoFit' => 90,
                    'brandAlignment' => 88,
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        self::assertTrue($rows[0]['hasQuality']);
        self::assertSame('good', $rows[0]['qualityView']['level']);
        self::assertSame(94, $rows[0]['qualityView']['score']);
    }

    public function testBuildProviderDistributionLegendCalculatesPercentages(): void
    {
        $legend = $this->builder->buildProviderDistributionLegend([
            ['provider' => 'openai', 'requests' => 75],
            ['provider' => 'anthropic', 'requests' => 25],
        ]);

        self::assertSame(75.0, $legend[0]['percent']);
        self::assertSame(25.0, $legend[1]['percent']);
    }

    public function testBuildKpiStripOmitsTokensInCreditsMode(): void
    {
        $strip = $this->builder->buildKpiStrip(
            ['totals' => ['totalRequests' => 10, 'totalTokens' => 5000, 'successRate' => 90.0]],
            ['totalRequests' => [], 'totalTokens' => [], 'cost' => [], 'successRate' => []],
            true,
            ['stats' => ['creditsUsedFormatted' => '12', 'estimatedDaysLeft' => 7]],
            [],
        );

        $ids = array_column($strip, 'id');
        self::assertNotContains('tokens', $ids);
        self::assertContains('creditsUsed', $ids);
    }
}
