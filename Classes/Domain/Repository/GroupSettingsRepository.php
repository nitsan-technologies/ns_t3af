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

namespace NITSAN\NsT3AF\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class GroupSettingsRepository
{
    public const TABLE = 'tx_nst3af_group_settings';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findByBeGroupUid(int $beGroupUid): ?array
    {
        if ($beGroupUid <= 0) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb
            ->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('be_group', $qb->createNamedParameter($beGroupUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsertForBeGroup(int $beGroupUid, array $data): void
    {
        if ($beGroupUid <= 0) {
            return;
        }

        $existing = $this->findByBeGroupUid($beGroupUid);
        $now = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        $payload = array_merge($data, [
            'be_group' => $beGroupUid,
            'tstamp' => $now,
        ]);

        if ($existing !== null) {
            $connection->update(self::TABLE, $payload, ['uid' => (int) $existing['uid']]);
            return;
        }

        $payload['crdate'] = $now;
        $connection->insert(self::TABLE, $payload);
    }
}
