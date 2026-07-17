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

namespace NITSAN\NsT3AF\Tests\Unit\Governance;

use NITSAN\NsT3AF\Domain\Repository\UsageBudgetRepository;
use NITSAN\NsT3AF\Governance\BudgetService;
use PHPUnit\Framework\TestCase;

final class BudgetServiceTest extends TestCase
{
    public function testAllLimitsMissingMeansUnlimited(): void
    {
        $service = new BudgetService($this->makeRepository(requests: 999999, tokens: 999999, cost: 999999.0));

        $result = $service->checkBudget(1, []);

        self::assertTrue($result->allowed);
    }

    public function testNegativeLimitMeansUnlimited(): void
    {
        $service = new BudgetService($this->makeRepository(requests: 500));

        $result = $service->checkBudget(1, ['maxRequests' => '-1']);

        self::assertTrue($result->allowed);
    }

    /**
     * CM-04: an explicit budget of 0 blocks all requests — it is not
     * coerced to "unlimited".
     */
    public function testZeroRequestBudgetBlocksAll(): void
    {
        $service = new BudgetService($this->makeRepository(requests: 0));

        $result = $service->checkBudget(1, ['maxRequests' => '0']);

        self::assertFalse($result->allowed);
        self::assertStringContainsString('Request budget exceeded', $result->reason);
    }

    public function testZeroTokenBudgetBlocksAll(): void
    {
        $service = new BudgetService($this->makeRepository(tokens: 0));

        $result = $service->checkBudget(1, ['maxTokens' => '0']);

        self::assertFalse($result->allowed);
        self::assertStringContainsString('Token budget exceeded', $result->reason);
    }

    public function testZeroCostBudgetBlocksAll(): void
    {
        $service = new BudgetService($this->makeRepository(cost: 0.0));

        $result = $service->checkBudget(1, ['maxCost' => '0']);

        self::assertFalse($result->allowed);
        self::assertStringContainsString('Cost budget exceeded', $result->reason);
    }

    public function testLimitOfNPermitsUsageBelowN(): void
    {
        $service = new BudgetService($this->makeRepository(requests: 9));

        $result = $service->checkBudget(1, ['maxRequests' => '10']);

        self::assertTrue($result->allowed);
    }

    public function testLimitOfNDeniesAtExactlyN(): void
    {
        $service = new BudgetService($this->makeRepository(requests: 10));

        $result = $service->checkBudget(1, ['maxRequests' => '10']);

        self::assertFalse($result->allowed);
    }

    public function testAnonymousUserIsAlwaysAllowed(): void
    {
        $service = new BudgetService($this->makeRepository(requests: 0));

        $result = $service->checkBudget(0, ['maxRequests' => '0']);

        self::assertTrue($result->allowed);
    }

    private function makeRepository(int $requests = 0, int $tokens = 0, float $cost = 0.0): UsageBudgetRepository
    {
        return new class ($requests, $tokens, $cost) extends UsageBudgetRepository {
            public function __construct(
                private readonly int $requests,
                private readonly int $tokens,
                private readonly float $cost,
            ) {}

            public function getCurrentUsage(int $userId, string $periodType): array
            {
                return [
                    'tokens_used' => $this->tokens,
                    'cost_used' => $this->cost,
                    'requests_used' => $this->requests,
                ];
            }
        };
    }
}
