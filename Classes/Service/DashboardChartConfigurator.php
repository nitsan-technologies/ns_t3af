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

/**
 * Builds Chart.js JSON configs for the AI Foundation dashboard.
 *
 * @internal
 */
final class DashboardChartConfigurator
{
    /** TYPO3 backend–aligned chart colours (neutral gray primary, semantic accents). */
    private const CHART_BAR_FILL = 'rgba(115, 115, 115, 0.75)';

    /** @var list<string> */
    private const CHART_PALETTE = ['#737373', '#5a5a5a', '#16a34a', '#d97706', '#dc2626', '#0891b2', '#7c3aed'];

    /** @var list<string> */
    private const CHART_PALETTE_LINE = ['#737373', '#7c3aed', '#16a34a', '#d97706'];
    /**
     * @param list<array{day:string,requests:int,success:int,cost:float}> $requestsOverTime
     */
    public function requestsLineChart(array $requestsOverTime, string $label): string
    {
        $labels = [];
        $values = [];
        foreach ($requestsOverTime as $row) {
            $labels[] = (string) ($row['day'] ?? '');
            $values[] = (int) ($row['requests'] ?? 0);
        }

        return $this->encode([
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => $label,
                    'data' => $values,
                    'backgroundColor' => self::CHART_BAR_FILL,
                    'borderRadius' => 6,
                    'maxBarThickness' => 36,
                ]],
            ],
            'options' => $this->baseOptions(false),
        ]);
    }

    /**
     * @param list<array{day:string,credits:float}> $creditsOverTime
     */
    public function creditsBurnChart(array $creditsOverTime, string $label): string
    {
        $labels = [];
        $values = [];
        foreach ($creditsOverTime as $row) {
            $labels[] = (string) ($row['day'] ?? '');
            $values[] = round((float) ($row['credits'] ?? 0.0), 2);
        }

        return $this->encode([
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => $label,
                    'data' => $values,
                    'backgroundColor' => self::CHART_BAR_FILL,
                    'borderRadius' => 6,
                    'maxBarThickness' => 36,
                ]],
            ],
            'options' => $this->baseOptions(false),
        ]);
    }

    /**
     * @param list<array{extensionKey:string,requests:int,cost:float}> $byExtension
     */
    public function extensionDonutChart(array $byExtension, string $label): string
    {
        $labels = [];
        $values = [];
        $palette = self::CHART_PALETTE;
        $colors = [];
        $index = 0;
        foreach (array_slice($byExtension, 0, 8) as $row) {
            $key = (string) ($row['extensionKey'] ?? '');
            if ($key === '') {
                continue;
            }
            $labels[] = $key;
            $values[] = (float) ($row['cost'] ?? 0.0) > 0
                ? round((float) $row['cost'], 4)
                : (int) ($row['requests'] ?? 0);
            $colors[] = $palette[$index % count($palette)];
            $index++;
        }

        if ($labels === []) {
            $labels = ['—'];
            $values = [1];
            $colors = ['#e5e7eb'];
        }

        return $this->encode([
            'type' => 'doughnut',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => $label,
                    'data' => $values,
                    'backgroundColor' => $colors,
                    'borderWidth' => 0,
                ]],
            ],
            'options' => $this->baseOptions(true),
        ]);
    }

    /**
     * @param list<array{day:string,success:int,failed:int}> $rows
     */
    public function requestsSuccessFailChart(array $rows, string $successLabel, string $failedLabel): string
    {
        $labels = [];
        $success = [];
        $failed = [];
        foreach ($rows as $row) {
            $labels[] = (string) ($row['day'] ?? '');
            $success[] = (int) ($row['success'] ?? 0);
            $failed[] = (int) ($row['failed'] ?? 0);
        }

        return $this->encode([
            'type' => 'line',
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => $successLabel,
                        'data' => $success,
                        'borderColor' => '#16a34a',
                        'backgroundColor' => 'rgba(22,163,74,0.15)',
                        'fill' => true,
                        'tension' => 0.35,
                    ],
                    [
                        'label' => $failedLabel,
                        'data' => $failed,
                        'borderColor' => '#dc2626',
                        'backgroundColor' => 'rgba(220,38,38,0.08)',
                        'fill' => true,
                        'tension' => 0.35,
                    ],
                ],
            ],
            'options' => $this->baseOptions(false),
        ]);
    }

    /**
     * @param array{success:int,failed:int} $totals
     */
    public function successRateDonutChart(array $totals, string $successLabel, string $failedLabel): string
    {
        $success = (int) ($totals['success'] ?? 0);
        $failed = (int) ($totals['failed'] ?? 0);

        return $this->encode([
            'type' => 'doughnut',
            'data' => [
                'labels' => [$successLabel, $failedLabel],
                'datasets' => [[
                    'data' => [$success, $failed],
                    'backgroundColor' => ['#16a34a', '#dc2626'],
                    'borderWidth' => 0,
                ]],
            ],
            'options' => $this->baseOptions(true),
        ]);
    }

    /**
     * @param list<array{day:string,extensionKey:string,credits:float}> $rows
     */
    public function creditsStackedBurnChart(array $rows, string $label): string
    {
        $byDay = [];
        $extensions = [];
        foreach ($rows as $row) {
            $day = (string) ($row['day'] ?? '');
            $ext = (string) ($row['extensionKey'] ?? 'other');
            if ($ext === '') {
                $ext = 'other';
            }
            $extensions[$ext] = true;
            $byDay[$day][$ext] = ($byDay[$day][$ext] ?? 0.0) + (float) ($row['credits'] ?? 0.0);
        }
        $labels = array_keys($byDay);
        sort($labels);
        $extKeys = array_keys($extensions);
        sort($extKeys);
        $palette = ['#16a34a', '#d97706', '#737373', '#7c3aed', '#0891b2', '#db2777'];
        $datasets = [];
        foreach ($extKeys as $i => $ext) {
            $data = [];
            foreach ($labels as $day) {
                $data[] = round((float) ($byDay[$day][$ext] ?? 0.0), 2);
            }
            $datasets[] = [
                'label' => $ext,
                'data' => $data,
                'backgroundColor' => $palette[$i % count($palette)],
                'stack' => 'burn',
            ];
        }

        return $this->encode([
            'type' => 'bar',
            'data' => ['labels' => $labels, 'datasets' => $datasets],
            'options' => $this->stackedBarOptions(),
        ]);
    }

    /**
     * @param list<array{day:string,provider:string,cost:float}> $rows
     */
    public function costTrendMultiLineChart(array $rows): string
    {
        $byDay = [];
        $providers = [];
        foreach ($rows as $row) {
            $day = (string) ($row['day'] ?? '');
            $provider = (string) ($row['provider'] ?? '');
            if ($provider === '') {
                continue;
            }
            $providers[$provider] = true;
            $byDay[$day][$provider] = ($byDay[$day][$provider] ?? 0.0) + (float) ($row['cost'] ?? 0.0);
        }
        $labels = array_keys($byDay);
        sort($labels);
        $providerKeys = array_keys($providers);
        sort($providerKeys);
        $palette = self::CHART_PALETTE_LINE;
        $datasets = [];
        foreach ($providerKeys as $i => $provider) {
            $data = [];
            foreach ($labels as $day) {
                $data[] = round((float) ($byDay[$day][$provider] ?? 0.0), 4);
            }
            $datasets[] = [
                'label' => $provider,
                'data' => $data,
                'borderColor' => $palette[$i % count($palette)],
                'backgroundColor' => $palette[$i % count($palette)] . '33',
                'fill' => true,
                'tension' => 0.35,
            ];
        }

        return $this->encode([
            'type' => 'line',
            'data' => ['labels' => $labels, 'datasets' => $datasets],
            'options' => $this->multiSeriesLineOptions(),
        ]);
    }

    /**
     * @param list<array{extensionKey:string,credits:float}> $rows
     */
    public function horizontalBarChart(array $rows, string $label, string $valueKey = 'credits'): string
    {
        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $key = (string) ($row['extensionKey'] ?? $row['model'] ?? '');
            if ($key === '') {
                continue;
            }
            $labels[] = $key;
            $values[] = round((float) ($row[$valueKey] ?? $row['tokens'] ?? 0), 2);
        }

        return $this->encode([
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => $label,
                    'data' => $values,
                    'backgroundColor' => self::CHART_BAR_FILL,
                    'borderRadius' => 4,
                ]],
            ],
            'options' => [
                'indexAxis' => 'y',
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => ['legend' => ['display' => false]],
                'scales' => [
                    'x' => ['beginAtZero' => true, 'grid' => ['color' => 'rgba(0,0,0,0.06)']],
                    'y' => ['grid' => ['display' => false]],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function multiSeriesLineOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                    'labels' => ['boxWidth' => 10, 'font' => ['size' => 11]],
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'scales' => [
                'x' => ['grid' => ['display' => false], 'ticks' => ['font' => ['size' => 10]]],
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['font' => ['size' => 10]],
                    'grid' => ['color' => 'rgba(0,0,0,0.06)'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stackedBarOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                    'labels' => ['boxWidth' => 10, 'font' => ['size' => 11]],
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'scales' => [
                'x' => ['stacked' => true, 'grid' => ['display' => false]],
                'y' => ['stacked' => true, 'beginAtZero' => true],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function encode(array $config): string
    {
        return json_encode($config, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseOptions(bool $donut): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => $donut,
                    'position' => 'bottom',
                    'labels' => ['boxWidth' => 10, 'font' => ['size' => 11]],
                ],
                'tooltip' => [
                    'intersect' => false,
                ],
            ],
        ] + ($donut ? [] : [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => ['grid' => ['display' => false], 'ticks' => ['font' => ['size' => 10]]],
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['font' => ['size' => 10]],
                    'grid' => ['color' => 'rgba(0,0,0,0.06)'],
                ],
            ],
        ]);
    }
}
