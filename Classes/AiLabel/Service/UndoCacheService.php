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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\AiLabel\Service;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * R10.6 cache-backed 10-minute undo for bulk label actions.
 */
final class UndoCacheService
{
    private const CACHE_IDENTIFIER = 'nst3af_ailabel_undo';
    private const BULK_TTL = 600;

    /** @var list<string> */
    private const AILABEL_FIELDS = [
        'tx_nst3af_ailabel_involvement',
        'tx_nst3af_ailabel_labelling_mode',
        'tx_nst3af_ailabel_exemption_reason',
        'tx_nst3af_ailabel_confirmed_by',
        'tx_nst3af_ailabel_confirmed_at',
        'tx_nst3af_ailabel_version_hash',
        'tx_nst3af_ailabel_public_interest',
        'tx_nst3af_ailabel_human_review',
        'tx_nst3af_ailabel_responsible_person',
    ];

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed> $previousValues
     */
    public function remember(string $table, int $uid, array $previousValues): void
    {
        $this->rememberBulk(0, [[
            'table' => $table,
            'uid' => $uid,
            'values' => $this->extractAiLabelFields($previousValues),
        ]]);
    }

    /**
     * @param list<array{table: string, uid: int, values?: array<string, mixed>}> $batch
     */
    public function rememberBulk(int $backendUserId, array $batch): void
    {
        try {
            $normalized = [];
            foreach ($batch as $item) {
                $values = $item['values'] ?? [];
                $normalized[] = [
                    'table' => $item['table'],
                    'uid' => $item['uid'],
                    'values' => $this->extractAiLabelFields($values),
                ];
            }
            $existing = $this->pullBulk($backendUserId);
            $this->cacheManager->getCache(self::CACHE_IDENTIFIER)->set(
                $this->bulkKey($backendUserId),
                array_merge($existing, $normalized),
                [],
                self::BULK_TTL,
            );
        } catch (NoSuchCacheException) {
        }
    }

    public function restoreBulk(int $backendUserId): int
    {
        $batch = $this->pullBulk($backendUserId, true);
        $restored = 0;
        foreach ($batch as $item) {
            $table = (string) ($item['table'] ?? '');
            $uid = (int) ($item['uid'] ?? 0);
            $values = $item['values'] ?? [];
            if ($table === '' || $uid <= 0 || !is_array($values)) {
                continue;
            }
            $this->connectionPool->getConnectionForTable($table)->update($table, $values, ['uid' => $uid]);
            ++$restored;
        }

        return $restored;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function restore(string $table, int $uid): ?array
    {
        try {
            $cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
            $key = $this->cacheKey($table, $uid);
            $values = $cache->get($key);
            if (!is_array($values)) {
                return null;
            }
            $cache->remove($key);

            return $values;
        } catch (NoSuchCacheException) {
            return null;
        }
    }

    /**
     * @return list<array{table: string, uid: int, values: array<string, mixed>}>
     */
    private function pullBulk(int $backendUserId, bool $remove = false): array
    {
        try {
            $cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
            $key = $this->bulkKey($backendUserId);
            $batch = $cache->get($key);
            if ($remove) {
                $cache->remove($key);
            }

            return is_array($batch) ? $batch : [];
        } catch (NoSuchCacheException) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function extractAiLabelFields(array $row): array
    {
        $fields = [];
        foreach (self::AILABEL_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                $fields[$field] = $row[$field];
            }
        }

        return $fields;
    }

    private function cacheKey(string $table, int $uid): string
    {
        return $table . '_' . $uid;
    }

    private function bulkKey(int $backendUserId): string
    {
        return 'bulk_' . max(0, $backendUserId);
    }
}
