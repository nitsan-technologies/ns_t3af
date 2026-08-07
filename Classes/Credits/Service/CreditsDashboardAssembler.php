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
use NITSAN\NsT3AF\Credits\CreditsReceiptEntryType;

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
     * @param array<string, int> $usedUnitsByFeatureKey Lifetime cost_units per catalog feature_key (full local receipt history).
     * @param int|null $statsLifetimeUnits When set, usage stats use these unit totals instead of summing $receipts (pagination-safe).
     * @param int|null $statsWindowUnits 7-day window cost_units when $statsLifetimeUnits is set.
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
        array $usedUnitsByFeatureKey = [],
        ?int $statsLifetimeUnits = null,
        ?int $statsWindowUnits = null,
    ): array {
        $balanceSummary = $this->summarizeBalance($balance, $plan, $productsPayload);
        $stats = $this->buildUsageStats(
            $receipts,
            $balanceSummary['remainingUnits'],
            $balanceSummary['remaining'],
            $balanceSummary['planUsed'],
            $statsLifetimeUnits,
            $statsWindowUnits,
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
            'plan' => $this->normalizePlan(
                $plan,
                $balanceSummary['credits'],
                $productsPayload,
                $balanceSummary,
            ),
            'stats' => $stats,
            'products' => $this->normalizeProducts($productsPayload, $returnUrl),
            'features' => $this->normalizeFeatures($featuresPayload, $usedUnitsByFeatureKey),
            'transactions' => $this->normalizeTransactions($receipts),
            'currentPlanSku' => (string) ($productsPayload['current_plan_sku'] ?? $plan['plan_sku'] ?? $plan['sku'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $balance
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $productsPayload
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
    public function summarizeBalance(array $balance, array $plan = [], array $productsPayload = []): array
    {
        $credits = is_array($balance['credits'] ?? null) ? $balance['credits'] : $balance;
        $pricing = is_array($balance['pricing'] ?? null) ? $balance['pricing'] : [];
        $scale = AiCreditUnits::scaleFromPricing($pricing);
        $buckets = AiCreditUnits::parseBalanceBuckets(array_merge($credits, $plan), $scale);

        $remainingUnits = $buckets['availableUnits'];
        $remaining = $buckets['availableCredits'];

        $sku = strtolower((string) (
            $plan['plan_sku']
            ?? $plan['sku']
            ?? $credits['plan_sku']
            ?? $credits['sku']
            ?? ''
        ));
        $currentSku = strtolower((string) ($productsPayload['current_plan_sku'] ?? ''));

        if ($buckets['planTotalCredits'] > 0.0) {
            $poolTotal = $buckets['planTotalCredits'];
            $poolTotalUnits = $buckets['planTotalUnits'];
        } elseif ($this->isTrialAccount($plan, $credits, $sku, $currentSku, $buckets)) {
            $poolTotal = $this->resolveTrialCreditsTotal($productsPayload, $remaining);
            $poolTotalUnits = AiCreditUnits::creditsToUnits($poolTotal, $scale);
        } else {
            $poolTotalUnits = max($remainingUnits, $buckets['freeUnits'] + $buckets['paidUnits']);
            $poolTotal = max($remaining, $buckets['freeCredits'] + $buckets['paidCredits']);
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
            'transactionsTotalCount' => 0,
            'transactionsCurrentPage' => 1,
            'transactionsPerPage' => 20,
            'transactionsEntryType' => CreditsReceiptEntryType::ALL,
            'currentPlanSku' => '',
            'pricing' => $pricing->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $credits
     * @param array<string, mixed> $productsPayload
     * @param array<string, mixed> $balanceSummary
     * @return array<string, mixed>
     */
    private function normalizePlan(
        array $plan,
        array $credits,
        array $productsPayload = [],
        array $balanceSummary = [],
    ): array {
        $sku = strtolower((string) (
            $plan['plan_sku']
            ?? $plan['sku']
            ?? $credits['plan_sku']
            ?? $credits['sku']
            ?? ''
        ));
        $currentSku = strtolower((string) ($productsPayload['current_plan_sku'] ?? ''));
        $name = (string) ($plan['plan_name'] ?? $plan['title'] ?? $credits['plan_name'] ?? '');
        if ($name === '' && $sku !== '' && $sku !== 'none') {
            $name = ucfirst($sku);
        }

        $scale = AiCreditUnits::scaleFromPricing(is_array($plan['pricing'] ?? null) ? $plan : $credits);
        $buckets = AiCreditUnits::parseBalanceBuckets(array_merge($credits, $plan), $scale);
        $planActive = (bool) ($plan['plan_active'] ?? $credits['plan_active'] ?? false);
        $hasSubscriptionPlan = $planActive
            && $sku !== ''
            && $sku !== 'none'
            && $buckets['planTotalCredits'] > 0.0;

        if (!$hasSubscriptionPlan && $this->isTrialAccount($plan, $credits, $sku, $currentSku, $buckets)) {
            return $this->trialPlanView($plan, $credits, $productsPayload, $buckets);
        }

        if ($name === '' || strtolower($name) === 'none') {
            if (!$planActive || $sku === '' || $sku === 'none') {
                return $this->emptyPlanView();
            }
            $name = ucfirst($sku);
        }

        $total = $buckets['planTotalCredits'];
        $used = $buckets['planUsedCredits'];
        $remaining = max(0.0, $total - $used);

        return [
            'hasPlan' => 1,
            'isTrial' => 0,
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
            'expiresAt' => (int) ($plan['plan_expires_at'] ?? $credits['plan_expires_at'] ?? $credits['expires_at'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $credits
     * @param array<string, mixed> $productsPayload
     * @param array{
     *   freeCredits: float,
     *   planTotalCredits: float,
     *   planUsedCredits: float,
     *   paidCredits: float
     * } $buckets
     * @return array<string, mixed>
     */
    private function trialPlanView(
        array $plan,
        array $credits,
        array $productsPayload,
        array $buckets,
    ): array {
        $remaining = max(0.0, $buckets['freeCredits']);
        $total = $this->resolveTrialCreditsTotal($productsPayload, $remaining);
        $used = max(0.0, $total - $remaining);
        $trialProduct = $this->findTrialProduct($productsPayload);
        $name = (string) ($trialProduct['title'] ?? 'Free Trial');

        return [
            'hasPlan' => 1,
            'isTrial' => 1,
            'name' => $name,
            'sku' => 'trial',
            'subtitle' => (string) ($plan['subtitle'] ?? ''),
            'purchasedAt' => (int) ($plan['plan_renewed_at'] ?? $plan['purchased_at'] ?? $credits['crdate'] ?? 0),
            'creditsTotal' => $total,
            'creditsTotalFormatted' => AiCreditUnits::formatCredits($total),
            'creditsUsed' => $used,
            'creditsUsedFormatted' => AiCreditUnits::formatCredits($used),
            'creditsRemaining' => $remaining,
            'creditsRemainingFormatted' => AiCreditUnits::formatCredits($remaining),
            'progressPercent' => $total > 0.0 ? (int) round(($used / $total) * 100) : 0,
            'expiresAt' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $credits
     * @param array{
     *   freeCredits: float,
     *   planTotalCredits: float,
     *   paidCredits: float
     * } $buckets
     */
    private function isTrialAccount(
        array $plan,
        array $credits,
        string $sku,
        string $currentSku,
        array $buckets,
    ): bool {
        if ((bool) ($plan['trial_granted'] ?? $credits['trial_granted'] ?? false)) {
            return true;
        }

        if ($sku === 'trial' || $currentSku === 'trial') {
            return true;
        }

        return $buckets['freeCredits'] > 0.0
            && $buckets['planTotalCredits'] <= 0.0
            && $buckets['paidCredits'] <= 0.0
            && ($sku === '' || $sku === 'none');
    }

    /**
     * @param array<string, mixed> $productsPayload
     */
    private function resolveTrialCreditsTotal(array $productsPayload, float $remaining): float
    {
        $trialProduct = $this->findTrialProduct($productsPayload);
        $catalogCredits = (int) ($trialProduct['credits'] ?? 0);
        if ($catalogCredits > 0) {
            return (float) $catalogCredits;
        }

        return max($remaining, 1.0);
    }

    /**
     * @param array<string, mixed> $productsPayload
     * @return array<string, mixed>
     */
    private function findTrialProduct(array $productsPayload): array
    {
        $items = $productsPayload['products'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            $sku = (string) ($item['sku'] ?? '');
            if ($type === 'trial' || $sku === 'trial') {
                return $item;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPlanView(): array
    {
        return [
            'hasPlan' => 0,
            'isTrial' => 0,
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
    private function buildUsageStats(
        array $receipts,
        int $remainingUnits,
        float $remaining,
        float $fallbackUsed,
        ?int $statsLifetimeUnits = null,
        ?int $statsWindowUnits = null,
    ): array {
        if ($statsLifetimeUnits !== null) {
            $totalUnits = max(0, $statsLifetimeUnits);
            $windowUnits = max(0, $statsWindowUnits ?? 0);
        } else {
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
                'description' => $this->normalizeProductDescription($item['description'] ?? null),
                'credits' => (int) ($item['credits'] ?? 0),
                'priceAmount' => (float) ($item['price_amount'] ?? 0),
                'priceCurrency' => (string) ($item['price_currency'] ?? $payload['currency_default'] ?? 'EUR'),
                'badge' => $badge,
                'badgeLabel' => $this->productBadgeLabel($badge),
                'features' => is_array($item['features'] ?? null) ? $item['features'] : [],
                'sortOrder' => (int) ($item['sort_order'] ?? 0),
                'renewalPeriod' => (string) ($item['renewal_period'] ?? ''),
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
     * Products API returns description as string[] (server-side comma-split). Legacy string responses
     * are treated as a single bullet.
     *
     * @return list<string>
     */
    private function normalizeProductDescription(mixed $description): array
    {
        if (is_array($description)) {
            $lines = [];
            foreach ($description as $line) {
                if (!is_scalar($line) && $line !== null) {
                    continue;
                }
                $trimmed = trim((string) $line);
                if ($trimmed !== '') {
                    $lines[] = $trimmed;
                }
            }

            return $lines;
        }

        if (!is_scalar($description) && $description !== null) {
            return [];
        }

        $trimmed = trim((string) $description);

        return $trimmed !== '' ? [$trimmed] : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, int> $usedUnitsByFeatureKey
     * @return list<array<string, mixed>>
     */
    private function normalizeFeatures(array $payload, array $usedUnitsByFeatureKey = []): array
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
                $rows[] = $this->normalizeFeatureRow($item, $usedUnitsByFeatureKey);
            }
        } else {
            foreach ($features as $key => $value) {
                if (!is_array($value)) {
                    continue;
                }
                $rows[] = $this->normalizeFeatureRow($value + ['key' => (string) $key], $usedUnitsByFeatureKey);
            }
        }

        $maxUsed = 0.0;
        foreach ($rows as $row) {
            $maxUsed = max($maxUsed, (float) ($row['usedCredits'] ?? 0.0));
        }
        if ($maxUsed <= 0.0) {
            $maxUsed = 1.0;
        }
        foreach ($rows as &$row) {
            $used = (float) ($row['usedCredits'] ?? 0.0);
            $row['usedBarPercent'] = round(($used / $maxUsed) * 100, 1);
        }
        unset($row);

        usort(
            $rows,
            static fn(array $a, array $b): int => ($a['sort'] <=> $b['sort']) ?: strcmp($a['label'], $b['label']),
        );

        return $rows;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, int> $usedUnitsByFeatureKey
     * @return array<string, mixed>
     */
    private function normalizeFeatureRow(array $item, array $usedUnitsByFeatureKey = []): array
    {
        $key = (string) ($item['key'] ?? $item['feature_key'] ?? '');
        $usedUnits = (int) ($usedUnitsByFeatureKey[$key] ?? 0);
        $usedCredits = $usedUnits > 0 ? AiCreditUnits::unitsToCredits($usedUnits) : 0.0;

        return [
            'key' => $key,
            'label' => (string) ($item['label'] ?? $item['title'] ?? $this->humanizeFeatureKey($key)),
            'defaultModel' => (string) ($item['default_model'] ?? ''),
            'defaultBackend' => (string) ($item['default_backend'] ?? ''),
            'sort' => (int) ($item['sort'] ?? $item['sort_order'] ?? 0),
            'description' => trim((string) ($item['description'] ?? '')),
            'exampleCost' => trim((string) ($item['example_cost'] ?? '')),
            'usedCredits' => $usedCredits,
            'usedCreditsFormatted' => AiCreditUnits::formatCredits($usedCredits),
            'usedBarPercent' => 0.0,
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
            $model = (string) ($receipt['model'] ?? '');
            $entryType = CreditsReceiptEntryType::normalize(
                $receipt['entry_type'] ?? null,
                CreditsReceiptEntryType::DEBIT,
            );
            $isCredit = $entryType === CreditsReceiptEntryType::CREDIT;
            $detailParts = [];
            if ($model !== '') {
                $detailParts[] = $model;
            }

            $clientFields = $this->extractReceiptClientFields($receipt);

            $rows[] = [
                'crdate' => (int) ($receipt['crdate'] ?? 0),
                'label' => $this->humanizeFeatureKey($featureKey),
                'detail' => implode(' · ', $detailParts),
                'credits' => $isCredit ? $parsed['credits'] : -$parsed['credits'],
                'creditsFormatted' => AiCreditUnits::formatCredits($parsed['credits']),
                'entryType' => $entryType,
                'isCredit' => $isCredit,
                'extensionKey' => $clientFields['extensionKey'],
                'pageName' => $clientFields['pageName'],
                'latencyMs' => $clientFields['latencyMs'],
                'latencyFormatted' => $clientFields['latencyFormatted'],
                'status' => $clientFields['status'],
                'requestUuid' => $clientFields['requestUuid'],
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array{
     *     extensionKey: string,
     *     pageName: string,
     *     latencyMs: int,
     *     latencyFormatted: string,
     *     status: string,
     *     requestUuid: string
     * }
     */
    private function extractReceiptClientFields(array $receipt): array
    {
        $requestUuid = trim((string) ($receipt['request_uuid'] ?? ''));
        $extra = [];
        $rawExtra = $receipt['extra'] ?? null;
        if (is_string($rawExtra) && $rawExtra !== '') {
            $decoded = json_decode($rawExtra, true);
            if (is_array($decoded)) {
                $extra = $decoded;
            }
        } elseif (is_array($rawExtra)) {
            $extra = $rawExtra;
        }

        $client = is_array($extra['client'] ?? null) ? $extra['client'] : [];
        $metaJson = is_array($extra['meta_json'] ?? null) ? $extra['meta_json'] : [];

        $extensionKey = trim((string) (
            $client['extension_key']
            ?? $extra['extension_key']
            ?? $metaJson['extension_key']
            ?? ''
        ));

        $pageName = trim((string) ($client['page_title'] ?? $extra['page_title'] ?? ''));
        if ($pageName === '') {
            $pageId = (int) ($client['page_id'] ?? $metaJson['page_id'] ?? $extra['page_id'] ?? 0);
            if ($pageId > 0) {
                $pageName = 'Page ' . $pageId;
            }
        }

        $latencyMs = max(0, (int) ($client['latency_ms'] ?? $extra['latency_ms'] ?? 0));
        $latencyFormatted = $latencyMs > 0 ? $latencyMs . ' ms' : '';

        $entryType = CreditsReceiptEntryType::normalize(
            $receipt['entry_type'] ?? null,
            CreditsReceiptEntryType::DEBIT,
        );
        $status = trim((string) ($client['status'] ?? ''));
        if ($status === '') {
            if ($entryType === CreditsReceiptEntryType::CREDIT) {
                $status = 'credit';
            } elseif (array_key_exists('status', $extra) && is_bool($extra['status'])) {
                $status = $extra['status'] ? 'success' : 'failed';
            } elseif ($requestUuid !== '') {
                // Local debit mirror only stores successful settlements.
                $status = 'success';
            }
        }

        return [
            'extensionKey' => $extensionKey,
            'pageName' => $pageName,
            'latencyMs' => $latencyMs,
            'latencyFormatted' => $latencyFormatted,
            'status' => $status,
            'requestUuid' => $requestUuid,
        ];
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
