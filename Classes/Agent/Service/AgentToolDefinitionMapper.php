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

use NITSAN\NsT3AF\Api\AiToolDefinition;
use NITSAN\NsT3AF\Mcp\Attribute\McpContentParam;
use NITSAN\NsT3AF\Mcp\Attribute\McpDualModeTool;
use NITSAN\NsT3AF\Mcp\Contract\McpPreviewableToolInterface;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Maps permitted MCP tools to provider-facing AiToolDefinition schemas.
 *
 * Agent schemas are built from attributes (not global mcpMode): content params,
 * aiProvider and workspaceId are hidden; previewable tools expose variants.
 *
 * @internal
 */
final readonly class AgentToolDefinitionMapper
{
    /** @var list<string> */
    private const HIDDEN_PARAM_NAMES = ['aiProvider', 'workspaceId'];

    public function __construct(
        private McpToolIntrospectorService $toolIntrospector,
    ) {}

    /**
     * @param list<array<string, mixed>> $executableTools
     * @return list<AiToolDefinition>
     */
    public function mapExecutableTools(array $executableTools): array
    {
        $introspected = [];
        foreach ($this->toolIntrospector->listTools() as $tool) {
            $introspected[(string) ($tool['name'] ?? '')] = $tool;
        }

        $definitions = [];
        foreach ($executableTools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $source = $introspected[$name] ?? null;
            $definitions[] = new AiToolDefinition(
                name: $name,
                description: $this->resolveDescription($tool, is_array($source) ? $source : []),
                parameters: $this->buildParametersSchema(is_array($source) ? $source : []),
            );
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $source
     */
    private function resolveDescription(array $tool, array $source): string
    {
        $className = (string) ($source['className'] ?? '');
        if ($className !== '' && class_exists($className)) {
            $attributes = (new \ReflectionClass($className))->getAttributes(McpDualModeTool::class);
            if ($attributes !== []) {
                $dual = $attributes[0]->newInstance();
                if (trim($dual->nativeDescription) !== '') {
                    return $dual->nativeDescription;
                }
            }
        }

        $intent = is_array($tool['intent'] ?? null) ? $tool['intent'] : (is_array($source['intent'] ?? null) ? $source['intent'] : null);
        if (is_array($intent) && trim((string) ($intent['summary'] ?? '')) !== '') {
            return (string) $intent['summary'];
        }

        return (string) ($tool['description'] ?? ($source['description'] ?? ''));
    }

    /**
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    private function buildParametersSchema(array $tool): array
    {
        $properties = [];
        $required = [];
        $className = (string) ($tool['className'] ?? '');
        $previewable = ($tool['previewable'] ?? false) === true
            || ($className !== '' && is_subclass_of($className, McpPreviewableToolInterface::class));

        $described = [];
        foreach (is_array($tool['params'] ?? null) ? $tool['params'] : [] as $param) {
            if (is_array($param) && is_string($param['name'] ?? null)) {
                $described[$param['name']] = trim((string) ($param['description'] ?? ''));
            }
        }

        if ($className !== '' && class_exists($className) && method_exists($className, 'execute')) {
            $reflection = new ReflectionMethod($className, 'execute');
            foreach ($reflection->getParameters() as $parameter) {
                if ($parameter->getAttributes(McpContentParam::class) !== []) {
                    continue;
                }
                $name = $parameter->getName();
                if (in_array($name, self::HIDDEN_PARAM_NAMES, true)) {
                    continue;
                }
                $properties[$name] = [
                    'type' => $this->mapJsonType($this->parameterTypeName($parameter)),
                    // Introspected @param text; the model chooses arguments from it.
                    'description' => $described[$name] ?? '',
                ];
                if ($properties[$name]['type'] === 'array') {
                    $properties[$name] = array_merge($properties[$name], $this->arrayShape($reflection, $name));
                }
                if (!$parameter->isOptional() && !$parameter->isDefaultValueAvailable()) {
                    $required[] = $name;
                }
            }
        } else {
            $params = is_array($tool['params'] ?? null) ? $tool['params'] : [];
            foreach ($params as $param) {
                if (!is_array($param)) {
                    continue;
                }
                $name = (string) ($param['name'] ?? '');
                if ($name === '' || in_array($name, self::HIDDEN_PARAM_NAMES, true)) {
                    continue;
                }
                $properties[$name] = [
                    'type' => $this->mapJsonType((string) ($param['type'] ?? 'string')),
                    'description' => (string) ($param['description'] ?? ''),
                ];
                if (($param['required'] ?? false) === true) {
                    $required[] = $name;
                }
            }
        }

        if ($previewable) {
            if (!isset($properties['variants'])) {
                $properties['variants'] = [
                    'type' => 'number',
                    'description' => 'Number of suggestion variants to generate (1–5, default 3).',
                ];
            }
            if ((string) ($tool['name'] ?? '') === 't3ai_generate_all_seo' && !isset($properties['fieldKeys'])) {
                $properties['fieldKeys'] = [
                    'type' => 'array',
                    'description' => 'Optional SEO field subset (metaTitle, metaDescription, keywords, ogTitle, ogDescription). Empty = all five. Aliases seo_title→metaTitle accepted.',
                    'items' => ['type' => 'string'],
                ];
            }
        }

        $schema = [
            'type' => 'object',
            // Empty array here; OpenAI HTTP path converts to {} in AiToolDefinition::toProviderShape().
            // Symfony AI path json_decodes to [] so Serializer never sees stdClass.
            'properties' => $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private function parameterTypeName(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        return 'string';
    }

    private function mapJsonType(string $phpType): string
    {
        $normalized = strtolower(trim(explode('|', $phpType)[0]));

        return match ($normalized) {
            'int', 'float' => 'number',
            'bool' => 'boolean',
            'array' => 'array',
            default => 'string',
        };
    }

    /**
     * JSON schema for an array parameter from its @param type: list<string> / string[] / array<int> → array with
     * items, array<string, T> → object. Providers such as OpenAI reject arrays without items.
     *
     * @return array<string, mixed>
     */
    private function arrayShape(ReflectionMethod $method, string $name): array
    {
        $doc = (string) $method->getDocComment();
        $type = '';
        if (preg_match('/@param\s+(\S+(?:<[^>]*>)?)\s+\$' . preg_quote($name, '/') . '\b/', $doc, $match) === 1) {
            $type = strtolower($match[1]);
        }

        if (preg_match('/^array<\s*string\s*,/', $type) === 1) {
            return ['type' => 'object'];
        }
        $item = '';
        if (preg_match('/^(?:list|array|non-empty-list|non-empty-array)<(?:\s*int\s*,)?\s*([a-z]+)/', $type, $match) === 1) {
            $item = $match[1];
        } elseif (preg_match('/^([a-z]+)\[\]$/', $type, $match) === 1) {
            $item = $match[1];
        }

        return ['items' => ['type' => match ($item) {
            'int', 'integer', 'float' => 'number',
            'bool' => 'boolean',
            'array' => 'object',
            default => 'string',
        }]];
    }
}
