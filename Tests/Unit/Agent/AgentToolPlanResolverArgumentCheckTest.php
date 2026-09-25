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

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Agent\Service\AgentToolPlanResolver;
use NITSAN\NsT3AF\Agent\Service\DynamicToolPlanService;
use NITSAN\NsT3AF\Agent\Service\SatelliteToolPlanService;
use NITSAN\NsT3AF\Mcp\Contract\McpArgumentCheckInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A tool that checks its arguments stops a wrong call before a card is shown.
 *
 * @internal
 */
final class AgentToolPlanResolverArgumentCheckTest extends TestCase
{
    #[Test]
    public function wrongArgumentsGoBackBeforeACardIsPlanned(): void
    {
        $tool = new class implements McpArgumentCheckInterface {
            public function checkArguments(array $arguments): void
            {
                if (!isset($arguments['widgetHideOnMobile'])) {
                    throw new \InvalidArgumentException('Unknown setting: hideOnMobile. Known settings: widgetHideOnMobile.');
                }
            }

            #[McpTool(name: 'eval_settings_tool', description: 'Test tool')]
            public function execute(string $settingsJson = ''): string
            {
                return $settingsJson;
            }
        };
        $resolver = new AgentToolPlanResolver(
            [$tool],
            (new \ReflectionClass(DynamicToolPlanService::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(SatelliteToolPlanService::class))->newInstanceWithoutConstructor(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Known settings: widgetHideOnMobile');
        $resolver->plan('eval_settings_tool', ['hideOnMobile' => true]);
    }
}
