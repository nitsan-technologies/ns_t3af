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
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;

/**
 * Maps permitted MCP tools to provider-facing AiToolDefinition schemas.
 *
 * @internal
 */
final readonly class AgentToolDefinitionMapper
{
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
                description: (string) ($tool['description'] ?? ($source['description'] ?? $name)),
                parameters: $this->buildParametersSchema(is_array($source) ? $source : []),
            );
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    private function buildParametersSchema(array $tool): array
    {
        $properties = [];
        $required = [];
        $params = is_array($tool['params'] ?? null) ? $tool['params'] : [];

        foreach ($params as $param) {
            if (!is_array($param)) {
                continue;
            }
            $name = (string) ($param['name'] ?? '');
            if ($name === '') {
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

        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
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
}
