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

use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use NITSAN\NsT3AF\Mcp\Attribute\McpContentParam;
use NITSAN\NsT3AF\Mcp\Attribute\McpNewsStorageTarget;
use NITSAN\NsT3AF\Mcp\Attribute\McpParentPageTarget;
use NITSAN\NsT3AF\Mcp\Contract\McpDualModeContentToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpExternalContentToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpFalStorageToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Tool\Workspace\WorkspaceSwitchTool;
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
        // Agents often send aliases (e.g. path instead of directoryPath); reject unknowns
        // so a call cannot silently act on the storage root (handoff P2 #14).
        $inputSchema['additionalProperties'] = false;

        return $this->normalizePortableSchema($inputSchema);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizePortableSchema(array $schema, string $propertyName = ''): array
    {
        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            /** @var list<array<string, mixed>> $branches */
            $branches = array_values(array_map(
                fn(mixed $branch): array => is_array($branch) ? $this->normalizePortableSchema($branch, $propertyName) : [],
                $schema['oneOf'],
            ));
            $collapsed = $this->collapsePortableOneOf($branches, $propertyName);
            if ($collapsed !== null) {
                unset($schema['oneOf']);

                return $this->normalizePortableSchema($collapsed + $schema, $propertyName);
            }
            $schema['oneOf'] = $branches;
        }

        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            $nonNull = array_values(array_filter($type, static fn(mixed $t): bool => $t !== 'null'));
            if (count($nonNull) === 1) {
                // Collapse ["null","integer"] → "integer" only; do not invent defaults (uid 0 reads as valid).
                $schema['type'] = $nonNull[0];
            }
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $name => $property) {
                if (is_array($property)) {
                    $property = $this->normalizePortableSchema($property, (string) $name);
                    if (!isset($property['description']) || trim((string) $property['description']) === '') {
                        $property['description'] = self::defaultPropertyDescription((string) $name);
                    }
                    $schema['properties'][$name] = $property;
                }
            }
        }

        if (($schema['type'] ?? null) === 'array') {
            $schema['items'] = $this->normalizeArrayItemsSchema($schema['items'] ?? null, $propertyName);
        }

        if (array_key_exists('default', $schema)) {
            $default = $schema['default'];
            $scalarType = $schema['type'] ?? null;
            if (
                $default === null
                || ($scalarType === 'integer' && $default === 0)
                || ($scalarType === 'string' && $default === '')
                || ($scalarType === 'boolean' && $default === false)
            ) {
                unset($schema['default']);
            }
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArrayItemsSchema(mixed $items, string $propertyName): array
    {
        if ($this->itemsSchemaIsUnset($items)) {
            $items = null;
        } elseif (is_array($items) && isset($items['type'])) {
            return $this->normalizePortableSchema($items, $propertyName);
        } elseif ($items instanceof \stdClass) {
            $items = (array) $items;
            if ($items !== [] && isset($items['type'])) {
                return $this->normalizePortableSchema($items, $propertyName);
            }
            $items = null;
        }

        if ($items === null && $this->propertyNameSuggestsIntegerList($propertyName)) {
            return ['type' => 'integer'];
        }

        if ($items === null && $this->propertyNameSuggestsStringList($propertyName)) {
            return ['type' => 'string'];
        }

        if ($items === null) {
            return [];
        }

        return is_array($items) ? $this->normalizePortableSchema($items, $propertyName) : [];
    }

    private function itemsSchemaIsUnset(mixed $items): bool
    {
        if ($items === null) {
            return true;
        }
        if ($items instanceof \stdClass) {
            return get_object_vars($items) === [];
        }

        return is_array($items) && $items === [];
    }

    private function propertyNameSuggestsIntegerList(string $propertyName): bool
    {
        if ($propertyName === '') {
            return false;
        }

        return (bool) preg_match('/(?:Ids|Uids|_uids)$/i', $propertyName);
    }

    private function propertyNameSuggestsStringList(string $propertyName): bool
    {
        return $propertyName === 'options';
    }

    /**
     * @param list<array<string, mixed>> $branches
     * @return array<string, mixed>|null
     */
    private function collapsePortableOneOf(array $branches, string $propertyName): ?array
    {
        if ($branches === []) {
            return null;
        }

        $byType = [];
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $type = $branch['type'] ?? null;
            if (is_string($type) && $type !== '') {
                $byType[$type] = $branch;
            }
        }

        if (isset($byType['array'])) {
            return $byType['array'];
        }
        if (isset($byType['object'])) {
            return $byType['object'];
        }
        if (isset($byType['string'])) {
            return $byType['string'];
        }
        if (isset($byType['integer'])) {
            return $byType['integer'];
        }

        return count($branches) === 1 ? $branches[0] : null;
    }

    private static function defaultPropertyDescription(string $name): string
    {
        static $known = [
            'uid' => 'Record UID to act on.',
            'pageId' => 'Page UID (pages.uid).',
            'pid' => 'Parent page UID for new records.',
            'parentPageId' => 'Parent page UID for the new page or content.',
            'sysLanguageUid' => 'Site language UID (0 = default).',
            'targetPid' => 'Destination parent page UID when moving.',
            'beforeUid' => 'Place the record before this sibling UID.',
            'afterUid' => 'Place the record after this sibling UID.',
            'target' => 'Move target: page UID or "first" / "last" on the parent.',
            'scope' => 'Cache scope: pages, pagesTags, all, or system.',
            'search' => 'Optional search string to filter results.',
            'limit' => 'Maximum number of rows to return.',
            'offset' => 'Number of rows to skip (pagination).',
            'module' => 'Extension or module key to explain.',
            'workspaceId' => 'Workspace UID (0 = live).',
            'aiProvider' => 'AI provider identifier from connected providers.',
            'fileName' => 'File name including extension.',
            'directoryPath' => 'Folder path inside the storage (starts with /).',
            'storageUid' => 'File storage UID (usually 1 for fileadmin).',
            'content' => 'UTF-8 text file body (for small text files).',
            'base64Content' => 'Base64-encoded file bytes.',
            'action' => 'Write action: create, update, or delete.',
            'data' => 'Field map for the record (TCA column names).',
            'cmd' => 'records_apply command (for example localize, copy, move).',
            'bulk' => 'Batch of record operations.',
        ];

        return $known[$name] ?? 'Parameter: ' . $name . '.';
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
        if (is_object($handler) && !$handler instanceof \Closure) {
            return $this->generateForClassHandler([$handler, '__invoke']);
        }
        if ($handler instanceof \Closure || is_string($handler)) {
            return $this->generateForDynamicCallable($handler);
        }

        throw new \InvalidArgumentException('Unsupported MCP tool handler.');
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
     * @param \Closure|string $handler Closure or global function name
     * @return array<string, mixed>
     */
    public function generateForDynamicCallable(\Closure|string $handler): array
    {
        $reflection = new ReflectionFunction($handler);

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
        if (is_subclass_of($handlerClass, McpFalStorageToolInterface::class)) {
            return false;
        }

        if ($handlerClass === WorkspaceSwitchTool::class) {
            return false;
        }

        return true;
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
