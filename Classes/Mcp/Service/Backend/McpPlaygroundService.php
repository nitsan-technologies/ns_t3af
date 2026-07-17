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

namespace NITSAN\NsT3AF\Mcp\Service\Backend;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Service\McpInvocationContext;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Authenticated backend playground for invoking MCP tools (tools/call wrapper).
 */
readonly class McpPlaygroundService
{
    private const DEFAULT_TOOL = 'pages_get';

    /**
     * @param iterable<object> $tools
     */
    public function __construct(
        private iterable $tools,
        private McpToolIntrospectorService $toolIntrospector,
        private McpToolMetadataService $toolMetadataService,
        private McpAnalyticsService $analyticsService,
        private McpInvocationContext $invocationContext,
        private McpToolLogService $toolLogService,
    ) {}

    /**
     * @return array{
     *     toolCount: int,
     *     defaultTool: string,
     *     categories: list<array{
     *         key: string,
     *         label: string,
     *         tools: list<array{
     *             name: string,
     *             description: string,
     *             params: list<array<string, mixed>>,
     *             status: string,
     *             category: string,
     *             tagline: string,
     *             notes: string,
     *             examplePrompts: list<string>
     *         }>
     *     }>
     * }
     */
    public function buildConfig(): array
    {
        $tools = $this->toolIntrospector->listTools();
        $grouped = [];
        foreach ($tools as $tool) {
            $metadata = $this->toolMetadataService->getForTool($tool['name']);
            $categoryKey = $metadata['category'] !== '' ? $metadata['category'] : 'Records';
            $grouped[$categoryKey][] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'params' => $tool['params'],
                'status' => $metadata['status'],
                'category' => $categoryKey,
                'tagline' => $metadata['tagline'],
                'notes' => $metadata['notes'],
                'examplePrompts' => $metadata['examplePrompts'],
            ];
        }

        ksort($grouped);

        $categories = [];
        foreach ($grouped as $key => $tools) {
            $categories[] = [
                'key' => $key,
                'label' => $key,
                'tools' => $tools,
            ];
        }

        return [
            'toolCount' => count($tools),
            'defaultTool' => self::DEFAULT_TOOL,
            'categories' => $categories,
        ];
    }

    /**
     * @param array<string, mixed>|string $periodQuery
     *
     * @return array{
     *     callsWeek: int,
     *     successRate: float,
     *     avgLatencyMs: float,
     *     lastCalled: int|null
     * }
     */
    public function getToolStats(string $toolName, array|string $periodQuery = '7d'): array
    {
        return $this->analyticsService->getForTool($toolName, $periodQuery);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{success: bool, result: mixed, latencyMs: int, message: string}
     */
    public function invoke(string $toolName, array $arguments): array
    {
        $handler = $this->findHandler($toolName);
        if ($handler === null) {
            return [
                'success' => false,
                'result' => null,
                'latencyMs' => 0,
                'message' => 'Unknown tool: ' . $toolName,
            ];
        }

        $this->invocationContext->applyFromArguments($arguments);
        $start = hrtime(true);

        try {
            $result = $this->invokeHandler($handler, $arguments);
            $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);
            $this->toolLogService->logSuccess($handler, 'playground', array_values($arguments), $latencyMs);

            return [
                'success' => true,
                'result' => $result,
                'latencyMs' => $latencyMs,
                'message' => '',
            ];
        } catch (\Throwable $exception) {
            $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);
            $this->toolLogService->logFailure(
                $handler,
                'playground',
                array_values($arguments),
                $latencyMs,
                $exception->getMessage(),
            );

            return [
                'success' => false,
                'result' => null,
                'latencyMs' => $latencyMs,
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function findHandler(string $toolName): ?object
    {
        foreach ($this->tools as $tool) {
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

    /**
     * @param array<string, mixed> $arguments
     */
    private function invokeHandler(object $handler, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($handler, 'execute');
        $ordered = [];

        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $arguments)) {
                $ordered[] = $this->castArgument($arguments[$name], $parameter);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $ordered[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $ordered[] = null;
                continue;
            }

            throw new \InvalidArgumentException('Missing required argument: ' . $name);
        }

        return $reflection->invoke($handler, ...$ordered);
    }

    private function castArgument(mixed $value, \ReflectionParameter $parameter): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin() === false) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            'array' => is_array($value) ? $value : $this->decodeJsonArray($value),
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }
}
