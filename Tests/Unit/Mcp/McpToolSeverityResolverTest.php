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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp;

use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\McpToolSeverityResolver;
use NITSAN\NsT3AF\Mcp\Tool\Dynamic\NsT3afDynamicToolRegistrar;
use NITSAN\NsT3AF\Mcp\Tool\Pages\PagesGetTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @internal
 */
final class McpToolSeverityResolverTest extends TestCase
{
    private McpToolSeverityResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new McpToolSeverityResolver();
    }

    #[Test]
    public function resolveForHandlerUsesClassLevelAttribute(): void
    {
        $severity = $this->resolver->resolveForHandler(new PagesGetTool(
            $this->createStub(\NITSAN\NsT3AF\Mcp\Service\RecordService::class),
            $this->createStub(\NITSAN\NsT3AF\Mcp\Service\TcaSchemaService::class),
        ));

        self::assertSame(ToolSeverity::Read, $severity);
    }

    #[Test]
    public function resolveFromReflectionPrefersMethodAttributeOverClass(): void
    {
        $reflection = new ReflectionMethod(SeverityFixtureWriteMethod::class, 'execute');

        self::assertSame(ToolSeverity::Write, $this->resolver->resolveFromReflection($reflection));
    }

    #[Test]
    public function resolveForDynamicToolNameMapsKnownOperations(): void
    {
        self::assertSame(ToolSeverity::Read, $this->resolver->resolveForDynamicToolName('blog_post_list'));
        self::assertSame(ToolSeverity::Read, $this->resolver->resolveForDynamicToolName('blog_post_get'));
        self::assertSame(ToolSeverity::Write, $this->resolver->resolveForDynamicToolName('blog_post_create'));
        self::assertSame(ToolSeverity::Write, $this->resolver->resolveForDynamicToolName('blog_post_update_batch'));
        self::assertSame(ToolSeverity::Destructive, $this->resolver->resolveForDynamicToolName('blog_post_delete'));
        self::assertSame(ToolSeverity::Destructive, $this->resolver->resolveForDynamicToolName('blog_post_delete_batch'));
    }

    #[Test]
    public function resolveForDynamicToolNameReturnsNullForUnknownTools(): void
    {
        self::assertNull($this->resolver->resolveForDynamicToolName('custom_ping'));
    }

    #[Test]
    public function dynamicRegistrarExposesOperationSeverityMap(): void
    {
        self::assertSame(
            McpToolSeverityResolver::dynamicOperationSeverityMap(),
            NsT3afDynamicToolRegistrar::operationSeverityMap(),
        );
    }

    #[Test]
    public function resolveFromToolNameHeuristicsClassifiesSatelliteTools(): void
    {
        self::assertSame(ToolSeverity::Read, $this->resolver->resolveFromToolNameHeuristics('t3ai_mass_seo_queue_list'));
        self::assertSame(ToolSeverity::Read, $this->resolver->resolveFromToolNameHeuristics('t3aa_get_page_speed'));
        self::assertSame(ToolSeverity::Read, $this->resolver->resolveFromToolNameHeuristics('t3aa_summarize_content'));
        self::assertSame(ToolSeverity::Read, $this->resolver->resolveFromToolNameHeuristics('t3cs_list_datasources'));
        self::assertSame(ToolSeverity::Read, $this->resolver->resolveFromToolNameHeuristics('t3ac_chatbot_settings'));

        self::assertSame(ToolSeverity::Write, $this->resolver->resolveFromToolNameHeuristics('t3ai_generate_all_seo'));
        self::assertSame(ToolSeverity::Write, $this->resolver->resolveFromToolNameHeuristics('t3ai_mass_translation_queue_add'));
        self::assertSame(ToolSeverity::Write, $this->resolver->resolveFromToolNameHeuristics('t3aa_update_file_metadata'));
        self::assertSame(ToolSeverity::Write, $this->resolver->resolveFromToolNameHeuristics('t3cs_save_datasource'));
    }

    #[Test]
    public function resolveForToolNamePrefersDynamicOperationOverHeuristics(): void
    {
        self::assertSame(ToolSeverity::Read, $this->resolver->resolveForToolName('blog_post_list'));
        self::assertSame(ToolSeverity::Write, $this->resolver->resolveForToolName('t3ai_create_page_simple'));
    }
}

#[McpToolSeverity(ToolSeverity::Read)]
final class SeverityFixtureWriteMethod
{
    #[McpToolSeverity(ToolSeverity::Write)]
    public function execute(): void {}
}
