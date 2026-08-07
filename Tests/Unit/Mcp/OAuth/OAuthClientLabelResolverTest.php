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

use NITSAN\NsT3AF\Mcp\Domain\Enum\TokenType;
use NITSAN\NsT3AF\Mcp\Domain\Model\OAuthToken;
use NITSAN\NsT3AF\Mcp\Domain\Repository\ClientRepository;
use NITSAN\NsT3AF\Mcp\OAuth\OAuthClientLabelResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class OAuthClientLabelResolverTest extends TestCase
{
    private ClientRepository&MockObject $clientRepository;

    private OAuthClientLabelResolver $resolver;

    protected function setUp(): void
    {
        $this->clientRepository = $this->createMock(ClientRepository::class);
        $this->resolver = new OAuthClientLabelResolver($this->clientRepository);
    }

    #[Test]
    public function inferFromRedirectUrisDetectsCursor(): void
    {
        self::assertSame(
            'Cursor',
            $this->resolver->inferFromRedirectUris(['cursor://anysphere.cursor-mcp/oauth/callback']),
        );
    }

    #[Test]
    public function inferFromRedirectUrisDetectsClaudeDesktop(): void
    {
        self::assertSame(
            'Claude Desktop',
            $this->resolver->inferFromRedirectUris(['https://claude.ai/api/mcp/auth_callback']),
        );
    }

    #[Test]
    public function normalizeClientNameUsesRedirectUriWhenGeneric(): void
    {
        self::assertSame(
            'Cursor',
            $this->resolver->normalizeClientName('MCP Client', ['cursor://anysphere.cursor-mcp/oauth/callback']),
        );
    }

    #[Test]
    public function normalizeClientNameKeepsExplicitName(): void
    {
        self::assertSame(
            'My Custom Integration',
            $this->resolver->normalizeClientName('My Custom Integration', ['cursor://callback']),
        );
    }

    #[Test]
    public function resolveKeepsMeaningfulTokenLabel(): void
    {
        $this->clientRepository->expects(self::never())->method('findByClientId');

        self::assertSame('n8n token', $this->resolver->resolve('n8n', 'n8n token'));
    }

    #[Test]
    public function resolveUsesRedirectUriFromAuthorization(): void
    {
        $this->clientRepository
            ->expects(self::once())
            ->method('findByClientId')
            ->with('47bd222a8e01fc7d873d5658f0eac41b')
            ->willReturn([
                'uid' => 1,
                'client_id' => '47bd222a8e01fc7d873d5658f0eac41b',
                'client_name' => 'MCP Client',
                'redirect_uris' => json_encode(['cursor://anysphere.cursor-mcp/oauth/callback'], JSON_THROW_ON_ERROR),
                'be_user' => 0,
            ]);

        self::assertSame(
            'Cursor',
            $this->resolver->resolve(
                '47bd222a8e01fc7d873d5658f0eac41b',
                'OAuth client token',
                'cursor://anysphere.cursor-mcp/oauth/callback',
            ),
        );
    }

    #[Test]
    public function resolveForTokenFallsBackToClientIdPrefix(): void
    {
        $this->clientRepository
            ->expects(self::once())
            ->method('findByClientId')
            ->with('abcdef1234567890')
            ->willReturn([
                'uid' => 2,
                'client_id' => 'abcdef1234567890',
                'client_name' => 'MCP Client',
                'redirect_uris' => json_encode(['https://example.com/callback'], JSON_THROW_ON_ERROR),
                'be_user' => 0,
            ]);

        $token = new OAuthToken(
            uid: 1,
            tokenType: TokenType::Bearer,
            clientId: 'abcdef1234567890',
            beUser: 1,
            workspaceId: 0,
            scope: 'mcp:read',
            label: 'OAuth client token',
            accessTokenExpires: time() + 3600,
            refreshTokenExpires: 0,
            revoked: false,
            lastUsedAt: time(),
            crdate: time(),
        );

        self::assertSame('abcdef12…', $this->resolver->resolveForToken($token));
    }
}
