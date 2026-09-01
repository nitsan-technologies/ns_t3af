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

namespace NITSAN\NsT3AF\Agent\Service;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Exception\UnsupportedPlanException;
use ReflectionMethod;

/**
 * Resolves MCP tool handlers and invokes plan mode.
 *
 * @internal
 */
final class AgentToolPlanResolver
{
    /**
     * @param iterable<object> $tools
     */
    public function __construct(
        private readonly iterable $tools,
        private readonly DynamicToolPlanService $dynamicToolPlanService,
        private readonly SatelliteToolPlanService $satelliteToolPlanService,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(string $toolName, array $arguments): \NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan
    {
        $handler = $this->findHandler($toolName);
        if ($handler instanceof McpPlannableToolInterface) {
            return $handler->plan($arguments);
        }

        if ($this->dynamicToolPlanService->supports($toolName)) {
            return $this->dynamicToolPlanService->plan($toolName, $arguments);
        }

        if ($this->satelliteToolPlanService->supports($toolName)) {
            return $this->satelliteToolPlanService->plan($toolName, $arguments);
        }

        throw new UnsupportedPlanException('Unknown tool: ' . $toolName);
    }

    public function supportsPlanning(string $toolName): bool
    {
        $handler = $this->findHandler($toolName);
        if ($handler instanceof McpPlannableToolInterface) {
            return true;
        }

        if ($this->dynamicToolPlanService->supports($toolName)) {
            return true;
        }

        return $this->satelliteToolPlanService->supports($toolName);
    }

    private function findHandler(string $toolName): ?object
    {
        foreach ($this->tools as $tool) {
            if (!method_exists($tool, 'execute')) {
                continue;
            }

            $reflection = new ReflectionMethod($tool, 'execute');
            $attributes = $reflection->getAttributes(McpTool::class);
            if ($attributes === []) {
                continue;
            }

            $attribute = $attributes[0]->newInstance();
            if ($attribute->name === $toolName) {
                return $tool;
            }
        }

        return null;
    }
}
