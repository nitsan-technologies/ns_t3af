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

namespace NITSAN\NsT3AF\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Domain\Enum\TokenType;
use NITSAN\NsT3AF\Mcp\Domain\Model\OAuthToken;
use NITSAN\NsT3AF\Mcp\Domain\Repository\TokenRepository;
use NITSAN\NsT3AF\Mcp\OAuth\OAuthClientLabelResolver;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class McpConnectionsService
{
    public function __construct(
        private TokenRepository $tokenRepository,
        private WorkspaceListService $workspaceListService,
        private OAuthClientLabelResolver $clientLabelResolver,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(): array
    {
        $tokens = $this->tokenRepository->findAllActive();
        $backendUserLabels = $this->resolveBackendUserLabels($tokens);

        return array_map(
            fn(OAuthToken $token): array => $this->formatRow($token, $backendUserLabels),
            $tokens,
        );
    }

    /**
     * @param array<int, string> $backendUserLabels
     * @return array<string, mixed>
     */
    private function formatRow(OAuthToken $token, array $backendUserLabels): array
    {
        return [
            'uid' => $token->uid,
            'client' => $this->clientLabelResolver->resolveForToken($token),
            'clientId' => $token->clientId,
            'beUserUid' => $token->beUser,
            'beUserLabel' => $backendUserLabels[$token->beUser] ?? '',
            'scope' => $token->scope,
            'connected' => $token->crdate,
            'lastActive' => $token->lastUsedAt,
            'expires' => $token->accessTokenExpires,
            'expiresSoon' => $token->expiresSoon() ? 1 : 0,
            'workspace' => $this->workspaceListService->resolveTitle($token->workspaceId),
            'workspaceId' => $token->workspaceId,
            'tokenType' => $token->tokenType->value,
            'transport' => $this->resolveTransport($token->tokenType),
            'tokenPreview' => $token->preview(),
        ];
    }

    /**
     * @param list<OAuthToken> $tokens
     * @return array<int, string>
     */
    private function resolveBackendUserLabels(array $tokens): array
    {
        $uids = array_values(array_unique(array_filter(
            array_map(static fn(OAuthToken $token): int => $token->beUser, $tokens),
            static fn(int $uid): bool => $uid > 0,
        )));

        if ($uids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'username', 'realName')
            ->from('be_users')
            ->where($queryBuilder->expr()->in(
                'uid',
                $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY),
            ))
            ->executeQuery()
            ->fetchAllAssociative();

        $labels = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }

            $realName = trim((string) ($row['realName'] ?? ''));
            $username = trim((string) ($row['username'] ?? ''));
            $labels[$uid] = $realName !== '' ? $realName . ' (' . $username . ')' : $username;
        }

        return $labels;
    }

    private function resolveTransport(TokenType $tokenType): string
    {
        return match ($tokenType) {
            TokenType::McpRemoteUrl => 'mcp-remote',
            TokenType::Bearer => 'HTTP',
        };
    }
}
