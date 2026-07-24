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

namespace NITSAN\NsT3AF\Tests\Functional\Governance;

use NITSAN\NsT3AF\Domain\Repository\UsageBudgetRepository;
use NITSAN\NsT3AF\Governance\BudgetService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TC-04: budget counters persist and enforce limits against real schema.
 */
final class UsageBudgetFunctionalTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'frontend',
        'workspaces',
        'scheduler',
        'extensionmanager',
    ];

    protected array $testExtensionsToLoad = [
        'ns_license',
        'ns_t3af',
    ];

    #[Test]
    public function recordUsageIncrementsCountersAndZeroBudgetBlocks(): void
    {
        $repository = $this->get(UsageBudgetRepository::class);
        $service = new BudgetService($repository);

        $userId = 42;

        $before = $repository->getCurrentUsage($userId, 'monthly');
        self::assertSame(0, $before['requests_used']);
        self::assertSame(0, $before['tokens_used']);
        self::assertSame(0.0, $before['cost_used']);

        $repository->recordUsage($userId, 'monthly', 100, 0.05);
        $after = $repository->getCurrentUsage($userId, 'monthly');
        self::assertSame(1, $after['requests_used']);
        self::assertSame(100, $after['tokens_used']);
        self::assertEqualsWithDelta(0.05, $after['cost_used'], 0.0001);

        $blocked = $service->checkBudget($userId, [
            'maxRequests' => '0',
            'period' => 'monthly',
        ]);
        self::assertFalse($blocked->allowed);
        self::assertStringContainsString('Request budget exceeded', (string) $blocked->reason);

        $allowed = $service->checkBudget($userId, [
            'maxRequests' => '5',
            'period' => 'monthly',
        ]);
        self::assertTrue($allowed->allowed);
    }
}
