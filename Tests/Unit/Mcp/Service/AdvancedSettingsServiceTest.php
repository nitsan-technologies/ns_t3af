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
        $extensionSettings->method('getAllIgnorePid')->with('ns_t3af')->willReturn([]);

        $service = new AdvancedSettingsService($extensionSettings);

        self::assertSame(AdvancedSettingsService::DEFAULT_MAX_BODY_BYTES, $service->maxBodyBytes());
        self::assertSame(100 * 1024 * 1024, $service->maxBodyBytes());
    }

    public function testMaxBodyBytesUsesConfiguredValue(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->with('ns_t3af')->willReturn([
            'mcpMaxBodyBytes' => '33554432',
        ]);

        $service = new AdvancedSettingsService($extensionSettings);

        self::assertSame(33554432, $service->maxBodyBytes());
    }

    public function testMaxBodyBytesFallsBackWhenConfiguredValueInvalid(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->with('ns_t3af')->willReturn([
            'mcpMaxBodyBytes' => '0',
        ]);

        $service = new AdvancedSettingsService($extensionSettings);

        self::assertSame(AdvancedSettingsService::DEFAULT_MAX_BODY_BYTES, $service->maxBodyBytes());
    }

    public function testAllExposesMaxBodyBytesForAdvancedForm(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->with('ns_t3af')->willReturn([
            'mcpMaxBodyBytes' => '33554432',
        ]);

        $service = new AdvancedSettingsService($extensionSettings);

        self::assertSame(33554432, $service->all()['mcpMaxBodyBytes']);
    }

    public function testSaveRejectsZeroAccessTokenLifetime(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->expects(self::never())->method('mergeGlobal');
        $extensionSettings->method('getAllIgnorePid')->with('ns_t3af')->willReturn([]);

        $service = new AdvancedSettingsService($extensionSettings);
        $result = $service->save(['accessTokenLifetime' => '0']);

        self::assertFalse($result['ok']);
        self::assertNotSame('', $result['message']);
    }

    public function testSaveRejectsUnknownOauthScope(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->expects(self::never())->method('mergeGlobal');
        $extensionSettings->method('getAllIgnorePid')->with('ns_t3af')->willReturn([]);

        $service = new AdvancedSettingsService($extensionSettings);
        $result = $service->save(['oauthDefaultScopes' => 'bogus:scope']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Unknown OAuth scope', $result['message']);
    }

    public function testSaveRejectsInvalidRedirectUri(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->expects(self::never())->method('mergeGlobal');
        $extensionSettings->method('getAllIgnorePid')->with('ns_t3af')->willReturn([]);

        $service = new AdvancedSettingsService($extensionSettings);
        $result = $service->save(['oauthDefaultRedirectUris' => 'not a url <script>x</script>']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Invalid OAuth redirect URI', $result['message']);
    }

    public function testSavePersistsValidAdvancedValues(): void
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->expects(self::once())->method('mergeGlobal')->with(
            'ns_t3af',
            self::callback(static function (array $normalized): bool {
                return ($normalized['accessTokenLifetime'] ?? null) === '3600'
                    && ($normalized['oauthDefaultScopes'] ?? null) === 'mcp:read mcp:write';
            }),
        );
        $extensionSettings->method('getAllIgnorePid')->with('ns_t3af')->willReturn([]);

        $service = new AdvancedSettingsService($extensionSettings);
        $result = $service->save([
            'accessTokenLifetime' => '3600',
            'oauthDefaultScopes' => 'mcp:read  mcp:write',
        ]);

        self::assertTrue($result['ok']);
    }
}
