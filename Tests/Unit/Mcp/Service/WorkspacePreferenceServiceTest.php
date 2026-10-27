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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use Doctrine\DBAL\Result;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use NITSAN\NsT3AF\Mcp\Service\WorkspacePreferenceService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

/**
 * @internal
 */
final class WorkspacePreferenceServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalBeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBeUser = $GLOBALS['BE_USER'] ?? [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['BE_USER'] = $this->originalBeUser;
        parent::tearDown();
    }

    #[Test]
    public function getForCurrentUserReturnsStoredPreferenceWhenWorkspaceExists(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->uc = [WorkspacePreferenceService::UC_KEY => 3];
        $backendUser->user = ['uid' => 1];
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new WorkspacePreferenceService(
            $this->createMock(ConnectionPool::class),
            $this->workspaceList([
                ['uid' => 0, 'title' => 'Live'],
                ['uid' => 3, 'title' => 'MCP Workspace for admin'],
            ]),
        );

        self::assertSame(3, $service->getForCurrentUser());
    }

    #[Test]
    public function getForCurrentUserFallsBackToLiveForUnknownWorkspace(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->uc = [WorkspacePreferenceService::UC_KEY => 99];
        $backendUser->user = ['uid' => 1];
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new WorkspacePreferenceService(
            $this->createMock(ConnectionPool::class),
            $this->workspaceList([
                ['uid' => 0, 'title' => 'Live'],
                ['uid' => 3, 'title' => 'MCP Workspace for admin'],
            ]),
        );

        self::assertSame(0, $service->getForCurrentUser());
    }

    #[Test]
    public function saveForUserMergesMcpKeyIntoExistingUcBlob(): void
    {
        $existingUc = ['lang' => 'de', 'backend' => ['layout' => 'default']];
        $serialized = serialize($existingUc);

        $selectResult = $this->createMock(Result::class);
        $selectResult->method('fetchAssociative')->willReturn(['uc' => $serialized]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $restrictions->method('removeAll')->willReturnSelf();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn(':uid');
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('executeQuery')->willReturn($selectResult);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'be_users',
                self::callback(static function (array $fields): bool {
                    $uc = unserialize((string) ($fields['uc'] ?? ''), ['allowed_classes' => false]);
                    if (!is_array($uc)) {
                        return false;
                    }

                    return ($uc['lang'] ?? null) === 'de'
                        && ($uc['backend']['layout'] ?? null) === 'default'
                        && ($uc[WorkspacePreferenceService::UC_KEY] ?? null) === 3;
                }),
                ['uid' => 5],
            );

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $service = new WorkspacePreferenceService(
            $pool,
            $this->workspaceList([
                ['uid' => 0, 'title' => 'Live'],
                ['uid' => 3, 'title' => 'Draft'],
            ]),
        );

        self::assertSame(3, $service->saveForUser(5, 3));
    }

    /** @param list<array{uid: int, title: string}> $workspaces */
    private function workspaceList(array $workspaces): WorkspaceListService
    {
        $service = $this->createMock(WorkspaceListService::class);
        $service->method('list')->willReturn($workspaces);

        return $service;
    }
}
