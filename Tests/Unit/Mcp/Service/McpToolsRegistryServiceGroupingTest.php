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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Contract\McpToolsExtensionCardDescriptor;
use NITSAN\NsT3AF\Contract\McpToolsExtensionCardProviderInterface;
use NITSAN\NsT3AF\Mcp\Repository\DiscoveredTableRepository;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpAnalyticsService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolLogRepository;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolMetadataService;
use NITSAN\NsT3AF\Mcp\Service\ExtensionTableDiscoveryService;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use NITSAN\NsT3AF\Mcp\Service\McpToolNameResolver;
use NITSAN\NsT3AF\Mcp\Service\McpToolOwnershipResolver;
use NITSAN\NsT3AF\Mcp\Service\McpToolsRegistryService;
use NITSAN\NsT3AF\Registry\McpToolsExtensionCardProviderRegistry;
use NITSAN\NsT3AF\Service\DashboardPeriodResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class McpToolsRegistryServiceGroupingTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['extensions']);
        parent::tearDown();
    }

    #[Test]
    public function getAiUniverseExtensionsGroupsToolsByOwnershipResolver(): void
    {
        $existingToolClass = 'NITSAN\\NsT3AF\\Mcp\\Tool\\Pages\\PagesListTool';
        $toolIntrospector = $this->createMock(McpToolIntrospectorService::class);
        $toolIntrospector->method('listTools')->willReturn([
            [
                'name' => 'pages_list',
                'description' => 'List pages',
                'params' => [],
                'className' => $existingToolClass,
                'ownerExtensionKey' => null,
            ],
            [
                'name' => 't3cs_list_datasources',
                'description' => 'List datasources',
                'params' => [],
                'className' => $existingToolClass,
                'ownerExtensionKey' => 'ns_t3cs',
            ],
            [
                'name' => 't3as_search',
                'description' => 'Search',
                'params' => [],
                'className' => $existingToolClass,
                'ownerExtensionKey' => 'ns_t3as',
            ],
        ]);

        $cardRegistry = new McpToolsExtensionCardProviderRegistry([
            new class implements McpToolsExtensionCardProviderInterface {
                public function isAvailable(): bool
                {
                    return true;
                }

                public function getExtensionKey(): string
                {
                    return 'ns_t3cs';
                }

                public function getCardDescriptor(): McpToolsExtensionCardDescriptor
                {
                    return new McpToolsExtensionCardDescriptor(
                        label: 'T3CS',
                        toolPrefix: 't3cs_',
                        sortPriority: 10,
                        showWhenNotLoaded: true,
                    );
                }
            },
            new class implements McpToolsExtensionCardProviderInterface {
                public function isAvailable(): bool
                {
                    return true;
                }

                public function getExtensionKey(): string
                {
                    return 'ns_t3as';
                }

                public function getCardDescriptor(): McpToolsExtensionCardDescriptor
                {
                    return new McpToolsExtensionCardDescriptor(
                        label: 'T3AS',
                        toolPrefix: 't3as_',
                        sortPriority: 20,
                        showWhenNotLoaded: true,
                    );
                }
            },
        ]);

        $metadataService = new McpToolMetadataService();
        $periodResolver = new DashboardPeriodResolver();

        $toolLogRepository = $this->createMock(McpToolLogRepository::class);
        $toolLogRepository->method('metricsForToolNames')->willReturn([
            'calls' => 0,
            'successRate' => 100.0,
            'avgLatencyMs' => 0.0,
        ]);
        $toolLogRepository->method('lastCalledTimestampForTools')->willReturn(null);

        $analyticsService = new McpAnalyticsService(
            $toolLogRepository,
            $periodResolver,
            $toolIntrospector,
            new McpToolNameResolver(),
        );

        $service = new McpToolsRegistryService(
            $toolIntrospector,
            $this->createMock(ExtensionTableDiscoveryService::class),
            $this->createMock(DiscoveredTableRepository::class),
            $metadataService,
            $analyticsService,
            $cardRegistry,
            new McpToolOwnershipResolver(),
        );

        $extensions = $service->getAiUniverseExtensions();

        self::assertCount(3, $extensions);
        self::assertSame('ns_t3af_core', $extensions[0]['id']);
        self::assertSame(1, $extensions[0]['toolCount']);
        self::assertSame('pages_list', $extensions[0]['tools'][0]['name']);

        self::assertSame('ns_t3cs', $extensions[1]['id']);
        self::assertSame(1, $extensions[1]['toolCount']);
        self::assertSame('t3cs_list_datasources', $extensions[1]['tools'][0]['name']);

        self::assertSame('ns_t3as', $extensions[2]['id']);
        self::assertSame(1, $extensions[2]['toolCount']);
        self::assertSame('t3as_search', $extensions[2]['tools'][0]['name']);
    }
}
