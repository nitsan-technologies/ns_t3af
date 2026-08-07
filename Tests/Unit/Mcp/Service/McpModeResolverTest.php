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

use NITSAN\NsT3AF\Mcp\Service\McpModeResolver;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class McpModeResolverTest extends TestCase
{
    #[Test]
    public function defaultsToContextMode(): void
    {
        $settings = $this->createMock(ExtensionSettingsService::class);
        $settings->method('getAllIgnorePid')->with('ns_t3af')->willReturn([]);

        $resolver = new McpModeResolver($settings);

        self::assertTrue($resolver->isContext());
        self::assertFalse($resolver->isNative());
        self::assertSame(McpModeResolver::MODE_CONTEXT, $resolver->getMode());
    }

    #[Test]
    public function resolvesNativeModeFromExtensionConfiguration(): void
    {
        $settings = $this->createMock(ExtensionSettingsService::class);
        $settings->method('getAllIgnorePid')->with('ns_t3af')->willReturn(['mcpMode' => 'native']);

        $resolver = new McpModeResolver($settings);

        self::assertTrue($resolver->isNative());
        self::assertFalse($resolver->isContext());
    }

    #[Test]
    public function invalidModeFallsBackToContext(): void
    {
        $settings = $this->createMock(ExtensionSettingsService::class);
        $settings->method('getAllIgnorePid')->with('ns_t3af')->willReturn(['mcpMode' => 'invalid']);

        $resolver = new McpModeResolver($settings);

        self::assertTrue($resolver->isContext());
    }

    #[Test]
    public function readsModeFromAnySitePid(): void
    {
        $settings = $this->createMock(ExtensionSettingsService::class);
        $settings->expects(self::once())
            ->method('getAllIgnorePid')
            ->with('ns_t3af')
            ->willReturn(['mcpMode' => 'native']);

        $resolver = new McpModeResolver($settings);

        self::assertTrue($resolver->isNative());
    }
}
