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

use Doctrine\DBAL\ParameterType;
use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

/**
 * @internal
 */
final class BrandContextProfileRepository implements BrandContextProfileRepositoryInterface
{
    public const TABLE = 'tx_nst3af_brand_context_profile';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * @return list<BrandContextProfile>
     */
    public function findAllByStoragePid(int $storagePid, bool $includeHidden = false): array
    {
        if ($storagePid <= 0) {
            return [];
        }

        $qb = $this->queryBuilder($includeHidden);
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('pid', $qb->createNamedParameter($storagePid, ParameterType::INTEGER)))
            ->orderBy('is_default', 'DESC')
            ->addOrderBy('brand_name', 'ASC');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(static fn(array $row): BrandContextProfile => BrandContextProfile::fromRow($row), $rows);
    }

    public function findByUid(int $uid): ?BrandContextProfile
    {
        return $this->loadByUid($uid, includeHidden: false);
    }

    public function findByUidIncludingHidden(int $uid): ?BrandContextProfile
    {
        return $this->loadByUid($uid, includeHidden: true);
    }

    private function loadByUid(int $uid, bool $includeHidden): ?BrandContextProfile
    {
        if ($uid <= 0) {
            return null;
        }

        $qb = $this->queryBuilder($includeHidden);
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->setMaxResults(1);

        /** @var array<string, mixed>|false $row */
        $row = $qb->executeQuery()->fetchAssociative();

        return $row === false ? null : BrandContextProfile::fromRow($row);
    }

    public function findDefault(int $storagePid): ?BrandContextProfile
    {
        if ($storagePid <= 0) {
            return null;
        }

        $qb = $this->queryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($storagePid, ParameterType::INTEGER)),
                $qb->expr()->eq('is_default', $qb->createNamedParameter(1, ParameterType::INTEGER)),
            )
            ->setMaxResults(1);

        /** @var array<string, mixed>|false $row */
        $row = $qb->executeQuery()->fetchAssociative();

        return $row === false ? null : BrandContextProfile::fromRow($row);
    }

    public function countByStoragePid(int $storagePid): int
    {
        if ($storagePid <= 0) {
            return 0;
        }

        $qb = $this->queryBuilder();
        $qb->count('uid')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('pid', $qb->createNamedParameter($storagePid, ParameterType::INTEGER)));

        return (int) $qb->executeQuery()->fetchOne();
    }

    public function setDefault(int $uid, int $storagePid): void
    {
        if ($uid <= 0 || $storagePid <= 0) {
            return;
        }

        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $connection = $this->connection();
        $connection->update(
            self::TABLE,
            ['is_default' => 0, 'tstamp' => $now],
            ['pid' => $storagePid, 'is_default' => 1],
        );
        $connection->update(
            self::TABLE,
            ['is_default' => 1, 'tstamp' => $now],
            ['uid' => $uid],
        );
    }

    public function setEnabled(int $uid, bool $enabled): void
    {
        if ($uid <= 0) {
            return;
        }

        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $this->connection()->update(
            self::TABLE,
            ['hidden' => $enabled ? 0 : 1, 'tstamp' => $now],
            ['uid' => $uid],
        );
    }

    /**
     * @param array<string, int|float|string|null> $values
     */
    public function save(int $uid, array $values): int
    {
        if ($values === []) {
            return $uid;
        }

        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $values['tstamp'] = $now;
        $connection = $this->connection();

        if ($uid > 0) {
            $connection->update(self::TABLE, $values, ['uid' => $uid]);

            return $uid;
        }

        $values['crdate'] = $now;
        $connection->insert(self::TABLE, $values);

        return (int) $connection->lastInsertId();
    }

    public function delete(int $uid): void
    {
        if ($uid <= 0) {
            return;
        }

        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $this->connection()->update(
            self::TABLE,
            ['deleted' => 1, 'tstamp' => $now],
            ['uid' => $uid],
        );
    }

    public function belongsToStorage(int $uid, int $storagePid): bool
    {
        if ($uid <= 0 || $storagePid <= 0) {
            return false;
        }

        $profile = $this->findByUidIncludingHidden($uid);

        return $profile !== null && $profile->pid === $storagePid;
    }

    private function queryBuilder(bool $includeHidden = false): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
        if (!$includeHidden) {
            $qb->getRestrictions()->add(new HiddenRestriction());
        }

        return $qb;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
