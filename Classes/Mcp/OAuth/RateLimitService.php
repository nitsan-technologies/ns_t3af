<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


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

namespace NITSAN\NsT3AF\Mcp\OAuth;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use TYPO3\CMS\Core\Database\ConnectionPool;

readonly class RateLimitService
{
    private const TABLE = 'tx_nst3af_oauth_rate_limit';

    private bool $enabled;

    /** @var array<string, array{limit: int, window: int}> */
    private array $endpointLimits;

    public function __construct(private ConnectionPool $connectionPool, ExtensionSettingsService $extensionSettingsService)
    {
        $c = $extensionSettingsService->getAll('ns_t3af');

        $enabled = $c['rateLimitEnabled'] ?? '1';
        $this->enabled = (bool) $enabled;

        $this->endpointLimits = [
            'authorize_post' => [
                'limit' => $this->intVal($c, 'rateLimitAuthorize', 5),
                'window' => $this->intVal($c, 'rateLimitAuthorizeWindow', 300),
            ],
            'authorize_get' => [
                'limit' => $this->intVal($c, 'rateLimitAuthorizeGet', 20),
                'window' => $this->intVal($c, 'rateLimitAuthorizeGetWindow', 300),
            ],
            'token_post' => [
                'limit' => $this->intVal($c, 'rateLimitToken', 20),
                'window' => $this->intVal($c, 'rateLimitTokenWindow', 300),
            ],
            'register_post' => [
                'limit' => $this->intVal($c, 'rateLimitRegister', 10),
                'window' => $this->intVal($c, 'rateLimitRegisterWindow', 3600),
            ],
            'revoke_post' => [
                'limit' => $this->intVal($c, 'rateLimitRevoke', 20),
                'window' => $this->intVal($c, 'rateLimitRevokeWindow', 300),
            ],
        ];
    }

    /**
     * Check if the request is within the rate limit.
     * Returns null if allowed, or the number of seconds until the window resets if blocked.
     */
    public function check(string $ipAddress, string $endpoint): ?int
    {
        if (!$this->enabled || $ipAddress === '') {
            return null;
        }

        $config = $this->endpointLimits[$endpoint] ?? null;
        if ($config === null) {
            return null;
        }

        $limit = $config['limit'];
        $window = $config['window'];
        $windowStart = intdiv(time(), $window) * $window;

        $hitCount = $this->incrementAndGetCount($ipAddress, $endpoint, $windowStart);

        if ($hitCount > $limit) {
            return max(1, $windowStart + $window - time());
        }

        return null;
    }

    /** Deletes expired rate limit entries older than 2 hours. */
    public function deleteExpiredEntries(): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->lt(
                    'window_start',
                    $queryBuilder->createNamedParameter(time() - 7200, ParameterType::INTEGER),
                ),
            )
            ->executeStatement();
    }

    private function incrementAndGetCount(string $ipAddress, string $endpoint, int $windowStart): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        try {
            $connection->insert(self::TABLE, [
                'ip_address' => $ipAddress,
                'endpoint' => $endpoint,
                'hit_count' => 1,
                'window_start' => $windowStart,
            ]);

            return 1;
        } catch (UniqueConstraintViolationException) {
            // Row already exists — increment
        }

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->update(self::TABLE)
            ->set('hit_count', 'hit_count + 1', false)
            ->where(
                $queryBuilder->expr()->eq('ip_address', $queryBuilder->createNamedParameter($ipAddress)),
                $queryBuilder->expr()->eq('endpoint', $queryBuilder->createNamedParameter($endpoint)),
                $queryBuilder->expr()->eq('window_start', $queryBuilder->createNamedParameter($windowStart, ParameterType::INTEGER)),
            )
            ->executeStatement();

        $selectBuilder = $connection->createQueryBuilder();
        $selectBuilder->getRestrictions()->removeAll();

        /** @var int|string|false $hitCount */
        $hitCount = $selectBuilder
            ->select('hit_count')
            ->from(self::TABLE)
            ->where(
                $selectBuilder->expr()->eq('ip_address', $selectBuilder->createNamedParameter($ipAddress)),
                $selectBuilder->expr()->eq('endpoint', $selectBuilder->createNamedParameter($endpoint)),
                $selectBuilder->expr()->eq('window_start', $selectBuilder->createNamedParameter($windowStart, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne();

        return (int) $hitCount;
    }

    /** @param array<mixed> $config */
    private function intVal(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
