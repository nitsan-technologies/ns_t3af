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

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Credits\Service\CreditOverviewLineService;

/**
 * T3Planet Credits for the agent window header: balance badge, low-balance warning and the
 * empty state that locks the input.
 *
 * @internal
 */
final readonly class AgentCreditsStatus
{
    public function __construct(
        private CreditOverviewLineService $creditOverview,
    ) {}

    /**
     * @return array{remaining: string, label: string, percentLeft: int, level: string, empty: bool}|null null = credits mode off / balance unknown
     */
    public function status(): ?array
    {
        try {
            $status = $this->creditOverview->resolveStatus();
        } catch (\Throwable) {
            return null;
        }
        if ($status === null) {
            return null;
        }
        if ($status['empty'] || $status['badge'] === null) {
            return ['remaining' => '0', 'label' => '0', 'percentLeft' => 0, 'level' => 'critical', 'empty' => true];
        }

        return [
            'remaining' => $status['badge']['remainingFormatted'],
            'label' => $status['badge']['creditsLabel'],
            'percentLeft' => $status['badge']['percentLeft'],
            'level' => $status['badge']['level'],
            'empty' => false,
        ];
    }
}
