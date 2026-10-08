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

namespace NITSAN\NsT3AF\Tests\Unit\Utility;

use NITSAN\NsT3AF\Utility\ModuleTabUtility;
use PHPUnit\Framework\TestCase;

final class ModuleTabUtilityTest extends TestCase
{
    private ModuleTabUtility $utility;

    protected function setUp(): void
    {
        $this->utility = new ModuleTabUtility();
    }

    public function testRouteForDashboardPointsAtDedicatedOverviewRoute(): void
    {
        self::assertSame('t3af_dashboard.overview', $this->utility->routeFor('dashboard'));
    }

    public function testRouteForOtherKnownTabs(): void
    {
        self::assertSame('t3af_dashboard.providers', $this->utility->routeFor('providers'));
        self::assertSame('t3af_dashboard.mcp_server', $this->utility->routeFor('mcpServer'));
        self::assertSame('t3af_dashboard.scheduler_cli', $this->utility->routeFor('schedulerCli'));
        self::assertSame('t3af_dashboard.ai_logs', $this->utility->routeFor('aiLogs'));
    }

    public function testRouteForUnknownTabReturnsNull(): void
    {
        self::assertNull($this->utility->routeFor('buyCredits'));
        self::assertNull($this->utility->routeFor(''));
        self::assertNull($this->utility->routeFor('does-not-exist'));
    }

    public function testIsPersistableTabReflectsTabRegistry(): void
    {
        self::assertTrue($this->utility->isPersistableTab('dashboard'));
        self::assertTrue($this->utility->isPersistableTab('providers'));
        self::assertTrue($this->utility->isPersistableTab('schedulerCli'));
        self::assertTrue($this->utility->isPersistableTab('aiLogs'));
        self::assertFalse($this->utility->isPersistableTab('buyCredits'));
        self::assertFalse($this->utility->isPersistableTab(''));
    }

    public function testBuildVisibleTabsProducesCleanHrefsWithoutQueryString(): void
    {
        $buildUri = static fn(string $route): string => '/typo3/module/' . str_replace('.', '/', $route);

        $tabs = $this->utility->buildVisibleTabs(
            'providers',
            static fn(string $key): string => $key,
            $buildUri,
        );

        self::assertArrayHasKey('dashboard', $tabs);
        self::assertArrayHasKey('providers', $tabs);
        self::assertSame('/typo3/module/t3af_dashboard/overview', $tabs['dashboard']['href']);
        self::assertSame('/typo3/module/t3af_dashboard/providers', $tabs['providers']['href']);
        self::assertStringNotContainsString('?', $tabs['dashboard']['href']);
        self::assertStringNotContainsString('?', $tabs['providers']['href']);
        self::assertTrue($tabs['providers']['active']);
        self::assertFalse($tabs['dashboard']['active']);
    }

    public function testBuildNavigationTabGroupsSeparatesUtilityTabs(): void
    {
        $buildUri = static fn(string $route): string => '/typo3/module/' . str_replace('.', '/', $route);

        $groups = $this->utility->buildNavigationTabGroups(
            'aiLogs',
            static fn(string $key): string => $key,
            $buildUri,
        );

        self::assertArrayHasKey('primary', $groups);
        self::assertArrayHasKey('utility', $groups);
        self::assertArrayHasKey('dashboard', $groups['primary']);
        self::assertArrayHasKey('schedulerCli', $groups['primary']);
        self::assertArrayNotHasKey('aiUsage', $groups['primary']);
        self::assertArrayNotHasKey('aiLogs', $groups['primary']);
        self::assertCount(2, $groups['utility']);
        self::assertSame(['aiUsage', 'aiLogs'], array_keys($groups['utility']));
        self::assertTrue($groups['utility']['aiLogs']['active']);
        self::assertFalse($groups['utility']['aiUsage']['active']);
    }

    public function testFirstVisibleNonDashboardTabRouteSkipsDashboard(): void
    {
        $user = $this->createUserWithTabs('nst3af_tab:providers,nst3af_tab:ai_logs');

        self::assertSame(
            't3af_dashboard.providers',
            $this->utility->firstVisibleNonDashboardTabRoute($user),
        );
    }

