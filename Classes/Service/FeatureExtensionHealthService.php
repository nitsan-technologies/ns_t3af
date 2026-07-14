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

use NITSAN\NsT3AF\Contract\FeatureHealthAreaProviderInterface;
use NITSAN\NsT3AF\Contract\FeatureHealthContributorInterface;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Live health cards for TYPO3 AI feature areas (dashboard footer).
 *
 * @internal
 */
final class FeatureExtensionHealthService
{
    private const ERROR_RATE_WARNING = 2.0;

    private const SECONDS_PER_DAY = 86400;

    /**
     * @param iterable<FeatureHealthAreaProviderInterface> $areaProviders
     * @param iterable<FeatureHealthContributorInterface> $contributors
     */
    public function __construct(
        private readonly RequestLogRepository $requestLogs,
        private readonly DashboardStatisticsCache $statisticsCache,
        private readonly iterable $areaProviders = [],
        private readonly iterable $contributors = [],
    ) {}

    /**
     * @return array{
     *   healthy:int,
     *   total:int,
     *   warnings:int,
     *   items:list<array{
     *     id:string,
     *     label:string,
     *     version:string,
     *     status:string,
     *     detail:string,
     *     iconIdentifier:string
     *   }>
     * }
     */
    public function build(int $windowDays = 7): array
    {
        $cached = $this->statisticsCache->getExtensionHealth($windowDays);
        if ($cached !== null) {
            return $cached;
        }

        $from = (int) (($GLOBALS['EXEC_TIME'] ?? time()) - max(1, $windowDays) * self::SECONDS_PER_DAY);
        $contributorsById = $this->indexContributors();
        $items = [];

        foreach ($this->collectHealthAreas() as $feature) {
            if (!$this->isParentExtensionLoaded($feature['extensionKey'])) {
                continue;
            }

            $stats = $this->requestLogs->featureWindowStats($feature['prefixes'], $from);
            $status = $this->resolveStatus($stats);
            $detail = $contributorsById[$feature['id']] ?? $this->buildDefaultDetail($stats, $status);

            $items[] = [
                'id' => $feature['id'],
                'label' => $feature['label'],
                'version' => $this->extensionVersion($feature['extensionKey']),
                'status' => $status,
                'detail' => $detail,
                'iconIdentifier' => $feature['iconIdentifier'],
            ];
        }

        $healthy = count(array_filter($items, static fn(array $row): bool => $row['status'] === 'healthy'));
        $warnings = count(array_filter($items, static fn(array $row): bool => $row['status'] === 'warning'));

        $payload = [
            'healthy' => $healthy,
            'total' => count($items),
            'warnings' => $warnings,
            'items' => $items,
        ];

        $this->statisticsCache->setExtensionHealth($windowDays, $payload);

        return $payload;
    }

    /**
     * @return list<array{id:string,label:string,extensionKey:string,iconIdentifier:string,prefixes:list<string>}>
     */
    private function collectHealthAreas(): array
    {
        $areas = [];
        foreach ($this->areaProviders as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            foreach ($provider->getHealthAreas() as $descriptor) {
                $areas[] = [
                    'id' => $descriptor->id,
                    'label' => $descriptor->label,
                    'extensionKey' => $descriptor->extensionKey,
                    'iconIdentifier' => $descriptor->iconIdentifier,
                    'prefixes' => $descriptor->requestLogPrefixes,
                ];
            }
        }

        return $areas;
    }

    /**
     * @param array{requests:int,failed:int,lastCrdate:int,lastErrorCode:string} $stats
     */
    private function resolveStatus(array $stats): string
    {
        $requests = (int) ($stats['requests'] ?? 0);
        $failed = (int) ($stats['failed'] ?? 0);
        if ($requests <= 0) {
            return 'healthy';
        }

        $errorRate = ($failed / $requests) * 100;
        $errorCode = strtolower((string) ($stats['lastErrorCode'] ?? ''));
        if ($errorRate >= 100.0) {
            return 'error';
        }
        if ($errorRate >= self::ERROR_RATE_WARNING || str_contains($errorCode, 'rate')) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @param array{requests:int,failed:int,lastCrdate:int,lastErrorCode:string} $stats
     */
    private function buildDefaultDetail(array $stats, string $status): string
    {
        $lastCrdate = (int) ($stats['lastCrdate'] ?? 0);
        if ($status === 'warning' && (string) ($stats['lastErrorCode'] ?? '') !== '') {
            $time = $lastCrdate > 0 ? date('H:i', $lastCrdate) : '';

            return trim('Issue: ' . (string) $stats['lastErrorCode'] . ($time !== '' ? ' at ' . $time : ''));
        }
        if ($lastCrdate <= 0) {
            return 'No recent activity';
        }

        return 'Last used ' . $this->relativeTime($lastCrdate);
    }

    /**
     * @return array<string, string>
     */
    private function indexContributors(): array
    {
        $map = [];
        foreach ($this->contributors as $contributor) {
            $message = trim($contributor->detailMessage());
            if ($message !== '') {
                $map[$contributor->featureId()] = $message;
            }
        }

        return $map;
    }

    private function isParentExtensionLoaded(string $extensionKey): bool
    {
        return ExtensionManagementUtility::isLoaded($extensionKey);
    }

    private function extensionVersion(string $extensionKey): string
    {
        if (!ExtensionManagementUtility::isLoaded($extensionKey)) {
            return '';
        }

        $emConfPath = ExtensionManagementUtility::extPath($extensionKey, 'ext_emconf.php');
        if (!is_file($emConfPath)) {
            return '';
        }

        /** @var array<string, array<string, mixed>> $EM_CONF */
        $EM_CONF = [];
        include $emConfPath;
        $version = trim((string) ($EM_CONF[$extensionKey]['version'] ?? ''));

        return $version !== '' ? 'v' . $version : '';
    }

    private function relativeTime(int $timestamp): string
    {
        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $delta = max(0, $now - $timestamp);
        if ($delta < 60) {
            return 'just now';
        }
        if ($delta < 3600) {
            return (int) floor($delta / 60) . ' min ago';
        }
        if ($delta < 86400) {
            return (int) floor($delta / 3600) . ' hr ago';
        }

        return (int) floor($delta / 86400) . ' day ago';
    }
}
