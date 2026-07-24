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

namespace NITSAN\NsT3AF\Governance;

use NITSAN\NsT3AF\Domain\Repository\UsageBudgetRepository;

/**
 * Evaluates per-user budget limits from UserTSconfig against recorded usage.
 *
 * TSconfig keys (all optional):
 *   nst3af.budget.period      = daily | weekly | monthly   (default monthly)
 *   nst3af.budget.maxCost     = float   (in the provider's currency)
 *   nst3af.budget.maxTokens   = int
 *   nst3af.budget.maxRequests = int
 *
 * Limit semantics: a missing, empty, or negative value means "no limit" on
 * that axis; an explicit `0` means "block all" (deny every request).
 *
 * @internal
 */
final class BudgetService
{
    public function __construct(private readonly UsageBudgetRepository $repository) {}

    /**
     * @param array<string, scalar> $tsConfig Flattened `nst3af.budget.*` values.
     */
    public function checkBudget(int $userId, array $tsConfig): BudgetCheckResult
    {
        if ($userId <= 0) {
            return BudgetCheckResult::allowed();
        }

        $maxCost = $this->floatOrNull($tsConfig['maxCost'] ?? null);
        $maxTokens = $this->intOrNull($tsConfig['maxTokens'] ?? null);
        $maxRequests = $this->intOrNull($tsConfig['maxRequests'] ?? null);

        if ($maxCost === null && $maxTokens === null && $maxRequests === null) {
            return BudgetCheckResult::allowed();
        }

        $period = $this->period($tsConfig);
        $usage = $this->repository->getCurrentUsage($userId, $period);

        if ($maxRequests !== null && $usage['requests_used'] >= $maxRequests) {
            return BudgetCheckResult::denied(sprintf(
                'Request budget exceeded (%d/%d %s requests).',
                $usage['requests_used'],
                $maxRequests,
                $period,
            ));
        }

        if ($maxTokens !== null && $usage['tokens_used'] >= $maxTokens) {
            return BudgetCheckResult::denied(sprintf(
                'Token budget exceeded (%d/%d %s tokens).',
                $usage['tokens_used'],
                $maxTokens,
                $period,
            ));
        }

        if ($maxCost !== null && $usage['cost_used'] >= $maxCost) {
            return BudgetCheckResult::denied(sprintf(
                'Cost budget exceeded (%.4f/%.4f %s).',
                $usage['cost_used'],
                $maxCost,
                $period,
            ));
        }

        return BudgetCheckResult::allowed();
    }

    public function recordUsage(int $userId, string $period, int $tokens, float $cost): void
    {
        $this->repository->recordUsage($userId, $period, $tokens, $cost);
    }

    /**
     * @param array<string, scalar> $tsConfig
     */
    private function period(array $tsConfig): string
    {
        $period = strtolower(trim((string) ($tsConfig['period'] ?? 'monthly')));

        return in_array($period, ['daily', 'weekly', 'monthly'], true) ? $period : 'monthly';
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $float = (float) $value;

        // 0 is a real limit ("block all"); only negatives mean "no limit".
        return $float >= 0.0 ? $float : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $int = (int) $value;

        // 0 is a real limit ("block all"); only negatives mean "no limit".
        return $int >= 0 ? $int : null;
    }
}