    public function testBuildNavigationTabGroupsCollapsesMcpTabsIntoOneEntry(): void
    {
        $groups = $this->utility->buildNavigationTabGroups(
            'providers',
            static fn(string $key): string => $key,
            $this->buildUri(...),
        );

        $primaryKeys = array_keys($groups['primary']);
        self::assertContains('mcp', $primaryKeys);
        self::assertNotContains('mcpServer', $primaryKeys);
        self::assertNotContains('mcpTools', $primaryKeys);
        self::assertSame(array_search('aiAgent', $primaryKeys, true) + 1, array_search('mcp', $primaryKeys, true));
        self::assertSame('module.menu.mcp', $groups['primary']['mcp']['title']);
        self::assertSame('/typo3/module/t3af_dashboard/mcp_server', $groups['primary']['mcp']['href']);
        self::assertFalse($groups['primary']['mcp']['active']);
    }

    public function testMcpEntryIsActiveOnEitherMcpPage(): void
    {
        foreach (['mcpServer', 'mcpTools'] as $active) {
            $groups = $this->utility->buildNavigationTabGroups(
                $active,
                static fn(string $key): string => $key,
                $this->buildUri(...),
            );

            self::assertTrue($groups['primary']['mcp']['active'], $active);
            self::assertFalse($groups['primary']['providers']['active'], $active);
        }
    }

    public function testMcpEntryLinksToToolsWhenOnlyToolsIsGranted(): void
    {
        $user = $this->createUserWithTabs('nst3af_tab:providers,nst3af_tab:mcp_tools');

        $groups = $this->utility->buildNavigationTabGroups(
            'mcpTools',
            static fn(string $key): string => $key,
            $this->buildUri(...),
            $user,
        );

        self::assertSame('/typo3/module/t3af_dashboard/mcp_tools', $groups['primary']['mcp']['href']);
        self::assertTrue($groups['primary']['mcp']['active']);

        $sections = $this->utility->buildGroupSections(
            'mcpTools',
            static fn(string $key): string => $key,
            $this->buildUri(...),
            $user,
        );
        self::assertCount(1, $sections);
        self::assertSame('mcpTools', $sections[0]['key']);
    }

    public function testMcpEntryIsOmittedWhenNoMcpTabIsGranted(): void
    {
        $user = $this->createUserWithTabs('nst3af_tab:providers');

        $groups = $this->utility->buildNavigationTabGroups(
            'providers',
            static fn(string $key): string => $key,
            $this->buildUri(...),
            $user,
        );

        self::assertArrayNotHasKey('mcp', $groups['primary']);
    }

    public function testBuildGroupSectionsListsMcpPagesWithActiveFlag(): void
    {
        $sections = $this->utility->buildGroupSections(
            'mcpTools',
            static fn(string $key): string => $key,
            $this->buildUri(...),
        );

        self::assertSame(['mcpServer', 'mcpTools'], array_column($sections, 'key'));
        self::assertSame('module.menu.mcpServer', $sections[0]['title']);
        self::assertSame('module.mcpServer.intro', $sections[0]['intro']);
        self::assertSame('/typo3/module/t3af_dashboard/mcp_server', $sections[0]['href']);
        self::assertFalse($sections[0]['active']);
        self::assertTrue($sections[1]['active']);
    }

    public function testBuildGroupSectionsIsEmptyForUngroupedTab(): void
    {
        self::assertSame([], $this->utility->buildGroupSections(
            'providers',
            static fn(string $key): string => $key,
            $this->buildUri(...),
        ));
    }

    private function buildUri(string $route): string
    {
        return '/typo3/module/' . str_replace('.', '/', $route);
    }

    private function createUserWithTabs(string $customOptions): \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
    {
        $user = $this->createMock(\TYPO3\CMS\Core\Authentication\BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->groupData = ['custom_options' => $customOptions];
        $user->method('check')->willReturnCallback(
            static function (string $type, string $value) use ($user): bool {
                if ($type === 'custom_options') {
                    return str_contains((string) ($user->groupData['custom_options'] ?? ''), $value);
                }

                return false;
            },
        );

        return $user;
    }
}
