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

namespace NITSAN\NsT3AF\EventListener;

use NITSAN\NsT3AF\Event\AfterProviderResponseEvent;
use NITSAN\NsT3AF\Governance\BudgetService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Records token + cost usage against the current backend user's budget after a
 * successful provider response. Pairs with
 * {@see \NITSAN\NsT3AF\Governance\AccessControlListener} which enforces
 * the limits on the next request.
 *
 * No-op outside the backend (CLI / scheduler / frontend).
 *
 * @internal
 */
final class RecordBudgetUsageListener
{
    public function __construct(private readonly BudgetService $budgetService) {}

    public function __invoke(AfterProviderResponseEvent $event): void
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return;
        }
        $userId = (int) ($user->user['uid'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $period = $this->period($user);
        $response = $event->getResponse();

        $tokens = $this->totalTokens($response->tokensInput, $response->tokensOutput, $response->credits !== null ? $response->credits->tokensTotal : 0);
        $cost = $this->cost($event->provider->pricingInputPer1m, $event->provider->pricingOutputPer1m, $response);

        $this->budgetService->recordUsage($userId, $period, $tokens, $cost);
    }

    private function totalTokens(int $input, int $output, int $creditsTotal): int
    {
        if ($creditsTotal > 0) {
            return $creditsTotal;
        }

        return max(0, $input + $output);
    }

    private function cost(float $inputPer1m, float $outputPer1m, \NITSAN\NsT3AF\Api\AiResponse $response): float
    {
        $charged = $response->credits !== null ? $response->credits->charged : 0.0;
        if ($charged > 0.0) {
            return round($charged, 6);
        }

        $input = ((float) $response->tokensInput / 1000000) * $inputPer1m;
        $output = ((float) $response->tokensOutput / 1000000) * $outputPer1m;

        return round($input + $output, 6);
    }

    private function period(BackendUserAuthentication $user): string
    {
        $root = $user->getTSConfig()['nst3af.'] ?? null;
        $budget = is_array($root) ? ($root['budget.'] ?? null) : null;
        $period = is_array($budget) ? strtolower(trim((string) ($budget['period'] ?? 'monthly'))) : 'monthly';

        return in_array($period, ['daily', 'weekly', 'monthly'], true) ? $period : 'monthly';
    }
}
