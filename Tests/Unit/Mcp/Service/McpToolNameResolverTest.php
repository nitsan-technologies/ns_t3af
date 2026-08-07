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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Service\McpToolNameResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpToolNameResolverTest extends TestCase
{
    private McpToolNameResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new McpToolNameResolver();
    }

    #[Test]
    public function resolveFromHandlerUsesMcpToolAttributeName(): void
    {
        self::assertSame(
            't3ai_create_news_advanced',
            $this->resolver->resolveFromHandler(new NamedToolHandler()),
        );
    }

    #[Test]
    public function legacyNameFromClassShortNameMatchesPreviousLoggingFormat(): void
    {
        self::assertSame('create_news_advanced', $this->resolver->legacyNameFromClassShortName('CreateNewsAdvancedTool'));
        self::assertSame('echo', $this->resolver->legacyNameFromClassShortName('EchoTool'));
        self::assertSame('cache_clear', $this->resolver->legacyNameFromClassShortName('CacheClearTool'));
    }
}

final readonly class NamedToolHandler
{
    #[McpTool(name: 't3ai_create_news_advanced', description: 'Test tool.')]
    public function execute(): string
    {
        return 'ok';
    }
}
