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
        private readonly CreditsDashboardAssembler $dashboardAssembler,
    ) {}

    public function resolve(): string
    {
        $badge = $this->resolveBadge();
        if ($badge === null) {
            return '';
        }

        $line = $badge['creditsLabel'];
        if ($badge['percentLeft'] > 0) {
            $line .= ' · ' . $badge['percentLeft'] . '%';
        }

        return $line;
    }

    /**
     * @return array{creditsLabel: string, percentLeft: int, showPercent: int, level: string}|null
     */
    public function resolveBadge(): ?array
    {
        if (!$this->creditModeResolver->isActive()) {
            return null;
        }

        try {
            $summary = $this->dashboardAssembler->summarizeBalance($this->balanceService->fetch());
        } catch (\Throwable) {
            return null;
        }

        if ($summary['remainingUnits'] <= 0 && $summary['remaining'] <= 0.0) {
            return null;
        }

        $percentLeft = max(0, min(100, (int) $summary['percentLeft']));

        return [
            'creditsLabel' => $summary['remainingFormatted'] . ' cr',
            'percentLeft' => $percentLeft,
            'showPercent' => $percentLeft > 0 ? 1 : 0,
            'level' => $this->resolveLevel($percentLeft),
        ];
    }

    private function resolveLevel(int $percentLeft): string
    {
        if ($percentLeft <= 20) {
            return 'critical';
        }
        if ($percentLeft <= 50) {
            return 'low';
        }

        return 'healthy';
    }
}
