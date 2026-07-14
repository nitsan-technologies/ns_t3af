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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Domain\Model\Provider;

/**
 * @internal
 */
final class DashboardProviderCardBuilder
{
    private const ERROR_RATE_DEGRADED_THRESHOLD = 2.0;

    private const MAX_CARDS = 4;

    /**
     * @param list<Provider> $providers
     * @param list<array{provider:string,requests:int,failed:int,cost:float,tokens:int,lastCrdate:int}> $stats
     * @return list<array<string, mixed>>
     */
    public function build(array $providers, array $stats): array
    {
        $byProvider = [];
        foreach ($stats as $row) {
            $byProvider[(string) ($row['provider'] ?? '')] = $row;
        }

        $cards = [];
        foreach ($providers as $provider) {
            $stat = $byProvider[$provider->identifier] ?? null;
            $requests = (int) ($stat['requests'] ?? 0);
            $failed = (int) ($stat['failed'] ?? 0);
            $errorRate = $requests > 0 ? round(($failed / $requests) * 100, 1) : 0.0;
            $cost = (float) ($stat['cost'] ?? 0.0);
            $tokens = (int) ($stat['tokens'] ?? 0);
            $missingKey = $provider->isEnabled && trim($provider->apiKeyCipher) === '';
            $highErrorRate = $errorRate >= self::ERROR_RATE_DEGRADED_THRESHOLD;

            if (!$provider->isEnabled) {
                $statusKey = 'offline';
            } elseif ($missingKey || $highErrorRate) {
                $statusKey = 'attention';
            } else {
                $statusKey = 'active';
            }

            $cards[] = [
                'provider' => $provider,
                'title' => $provider->title !== '' ? $provider->title : $provider->identifier,
                'modelId' => $provider->modelId !== '' ? $provider->modelId : '—',
                'isDefault' => $provider->isDefault,
                'isEnabled' => $provider->isEnabled,
                'periodCost' => $cost,
                'periodCostFormatted' => '$' . number_format($cost, 2),
                'errorRate' => $errorRate,
                'errorRateHigh' => $highErrorRate,
                'missingKey' => $missingKey,
                'lastUsedAt' => $provider->lastUsedAt,
                'periodTokens' => $tokens,
                'lastUsedLabel' => $this->formatLastUsed($provider->lastUsedAt),
                'statusKey' => $statusKey,
            ];
        }

        usort(
            $cards,
            static fn(array $a, array $b): int => [$b['lastUsedAt'], $b['periodTokens'], $b['title']]
                <=> [$a['lastUsedAt'], $a['periodTokens'], $a['title']],
        );

        return array_slice($cards, 0, self::MAX_CARDS);
    }

    private function formatLastUsed(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '—';
        }
        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $diff = max(0, $now - $timestamp);
        if ($diff < 3600) {
            return max(1, (int) round($diff / 60)) . ' min ago';
        }
        if ($diff < 86400) {
            return max(1, (int) round($diff / 3600)) . ' h ago';
        }

        return max(1, (int) round($diff / 86400)) . ' d ago';
    }
}
