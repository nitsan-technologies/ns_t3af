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

use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Domain\RequestLog\RequestLogProviderScope;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Persistence and aggregate queries for `tx_nst3af_request_log`.
 *
 * The dashboard reads from this repository only; callers should not query the
 * table directly to keep schema evolution isolated.
 *
 * @internal Not final so unit tests can substitute doubles for governance listeners.
 */
class RequestLogRepository
{
    public const TABLE = 'tx_nst3af_request_log';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * @param array<string, int|float|string|null> $values
     */
    public function add(array $values): void
    {
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $payload = $values;
        $payload['pid'] = 0;
        $payload['crdate'] = $now;
        $payload['tstamp'] = $now;
        $payload['hidden'] = 0;
        $payload['deleted'] = 0;

        $this->connection()->insert(self::TABLE, $payload);
    }

    /**
     * Count a backend user's requests since a timestamp (rate-limit window).
     *
     * Includes both successful and failed rows so a flood of failures still
     * counts against the limit. Historic rows predating the `be_user_id`
     * column carry 0 and never match a real user id.
     */
    public function countRecentRequestsByUser(int $userId, int $sinceTimestamp): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $qb = $this->queryBuilder();

        return (int) $qb
            ->count('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('be_user_id', $qb->createNamedParameter($userId, Connection::PARAM_INT)),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($sinceTimestamp, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Sum credits debited for a backend user since a timestamp (monthly caps).
     */
    public function sumCreditsUsedByUserSince(int $userId, int $sinceTimestamp): float
    {
        if ($userId <= 0) {
            return 0.0;
        }

        $qb = $this->queryBuilder();

        $sum = $qb
            ->selectLiteral('COALESCE(SUM(credits_used), 0) AS total')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('be_user_id', $qb->createNamedParameter($userId, Connection::PARAM_INT)),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($sinceTimestamp, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return (float) $sum;
    }

    /**
     * @return array{totalRequests:int,totalTokens:int,totalCost:float,successRate:float}
     * @param list<int> $providerUids
     */
    public function totals(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'COUNT(*) AS total_requests',
            'COALESCE(SUM(total_tokens), 0) AS total_tokens',
            'COALESCE(SUM(estimated_cost), 0) AS total_cost',
            'COALESCE(SUM(success), 0) AS success_count',
            'COALESCE(SUM(credits_used), 0) AS credits_used',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);

        /** @var array<string, scalar|null>|false $row */
        $row = $qb->executeQuery()->fetchAssociative();
        $totalRequests = (int) ($row['total_requests'] ?? 0);
        $successCount = (int) ($row['success_count'] ?? 0);

        return [
            'totalRequests' => $totalRequests,
            'totalTokens' => (int) ($row['total_tokens'] ?? 0),
            'totalCost' => (float) ($row['total_cost'] ?? 0.0),
            'totalCredits' => (float) ($row['credits_used'] ?? 0.0),
            'successRate' => $totalRequests > 0 ? round(($successCount / $totalRequests) * 100, 2) : 0.0,
        ];
    }

    /**
     * @return list<array{day:string,requests:int,success:int,cost:float}>
     * @param list<int> $providerUids
     */
    public function requestsByDay(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            "DATE_FORMAT(FROM_UNIXTIME(crdate), '%Y-%m-%d') AS day",
            'COUNT(*) AS requests',
            'COALESCE(SUM(success), 0) AS success',
            'COALESCE(SUM(estimated_cost), 0) AS cost',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->groupBy('day')
            ->orderBy('day', 'ASC');

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(
            static fn(array $row): array => [
                'day' => (string) ($row['day'] ?? ''),
                'requests' => (int) ($row['requests'] ?? 0),
                'success' => (int) ($row['success'] ?? 0),
                'cost' => (float) ($row['cost'] ?? 0.0),
            ],
            $rows,
        ));
    }

