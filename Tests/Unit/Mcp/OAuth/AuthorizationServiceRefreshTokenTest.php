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
use NITSAN\NsT3AF\Mcp\Domain\Repository\CodeRepository;
use NITSAN\NsT3AF\Mcp\Domain\Repository\TokenRepository;
use NITSAN\NsT3AF\Mcp\OAuth\AuthorizationService;
use NITSAN\NsT3AF\Mcp\OAuth\OAuthClientLabelResolver;
use NITSAN\NsT3AF\Mcp\OAuth\OAuthTokenPair;
use NITSAN\NsT3AF\Mcp\OAuth\PkceVerifier;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\TestCase;

final class AuthorizationServiceRefreshTokenTest extends TestCase
{
    public function testRefreshTokenAtomicallyRevokesBeforeIssuingNewPair(): void
    {
        $existing = $this->makeToken(uid: 7, revoked: false, expires: time() + 3600);
        $newPair = new OAuthTokenPair('access-new', 'refresh-new', 3600);

        $tokenRepository = $this->createMock(TokenRepository::class);
        $tokenRepository->expects(self::once())
            ->method('findByRefreshTokenHash')
            ->willReturn($existing);
        $tokenRepository->expects(self::once())
            ->method('revokeIfActive')
            ->with(7)
            ->willReturn(true);
        $tokenRepository->expects(self::once())
            ->method('issueBearerPair')
            ->willReturn($newPair);
        $tokenRepository->expects(self::never())->method('revokeAllForClientAndUser');

        $service = $this->makeService($tokenRepository);
        $pair = $service->refreshToken('plain-refresh', 'client-1');

        self::assertSame('access-new', $pair->accessToken);
        self::assertSame('refresh-new', $pair->refreshToken);
    }

    /**
     * S-05: concurrent refresh that loses the atomic revoke must not issue tokens.
     */
    public function testRefreshTokenFailsWhenAtomicRevokeLosesRace(): void
    {
        $existing = $this->makeToken(uid: 7, revoked: false, expires: time() + 3600);

        $tokenRepository = $this->createMock(TokenRepository::class);
        $tokenRepository->method('findByRefreshTokenHash')->willReturn($existing);
        $tokenRepository->method('revokeIfActive')->with(7)->willReturn(false);
        $tokenRepository->expects(self::never())->method('issueBearerPair');
        $tokenRepository->expects(self::never())->method('revokeAllForClientAndUser');

        $service = $this->makeService($tokenRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refresh token has been revoked');
        $service->refreshToken('plain-refresh', 'client-1');
    }

    /**
     * S-05: presenting an already-revoked refresh token revokes the whole family.
     */
    public function testRefreshTokenReuseRevokesClientUserFamily(): void
    {
        $existing = $this->makeToken(uid: 7, revoked: true, expires: time() + 3600);

        $tokenRepository = $this->createMock(TokenRepository::class);
        $tokenRepository->method('findByRefreshTokenHash')->willReturn($existing);
        $tokenRepository->expects(self::once())
            ->method('revokeAllForClientAndUser')
            ->with('client-1', 42);
        $tokenRepository->expects(self::never())->method('issueBearerPair');
        $tokenRepository->expects(self::never())->method('revokeIfActive');

        $service = $this->makeService($tokenRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refresh token has been revoked');
        $service->refreshToken('plain-refresh', 'client-1');
    }

    private function makeService(TokenRepository $tokenRepository): AuthorizationService
    {
        $settings = $this->createMock(ExtensionSettingsService::class);
        $settings->method('getAll')->willReturn([
            'accessTokenLifetime' => 3600,
            'refreshTokenLifetime' => 2592000,
            'codeLifetime' => 60,
        ]);

        $labelResolver = $this->createMock(OAuthClientLabelResolver::class);
        $labelResolver->method('resolve')->willReturn('test-client');

        return new AuthorizationService(
            $tokenRepository,
            $this->createMock(CodeRepository::class),
            $this->createMock(PkceVerifier::class),
            $this->createMock(ClientRepository::class),
            $labelResolver,
            $settings,
        );
    }

    private function makeToken(int $uid, bool $revoked, int $expires): OAuthToken
    {
        return new OAuthToken(
            uid: $uid,
            tokenType: TokenType::Bearer,
            clientId: 'client-1',
            beUser: 42,
            workspaceId: 0,
            scope: 'mcp:read',
            label: 'test',
            accessTokenExpires: time() + 60,
            refreshTokenExpires: $expires,
            revoked: $revoked,
            lastUsedAt: 0,
            crdate: time(),
            accessTokenHash: 'a',
        );
    }
}
