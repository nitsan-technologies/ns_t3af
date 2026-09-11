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

namespace NITSAN\NsT3AF\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use ReflectionMethod;

/**
 * Resolves declared severity for static and dynamic MCP tools.
 *
 * @internal
 */
class McpToolSeverityResolver
{
    /**
     * Dynamic tool operation suffixes registered by {@see \NITSAN\NsT3AF\Mcp\Tool\Dynamic\NsT3afDynamicToolRegistrar}.
     *
     * @var array<string, ToolSeverity>
     */
    private const DYNAMIC_OPERATION_SEVERITY = [
        'delete_batch' => ToolSeverity::Destructive,
        'update_batch' => ToolSeverity::Write,
        'move_batch' => ToolSeverity::Write,
        'list' => ToolSeverity::Read,
        'get' => ToolSeverity::Read,
        'create' => ToolSeverity::Write,
        'update' => ToolSeverity::Write,
        'delete' => ToolSeverity::Destructive,
        'move' => ToolSeverity::Write,
    ];

    /**
     * @return array<string, ToolSeverity>
     */
    public static function dynamicOperationSeverityMap(): array
    {
        return self::DYNAMIC_OPERATION_SEVERITY;
    }

    public function resolveForHandler(object $tool): ?ToolSeverity
    {
        return $this->resolveFromReflection(new ReflectionMethod($tool, 'execute'));
    }

    public function resolveFromReflection(ReflectionMethod $reflection): ?ToolSeverity
    {
        $methodAttributes = $reflection->getAttributes(McpToolSeverity::class);
        if ($methodAttributes !== []) {
            return $methodAttributes[0]->newInstance()->severity;
        }

        $classAttributes = $reflection->getDeclaringClass()->getAttributes(McpToolSeverity::class);
        if ($classAttributes !== []) {
            return $classAttributes[0]->newInstance()->severity;
        }

        return null;
    }

    public function resolveForDynamicToolName(string $toolName): ?ToolSeverity
    {
        $operation = self::extractDynamicOperation($toolName);
        if ($operation === null) {
            return null;
        }

        return self::DYNAMIC_OPERATION_SEVERITY[$operation] ?? null;
    }

    /**
     * Name-based resolution when reflection does not declare severity (dynamic + satellite tools).
     */
    public function resolveForToolName(string $toolName): ?ToolSeverity
    {
        $fromDynamic = $this->resolveForDynamicToolName($toolName);
        if ($fromDynamic !== null) {
            return $fromDynamic;
        }

        return $this->resolveFromToolNameHeuristics($toolName);
    }

    /**
     * Infer severity from T3Planet satellite and other static tool names without {@see McpToolSeverity}.
     */
    public function resolveFromToolNameHeuristics(string $toolName): ?ToolSeverity
    {
        $name = strtolower(trim($toolName));
        if ($name === '') {
            return null;
        }

        if (preg_match('/(?:^|_)(delete|purge|remove)(?:_|$)/', $name) === 1) {
            return ToolSeverity::Destructive;
        }

        if (
            str_contains($name, '_queue_list')
            || preg_match('/(?:^|_)(list|get|search|summary|analytics|settings|questions|pagespeed|echo|missing|inspect|read|summarize)(?:_|$)/', $name) === 1
        ) {
            return ToolSeverity::Read;
        }

        if (
            str_contains($name, '_queue_add')
            || preg_match('/(?:^|_)(update|apply|create|generate|translate|save|sync|reset|add|move|copy|upload|structure)(?:_|$)/', $name) === 1
        ) {
            return ToolSeverity::Write;
        }

        return null;
    }

    private static function extractDynamicOperation(string $toolName): ?string
    {
        foreach (array_keys(self::DYNAMIC_OPERATION_SEVERITY) as $operation) {
            $suffix = '_' . $operation;
            if (str_ends_with($toolName, $suffix)) {
                return $operation;
            }
        }

        return null;
    }
}
