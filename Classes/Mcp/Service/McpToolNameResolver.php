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

use Mcp\Capability\Attribute\McpTool;
use ReflectionClass;
use ReflectionMethod;

/**
 * Resolves the public MCP tool name from a handler and legacy class-derived aliases.
 *
 * @internal
 */
final readonly class McpToolNameResolver
{
    public function resolveFromHandler(object $handler): string
    {
        if (method_exists($handler, 'execute')) {
            $reflection = new ReflectionMethod($handler, 'execute');
            $attributes = $reflection->getAttributes(McpTool::class);
            if ($attributes !== []) {
                $name = $attributes[0]->newInstance()->name ?? '';
                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }
        }

        return $this->legacyNameFromClassShortName((new ReflectionClass($handler))->getShortName());
    }

    public function legacyNameFromClassShortName(string $handlerShortName): string
    {
        $base = preg_replace('/Tool$/', '', $handlerShortName) ?? $handlerShortName;
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base));

        return trim($snake, '_');
    }
}
