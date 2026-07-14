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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Api\AiCreditUnits;

/**
 * Prototype-aligned dashboard view-model helpers (spend, KPIs, credits hero, lists).
 *
 * @internal
 */
final class DashboardViewModelBuilder
{
    /**
     * @param array<string, mixed> $analytics
     * @return array{
     *   total:float,
     *   totalFormatted:string,
     *   dailyAvg:float,
     *   dailyAvgFormatted:string,
     *   periodLabel:string,
     *   rows:list<array{label:string,cost:float,costFormatted:string,barPercent:float}>
     * }
     */
    public function buildApiSpendSummary(array $analytics, float $totalSpend): array
    {
        $periodDays = max(1, (int) ($analytics['periodDays'] ?? 7));
        $dailyAvg = $totalSpend / $periodDays;
        $rows = [];
        $distribution = is_array($analytics['providerStats'] ?? null) ? $analytics['providerStats'] : [];
        $maxCost = 0.0;
        foreach ($distribution as $row) {
            $maxCost = max($maxCost, (float) ($row['cost'] ?? 0.0));
        }
        if ($maxCost <= 0.0) {
            $maxCost = 1.0;
        }
        foreach ($distribution as $row) {
            $cost = (float) ($row['cost'] ?? 0.0);
            $label = (string) ($row['provider'] ?? '');
            if ($label === '') {
                continue;
            }
            $rows[] = [
                'label' => $label,
                'cost' => $cost,
                'costFormatted' => '$' . number_format($cost, 2),
                'barPercent' => round(100 * $cost / $maxCost, 1),
            ];
        }

        return [
            'total' => $totalSpend,
            'totalFormatted' => '$' . number_format($totalSpend, 2),
            'dailyAvg' => $dailyAvg,
            'dailyAvgFormatted' => '$' . number_format($dailyAvg, 2) . '/day avg',
            'periodLabel' => (string) $periodDays . ' days',
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $analytics
     * @param array<string, mixed> $trends
     * @param array<string, mixed> $creditsDashboard
     * @param list<string> $activeProviderLabels
     * @return list<array<string, mixed>>
     */
    public function buildKpiStrip(
        array $analytics,
        array $trends,
        bool $creditsMode,
        array $creditsDashboard,
        array $activeProviderLabels,
    ): array {
        $totals = is_array($analytics['totals'] ?? null) ? $analytics['totals'] : [];
        $successFail = is_array($analytics['successFail'] ?? null) ? $analytics['successFail'] : [];
        $scheduled = is_array($analytics['scheduledTasks'] ?? null) ? $analytics['scheduledTasks'] : [];
        $success = (int) ($successFail['success'] ?? 0);
        $failed = (int) ($successFail['failed'] ?? 0);

        $strip = [
            $this->kpiCard(
                'requests',
                'actions-list-alternative',
                (string) ($totals['totalRequests'] ?? 0),
                $trends['totalRequests'] ?? [],
                $success . ' ok · ' . $failed . ' failed',
            ),
            $this->kpiCard(
                'tokens',
                'actions-database',
                $this->formatTokenCount((int) ($totals['totalTokens'] ?? 0)),
                $trends['totalTokens'] ?? [],
                'cumulative all extensions',
            ),
        ];

        if ($creditsMode) {
            $stats = is_array($creditsDashboard['stats'] ?? null) ? $creditsDashboard['stats'] : [];
            $strip[] = $this->kpiCard(
                'creditsUsed',
                'actions-wallet',
                (string) ($stats['creditsUsedFormatted'] ?? '0') . ' cr',
                $trends['cost'] ?? [],
                'consumed this period',
            );
        } else {
            $cost = (float) ($totals['totalCost'] ?? 0.0);
            $strip[] = $this->kpiCard(
                'apiCost',
                'actions-credit-card',
                '$' . number_format($cost, 2),
                $trends['cost'] ?? [],
                'across all providers',
            );
        }

        $strip[] = $this->kpiCard(
            'successRate',
            'actions-check',
            (string) ($totals['successRate'] ?? 0) . '%',
            $trends['successRate'] ?? [],
            $success . ' ok · ' . $failed . ' failed',
        );

        if ($creditsMode) {
            $stats = is_array($creditsDashboard['stats'] ?? null) ? $creditsDashboard['stats'] : [];
            $daysLeft = (int) ($stats['estimatedDaysLeft'] ?? 0);
            $strip[] = $this->kpiCard(
                'daysLeft',
                'actions-calendar',
                $daysLeft > 0 ? (string) $daysLeft . ' days' : '—',
                [],
                'at current burn rate',
            );
        } else {
            $active = (int) ($analytics['activeProviders'] ?? 0);
            $strip[] = $this->kpiCard(
                'activeProviders',
                'actions-extension',
                $active . ' active',
                $trends['activeProviders'] ?? [],
                $activeProviderLabels !== [] ? implode(' · ', array_slice($activeProviderLabels, 0, 4)) : 'none configured',
            );
        }

        $strip[] = $this->kpiCard(
            'scheduledTasks',
            'actions-clock',
            (string) ((int) ($scheduled['active'] ?? 0)) . ' active',
            [],
            $this->scheduledTaskSubLabel($scheduled),
        );

        return $strip;
    }

    /**
     * @param array<string, mixed> $creditsDashboard
     * @param array<string, mixed> $analyticsCredits
     * @param array{runOutDate:string,dailyAvg:float,dailyAvgFormatted:string} $projection
     * @return array<string, mixed>
     */
    public function buildCreditsHero(array $creditsDashboard, array $analyticsCredits, array $projection): array
    {
        $balance = is_array($creditsDashboard['balance'] ?? null) ? $creditsDashboard['balance'] : [];
        $plan = is_array($creditsDashboard['plan'] ?? null) ? $creditsDashboard['plan'] : [];
        $stats = is_array($creditsDashboard['stats'] ?? null) ? $creditsDashboard['stats'] : [];
        $remaining = (float) ($balance['remaining'] ?? 0.0);
        $total = (float) ($balance['total'] ?? 0.0);
        $used = (float) ($balance['used'] ?? max(0.0, $total - $remaining));
        $percentLeft = (int) ($balance['percentLeft'] ?? 0);
        $consumedPercent = $total > 0.0 ? (int) round(($used / $total) * 100) : 0;

        $weeklyBurn = 0.0;
        foreach ($analyticsCredits['creditsByDayAndExtension'] ?? [] as $dayRow) {
            if (!is_array($dayRow)) {
                continue;
            }
            foreach ($dayRow as $key => $value) {
                if ($key === 'day' || !is_numeric($value)) {
                    continue;
                }
                $weeklyBurn += (float) $value;
            }
        }

        $expiresAt = (int) ($plan['expiresAt'] ?? 0);
        $planLine = trim((string) ($plan['name'] ?? ''));
        if ($planLine !== '') {
            $planLine .= $expiresAt > 0 ? ' · expires ' . date('M j, Y', $expiresAt) : ' · no expiry';
        }

        $daysLeft = (int) ($stats['estimatedDaysLeft'] ?? 0);

        return [
            'percentLeft' => $percentLeft,
            'progressDash' => (string) (int) max(0, min(97, round($percentLeft * 97 / 100))),
            'remainingFormatted' => (string) ($balance['remainingFormatted'] ?? AiCreditUnits::formatCredits($remaining)),
            'totalFormatted' => (string) ($balance['totalFormatted'] ?? AiCreditUnits::formatCredits($total)),
            'usedFormatted' => (string) ($balance['usedFormatted'] ?? AiCreditUnits::formatCredits($used)),
            'used' => $used,
            'total' => $total,
            'consumedPercent' => $consumedPercent,
            'planLine' => $planLine,
            'daysLeftLabel' => $daysLeft > 0 ? '~' . $daysLeft . ' days at current rate' : '',
            'dailyAverageFormatted' => (string) ($stats['dailyAverageFormatted'] ?? '0'),
            'weeklyBurnFormatted' => AiCreditUnits::formatCredits($weeklyBurn) . ' this week',
            'projectionDate' => (string) ($projection['runOutDate'] ?? ''),
            'projectionDailyAvg' => (string) ($projection['dailyAvgFormatted'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $analyticsCredits
     * @param array<string, mixed> $creditsDashboard
     * @return list<array{label:string,credits:float,creditsFormatted:string,barPercent:float,trend:string}>
     */
    public function buildCreditEfficiency(array $analyticsCredits, array $creditsDashboard): array
    {
        $featureRows = is_array($analyticsCredits['featureCredits'] ?? null) ? $analyticsCredits['featureCredits'] : [];
        $catalog = [];
        foreach (is_array($creditsDashboard['features'] ?? null) ? $creditsDashboard['features'] : [] as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $key = (string) ($feature['key'] ?? '');
            if ($key !== '') {
                $catalog[$key] = (string) ($feature['label'] ?? $key);
            }
        }

        $max = 0.0;
        foreach ($featureRows as $row) {
            $max = max($max, (float) ($row['credits'] ?? 0.0));
        }
        if ($max <= 0.0) {
            $max = 1.0;
        }

        $items = [];
        foreach (array_slice($featureRows, 0, 5) as $row) {
            $key = (string) ($row['featureKey'] ?? $row['feature_key'] ?? '');
            $creditsPerRequest = (float) ($row['creditsPerRequest'] ?? 0.0);
            if ($creditsPerRequest <= 0.0) {
                $requests = max(1, (int) ($row['requests'] ?? 1));
                $creditsPerRequest = (float) ($row['credits'] ?? 0.0) / $requests;
            }
            $items[] = [
                'label' => $catalog[$key] ?? ((string) ($row['featureLabel'] ?? $key)),
                'credits' => round($creditsPerRequest, 1),
                'creditsFormatted' => number_format($creditsPerRequest, 1) . ' cr',
                'barPercent' => 0.0,
                'trend' => 'stable',
            ];
        }

        return $items;
    }

    /**
     * @param list<array{provider:string,requests:int,cost?:float}> $distribution
     * @return list<array{name:string,value:float,percent:float,barPercent:float}>
     */
    public function buildProviderDistributionLegend(array $distribution): array
    {
        $totalRequests = 0;
        foreach ($distribution as $row) {
            $totalRequests += (int) ($row['requests'] ?? 0);
        }
        if ($totalRequests <= 0) {
            $totalRequests = 1;
        }

        $legend = [];
        foreach ($distribution as $row) {
            $requests = (int) ($row['requests'] ?? 0);
            $percent = round(100 * $requests / $totalRequests, 1);
            $legend[] = [
                'name' => (string) ($row['provider'] ?? ''),
                'value' => (float) $requests,
                'percent' => $percent,
                'barPercent' => $percent,
            ];
        }

        return $legend;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function enrichRecentRequests(array $rows): array
    {
        $enriched = [];
        foreach ($rows as $row) {
            $score = (int) ($row['quality_score'] ?? 0);
            $dimensions = [];
            $rawDimensions = (string) ($row['quality_dimensions'] ?? '');
            if ($rawDimensions !== '') {
                $decoded = json_decode($rawDimensions, true);
                if (is_array($decoded)) {
                    $dimensions = $decoded;
                }
            }
            $enriched[] = array_merge($row, [
                'qualityScore' => $score,
                'qualityDimensions' => $dimensions,
                'hasQuality' => $score > 0,
                'qualityDash' => $score > 0 ? (string) (int) max(0, min(97, round($score * 97 / 100))) : '0',
                'qualityView' => $this->buildQualityView($score, $dimensions),
            ]);
        }

        return $enriched;
    }

    /**
     * @param array<string, int|float> $dimensions
     * @return array{hasQuality:bool,score:int,dash:string,level:string,title:string}
     */
    private function buildQualityView(int $score, array $dimensions): array
    {
        if ($score <= 0) {
            return [
                'hasQuality' => false,
                'score' => 0,
                'dash' => '0',
                'level' => 'none',
                'title' => '',
            ];
        }

        $level = $score >= 80 ? 'good' : ($score >= 60 ? 'warn' : 'bad');
        $parts = [];
        foreach (['relevance', 'readability', 'seoFit', 'brandAlignment'] as $key) {
            if (isset($dimensions[$key])) {
                $parts[] = ucfirst($key) . ': ' . (int) $dimensions[$key];
            }
        }

        return [
            'hasQuality' => true,
            'score' => $score,
            'dash' => (string) (int) max(0, min(97, round($score * 97 / 100))),
            'level' => $level,
            'title' => $parts !== [] ? implode(' · ', $parts) : (string) $score . '/100',
        ];
    }

    /**
     * @param array<string, mixed> $scheduled
     */
    private function scheduledTaskSubLabel(array $scheduled): string
    {
        $next = trim((string) ($scheduled['nextRunLabel'] ?? ''));
        if ($next !== '') {
            return 'next run ' . $next;
        }

        return (int) ($scheduled['active'] ?? 0) . ' tasks configured';
    }

    /**
     * @param array<string, mixed> $trend
     * @return array<string, mixed>
     */
    private function kpiCard(string $id, string $iconIdentifier, string $value, array $trend, string $subLabel): array
    {
        return [
            'id' => $id,
            'iconIdentifier' => $iconIdentifier,
            'value' => $value,
            'trend' => $trend,
            'subLabel' => $subLabel,
        ];
    }

    private function formatTokenCount(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return number_format($tokens / 1_000_000, 1) . 'M';
        }
        if ($tokens >= 1_000) {
            return number_format($tokens / 1_000, 1) . 'K';
        }

        return (string) $tokens;
    }
}
