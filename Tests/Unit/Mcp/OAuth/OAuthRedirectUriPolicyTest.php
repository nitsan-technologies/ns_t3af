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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\OAuth;

use NITSAN\NsT3AF\Mcp\OAuth\OAuthRedirectUriPolicy;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OAuthRedirectUriPolicyTest extends TestCase
{
    #[Test]
    public function allowsAnyUriWhenAllowListIsEmpty(): void
    {
        $settings = $this->createMock(AdvancedSettingsService::class);
        $settings->method('oauthDefaultRedirectUris')->willReturn('');

        $policy = new OAuthRedirectUriPolicy($settings);

        self::assertFalse($policy->isEnforced());
        self::assertTrue($policy->isAllowed('https://evil.example/callback'));
        self::assertFalse($policy->isAllowed('https://user@evil.example/callback'));
    }

    #[Test]
    public function enforcesConfiguredRedirectUris(): void
    {
        $settings = $this->createMock(AdvancedSettingsService::class);
        $settings->method('oauthDefaultRedirectUris')->willReturn("https://claude.ai/api/mcp/auth_callback\nhttp://127.0.0.1:*");

        $policy = new OAuthRedirectUriPolicy($settings);

        self::assertTrue($policy->isAllowed('https://claude.ai/api/mcp/auth_callback'));
        self::assertTrue($policy->isAllowed('http://127.0.0.1:6274/callback'));
        self::assertFalse($policy->isAllowed('https://attacker.example/callback'));
    }

    #[Test]
    public function prefixEntryMatchesClientRedirectUris(): void
    {
        $settings = $this->createMock(AdvancedSettingsService::class);
        $settings->method('oauthDefaultRedirectUris')->willReturn("cursor://*\nhttp://127.0.0.1:*");

        $policy = new OAuthRedirectUriPolicy($settings);

        self::assertTrue($policy->isAllowed('cursor://anysphere.cursor-mcp/oauth/callback'));
        self::assertTrue($policy->isAllowed('http://127.0.0.1:6274/callback'));
        self::assertFalse($policy->isAllowed('https://evil.example/callback'));
    }

    #[Test]
    public function cursorShorthandAllowsCurrentCursorRemoteOAuthUris(): void
    {
        $settings = $this->createMock(AdvancedSettingsService::class);
        $settings->method('oauthDefaultRedirectUris')->willReturn('cursor://*');

        $policy = new OAuthRedirectUriPolicy($settings);

        self::assertTrue($policy->isAllowed('https://www.cursor.com/agents/mcp/oauth/callback'));
        self::assertTrue($policy->isAllowed('http://localhost:8787/callback'));
    }

    #[Test]
    public function broadHttpPrefixPatternsDoNotMatch(): void
    {
        $settings = $this->createMock(AdvancedSettingsService::class);
        $settings->method('oauthDefaultRedirectUris')->willReturn('https://*');

        $policy = new OAuthRedirectUriPolicy($settings);

        self::assertFalse($policy->isAllowed('https://attacker.example/oauth/callback'));
    }

    #[Test]
    public function rejectsRedirectUriWithUserinfoBypass(): void
    {
        $settings = $this->createMock(AdvancedSettingsService::class);
        $settings->method('oauthDefaultRedirectUris')->willReturn('cursor://*');

        $policy = new OAuthRedirectUriPolicy($settings);

        self::assertFalse($policy->isAllowed('http://localhost:@evil.example/cb'));
        self::assertFalse($policy->isAllowed('http://127.0.0.1:@evil.example/cb'));
    }

    #[Test]
    public function redirectHostIgnoresUserinfoBypass(): void
    {
        self::assertSame('', OAuthRedirectUriPolicy::redirectHost('http://localhost:@evil.example/cb'));
        self::assertSame('www.cursor.com', OAuthRedirectUriPolicy::redirectHost('https://www.cursor.com/agents/mcp/oauth/callback'));
    }
}
