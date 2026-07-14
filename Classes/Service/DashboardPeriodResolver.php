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

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves dashboard date-range presets from backend module query parameters.
 *
 * @internal
 */
final class DashboardPeriodResolver
{
    public const PRESET_TODAY = 'today';

    public const PRESET_YESTERDAY = 'yesterday';

    public const PRESET_7D = '7d';

    public const PRESET_14D = '14d';

    public const PRESET_30D = '30d';

    public const PRESET_CUSTOM = 'custom';

    /**
     * @return array{
     *   preset: string,
     *   days: int,
     *   fromTimestamp: int,
     *   toTimestamp: int,
     *   labelKey: string
     * }
     */
    public function resolve(ServerRequestInterface $request): array
    {
        return $this->resolveFromQueryParams($request->getQueryParams());
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{
     *   preset: string,
     *   days: int,
     *   fromTimestamp: int,
     *   toTimestamp: int,
     *   labelKey: string
     * }
     */
    public function resolveFromQueryParams(array $query, string $defaultPreset = self::PRESET_7D): array
    {
        $preset = (string) ($query['period'] ?? $defaultPreset);
        if (!in_array($preset, $this->allowedPresets(), true)) {
            $preset = $defaultPreset;
        }

        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());
        $toTimestamp = $now;

        if ($preset === self::PRESET_TODAY) {
            $fromTimestamp = (int) strtotime('today', $now);
            $days = 1;

            return $this->buildResult($preset, $days, $fromTimestamp, $toTimestamp);
        }

        if ($preset === self::PRESET_YESTERDAY) {
            $fromTimestamp = (int) strtotime('yesterday', $now);
            $toTimestamp = (int) strtotime('today', $now) - 1;
            $days = 1;

            return $this->buildResult($preset, $days, $fromTimestamp, $toTimestamp);
        }

        if ($preset === self::PRESET_CUSTOM) {
            $fromRaw = (string) ($query['from'] ?? '');
            $toRaw = (string) ($query['to'] ?? '');
            $fromParsed = $fromRaw !== '' ? strtotime($fromRaw . ' 00:00:00') : false;
            $toParsed = $toRaw !== '' ? strtotime($toRaw . ' 23:59:59') : false;
            if ($fromParsed !== false && $toParsed !== false && $fromParsed <= $toParsed) {
                $fromTimestamp = (int) $fromParsed;
                $toTimestamp = (int) $toParsed;
                $days = max(1, (int) ceil(($toTimestamp - $fromTimestamp + 1) / 86400));

                return $this->buildResult($preset, $days, $fromTimestamp, $toTimestamp);
            }
            $days = max(1, min(365, (int) ($query['days'] ?? 7)));
            $fromTimestamp = $now - $days * 86400;

            return $this->buildResult($preset, $days, $fromTimestamp, $toTimestamp);
        }

        $days = match ($preset) {
            self::PRESET_14D => 14,
            self::PRESET_30D => 30,
            default => 7,
        };
        $fromTimestamp = $now - $days * 86400;

        return $this->buildResult($preset, $days, $fromTimestamp, $toTimestamp);
    }

    /**
     * @return list<string>
     */
    public function allowedPresets(): array
    {
        return [
            self::PRESET_TODAY,
            self::PRESET_YESTERDAY,
            self::PRESET_7D,
            self::PRESET_14D,
            self::PRESET_30D,
            self::PRESET_CUSTOM,
        ];
    }

    /**
     * @return array{
     *   preset: string,
     *   days: int,
     *   fromTimestamp: int,
     *   toTimestamp: int,
     *   labelKey: string
     * }
     */
    private function buildResult(string $preset, int $days, int $fromTimestamp, int $toTimestamp): array
    {
        return [
            'preset' => $preset,
            'days' => $days,
            'fromTimestamp' => $fromTimestamp,
            'toTimestamp' => $toTimestamp,
            'labelKey' => 'module.dashboard.period.' . $preset,
        ];
    }
}
