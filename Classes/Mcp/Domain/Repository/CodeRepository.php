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
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class CodeRepository
{
    private const TABLE = 'tx_nst3af_oauth_code';

    public function __construct(private ConnectionPool $connectionPool) {}

    public function create(
        string $clientId,
        int $beUserUid,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $redirectUri,
        int $workspaceId,
        int $codeLifetime,
        string $scope = '',
    ): string {
        $code = bin2hex(random_bytes(32));
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'authorization_code_hash' => hash('sha256', $code),
            'client_id' => $clientId,
            'be_user' => $beUserUid,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'workspace_id' => $workspaceId,
            'code_expires' => time() + $codeLifetime,
            'revoked' => 0,
        ]);

        return $code;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCodeHash(string $codeHash): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('authorization_code_hash', $queryBuilder->createNamedParameter($codeHash)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    public function revoke(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, ['revoked' => 1], ['uid' => $uid]);
    }

    public function deleteExpired(): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->lt(
                    'code_expires',
                    $queryBuilder->createNamedParameter(time(), ParameterType::INTEGER),
                ),
            )
            ->executeStatement();
    }
}
