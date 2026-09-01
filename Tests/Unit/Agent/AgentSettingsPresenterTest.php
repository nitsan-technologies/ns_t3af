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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use Doctrine\DBAL\Result;
use NITSAN\NsT3AF\Access\ExtensionAvailability;
use NITSAN\NsT3AF\Agent\Entitlement\EntitlementResolver;
use NITSAN\NsT3AF\Agent\Service\AgentDemandCounter;
use NITSAN\NsT3AF\Agent\Service\AgentGovernanceGuard;
use NITSAN\NsT3AF\Agent\Service\AgentSettingsPresenter;
use NITSAN\NsT3AF\Agent\Service\AgentTurnRepository;
use NITSAN\NsT3AF\Contract\ExtensionOperationalStatusInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

/**
 * @internal
 */
final class AgentSettingsPresenterTest extends TestCase
{
    #[Test]
    public function buildExposesOverviewGovernanceAndAuditKeys(): void
    {
        $toolIntrospector = $this->createStub(McpToolIntrospectorService::class);
        $toolIntrospector->method('listTools')->willReturn([
            [
                'name' => 'pages_get',
                'description' => 'Read page',
                'severity' => ToolSeverity::Read->value,
                'ownerExtensionKey' => 'ns_t3af',
            ],
            [
                'name' => 'pages_update',
                'description' => 'Write page',
                'severity' => ToolSeverity::Write->value,
                'ownerExtensionKey' => 'definitely_not_an_extension_key_xyz',
            ],
        ]);

        $provider = new class implements ExtensionOperationalStatusInterface {
            public function extensionKey(): string
            {
                return 'ns_t3af';
            }

            public function isOperational(): bool
            {
                return true;
            }

            public function toolCount(): int
            {
                return 3;
            }
        };
        $entitlementResolver = new EntitlementResolver([$provider], new ExtensionAvailability());

        $connectionPool = $this->createConnectionPoolStub();
        $demandCounter = new AgentDemandCounter($connectionPool);
        $turnRepository = new AgentTurnRepository($connectionPool);

        $presenter = new AgentSettingsPresenter(
            $toolIntrospector,
            $entitlementResolver,
            $demandCounter,
            $turnRepository,
            $connectionPool,
        );

        $viewModel = $presenter->build();

        self::assertSame(2, $viewModel['overview']['totalTools']);
        self::assertSame(2, $viewModel['overview']['ownerCount']);
        self::assertGreaterThanOrEqual(1, $viewModel['overview']['executableTools']);
        self::assertGreaterThanOrEqual(0, $viewModel['overview']['lockedTools']);
        self::assertCount(3, $viewModel['overview']['availability']);
        self::assertCount(4, $viewModel['severityPolicyTable']);
        self::assertCount(6, $viewModel['governanceMatrix']);
        self::assertCount(4, $viewModel['audit']['coverage']);
        self::assertSame(
            AgentGovernanceGuard::TURN_GUARD_WARN,
            (int) $viewModel['governanceMatrix'][5]['effectArguments'][0],
        );
    }

    private function createConnectionPoolStub(): ConnectionPool
    {
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();

        $queryResult = $this->createStub(Result::class);
        $queryResult->method('fetchAllAssociative')->willReturn([]);
        $queryResult->method('fetchOne')->willReturn(0);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn('param');
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('executeQuery')->willReturn($queryResult);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }
}
