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

namespace NITSAN\NsT3AF\Mcp\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use NITSAN\NsT3AF\Mcp\Domain\Enum\TokenType;
use NITSAN\NsT3AF\Mcp\Domain\Model\OAuthToken;
use NITSAN\NsT3AF\Mcp\OAuth\OAuthTokenPair;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class TokenRepository
{
    public const LABEL_N8N = 'n8n token';
    public const LABEL_MANUS = 'manus token';
    public const LABEL_MCP_REMOTE = 'mcp-remote token';

    private const TABLE = 'tx_nst3af_oauth_token';

    public function __construct(private ConnectionPool $connectionPool) {}

    public function findByAccessTokenHash(#[\SensitiveParameter] string $hash): ?OAuthToken
    {
        $row = $this->fetchRowByField('access_token_hash', $hash);

        return $row !== null ? OAuthToken::fromRow($row) : null;
    }

    public function findByRefreshTokenHash(#[\SensitiveParameter] string $hash): ?OAuthToken
    {
        $row = $this->fetchRowByField('refresh_token_hash', $hash);

        return $row !== null ? OAuthToken::fromRow($row) : null;
    }

    public function findByUid(int $uid): ?OAuthToken
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? OAuthToken::fromRow($row) : null;
    }

    /**
     * @return list<OAuthToken>
     */
    public function findActiveForUser(int $beUserUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('revoked', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->gte('access_token_expires', $queryBuilder->createNamedParameter(time(), ParameterType::INTEGER)),
            )
            ->orderBy('crdate', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): OAuthToken => OAuthToken::fromRow($row), $rows);
    }

    public function countActiveForUser(int $beUserUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('revoked', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->gte('access_token_expires', $queryBuilder->createNamedParameter(time(), ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    public function countActiveGlobal(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('revoked', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->gte('access_token_expires', $queryBuilder->createNamedParameter(time(), ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<OAuthToken>
     */
    public function findAllActive(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('revoked', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->gte('access_token_expires', $queryBuilder->createNamedParameter(time(), ParameterType::INTEGER)),
            )
            ->orderBy('last_used_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): OAuthToken => OAuthToken::fromRow($row), $rows);
    }

    public function hasPersonalBearerToken(int $beUserUid): bool
    {
        return $this->findPersonalBearerTokenRow($beUserUid) !== null;
    }

    /**
     * @return array{uid: int, preview: string}|null
     */
    public function findPersonalBearerToken(int $beUserUid): ?array
    {
        $row = $this->findPersonalBearerTokenRow($beUserUid);
        if ($row === null) {
            return null;
        }

        $token = OAuthToken::fromRow($row);

        return [
            'uid' => $token->uid,
            'preview' => $token->preview(),
        ];
    }

    public function issuePersonalBearerToken(
        int $beUserUid,
        string $username,
        int $accessLifetime,
        int $refreshLifetime,
        int $maxActiveTokens,
    ): string {
        if ($this->countActiveForUser($beUserUid) >= $maxActiveTokens) {
            throw new \RuntimeException('Maximum active tokens reached', 1712100100);
        }

        return $this->issueBearerPair(
            clientId: 'personal',
            beUserUid: $beUserUid,
            workspaceId: 0,
            label: $username . "'s personal token",
            accessLifetime: $accessLifetime,
            refreshLifetime: $refreshLifetime,
        )->accessToken;
    }

    public function issueBearerPair(
        string $clientId,
        int $beUserUid,
        int $workspaceId,
        string $label,
        int $accessLifetime,
        int $refreshLifetime,
        string $scope = '',
    ): OAuthTokenPair {
        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));
        $now = time();

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'token_type' => TokenType::Bearer->value,
            'access_token_hash' => hash('sha256', $accessToken),
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'client_id' => $clientId,
            'be_user' => $beUserUid,
            'workspace_id' => $workspaceId,
            'scope' => $scope,
            'label' => $label,
            'access_token_expires' => $now + $accessLifetime,
            'refresh_token_expires' => $now + $refreshLifetime,
            'revoked' => 0,
            'last_used_at' => $now,
            'crdate' => $now,
        ]);

        return new OAuthTokenPair(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresIn: $accessLifetime,
        );
    }

    public function issueMcpRemoteToken(
        int $beUserUid,
        int $workspaceId,
        string $label,
        int $lifetimeSeconds,
    ): string {
        $accessToken = bin2hex(random_bytes(32));
        $now = time();

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'token_type' => TokenType::McpRemoteUrl->value,
            'access_token_hash' => hash('sha256', $accessToken),
            'refresh_token_hash' => '',
            'client_id' => '',
            'be_user' => $beUserUid,
            'workspace_id' => $workspaceId,
            'scope' => 'mcp:read mcp:write mcp:tools',
            'label' => $label,
            'access_token_expires' => $now + $lifetimeSeconds,
            'refresh_token_expires' => 0,
            'revoked' => 0,
            'last_used_at' => $now,
            'crdate' => $now,
        ]);

        return $accessToken;
    }

    public function findActiveByLabel(int $beUserUid, string $label): ?OAuthToken
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('label', $queryBuilder->createNamedParameter($label)),
                $queryBuilder->expr()->eq('revoked', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->gte('access_token_expires', $queryBuilder->createNamedParameter(time(), ParameterType::INTEGER)),
            )
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? OAuthToken::fromRow($row) : null;
    }

    public function issueClientBearerToken(
        int $beUserUid,
        string $clientKey,
        string $label,
        int $workspaceId,
        int $accessLifetime,
        int $refreshLifetime,
        int $maxActiveTokens,
        string $scope = 'mcp:read mcp:write mcp:tools',
    ): string {
        if ($this->findActiveByLabel($beUserUid, $label) !== null) {
            throw new \RuntimeException('An active token already exists for this client.', 1712100200);
        }

        if ($this->countActiveForUser($beUserUid) >= $maxActiveTokens) {
            throw new \RuntimeException('Maximum active tokens reached', 1712100100);
        }

        return $this->issueBearerPair(
            clientId: $clientKey,
            beUserUid: $beUserUid,
            workspaceId: $workspaceId,
            label: $label,
            accessLifetime: $accessLifetime,
            refreshLifetime: $refreshLifetime,
            scope: $scope,
        )->accessToken;
    }

    public function ensurePersonalBearerToken(
        int $beUserUid,
        string $username,
        int $accessLifetime,
        int $refreshLifetime,
        int $maxActiveTokens,
    ): ?string {
        if ($this->hasPersonalBearerToken($beUserUid)) {
            return null;
        }

        return $this->issuePersonalBearerToken($beUserUid, $username, $accessLifetime, $refreshLifetime, $maxActiveTokens);
    }

    public function revokeByUid(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, ['revoked' => 1], ['uid' => $uid]);
    }

    /**
     * Conditionally revoke an active token row (S-05).
     *
     * Returns true only when this call flipped `revoked` from 0 → 1, so concurrent
     * refresh attempts cannot both succeed.
     */
    public function revokeIfActive(int $uid): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $affected = $connection->update(
            self::TABLE,
            ['revoked' => 1],
            ['uid' => $uid, 'revoked' => 0],
        );

        return $affected > 0;
    }

    /**
     * Revoke every active token for a client+user pair (refresh-token reuse family).
     */
    public function revokeAllForClientAndUser(string $clientId, int $beUserUid): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        return $connection->update(
            self::TABLE,
            ['revoked' => 1],
            [
                'client_id' => $clientId,
                'be_user' => $beUserUid,
                'revoked' => 0,
            ],
        );
    }

    public function revokeByPlainToken(#[\SensitiveParameter] string $plainToken): void
    {
        $hash = hash('sha256', $plainToken);
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            ['revoked' => 1],
            ['access_token_hash' => $hash],
        );
        $connection->update(
            self::TABLE,
            ['revoked' => 1],
            ['refresh_token_hash' => $hash],
        );
    }

    public function revokeAllForUser(int $beUserUid): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        return $connection->update(
            self::TABLE,
            ['revoked' => 1],
            ['be_user' => $beUserUid, 'revoked' => 0],
        );
    }

    public function updateLastUsed(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, ['last_used_at' => time()], ['uid' => $uid]);
    }

    public function deleteExpiredAndRevoked(): int
    {
        $now = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        return (int) $connection->delete(self::TABLE, [
            'revoked' => 1,
        ]) + (int) $connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE access_token_expires > 0 AND access_token_expires < ? AND refresh_token_expires > 0 AND refresh_token_expires < ?',
            [$now, $now],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPersonalBearerTokenRow(int $beUserUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('client_id', $queryBuilder->createNamedParameter('personal')),
                $queryBuilder->expr()->eq('token_type', $queryBuilder->createNamedParameter(TokenType::Bearer->value)),
                $queryBuilder->expr()->eq('revoked', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->gte('access_token_expires', $queryBuilder->createNamedParameter(time(), ParameterType::INTEGER)),
            )
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRowByField(string $field, string $value): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }
}
