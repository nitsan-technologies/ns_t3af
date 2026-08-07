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

namespace NITSAN\NsT3AF\Credits\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use NITSAN\NsT3AF\Api\AiCreditUnits;
use NITSAN\NsT3AF\Credits\CreditsReceiptEntryType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * @internal
 */
final class LocalReceiptCache
{
    private const TABLE = 'tx_nst3af_credit_receipt';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function storeFromCharge(string $requestUuid, string $featureKey, array $payload): void
    {
        $this->storeReceipt($requestUuid, $featureKey, $payload, CreditsReceiptEntryType::DEBIT);
    }

    /**
     * Persist a purchase / top-up / grant as a credit ledger row.
     *
     * @param array<string, mixed> $payload
     */
    public function storeFromCredit(string $requestUuid, string $featureKey, array $payload): void
    {
        $this->storeReceipt($requestUuid, $featureKey, $payload, CreditsReceiptEntryType::CREDIT);
    }

    /**
     * Upsert one History API entry into the local mirror.
     *
     * @param array<string, mixed> $entry
     */
    public function upsertFromHistoryEntry(array $entry): bool
    {
        $requestUuid = trim((string) ($entry['request_uuid'] ?? $entry['uuid'] ?? ''));
        if ($requestUuid === '') {
            return false;
        }

        $featureKey = trim((string) ($entry['feature_key'] ?? $entry['featureKey'] ?? ''));
        if ($featureKey === '') {
            $featureKey = 'unknown';
        }

        $defaultEntryType = CreditsReceiptEntryType::normalize(
            $entry['entry_type'] ?? $entry['transaction_type'] ?? null,
            CreditsReceiptEntryType::DEBIT,
        );

        $payload = $entry;
        if (!isset($payload['charged']) || !is_array($payload['charged'])) {
            $payload['charged'] = [
                'model' => (string) ($entry['model'] ?? ''),
                'bucket' => (string) ($entry['bucket'] ?? ''),
            ];
        }
        if (!isset($payload['model'])) {
            $payload['model'] = (string) ($entry['model'] ?? '');
        }

        $this->storeReceipt($requestUuid, $featureKey, $payload, $defaultEntryType);

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storeReceipt(
        string $requestUuid,
        string $featureKey,
        array $payload,
        string $defaultEntryType,
    ): void {
        $credits = is_array($payload['credits'] ?? null) ? $payload['credits'] : [];
        $charged = is_array($payload['charged'] ?? null) ? $payload['charged'] : [];
        $cost = AiCreditUnits::parseCost($payload, $charged);
        $buckets = AiCreditUnits::parseBalanceBuckets($credits);
        $entryType = CreditsReceiptEntryType::normalize(
            $payload['entry_type'] ?? $payload['transaction_type'] ?? $defaultEntryType,
            $defaultEntryType,
        );

        $crdateRaw = $payload['crdate'] ?? $payload['created_at'] ?? null;
        $crdate = is_numeric($crdateRaw) ? max(0, (int) $crdateRaw) : time();

        $row = [
            'request_uuid' => $requestUuid,
            'feature_key' => $featureKey,
            'model' => (string) ($charged['model'] ?? $payload['model'] ?? ''),
            'bucket' => (string) ($charged['bucket'] ?? $payload['bucket'] ?? ''),
            'entry_type' => $entryType,
            'cost_units' => $cost['units'],
            'cost' => $cost['credits'],
            'balance_free' => $buckets['freeCredits'],
            'balance_paid' => $buckets['paidCredits'],
            'plan_used' => $buckets['planUsedCredits'],
            'plan_total' => $buckets['planTotalCredits'],
            'crdate' => $crdate,
            'extra' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        try {
            $connection->insert(self::TABLE, $row);
        } catch (UniqueConstraintViolationException) {
            $existingClient = $this->loadExistingClientContext($connection, $requestUuid);
            if ($existingClient !== [] && (!isset($payload['client']) || !is_array($payload['client']) || $payload['client'] === [])) {
                $payload['client'] = $existingClient;
                $row['extra'] = json_encode($payload, JSON_THROW_ON_ERROR);
            } elseif ($existingClient !== [] && is_array($payload['client'] ?? null)) {
                $payload['client'] = array_merge($existingClient, $payload['client']);
                $row['extra'] = json_encode($payload, JSON_THROW_ON_ERROR);
            }
            $uuid = $row['request_uuid'];
            unset($row['request_uuid']);
            $connection->update(self::TABLE, $row, ['request_uuid' => $uuid]);
        }
    }

    /**
     * History sync replaces `extra`; keep locally stamped UI context (extension/page/latency).
     *
     * @return array<string, mixed>
     */
    private function loadExistingClientContext(Connection $connection, string $requestUuid): array
    {
        try {
            $extra = $connection->select(
                ['extra'],
                self::TABLE,
                ['request_uuid' => $requestUuid],
            )->fetchOne();
        } catch (\Throwable) {
            return [];
        }

        if (!is_string($extra) || $extra === '') {
            return [];
        }
        $decoded = json_decode($extra, true);
        if (!is_array($decoded) || !is_array($decoded['client'] ?? null)) {
            return [];
        }

        return $decoded['client'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 10, int $offset = 0, string $entryTypeFilter = CreditsReceiptEntryType::ALL): array
    {
        return $this->listBillable($limit, $offset, $entryTypeFilter);
    }

    /**
     * Billable receipts (cost_units > 0), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listBillable(
        int $limit = 10,
        int $offset = 0,
        string $entryTypeFilter = CreditsReceiptEntryType::ALL,
    ): array {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->gt('cost_units', $qb->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        $this->applyEntryTypeFilter($qb, $entryTypeFilter);

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return $rows;
    }

    public function countBillable(string $entryTypeFilter = CreditsReceiptEntryType::ALL): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->count('uid')
            ->from(self::TABLE)
            ->where($qb->expr()->gt('cost_units', $qb->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)));
        $this->applyEntryTypeFilter($qb, $entryTypeFilter);

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * Sum of cost_units for debit receipts since the given unix timestamp (inclusive).
     */
    public function sumCostUnitsSince(int $sinceTimestamp): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->addSelectLiteral(
            'COALESCE(SUM(' . $qb->quoteIdentifier('cost_units') . '), 0) AS ' . $qb->quoteIdentifier('units_sum'),
        )
            ->from(self::TABLE)
            ->where(
                $qb->expr()->gte(
                    'crdate',
                    $qb->createNamedParameter(max(0, $sinceTimestamp), \Doctrine\DBAL\ParameterType::INTEGER),
                ),
            );
        $this->applyEntryTypeFilter($qb, CreditsReceiptEntryType::DEBIT);

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * Lifetime debit spend on this install, grouped by catalog feature_key (no row limit).
     *
     * @return array<string, int> feature_key => cost_units sum
     */
    public function sumCostUnitsByFeatureKey(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->select('feature_key')
            ->addSelectLiteral(
                'COALESCE(SUM(' . $qb->quoteIdentifier('cost_units') . '), 0) AS ' . $qb->quoteIdentifier('units_sum'),
            )
            ->from(self::TABLE)
            ->groupBy('feature_key');
        $this->applyEntryTypeFilter($qb, CreditsReceiptEntryType::DEBIT);

        $map = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $key = trim((string) ($row['feature_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $map[$key] = (int) ($row['units_sum'] ?? 0);
        }

        return $map;
    }

    private function applyEntryTypeFilter(QueryBuilder $qb, string $entryTypeFilter): void
    {
        $filter = CreditsReceiptEntryType::normalizeFilter($entryTypeFilter);
        if ($filter === CreditsReceiptEntryType::ALL) {
            return;
        }

        $qb->andWhere(
            $qb->expr()->eq(
                'entry_type',
                $qb->createNamedParameter($filter),
            ),
        );
    }
}
