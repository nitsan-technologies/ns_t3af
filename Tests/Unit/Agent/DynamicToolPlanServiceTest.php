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

use NITSAN\NsT3AF\Agent\Service\DynamicToolPlanService;
use NITSAN\NsT3AF\Mcp\Repository\DiscoveredTableRepository;
use NITSAN\NsT3AF\Mcp\Service\McpRecordPlanService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DynamicToolPlanServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalExtConf;

    protected function setUp(): void
    {
        $this->originalExtConf = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af'] ?? [];
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['tables'] = [
            'tx_news_domain_model_news' => ['prefix' => 'tx_news'],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->originalExtConf === []) {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af'] = $this->originalExtConf;
        }
        parent::tearDown();
    }

    #[Test]
    public function supportsDynamicUpdateToolNames(): void
    {
        $service = $this->makeService();

        self::assertTrue($service->supports('tx_news_update'));
        self::assertFalse($service->supports('unknown_tool'));
    }

    #[Test]
    public function plansDynamicUpdateUsingSharedRecordPlanner(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([7]);
        $recordService->method('findByUid')->willReturn(['title' => 'Old']);

        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->method('getWritableFields')->willReturn(['title']);

        $recordPlanService = new McpRecordPlanService($recordService, $tcaSchemaService);
        $service = new DynamicToolPlanService(
            $recordPlanService,
            $this->createMock(DiscoveredTableRepository::class),
        );

        $plan = $service->plan('tx_news_update', [
            'uid' => 7,
            'data' => ['title' => 'New'],
        ]);

        self::assertInstanceOf(ToolPlan::class, $plan);
        self::assertSame('update', $plan->action);
        self::assertSame('tx_news_update', $plan->toolName);
        self::assertSame('New', $plan->fields[0]->proposedValue);
    }

    private function makeService(): DynamicToolPlanService
    {
        $recordPlanService = new McpRecordPlanService(
            $this->createMock(RecordService::class),
            $this->createMock(TcaSchemaService::class),
        );

        return new DynamicToolPlanService(
            $recordPlanService,
            $this->createMock(DiscoveredTableRepository::class),
        );
    }
}
