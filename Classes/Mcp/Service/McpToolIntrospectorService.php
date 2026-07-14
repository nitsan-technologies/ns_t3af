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
use NITSAN\NsT3AF\Mcp\Attribute\McpContentParam;
use NITSAN\NsT3AF\Mcp\Attribute\McpNewsStorageTarget;
use NITSAN\NsT3AF\Mcp\Attribute\McpParentPageTarget;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolOwner;
use NITSAN\NsT3AF\Mcp\Contract\McpDualModeContentToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpExternalContentToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpFalStorageToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Introspects tagged MCP tool handlers for backend catalog display.
 *
 * @internal
 */
class McpToolIntrospectorService
{
    /**
     * @var list<array{
     *     name: string,
     *     description: string,
     *     params: list<array{name: string, type: string, required: bool, default: string|null, description: string}>,
     *     className: class-string,
     *     ownerExtensionKey: string|null
     * }>|null
     */
    private ?array $listToolsCache = null;

    /**
     * @param iterable<object> $tools
     */
    public function __construct(
        private readonly iterable $tools,
        private readonly McpToolSchemaAugmenter $toolSchemaAugmenter,
        private readonly McpModeResolver $mcpModeResolver,
        private readonly McpToolDescriptionResolver $toolDescriptionResolver,
    ) {}

    /**
     * @return list<array{
     *     name: string,
     *     description: string,
     *     params: list<array{name: string, type: string, required: bool, default: string|null, description: string}>,
     *     className: class-string,
     *     ownerExtensionKey: string|null
     * }>
     */
    public function listTools(): array
    {
        if ($this->listToolsCache !== null) {
            return $this->listToolsCache;
        }

        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = $this->introspectTool($tool);
        }

        usort($tools, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $this->listToolsCache = $tools;
    }

    /**
     * @return array{
     *     name: string,
     *     description: string,
     *     params: list<array{name: string, type: string, required: bool, default: string|null, description: string}>,
     *     className: class-string,
     *     ownerExtensionKey: string|null
     * }
     */
    private function introspectTool(object $tool): array
    {
        $reflection = new ReflectionMethod($tool, 'execute');
        $attribute = $this->resolveMcpToolAttribute($reflection);

        $paramDescriptions = $this->parseParamDescriptions($reflection);

        $params = [];
        $isDualMode = $tool instanceof McpDualModeContentToolInterface;
        foreach ($reflection->getParameters() as $parameter) {
            $described = $this->describeParameter($parameter, $paramDescriptions);
            if ($isDualMode && $parameter->getAttributes(McpContentParam::class) !== []) {
                $described['required'] = $this->mcpModeResolver->isContext();
            }
            if ($parameter->getAttributes(McpParentPageTarget::class) !== []) {
                $described['required'] = $parameter->getName() === 'parentPageId';
            }
            if ($parameter->getAttributes(McpNewsStorageTarget::class) !== []) {
                $described['required'] = true;
                if ($parameter->getName() === 'pageId') {
                    $described['name'] = 'Storage Id';
                }
            }
            $params[] = $described;
        }

        $includeAiProvider = match (true) {
            $tool instanceof McpNonAiToolInterface,
            $tool instanceof McpExternalContentToolInterface => false,
            $tool instanceof McpDualModeContentToolInterface => $this->mcpModeResolver->isNative(),
            default => true,
        };
        $includeWorkspaceId = !$tool instanceof McpFalStorageToolInterface;
        foreach ($this->toolSchemaAugmenter->standardParameterDefinitions($includeAiProvider, $includeWorkspaceId) as $standardParam) {
            $params[] = [
                'name' => $standardParam['name'],
                'type' => $standardParam['type'],
                'required' => $standardParam['required'],
                'default' => $standardParam['default'],
                'description' => $standardParam['description'],
            ];
        }

        return [
            'name' => $attribute?->name ?? $tool::class,
            'description' => $this->toolDescriptionResolver->resolve($tool, $attribute?->description ?? ''),
            'params' => $params,
            'className' => $tool::class,
            'ownerExtensionKey' => $this->resolveOwnerExtensionKey($reflection),
        ];
    }

    private function resolveOwnerExtensionKey(ReflectionMethod $reflection): ?string
    {
        $methodAttributes = $reflection->getAttributes(McpToolOwner::class);
        if ($methodAttributes !== []) {
            return $methodAttributes[0]->newInstance()->extensionKey;
        }

        $classAttributes = $reflection->getDeclaringClass()->getAttributes(McpToolOwner::class);
        if ($classAttributes !== []) {
            return $classAttributes[0]->newInstance()->extensionKey;
        }

        return null;
    }

    private function resolveMcpToolAttribute(ReflectionMethod $reflection): ?McpTool
    {
        $attributes = $reflection->getAttributes(McpTool::class);
        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * @param array<string, string> $paramDescriptions
     * @return array{name: string, type: string, required: bool, default: string|null, description: string}
     */
    private function describeParameter(ReflectionParameter $parameter, array $paramDescriptions): array
    {
        $type = $parameter->getType();
        $typeName = 'mixed';
        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();
            if ($type->allowsNull() && !$type->isBuiltin()) {
                $typeName .= '|null';
            } elseif ($type->allowsNull() && $type->isBuiltin()) {
                $typeName .= '|null';
            }
        }

        $default = null;
        if ($parameter->isDefaultValueAvailable()) {
            $value = $parameter->getDefaultValue();
            if (is_scalar($value) || $value === null) {
                $default = (string) $value;
            } else {
                $encoded = json_encode($value);
                $default = is_string($encoded) ? $encoded : null;
            }
        }

        $name = $parameter->getName();

        return [
            'name' => $name,
            'type' => $typeName,
            'required' => !$parameter->isOptional(),
            'default' => $default,
            'description' => $paramDescriptions[$name] ?? '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseParamDescriptions(ReflectionMethod $reflection): array
    {
        $docComment = $reflection->getDocComment();
        if (!is_string($docComment) || $docComment === '') {
            return [];
        }

        $descriptions = [];
        if (preg_match_all('/@param\s+\S+\s+\$(\w+)\s+(.+)/', $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $descriptions[$match[1]] = trim($match[2]);
            }
        }

        return $descriptions;
    }
}