    /**
     * @return list<array{day:string,credits:float}>
     * @param list<int> $providerUids
     */
    public function creditsByDay(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            "DATE_FORMAT(FROM_UNIXTIME(crdate), '%Y-%m-%d') AS day",
            'COALESCE(SUM(credits_used), 0) AS credits',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->groupBy('day')
            ->orderBy('day', 'ASC');

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(
            static fn(array $row): array => [
                'day' => (string) ($row['day'] ?? ''),
                'credits' => (float) ($row['credits'] ?? 0.0),
            ],
            $rows,
        ));
    }

    /**
     * @return list<array{extensionKey:string,requests:int,tokens:int,cost:float,credits:float}>
     * @param list<int> $providerUids
     */
    public function usageByExtension(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        int $limit = 12,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'extension_key',
            'COUNT(*) AS requests',
            'COALESCE(SUM(total_tokens), 0) AS tokens',
            'COALESCE(SUM(estimated_cost), 0) AS cost',
            'COALESCE(SUM(credits_used), 0) AS credits',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->groupBy('extension_key')
            ->orderBy('cost', 'DESC')
            ->setMaxResults($limit);

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(
            static fn(array $row): array => [
                'extensionKey' => (string) ($row['extension_key'] ?? ''),
                'requests' => (int) ($row['requests'] ?? 0),
                'tokens' => (int) ($row['tokens'] ?? 0),
                'cost' => (float) ($row['cost'] ?? 0.0),
                'credits' => (float) ($row['credits'] ?? 0.0),
            ],
            $rows,
        ));
    }

    /**
     * @return list<array{extensionKey:string,featureKey:string,requests:int,tokens:int,cost:float}>
     * @param list<int> $providerUids
     */
    public function usageByExtensionAndFeature(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        int $limit = 20,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'extension_key',
            'feature_key',
            'COUNT(*) AS requests',
            'COALESCE(SUM(total_tokens), 0) AS tokens',
            'COALESCE(SUM(estimated_cost), 0) AS cost',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->groupBy('extension_key', 'feature_key')
            ->orderBy('cost', 'DESC')
            ->setMaxResults($limit);

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(
            static fn(array $row): array => [
                'extensionKey' => (string) ($row['extension_key'] ?? ''),
                'featureKey' => (string) ($row['feature_key'] ?? ''),
                'requests' => (int) ($row['requests'] ?? 0),
                'tokens' => (int) ($row['tokens'] ?? 0),
                'cost' => (float) ($row['cost'] ?? 0.0),
            ],
            $rows,
        ));
    }

    /**
     * @return list<array{model:string,tokens:int,cost:float}>
     * @param list<int> $providerUids
     */
    public function topModelsByTokens(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        int $limit = 10,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'model_used',
            'COALESCE(SUM(total_tokens), 0) AS tokens',
            'COALESCE(SUM(estimated_cost), 0) AS cost',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->groupBy('model_used')
            ->orderBy('tokens', 'DESC')
            ->setMaxResults($limit);

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(
            static fn(array $row): array => [
                'model' => (string) ($row['model_used'] ?? ''),
                'tokens' => (int) ($row['tokens'] ?? 0),
                'cost' => (float) ($row['cost'] ?? 0.0),
            ],
            $rows,
        ));
    }

    /**
     * @return list<array{provider:string,requests:int,cost:float}>
     * @param list<int> $providerUids
     */
    public function providerDistribution(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'provider_identifier',
            'COUNT(*) AS requests',
            'COALESCE(SUM(estimated_cost), 0) AS cost',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->groupBy('provider_identifier')
            ->orderBy('requests', 'DESC');

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(
            static fn(array $row): array => [
                'provider' => (string) ($row['provider_identifier'] ?? ''),
                'requests' => (int) ($row['requests'] ?? 0),
                'cost' => (float) ($row['cost'] ?? 0.0),
            ],
            $rows,
        ));
    }

    /**
     * @return list<array<string, scalar>>
     * @param list<int> $providerUids
     */
    public function recent(
        int $limit = 10,
        ?int $fromTimestamp = null,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->select(
            'crdate',
            'provider_identifier',
            'extension_key',
            'feature_key',
            'model_used',
            'success',
            'total_tokens',
            'estimated_cost',
            'credits_used',
            'latency_ms',
            'error_code',
            'quality_score',
            'quality_dimensions',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        if ($fromTimestamp !== null) {
            $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        }
        $qb->orderBy('crdate', 'DESC')
            ->setMaxResults($limit);

        /** @var list<array<string, scalar>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return $rows;
    }

    /**
     * @param array{
     *   search?:string,
     *   engine?:string,
     *   model?:string,
     *   module?:string,
     *   scope?:string,
     *   reqType?:string,
     *   status?:string,
     *   user?:string,
     *   max?:int
     * } $filters
     */
    public function countFiltered(
        array $filters,
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
    ): int {
        $qb = $this->queryBuilder();
        $qb->count('uid')->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $this->applyUsageListFilters($qb, $filters);

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * @param array{
     *   search?:string,
     *   engine?:string,
     *   model?:string,
     *   module?:string,
     *   scope?:string,
     *   reqType?:string,
     *   status?:string,
     *   user?:string,
     *   max?:int
     * } $filters
     * @return list<array<string, scalar|null>>
     */
    public function findFiltered(
        array $filters,
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $qb = $this->queryBuilder();
        $qb->select(
            'uid',
            'crdate',
            'provider_identifier',
            'extension_key',
            'feature_key',
            'feature_label',
            'request_source',
            'request_type',
            'model_requested',
            'model_used',
            'success',
            'error_code',
            'error_class',
            'prompt_tokens',
            'completion_tokens',
            'total_tokens',
            'latency_ms',
            'estimated_cost',
            'credits_used',
            'currency',
            'raw_meta',
        )->from(self::TABLE);

        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $this->applyUsageListFilters($qb, $filters);
        $qb->orderBy('crdate', 'DESC');

        if ($limit > 0) {
            $qb->setFirstResult(max(0, $offset))->setMaxResults($limit);
        }

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return $rows;
    }

    /**
     * @param array{
     *   search?:string,
     *   engine?:string,
     *   model?:string,
     *   module?:string,
     *   scope?:string,
     *   reqType?:string,
     *   status?:string,
     *   user?:string,
     *   max?:int
     * } $filters
     * @return list<array<string, scalar|null>>
     */
    public function findForExport(
        array $filters,
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
    ): array {
        return $this->findFiltered($filters, $fromTimestamp, $toTimestamp, $scope, 0, 0);
    }

    /**
     * @return array{
     *   engines:list<string>,
     *   models:list<string>,
     *   modules:list<string>,
     *   scopes:list<string>,
     *   reqTypes:list<string>,
     *   users:list<string>
     * }
     * @param list<int> $providerUids
     */
    public function usageFilterOptions(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        return [
            'engines' => $this->distinctColumnValues('provider_identifier', $fromTimestamp, $toTimestamp, $scope, $providerUids),
            'models' => $this->distinctColumnValues('model_used', $fromTimestamp, $toTimestamp, $scope, $providerUids),
            'modules' => $this->distinctColumnValues('extension_key', $fromTimestamp, $toTimestamp, $scope, $providerUids),
            'scopes' => $this->distinctColumnValues('feature_key', $fromTimestamp, $toTimestamp, $scope, $providerUids),
            'reqTypes' => $this->distinctColumnValues('request_type', $fromTimestamp, $toTimestamp, $scope, $providerUids),
            'users' => $this->distinctColumnValues('request_source', $fromTimestamp, $toTimestamp, $scope, $providerUids),
        ];
    }

    /**
     * @return list<array{day:string,success:int,failed:int}>
     * @param list<int> $providerUids
     */
    public function requestsByDaySuccessFail(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            "DATE_FORMAT(FROM_UNIXTIME(crdate), '%Y-%m-%d') AS day",
            'COALESCE(SUM(success), 0) AS success',
            'COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) AS failed',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->groupBy('day')->orderBy('day', 'ASC');

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(
            static fn(array $row): array => [
                'day' => (string) ($row['day'] ?? ''),
                'success' => (int) ($row['success'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
            ],
            $rows,
        ));
    }

    /**
     * @return array{success:int,failed:int}
     * @param list<int> $providerUids
     */
    public function successFailTotals(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'COALESCE(SUM(success), 0) AS success',
            'COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) AS failed',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);

        /** @var array<string, scalar|null>|false $row */
        $row = $qb->executeQuery()->fetchAssociative();

        return [
            'success' => (int) ($row['success'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }

    /**
     * @return list<array{day:string,extensionKey:string,credits:float}>
     * @param list<int> $providerUids
     */
    public function creditsByDayAndExtension(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            "DATE_FORMAT(FROM_UNIXTIME(crdate), '%Y-%m-%d') AS day",
            'extension_key',
            'COALESCE(SUM(credits_used), 0) AS credits',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->groupBy('day', 'extension_key')->orderBy('day', 'ASC');

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(
            static fn(array $row): array => [
                'day' => (string) ($row['day'] ?? ''),
                'extensionKey' => (string) ($row['extension_key'] ?? ''),
                'credits' => (float) ($row['credits'] ?? 0.0),
            ],
            $rows,
        ));
    }

    /**
     * @return list<array{day:string,provider:string,cost:float}>
     * @param list<int> $providerUids
     */
    public function costByDayAndProvider(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            "DATE_FORMAT(FROM_UNIXTIME(crdate), '%Y-%m-%d') AS day",
            'provider_identifier',
            'COALESCE(SUM(estimated_cost), 0) AS cost',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->groupBy('day', 'provider_identifier')->orderBy('day', 'ASC');

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(
            static fn(array $row): array => [
                'day' => (string) ($row['day'] ?? ''),
                'provider' => (string) ($row['provider_identifier'] ?? ''),
                'cost' => (float) ($row['cost'] ?? 0.0),
            ],
            $rows,
        ));
    }

    /**
     * @return list<array{extensionKey:string,credits:float,requests:int}>
     * @param list<int> $providerUids
     */
    public function usageByExtensionCredits(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        int $limit = 8,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'extension_key',
            'COALESCE(SUM(credits_used), 0) AS credits',
            'COUNT(*) AS requests',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->groupBy('extension_key')
            ->orderBy('credits', 'DESC')
            ->setMaxResults($limit);

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(
            static fn(array $row): array => [
                'extensionKey' => (string) ($row['extension_key'] ?? ''),
                'credits' => (float) ($row['credits'] ?? 0.0),
                'requests' => (int) ($row['requests'] ?? 0),
            ],
            $rows,
        ));
    }

    /**
     * @return list<array{featureKey:string,featureLabel:string,creditsPerRequest:float,requests:int}>
     * @param list<int> $providerUids
     */
    public function usageByFeatureCredits(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        int $limit = 8,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'feature_key',
            'MAX(feature_label) AS feature_label',
            'COALESCE(SUM(credits_used), 0) AS credits',
            'COUNT(*) AS requests',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->groupBy('feature_key')
            ->orderBy('credits', 'DESC')
            ->setMaxResults($limit);

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(
            static function (array $row): array {
                $requests = max(1, (int) ($row['requests'] ?? 0));

                return [
                    'featureKey' => (string) ($row['feature_key'] ?? ''),
                    'featureLabel' => (string) ($row['feature_label'] ?? ''),
                    'creditsPerRequest' => round((float) ($row['credits'] ?? 0.0) / $requests, 2),
                    'requests' => (int) ($row['requests'] ?? 0),
                ];
            },
            $rows,
        ));
    }

    /**
     * @return list<array{provider:string,requests:int,failed:int,cost:float,tokens:int,lastCrdate:int}>
     * @param list<int> $providerUids
     */
    public function providerStats(
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'provider_identifier',
            'COUNT(*) AS requests',
            'COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) AS failed',
            'COALESCE(SUM(estimated_cost), 0) AS cost',
            'COALESCE(SUM(total_tokens), 0) AS tokens',
            'MAX(crdate) AS last_crdate',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->groupBy('provider_identifier')->orderBy('requests', 'DESC');

        /** @var array<int, array<string, scalar|null>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_values(array_map(
            static fn(array $row): array => [
                'provider' => (string) ($row['provider_identifier'] ?? ''),
                'requests' => (int) ($row['requests'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
                'cost' => (float) ($row['cost'] ?? 0.0),
                'tokens' => (int) ($row['tokens'] ?? 0),
                'lastCrdate' => (int) ($row['last_crdate'] ?? 0),
            ],
            $rows,
        ));
    }

    public function softDeleteByUid(int $uid): int
    {
        if ($uid <= 0) {
            return 0;
        }

        $updated = $this->connection()->update(
            self::TABLE,
            [
                'deleted' => 1,
                'tstamp' => (int) ($GLOBALS['EXEC_TIME'] ?? time()),
            ],
            ['uid' => $uid],
            ['deleted' => Connection::PARAM_INT, 'tstamp' => Connection::PARAM_INT, 'uid' => Connection::PARAM_INT],
        );
        return $updated;
    }

    /**
     * @param list<int> $uids
     */
    public function softDeleteByUids(array $uids): int
    {
        $uids = array_values(array_filter(array_map('intval', $uids), static fn(int $uid): bool => $uid > 0));
        if ($uids === []) {
            return 0;
        }

        $qb = $this->queryBuilder();
        $updated = $qb->update(self::TABLE)
            ->set('deleted', 1)
            ->set('tstamp', (int) ($GLOBALS['EXEC_TIME'] ?? time()))
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeStatement();

        return max(0, $updated);
    }

    /**
     * @param array{
     *   search?:string,
     *   engine?:string,
     *   model?:string,
     *   module?:string,
     *   scope?:string,
     *   reqType?:string,
     *   status?:string,
     *   user?:string
     * } $filters
     */
    private function applyUsageListFilters(QueryBuilder $qb, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . mb_strtolower($search) . '%';
            $qb->andWhere(
                $qb->expr()->or(
                    $qb->expr()->like('LOWER(extension_key)', $qb->createNamedParameter($like)),
                    $qb->expr()->like('LOWER(feature_key)', $qb->createNamedParameter($like)),
                    $qb->expr()->like('LOWER(feature_label)', $qb->createNamedParameter($like)),
                    $qb->expr()->like('LOWER(provider_identifier)', $qb->createNamedParameter($like)),
                    $qb->expr()->like('LOWER(model_used)', $qb->createNamedParameter($like)),
                    $qb->expr()->like('LOWER(request_source)', $qb->createNamedParameter($like)),
                    $qb->expr()->like('LOWER(error_code)', $qb->createNamedParameter($like)),
                    $qb->expr()->like('LOWER(raw_meta)', $qb->createNamedParameter($like)),
                ),
            );
        }

        $engine = trim((string) ($filters['engine'] ?? ''));
        if ($engine !== '' && $engine !== 'Any') {
            $qb->andWhere($qb->expr()->eq('provider_identifier', $qb->createNamedParameter($engine)));
        }

        $model = trim((string) ($filters['model'] ?? ''));
        if ($model !== '' && $model !== 'Any') {
            $qb->andWhere($qb->expr()->eq('model_used', $qb->createNamedParameter($model)));
        }

        $module = trim((string) ($filters['module'] ?? ''));
        if ($module !== '' && $module !== 'Any') {
            $qb->andWhere($qb->expr()->eq('extension_key', $qb->createNamedParameter($module)));
        }

        $featureScope = trim((string) ($filters['scope'] ?? ''));
        if ($featureScope !== '' && $featureScope !== 'Any') {
            $qb->andWhere($qb->expr()->eq('feature_key', $qb->createNamedParameter($featureScope)));
        }

        $reqType = trim((string) ($filters['reqType'] ?? ''));
        if ($reqType !== '' && $reqType !== 'Any') {
            $qb->andWhere($qb->expr()->eq('request_type', $qb->createNamedParameter($reqType)));
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'All') {
            if ($status === 'success') {
                $qb->andWhere($qb->expr()->eq('success', $qb->createNamedParameter(1, Connection::PARAM_INT)));
            } elseif ($status === 'failed') {
                $qb->andWhere($qb->expr()->eq('success', $qb->createNamedParameter(0, Connection::PARAM_INT)));
            }
        }

        $user = trim((string) ($filters['user'] ?? ''));
        if ($user !== '' && $user !== 'ALL') {
            $qb->andWhere($qb->expr()->eq('request_source', $qb->createNamedParameter($user)));
        }
    }

    private function applyProviderScopeConstraint(QueryBuilder $qb, ?RequestLogProviderScope $scope): void
    {
        if ($scope === null) {
            return;
        }

        $creditsId = CreditsProviderIdentifier::IDENTIFIER;
        $qb->andWhere(match ($scope) {
            RequestLogProviderScope::Credits => $qb->expr()->eq(
                'provider_identifier',
                $qb->createNamedParameter($creditsId),
            ),
            RequestLogProviderScope::OwnKeys => $qb->expr()->neq(
                'provider_identifier',
                $qb->createNamedParameter($creditsId),
            ),
        });
    }

    /**
     * Restrict rows to specific provider records (per-site dashboard Own Keys scope).
     *
     * @param list<int>|null $providerUids null = no filter; empty list = no matches
     */
    private function applyProviderUidConstraint(QueryBuilder $qb, ?array $providerUids): void
    {
        if ($providerUids === null) {
            return;
        }
        if ($providerUids === []) {
            $qb->andWhere($qb->expr()->eq('provider_uid', $qb->createNamedParameter(-1, Connection::PARAM_INT)));

            return;
        }
        $qb->andWhere($qb->expr()->in(
            'provider_uid',
            $qb->createNamedParameter(array_values(array_map('intval', $providerUids)), Connection::PARAM_INT_ARRAY),
        ));
    }

    private function applyPeriodConstraint(
        QueryBuilder $qb,
        int $fromTimestamp,
        ?int $toTimestamp,
    ): void {
        $constraints = [
            $qb->expr()->gte('crdate', $qb->createNamedParameter($fromTimestamp)),
        ];
        if ($toTimestamp !== null && $toTimestamp > 0) {
            $constraints[] = $qb->expr()->lte('crdate', $qb->createNamedParameter($toTimestamp));
        }
        $qb->andWhere(...$constraints);
    }

    private function queryBuilder(): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        return $qb;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }

    private function applyVisibleConstraint(QueryBuilder $qb): void
    {
        $qb->andWhere(
            $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
        );
    }

    /**
     * @return list<string>
     * @param list<int> $providerUids
     */
    private function distinctColumnValues(
        string $column,
        int $fromTimestamp,
        ?int $toTimestamp = null,
        ?RequestLogProviderScope $scope = null,
        ?array $providerUids = null,
    ): array {
        $qb = $this->queryBuilder();
        $qb->select($column)
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyProviderScopeConstraint($qb, $scope);
        $this->applyProviderUidConstraint($qb, $providerUids);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);
        $qb->andWhere($qb->expr()->neq($column, $qb->createNamedParameter('')))
            ->groupBy($column)
            ->orderBy($column, 'ASC');

        $values = $qb->executeQuery()->fetchFirstColumn();

        return array_values(array_filter(array_map('strval', $values), static fn(string $v): bool => trim($v) !== ''));
    }

    /**
     * Aggregate request stats for dashboard feature-health cards (last N days).
     *
     * @param list<string> $featureKeyPrefixes
     * @return array{requests:int,failed:int,lastCrdate:int,lastErrorCode:string}
     */
    public function featureWindowStats(
        array $featureKeyPrefixes,
        int $fromTimestamp,
        ?int $toTimestamp = null,
    ): array {
        if ($featureKeyPrefixes === []) {
            return ['requests' => 0, 'failed' => 0, 'lastCrdate' => 0, 'lastErrorCode' => ''];
        }

        $qb = $this->queryBuilder();
        $qb->selectLiteral(
            'COUNT(*) AS requests',
            'COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) AS failed',
            'MAX(crdate) AS last_crdate',
        )
            ->from(self::TABLE);
        $this->applyVisibleConstraint($qb);
        $this->applyPeriodConstraint($qb, $fromTimestamp, $toTimestamp);

        $or = [];
        foreach ($featureKeyPrefixes as $prefix) {
            $or[] = $qb->expr()->like(
                'feature_key',
                $qb->createNamedParameter($prefix . '%'),
            );
        }
        $qb->andWhere($qb->expr()->or(...$or));

        /** @var array<string, scalar|null>|false $row */
        $row = $qb->executeQuery()->fetchAssociative();
        $lastCrdate = (int) ($row['last_crdate'] ?? 0);

        $lastErrorCode = '';
        if ($lastCrdate > 0) {
            $errorQb = $this->queryBuilder();
            $errorQb->select('error_code')
                ->from(self::TABLE);
            $this->applyVisibleConstraint($errorQb);
            $this->applyPeriodConstraint($errorQb, $fromTimestamp, $toTimestamp);
            $errorQb->andWhere(
                $errorQb->expr()->eq('success', $errorQb->createNamedParameter(0, Connection::PARAM_INT)),
                $errorQb->expr()->or(...array_map(
                    static fn(string $prefix) => $errorQb->expr()->like(
                        'feature_key',
                        $errorQb->createNamedParameter($prefix . '%'),
                    ),
                    $featureKeyPrefixes,
                )),
            )
                ->orderBy('crdate', 'DESC')
                ->setMaxResults(1);
            $lastErrorCode = (string) ($errorQb->executeQuery()->fetchOne() ?: '');
        }

        return [
            'requests' => (int) ($row['requests'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'lastCrdate' => $lastCrdate,
            'lastErrorCode' => $lastErrorCode,
        ];
    }
}
