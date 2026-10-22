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

namespace NITSAN\NsT3AF\Mcp\Server;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Builder;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\McpToolDescriptionResolver;
use NITSAN\NsT3AF\Mcp\Service\McpToolSchemaAugmenter;
use NITSAN\NsT3AF\Mcp\Service\McpToolSeverityResolver;
use ReflectionMethod;

/**
 * Registers one MCP tool on the SDK builder with list metadata (title, annotations, outputSchema).
 *
 * @internal
 */
readonly class McpServerToolRegistration
{
    public function __construct(
        private McpToolSchemaAugmenter $toolSchemaAugmenter,
        private McpToolDescriptionResolver $toolDescriptionResolver,
        private McpToolSeverityResolver $toolSeverityResolver,
    ) {}

    /**
     * @param array{0: class-string|object, 1: string} $handler
     * @param array<string, mixed>|null $inputSchema When null, schema is generated from the handler.
     */
    public function addTool(
        Builder $builder,
        array $handler,
        ?string $name,
        ?string $description = null,
        ?array $inputSchema = null,
        ?string $toolNameForSeverity = null,
        bool $customToolHandler = false,
        ?object $toolInstance = null,
    ): void {
        $reflection = new ReflectionMethod($handler[0], $handler[1]);
        $mcpTool = $this->resolveMcpToolAttribute($reflection);

        $resolvedName = $name ?? $mcpTool?->name;
        $descriptionSubject = $toolInstance ?? $reflection->getDeclaringClass()->getName();
        $attributeDescription = $mcpTool !== null ? $mcpTool->description : null;
        $resolvedDescription = $this->toolDescriptionResolver->resolve($descriptionSubject, $description ?? $attributeDescription);

        if ($inputSchema === null) {
            $inputSchema = $customToolHandler
                ? $this->toolSchemaAugmenter->generateForCustomToolHandler($handler)
                : $this->toolSchemaAugmenter->generateForClassHandler($handler);
        }

        $severity = $this->toolSeverityResolver->resolveFromReflection($reflection)
            ?? ($toolNameForSeverity !== null ? $this->toolSeverityResolver->resolveForToolName($toolNameForSeverity) : null);

        $attributeTitle = $mcpTool !== null ? $mcpTool->title : null;
        $attributeAnnotations = $mcpTool !== null ? $mcpTool->annotations : null;
        $outputSchema = $mcpTool !== null ? $mcpTool->outputSchema : null;

        $builder->addTool(
            handler: $handler,
            name: $resolvedName,
            title: $attributeTitle ?? $this->humanizeToolTitle($resolvedName),
            description: $resolvedDescription,
            annotations: $attributeAnnotations ?? $this->annotationsFromSeverity($severity),
            inputSchema: $inputSchema,
            outputSchema: $outputSchema,
        );
    }

    /**
     * @param \Closure|string $handler
     */
    public function addDynamicTool(
        Builder $builder,
        \Closure|string $handler,
        string $name,
        string $description,
    ): void {
        $severity = $this->toolSeverityResolver->resolveForToolName($name);

        $builder->addTool(
            handler: $handler,
            name: $name,
            title: $this->humanizeToolTitle($name),
            description: $description,
            annotations: $this->annotationsFromSeverity($severity),
            inputSchema: $this->toolSchemaAugmenter->generateForDynamicCallable($handler),
        );
    }

    private function resolveMcpToolAttribute(ReflectionMethod $reflection): ?McpTool
    {
        $methodAttributes = $reflection->getAttributes(McpTool::class);
        if ($methodAttributes !== []) {
            return $methodAttributes[0]->newInstance();
        }

        $classAttributes = $reflection->getDeclaringClass()->getAttributes(McpTool::class);
        if ($classAttributes !== []) {
            return $classAttributes[0]->newInstance();
        }

        return null;
    }

    private function annotationsFromSeverity(?ToolSeverity $severity): ?ToolAnnotations
    {
        if ($severity === null) {
            return null;
        }

        return match ($severity) {
            ToolSeverity::Read => new ToolAnnotations(readOnlyHint: true, destructiveHint: false),
            ToolSeverity::Write => new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
            ToolSeverity::Destructive => new ToolAnnotations(readOnlyHint: false, destructiveHint: true),
        };
    }

    private function humanizeToolTitle(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        $label = str_replace(['_', '-'], ' ', $name);

        return ucwords($label);
    }
}
