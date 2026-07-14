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

use NITSAN\NsT3AF\Service\AiLogChannelCatalog;
use NITSAN\NsT3AF\Utility\SysLogWriterUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Read, aggregate, and delete AI-related sys_log entries.
 */
final class AiSysLogRepository
{
    private const TABLE = 'sys_log';

    public function __construct(
        private readonly AiLogChannelCatalog $channelCatalog,
    ) {}

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function findFiltered(array $filters = [], int $limit = 200, int $offset = 0): array
    {
        $qb = $this->createBaseQueryBuilder();
        $qb->select(...SysLogWriterUtility::getReadableColumns())
            ->orderBy('tstamp', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->applyFilters($qb, $filters);

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(
            static fn(array $row): array => SysLogWriterUtility::normalizeLogRow($row),
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countFiltered(array $filters = []): int
    {
        $qb = $this->createBaseQueryBuilder();
        $qb->count('uid');
        $this->applyFilters($qb, $filters);

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{total:int,info:int,warning:int,error:int,lastEntryTstamp:int}
     */
    public function getStatistics(array $filters = []): array
    {
        $qb = $this->createBaseQueryBuilder();
        $qb->select('level')
            ->addSelectLiteral(
                $qb->expr()->count('uid', 'count'),
                $qb->expr()->max('tstamp', 'max_tstamp'),
            );
        $this->applyFilters($qb, $filters);
        $qb->groupBy('level');

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $stats = [
            'total' => 0,
            'info' => 0,
            'warning' => 0,
            'error' => 0,
            'lastEntryTstamp' => 0,
        ];

        foreach ($rows as $row) {
            $count = (int) ($row['count'] ?? 0);
            $level = strtolower((string) ($row['level'] ?? ''));
            $stats['total'] += $count;
            if ($level === 'info') {
                $stats['info'] += $count;
            } elseif ($level === 'warning') {
                $stats['warning'] += $count;
            } elseif ($level === 'error') {
                $stats['error'] += $count;
            }
            $stats['lastEntryTstamp'] = max($stats['lastEntryTstamp'], (int) ($row['max_tstamp'] ?? 0));
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function findForExport(array $filters = [], int $maxRows = 5000): array
    {
        return $this->findFiltered($filters, max(1, min($maxRows, 5000)), 0);
    }

    /**
     * @param list<int> $uids
     */
    public function deleteByUids(array $uids): int
    {
        $uids = array_values(array_filter(array_map('intval', $uids), static fn(int $id): bool => $id > 0));
        if ($uids === []) {
            return 0;
        }

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->delete(self::TABLE)
            ->where(
                $qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->in(
                    'channel',
                    $qb->createNamedParameter($this->channelCatalog->getAllScopedChannelValues(), Connection::PARAM_STR_ARRAY),
                ),
            );

        return $qb->executeStatement();
    }

    public function deleteOlderThan(int $cutoffTimestamp): int
    {
        if ($cutoffTimestamp <= 0) {
            return 0;
        }

        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->delete(self::TABLE)
            ->where(
                $qb->expr()->lt('tstamp', $qb->createNamedParameter($cutoffTimestamp, Connection::PARAM_INT)),
                $qb->expr()->in(
                    'channel',
                    $qb->createNamedParameter($this->channelCatalog->getAllScopedChannelValues(), Connection::PARAM_STR_ARRAY),
                ),
            );

        return $qb->executeStatement();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters($qb, array $filters): void
    {
        $channelValues = $this->resolveChannelConstraint($filters);
        $qb->where(
            $qb->expr()->in('channel', $qb->createNamedParameter($channelValues, Connection::PARAM_STR_ARRAY)),
        );

        $this->applyPeriodFilter($qb, $filters);
        $this->applyLevelFilter($qb, $filters);
        $this->applySearchFilter($qb, $filters);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    private function resolveChannelConstraint(array $filters): array
    {
        $logChannel = $this->channelCatalog->normalizeLogChannel((string) ($filters['logChannel'] ?? 'all'));
        $extension = $this->channelCatalog->normalizeExtension((string) ($filters['extension'] ?? 'all'));

        if ($logChannel !== AiLogChannelCatalog::FILTER_ALL) {
            return [$logChannel];
        }

        return $this->channelCatalog->resolveChannelValuesForExtension($extension);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyPeriodFilter($qb, array $filters): void
    {
        $from = (int) ($filters['fromTimestamp'] ?? 0);
        $to = (int) ($filters['toTimestamp'] ?? 0);
        if ($from > 0) {
            $qb->andWhere(
                $qb->expr()->gte('tstamp', $qb->createNamedParameter($from, Connection::PARAM_INT)),
            );
        }
        if ($to > 0) {
            $qb->andWhere(
                $qb->expr()->lte('tstamp', $qb->createNamedParameter($to, Connection::PARAM_INT)),
            );
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyLevelFilter($qb, array $filters): void
    {
        if (empty($filters['level'])) {
            return;
        }

        $qb->andWhere(
            $qb->expr()->eq('level', $qb->createNamedParameter((string) $filters['level'], Connection::PARAM_STR)),
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applySearchFilter($qb, array $filters): void
    {
        if (empty($filters['search'])) {
            return;
        }

        $search = '%' . $qb->escapeLikeWildcards((string) $filters['search']) . '%';
        $columns = SysLogWriterUtility::getReadableColumns();
        $conditions = [];

        if (in_array('details', $columns, true)) {
            $conditions[] = $qb->expr()->like(
                'details',
                $qb->createNamedParameter($search, Connection::PARAM_STR),
            );
        }
        if (in_array('message', $columns, true)) {
            $conditions[] = $qb->expr()->like(
                'message',
                $qb->createNamedParameter($search, Connection::PARAM_STR),
            );
        }

        if ($conditions !== []) {
            $qb->andWhere($qb->expr()->or(...$conditions));
        }
    }

    private function createBaseQueryBuilder()
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->from(self::TABLE);

        return $qb;
    }
}
