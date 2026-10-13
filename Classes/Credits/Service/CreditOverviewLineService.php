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
            return null;
        }

        $remaining = (float) $summary['remaining'];
        $total = (float) $summary['total'];
        $used = max(0.0, $total - $remaining);
        $usedFormatted = AiCreditUnits::formatCredits($used);
        $percentLeft = max(0, min(100, (int) $summary['percentLeft']));

        return $this->mapSummaryToBadge($summary, $used, $usedFormatted, $percentLeft);
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
