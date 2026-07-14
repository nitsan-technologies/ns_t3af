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

use NITSAN\NsT3AF\Domain\Repository\AiSysLogRepository;

final class AiLogsStatisticsService
{
    public function __construct(
        private readonly AiSysLogRepository $aiSysLogRepository,
    ) {}

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{
     *   total:int,
     *   info:int,
     *   warning:int,
     *   error:int,
     *   lastEntryTstamp:int,
     *   lastEntryFormatted:string
     * }
     */
    public function buildSummary(array $filters): array
    {
        $stats = $this->aiSysLogRepository->getStatistics($filters);

        $lastTstamp = (int) ($stats['lastEntryTstamp'] ?? 0);

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'info' => (int) ($stats['info'] ?? 0),
            'warning' => (int) ($stats['warning'] ?? 0),
            'error' => (int) ($stats['error'] ?? 0),
            'lastEntryTstamp' => $lastTstamp,
            'lastEntryFormatted' => $lastTstamp > 0 ? date('d.m.Y H:i:s', $lastTstamp) : '—',
        ];
    }
}
