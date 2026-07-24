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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Api\AiCreditUnits;
use NITSAN\NsT3AF\Api\CreditsPricing;

/**
 * Normalizes T3Planet API payloads into a Fluid-friendly dashboard view model.
 *
 * @internal
 */
final class CreditsDashboardAssembler
{
    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private readonly CreditsCheckoutUrlBuilder $checkoutUrlBuilder,
    ) {}

    /**
     * @param array<string, mixed> $balance
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $productsPayload
     * @param array<string, mixed> $featuresPayload
     * @param list<array<string, mixed>> $receipts
     * @param list<string>|array<string, string> $errors  Deduplicated user-facing messages for the UI (may be a list after {@see CreditsDashboardService::fetchAndAssemble}).
     * @return array<string, mixed>
     */
    public function assemble(
        array $balance,
        array $plan,
        array $productsPayload,
        array $featuresPayload,
        array $receipts,
        array $errors,
        string $returnUrl,
    ): array {
        $balanceSummary = $this->summarizeBalance($balance, $plan);
        $stats = $this->buildUsageStats(
            $receipts,
            $balanceSummary['remainingUnits'],
            $balanceSummary['remaining'],
            $balanceSummary['planUsed'],
        );
        $pricing = $this->resolvePricing($balance, $featuresPayload, $productsPayload);

        return [
            'loaded' => $errors === [],
            'errors' => $errors,
            'pricing' => $pricing->toArray(),
            'balance' => [
                'remaining' => $balanceSummary['remaining'],
                'remainingFormatted' => $balanceSummary['remainingFormatted'],
                'remainingUnits' => $balanceSummary['remainingUnits'],
                'total' => $balanceSummary['total'],
                'totalFormatted' => $balanceSummary['totalFormatted'],
                'used' => max(0.0, $stats['creditsUsed']),
                'usedFormatted' => $stats['creditsUsedFormatted'],
                'percentLeft' => $balanceSummary['percentLeft'],
                'free' => $balanceSummary['free'],
                'paid' => $balanceSummary['paid'],
                'planUsed' => $balanceSummary['planUsed'],
                'planTotal' => $balanceSummary['planTotal'],
            ],
            'plan' => $this->normalizePlan($plan, $balanceSummary['credits']),
            'stats' => $stats,
            'products' => $this->normalizeProducts($productsPayload, $returnUrl),
            'features' => $this->normalizeFeatures($featuresPayload),
            'transactions' => $this->normalizeTransactions($receipts),
            'currentPlanSku' => (string) ($productsPayload['current_plan_sku'] ?? $plan['plan_sku'] ?? $plan['sku'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $balance
     * @param array<string, mixed> $plan
     * @return array{
     *   remainingUnits: int,
     *   remaining: float,
     *   remainingFormatted: string,
     *   total: float,
     *   totalFormatted: string,
     *   percentLeft: int,
     *   free: float,
     *   paid: float,
     *   planUsed: float,
     *   planTotal: float,
     *   credits: array<string, mixed>
     * }
     */
    public function summarizeBalance(array $balance, array $plan = []): array
    {
        $credits = is_array($balance['credits'] ?? null) ? $balance['credits'] : $balance;
        $pricing = is_array($balance['pricing'] ?? null) ? $balance['pricing'] : [];
        $scale = AiCreditUnits::scaleFromPricing($pricing);
        $buckets = AiCreditUnits::parseBalanceBuckets(array_merge($credits, $plan), $scale);

        $remainingUnits = $buckets['availableUnits'];
        $remaining = $buckets['availableCredits'];

        $poolTotalUnits = $buckets['planTotalUnits'] > 0
            ? $buckets['planTotalUnits']
            : max($remainingUnits, $buckets['freeUnits'] + $buckets['paidUnits']);
        $poolTotal = $buckets['planTotalCredits'] > 0.0
            ? $buckets['planTotalCredits']
            : max($remaining, $buckets['freeCredits'] + $buckets['paidCredits']);
        if ($poolTotalUnits <= 0 && $remainingUnits > 0) {
            $poolTotalUnits = $remainingUnits;
            $poolTotal = $remaining;
        }
        if ($poolTotal <= 0.0 && $remaining > 0.0) {
            $poolTotal = $remaining;
            $poolTotalUnits = $remainingUnits;
        }

        $percentLeft = $poolTotalUnits > 0
            ? (int) round(($remainingUnits / $poolTotalUnits) * 100)
            : ($remainingUnits > 0 ? 100 : 0);

        return [
            'remainingUnits' => $remainingUnits,
            'remaining' => $remaining,
            'remainingFormatted' => AiCreditUnits::formatCredits($remaining),
            'total' => max($poolTotal, 0.001),
            'totalFormatted' => AiCreditUnits::formatCredits(max($poolTotal, 0.0)),
            'percentLeft' => $percentLeft,
            'free' => $buckets['freeCredits'],
            'paid' => $buckets['paidCredits'],
            'planUsed' => $buckets['planUsedCredits'],
            'planTotal' => $buckets['planTotalCredits'],
            'credits' => $credits,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyPrompt(): array
    {
        $pricing = CreditsPricing::default();

        return [
            'loaded' => false,
            'errors' => [],
            'balance' => [
                'remaining' => 0.0,
                'remainingFormatted' => '0',
                'remainingUnits' => 0,
                'total' => 0.0,
                'totalFormatted' => '0',
                'used' => 0.0,
                'usedFormatted' => '0',
                'percentLeft' => 100,
            ],
            'plan' => [],
            'stats' => [
                'creditsUsed' => 0.0,
                'creditsUsedFormatted' => '0',
                'dailyAverage' => 0.0,
                'dailyAverageFormatted' => '0',
                'estimatedDaysLeft' => null,
            ],
            'products' => [],
            'features' => [],
            'transactions' => [],
            'currentPlanSku' => '',
            'pricing' => $pricing->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $credits
     * @return array<string, mixed>
     */
    private function normalizePlan(array $plan, array $credits): array
    {
        $sku = strtolower((string) (
            $plan['plan_sku']
            ?? $plan['sku']
            ?? $credits['plan_sku']
            ?? $credits['sku']
            ?? ''
        ));
        $name = (string) ($plan['plan_name'] ?? $plan['title'] ?? $credits['plan_name'] ?? '');
        if ($name === '' && $sku !== '' && $sku !== 'none') {
            $name = ucfirst($sku);
        }

        $planActive = (bool) ($plan['plan_active'] ?? $credits['plan_active'] ?? false);
        if ($name === '' || strtolower($name) === 'none') {
            if (!$planActive || $sku === '' || $sku === 'none') {
                return $this->emptyPlanView();
            }
            $name = ucfirst($sku);
        }

        $scale = AiCreditUnits::scaleFromPricing(is_array($plan['pricing'] ?? null) ? $plan : $credits);
        $buckets = AiCreditUnits::parseBalanceBuckets(array_merge($credits, $plan), $scale);
        $total = $buckets['planTotalCredits'];
        $used = $buckets['planUsedCredits'];
        $remaining = max(0.0, $total - $used);

        return [
            'hasPlan' => 1,
            'name' => $name,
            'sku' => $sku !== '' && $sku !== 'none' ? $sku : '',
            'subtitle' => (string) ($plan['subtitle'] ?? ''),
            'purchasedAt' => (int) ($plan['plan_renewed_at'] ?? $plan['purchased_at'] ?? 0),
            'creditsTotal' => $total,
            'creditsTotalFormatted' => AiCreditUnits::formatCredits($total),
            'creditsUsed' => $used,
            'creditsUsedFormatted' => AiCreditUnits::formatCredits($used),
            'creditsRemaining' => $remaining,
            'creditsRemainingFormatted' => AiCreditUnits::formatCredits($remaining),
            'progressPercent' => $total > 0.0 ? (int) round(($used / $total) * 100) : 0,
            'expiresAt' => (int) ($plan['plan_expires_at'] ?? $credits['expires_at'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPlanView(): array
    {
        return [
            'hasPlan' => 0,
            'name' => '',
            'sku' => '',
            'subtitle' => '',
            'purchasedAt' => 0,
            'creditsTotal' => 0.0,
            'creditsTotalFormatted' => '0',
            'creditsUsed' => 0.0,
            'creditsUsedFormatted' => '0',
            'creditsRemaining' => 0.0,
            'creditsRemainingFormatted' => '0',
            'expiresAt' => 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $receipts
     * @return array{
     *   creditsUsed: float,
     *   creditsUsedFormatted: string,
     *   dailyAverage: float,
     *   dailyAverageFormatted: string,
     *   estimatedDaysLeft: int|null
     * }
     */
    private function buildUsageStats(array $receipts, int $remainingUnits, float $remaining, float $fallbackUsed): array
    {
        $now = time();
        $windowStart = $now - (7 * self::SECONDS_PER_DAY);
        $windowUnits = 0;
        $totalUnits = 0;

        foreach ($receipts as $receipt) {
            $parsed = AiCreditUnits::parseReceiptCost($receipt);
            $totalUnits += $parsed['units'];
            if ((int) ($receipt['crdate'] ?? 0) >= $windowStart) {
                $windowUnits += $parsed['units'];
            }
        }

        $creditsUsed = $totalUnits > 0
            ? AiCreditUnits::unitsToCredits($totalUnits)
            : $fallbackUsed;
        $dailyAverageUnits = (int) max(1, (int) round($windowUnits / 7));
        $dailyAverage = AiCreditUnits::unitsToCredits($dailyAverageUnits);
        $estimatedDaysLeft = $remainingUnits > 0 && $dailyAverageUnits > 0
            ? (int) max(1, (int) ceil($remainingUnits / $dailyAverageUnits))
            : null;

        return [
            'creditsUsed' => $creditsUsed,
            'creditsUsedFormatted' => AiCreditUnits::formatCredits($creditsUsed),
            'dailyAverage' => $dailyAverage,
            'dailyAverageFormatted' => AiCreditUnits::formatCredits($dailyAverage),
            'estimatedDaysLeft' => $estimatedDaysLeft,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function normalizeProducts(array $payload, string $returnUrl): array
    {
        $items = $payload['products'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $currentSku = (string) ($payload['current_plan_sku'] ?? '');
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((int) ($item['is_active'] ?? 1) === 0) {
                continue;
            }

            $sku = (string) ($item['sku'] ?? '');
            $checkoutUrl = (string) ($item['checkout_url'] ?? '');
            $badge = (string) ($item['badge'] ?? '');
            $normalized[] = [
                'sku' => $sku,
                'type' => (string) ($item['type'] ?? 'topup'),
                'title' => (string) ($item['title'] ?? $sku),
                'subtitle' => (string) ($item['subtitle'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'credits' => (int) ($item['credits'] ?? 0),
                'priceAmount' => (float) ($item['price_amount'] ?? 0),
                'priceCurrency' => (string) ($item['price_currency'] ?? $payload['currency_default'] ?? 'EUR'),
                'badge' => $badge,
                'badgeLabel' => $this->productBadgeLabel($badge),
                'features' => is_array($item['features'] ?? null) ? $item['features'] : [],
                'sortOrder' => (int) ($item['sort_order'] ?? 0),
                'checkoutUrl' => $this->checkoutUrlBuilder->normalize($checkoutUrl, $returnUrl),
                'checkoutEmbedUrl' => (string) ($item['checkout_embed_url'] ?? ''),
                'isCurrentPlan' => (int) ($currentSku !== '' && $sku === $currentSku),
                'isLastPurchased' => false,
            ];
        }

        usort(
            $normalized,
            static fn(array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder'],
        );

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function normalizeFeatures(array $payload): array
    {
        $features = $payload['features'] ?? $payload;
        if (!is_array($features)) {
            return [];
        }

        $rows = [];
        if (array_is_list($features)) {
            foreach ($features as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $rows[] = $this->normalizeFeatureRow($item);
            }
        } else {
            foreach ($features as $key => $value) {
                if (!is_array($value)) {
                    continue;
                }
                $rows[] = $this->normalizeFeatureRow($value + ['key' => (string) $key]);
            }
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => ($a['sort'] <=> $b['sort']) ?: strcmp($a['label'], $b['label']),
        );

        return $rows;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeFeatureRow(array $item): array
    {
        $key = (string) ($item['key'] ?? $item['feature_key'] ?? '');

        return [
            'key' => $key,
            'label' => (string) ($item['label'] ?? $item['title'] ?? $this->humanizeFeatureKey($key)),
            'defaultModel' => (string) ($item['default_model'] ?? ''),
            'defaultBackend' => (string) ($item['default_backend'] ?? ''),
            'sort' => (int) ($item['sort'] ?? $item['sort_order'] ?? 0),
            'description' => (string) ($item['description'] ?? ''),
        ];
    }

    /**
     * @param list<array<string, mixed>> $receipts
     * @return list<array<string, mixed>>
     */
    private function normalizeTransactions(array $receipts): array
    {
        $rows = [];
        foreach ($receipts as $receipt) {
            $parsed = AiCreditUnits::parseReceiptCost($receipt);
            if ($parsed['units'] <= 0) {
                continue;
            }
            $featureKey = (string) ($receipt['feature_key'] ?? '');
            $tokenStats = $this->tokenStatsFromReceiptExtra((string) ($receipt['extra'] ?? ''));
            $tokensTotal = $tokenStats['tokensTotal'];
            $tokensInput = $tokenStats['tokensInput'];
            $tokensOutput = $tokenStats['tokensOutput'];
            $model = (string) ($receipt['model'] ?? '');
            $detailParts = [];
            if ($model !== '') {
                $detailParts[] = $model;
            }
            if ($tokensTotal > 0) {
                $detailParts[] = $tokensTotal . ' tokens';
            } elseif ($tokensInput > 0) {
                $detailParts[] = $tokensInput . ' tokens in';
                if ($tokensOutput > 0) {
                    $detailParts[] = $tokensOutput . ' out';
                }
            }

            $rows[] = [
                'crdate' => (int) ($receipt['crdate'] ?? 0),
                'label' => $this->humanizeFeatureKey($featureKey),
                'detail' => implode(' · ', $detailParts),
                'credits' => -$parsed['credits'],
                'creditsFormatted' => AiCreditUnits::formatCredits($parsed['credits']),
                'tokensTotal' => $tokensTotal,
                'isCredit' => false,
            ];
        }

        return $rows;
    }

    private function humanizeFeatureKey(string $key): string
    {
        if ($key === '') {
            return 'AI request';
        }

        return ucfirst(str_replace('_', ' ', $key));
    }

    private function productBadgeLabel(string $badge): string
    {
        return match ($badge) {
            'popular' => 'Most Popular',
            'best_value' => 'Best Value',
            default => $badge !== '' ? ucfirst(str_replace('_', ' ', $badge)) : '',
        };
    }

    /**
     * @return array{tokensTotal: int, tokensInput: int, tokensOutput: int}
     */
    private function tokenStatsFromReceiptExtra(string $extraRaw): array
    {
        $tokensInput = 0;
        $tokensOutput = 0;
        $tokensTotal = 0;

        if ($extraRaw === '') {
            return [
                'tokensTotal' => 0,
                'tokensInput' => 0,
                'tokensOutput' => 0,
            ];
        }

        $decoded = json_decode($extraRaw, true);
        if (!is_array($decoded)) {
            return [
                'tokensTotal' => 0,
                'tokensInput' => 0,
                'tokensOutput' => 0,
            ];
        }

        $tokensInput = (int) ($decoded['tokens_input'] ?? 0);
        $tokensOutput = (int) ($decoded['tokens_output'] ?? 0);
        $charged = is_array($decoded['charged'] ?? null) ? $decoded['charged'] : [];
        $tokensTotal = (int) (
            $decoded['tokens_total']
            ?? $charged['tokens_total']
            ?? 0
        );
        if ($tokensTotal <= 0 && ($tokensInput > 0 || $tokensOutput > 0)) {
            $tokensTotal = $tokensInput + $tokensOutput;
        }

        return [
            'tokensTotal' => $tokensTotal,
            'tokensInput' => $tokensInput,
            'tokensOutput' => $tokensOutput,
        ];
    }

    /**
     * @param array<string, mixed> $balance
     * @param array<string, mixed> $featuresPayload
     * @param array<string, mixed> $productsPayload
     */
    private function resolvePricing(array $balance, array $featuresPayload, array $productsPayload): CreditsPricing
    {
        foreach ([$balance, $featuresPayload, $productsPayload] as $payload) {
            if (isset($payload['pricing']) && is_array($payload['pricing'])) {
                return CreditsPricing::fromArray($payload);
            }
        }

        return CreditsPricing::default();
    }
}
