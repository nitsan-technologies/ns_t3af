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

use NITSAN\NsT3AF\Agent\Service\SatelliteToolPlanService;
use NITSAN\NsT3AF\Mcp\Service\McpConfirmationPlanBuilder;
use NITSAN\NsT3AF\Mcp\Service\McpToolSeverityResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SatelliteToolPlanServiceTest extends TestCase
{
    private SatelliteToolPlanService $service;

    protected function setUp(): void
    {
        $this->service = new SatelliteToolPlanService(
            new McpToolSeverityResolver(),
            new McpConfirmationPlanBuilder(),
        );
    }

    #[Test]
    public function supportsWriteSatelliteToolsOnly(): void
    {
        self::assertTrue($this->service->supports('t3ai_generate_meta_description'));
        self::assertTrue($this->service->supports('t3cs_save_datasource'));
        self::assertFalse($this->service->supports('t3cs_list_datasources'));
        self::assertFalse($this->service->supports('pages_get'));
    }

    #[Test]
    public function planBuildsConfirmationDraft(): void
    {
        $plan = $this->service->plan('t3ai_apply_schema_markup', ['pageId' => 3]);

        self::assertSame('update', $plan->action);
        self::assertSame('t3ai_apply_schema_markup', $plan->toolName);
        self::assertSame(SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION, $plan->context['planKind'] ?? null);
        self::assertSame(['pageId' => 3], $plan->context['arguments'] ?? null);
        self::assertNotSame('', $plan->context['summary'] ?? '');
    }

    #[Test]
    public function planDropsRedundantContextFillersFromDisplayArguments(): void
    {
        $plan = $this->service->plan('t3ai_generate_all_seo', ['pageId' => 8, 'pid' => 8, 'uid' => 8]);
        $displayArguments = is_array($plan->context['displayArguments'] ?? null)
            ? $plan->context['displayArguments']
            : [];

        self::assertSame([['key' => 'pageId', 'value' => '8']], $displayArguments);
    }
}
