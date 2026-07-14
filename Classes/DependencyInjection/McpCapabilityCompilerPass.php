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

namespace NITSAN\NsT3AF\DependencyInjection;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\AsMcpPrompt;
use NITSAN\NsT3AF\Mcp\Attribute\AsMcpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpPromptHandlerInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpToolHandlerInterface;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Registers MCP tools and prompts from any installed extension.
 *
 * Mirrors {@see AdapterCompilerPass}: child extensions only need a DI service
 * implementing a handler interface (or carrying {@see AsMcpTool}) — no edits to
 * ns_t3af configuration required.
 *
 * @internal
 */
final class McpCapabilityCompilerPass implements CompilerPassInterface
{
    public const TAG_TOOL = 'mcp.tool';

    public const TAG_PROMPT = 'mcp.prompt';

    public function process(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(McpToolHandlerInterface::class)->addTag(self::TAG_TOOL);
        $container->registerForAutoconfiguration(McpPromptHandlerInterface::class)->addTag(self::TAG_PROMPT);

        $container->registerAttributeForAutoconfiguration(
            AsMcpTool::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag(self::TAG_TOOL);
            },
        );
        $container->registerAttributeForAutoconfiguration(
            AsMcpPrompt::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag(self::TAG_PROMPT);
            },
        );

        $this->finalizeTaggedHandlers($container, self::TAG_TOOL, McpTool::class);
        $this->finalizeTaggedHandlers($container, self::TAG_PROMPT, McpPrompt::class);
    }

    /**
     * @param class-string ...$requiredExecuteAttributes
     */
    private function finalizeTaggedHandlers(
        ContainerBuilder $container,
        string $tag,
        string ...$requiredExecuteAttributes,
    ): void {
        foreach (array_keys($container->findTaggedServiceIds($tag)) as $serviceId) {
            if (!$container->hasDefinition($serviceId)) {
                continue;
            }

            $definition = $container->findDefinition($serviceId);
            $definition->setPublic(true);

            $class = $this->resolveClass($definition);
            if ($class === null || !class_exists($class)) {
                continue;
            }

            if ($requiredExecuteAttributes === []) {
                continue;
            }

            $this->assertExecuteMethodHasAttribute($class, $serviceId, ...$requiredExecuteAttributes);
        }
    }

    private function resolveClass(Definition $definition): ?string
    {
        $class = $definition->getClass();
        if (is_string($class) && $class !== '') {
            return $class;
        }

        return null;
    }

    /**
     * @param class-string ...$attributeClasses At least one must be present on execute().
     */
    private function assertExecuteMethodHasAttribute(string $class, string $serviceId, string ...$attributeClasses): void
    {
        if (!method_exists($class, 'execute')) {
            throw new \InvalidArgumentException(sprintf(
                'MCP handler "%s" (%s) must expose a public execute() method.',
                $serviceId,
                $class,
            ));
        }

        $reflection = new ReflectionMethod($class, 'execute');
        foreach ($attributeClasses as $attributeClass) {
            if ($reflection->getAttributes($attributeClass) !== []) {
                return;
            }
        }

        $expected = implode(' or ', array_map(static fn(string $attributeClass): string => $attributeClass, $attributeClasses));
        throw new \InvalidArgumentException(sprintf(
            'MCP handler "%s" (%s) must annotate execute() with %s.',
            $serviceId,
            $class,
            $expected,
        ));
    }
}
