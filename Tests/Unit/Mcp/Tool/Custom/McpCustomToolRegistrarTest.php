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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Custom;

use Mcp\Server;
use Mcp\Server\Builder;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpCustomToolRepository;
use NITSAN\NsT3AF\Mcp\Service\McpConnectedProviderEnumResolver;
use NITSAN\NsT3AF\Mcp\Service\McpModeResolver;
use NITSAN\NsT3AF\Mcp\Service\McpToolSchemaAugmenter;
use NITSAN\NsT3AF\Mcp\Service\McpWorkspaceEnumResolver;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use NITSAN\NsT3AF\Mcp\Tool\Custom\McpCustomToolRegistrar;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Custom\Fixtures\SampleCustomTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
final class McpCustomToolRegistrarTest extends TestCase
{
    #[Test]
    public function collectPhpToolsReturnsValidatedPhpRows(): void
    {
        $repository = $this->createRepository([
            $this->row(toolKey: 'shipping_quote', label: 'Shipping Quote', description: 'Quote shipping cost.', handlerType: 'php', handlerValue: SampleCustomTool::class),
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with(SampleCustomTool::class)->willReturn(true);

        $registrar = $this->createRegistrar($repository, $container);

        $tools = $registrar->collectPhpTools();

        self::assertCount(1, $tools);
        self::assertSame(SampleCustomTool::class, $tools[0]['class']);
        self::assertSame('shipping_quote', $tools[0]['name']);
        self::assertSame('Quote shipping cost.', $tools[0]['description']);
        self::assertTrue($tools[0]['resolvable']);
    }

    #[Test]
    public function collectPhpToolsFallsBackToGeneratedDescription(): void
    {
        $repository = $this->createRepository([
            $this->row(toolKey: 'shipping_quote', label: 'Shipping Quote', description: '', handlerType: 'php', handlerValue: SampleCustomTool::class),
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);

        $registrar = $this->createRegistrar($repository, $container);

        $tools = $registrar->collectPhpTools();

        self::assertSame('Custom tool: Shipping Quote', $tools[0]['description']);
    }

    #[Test]
    public function collectPhpToolsIgnoresNonPhpHandlerTypes(): void
    {
        $repository = $this->createRepository([
            $this->row(toolKey: 'rest_tool', label: 'Rest', description: '', handlerType: 'rest', handlerValue: 'https://example.com/api'),
            $this->row(toolKey: 'webhook_tool', label: 'Webhook', description: '', handlerType: 'webhook', handlerValue: 'https://example.com/hook'),
        ]);
        $container = $this->createMock(ContainerInterface::class);

        $registrar = $this->createRegistrar($repository, $container);

        self::assertSame([], $registrar->collectPhpTools());
    }

    #[Test]
    public function collectPhpToolsSkipsMissingClassAndLogsWarning(): void
    {
        $repository = $this->createRepository([
            $this->row(toolKey: 'ghost', label: 'Ghost', description: '', handlerType: 'php', handlerValue: 'NITSAN\\NsT3AF\\Does\\Not\\Exist'),
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('warning');

        $registrar = $this->createRegistrar($repository, $container, $logger);

        self::assertSame([], $registrar->collectPhpTools());
    }

    #[Test]
    public function collectPhpToolsMarksUnresolvableClassesAndWarns(): void
    {
        $repository = $this->createRepository([
            $this->row(toolKey: 'shipping_quote', label: 'Shipping Quote', description: 'x', handlerType: 'php', handlerValue: SampleCustomTool::class),
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('warning');

        $registrar = $this->createRegistrar($repository, $container, $logger);

        $tools = $registrar->collectPhpTools();

        self::assertFalse($tools[0]['resolvable']);
    }

    #[Test]
    public function handlerTypeMapIncludesOnlyResolvableTools(): void
    {
        $registrar = $this->createRegistrar($this->createRepository([]), $this->createMock(ContainerInterface::class));

        $map = $registrar->handlerTypeMap([
            ['class' => SampleCustomTool::class, 'name' => 'a', 'description' => 'a', 'resolvable' => true],
            ['class' => 'NITSAN\\NsT3AF\\Ghost', 'name' => 'b', 'description' => 'b', 'resolvable' => false],
        ]);

        self::assertSame([SampleCustomTool::class => 'tool'], $map);
    }

    #[Test]
    public function registerCollectedAddsToolToBuilderWithReflectedSchema(): void
    {
        $registrar = $this->createRegistrar($this->createRepository([]), $this->createMock(ContainerInterface::class));
        $builder = Server::builder();

        $registrar->registerCollected($builder, [
            ['class' => SampleCustomTool::class, 'name' => 'shipping_quote', 'description' => 'Quote shipping cost.', 'resolvable' => true],
        ]);

        $registeredTools = $this->readBuilderTools($builder);

        self::assertCount(1, $registeredTools);
        self::assertSame('shipping_quote', $registeredTools[0]['name']);
        self::assertSame('Quote shipping cost.', $registeredTools[0]['description']);
        self::assertSame([SampleCustomTool::class, 'execute'], $registeredTools[0]['handler']);
        self::assertIsArray($registeredTools[0]['inputSchema']);
        self::assertArrayHasKey('country', $registeredTools[0]['inputSchema']['properties']);
        self::assertArrayHasKey('weight', $registeredTools[0]['inputSchema']['properties']);
        self::assertArrayNotHasKey('aiProvider', $registeredTools[0]['inputSchema']['properties']);
        self::assertArrayNotHasKey('workspaceId', $registeredTools[0]['inputSchema']['properties']);
    }

    #[Test]
    public function registerCollectedSkipsFailingToolWithoutBreakingOthers(): void
    {
        $registrar = $this->createRegistrar($this->createRepository([]), $this->createMock(ContainerInterface::class));
        $builder = Server::builder();

        $registrar->registerCollected($builder, [
            ['class' => 'NITSAN\\NsT3AF\\Does\\Not\\Exist', 'name' => 'broken', 'description' => 'b', 'resolvable' => true],
            ['class' => SampleCustomTool::class, 'name' => 'shipping_quote', 'description' => 'ok', 'resolvable' => true],
        ]);

        $registeredTools = $this->readBuilderTools($builder);

        self::assertCount(1, $registeredTools);
        self::assertSame('shipping_quote', $registeredTools[0]['name']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createRepository(array $rows): McpCustomToolRepository
    {
        $repository = $this->createMock(McpCustomToolRepository::class);
        $repository->method('findVisible')->willReturn($rows);

        return $repository;
    }

    private function createRegistrar(
        McpCustomToolRepository $repository,
        ContainerInterface $container,
        ?LoggerInterface $logger = null,
    ): McpCustomToolRegistrar {
        return new McpCustomToolRegistrar(
            $repository,
            $this->createAugmenter(),
            $container,
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }

    private function createAugmenter(): McpToolSchemaAugmenter
    {
        $workspaceList = $this->createMock(WorkspaceListService::class);
        $workspaceList->method('list')->willReturn([]);

        $providerResolver = $this->createMock(McpConnectedProviderEnumResolver::class);
        $providerResolver->method('resolveEnum')->willReturn([]);
        $providerResolver->method('buildDescription')->willReturn('');

        $settings = $this->createMock(ExtensionSettingsService::class);
        $settings->method('getAllIgnorePid')->willReturn(['mcpMode' => McpModeResolver::MODE_CONTEXT]);

        return new McpToolSchemaAugmenter(
            new McpWorkspaceEnumResolver($workspaceList),
            $providerResolver,
            new McpModeResolver($settings),
        );
    }

    /**
     * @return array{
     *     uid: int,
     *     toolKey: string,
     *     label: string,
     *     description: string,
     *     handlerType: string,
     *     handlerValue: string,
     *     parameters: list<array<string, mixed>>,
     *     hidden: bool,
     *     deleted: bool,
     *     crdate: int,
     *     tstamp: int
     * }
     */
    private function row(
        string $toolKey,
        string $label,
        string $description,
        string $handlerType,
        string $handlerValue,
    ): array {
        return [
            'uid' => 1,
            'toolKey' => $toolKey,
            'label' => $label,
            'description' => $description,
            'handlerType' => $handlerType,
            'handlerValue' => $handlerValue,
            'parameters' => [],
            'hidden' => false,
            'deleted' => false,
            'crdate' => 0,
            'tstamp' => 0,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readBuilderTools(Builder $builder): array
    {
        $reflection = new \ReflectionProperty(Builder::class, 'tools');

        /** @var list<array<string, mixed>> $tools */
        $tools = $reflection->getValue($builder);

        return $tools;
    }
}
