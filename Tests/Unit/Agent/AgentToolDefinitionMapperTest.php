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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentToolDefinitionMapper;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentToolDefinitionMapperTest extends TestCase
{
    #[Test]
    public function previewableAllSeoSchemaExposesVariantsAndFieldKeys(): void
    {
        $introspector = $this->createMock(McpToolIntrospectorService::class);
        $introspector->method('listTools')->willReturn([
            [
                'name' => 't3ai_generate_all_seo',
                'description' => 'Generate SEO fields',
                'previewable' => true,
                'params' => [
                    ['name' => 'pageId', 'type' => 'int', 'required' => true],
                    ['name' => 'aiProvider', 'type' => 'string', 'required' => false],
                    ['name' => 'workspaceId', 'type' => 'int', 'required' => false],
                ],
            ],
        ]);

        $mapper = new AgentToolDefinitionMapper($introspector);
        $definitions = $mapper->mapExecutableTools([
            [
                'name' => 't3ai_generate_all_seo',
                'description' => 'Generate SEO fields',
            ],
        ]);

        self::assertCount(1, $definitions);
        $parameters = $definitions[0]->parameters;
        self::assertIsArray($parameters['properties'] ?? null);
        $properties = $parameters['properties'];
        self::assertArrayHasKey('pageId', $properties);
        self::assertArrayHasKey('variants', $properties);
        self::assertArrayHasKey('fieldKeys', $properties);
        self::assertArrayNotHasKey('aiProvider', $properties);
        self::assertArrayNotHasKey('workspaceId', $properties);
        self::assertSame('array', $properties['fieldKeys']['type'] ?? null);
    }

    #[Test]
    public function nonSeoPreviewableDoesNotInjectFieldKeys(): void
    {
        $introspector = $this->createMock(McpToolIntrospectorService::class);
        $introspector->method('listTools')->willReturn([
            [
                'name' => 't3aa_update_file_metadata',
                'description' => 'Update file metadata',
                'previewable' => true,
                'params' => [
                    ['name' => 'fileUid', 'type' => 'int', 'required' => true],
                ],
            ],
        ]);

        $mapper = new AgentToolDefinitionMapper($introspector);
        $definitions = $mapper->mapExecutableTools([
            ['name' => 't3aa_update_file_metadata', 'description' => 'Update file metadata'],
        ]);

        $properties = $definitions[0]->parameters['properties'] ?? [];
        self::assertIsArray($properties);
        self::assertArrayHasKey('variants', $properties);
        self::assertArrayNotHasKey('fieldKeys', $properties);
    }

    #[Test]
    public function arrayParametersGetItemsFromTheDocblock(): void
    {
        $mapper = new AgentToolDefinitionMapper($this->createMock(McpToolIntrospectorService::class));
        $shape = new \ReflectionMethod(AgentToolDefinitionMapper::class, 'arrayShape');
        $execute = new \ReflectionMethod(AgentToolDefinitionMapperArrayFixture::class, 'execute');

        self::assertSame(['items' => ['type' => 'string']], $shape->invoke($mapper, $execute, 'options'));
        self::assertSame(['items' => ['type' => 'number']], $shape->invoke($mapper, $execute, 'uids'));
        self::assertSame(['items' => ['type' => 'boolean']], $shape->invoke($mapper, $execute, 'flags'));
        self::assertSame(['type' => 'object'], $shape->invoke($mapper, $execute, 'fields'));
        // No docblock type: OpenAI still needs "items".
        self::assertSame(['items' => ['type' => 'string']], $shape->invoke($mapper, $execute, 'untyped'));
    }
}

/**
 * @internal
 */
final class AgentToolDefinitionMapperArrayFixture
{
    /**
     * @param list<string> $options
     * @param int[] $uids
     * @param array<int, bool> $flags
     * @param array<string, mixed> $fields
     * @param array<mixed> $untyped
     */
    public function execute(array $options, array $uids, array $flags, array $fields, array $untyped): string
    {
        return '';
    }
}
