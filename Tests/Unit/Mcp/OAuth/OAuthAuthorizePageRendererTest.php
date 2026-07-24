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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\OAuth;

use NITSAN\NsT3AF\Mcp\OAuth\OAuthAuthorizeAssetProvider;
use NITSAN\NsT3AF\Mcp\OAuth\OAuthAuthorizePageRenderer;
use NITSAN\NsT3AF\Mcp\Service\McpPathProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class OAuthAuthorizePageRendererTest extends TestCase
{
    private McpPathProvider&MockObject $pathProvider;

    private OAuthAuthorizeAssetProvider&MockObject $assetProvider;

    private OAuthAuthorizePageRenderer $renderer;

    protected function setUp(): void
    {
        $this->pathProvider = $this->createMock(McpPathProvider::class);
        $this->assetProvider = $this->createMock(OAuthAuthorizeAssetProvider::class);

        $this->pathProvider->method('getAuthorizePath')->willReturn('/mcp/oauth/authorize');
        $this->assetProvider->method('stylesheetUri')->willReturn('/typo3conf/ext/ns_t3af/Resources/Public/Css/oauth-authorize.css');
        $this->assetProvider->method('logoUri')->willReturn('/typo3conf/ext/ns_t3af/Resources/Public/Icons/HeaderLogo.svg');
        $this->assetProvider->method('backgroundUri')->willReturn('/typo3conf/ext/ns_t3af/Resources/Public/Images/oauth-login-background.jpg');
        $this->assetProvider->method('highlightColor')->willReturn('#ff8700');
        $this->assetProvider->method('footnote')->willReturn('');

        $this->renderer = new OAuthAuthorizePageRenderer(
            $this->pathProvider,
            $this->assetProvider,
        );
    }

    #[Test]
    public function renderIncludesBrandingClientNameAndFormFields(): void
    {
        $html = $this->renderer->render('Cursor', [
            'client_id' => 'abc',
            'redirect_uri' => 'cursor://callback',
            'code_challenge' => 'challenge',
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
        ], 'csrf-token-123');

        self::assertStringContainsString('AI Foundation MCP — Authorization', $html);
        self::assertStringContainsString('class="aiu-oauth-authorize__logo"', $html);
        self::assertStringContainsString('rel="icon"', $html);
        self::assertStringContainsString('type="image/svg+xml"', $html);
        self::assertStringContainsString('>Cursor</span>', $html);
        self::assertStringContainsString('name="csrf_token" value="csrf-token-123"', $html);
        self::assertStringContainsString('name="client_id" value="abc"', $html);
        self::assertStringContainsString('autocomplete="off"', $html);
        self::assertStringContainsString('class="aiu-oauth-authorize__submit"', $html);
        self::assertStringContainsString('oauth-authorize.css', $html);
        self::assertStringContainsString('--aiu-oauth-highlight: #ff8700', $html);
    }

    #[Test]
    public function renderEscapesClientNameAndShowsAuthenticationError(): void
    {
        $html = $this->renderer->render('<script>alert(1)</script>', [], 'token', 'Invalid username or password.');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('Authentication failed', $html);
        self::assertStringContainsString('Invalid username or password.', $html);
    }
}
