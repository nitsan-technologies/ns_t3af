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

use NITSAN\NsT3AF\Api\AiCreditUnits;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
final class LocalReceiptCache
{
    private const TABLE = 'tx_nst3af_credit_receipt';

    private const MAX_ROWS = 50;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function storeFromCharge(string $requestUuid, string $featureKey, array $payload): void
    {
        $credits = is_array($payload['credits'] ?? null) ? $payload['credits'] : [];
        $charged = is_array($payload['charged'] ?? null) ? $payload['charged'] : [];
        $cost = AiCreditUnits::parseCost($payload, $charged);
        $buckets = AiCreditUnits::parseBalanceBuckets($credits);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $tokensInput = (int) ($payload['tokens_input'] ?? 0);
        $tokensOutput = (int) ($payload['tokens_output'] ?? 0);
        $tokensTotal = (int) (
            $payload['tokens_total']
            ?? $charged['tokens_total']
            ?? ($tokensInput + $tokensOutput)
        );

        $connection->insert(
            self::TABLE,
            [
                'request_uuid' => $requestUuid,
                'feature_key' => $featureKey,
                'model' => (string) ($charged['model'] ?? $payload['model'] ?? ''),
                'bucket' => (string) ($charged['bucket'] ?? ''),
                'cost_units' => $cost['units'],
                'cost' => $cost['credits'],
                'balance_free' => $buckets['freeCredits'],
                'balance_paid' => $buckets['paidCredits'],
                'plan_used' => $buckets['planUsedCredits'],
                'plan_total' => $buckets['planTotalCredits'],
                'crdate' => time(),
                'extra' => json_encode($payload, JSON_THROW_ON_ERROR),
            ],
        );

        $this->trimOldRows();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 10): array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $rows = $connection->select(
            ['*'],
            self::TABLE,
            [],
            [],
            ['crdate' => 'DESC'],
            $limit,
        )->fetchAllAssociative();

        return $rows;
    }

    private function trimOldRows(): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $count = (int) $connection->count('*', self::TABLE, []);
        if ($count <= self::MAX_ROWS) {
            return;
        }

        $uids = $connection->select(
            ['uid'],
            self::TABLE,
            [],
            [],
            ['crdate' => 'ASC'],
            $count - self::MAX_ROWS,
        )->fetchFirstColumn();

        foreach ($uids as $uid) {
            $connection->delete(self::TABLE, ['uid' => (int) $uid]);
        }
    }
}
