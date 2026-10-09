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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service\Backend;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Mcp\Contract\McpPreviewableToolInterface;
use NITSAN\NsT3AF\Mcp\Dto\PreviewResult;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpAnalyticsService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolLogService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolMetadataService;
use NITSAN\NsT3AF\Mcp\Service\McpInvocationContext;
use NITSAN\NsT3AF\Mcp\Service\McpModeOverride;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
final class McpPlaygroundServicePreviewClearTest extends TestCase
{
    #[Test]
    public function previewClearsInvocationContextOnSuccess(): void
    {
        $context = new McpInvocationContext($this->createMock(WorkspaceListService::class));
        $tool = new class implements McpPreviewableToolInterface {
            #[McpTool(name: 'test_preview_clear', description: 'Fixture')]
            public function execute(): void {}

            public function preview(array $arguments, int $variants = 1): PreviewResult
            {
                return new PreviewResult(
                    tool: 'test_preview_clear',
                    target: ['table' => 'pages', 'uid' => 1, 'languageId' => 0],
                    fields: [],
                    variants: [],
                );
            }
        };

        $service = $this->createPlayground([$tool], $context);
        $result = $service->preview('test_preview_clear', ['aiProvider' => 'demo']);

        self::assertTrue($result['success']);
        self::assertFalse($context->isActive());
        self::assertSame(
            'backend_module',
            $context->enrichAiOptions(new AiOptions(requestSource: 'backend_module'))->requestSource,
        );
    }

    #[Test]
    public function previewClearsInvocationContextOnFailure(): void
    {
        $context = new McpInvocationContext($this->createMock(WorkspaceListService::class));
        $tool = new class implements McpPreviewableToolInterface {
            #[McpTool(name: 'test_preview_fail', description: 'Fixture')]
            public function execute(): void {}

            public function preview(array $arguments, int $variants = 1): PreviewResult
            {
                throw new \RuntimeException('Insufficient permissions to edit page uid 1.');
            }
        };

        $service = $this->createPlayground([$tool], $context);
        $result = $service->preview('test_preview_fail', []);

        self::assertFalse($result['success']);
        self::assertFalse($context->isActive());
    }

    /**
     * @param list<object> $tools
     */
    private function createPlayground(array $tools, McpInvocationContext $context): McpPlaygroundService
    {
        // preview() does not call analytics; avoid constructing its DB deps.
        $analytics = (new ReflectionClass(McpAnalyticsService::class))->newInstanceWithoutConstructor();

        return new McpPlaygroundService(
            $tools,
            $this->createMock(McpToolIntrospectorService::class),
            new McpToolMetadataService(),
            $analytics,
            $context,
            $this->createMock(McpToolLogService::class),
            new McpModeOverride(),
        );
    }
}
