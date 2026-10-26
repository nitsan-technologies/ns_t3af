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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Api\AiCreditUnits;

/**
 * Formats the T3Planet credit balance for the backend toolbar and overview line.
 *
 * @internal
 */
final class CreditOverviewLineService
{
    public function __construct(
        private readonly CreditModeResolver $creditModeResolver,
        private readonly BalanceService $balanceService,
        private readonly CurrentPlanService $currentPlanService,
        private readonly ProductCatalogService $productCatalogService,
        private readonly CreditsDashboardAssembler $dashboardAssembler,
        private readonly LocalReceiptCache $localReceiptCache,
    ) {}

    public function resolve(): string
    {
        $badge = $this->resolveBadge();
        if ($badge === null) {
            return '';
        }

        return $badge['creditsLabel'];
    }

    /**
     * @return array{
     *   creditsLabel: string,
     *   usedFormatted: string,
     *   totalFormatted: string,
     *   remainingFormatted: string,
     *   percentLeft: int,
     *   level: string
     * }|null
     */
    public function resolveBadge(): ?array
    {
        $status = $this->resolveStatus();

        return is_array($status['badge'] ?? null) ? $status['badge'] : null;
    }

    /**
     * Like {@see resolveBadge()}, but tells an empty balance apart from an unavailable one.
     *
     * @return array{badge: array{creditsLabel: string, usedFormatted: string, totalFormatted: string, remainingFormatted: string, percentLeft: int, level: string}|null, empty: bool}|null
     *         null = credits mode off or balance not available
     */
    public function resolveStatus(): ?array
    {
        if (!$this->creditModeResolver->isActive()) {
            return null;
        }

        try {
            $balance = $this->balanceService->fetch();
        } catch (\Throwable) {
            return null;
        }

        try {
            $plan = $this->currentPlanService->fetch();
        } catch (\Throwable) {
            $plan = [];
        }

        try {
            $products = $this->productCatalogService->fetch('');
        } catch (\Throwable) {
            $products = [];
        }

        $summary = $this->dashboardAssembler->summarizeBalance($balance, $plan, $products);

        if ($summary['remainingUnits'] <= 0 && $summary['remaining'] <= 0.0) {
            return ['badge' => null, 'empty' => true];
        }

        $remaining = (float) $summary['remaining'];
        $lifetimeUsed = 0.0;
        if ($summary['isTrial'] ?? false) {
            $lifetimeUnits = (int) array_sum($this->localReceiptCache->sumCostUnitsByFeatureKey());
            $lifetimeUsed = $lifetimeUnits > 0 ? AiCreditUnits::unitsToCredits($lifetimeUnits) : 0.0;
        }

        $badgeSummary = $summary;
        $used = max(0.0, (float) $summary['total'] - $remaining);
        if ($summary['isTrial'] ?? false) {
            $total = $this->dashboardAssembler->applyTrialLifetimeUsage(
                [
                    'remaining' => $remaining,
                    'total' => (float) $summary['total'],
                ],
                ['isTrial' => 1],
                $products,
                $lifetimeUsed,
            )[0]['total'];
            $used = $lifetimeUsed;
            $percentLeft = $total > 0.0
                ? max(0, min(100, (int) round(($remaining / $total) * 100)))
                : 0;
            $badgeSummary = array_merge($summary, [
                'total' => $total,
                'totalFormatted' => AiCreditUnits::formatCredits($total),
                'percentLeft' => $percentLeft,
            ]);
        } else {
            $percentLeft = max(0, min(100, (int) $summary['percentLeft']));
        }

        $usedFormatted = AiCreditUnits::formatCredits($used);

        return ['badge' => $this->mapSummaryToBadge($badgeSummary, $used, $usedFormatted, $percentLeft), 'empty' => false];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array{
     *   creditsLabel: string,
     *   usedFormatted: string,
     *   totalFormatted: string,
     *   remainingFormatted: string,
     *   percentLeft: int,
     *   level: string
     * }
     */
    private function mapSummaryToBadge(
        array $summary,
        float $used,
        string $usedFormatted,
        int $percentLeft,
    ): array {
        $totalFormatted = (string) ($summary['totalFormatted'] ?? '');

        return [
            'creditsLabel' => $usedFormatted . '/' . $totalFormatted . ' cr',
            'usedFormatted' => $usedFormatted,
            'totalFormatted' => $totalFormatted,
            'remainingFormatted' => (string) ($summary['remainingFormatted'] ?? ''),
            'percentLeft' => $percentLeft,
            'level' => $this->resolveLevel($percentLeft),
        ];
    }

    private function resolveLevel(int $percentLeft): string
    {
        if ($percentLeft <= 10) {
            return 'critical';
        }
        if ($percentLeft <= 40) {
            return 'low';
        }

        return 'healthy';
    }
}
