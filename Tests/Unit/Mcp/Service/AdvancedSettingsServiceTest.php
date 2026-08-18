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

use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\TestCase;

final class AdvancedSettingsServiceTest extends TestCase
{
    public function testMaxBodyBytesDefaultsToSixteenMiB(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAll')->with('ns_t3af')->willReturn([]);

        $service = new AdvancedSettingsService($extensionSettings);

        self::assertSame(AdvancedSettingsService::DEFAULT_MAX_BODY_BYTES, $service->maxBodyBytes());
        self::assertSame(16 * 1024 * 1024, $service->maxBodyBytes());
    }

    public function testMaxBodyBytesUsesConfiguredValue(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAll')->with('ns_t3af')->willReturn([
            'mcpMaxBodyBytes' => '33554432',
        ]);

        $service = new AdvancedSettingsService($extensionSettings);

        self::assertSame(33554432, $service->maxBodyBytes());
    }

    public function testMaxBodyBytesFallsBackWhenConfiguredValueInvalid(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAll')->with('ns_t3af')->willReturn([
            'mcpMaxBodyBytes' => '0',
        ]);

        $service = new AdvancedSettingsService($extensionSettings);

        self::assertSame(AdvancedSettingsService::DEFAULT_MAX_BODY_BYTES, $service->maxBodyBytes());
    }

    public function testAllExposesMaxBodyBytesForAdvancedForm(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAll')->with('ns_t3af')->willReturn([
            'mcpMaxBodyBytes' => '33554432',
        ]);

        $service = new AdvancedSettingsService($extensionSettings);

        self::assertSame(33554432, $service->all()['mcpMaxBodyBytes']);
    }
}
