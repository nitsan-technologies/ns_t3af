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

namespace NITSAN\NsT3AF\Mcp\OAuth;

use NITSAN\NsT3AF\Mcp\Domain\Repository\ClientRepository;
use NITSAN\NsT3AF\Mcp\Domain\Repository\CodeRepository;
use NITSAN\NsT3AF\Mcp\Domain\Repository\TokenRepository;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class AuthorizationService
{
    private const DEFAULT_ACCESS_TOKEN_LIFETIME = 3600;

    private const DEFAULT_REFRESH_TOKEN_LIFETIME = 2592000;

    private const DEFAULT_CODE_LIFETIME = 60;

    private int $accessTokenLifetime;

    private int $refreshTokenLifetime;

    private int $codeLifetime;

    public function __construct(
        private TokenRepository $tokenRepository,
        private CodeRepository $codeRepository,
        private PkceVerifier $pkceVerifier,
        private ClientRepository $clientRepository,
        private OAuthClientLabelResolver $clientLabelResolver,
        ExtensionSettingsService $extensionSettingsService,
    ) {
        $config = $extensionSettingsService->getAll('ns_t3af');
        $this->accessTokenLifetime = $this->intConfig($config, 'accessTokenLifetime', self::DEFAULT_ACCESS_TOKEN_LIFETIME);
        $this->refreshTokenLifetime = $this->intConfig($config, 'refreshTokenLifetime', self::DEFAULT_REFRESH_TOKEN_LIFETIME);
        $this->codeLifetime = $this->intConfig($config, 'codeLifetime', self::DEFAULT_CODE_LIFETIME);
    }

    public function createAuthorizationCode(
        string $clientId,
        int $beUserUid,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $redirectUri,
        int $workspaceId = 0,
    ): string {
        if ($this->clientRepository->findByClientId($clientId) === null) {
            throw new \RuntimeException('Unknown client', 1712100001);
        }

        return $this->codeRepository->create(
            $clientId,
            $beUserUid,
            $codeChallenge,
            $codeChallengeMethod,
            $redirectUri,
            $workspaceId,
            $this->codeLifetime,
        );
    }

    public function exchangeCode(#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $codeVerifier, string $clientId, string $redirectUri): OAuthTokenPair
    {
        $row = $this->codeRepository->findByCodeHash(hash('sha256', $code));
        if ($row === null) {
            throw new \RuntimeException('Invalid authorization code', 1712100010);
        }

        if ((int) ($row['revoked'] ?? 0) === 1) {
            throw new \RuntimeException('Authorization code has been revoked', 1712100011);
        }

        if ((int) ($row['code_expires'] ?? 0) < time()) {
            throw new \RuntimeException('Authorization code has expired', 1712100012);
        }

        if (($row['client_id'] ?? '') !== $clientId) {
            throw new \RuntimeException('Client ID mismatch', 1712100013);
        }

        if (($row['redirect_uri'] ?? '') !== $redirectUri) {
            throw new \RuntimeException('Redirect URI mismatch', 1712100014);
        }

        if (!$this->pkceVerifier->verify($codeVerifier, (string) ($row['code_challenge'] ?? ''))) {
            throw new \RuntimeException('PKCE verification failed', 1712100015);
        }

        $label = $this->clientLabelResolver->resolve(
            $clientId,
            '',
            (string) ($row['redirect_uri'] ?? ''),
        );

        $tokenPair = $this->tokenRepository->issueBearerPair(
            $clientId,
            (int) ($row['be_user'] ?? 0),
            (int) ($row['workspace_id'] ?? 0),
            $label,
            $this->accessTokenLifetime,
            $this->refreshTokenLifetime,
            (string) ($row['scope'] ?? ''),
        );

        $this->codeRepository->revoke((int) ($row['uid'] ?? 0));

        return $tokenPair;
    }

    public function refreshToken(#[\SensitiveParameter] string $refreshToken, string $clientId): OAuthTokenPair
    {
        $token = $this->tokenRepository->findByRefreshTokenHash(hash('sha256', $refreshToken));
        if ($token === null) {
            throw new \RuntimeException('Invalid refresh token', 1712100020);
        }

        if ($token->clientId !== $clientId) {
            throw new \RuntimeException('Client ID mismatch', 1712100023);
        }

        if ($token->revoked) {
            // Already-revoked refresh token presented again → treat as reuse and
            // revoke the whole client+user family (S-05).
            $this->tokenRepository->revokeAllForClientAndUser($token->clientId, $token->beUser);
            throw new \RuntimeException('Refresh token has been revoked', 1712100021);
        }

        if ($token->refreshTokenExpires < time()) {
            throw new \RuntimeException('Refresh token has expired', 1712100022);
        }

        // Atomic revoke: only the first concurrent refresh wins (S-05).
        if (!$this->tokenRepository->revokeIfActive($token->uid)) {
            throw new \RuntimeException('Refresh token has been revoked', 1712100021);
        }

        $label = $this->clientLabelResolver->resolve($clientId, $token->label);

        return $this->tokenRepository->issueBearerPair(
            $clientId,
            $token->beUser,
            $token->workspaceId,
            $label,
            $this->accessTokenLifetime,
            $this->refreshTokenLifetime,
            $token->scope,
        );
    }

    /**
     * @return array{beUser: int, workspaceId: int, tokenUid: int, clientLabel: string}
     */
    public function validateAccessToken(#[\SensitiveParameter] string $accessToken): array
    {
        $token = $this->tokenRepository->findByAccessTokenHash(hash('sha256', $accessToken));
        if ($token === null) {
            throw new \RuntimeException('Invalid access token', 1712100030);
        }

        if ($token->revoked) {
            throw new \RuntimeException('Access token has been revoked', 1712100031);
        }

        if ($token->accessTokenExpires < time()) {
            throw new \RuntimeException('Access token has expired', 1712100032);
        }

        $this->tokenRepository->updateLastUsed($token->uid);

        return [
            'beUser' => $token->beUser,
            'workspaceId' => $token->workspaceId,
            'tokenUid' => $token->uid,
            'clientLabel' => $this->clientLabelResolver->resolveForToken($token),
        ];
    }

    public function revokeToken(#[\SensitiveParameter] string $token): void
    {
        $this->tokenRepository->revokeByPlainToken($token);
    }

    /**
     * @param array<string, mixed>|null $config
     */
    private function intConfig(?array $config, string $key, int $default): int
    {
        if (!is_array($config)) {
            return $default;
        }
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
