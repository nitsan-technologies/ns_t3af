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

namespace NITSAN\NsT3AF\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Attribute\McpDualModeTool;
use ReflectionClass;

/**
 * Resolves MCP tool descriptions based on the active {@see McpModeResolver} mode.
 */
final readonly class McpToolDescriptionResolver
{
    public function __construct(
        private McpModeResolver $mcpModeResolver,
    ) {}

    public function resolve(object|string $handler, ?string $fallbackDescription = null): string
    {
        $className = is_string($handler) ? $handler : $handler::class;
        $reflection = new ReflectionClass($className);
        $attributes = $reflection->getAttributes(McpDualModeTool::class);
        if ($attributes === []) {
            return $fallbackDescription ?? '';
        }

        $dualMode = $attributes[0]->newInstance();

        return $this->mcpModeResolver->isNative()
            ? $dualMode->nativeDescription
            : $dualMode->contextDescription;
    }
}
