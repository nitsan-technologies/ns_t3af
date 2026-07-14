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

/**
 * Pure helpers for dashboard period-over-period KPI trends.
 *
 * @internal
 */
final class DashboardTrendMath
{
    /**
     * @param list<int> $sparkline
     * @return array{value:float,changePercent:float,direction:string,sparkline:list<int>}
     */
    public static function metric(
        float $current,
        float $prior,
        array $sparkline = [],
        bool $invertPositive = false,
    ): array {
        $changePercent = 0.0;
        if ($prior > 0.0) {
            $changePercent = round((($current - $prior) / $prior) * 100, 1);
        } elseif ($current > 0.0) {
            $changePercent = 100.0;
        }

        $direction = 'neutral';
        if ($changePercent > 0.1) {
            $direction = $invertPositive ? 'down' : 'up';
        } elseif ($changePercent < -0.1) {
            $direction = $invertPositive ? 'up' : 'down';
        }

        return [
            'value' => $current,
            'changePercent' => $changePercent,
            'direction' => $direction,
            'sparkline' => $sparkline,
        ];
    }
}
