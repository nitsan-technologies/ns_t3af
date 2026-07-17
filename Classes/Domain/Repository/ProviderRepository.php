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
use NITSAN\NsT3AF\Domain\Model\Provider;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

/**
 * Read/write access to the `tx_nst3af_provider` table.
 *
 * @internal
 */
final class ProviderRepository implements ProviderRepositoryInterface
{
    public const TABLE = 'tx_nst3af_provider';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * @return list<Provider>
     */
    public function findAll(bool $includeHidden = false): array
    {
        $qb = $this->queryBuilder($includeHidden);
        $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('is_default', 'DESC')
            ->addOrderBy('priority', 'ASC')
            ->addOrderBy('title', 'ASC');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(static fn(array $row): Provider => Provider::fromRow($row), $rows));
    }

    /**
     * @return list<Provider>
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
            ->addOrderBy('priority', 'ASC')
            ->addOrderBy('title', 'ASC');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(static fn(array $row): Provider => Provider::fromRow($row), $rows));
    }

    public function findByUid(int $uid): ?Provider
    {
        if ($uid <= 0) {
            return null;
        }
        $qb = $this->queryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->setMaxResults(1);

        /** @var array<string, mixed>|false $row */
        $row = $qb->executeQuery()->fetchAssociative();

        return $row === false ? null : Provider::fromRow($row);
    }

    public function identifierExistsAtStoragePid(string $identifier, int $storagePid): bool
    {
        if ($identifier === '') {
            return false;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        $count = $qb->count('uid')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('identifier', $qb->createNamedParameter($identifier)))
            ->andWhere($qb->expr()->eq('pid', $qb->createNamedParameter($storagePid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne();

        return (int) $count > 0;
    }

    public function findReusableWizardDraft(int $storagePid, string $adapterType): ?Provider
    {
        if ($adapterType === '') {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $restrictions = $qb->getRestrictions();
        $restrictions->removeAll();
        $restrictions->add(new DeletedRestriction());

        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('pid', $qb->createNamedParameter($storagePid, ParameterType::INTEGER)))
            ->andWhere($qb->expr()->eq('adapter_type', $qb->createNamedParameter($adapterType)))
            ->andWhere(
                $qb->expr()->or(
                    $qb->expr()->eq('api_key', $qb->createNamedParameter('')),
                    $qb->expr()->isNull('api_key'),
                ),
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1);

        /** @var array<string, mixed>|false $row */
        $row = $qb->executeQuery()->fetchAssociative();

        return $row === false ? null : Provider::fromRow($row);
    }

    public function findByIdentifier(string $identifier, ?int $storagePid = null): ?Provider
    {
        if ($identifier === '') {
            return null;
        }
        $qb = $this->queryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('identifier', $qb->createNamedParameter($identifier)))
            ->setMaxResults(1);

        if ($storagePid !== null && $storagePid > 0) {
            $qb->andWhere($qb->expr()->eq('pid', $qb->createNamedParameter($storagePid, ParameterType::INTEGER)));
        }

        /** @var array<string, mixed>|false $row */
        $row = $qb->executeQuery()->fetchAssociative();

        return $row === false ? null : Provider::fromRow($row);
    }

    public function findDefault(?int $storagePid = null): ?Provider
    {
        $qb = $this->queryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('is_default', $qb->createNamedParameter(1, ParameterType::INTEGER)))
            ->orderBy('priority', 'ASC')
            ->setMaxResults(1);

        if ($storagePid !== null && $storagePid > 0) {
            $qb->andWhere($qb->expr()->eq('pid', $qb->createNamedParameter($storagePid, ParameterType::INTEGER)));
        }

        /** @var array<string, mixed>|false $row */
        $row = $qb->executeQuery()->fetchAssociative();

        return $row === false ? null : Provider::fromRow($row);
    }

    public function setDefault(int $uid, int $storagePid): void
    {
        if ($uid <= 0) {
            return;
        }
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $connection = $this->connection();
        $clearCriteria = ['is_default' => 1];
        if ($storagePid > 0) {
            $clearCriteria['pid'] = $storagePid;
        }
        $connection->update(
            self::TABLE,
            ['is_default' => 0, 'tstamp' => $now],
            $clearCriteria,
        );
        $connection->update(self::TABLE, ['is_default' => 1, 'tstamp' => $now], ['uid' => $uid]);
    }

    /**
     * @param array<string, int|float|string|null> $values
     */
    public function save(int $uid, array $values): int
    {
        if ($values === []) {
            return $uid;
        }
        $now = $GLOBALS['EXEC_TIME'] ?? time();
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

    public function softDelete(int $uid): void
    {
        if ($uid <= 0) {
            return;
        }
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $this->connection()->update(self::TABLE, ['deleted' => 1, 'tstamp' => $now], ['uid' => $uid]);
    }

    /**
     * @param array<string, int|float|string|null> $values
     */
    public function updateStatus(int $uid, array $values): void
    {
        if ($uid <= 0 || $values === []) {
            return;
        }
        $allowed = ['last_status', 'last_status_at', 'last_status_message', 'last_used_at'];
        $payload = array_intersect_key($values, array_flip($allowed));
        if ($payload === []) {
            return;
        }
        $payload['tstamp'] = $GLOBALS['EXEC_TIME'] ?? time();
        $this->connection()->update(self::TABLE, $payload, ['uid' => $uid]);
    }

    private function queryBuilder(bool $includeHidden = false): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $restrictions = $qb->getRestrictions();
        $restrictions->removeAll();
        $restrictions->add(new DeletedRestriction());
        if (!$includeHidden) {
            $restrictions->add(new HiddenRestriction());
        }

        return $qb;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
