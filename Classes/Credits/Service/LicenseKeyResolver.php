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

use NITSAN\NsT3AF\Credits\Contract\LicenseDataRepositoryInterface;
use NITSAN\NsT3AF\Credits\Domain\Model\LicenseContext;

/**
 * @internal
 */
final class LicenseKeyResolver
{
    public function __construct(
        private readonly ?LicenseDataRepositoryInterface $licenseRepository,
    ) {}

    /**
     * @return list<LicenseContext>
     */
    public function listAvailable(): array
    {
        if ($this->licenseRepository === null) {
            return [];
        }

        $contexts = [];
        foreach ($this->licenseRepository->fetchAllData() as $row) {
            $key = trim((string) ($row['license_key'] ?? ''));
            if ($key === '' || !$this->isValidRow($row)) {
                continue;
            }
            $contexts[] = $this->mapRow($row);
        }

        usort(
            $contexts,
            static fn(LicenseContext $a, LicenseContext $b): int => strcmp($a->extensionKey, $b->extensionKey)
                ?: strcmp($a->licenseKey, $b->licenseKey),
        );

        return $contexts;
    }

    public function buildLicenseKeysCommaSeparated(): string
    {
        $keys = [];
        foreach ($this->listAvailable() as $context) {
            $keys[$context->licenseKey] = true;
        }

        $sorted = array_keys($keys);
        sort($sorted, SORT_STRING);

        return implode(',', $sorted);
    }

    /**
     * Keys present in $discoveredCommaSeparated but not yet in runtime storage.
     */
    public function buildNewLicenseKeysCommaSeparated(string $discoveredCommaSeparated, string $storedCommaSeparated): string
    {
        $new = array_diff(
            $this->parseLicenseKeySet($discoveredCommaSeparated),
            $this->parseLicenseKeySet($storedCommaSeparated),
        );
        sort($new, SORT_STRING);

        return implode(',', $new);
    }

    /**
     * @return list<string>
     */
    public function parseLicenseKeySet(string $commaSeparated): array
    {
        $commaSeparated = trim($commaSeparated);
        if ($commaSeparated === '') {
            return [];
        }

        $keys = [];
        foreach (explode(',', $commaSeparated) as $part) {
            $key = trim($part);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        $unique = array_keys($keys);
        sort($unique, SORT_STRING);

        return $unique;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isValidRow(array $row): bool
    {
        if ((int) ($row['is_life_time'] ?? 0) === 1) {
            return true;
        }

        $expires = (int) ($row['expiration_date'] ?? 0);

        return $expires === 0 || $expires > time();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): LicenseContext
    {
        $domains = [];
        foreach (['domains', 'local_domains', 'staging_domains'] as $field) {
            $csv = trim((string) ($row[$field] ?? ''));
            if ($csv === '') {
                continue;
            }
            foreach (explode(',', $csv) as $domain) {
                $domain = trim($domain);
                if ($domain !== '') {
                    $domains[] = $domain;
                }
            }
        }

        return new LicenseContext(
            licenseKey: (string) ($row['license_key'] ?? ''),
            extensionKey: (string) ($row['extension_key'] ?? ''),
            orderId: (string) ($row['order_id'] ?? ''),
            expiresAt: (int) ($row['expiration_date'] ?? 0),
            isLifetime: (int) ($row['is_life_time'] ?? 0) === 1,
            domains: array_values(array_unique($domains)),
        );
    }
}
