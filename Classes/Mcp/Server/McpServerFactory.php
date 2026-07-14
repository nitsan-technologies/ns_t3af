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

namespace NITSAN\NsT3AF\Mcp\Server;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use NITSAN\NsT3AF\Mcp\Domain\Repository\SessionRepository;
use NITSAN\NsT3AF\Mcp\Logging\AuditLogger;
use NITSAN\NsT3AF\Mcp\Prompt\McpDynamicPromptRegistrar;
use NITSAN\NsT3AF\Mcp\Server\Session\DatabaseSessionStore;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolLogService;
use NITSAN\NsT3AF\Mcp\Service\McpInvocationContext;
use NITSAN\NsT3AF\Mcp\Service\McpToolDescriptionResolver;
use NITSAN\NsT3AF\Mcp\Service\McpToolSchemaAugmenter;
use NITSAN\NsT3AF\Mcp\Tool\Custom\McpCustomToolRegistrar;
use NITSAN\NsT3AF\Mcp\Tool\Dynamic\NsT3afDynamicToolRegistrar;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class McpServerFactory
{
    public const VERSION = '1.0.0';

    private const DEFAULT_SESSION_LIFETIME = 86400;

    private int $sessionLifetime;

    /**
     * @param iterable<object> $tools
     * @param iterable<object> $prompts
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private AuditLogger $auditLogger,
        private McpToolLogService $toolLogService,
        private SessionRepository $sessionRepository,
        ExtensionSettingsService $extensionSettingsService,
        private iterable $tools,
        private iterable $prompts,
        private NsT3afDynamicToolRegistrar $dynamicToolRegistrar,
        private McpCustomToolRegistrar $customToolRegistrar,
        private McpDynamicPromptRegistrar $dynamicPromptRegistrar,
        private McpToolSchemaAugmenter $toolSchemaAugmenter,
        private McpToolDescriptionResolver $toolDescriptionResolver,
        private McpInvocationContext $invocationContext,
    ) {
        $config = $extensionSettingsService->getAll('ns_t3af');
        $sessionLifetime = $config['sessionLifetime'] ?? null;
        $resolved = is_numeric($sessionLifetime) ? (int) $sessionLifetime : self::DEFAULT_SESSION_LIFETIME;
        $this->sessionLifetime = $resolved > 0 ? $resolved : self::DEFAULT_SESSION_LIFETIME;
    }

    /**
     * @return list<string>
     */
    public function listToolNames(): array
    {
        $names = [];
        foreach ($this->tools as $tool) {
            $attribute = $this->getMethodAttribute($tool, McpTool::class);
            $names[] = $attribute?->name ?? $tool::class;
        }

        return $names;
    }

    public function create(): Server
    {
        try {
            $sessionStore = new DatabaseSessionStore($this->sessionRepository, $this->sessionLifetime);

            $customTools = $this->customToolRegistrar->collectPhpTools();
            $handlerTypes = array_merge(
                $this->buildHandlerTypeMap(),
                $this->customToolRegistrar->handlerTypeMap($customTools),
            );
            $errorHandlingContainer = new ErrorHandlingContainer(
                $this->container,
                $this->logger,
                $this->auditLogger,
                $this->toolLogService,
                $handlerTypes,
            );

            $referenceHandler = new ContextualReferenceHandler(
                $this->invocationContext,
                new ReferenceHandler($errorHandlingContainer),
            );

            $builder = Server::builder()
                ->setServerInfo('AI Foundation MCP Server', self::VERSION)
                ->setContainer($errorHandlingContainer)
                ->setReferenceHandler($referenceHandler)
                ->setSession($sessionStore)
                ->setPaginationLimit(500);

            foreach ($this->tools as $tool) {
                $attribute = $this->getMethodAttribute($tool, McpTool::class);
                $handler = [$tool::class, 'execute'];
                $builder->addTool(
                    $handler,
                    $attribute?->name,
                    description: $this->toolDescriptionResolver->resolve($tool, $attribute?->description),
                    inputSchema: $this->toolSchemaAugmenter->generateForClassHandler($handler),
                );
            }

            foreach ($this->prompts as $prompt) {
                $attribute = $this->getMethodAttribute($prompt, McpPrompt::class);
                $builder->addPrompt([$prompt::class, 'execute'], $attribute?->name);
            }

            $this->dynamicToolRegistrar->register($builder);
            $this->customToolRegistrar->registerCollected($builder, $customTools);
            $this->dynamicPromptRegistrar->register($builder);

            return $builder->build();
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Failed to build MCP server: ' . $exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    /**
     * @param class-string<T> $attributeClass
     * @return T|null
     * @template T of object
     */
    private function getMethodAttribute(object $instance, string $attributeClass): ?object
    {
        $reflection = new ReflectionMethod($instance, 'execute');
        $attributes = $reflection->getAttributes($attributeClass);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /** @return array<class-string, 'tool'> */
    private function buildHandlerTypeMap(): array
    {
        $map = [];

        foreach ($this->tools as $tool) {
            $map[$tool::class] = 'tool';
        }

        return $map;
    }
}
