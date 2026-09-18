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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\McpModeOverride;
use NITSAN\NsT3AF\Mcp\Service\McpModeResolver;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class McpModeOverrideTest extends TestCase
{
    #[Test]
    public function overrideWinsOverGlobalSettingAndPopsCleanly(): void
    {
        $settings = $this->createMock(ExtensionSettingsService::class);
        $settings->method('getAllIgnorePid')->with('ns_t3af')->willReturn(['mcpMode' => 'context']);

        $override = new McpModeOverride();
        $resolver = new McpModeResolver($settings, $override);

        self::assertTrue($resolver->isContext());

        $seen = $override->run(McpModeResolver::MODE_NATIVE, static function () use ($resolver): string {
            return $resolver->getMode();
        });

        self::assertSame(McpModeResolver::MODE_NATIVE, $seen);
        self::assertTrue($resolver->isContext());
        self::assertNull($override->current());
    }

    #[Test]
    public function nestedOverridesRestoreInOrder(): void
    {
        $settings = $this->createMock(ExtensionSettingsService::class);
        $settings->method('getAllIgnorePid')->willReturn(['mcpMode' => 'native']);

        $override = new McpModeOverride();
        $resolver = new McpModeResolver($settings, $override);

        $override->run(McpModeResolver::MODE_CONTEXT, static function () use ($override, $resolver): void {
            self::assertTrue($resolver->isContext());
            $override->run(McpModeResolver::MODE_NATIVE, static function () use ($resolver): void {
                self::assertTrue($resolver->isNative());
            });
            self::assertTrue($resolver->isContext());
        });

        self::assertTrue($resolver->isNative());
    }

    #[Test]
    public function runPopsEvenWhenCallbackThrows(): void
    {
        $override = new McpModeOverride();

        try {
            $override->run(McpModeResolver::MODE_NATIVE, static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        self::assertNull($override->current());
    }
}
