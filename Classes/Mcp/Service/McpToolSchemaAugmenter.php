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

use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use NITSAN\NsT3AF\Mcp\Attribute\McpContentParam;
use NITSAN\NsT3AF\Mcp\Attribute\McpNewsStorageTarget;
use NITSAN\NsT3AF\Mcp\Attribute\McpParentPageTarget;
use NITSAN\NsT3AF\Mcp\Contract\McpDualModeContentToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpExternalContentToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpFalStorageToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Appends global MCP tool parameters (workspace + AI provider) to JSON schemas.
 *
 * Two handler shapes exist in AI Foundation MCP:
 * - Class tools: {@see generateForClassHandler()} — tagged services with marker interfaces and PHP attributes.
 * - Dynamic tools: {@see generateForDynamicCallable()} — closure handlers from {@see NsT3afDynamicToolRegistrar}.
 */
readonly class McpToolSchemaAugmenter
{
    public const PARAM_WORKSPACE_ID = 'workspaceId';

    public const PARAM_AI_PROVIDER = 'aiProvider';

    public function __construct(
        private McpWorkspaceEnumResolver $workspaceEnumResolver,
        private McpConnectedProviderEnumResolver $connectedProviderEnumResolver,
        private McpModeResolver $mcpModeResolver,
    ) {}

    /**
     * @param array<string, mixed> $inputSchema
     * @return array<string, mixed>
     */
    public function augment(array $inputSchema, bool $includeAiProvider = true, bool $includeWorkspaceId = true): array
    {
        if (($inputSchema['type'] ?? null) !== 'object') {
            $inputSchema['type'] = 'object';
        }

        if (!isset($inputSchema['properties']) || !is_array($inputSchema['properties'])) {
            $inputSchema['properties'] = [];
        }

        /** @var array<string, mixed> $properties */
        $properties = $inputSchema['properties'];

        if ($includeWorkspaceId) {
            $properties[self::PARAM_WORKSPACE_ID] = [
                'type' => 'integer',
                'description' => $this->workspaceEnumResolver->buildDescription(),
                'enum' => $this->workspaceEnumResolver->resolveEnum(),
            ];
        }

        if ($includeAiProvider) {
            $providerProperty = [
                'type' => 'string',
                'description' => $this->connectedProviderEnumResolver->buildDescription(),
            ];
            $providerEnum = $this->connectedProviderEnumResolver->resolveEnum();
            if ($providerEnum !== []) {
                $providerProperty['enum'] = $providerEnum;
            }
            $properties[self::PARAM_AI_PROVIDER] = $providerProperty;
        }

        $inputSchema['properties'] = $properties;

        return $inputSchema;
    }

    /**
     * Build a JSON schema for any MCP tool handler.
     *
     * @param callable|array{0: class-string|object, 1: string}|string $handler
     * @return array<string, mixed>
     */
    public function generateForHandler(callable|array|string $handler): array
    {
        if (is_array($handler) && (is_string($handler[0]) || is_object($handler[0]))) {
            return $this->generateForClassHandler($handler);
        }

        return $this->generateForDynamicCallable($handler);
    }

    /**
     * Schema for tagged class tools ({@see McpServerFactory}).
     *
     * Applies marker-interface rules, dual-mode content requirements, and parameter attributes.
     *
     * @param array{0: class-string|object, 1: string} $handler
     * @return array<string, mixed>
     */
    public function generateForClassHandler(array $handler): array
    {
        $reflection = new ReflectionMethod($handler[0], $handler[1]);
        $handlerClass = is_string($handler[0]) ? $handler[0] : $handler[0]::class;

        $schema = $this->schemaGenerator()->generate($reflection);
        $schema = $this->applyDualModeContentRequirements($schema, $reflection, $handlerClass);
        $schema = $this->applyParentPageTargetRequirements($schema, $reflection);
        $schema = $this->applyNewsStorageTargetRequirements($schema, $reflection);

        return $this->augment(
            $schema,
            $this->resolveIncludeAiProvider($handlerClass),
            $this->resolveIncludeWorkspaceId($handlerClass),
        );
    }

    /**
     * Schema for backend-registered custom tools (PHP class handler type).
     *
     * Custom tools are plain public services referenced by FQCN from the MCP Tools backend UI.
     * They do not implement the marker interfaces, so no aiProvider / workspaceId params are added
     * by default — the schema is taken purely from the {@code execute()} signature and PHPDoc.
     *
     * @param array{0: class-string|object, 1: string} $handler
     * @return array<string, mixed>
     */
    public function generateForCustomToolHandler(array $handler, bool $includeAiProvider = false, bool $includeWorkspaceId = false): array
    {
        $reflection = new ReflectionMethod($handler[0], $handler[1]);
        $schema = $this->schemaGenerator()->generate($reflection);

        return $this->augment($schema, $includeAiProvider, $includeWorkspaceId);
    }

    /**
     * Schema for runtime-registered dynamic CRUD tools (closure handlers).
     *
     * Dynamic tools are pure TYPO3 record operations — workspace only, no aiProvider, no dual-mode attributes.
     *
     * @param callable|string $handler Closure or global function name
     * @return array<string, mixed>
     */
    public function generateForDynamicCallable(callable|string $handler): array
    {
        $reflection = is_string($handler) && function_exists($handler)
            ? new ReflectionFunction($handler)
            : new ReflectionFunction($handler);

        $schema = $this->schemaGenerator()->generate($reflection);

        return $this->augment($schema, includeAiProvider: false);
    }

    /**
     * @return list<array{name: string, type: string, required: bool, default: string|null, description: string}>
     */
    public function standardParameterDefinitions(bool $includeAiProvider = true, bool $includeWorkspaceId = true): array
    {
        $providerType = 'string';
        $providerDescription = $this->connectedProviderEnumResolver->buildDescription();
        $providerEnum = $this->connectedProviderEnumResolver->resolveEnum();
        if ($providerEnum !== []) {
            $providerDescription .= ' Allowed: ' . implode(', ', $providerEnum) . '.';
        }

        $definitions = [];

        if ($includeWorkspaceId) {
            $definitions[] = [
                'name' => self::PARAM_WORKSPACE_ID,
                'type' => 'int',
                'required' => false,
                'default' => null,
                'description' => $this->workspaceEnumResolver->buildDescription(),
            ];
        }

        if ($includeAiProvider) {
            $definitions[] = [
                'name' => self::PARAM_AI_PROVIDER,
                'type' => $providerType,
                'required' => false,
                'default' => null,
                'description' => $providerDescription,
            ];
        }

        return $definitions;
    }

    private function resolveIncludeWorkspaceId(string $handlerClass): bool
    {
        return !is_subclass_of($handlerClass, McpFalStorageToolInterface::class);
    }

    private function resolveIncludeAiProvider(string $handlerClass): bool
    {
        if (is_subclass_of($handlerClass, McpNonAiToolInterface::class)) {
            return false;
        }

        if (is_subclass_of($handlerClass, McpExternalContentToolInterface::class)) {
            return false;
        }

        if (is_subclass_of($handlerClass, McpDualModeContentToolInterface::class)) {
            return $this->mcpModeResolver->isNative();
        }

        return true;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function applyDualModeContentRequirements(
        array $schema,
        ReflectionMethod $reflection,
        string $handlerClass,
    ): array {
        if (!is_subclass_of($handlerClass, McpDualModeContentToolInterface::class)) {
            return $schema;
        }

        $contentParams = $this->extractContentParamNames($reflection);
        if ($contentParams === []) {
            return $schema;
        }

        /** @var list<string> $required */
        $required = $schema['required'] ?? [];

        if ($this->mcpModeResolver->isContext()) {
            foreach ($contentParams as $name) {
                if (!in_array($name, $required, true)) {
                    $required[] = $name;
                }
            }
            $schema['required'] = $required;

            return $schema;
        }

        $required = array_values(array_diff($required, $contentParams));
        if ($required === []) {
            unset($schema['required']);
        } else {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function applyParentPageTargetRequirements(array $schema, ReflectionMethod $reflection): array
    {
        $parentPageParams = $this->extractParentPageTargetNames($reflection);
        if ($parentPageParams === []) {
            return $schema;
        }

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'] ?? [];

        if (isset($properties['parentPageId'])) {
            $properties['parentPageId']['description'] = 'Parent page UID under which the new page is created (same as TYPO3 backend page-tree selection). Required unless parentPageUrl is provided.';
        }
        if (isset($properties['parentPageUrl'])) {
            $properties['parentPageUrl']['description'] = 'Alternative to parentPageId: frontend URL or site path of the parent page under which the new page is created.';
        }

        $schema['properties'] = $properties;

        if (isset($properties['parentPageId'])) {
            /** @var list<string> $required */
            $required = $schema['required'] ?? [];
            if (!in_array('parentPageId', $required, true)) {
                $required[] = 'parentPageId';
            }
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function applyNewsStorageTargetRequirements(array $schema, ReflectionMethod $reflection): array
    {
        if ($this->extractNewsStorageTargetNames($reflection) === []) {
            return $schema;
        }

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'] ?? [];

        unset($properties['pageUrl']);

        if (isset($properties['pageId'])) {
            $properties['pageId']['title'] = 'Storage Id';
            $properties['pageId']['description'] = 'Storage Id — UID of the EXT:news storage folder page where the record is created.';
        }

        $schema['properties'] = $properties;

        if (isset($properties['pageId'])) {
            /** @var list<string> $required */
            $required = $schema['required'] ?? [];
            if (!in_array('pageId', $required, true)) {
                $required[] = 'pageId';
            }
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return list<string>
     */
    private function extractNewsStorageTargetNames(ReflectionMethod $reflection): array
    {
        $names = [];
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->getAttributes(McpNewsStorageTarget::class) === []) {
                continue;
            }
            $names[] = $parameter->getName();
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function extractParentPageTargetNames(ReflectionMethod $reflection): array
    {
        $names = [];
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->getAttributes(McpParentPageTarget::class) === []) {
                continue;
            }
            $names[] = $parameter->getName();
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function extractContentParamNames(ReflectionMethod $reflection): array
    {
        $names = [];
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->getAttributes(McpContentParam::class) === []) {
                continue;
            }
            $names[] = $parameter->getName();
        }

        return $names;
    }

    /**
     * Built locally so Classic (phar-scoped) and Composer installs never rely on
     * Symfony autowiring {@see SchemaGenerator} / {@see DocBlockParser}.
     */
    private function schemaGenerator(): SchemaGenerator
    {
        static $generator = null;

        if ($generator === null) {
            $generator = new SchemaGenerator(new DocBlockParser());
        }

        return $generator;
    }
}
