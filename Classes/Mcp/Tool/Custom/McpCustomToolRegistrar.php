<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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

namespace NITSAN\NsT3AF\Mcp\Tool\Custom;

use Mcp\Server\Builder;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpCustomToolRepository;
use NITSAN\NsT3AF\Mcp\Service\McpToolSchemaAugmenter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers backend-defined custom MCP tools (handler type "php") onto the MCP server.
 *
 * Each custom tool row points at a fully-qualified class name from any extension. The class is a
 * plain public service exposing a public {@code execute()} method; this registrar advertises it as
 * an MCP tool using the stored label (tool key) and description, with the input schema derived from
 * the {@code execute()} signature via {@see McpToolSchemaAugmenter::generateForCustomToolHandler()}.
 *
 * @internal
 */
readonly class McpCustomToolRegistrar
{
    public function __construct(
        private McpCustomToolRepository $customToolRepository,
        private McpToolSchemaAugmenter $toolSchemaAugmenter,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {}

    /**
     * Collect and validate the custom tools with handler type "php".
     *
     * @return list<array{class: class-string, name: string, description: string, resolvable: bool}>
     */
    public function collectPhpTools(): array
    {
        $tools = [];

        foreach ($this->customToolRepository->findVisible() as $row) {
            if ($row['handlerType'] !== 'php') {
                continue;
            }

            $class = $row['handlerValue'];
            if (!class_exists($class)) {
                $this->logger->warning('Custom MCP tool class not found; skipping.', [
                    'toolKey' => $row['toolKey'],
                    'class' => $class,
                ]);

                continue;
            }

            if (!method_exists($class, 'execute')) {
                $this->logger->warning('Custom MCP tool class has no execute() method; skipping.', [
                    'toolKey' => $row['toolKey'],
                    'class' => $class,
                ]);

                continue;
            }

            $resolvable = $this->container->has($class);
            if (!$resolvable) {
                $this->logger->warning(
                    'Custom MCP tool class is not a public service; it will run without dependency injection'
                    . ' or centralized error handling. Register it with "public: true" in your extension.',
                    [
                        'toolKey' => $row['toolKey'],
                        'class' => $class,
                    ],
                );
            }

            $description = $row['description'] !== ''
                ? $row['description']
                : 'Custom tool: ' . $row['label'];

            /** @var class-string $class */
            $tools[] = [
                'class' => $class,
                'name' => $row['toolKey'],
                'description' => $description,
                'resolvable' => $resolvable,
            ];
        }

        return $tools;
    }

    /**
     * @param list<array{class: class-string, name: string, description: string, resolvable: bool}> $tools
     */
    public function registerCollected(Builder $builder, array $tools): void
    {
        foreach ($tools as $tool) {
            $handler = [$tool['class'], 'execute'];

            try {
                $builder->addTool(
                    handler: $handler,
                    name: $tool['name'],
                    description: $tool['description'],
                    inputSchema: $this->toolSchemaAugmenter->generateForCustomToolHandler($handler),
                );
            } catch (\Throwable $exception) {
                $this->logger->error('Failed to register custom MCP tool; skipping.', [
                    'toolKey' => $tool['name'],
                    'class' => $tool['class'],
                    'exception' => $exception,
                ]);
            }
        }
    }

    /**
     * Build the handler type map (class => "tool") for resolvable custom tools so the
     * {@see \NITSAN\NsT3AF\Mcp\Server\ErrorHandlingContainer} wraps them in the error proxy.
     *
     * @param list<array{class: class-string, name: string, description: string, resolvable: bool}> $tools
     * @return array<class-string, 'tool'>
     */
    public function handlerTypeMap(array $tools): array
    {
        $map = [];
        foreach ($tools as $tool) {
            if ($tool['resolvable']) {
                $map[$tool['class']] = 'tool';
            }
        }

        return $map;
    }
}
