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

use NITSAN\NsT3AF\Credits\CreditsReceiptEntryType;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;

/**
 * Pulls T3Planet {@see T3PlanetApiClient::history()} pages into {@see LocalReceiptCache}.
 *
 * @internal
 */
final class CreditsHistorySyncService
{
    public function __construct(
        private readonly T3PlanetApiClient $apiClient,
        private readonly LocalReceiptCache $localReceiptCache,
    ) {}

    /**
     * Best-effort sync of one History page into the local receipt mirror.
     *
     * @return int Number of entries upserted
     */
    public function syncPage(
        string $domain,
        #[\SensitiveParameter]
        string $bearerToken,
        string $entryTypeFilter = CreditsReceiptEntryType::ALL,
        int $page = 1,
        int $perPage = 20,
    ): int {
        $entryTypeFilter = CreditsReceiptEntryType::normalizeFilter($entryTypeFilter);
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $payload = $this->apiClient->history($domain, $bearerToken, $entryTypeFilter, $page, $perPage);

        $entries = $this->extractEntries($payload);
        $upserted = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            try {
                if ($this->localReceiptCache->upsertFromHistoryEntry($entry)) {
                    ++$upserted;
                }
            } catch (\Throwable) {
                // Skip malformed / oversized History rows; keep syncing the rest.
                continue;
            }
        }

        return $upserted;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<mixed>
     */
    private function extractEntries(array $payload): array
    {
        foreach (['entries', 'transactions', 'items', 'rows'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values($payload[$key]);
            }
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            foreach (['entries', 'transactions', 'items', 'rows'] as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    return array_values($data[$key]);
                }
            }
            if (array_is_list($data)) {
                return $data;
            }
        }

        return [];
    }
}
