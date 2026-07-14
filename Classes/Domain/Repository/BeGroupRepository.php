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
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

final class BeGroupRepository
{
    public const TABLE = 'be_groups';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $rows = $qb
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $row = $qb
            ->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, int> group uid => member count
     */
    public function memberCountsByGroup(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $rows = $qb
            ->selectLiteral('usergroup')
            ->from('be_users')
            ->where($qb->expr()->neq('usergroup', $qb->createNamedParameter('')))
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $groups = array_filter(array_map('intval', explode(',', (string) ($row['usergroup'] ?? ''))));
            foreach ($groups as $groupUid) {
                $counts[$groupUid] = ($counts[$groupUid] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $uid, array $fields): void
    {
        if ($uid <= 0 || $fields === []) {
            return;
        }

        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, $fields, ['uid' => $uid]);
    }
}
